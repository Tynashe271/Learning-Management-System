<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Roadmap item 6, phase 6b: draft submissions, lecturer feedback on a draft, controlled resubmission, and version history. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->boolean('allow_resubmission')->default(false)->after('allow_late_submissions');
        });

        Schema::table('submissions', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1)->after('late_explanation');
        });

        Schema::create('submission_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->text('body')->nullable();
            $table->string('storage_path')->nullable();
            $table->timestampTz('submitted_at');
            $table->timestamps();
            $table->unique(['submission_id', 'version']);
        });

        Schema::create('submission_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->index('submission_id');
            $table->index('author_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_feedback');
        Schema::dropIfExists('submission_versions');
        Schema::table('submissions', function (Blueprint $table) {
            $table->dropColumn('version');
        });
        Schema::table('assignments', function (Blueprint $table) {
            $table->dropColumn('allow_resubmission');
        });
    }
};
