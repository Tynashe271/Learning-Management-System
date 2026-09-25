<?php

namespace App\Services;

use App\Models\CourseOffering;
use App\Models\Enrolment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** The rules for taking a place in an offering, in one place so registrars, imports and students' own registration all follow them. */
class EnrolmentService
{
    /**
     * Gives the person an active place. Refused when the offering is archived, or full (unless `$override`, which only an
     * administrator may pass). Safe to call for someone who already has a place. The check and the write happen together
     * under a lock on the offering, so two people cannot both take the last seat.
     */
    public function activate(CourseOffering $offering, int $userId, bool $override = false): Enrolment
    {
        return DB::transaction(function () use ($offering, $userId, $override) {
            $locked = CourseOffering::whereKey($offering->id)->lockForUpdate()->firstOrFail();
            if ($locked->archived_at !== null) {
                throw ValidationException::withMessages(['offering' => 'This offering is archived, so nobody can be enrolled in it.']);
            }
            $existing = Enrolment::where('course_offering_id', $locked->id)->where('user_id', $userId)->first();
            if ($existing?->status === 'active') {
                return $existing;
            }
            if (! $override && $locked->isFull()) {
                throw ValidationException::withMessages(['capacity' => "This offering is full ({$locked->capacity} places)."]);
            }

            return Enrolment::updateOrCreate(['course_offering_id' => $locked->id, 'user_id' => $userId], ['status' => 'active']);
        });
    }

    public function withdraw(CourseOffering $offering, int $userId): Enrolment
    {
        return Enrolment::updateOrCreate(['course_offering_id' => $offering->id, 'user_id' => $userId], ['status' => 'withdrawn']);
    }
}
