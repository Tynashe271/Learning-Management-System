<?php

namespace App\Support;

use App\Models\CourseOffering;
use App\Models\Enrolment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;

/** Page-by-page results with a hard ceiling, so no single request can ask for a whole large class at once. */
class Paging
{
    public const DEFAULT = 100;

    public const MAX = 200;

    /**
     * Reads ?page= and ?per_page= (at most MAX) and returns the items on that page plus a `meta` block.
     *
     * @return array{0: Collection, 1: array{page: int, per_page: int, total: int, last_page: int}}
     */
    public static function page(Request $request, Builder|Relation $query, int $default = self::DEFAULT, int $max = self::MAX): array
    {
        $input = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.$max]]);
        $paginator = $query->paginate($input['per_page'] ?? $default, ['*'], 'page', $input['page'] ?? 1);

        return [$paginator->getCollection(), [
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ]];
    }

    /** Actively enrolled students of an offering, alphabetical by name, with their user loaded. */
    public static function activeStudents(CourseOffering $offering): Builder
    {
        return Enrolment::where('course_offering_id', $offering->id)->where('status', 'active')->with('user:id,name,email')
            ->orderByRaw('(select lower(name) from users where users.id = enrolments.user_id)')->orderBy('id');
    }
}
