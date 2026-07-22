<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'categories' => Category::orderBy('id')->get(),
            'invoices' => Invoice::with('lines')->orderBy('filename')->get(),
        ]);
    }

    public function update(Request $request, Invoice $invoice): JsonResponse
    {
        $validated = $request->validate([
            'supplier' => ['required', 'string'],
            'invoice_date' => ['required', 'date'],
            'total' => ['required', 'numeric'],
            'category_id' => ['required', 'exists:categories,id'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string'],
            'lines.*.amount' => ['required', 'numeric'],
        ]);

        $invoice->update([
            'supplier' => $validated['supplier'],
            'invoice_date' => $validated['invoice_date'],
            'total' => $validated['total'],
            'category_id' => $validated['category_id'],
            'entered_at' => now(),
        ]);

        // Re-entering an invoice replaces its lines rather than appending.
        $invoice->lines()->delete();
        $invoice->lines()->createMany($validated['lines']);

        return response()->json($invoice->load('lines'));
    }
}
