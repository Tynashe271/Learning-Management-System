<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The private-messaging feature was removed; these tables are no longer read or written by the application. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('message_attachments');
        Schema::dropIfExists('direct_messages');
    }

    public function down(): void
    {
        Schema::create('direct_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->restrictOnDelete();
            $table->text('body');
            $table->timestampTz('read_at')->nullable();
            $table->timestamps();
            $table->index(['recipient_id', 'read_at']);
            $table->index(['sender_id', 'recipient_id', 'id']);
        });
        Schema::create('message_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('direct_message_id')->constrained()->cascadeOnDelete();
            $table->string('original_name');
            $table->string('storage_path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size');
            $table->timestamps();
        });
    }
};
