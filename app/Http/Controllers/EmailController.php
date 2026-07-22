<?php

namespace App\Http\Controllers;

use App\Models\Email;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Email::orderByDesc('received_at')->get());
    }

    public function reply(Request $request, Email $email): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string'],
        ]);

        // "Sending" just saves the reply against the email - nothing actually
        // goes out, so reply as freely as you like.
        $email->update([
            'reply_body' => $validated['body'],
            'replied_at' => now(),
        ]);

        return response()->json($email);
    }
}
