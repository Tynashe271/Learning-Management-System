<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A lecturer's own reusable library of quiz questions, copied into a quiz's real questions when used rather than referenced live. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_bank_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('course_offering_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->text('prompt');
            $table->unsignedInteger('points')->default(1);
            $table->json('tags')->nullable();
            $table->timestamps();
            $table->index('owner_id');
            $table->index('course_offering_id');
        });

        Schema::create('question_bank_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_bank_item_id')->constrained()->cascadeOnDelete();
            $table->string('text');
            $table->boolean('is_correct')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->index('question_bank_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_bank_options');
        Schema::dropIfExists('question_bank_items');
    }
};
