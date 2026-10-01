<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Roadmap item 17, phase 17a: extended-time assessment accommodations. A documented, per-student percentage added to every timed quiz's time limit in a course - set once, applied automatically everywhere, rather than reconfigured quiz by quiz. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accommodations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_offering_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('extra_time_percent');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['course_offering_id', 'user_id']);
            $table->index('user_id');
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accommodations');
    }
};
