<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** No rows for an assignment/quiz means it is visible to the whole class, exactly as before; a lecturer targeting specific students adds rows here instead. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignment_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['assignment_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('quiz_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['quiz_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_targets');
        Schema::dropIfExists('assignment_targets');
    }
};
