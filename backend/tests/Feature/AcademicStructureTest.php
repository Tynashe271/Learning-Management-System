<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class AcademicStructureTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    private User $admin;

    private User $registrar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = $this->userWithRole('university-admin');
        $this->registrar = $this->userWithRole('registrar');
    }

    private function as(User $user)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user);
    }

    private function day(int $offset): string
    {
        return now()->addDays($offset)->toDateString();
    }

    /** A term whose registration is open (or not), with a course and a published, self-enrolment offering in it. */
    private function openOffering(array $offering = [], array $term = []): CourseOffering
    {
        $term = AcademicTerm::create($term + ['name' => 'Term '.uniqid(), 'starts_on' => $this->day(10), 'ends_on' => $this->day(100), 'registration_opens_on' => $this->day(-3), 'registration_closes_on' => $this->day(5), 'add_drop_deadline' => $this->day(15)]);
        $course = Course::create(['code' => 'REG'.uniqid(), 'title' => 'Registerable']);

        return CourseOffering::create($offering + ['course_id' => $course->id, 'academic_term_id' => $term->id, 'section' => 'A', 'published' => true, 'self_enrolment' => true]);
    }

    // ---- departments and courses -------------------------------------------------------------------------------------

    public function test_departments_can_be_created_renamed_archived_and_only_deleted_when_empty(): void
    {
        $created = $this->as($this->admin)->postJson('/api/departments', ['code' => 'cs', 'name' => 'Computer Science'])->assertCreated()->assertJsonPath('code', 'CS');
        $id = $created->json('id');
        $this->as($this->admin)->postJson('/api/departments', ['code' => 'CS', 'name' => 'Another'])->assertUnprocessable()->assertJsonValidationErrors('code');
        // a code that differs only by case is the same code (they are stored in capitals), not a database error
        $this->as($this->admin)->postJson('/api/departments', ['code' => ' cS ', 'name' => 'Another'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->as($this->admin)->postJson('/api/departments', ['code' => 'bad code!', 'name' => 'X'])->assertUnprocessable();

        $this->as($this->admin)->patchJson("/api/departments/{$id}", ['name' => 'Computing'])->assertOk()->assertJsonPath('name', 'Computing');
        Course::create(['code' => 'CS101', 'title' => 'Intro', 'department_id' => $id]);
        $this->as($this->admin)->getJson('/api/departments')->assertJsonPath('0.courses_count', 1);
        $this->as($this->admin)->deleteJson("/api/departments/{$id}")->assertUnprocessable();

        $this->as($this->admin)->patchJson("/api/departments/{$id}", ['archived' => true])->assertOk();
        $this->as($this->admin)->getJson('/api/departments')->assertJsonCount(0);
        $this->as($this->admin)->getJson('/api/departments?archived=only')->assertJsonCount(1);
        $this->as($this->admin)->patchJson("/api/departments/{$id}", ['archived' => false])->assertOk();

        $empty = Department::create(['code' => 'EMPTY', 'name' => 'Nothing here']);
        $this->as($this->admin)->deleteJson("/api/departments/{$empty->id}")->assertOk();
        $this->assertSame(1, DB::table('activity_log')->where('description', 'department deleted')->count());
        $this->assertSame(1, DB::table('activity_log')->where('description', 'department created')->count());
    }

    public function test_only_catalogue_administrators_change_departments_but_registrars_can_look_at_them(): void
    {
        $this->as($this->registrar)->getJson('/api/departments')->assertOk();
        foreach (['student', 'lecturer'] as $role) {
            $this->as($this->userWithRole($role))->postJson('/api/departments', ['code' => 'X', 'name' => 'X'])->assertForbidden();
        }
        // A registrar now also manages courses.
        $this->as($this->registrar)->postJson('/api/departments', ['code' => 'X', 'name' => 'X'])->assertCreated();
        $this->as($this->userWithRole('student'))->getJson('/api/departments')->assertForbidden();
    }

    public function test_courses_carry_a_department_credits_and_level_and_can_be_searched_and_archived(): void
    {
        $cs = Department::create(['code' => 'CS', 'name' => 'Computing']);
        $old = Department::create(['code' => 'OLD', 'name' => 'Closed', 'archived_at' => now()]);

        $this->as($this->admin)->postJson('/api/courses', ['code' => 'CSC201', 'title' => 'Algorithms', 'department_id' => $cs->id, 'credits' => 15, 'level' => 'undergraduate'])->assertCreated()
            ->assertJsonPath('department.code', 'CS')->assertJsonPath('credits', 15)->assertJsonPath('level', 'undergraduate');
        $this->as($this->admin)->postJson('/api/courses', ['code' => 'X1', 'title' => 'X', 'department_id' => $old->id])->assertUnprocessable()->assertJsonValidationErrors('department_id');
        $this->as($this->admin)->postJson('/api/courses', ['code' => 'X2', 'title' => 'X', 'level' => 'kindergarten'])->assertUnprocessable()->assertJsonValidationErrors('level');
        $this->as($this->admin)->postJson('/api/courses', ['code' => 'X3', 'title' => 'X', 'credits' => 0])->assertUnprocessable();
        Course::create(['code' => 'MAT101', 'title' => 'Calculus']);

        $this->as($this->admin)->getJson('/api/courses?department_id='.$cs->id)->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'CSC201');
        $this->as($this->admin)->getJson('/api/courses?q=calc')->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'MAT101');
        $this->as($this->admin)->getJson('/api/courses?level=postgraduate')->assertJsonCount(0, 'data');

        $id = Course::where('code', 'CSC201')->value('id');
        $this->as($this->admin)->patchJson("/api/courses/{$id}", ['archived' => true])->assertOk()->assertJsonPath('archived_at', fn ($v) => $v !== null);
        $this->as($this->admin)->getJson('/api/courses')->assertJsonCount(1, 'data');
        $this->as($this->admin)->getJson('/api/courses?archived=include')->assertJsonCount(2, 'data');
        $this->as($this->admin)->getJson('/api/courses?archived=only')->assertJsonCount(1, 'data');
    }

    public function test_an_archived_course_cannot_be_offered_until_it_is_restored(): void
    {
        $course = Course::create(['code' => 'ARC1', 'title' => 'Old course', 'archived_at' => now()]);
        $term = AcademicTerm::create(['name' => 'T', 'starts_on' => $this->day(1), 'ends_on' => $this->day(90)]);
        $body = ['course_id' => $course->id, 'academic_term_id' => $term->id, 'section' => 'A'];

        $this->as($this->admin)->postJson('/api/offerings', $body)->assertUnprocessable()->assertJsonValidationErrors('course_id');
        $this->as($this->admin)->patchJson("/api/courses/{$course->id}", ['archived' => false])->assertOk();
        $this->as($this->admin)->postJson('/api/offerings', $body)->assertCreated();
        $this->as($this->admin)->postJson('/api/offerings', $body)->assertUnprocessable()->assertJsonValidationErrors('section');
    }

    // ---- terms -------------------------------------------------------------------------------------------------------

    public function test_a_term_has_an_academic_year_a_registration_period_and_an_add_drop_deadline(): void
    {
        $this->as($this->admin)->postJson('/api/terms', [
            'name' => 'Semester 1', 'academic_year' => '2026/2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-01-31',
            'registration_opens_on' => '2026-08-01', 'registration_closes_on' => '2026-09-15', 'add_drop_deadline' => '2026-09-22', 'is_current' => true,
        ])->assertCreated()->assertJsonPath('academic_year', '2026/2027')->assertJsonPath('registration_closes_on', '2026-09-15')->assertJsonPath('is_current', true);
    }

    public function test_term_dates_must_make_sense(): void
    {
        $base = ['name' => 'T', 'starts_on' => '2026-09-01', 'ends_on' => '2027-01-31'];
        $post = fn (array $extra) => $this->as($this->admin)->postJson('/api/terms', $base + $extra);

        $post(['registration_opens_on' => '2026-08-01'])->assertUnprocessable()->assertJsonValidationErrors('registration_closes_on');
        $post(['registration_opens_on' => '2026-09-10', 'registration_closes_on' => '2026-09-01'])->assertUnprocessable()->assertJsonValidationErrors('registration_closes_on');
        $post(['registration_opens_on' => '2026-08-01', 'registration_closes_on' => '2027-03-01'])->assertUnprocessable()->assertJsonValidationErrors('registration_closes_on');
        $post(['add_drop_deadline' => '2028-01-01'])->assertUnprocessable()->assertJsonValidationErrors('add_drop_deadline');
        $this->as($this->admin)->postJson('/api/terms', ['name' => 'T', 'starts_on' => '2026-09-01', 'ends_on' => '2026-08-01'])->assertUnprocessable()->assertJsonValidationErrors('ends_on');
        $post(['registration_opens_on' => '2026-08-01', 'registration_closes_on' => '2026-09-15', 'add_drop_deadline' => '2026-09-22'])->assertCreated();
    }

    public function test_only_one_term_is_current_at_a_time(): void
    {
        $a = AcademicTerm::create(['name' => 'A', 'starts_on' => '2026-01-01', 'ends_on' => '2026-06-01', 'is_current' => true]);
        $b = $this->as($this->admin)->postJson('/api/terms', ['name' => 'B', 'starts_on' => '2026-09-01', 'ends_on' => '2027-01-01', 'is_current' => true])->assertCreated()->json('id');

        $this->assertFalse($a->fresh()->is_current);
        $this->as($this->admin)->getJson('/api/terms?current=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $b);
        $this->as($this->admin)->patchJson("/api/terms/{$a->id}", ['is_current' => true])->assertOk();
        $this->assertSame(1, AcademicTerm::where('is_current', true)->count());
        $this->assertSame($a->id, AcademicTerm::where('is_current', true)->value('id'));
    }

    public function test_ending_a_term_archives_all_its_offerings_at_once_and_can_be_undone(): void
    {
        $offering = $this->openOffering(['self_enrolment' => false], ['is_current' => true]);
        $other = $this->openOffering(['self_enrolment' => false]);
        $termId = $offering->academic_term_id;

        $this->as($this->admin)->postJson("/api/terms/{$termId}/archive", ['archived' => true])->assertOk()->assertJsonPath('offerings', 1);

        $this->assertNotNull($offering->fresh()->archived_at);
        $this->assertNull($other->fresh()->archived_at, 'other terms are untouched');
        $this->assertFalse(AcademicTerm::find($termId)->is_current);
        $this->as($this->admin)->postJson("/api/terms/{$termId}/archive", ['archived' => false])->assertOk()->assertJsonPath('offerings', 1);
        $this->assertNull($offering->fresh()->archived_at);
        $this->assertSame(2, DB::table('activity_log')->whereIn('description', ['term archived', 'term reopened'])->count());
    }

    // ---- offerings, capacity and archiving ----------------------------------------------------------------------------

    public function test_an_offering_can_have_a_capacity_and_the_capacity_cannot_go_below_the_number_enrolled(): void
    {
        $offering = $this->openOffering(['capacity' => 10]);
        foreach (range(1, 3) as $i) {
            $this->enrol($offering, $this->userWithRole('student'));
        }

        $this->as($this->admin)->patchJson("/api/offerings/{$offering->id}", ['capacity' => 2])->assertUnprocessable()->assertJsonValidationErrors('capacity');
        $this->as($this->admin)->patchJson("/api/offerings/{$offering->id}", ['capacity' => 3])->assertOk()->assertJsonPath('capacity', 3);
        $this->as($this->admin)->patchJson("/api/offerings/{$offering->id}", ['capacity' => null])->assertOk()->assertJsonPath('capacity', null);
        $this->as($this->admin)->patchJson("/api/offerings/{$offering->id}", [])->assertUnprocessable();
    }

    public function test_enrolling_into_a_full_offering_is_refused_unless_a_course_administrator_overrides_it(): void
    {
        $offering = $this->openOffering(['capacity' => 1, 'self_enrolment' => false]);
        [$first, $second, $third] = [$this->userWithRole('student'), $this->userWithRole('student'), $this->userWithRole('student')];
        $enrol = fn (User $by, User $student, array $extra = []) => $this->as($by)->postJson("/api/offerings/{$offering->id}/enrolments", ['user_id' => $student->id, 'status' => 'active'] + $extra);

        $enrol($this->registrar, $first)->assertOk();
        $enrol($this->registrar, $first)->assertOk(); // already has the place: nothing changes
        $enrol($this->registrar, $second)->assertUnprocessable()->assertJsonValidationErrors('capacity');
        // A registrar now also manages courses, so they can override capacity too.
        $enrol($this->registrar, $second, ['override' => true])->assertOk();
        $enrol($this->admin, $second, ['override' => true])->assertOk();

        $this->assertSame(2, $offering->enrolments()->where('status', 'active')->count());
        $this->assertTrue(json_decode(DB::table('activity_log')->where('description', 'enrolment changed')->latest('id')->value('properties'), true)['over_capacity']);
        $this->as($this->registrar)->postJson("/api/offerings/{$offering->id}/enrolments", ['user_id' => $first->id, 'status' => 'withdrawn'])->assertOk();
        $this->as($this->registrar)->postJson("/api/offerings/{$offering->id}/enrolments", ['user_id' => $third->id, 'status' => 'withdrawn'])->assertOk(); // withdrawing is never blocked
        $this->assertSame(1, $offering->enrolments()->where('status', 'active')->count());
        $enrol($this->registrar, $third)->assertUnprocessable(); // the offering is at capacity again
    }

    public function test_a_list_import_fills_the_places_and_reports_who_did_not_fit(): void
    {
        $offering = $this->openOffering(['capacity' => 2, 'self_enrolment' => false]);
        $students = collect(range(1, 4))->map(fn ($i) => $this->userWithRole('student', ['email' => "s{$i}@example.test"]));

        $result = $this->as($this->registrar)->postJson("/api/offerings/{$offering->id}/enrolments/import", ['emails' => $students->pluck('email')->all()])->assertOk();

        $result->assertJsonPath('enrolled', 2)->assertJsonPath('full', ['s3@example.test', 's4@example.test']);
        $this->assertSame(2, $offering->enrolments()->where('status', 'active')->count());
    }

    public function test_an_archived_offering_is_hidden_from_the_catalogue_but_stays_readable_for_its_people_and_closed_to_new_work(): void
    {
        $offering = $this->openOffering(['self_enrolment' => false]);
        $student = $this->userWithRole('student');
        $teacher = $this->userWithRole('lecturer');
        $this->enrol($offering, $student);
        $this->teach($offering, $teacher);
        $assignment = $offering->assignments()->create(['title' => 'Essay', 'due_at' => now()->addDay(), 'max_score' => 10, 'published' => true]);
        $quiz = $offering->quizzes()->create(['title' => 'Quiz', 'due_at' => now()->addDay(), 'published' => true, 'max_attempts' => 1]);
        $quiz->questions()->create(['type' => 'true_false', 'prompt' => 'q', 'points' => 1]);
        $session = $offering->sessions()->create(['title' => 'L1', 'starts_at' => now(), 'ends_at' => now()->addHour()]);
        $session->forceFill(['checkin_code' => '123456', 'checkin_opens_at' => now()->subMinute(), 'checkin_closes_at' => now()->addHour()])->save();

        // Before archiving the student can do all of these...
        $this->as($student)->postJson("/api/quizzes/{$quiz->id}/attempts")->assertSuccessful();
        $this->as($student)->postJson("/api/sessions/{$session->id}/checkin", ['code' => '123456'])->assertOk();
        $this->as($this->admin)->patchJson("/api/offerings/{$offering->id}", ['archived' => true])->assertOk();

        $this->as($this->admin)->getJson('/api/offerings')->assertJsonCount(0, 'data');
        $this->as($this->admin)->getJson('/api/offerings?archived=only')->assertJsonCount(1, 'data');
        $this->as($student)->getJson('/api/offerings')->assertJsonCount(1, 'data')->assertJsonPath('data.0.archived_at', fn ($v) => $v !== null);
        $this->as($teacher)->getJson('/api/offerings')->assertJsonCount(1, 'data');
        $this->as($student)->getJson("/api/offerings/{$offering->id}")->assertOk();

        $this->as($student)->post("/api/assignments/{$assignment->id}/submissions", ['body' => 'late'], ['Accept' => 'application/json'])->assertForbidden();
        $this->as($this->userWithRole('student'))->postJson("/api/quizzes/{$quiz->id}/attempts")->assertForbidden();
        $other = $this->userWithRole('student');
        $this->as($this->registrar)->postJson("/api/offerings/{$offering->id}/enrolments", ['user_id' => $other->id, 'status' => 'active'])->assertUnprocessable()->assertJsonValidationErrors('offering');

        $this->as($this->admin)->patchJson("/api/offerings/{$offering->id}", ['archived' => false])->assertOk();
        $this->as($this->registrar)->postJson("/api/offerings/{$offering->id}/enrolments", ['user_id' => $other->id, 'status' => 'active'])->assertOk();
    }

    public function test_offerings_can_be_filtered_by_term_department_status_and_search(): void
    {
        $cs = Department::create(['code' => 'CS', 'name' => 'Computing']);
        $t1 = AcademicTerm::create(['name' => 'T1', 'starts_on' => $this->day(1), 'ends_on' => $this->day(50)]);
        $t2 = AcademicTerm::create(['name' => 'T2', 'starts_on' => $this->day(60), 'ends_on' => $this->day(120)]);
        $algo = Course::create(['code' => 'CSC201', 'title' => 'Algorithms', 'department_id' => $cs->id]);
        $calc = Course::create(['code' => 'MAT101', 'title' => 'Calculus']);
        CourseOffering::create(['course_id' => $algo->id, 'academic_term_id' => $t1->id, 'section' => 'A', 'published' => true]);
        CourseOffering::create(['course_id' => $calc->id, 'academic_term_id' => $t2->id, 'section' => 'A', 'published' => false]);

        $get = fn (string $q) => $this->as($this->admin)->getJson('/api/offerings?'.$q)->assertOk()->json('data');
        $this->assertCount(2, $get(''));
        $this->assertSame(['CSC201'], collect($get('term_id='.$t1->id))->pluck('course.code')->all());
        $this->assertSame(['CSC201'], collect($get('department_id='.$cs->id))->pluck('course.code')->all());
        $this->assertSame(['MAT101'], collect($get('status=draft'))->pluck('course.code')->all());
        $this->assertSame(['MAT101'], collect($get('q=calc'))->pluck('course.code')->all());
        $this->assertSame('CS', $get('term_id='.$t1->id)[0]['course']['department']['code']);
    }

    // ---- registration by students ------------------------------------------------------------------------------------

    public function test_a_student_sees_the_courses_they_can_register_for_and_whether_they_can_right_now(): void
    {
        $open = $this->openOffering(['capacity' => 5]);
        $closed = $this->openOffering([], ['registration_opens_on' => $this->day(20), 'registration_closes_on' => $this->day(30)]);
        $this->openOffering(['self_enrolment' => false]);      // registrar-only
        $this->openOffering(['published' => false]);           // not published
        $this->openOffering([], ['starts_on' => $this->day(-100), 'ends_on' => $this->day(-10)]); // term is over
        $student = $this->userWithRole('student');
        $this->enrol($open, $this->userWithRole('student'));

        $list = collect($this->as($student)->getJson('/api/registration')->assertOk()->json('data'))->keyBy('id');

        $this->assertEqualsCanonicalizing([$open->id, $closed->id], $list->keys()->all());
        $this->assertTrue($list[$open->id]['registration']['open']);
        $this->assertTrue($list[$open->id]['registration']['can_register']);
        $this->assertSame(4, $list[$open->id]['registration']['seats_left']);
        $this->assertFalse($list[$closed->id]['registration']['open']);
        $this->assertFalse($list[$closed->id]['registration']['can_register']);
        $this->as($student)->getJson('/api/registration?q=nothing-like-this')->assertJsonCount(0, 'data');
        foreach (['lecturer', 'registrar', 'super-admin'] as $role) {
            $this->as($this->userWithRole($role))->getJson('/api/registration')->assertForbidden();
        }
    }

    public function test_a_student_registers_while_registration_is_open_and_seats_remain(): void
    {
        $offering = $this->openOffering(['capacity' => 1]);
        [$ada, $ben] = [$this->userWithRole('student'), $this->userWithRole('student')];

        $this->as($ada)->postJson("/api/offerings/{$offering->id}/register")->assertCreated()->assertJsonPath('status', 'active');
        $this->as($ada)->postJson("/api/offerings/{$offering->id}/register")->assertCreated(); // twice is harmless
        $this->as($ben)->postJson("/api/offerings/{$offering->id}/register")->assertUnprocessable()->assertJsonValidationErrors('capacity');

        $this->assertSame(1, $offering->enrolments()->where('status', 'active')->count());
        $this->as($ada)->getJson("/api/offerings/{$offering->id}")->assertOk();
        $this->assertSame(1, DB::table('activity_log')->where('description', 'student registered')->count(), 'registering twice is recorded once');
    }

    public function test_a_teacher_generates_a_join_code_students_can_redeem(): void
    {
        $offering = $this->offering();
        $lecturer = $this->userWithRole('lecturer');
        $this->teach($offering, $lecturer);
        $student = $this->userWithRole('student');

        $this->as($this->userWithRole('student'))->postJson("/api/offerings/{$offering->id}/join-code")->assertForbidden();
        $code = $this->as($lecturer)->postJson("/api/offerings/{$offering->id}/join-code")->assertOk()->json('join_code');
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{6}$/', $code);

        $this->as($student)->postJson('/api/offerings/join', ['code' => strtolower($code)])->assertCreated()->assertJsonPath('enrolment.status', 'active');
        $this->assertSame(1, $offering->enrolments()->where('status', 'active')->where('user_id', $student->id)->count());

        // generating again revokes the old code
        $newCode = $this->as($lecturer)->postJson("/api/offerings/{$offering->id}/join-code")->assertOk()->json('join_code');
        $this->assertNotSame($code, $newCode);
        $this->as($this->userWithRole('student'))->postJson('/api/offerings/join', ['code' => $code])->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_a_join_code_is_refused_for_an_unpublished_or_full_offering(): void
    {
        $offering = $this->offering();
        $this->teach($offering, $this->userWithRole('lecturer'));
        $offering->update(['published' => false, 'join_code' => 'ABCDEF']);

        $this->as($this->userWithRole('student'))->postJson('/api/offerings/join', ['code' => 'ABCDEF'])->assertUnprocessable()->assertJsonValidationErrors('code');

        $offering->update(['published' => true, 'capacity' => 1]);
        $this->as($this->userWithRole('student'))->postJson('/api/offerings/join', ['code' => 'ABCDEF'])->assertCreated();
        $this->as($this->userWithRole('student'))->postJson('/api/offerings/join', ['code' => 'ABCDEF'])->assertUnprocessable()->assertJsonValidationErrors('capacity');
    }

    public function test_a_lecturer_not_assigned_to_the_offering_cannot_generate_its_join_code(): void
    {
        $offering = $this->offering();
        $stranger = $this->userWithRole('lecturer');

        $this->as($stranger)->postJson("/api/offerings/{$offering->id}/join-code")->assertForbidden();
    }

    public function test_registration_is_refused_when_the_window_is_closed_or_the_course_does_not_allow_it(): void
    {
        $student = $this->userWithRole('student');
        $before = $this->openOffering([], ['registration_opens_on' => $this->day(5), 'registration_closes_on' => $this->day(9)]);
        $after = $this->openOffering([], ['registration_opens_on' => $this->day(-9), 'registration_closes_on' => $this->day(-2)]);
        $none = $this->openOffering([], ['registration_opens_on' => null, 'registration_closes_on' => null]);
        $registrarOnly = $this->openOffering(['self_enrolment' => false]);
        $draft = $this->openOffering(['published' => false]);
        $archived = $this->openOffering(['archived_at' => now()]);

        foreach ([$before, $after, $none, $registrarOnly, $draft, $archived] as $offering) {
            $this->as($student)->postJson("/api/offerings/{$offering->id}/register")->assertUnprocessable()->assertJsonValidationErrors('offering');
        }
        $this->assertSame(0, DB::table('enrolments')->count());
        $this->as($this->registrar)->postJson("/api/offerings/{$before->id}/register")->assertForbidden();
    }

    public function test_a_student_can_drop_until_the_add_drop_deadline_and_then_only_the_registrar_can(): void
    {
        $offering = $this->openOffering([], ['add_drop_deadline' => $this->day(7)]);
        $student = $this->userWithRole('student');
        $this->as($student)->postJson("/api/offerings/{$offering->id}/register")->assertCreated();
        $this->as($student)->getJson('/api/registration')->assertJsonPath('data.0.registration.can_drop', true)->assertJsonPath('data.0.registration.drop_deadline', $this->day(7));

        $this->as($student)->deleteJson("/api/offerings/{$offering->id}/register")->assertOk();
        $this->assertSame('withdrawn', $offering->enrolments()->first()->status);
        $this->as($student)->deleteJson("/api/offerings/{$offering->id}/register")->assertUnprocessable(); // nothing to drop now
        $this->as($student)->postJson("/api/offerings/{$offering->id}/register")->assertCreated(); // and they may change their mind

        $this->travelTo(now()->addDays(8));
        $this->as($student)->deleteJson("/api/offerings/{$offering->id}/register")->assertUnprocessable()->assertJsonPath('errors.offering.0', fn ($m) => str_contains($m, 'deadline'));
        $this->assertSame('active', $offering->enrolments()->first()->status);
        $this->as($this->registrar)->postJson("/api/offerings/{$offering->id}/enrolments", ['user_id' => $student->id, 'status' => 'withdrawn'])->assertOk();
    }

    public function test_a_student_cannot_drop_a_course_the_registrar_enrolled_them_in(): void
    {
        $offering = $this->openOffering(['self_enrolment' => false]);
        $student = $this->userWithRole('student');
        $this->enrol($offering, $student);

        $this->as($student)->deleteJson("/api/offerings/{$offering->id}/register")->assertUnprocessable();
        $this->assertSame('active', $offering->enrolments()->first()->status);
    }

    public function test_without_an_add_drop_deadline_dropping_is_allowed_until_registration_closes(): void
    {
        $offering = $this->openOffering([], ['add_drop_deadline' => null]);
        $student = $this->userWithRole('student');
        $this->as($student)->postJson("/api/offerings/{$offering->id}/register")->assertCreated();

        $this->as($student)->getJson('/api/registration')->assertJsonPath('data.0.registration.drop_deadline', $this->day(5));
        $this->travelTo(now()->addDays(6));
        $this->as($student)->deleteJson("/api/offerings/{$offering->id}/register")->assertUnprocessable();
    }

    public function test_registration_days_follow_the_institutions_time_zone(): void
    {
        // Closes "today" in Auckland, which is already tomorrow in UTC: the window is still open all through the local day.
        config(['lms.institution.timezone' => 'Pacific/Auckland']);
        $today = now('Pacific/Auckland')->toDateString();
        $offering = $this->openOffering([], ['registration_opens_on' => $today, 'registration_closes_on' => $today, 'starts_on' => $today, 'ends_on' => now('Pacific/Auckland')->addDays(60)->toDateString()]);

        $this->as($this->userWithRole('student'))->postJson("/api/offerings/{$offering->id}/register")->assertCreated();
    }

    public function test_a_copied_offering_keeps_the_capacity_and_registration_setting_but_starts_unpublished(): void
    {
        $offering = $this->openOffering(['capacity' => 30]);
        $next = AcademicTerm::create(['name' => 'Next', 'starts_on' => $this->day(200), 'ends_on' => $this->day(300)]);

        $copy = $this->as($this->admin)->postJson("/api/offerings/{$offering->id}/copy", ['academic_term_id' => $next->id, 'section' => 'A'])->assertCreated()->json('offering');

        $this->assertSame(30, $copy['capacity']);
        $this->assertTrue($copy['self_enrolment']);
        $this->assertFalse($copy['published']);
    }
}
