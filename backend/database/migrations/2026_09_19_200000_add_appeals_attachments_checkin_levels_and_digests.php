<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grade_appeals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            // The published grade that was challenged, so the appeal keeps pointing at it if the grade is later revised.
            $table->foreignId('grade_record_id')->constrained()->restrictOnDelete();
            $table->text('reason');
            $table->string('status')->default('open');
            $table->text('response')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestamps();
            $table->index('status');
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
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->string('checkin_code', 8)->nullable();
            $table->timestampTz('checkin_opens_at')->nullable();
            $table->timestampTz('checkin_closes_at')->nullable();
        });
        Schema::table('attendance_records', function (Blueprint $table) {
            // 'staff' when a teacher marked it, 'self' when the student checked in with the class code.
            $table->string('source')->default('staff');
        });
        Schema::create('rubric_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rubric_criterion_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('points');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->string('digest_frequency')->default('off');
            $table->timestampTz('digest_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['digest_frequency', 'digest_sent_at']);
        });
        Schema::dropIfExists('rubric_levels');
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropColumn('source');
        });
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->dropColumn(['checkin_code', 'checkin_opens_at', 'checkin_closes_at']);
        });
        Schema::dropIfExists('message_attachments');
        Schema::dropIfExists('grade_appeals');
    }
};
