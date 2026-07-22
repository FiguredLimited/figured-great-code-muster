<?php

namespace App\Http\Controllers;

use App\Models\BankTransaction;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BankTransactionController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'categories' => Category::orderBy('id')->get(),
            'transactions' => BankTransaction::orderBy('transacted_on')->orderBy('id')->get(),
        ]);
    }

    public function update(Request $request, BankTransaction $bankTransaction): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => ['nullable', 'exists:categories,id'],
        ]);

        $bankTransaction->update($validated);

        return response()->json($bankTransaction);
    }
}
