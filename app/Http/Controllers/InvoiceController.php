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
            'invoice_number' => ['nullable', 'string'],
            'gst_number' => ['nullable', 'string'],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date'],
            'total' => ['required', 'numeric'],
            'category_id' => ['required', 'exists:categories,id'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string'],
            'lines.*.quantity' => ['nullable', 'numeric'],
            'lines.*.unit_price' => ['nullable', 'numeric'],
            'lines.*.amount' => ['required', 'numeric'],
        ]);

        $invoice->update([
            'supplier' => $validated['supplier'],
            'invoice_number' => $validated['invoice_number'] ?? null,
            'gst_number' => $validated['gst_number'] ?? null,
            'invoice_date' => $validated['invoice_date'],
            'due_date' => $validated['due_date'] ?? null,
            'total' => $validated['total'],
            'category_id' => $validated['category_id'],
            'entered_at' => now(),
        ]);

        // Re-entering an invoice replaces its lines rather than appending.
        $invoice->lines()->delete();
        $invoice->lines()->createMany($validated['lines']);

        return response()->json($invoice->load('lines'));
    }

    public function destroy(Invoice $invoice): JsonResponse
    {
        $invoice->delete();
        return response()->json(['message' => 'Invoice deleted']);
    }

    public function reset(Invoice $invoice): JsonResponse
    {
        $invoice->update([
            'supplier' => null,
            'invoice_number' => null,
            'gst_number' => null,
            'invoice_date' => null,
            'due_date' => null,
            'total' => null,
            'category_id' => null,
            'entered_at' => null,
        ]);
        $invoice->lines()->delete();

        return response()->json($invoice->load('lines'));
    }

    /**
     * Upload one or more .txt invoice files. Creates new Invoice records
     * with the file contents as raw_text, ready for manual or AI-assisted entry.
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['required', 'file', 'max:2048'],
        ]);

        foreach ($request->file('files') as $file) {
            $invoice = new Invoice;
            $invoice->filename = $file->getClientOriginalName();
            $invoice->raw_text = $file->get();
            $invoice->save();
        }

        return response()->json([
            'invoices' => Invoice::with('lines')->orderBy('filename')->get(),
        ], 201);
    }
}
