<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The AI proxy. The frontend posts { system?, prompt } here; we call the
 * Anthropic Messages API with the key from .env and return { text }.
 *
 * The key stays on the server. Never call the Anthropic API from browser
 * code, and never put the key anywhere in the frontend.
 */
class AiController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'system' => ['nullable', 'string'],
            'prompt' => ['required', 'string'],
        ]);

        try {
            $text = self::ask($validated['prompt'], $validated['system'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], $e->getCode() ?: 500);
        }

        return response()->json(['text' => $text]);
    }

    /**
     * Call Claude and return the reply text. Other controllers use this when
     * they build a prompt server-side rather than taking one from the browser.
     *
     * Throws a RuntimeException whose code is the HTTP status to respond with.
     */
    public static function ask(string $prompt, ?string $system = null): string
    {
        // A long generation easily outruns PHP's default 30s max_execution_time,
        // which kills the request mid-call regardless of the HTTP timeout below.
        set_time_limit(180);

        $apiKey = config('services.anthropic.key');
        if (! $apiKey || str_starts_with($apiKey, 'sk-ant-your-key')) {
            throw new RuntimeException(
                'No Anthropic API key configured. Set ANTHROPIC_API_KEY in .env (ask a Figgie for the key).',
                500,
            );
        }

        $body = [
            'model' => 'claude-sonnet-4-6',
            'max_tokens' => 4096,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ];
        if (! empty($system)) {
            $body['system'] = $system;
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
            ])->timeout(120)->post('https://api.anthropic.com/v1/messages', $body);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Could not reach the Anthropic API: '.$e->getMessage(), 502);
        }

        if ($response->failed()) {
            throw new RuntimeException(
                $response->json('error.message') ?? 'Anthropic API request failed.',
                $response->status(),
            );
        }

        // The response content is a list of blocks; concatenate the text ones.
        return collect($response->json('content'))
            ->where('type', 'text')
            ->pluck('text')
            ->implode('');
    }
}
