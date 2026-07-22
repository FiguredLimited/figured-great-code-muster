<?php

namespace App\Http\Controllers;

use App\Models\StockClass;
use App\Models\StockMovement;
use App\Models\StockRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'classes' => StockClass::with('movements')->orderBy('id')->get(),
            'records' => StockRecord::orderBy('recorded_on')->orderBy('id')->get(),
        ]);
    }

    public function storeMovement(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'stock_class_id' => ['required', 'exists:stock_classes,id'],
            'type' => ['required', 'in:birth,purchase,death,sale'],
            'quantity' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string'],
        ]);

        return response()->json(StockMovement::create($validated), 201);
    }

    public function destroyMovement(StockMovement $stockMovement): JsonResponse
    {
        // Mis-keyed a movement? Delete it and key it again.
        $stockMovement->delete();

        return response()->json(['deleted' => true]);
    }
}
