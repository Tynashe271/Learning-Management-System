<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Roadmap item 6, phase 6e: a grader can attach a short voice or video note to a grade, alongside or instead of written feedback. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grade_records', function (Blueprint $table) {
            $table->string('feedback_recording_path')->nullable()->after('feedback');
        });
    }

    public function down(): void
    {
        Schema::table('grade_records', function (Blueprint $table) {
            $table->dropColumn('feedback_recording_path');
        });
    }
};
