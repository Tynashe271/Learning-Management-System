<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Roadmap item 10, phase 10a: the research and project workspace - a student's project topic, supervisor allocation, milestones and meeting records. Draft chapters, similarity and rubric-based assessment reuse the existing assignment, similarity and rubric features rather than duplicating them. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_offering_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('proposed');
            $table->foreignId('supervisor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['course_offering_id', 'user_id']);
            $table->index('user_id');
            $table->index('supervisor_id');
        });

        Schema::create('project_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->date('due_on');
            $table->timestampTz('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index('project_id');
        });

        Schema::create('project_meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->date('occurred_on');
            $table->text('notes');
            $table->foreignId('logged_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index('project_id');
            $table->index('logged_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_meetings');
        Schema::dropIfExists('project_milestones');
        Schema::dropIfExists('projects');
    }
};
