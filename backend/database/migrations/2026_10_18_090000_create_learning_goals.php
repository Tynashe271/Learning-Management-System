<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Roadmap item 18, phase 18a: engagement and motivation. A student's own personal learning goal - free text, not tied to any course or grade. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 255);
            $table->date('target_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_goals');
    }
};
