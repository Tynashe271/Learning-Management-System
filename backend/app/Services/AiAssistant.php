<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/** A thin wrapper around the Claude API, off by default like this app's other optional integrations (single sign-on, virus scanning). */
class AiAssistant
{
    public function enabled(): bool
    {
        return (bool) config('lms.ai.enabled') && filled(config('lms.ai.api_key'));
    }

    /**
     * @throws AiUnavailable when the assistant is not configured or the API call fails
     */
    public function complete(string $system, string $prompt): string
    {
        if (! $this->enabled()) {
            throw new AiUnavailable('The AI assistant is not configured.');
        }
        try {
            $response = Http::withHeaders([
                'x-api-key' => config('lms.ai.api_key'),
                'anthropic-version' => '2023-06-01',
            ])->timeout(30)->post(config('lms.ai.base_url'), [
                'model' => config('lms.ai.model'),
                'max_tokens' => 1024,
                'system' => $system,
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ]);
        } catch (Throwable $e) {
            throw new AiUnavailable('The AI service could not be reached: '.$e->getMessage());
        }
        if ($response->failed()) {
            throw new AiUnavailable('The AI service returned an error: '.Str::limit($response->body(), 200));
        }
        $text = collect($response->json('content'))->firstWhere('type', 'text')['text'] ?? null;
        if (! $text) {
            throw new AiUnavailable('The AI service returned an empty response.');
        }

        return $text;
    }
}
