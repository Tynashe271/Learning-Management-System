<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Roadmap item 7, phase 7d: an open/closed-book label on a quiz. This is honesty, not enforcement - nothing here can check what a student has open elsewhere. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->boolean('is_open_book')->default(false)->after('questions_per_attempt');
        });
    }

    public function down(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->dropColumn('is_open_book');
        });
    }
};
