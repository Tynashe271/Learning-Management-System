<?php

namespace App\Rules;

use App\Services\ScannerUnavailable;
use App\Services\VirusScanner;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/** Rejects an upload the virus scanner flags. Does nothing while scanning is switched off. */
class CleanFile implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $scanner = app(VirusScanner::class);
        if (! $scanner->enabled() || ! $value instanceof UploadedFile) {
            return;
        }
        try {
            $signature = $scanner->scan($value->getRealPath());
        } catch (ScannerUnavailable $e) {
            if (config('lms.virus_scan.fail_open')) {
                Log::warning('Upload accepted without a virus scan: '.$e->getMessage());

                return;
            }
            Log::error('Upload refused, virus scanner unavailable: '.$e->getMessage());
            $fail('Uploads are temporarily unavailable because the virus scanner cannot be reached. Please try again shortly.');

            return;
        }
        if ($signature !== null) {
            Log::warning('Upload rejected by the virus scanner', ['signature' => $signature, 'user_id' => request()->user()?->id]);
            $fail('The file was rejected because it may contain a virus.');
        }
    }
}
