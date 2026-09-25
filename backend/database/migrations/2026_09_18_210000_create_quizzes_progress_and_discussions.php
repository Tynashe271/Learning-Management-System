<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quizzes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_offering_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('instructions')->nullable();
            $table->timestampTz('opens_at')->nullable();
            $table->timestampTz('due_at');
            $table->unsignedSmallInteger('time_limit_minutes')->nullable();
            $table->unsignedTinyInteger('max_attempts')->default(1);
            $table->boolean('published')->default(false);
            $table->timestamps();
        });
        Schema::create('quiz_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->text('prompt');
            $table->unsignedInteger('points')->default(1);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
        Schema::create('quiz_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_question_id')->constrained()->cascadeOnDelete();
            $table->string('text');
            $table->boolean('is_correct')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
        Schema::create('quiz_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestampTz('started_at');
            $table->timestampTz('submitted_at')->nullable();
            $table->decimal('score', 8, 2)->nullable();
            $table->decimal('max_score', 8, 2)->nullable();
            $table->timestamps();
            $table->index(['quiz_id', 'user_id']);
        });
        Schema::create('quiz_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quiz_question_id')->constrained()->restrictOnDelete();
            $table->json('response')->nullable();
            $table->boolean('is_correct')->default(false);
            $table->decimal('points', 8, 2)->default(0);
            $table->timestamps();
            $table->unique(['quiz_attempt_id', 'quiz_question_id']);
        });
        Schema::create('item_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learning_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['learning_item_id', 'user_id']);
        });
        Schema::create('discussion_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_offering_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->text('body');
            $table->timestamps();
            $table->index(['course_offering_id', 'updated_at']);
        });
        Schema::create('discussion_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('discussion_thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['discussion_posts', 'discussion_threads', 'item_completions', 'quiz_answers', 'quiz_attempts', 'quiz_options', 'quiz_questions', 'quizzes'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
