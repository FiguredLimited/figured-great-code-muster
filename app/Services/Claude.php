<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
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

    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    private const MODEL = 'claude-sonnet-4-6';

    /**
     * Send several prompts at once and return their replies keyed the same way.
     *
     * Latency here is dominated by output tokens, so splitting one long reply
     * into two shorter ones that generate concurrently costs roughly the slower
     * half rather than the sum.
     *
     * @param  array<string, array{system: string, prompt: string}>  $requests
     * @return array<string, string>
     */
    public static function askMany(array $requests, int $maxTokens = 8192): array
    {
        $apiKey = self::apiKey();
        set_time_limit(self::TIMEOUT + 30);

        $responses = Http::pool(fn (Pool $pool) => collect($requests)
            ->map(fn (array $request, string $key) => $pool->as($key)
                ->withHeaders([
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                ])
                ->timeout(self::TIMEOUT)
                ->post(self::ENDPOINT, [
                    'model' => self::MODEL,
                    'max_tokens' => $maxTokens,
                    'system' => $request['system'],
                    'messages' => [['role' => 'user', 'content' => $request['prompt']]],
                ]))
            ->all());

        $replies = [];
        foreach (array_keys($requests) as $key) {
            $response = $responses[$key] ?? null;

            if ($response instanceof \Throwable) {
                throw new RuntimeException('Could not reach the Anthropic API: '.$response->getMessage());
            }
            if ($response === null || $response->failed()) {
                throw new RuntimeException($response?->json('error.message') ?? 'Anthropic API request failed.');
            }

            $replies[$key] = self::textOf($response->json('content'));
        }

        return $replies;
    }

    /** Send a system + user prompt and return the concatenated text reply. */
    public static function ask(string $system, string $prompt, int $maxTokens = 8192): string
    {
        // PHP's own max_execution_time is 30s under `artisan serve`, which kills
        // the request mid-flight and reports it as a connection failure. Give the
        // script at least as long as we are prepared to wait on the API.
        set_time_limit(self::TIMEOUT + 30);

        $apiKey = self::apiKey();
        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
            ])->timeout(self::TIMEOUT)->post(self::ENDPOINT, [
                'model' => self::MODEL,
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

        return self::textOf($response->json('content'));
    }

    private static function apiKey(): string
    {
        $apiKey = config('services.anthropic.key');
        if (! $apiKey || str_starts_with($apiKey, 'sk-ant-your-key')) {
            throw new RuntimeException('No Anthropic API key configured. Set ANTHROPIC_API_KEY in .env (ask a Figgie for the key).');
        }

        return $apiKey;
    }

    /** The response content is a list of blocks; concatenate the text ones. */
    private static function textOf(?array $content): string
    {
        return collect($content)->where('type', 'text')->pluck('text')->implode('');
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
