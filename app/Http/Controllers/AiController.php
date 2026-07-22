<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

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

        $apiKey = config('services.anthropic.key');
        if (! $apiKey || str_starts_with($apiKey, 'sk-ant-your-key')) {
            return response()->json([
                'error' => 'No Anthropic API key configured. Set ANTHROPIC_API_KEY in .env (ask a Figgie for the key).',
            ], 500);
        }

        $body = [
            'model' => 'claude-sonnet-4-6',
            'max_tokens' => 4096,
            'messages' => [
                ['role' => 'user', 'content' => $validated['prompt']],
            ],
        ];
        if (! empty($validated['system'])) {
            $body['system'] = $validated['system'];
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
            ])->timeout(120)->post('https://api.anthropic.com/v1/messages', $body);
        } catch (ConnectionException $e) {
            return response()->json(['error' => 'Could not reach the Anthropic API: '.$e->getMessage()], 502);
        }

        if ($response->failed()) {
            return response()->json([
                'error' => $response->json('error.message') ?? 'Anthropic API request failed.',
            ], $response->status());
        }

        // The response content is a list of blocks; concatenate the text ones.
        $text = collect($response->json('content'))
            ->where('type', 'text')
            ->pluck('text')
            ->implode('');

        return response()->json(['text' => $text]);
    }
}
