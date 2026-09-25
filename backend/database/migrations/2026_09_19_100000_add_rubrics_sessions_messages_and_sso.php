<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('sso_subject')->nullable()->unique();
        });
        Schema::table('grade_records', function (Blueprint $table) {
            $table->json('criteria_scores')->nullable();
        });
        // Welcome-link tokens live apart from password-reset tokens so one kind can never stand in for the other.
        Schema::create('account_invitation_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
        Schema::create('rubric_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('max_points');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
        Schema::create('class_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_offering_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('join_url', 2048)->nullable();
            $table->string('location')->nullable();
            $table->timestamps();
            $table->index(['course_offering_id', 'starts_at']);
        });
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->string('note', 500)->nullable();
            $table->foreignId('marked_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['class_session_id', 'user_id']);
        });
        Schema::create('direct_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->restrictOnDelete();
            $table->text('body');
            $table->timestampTz('read_at')->nullable();
            $table->timestamps();
            $table->index(['recipient_id', 'read_at']);
            $table->index(['sender_id', 'recipient_id', 'id']);
        });
    }

    public function down(): void
    {
        foreach (['direct_messages', 'attendance_records', 'class_sessions', 'rubric_criteria', 'account_invitation_tokens'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('grade_records', function (Blueprint $table) {
            $table->dropColumn('criteria_scores');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['sso_subject']);
            $table->dropColumn('sso_subject');
        });
    }
};
