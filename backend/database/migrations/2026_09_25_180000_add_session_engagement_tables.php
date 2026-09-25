<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Live tools for a scheduled class session that need no video infrastructure: hand-raising, a live Q&A queue, and single-choice polls. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_hand_raises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('raised_at');
            $table->timestamps();
            $table->unique(['class_session_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('session_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('body', 500);
            $table->timestampTz('answered_at')->nullable();
            $table->timestamps();
            $table->index(['class_session_id', 'id']);
            $table->index('user_id');
        });

        Schema::create('session_polls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_session_id')->constrained()->cascadeOnDelete();
            $table->string('question', 255);
            $table->timestampTz('closed_at')->nullable();
            $table->timestamps();
            $table->index('class_session_id');
        });

        Schema::create('session_poll_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_poll_id')->constrained()->cascadeOnDelete();
            $table->string('text', 200);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->index('session_poll_id');
        });

        Schema::create('session_poll_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_poll_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_poll_option_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['session_poll_id', 'user_id']);
            $table->index('session_poll_option_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_poll_responses');
        Schema::dropIfExists('session_poll_options');
        Schema::dropIfExists('session_polls');
        Schema::dropIfExists('session_questions');
        Schema::dropIfExists('session_hand_raises');
    }
};
