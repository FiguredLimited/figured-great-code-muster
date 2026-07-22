<?php

namespace App\Http\Controllers;

use App\Models\Farm;
use App\Models\WeatherMonth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function farms(): JsonResponse
    {
        return response()->json(Farm::orderBy('id')->get());
    }

    public function show(Farm $farm): JsonResponse
    {
        return response()->json([
            'farm' => $farm,
            'lines' => $farm->reportLines()->orderBy('month')->orderBy('id')->get(),
            'commentaries' => $farm->commentaries()->orderBy('month')->get(),
            // Regional conditions - the same for every farm.
            'weather' => WeatherMonth::orderBy('month')->get(),
        ]);
    }

    public function saveCommentary(Request $request, Farm $farm): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['required', 'date'],
            'body' => ['required', 'string'],
        ]);

        $commentary = $farm->commentaries()->updateOrCreate(
            ['month' => $validated['month']],
            ['body' => $validated['body']],
        );

        return response()->json($commentary);
    }
}
