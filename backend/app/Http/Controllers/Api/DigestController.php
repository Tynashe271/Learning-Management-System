<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\DigestBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DigestController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json($this->preferences($request->user()));
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['digest_frequency' => ['required', Rule::in(User::DIGEST_FREQUENCIES)]]);
        $request->user()->forceFill($data)->save();

        return response()->json($this->preferences($request->user()));
    }

    /** What the next summary email would say right now. Nothing is sent and the schedule is not affected. */
    public function preview(Request $request, DigestBuilder $builder): JsonResponse
    {
        $since = $request->user()->digest_sent_at ?? now()->subDay();

        return response()->json(['since' => $since, 'summary' => $builder->build($request->user(), $since)]);
    }

    /** @return array<string, mixed> */
    private function preferences(User $user): array
    {
        return ['digest_frequency' => $user->digest_frequency, 'digest_sent_at' => $user->digest_sent_at];
    }
}
