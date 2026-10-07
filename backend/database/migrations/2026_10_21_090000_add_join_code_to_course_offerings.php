<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A teacher-generated code students can redeem to join an offering directly, instead of browsing the self-registration list. */
    public function up(): void
    {
        Schema::table('course_offerings', function (Blueprint $table) {
            $table->string('join_code', 12)->nullable()->unique()->after('self_enrolment');
        });
    }

    public function down(): void
    {
        Schema::table('course_offerings', function (Blueprint $table) {
            $table->dropColumn('join_code');
        });
    }
};
