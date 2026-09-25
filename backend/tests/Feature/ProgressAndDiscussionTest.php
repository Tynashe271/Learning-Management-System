<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class ProgressAndDiscussionTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    private CourseOffering $offering;

    private User $lecturer;

    private User $ada;

    private User $ben;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->offering = $this->offering();
        $this->lecturer = $this->userWithRole('lecturer');
        $this->ada = $this->userWithRole('student', ['name' => 'Ada']);
        $this->ben = $this->userWithRole('student', ['name' => 'Ben']);
        $this->teach($this->offering, $this->lecturer);
        $this->enrol($this->offering, $this->ada);
        $this->enrol($this->offering, $this->ben);
    }

    /** Three visible items, one unpublished item, and one item inside an unpublished module. */
    private function content(): array
    {
        $week1 = $this->offering->modules()->create(['title' => 'Week 1', 'published' => true]);
        $draft = $this->offering->modules()->create(['title' => 'Draft week', 'published' => false]);
        $items = [];
        foreach (['a', 'b', 'c'] as $t) {
            $items[] = $week1->items()->create(['title' => $t, 'type' => 'text', 'body' => $t, 'published' => true]);
        }
        $hidden = $week1->items()->create(['title' => 'hidden', 'type' => 'text', 'body' => 'h', 'published' => false]);
        $inDraft = $draft->items()->create(['title' => 'in draft', 'type' => 'text', 'body' => 'd', 'published' => true]);

        return [$items, $hidden, $inDraft];
    }

    public function test_students_track_their_own_completion(): void
    {
        [$items] = $this->content();
        $progress = '/api/offerings/'.$this->offering->id.'/progress';

        $this->actingAs($this->ada)->postJson('/api/items/'.$items[0]->id.'/complete')->assertOk()->assertJsonPath('completed', true);
        $this->actingAs($this->ada)->postJson('/api/items/'.$items[0]->id.'/complete')->assertOk(); // idempotent
        $this->actingAs($this->ada)->getJson($progress)->assertOk()
            ->assertJsonPath('items_total', 3)->assertJsonPath('completed', 1)->assertJsonPath('percent', 33.33)->assertJsonPath('completed_item_ids', [$items[0]->id]);
        $this->actingAs($this->ben)->getJson($progress)->assertJsonPath('completed', 0);

        $this->actingAs($this->ada)->deleteJson('/api/items/'.$items[0]->id.'/complete')->assertOk()->assertJsonPath('completed', false);
        $this->actingAs($this->ada)->getJson($progress)->assertJsonPath('completed', 0);
    }

    public function test_hidden_material_and_non_students_cannot_be_completed(): void
    {
        [$items, $hidden, $inDraft] = $this->content();
        $withdrawn = $this->userWithRole('student');
        $this->enrol($this->offering, $withdrawn, 'withdrawn');

        $this->actingAs($this->ada)->postJson('/api/items/'.$hidden->id.'/complete')->assertNotFound();
        $this->actingAs($this->ada)->postJson('/api/items/'.$inDraft->id.'/complete')->assertNotFound();
        $this->actingAs($this->userWithRole('student'))->postJson('/api/items/'.$items[0]->id.'/complete')->assertForbidden();
        $this->actingAs($withdrawn)->postJson('/api/items/'.$items[0]->id.'/complete')->assertForbidden();
        $this->actingAs($this->lecturer)->postJson('/api/items/'.$items[0]->id.'/complete')->assertForbidden();
        $this->assertDatabaseCount('item_completions', 0);
    }

    public function test_teachers_see_completion_for_every_enrolled_student(): void
    {
        [$items] = $this->content();
        $this->actingAs($this->ada)->postJson('/api/items/'.$items[0]->id.'/complete');
        $this->actingAs($this->ada)->postJson('/api/items/'.$items[1]->id.'/complete');
        $this->actingAs($this->ben)->postJson('/api/items/'.$items[2]->id.'/complete');

        $view = $this->actingAs($this->lecturer)->getJson('/api/offerings/'.$this->offering->id.'/progress')->assertOk();

        $view->assertJsonPath('items_total', 3)->assertJsonCount(2, 'students')
            ->assertJsonPath('students.0.user.name', 'Ada')->assertJsonPath('students.0.completed', 2)->assertJsonPath('students.0.percent', 66.67)
            ->assertJsonPath('students.1.completed', 1);
    }

    public function test_enrolled_students_and_staff_can_discuss_and_outsiders_cannot(): void
    {
        $outsider = $this->userWithRole('student');
        $url = '/api/offerings/'.$this->offering->id.'/discussions';

        $threadId = $this->actingAs($this->ada)->postJson($url, ['title' => 'Question about week 1', 'body' => 'What is a join?'])->assertCreated()->assertJsonPath('author.name', 'Ada')->json('id');
        $this->actingAs($this->ben)->postJson('/api/discussions/'.$threadId.'/posts', ['body' => 'Combining tables.'])->assertCreated();
        $this->actingAs($this->lecturer)->postJson('/api/discussions/'.$threadId.'/posts', ['body' => 'Correct, Ben.'])->assertCreated();

        $this->actingAs($this->ben)->getJson($url)->assertOk()->assertJsonPath('data.0.posts_count', 2);
        $this->actingAs($this->ben)->getJson('/api/discussions/'.$threadId)->assertOk()->assertJsonPath('thread.title', 'Question about week 1')->assertJsonCount(2, 'posts.data')->assertJsonPath('posts.data.0.author.name', 'Ben');

        $this->actingAs($outsider)->getJson($url)->assertForbidden();
        $this->actingAs($outsider)->postJson($url, ['title' => 'Hi', 'body' => 'There'])->assertForbidden();
        $this->actingAs($outsider)->getJson('/api/discussions/'.$threadId)->assertForbidden();
        $this->actingAs($outsider)->postJson('/api/discussions/'.$threadId.'/posts', ['body' => 'Hi'])->assertForbidden();
    }

    public function test_withdrawn_students_lose_access_to_discussions(): void
    {
        $this->offering->enrolments()->where('user_id', $this->ben->id)->update(['status' => 'withdrawn']);

        $this->actingAs($this->ben)->getJson('/api/offerings/'.$this->offering->id.'/discussions')->assertForbidden();
        $this->actingAs($this->ben)->postJson('/api/offerings/'.$this->offering->id.'/discussions', ['title' => 'Hi', 'body' => 'There'])->assertForbidden();
    }

    public function test_recent_activity_bumps_a_thread_to_the_top(): void
    {
        $url = '/api/offerings/'.$this->offering->id.'/discussions';
        $first = $this->actingAs($this->ada)->postJson($url, ['title' => 'First', 'body' => 'a'])->json('id');
        $this->travel(5)->minutes();
        $this->actingAs($this->ada)->postJson($url, ['title' => 'Second', 'body' => 'b']);
        $this->travel(5)->minutes();
        $this->actingAs($this->ben)->postJson('/api/discussions/'.$first.'/posts', ['body' => 'bump']);

        $this->actingAs($this->ada)->getJson($url)->assertJsonPath('data.0.title', 'First')->assertJsonPath('data.1.title', 'Second');
    }

    public function test_authors_delete_their_own_posts_and_teachers_moderate_everything(): void
    {
        $url = '/api/offerings/'.$this->offering->id.'/discussions';
        $threadId = $this->actingAs($this->ada)->postJson($url, ['title' => 'T', 'body' => 'b'])->json('id');
        $benPost = $this->actingAs($this->ben)->postJson('/api/discussions/'.$threadId.'/posts', ['body' => 'mine'])->json('id');
        $adaPost = $this->actingAs($this->ada)->postJson('/api/discussions/'.$threadId.'/posts', ['body' => 'hers'])->json('id');

        $this->actingAs($this->ben)->deleteJson('/api/posts/'.$adaPost)->assertForbidden();
        $this->actingAs($this->ben)->deleteJson('/api/discussions/'.$threadId)->assertForbidden();
        $this->actingAs($this->ben)->deleteJson('/api/posts/'.$benPost)->assertOk();
        $this->actingAs($this->lecturer)->deleteJson('/api/posts/'.$adaPost)->assertOk();
        $this->assertDatabaseCount('discussion_posts', 0);

        $this->actingAs($this->lecturer)->deleteJson('/api/discussions/'.$threadId)->assertOk();
        $this->assertDatabaseCount('discussion_threads', 0);
    }

    public function test_deleting_a_thread_removes_its_replies(): void
    {
        $url = '/api/offerings/'.$this->offering->id.'/discussions';
        $threadId = $this->actingAs($this->ada)->postJson($url, ['title' => 'T', 'body' => 'b'])->json('id');
        $this->actingAs($this->ben)->postJson('/api/discussions/'.$threadId.'/posts', ['body' => 'reply']);

        $this->actingAs($this->ada)->deleteJson('/api/discussions/'.$threadId)->assertOk();

        $this->assertDatabaseCount('discussion_posts', 0);
    }
}
