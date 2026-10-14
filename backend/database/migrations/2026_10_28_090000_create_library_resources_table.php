<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Resource library: textbooks, past exam papers and other reading material teaching staff share institution-wide. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_resources', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('category');
            $table->string('course_code', 20)->nullable();
            $table->text('description')->nullable();
            $table->string('storage_path');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['category', 'course_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_resources');
    }
};
