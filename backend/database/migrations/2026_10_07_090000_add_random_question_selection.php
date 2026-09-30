<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Roadmap item 7, phase 7c: a quiz can draw a random subset of its question pool for each attempt, so different students (and different attempts) see different questions. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->unsignedInteger('questions_per_attempt')->nullable()->after('max_attempts');
        });

        Schema::create('quiz_attempt_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quiz_question_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('position');
            $table->timestamps();
            $table->unique(['quiz_attempt_id', 'quiz_question_id']);
            $table->index('quiz_question_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_attempt_questions');
        Schema::table('quizzes', function (Blueprint $table) {
            $table->dropColumn('questions_per_attempt');
        });
    }
};
