<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class AiAssistantTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    private CourseOffering $offering;

    private User $lecturer;

    private User $ada;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->offering = $this->offering();
        $this->lecturer = $this->userWithRole('lecturer');
        $this->ada = $this->userWithRole('student');
        $this->teach($this->offering, $this->lecturer);
        $this->enrol($this->offering, $this->ada);
    }

    private function enableAi(): void
    {
        config(['lms.ai.enabled' => true, 'lms.ai.api_key' => 'test-key']);
    }

    private function fakeReply(string $text): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => $text]]], 200)]);
    }

    public function test_the_assistant_is_off_by_default(): void
    {
        $this->actingAs($this->ada)->postJson('/api/offerings/'.$this->offering->id.'/ai/assist', ['mode' => 'ask', 'prompt' => 'What is a variable?'])
            ->assertUnprocessable()->assertJsonValidationErrors('prompt');
    }

    public function test_a_student_gets_an_answer_grounded_in_published_course_material(): void
    {
        $this->enableAi();
        $module = $this->offering->modules()->create(['title' => 'Week 1', 'position' => 1]);
        $module->items()->create(['title' => 'Variables', 'type' => 'text', 'body' => 'A variable stores a value under a name.', 'published' => true, 'position' => 1]);
        $module->items()->create(['title' => 'Secret draft', 'type' => 'text', 'body' => 'Not ready yet.', 'published' => false, 'position' => 2]);
        $this->fakeReply("A variable is a named storage location for a value.\n\nSources: 1");

        $result = $this->actingAs($this->ada)->postJson('/api/offerings/'.$this->offering->id.'/ai/assist', ['mode' => 'ask', 'prompt' => 'What is a variable?'])
            ->assertOk()->json();

        $this->assertStringContainsString('named storage location', $result['answer']);
        $this->assertStringNotContainsString('Sources:', $result['answer']);
        $this->assertCount(1, $result['sources']);
        $this->assertSame('Variables', $result['sources'][0]['title']);

        Http::assertSent(function ($request) {
            return str_contains($request['system'], 'Variables') && ! str_contains($request['system'], 'Secret draft');
        });
    }

    public function test_sources_none_yields_an_empty_sources_list(): void
    {
        $this->enableAi();
        $this->fakeReply("I don't have that in the course material.\n\nSources: none");

        $result = $this->actingAs($this->ada)->postJson('/api/offerings/'.$this->offering->id.'/ai/assist', ['mode' => 'ask', 'prompt' => 'What is quantum computing?'])
            ->assertOk()->json();

        $this->assertSame([], $result['sources']);
    }

    public function test_only_an_enrolled_student_can_use_the_student_assistant(): void
    {
        $this->enableAi();
        $this->fakeReply('hi');
        $outsider = $this->userWithRole('student');

        $this->actingAs($outsider)->postJson('/api/offerings/'.$this->offering->id.'/ai/assist', ['mode' => 'ask', 'prompt' => 'x'])->assertForbidden();
    }

    public function test_a_lecturer_can_use_the_teaching_assistant_but_a_student_cannot(): void
    {
        $this->enableAi();
        $this->fakeReply("1. Intro\n2. Core ideas\n\nSources: none");

        $this->actingAs($this->lecturer)->postJson('/api/offerings/'.$this->offering->id.'/ai/teaching-assist', ['mode' => 'lesson_outline', 'prompt' => 'Recursion'])
            ->assertOk()->assertJsonPath('sources', []);

        $this->actingAs($this->ada)->postJson('/api/offerings/'.$this->offering->id.'/ai/teaching-assist', ['mode' => 'lesson_outline', 'prompt' => 'Recursion'])->assertForbidden();
    }

    public function test_an_invalid_mode_is_rejected(): void
    {
        $this->enableAi();
        $this->actingAs($this->ada)->postJson('/api/offerings/'.$this->offering->id.'/ai/assist', ['mode' => 'write_my_essay', 'prompt' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('mode');
    }
}
