<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The personal learning-goals feature was removed; this table is no longer read or written by the application. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('learning_goals');
    }

    public function down(): void
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
};
