<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Roadmap item 14, phase 14a: an AI-use declaration on a submission, and a lecturer's academic-integrity case record. Plagiarism/similarity screening, submission version history and the grade-appeal process already exist. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->boolean('used_ai')->default(false)->after('late_explanation');
            $table->text('ai_use_description')->nullable()->after('used_ai');
        });

        Schema::create('integrity_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_offering_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('submission_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reported_by')->constrained('users')->restrictOnDelete();
            $table->text('description');
            $table->string('status')->default('open');
            $table->text('outcome')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['course_offering_id', 'user_id']);
            $table->index('user_id');
            $table->index('submission_id');
            $table->index('reported_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrity_cases');
        Schema::table('submissions', function (Blueprint $table) {
            $table->dropColumn(['used_ai', 'ai_use_description']);
        });
    }
};
