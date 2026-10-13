<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class AcademicTerm extends Model
{
    protected $fillable = ['name', 'semester', 'part', 'starts_on', 'ends_on', 'academic_year', 'registration_opens_on', 'registration_closes_on', 'add_drop_deadline', 'is_current'];

    protected $appends = ['code'];

    protected function casts(): array
    {
        return ['is_current' => 'boolean', 'semester' => 'integer', 'part' => 'integer'];
    }

    /** "1.1", "1.2", "2.1" or "2.2" (semester.part); null until both are set. */
    protected function code(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->semester && $this->part ? "{$this->semester}.{$this->part}" : null,
        );
    }

    /** True while students may register (self-enrol) for this term's courses. Today is judged in the institution's time zone. */
    public function registrationIsOpen(): bool
    {
        if ($this->registration_opens_on === null || $this->registration_closes_on === null) {
            return false;
        }
        $today = now(config('lms.institution.timezone'))->toDateString();

        return $today >= substr((string) $this->registration_opens_on, 0, 10) && $today <= substr((string) $this->registration_closes_on, 0, 10);
    }

    /** The last day a student may drop a course on their own: the add/drop deadline, or else the close of registration. */
    public function dropDeadline(): ?string
    {
        $date = $this->add_drop_deadline ?? $this->registration_closes_on;

        return $date === null ? null : substr((string) $date, 0, 10);
    }

    public function dropIsAllowed(): bool
    {
        $deadline = $this->dropDeadline();

        return $deadline !== null && now(config('lms.institution.timezone'))->toDateString() <= $deadline;
    }
}
