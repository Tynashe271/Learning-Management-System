<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PostgreSQL does not index foreign-key columns on its own, so every "this student's enrolments", "this quiz's questions", or
 * "this submission's grades" lookup was scanning its whole table. Each index below serves a query the API actually runs.
 */
return new class extends Migration
{
    /** @var array<string, list<list<string>>> */
    private const INDEXES = [
        'course_offerings' => [['academic_term_id']],
        'teaching_assignments' => [['user_id']],
        'enrolments' => [['user_id', 'status']],
        'course_modules' => [['course_offering_id', 'position']],
        'learning_items' => [['course_module_id', 'position']],
        'assignments' => [['course_offering_id', 'due_at']],
        'submissions' => [['user_id']],
        'grade_records' => [['submission_id', 'status', 'id'], ['graded_by']],
        'announcements' => [['user_id']],
        'quizzes' => [['course_offering_id', 'due_at']],
        'quiz_questions' => [['quiz_id', 'position']],
        'quiz_options' => [['quiz_question_id', 'position']],
        'quiz_attempts' => [['user_id']],
        'quiz_answers' => [['quiz_question_id']],
        'item_completions' => [['user_id']],
        'discussion_threads' => [['user_id']],
        'discussion_posts' => [['discussion_thread_id', 'id'], ['user_id']],
        'rubric_criteria' => [['assignment_id', 'position']],
        'rubric_levels' => [['rubric_criterion_id', 'position']],
        'attendance_records' => [['user_id'], ['marked_by']],
        'grade_appeals' => [['user_id'], ['grade_record_id'], ['resolved_by']],
        'message_attachments' => [['direct_message_id']],
        'users' => [['digest_frequency', 'is_active']],
        'role_has_permissions' => [['role_id']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) use ($table, $indexes) {
                foreach ($indexes as $columns) {
                    $blueprint->index($columns, $this->name($table, $columns));
                }
            });
        }
        // Sign-in looks people up by lower-cased email, and "Ada@uni.edu" and "ada@uni.edu" must not be two accounts.
        DB::statement('create unique index users_email_lower_unique on users (lower(email))');
    }

    public function down(): void
    {
        DB::statement('drop index if exists users_email_lower_unique');
        foreach (self::INDEXES as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) use ($table, $indexes) {
                foreach ($indexes as $columns) {
                    $blueprint->dropIndex($this->name($table, $columns));
                }
            });
        }
    }

    /** @param  list<string>  $columns */
    private function name(string $table, array $columns): string
    {
        return 'idx_'.$table.'_'.implode('_', $columns);
    }
};
