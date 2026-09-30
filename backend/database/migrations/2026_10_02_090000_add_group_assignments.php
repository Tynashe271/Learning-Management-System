<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Roadmap item 6, phase 6c: group assignments. A group's members each keep their own submission row (so grading, appeals, the gradebook and notifications need no change) but share content and a grade, fanned out on submit and on grade. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->boolean('is_group_assignment')->default(false)->after('allow_resubmission');
        });

        Schema::create('assignment_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
            $table->index('assignment_id');
        });

        Schema::create('assignment_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['assignment_group_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::table('submissions', function (Blueprint $table) {
            $table->foreignId('assignment_group_id')->nullable()->after('assignment_id')->constrained()->nullOnDelete();
            $table->index('assignment_group_id');
        });
    }

    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assignment_group_id');
        });
        Schema::dropIfExists('assignment_group_members');
        Schema::dropIfExists('assignment_groups');
        Schema::table('assignments', function (Blueprint $table) {
            $table->dropColumn('is_group_assignment');
        });
    }
};
