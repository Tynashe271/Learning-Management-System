<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\TransformsRequest;
use Illuminate\Support\Str;

/**
 * Cleans text before it is validated or stored: broken UTF-8 and control characters are removed everywhere, and HTML tags
 * are stripped from short plain-text fields such as names and titles. Passwords are never touched. Longer free text (message
 * and announcement bodies) is stored as typed; the frontend must escape it when displaying, which frameworks such as React do
 * by default.
 */
class SanitizeInput extends TransformsRequest
{
    /** @var list<string> */
    private const UNTOUCHED = ['password', 'current_password', 'password_confirmation', 'token', 'state', 'code'];

    /** @var list<string> */
    private const PLAIN_TEXT = ['name', 'title', 'section', 'location', 'label'];

    protected function transform($key, $value)
    {
        if (! is_string($value)) {
            return $value;
        }
        $leaf = Str::afterLast((string) $key, '.');
        if (in_array($leaf, self::UNTOUCHED, true)) {
            return $value;
        }
        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';

        return in_array($leaf, self::PLAIN_TEXT, true) ? trim(strip_tags($value)) : $value;
    }
}
