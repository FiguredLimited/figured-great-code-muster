<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Server-side wrapper around the Anthropic Messages API, for backend features
 * that build their own prompt rather than proxying one from the browser.
 *
 * Same rules as AiController: the key comes from config('services.anthropic.key')
 * and never leaves the server.
 */
class Claude
{
    /** Seconds to wait on the API. A long structured reply can take well over a minute. */
    private const TIMEOUT = 120;

    /** Send a system + user prompt and return the concatenated text reply. */
    public static function ask(string $system, string $prompt, int $maxTokens = 8192): string
    {
        // PHP's own max_execution_time is 30s under `artisan serve`, which kills
        // the request mid-flight and reports it as a connection failure. Give the
        // script at least as long as we are prepared to wait on the API.
        set_time_limit(self::TIMEOUT + 30);

        $apiKey = config('services.anthropic.key');
        if (! $apiKey || str_starts_with($apiKey, 'sk-ant-your-key')) {
            throw new RuntimeException('No Anthropic API key configured. Set ANTHROPIC_API_KEY in .env (ask a Figgie for the key).');
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
            ])->timeout(self::TIMEOUT)->post('https://api.anthropic.com/v1/messages', [
                'model' => 'claude-sonnet-4-6',
                'max_tokens' => $maxTokens,
                'system' => $system,
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Could not reach the Anthropic API: '.$e->getMessage());
        }

        if ($response->failed()) {
            throw new RuntimeException($response->json('error.message') ?? 'Anthropic API request failed.');
        }

        return collect($response->json('content'))->where('type', 'text')->pluck('text')->implode('');
    }

    /**
     * Decode a JSON reply defensively. Models sometimes wrap JSON in markdown
     * fences or add a sentence either side, so take the outermost braces.
     */
    public static function decodeJson(string $text): ?array
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }
}
