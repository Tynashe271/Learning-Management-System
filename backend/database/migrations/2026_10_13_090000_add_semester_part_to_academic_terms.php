<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Which semester (1 First, 2 Second) and which half of it (1 or 2) a term is, so it can be labelled and coded (e.g. 1.1, 1.2, 2.1, 2.2). Nullable: older terms keep their free-form name until edited. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('academic_terms', function (Blueprint $table) {
            $table->unsignedTinyInteger('semester')->nullable()->after('name');
            $table->unsignedTinyInteger('part')->nullable()->after('semester');
        });
    }

    public function down(): void
    {
        Schema::table('academic_terms', function (Blueprint $table) {
            $table->dropColumn(['semester', 'part']);
        });
    }
};
