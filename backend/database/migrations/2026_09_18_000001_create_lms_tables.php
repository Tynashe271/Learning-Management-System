<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_terms', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamps();
        });
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::create('course_offerings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_term_id')->constrained()->restrictOnDelete();
            $table->string('section');
            $table->boolean('published')->default(false);
            $table->timestamps();
            $table->unique(['course_id', 'academic_term_id', 'section']);
        });
        Schema::create('teaching_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_offering_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['course_offering_id', 'user_id']);
        });
        Schema::create('enrolments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_offering_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('active');
            $table->timestamps();
            $table->unique(['course_offering_id', 'user_id']);
        });
        Schema::create('course_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_offering_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->unsignedInteger('position')->default(0);
            $table->boolean('published')->default(false);
            $table->timestamps();
        });
        Schema::create('learning_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_module_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('type');
            $table->text('body')->nullable();
            $table->string('storage_path')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('published')->default(false);
            $table->timestamps();
        });
        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_offering_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('instructions')->nullable();
            $table->timestampTz('due_at');
            $table->unsignedInteger('max_score');
            $table->boolean('published')->default(false);
            $table->timestamps();
        });
        Schema::create('submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('body')->nullable();
            $table->string('storage_path')->nullable();
            $table->timestampTz('submitted_at');
            $table->timestamps();
            $table->unique(['assignment_id', 'user_id']);
        });
        Schema::create('grade_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained()->restrictOnDelete();
            $table->foreignId('graded_by')->constrained('users')->restrictOnDelete();
            $table->decimal('score', 8, 2);
            $table->string('status')->default('draft');
            $table->text('feedback')->nullable();
            $table->text('change_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['grade_records', 'submissions', 'assignments', 'learning_items', 'course_modules', 'enrolments', 'teaching_assignments', 'course_offerings', 'courses', 'academic_terms'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
