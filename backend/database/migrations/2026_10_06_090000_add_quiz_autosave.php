<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Roadmap item 7, phase 7b: auto-save during examinations. A periodic draft of in-progress answers, kept server-side so it survives a crashed browser, a cleared cache, or switching devices - not just a lost connection. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->json('draft_answers')->nullable()->after('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->dropColumn('draft_answers');
        });
    }
};
