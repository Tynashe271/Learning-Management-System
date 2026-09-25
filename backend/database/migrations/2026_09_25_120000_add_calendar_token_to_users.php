<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A secret token for the personal calendar-subscription feed. Calendar apps cannot send an Authorization header, so the URL itself is the credential. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('calendar_token', 40)->nullable()->unique()->after('digest_frequency');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('calendar_token');
        });
    }
};
