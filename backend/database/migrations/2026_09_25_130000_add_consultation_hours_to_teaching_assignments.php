<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Lets a teacher publish when students can reach them for this course, e.g. "Tuesdays 2-4pm, Room 204, or by appointment". */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teaching_assignments', function (Blueprint $table) {
            $table->string('consultation_hours', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('teaching_assignments', function (Blueprint $table) {
            $table->dropColumn('consultation_hours');
        });
    }
};
