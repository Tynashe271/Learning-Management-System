<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Roadmap item 12, phase 12a: the learning intervention centre. At-risk signals are computed on the fly (missing work, a declining grade trend, inactivity); an intervention plan is the only new record kept. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intervention_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_offering_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->text('action_plan')->nullable();
            $table->string('status')->default('open');
            $table->timestampTz('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['course_offering_id', 'user_id']);
            $table->index('user_id');
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intervention_plans');
    }
};
