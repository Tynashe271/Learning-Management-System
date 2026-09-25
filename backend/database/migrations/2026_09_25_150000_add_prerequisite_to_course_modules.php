<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A topic can require a prior topic be completed first. Nullable and self-referencing, so a deleted prerequisite just un-gates the topic rather than deleting it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_modules', function (Blueprint $table) {
            $table->foreignId('prerequisite_module_id')->nullable()->after('position')->constrained('course_modules')->nullOnDelete();
            $table->index('prerequisite_module_id');
        });
    }

    public function down(): void
    {
        Schema::table('course_modules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('prerequisite_module_id');
        });
    }
};
