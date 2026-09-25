<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseOffering extends Model
{
    protected $fillable = ['course_id', 'academic_term_id', 'section', 'published', 'capacity', 'self_enrolment', 'archived_at'];

    protected function casts(): array
    {
        return ['published' => 'boolean', 'self_enrolment' => 'boolean', 'capacity' => 'integer', 'archived_at' => 'datetime'];
    }

    /** Students currently holding a place. */
    public function activeEnrolmentCount(): int
    {
        return $this->enrolments()->where('status', 'active')->count();
    }

    public function isFull(): bool
    {
        return $this->capacity !== null && $this->activeEnrolmentCount() >= $this->capacity;
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class, 'academic_term_id');
    }

    public function modules(): HasMany
    {
        return $this->hasMany(CourseModule::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    public function enrolments(): HasMany
    {
        return $this->hasMany(Enrolment::class);
    }

    public function teachers(): HasMany
    {
        return $this->hasMany(TeachingAssignment::class);
    }

    public function announcements(): HasMany
    {
        return $this->hasMany(Announcement::class);
    }

    public function quizzes(): HasMany
    {
        return $this->hasMany(Quiz::class);
    }

    public function threads(): HasMany
    {
        return $this->hasMany(DiscussionThread::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(ClassSession::class);
    }
}
