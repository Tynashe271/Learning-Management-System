<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Roadmap item 9, phase 9a: the industrial attachment workspace - a student's placement, their weekly logbook, and a secure link a workplace supervisor (who has no LMS account) uses to leave a rating and comment. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachment_placements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_offering_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('organisation');
            $table->string('supervisor_name');
            $table->string('supervisor_email');
            $table->text('objectives')->nullable();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedTinyInteger('supervisor_rating')->nullable();
            $table->text('supervisor_comment')->nullable();
            $table->timestampTz('supervisor_submitted_at')->nullable();
            $table->timestamps();
            $table->unique(['course_offering_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('attachment_logbook_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('placement_id')->constrained('attachment_placements')->cascadeOnDelete();
            $table->date('week_ending');
            $table->decimal('hours', 5, 2);
            $table->text('activities');
            $table->string('evidence_path')->nullable();
            $table->timestamps();
            $table->unique(['placement_id', 'week_ending']);
        });

        Schema::create('attachment_feedback_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('placement_id')->constrained('attachment_placements')->cascadeOnDelete();
            $table->string('token_hash');
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestamps();
            $table->index('placement_id');
            $table->index('token_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachment_feedback_tokens');
        Schema::dropIfExists('attachment_logbook_entries');
        Schema::dropIfExists('attachment_placements');
    }
};
