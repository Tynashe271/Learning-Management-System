<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Roadmap item 6, phase 6d: peer review. A lecturer assigns each student a handful of classmates' submissions to review anonymously. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->unsignedInteger('peer_reviews_per_student')->default(0)->after('is_group_assignment');
        });

        Schema::create('peer_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->text('body')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestamps();
            $table->unique(['assignment_id', 'reviewer_id', 'submission_id']);
            $table->index('reviewer_id');
            $table->index('submission_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('peer_reviews');
        Schema::table('assignments', function (Blueprint $table) {
            $table->dropColumn('peer_reviews_per_student');
        });
    }
};
