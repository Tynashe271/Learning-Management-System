<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The in-app video room for a class session, created on demand the first time someone joins (see the video integration). */
    public function up(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->string('video_room_url')->nullable()->after('join_url');
        });
    }

    public function down(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->dropColumn('video_room_url');
        });
    }
};
