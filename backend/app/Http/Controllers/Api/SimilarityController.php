<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\Submission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Screens a class's submissions against each other for copied text. It compares written answers and plain-text files
 * (.txt, .md, .csv) using overlapping five-word phrases. It cannot see the web or older cohorts, and a high score is a
 * reason to look, not proof of copying.
 */
class SimilarityController extends Controller
{
    private const SHINGLE_WORDS = 5;

    private const MIN_WORDS = 20;

    private const MAX_SUBMISSIONS = 300;

    private const MAX_FILE_BYTES = 512 * 1024;

    public function show(Request $request, Assignment $assignment): JsonResponse
    {
        $this->authorize('grade', $assignment);
        $threshold = (float) ($request->validate(['threshold' => ['sometimes', 'numeric', 'min:0.1', 'max:1']])['threshold'] ?? 0.5);
        $submissions = $assignment->submissions()->with('user:id,name,email')->get();
        if ($submissions->count() > self::MAX_SUBMISSIONS) {
            throw ValidationException::withMessages(['assignment' => 'Too many submissions to compare in one request (the limit is '.self::MAX_SUBMISSIONS.').']);
        }

        $sets = [];
        $skipped = 0;
        foreach ($submissions as $submission) {
            $words = $this->words($this->textOf($submission));
            if (count($words) < self::MIN_WORDS) {
                $skipped++;

                continue;
            }
            $sets[$submission->id] = $this->shingles($words);
        }

        $byId = $submissions->keyBy('id');
        $pairs = [];
        $ids = array_keys($sets);
        for ($i = 0, $n = count($ids); $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $shared = count(array_intersect_key($sets[$ids[$i]], $sets[$ids[$j]]));
                $similarity = $shared / (count($sets[$ids[$i]]) + count($sets[$ids[$j]]) - $shared);
                if ($similarity >= $threshold) {
                    $pairs[] = [
                        'similarity' => round($similarity, 4),
                        'a' => ['submission_id' => $ids[$i], 'user' => $byId[$ids[$i]]->user->only(['id', 'name', 'email'])],
                        'b' => ['submission_id' => $ids[$j], 'user' => $byId[$ids[$j]]->user->only(['id', 'name', 'email'])],
                    ];
                }
            }
        }
        usort($pairs, fn ($x, $y) => $y['similarity'] <=> $x['similarity']);
        activity()->causedBy($request->user())->performedOn($assignment)->withProperties(['threshold' => $threshold, 'flagged' => count($pairs)])->log('similarity check run');

        return response()->json([
            'threshold' => $threshold,
            'compared' => count($sets),
            'skipped_too_short_or_unreadable' => $skipped,
            'pairs' => array_slice($pairs, 0, 200),
            'note' => 'Screening only: it compares this class against itself. Review flagged pairs by hand before acting on them.',
        ]);
    }

    private function textOf(Submission $submission): string
    {
        $text = (string) $submission->body;
        if ($submission->storage_path && in_array(strtolower(pathinfo($submission->storage_path, PATHINFO_EXTENSION)), ['txt', 'md', 'csv'], true)) {
            try {
                if (Storage::disk('s3')->size($submission->storage_path) <= self::MAX_FILE_BYTES) {
                    $text .= ' '.Storage::disk('s3')->get($submission->storage_path);
                }
            } catch (Throwable) {
                // A missing or unreadable file just leaves the written answer to compare.
            }
        }

        return $text;
    }

    /** @return list<string> */
    private function words(string $text): array
    {
        return preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * @param  list<string>  $words
     * @return array<int, true> set of hashed phrases
     */
    private function shingles(array $words): array
    {
        $set = [];
        for ($i = 0, $n = count($words) - self::SHINGLE_WORDS + 1; $i < $n; $i++) {
            $set[crc32(implode(' ', array_slice($words, $i, self::SHINGLE_WORDS)))] = true;
        }

        return $set;
    }
}
