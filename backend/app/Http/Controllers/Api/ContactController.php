<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Enrolment;
use App\Models\TeachingAssignment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    /**
     * People the signed-in user may start a private message with, so a frontend can offer a "new message" picker. It mirrors
     * the rule the message endpoint enforces: staff who manage users or enrolments may write to anyone active; everyone else
     * may write to the teachers and classmates of the courses they teach or are actively enrolled in. Emails are only shown
     * to those staff, so a student cannot harvest classmates' addresses. `?q=` filters by name.
     */
    public function index(Request $request): JsonResponse
    {
        $me = $request->user();
        $data = $request->validate(['q' => ['sometimes', 'string', 'max:100']]);
        $staff = $me->can('manage-users') || $me->can('manage-enrolments');

        $query = User::where('is_active', true)->where('id', '!=', $me->id)->with('roles:id,name')->orderBy('name')->orderBy('id');
        if (! $staff) {
            $offerings = TeachingAssignment::where('user_id', $me->id)->pluck('course_offering_id')
                ->merge(Enrolment::where('user_id', $me->id)->where('status', 'active')->pluck('course_offering_id'))->unique();
            $query->where(fn ($q) => $q
                ->whereIn('id', TeachingAssignment::whereIn('course_offering_id', $offerings)->select('user_id'))
                ->orWhereIn('id', Enrolment::whereIn('course_offering_id', $offerings)->where('status', 'active')->select('user_id')));
        }
        if (isset($data['q'])) {
            // '!' is the LIKE escape character: a backslash breaks PDO's placeholder parsing on PostgreSQL.
            $query->whereRaw("lower(name) like ? escape '!'", ['%'.strtolower(preg_replace('/[!%_]/', '!$0', $data['q'])).'%']);
        }

        return response()->json($query->paginate(30, $staff ? ['id', 'name', 'email'] : ['id', 'name']));
    }
}
