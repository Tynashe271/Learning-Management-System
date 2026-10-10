<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Lecturer resource sharing: a module an owning teacher marks shared can be browsed and imported by any other teacher. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_modules', function (Blueprint $table) {
            $table->boolean('is_shared')->default(false)->after('published');
        });
    }

    public function down(): void
    {
        Schema::table('course_modules', function (Blueprint $table) {
            $table->dropColumn('is_shared');
        });
    }
};
