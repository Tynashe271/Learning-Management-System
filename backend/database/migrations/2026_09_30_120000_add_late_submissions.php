<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Roadmap item 6: late-submission explanations. A lecturer opts an assignment into accepting late work; a late submitter must say why. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->boolean('allow_late_submissions')->default(false)->after('published');
        });

        Schema::table('submissions', function (Blueprint $table) {
            $table->boolean('late')->default(false)->after('submitted_at');
            $table->text('late_explanation')->nullable()->after('late');
        });
    }

    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->dropColumn(['late', 'late_explanation']);
        });
        Schema::table('assignments', function (Blueprint $table) {
            $table->dropColumn('allow_late_submissions');
        });
    }
};
