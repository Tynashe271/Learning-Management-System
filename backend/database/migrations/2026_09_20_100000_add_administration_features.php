<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Institution-wide settings an administrator can change without touching server files.
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value');
            $table->foreignId('updated_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Faculties and departments, so courses can be grouped and reported on.
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('id')->index()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('credits')->nullable();
            $table->string('level', 30)->nullable();
            $table->timestamp('archived_at')->nullable();
        });

        Schema::table('academic_terms', function (Blueprint $table) {
            $table->string('academic_year', 20)->nullable();
            $table->date('registration_opens_on')->nullable();
            $table->date('registration_closes_on')->nullable();
            $table->date('add_drop_deadline')->nullable();
            $table->boolean('is_current')->default(false);
        });

        Schema::table('course_offerings', function (Blueprint $table) {
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->boolean('self_enrolment')->default(false);
            $table->timestamp('archived_at')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('anonymised_at')->nullable();
        });

        // Messages shown to everyone (or to some roles) at the top of every screen.
        Schema::create('system_announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->string('severity', 10)->default('info');
            $table->json('audience')->nullable(); // role names; null means everyone
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Sign-ins, lockouts, password changes and refused requests, kept where an administrator can search them.
        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->string('event', 60)->index();
            $table->unsignedBigInteger('user_id')->nullable()->index(); // not a foreign key: the history outlives the account
            $table->string('email_hash', 16)->nullable()->index();
            $table->string('ip', 45)->nullable();
            $table->string('level', 10)->default('info');
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
        Schema::dropIfExists('system_announcements');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['last_login_at', 'anonymised_at']));
        Schema::table('course_offerings', fn (Blueprint $t) => $t->dropColumn(['capacity', 'self_enrolment', 'archived_at']));
        Schema::table('academic_terms', fn (Blueprint $t) => $t->dropColumn(['academic_year', 'registration_opens_on', 'registration_closes_on', 'add_drop_deadline', 'is_current']));
        Schema::table('courses', function (Blueprint $t) {
            $t->dropConstrainedForeignId('department_id');
            $t->dropColumn(['credits', 'level', 'archived_at']);
        });
        Schema::dropIfExists('departments');
        Schema::dropIfExists('settings');
    }
};
