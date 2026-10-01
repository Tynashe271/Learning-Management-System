<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Roadmap item 11, phase 11a: weighted assessments and a continuous-assessment total. A weight is optional per item; the gradebook only computes a weighted percentage once at least one marked item for a student carries one. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->decimal('weight', 5, 2)->nullable()->after('max_score');
        });
        Schema::table('quizzes', function (Blueprint $table) {
            $table->decimal('weight', 5, 2)->nullable()->after('is_open_book');
        });
    }

    public function down(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->dropColumn('weight');
        });
        Schema::table('quizzes', function (Blueprint $table) {
            $table->dropColumn('weight');
        });
    }
};
