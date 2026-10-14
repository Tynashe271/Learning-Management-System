<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * A thin wrapper around the configured AI provider, off by default like this app's other optional integrations
 * (single sign-on, virus scanning). Supports Anthropic's Messages API (default) or DeepSeek's OpenAI-compatible
 * chat completions API - the two differ in auth header, request shape and where the reply text lives in the response.
 */
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
        $deepseek = config('lms.ai.provider') === 'deepseek';
        try {
            $response = $deepseek
                ? Http::withToken(config('lms.ai.api_key'))->timeout(45)->post(config('lms.ai.base_url'), [
                    'model' => config('lms.ai.model'),
                    'max_tokens' => config('lms.ai.max_tokens'),
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ])
                : Http::withHeaders([
                    'x-api-key' => config('lms.ai.api_key'),
                    'anthropic-version' => '2023-06-01',
                ])->timeout(45)->post(config('lms.ai.base_url'), [
                    'model' => config('lms.ai.model'),
                    'max_tokens' => config('lms.ai.max_tokens'),
                    'system' => $system,
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                ]);
        } catch (Throwable $e) {
            throw new AiUnavailable('The AI service could not be reached: '.$e->getMessage());
        }
        if ($response->failed()) {
            throw new AiUnavailable('The AI service returned an error: '.Str::limit($response->body(), 200));
        }
        $text = $deepseek
            ? $response->json('choices.0.message.content')
            : collect($response->json('content'))->firstWhere('type', 'text')['text'] ?? null;
        if (! $text) {
            throw new AiUnavailable('The AI service returned an empty response.');
        }

        return $text;
    }
}
