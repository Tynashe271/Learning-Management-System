<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Lets an assignment or quiz belong to a module, so course content can be grouped by topic like the rest of a module's items. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->foreignId('course_module_id')->nullable()->after('course_offering_id')->constrained('course_modules')->nullOnDelete();
            $table->index('course_module_id');
        });
        Schema::table('quizzes', function (Blueprint $table) {
            $table->foreignId('course_module_id')->nullable()->after('course_offering_id')->constrained('course_modules')->nullOnDelete();
            $table->index('course_module_id');
        });
    }

    public function down(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('course_module_id');
        });
        Schema::table('quizzes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('course_module_id');
        });
    }
};
