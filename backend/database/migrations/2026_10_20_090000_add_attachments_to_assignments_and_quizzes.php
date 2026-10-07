<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A lecturer or TA can attach one file (e.g. a question paper) to an assignment or quiz, stored the same way a course file is. */
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->string('storage_path')->nullable()->after('instructions');
        });
        Schema::table('quizzes', function (Blueprint $table) {
            $table->string('storage_path')->nullable()->after('instructions');
        });
    }

    public function down(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->dropColumn('storage_path');
        });
        Schema::table('quizzes', function (Blueprint $table) {
            $table->dropColumn('storage_path');
        });
    }
};
