<?php

namespace App\Http\Controllers;

use App\Models\StockClass;
use App\Models\StockMovement;
use App\Models\StockRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class StockController extends Controller
{
    private const MOVEMENT_TYPES = ['birth', 'purchase', 'death', 'sale'];

    private const PROPOSAL_FLAGS = [
        'duplicate',
        'superseded',
        'same_event',
        'source_mislabelled',
        'quantity_estimated',
    ];

    private const PARSE_SYSTEM_PROMPT = <<<'SYSTEM'
        You are a stock reconciliation clerk at a rural accounting practice in New Zealand. You read
        a farmer's raw records - diary notes, sale dockets, text messages - and turn them into stock
        movements. A movement is one of birth, purchase, death or sale, and the year must satisfy
        opening + births + purchases - deaths - sales = closing.

        Farmers keep messy records. The same sale turns up twice, a text message corrects an earlier
        one, a docket gets filed under the wrong heading, and quantities are often words rather than
        numbers. Flag anything you are not certain of rather than resolving it silently.

        Reply with JSON only. No prose, no markdown fences.
        SYSTEM;

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

    /**
     * Read the whole paper trail and propose the movements it implies.
     *
     * Claude does the deciphering; everything it hands back is re-checked here
     * against the database before it goes to the browser. Anything that fails a
     * check is reported in `unresolved` rather than quietly dropped.
     */
    public function parseRecords(): JsonResponse
    {
        $classes = StockClass::orderBy('id')->get();
        $records = StockRecord::orderBy('recorded_on')->orderBy('id')->get();

        try {
            $text = AiController::ask($this->parsePrompt($classes, $records), self::PARSE_SYSTEM_PROMPT);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], $e->getCode() ?: 500);
        }

        // We asked for bare JSON, but models like to wrap it in a fence anyway.
        $json = trim($text);
        if (str_starts_with($json, '```')) {
            $json = trim(preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $json));
        }

        $parsed = json_decode($json, true);
        if (! is_array($parsed)) {
            return response()->json([
                'error' => 'Claude did not return valid JSON. The raw reply is below.',
                'raw' => $text,
            ], 502);
        }

        return response()->json($this->validateProposals($parsed, $classes, $records));
    }

    /**
     * Re-check Claude's output against the database. Stock class ids in
     * particular are resolved here, never taken from the model.
     */
    private function validateProposals(array $parsed, $classes, $records): array
    {
        $classIds = $classes->mapWithKeys(fn ($c) => [mb_strtolower($c->name) => $c->id]);
        $recordIds = $records->pluck('id');

        $proposals = [];
        $unresolved = [];

        // Anything Claude itself could not pin down comes through as-is. Entries
        // naming no record are summaries rather than unresolved records, so drop them.
        foreach ($parsed['unresolved'] ?? [] as $item) {
            $ids = array_values(array_intersect(
                array_map('intval', (array) ($item['record_ids'] ?? [])),
                $recordIds->all(),
            ));

            if ($ids) {
                $unresolved[] = [
                    'record_ids' => $ids,
                    'reason' => (string) ($item['reason'] ?? 'No reason given.'),
                ];
            }
        }

        foreach ($parsed['proposals'] ?? [] as $proposal) {
            $className = mb_strtolower(trim((string) ($proposal['stock_class'] ?? '')));
            $type = mb_strtolower(trim((string) ($proposal['type'] ?? '')));
            $quantity = $proposal['quantity'] ?? null;

            $ids = array_values(array_intersect(
                array_map('intval', (array) ($proposal['record_ids'] ?? [])),
                $recordIds->all(),
            ));

            $reject = match (true) {
                ! $classIds->has($className) => "Unknown stock class '{$className}'.",
                ! in_array($type, self::MOVEMENT_TYPES, true) => "Unknown movement type '{$type}'.",
                ! is_numeric($quantity) || (int) $quantity < 1 => 'Quantity was missing or not a positive whole number.',
                default => null,
            };

            if ($reject) {
                $unresolved[] = ['record_ids' => $ids, 'reason' => $reject];

                continue;
            }

            $flag = mb_strtolower(trim((string) ($proposal['flag'] ?? '')));
            $flag = in_array($flag, self::PROPOSAL_FLAGS, true) ? $flag : null;

            $proposals[] = [
                'record_ids' => $ids,
                'stock_class' => $classes->firstWhere('id', $classIds[$className])->name,
                'stock_class_id' => $classIds[$className],
                'type' => $type,
                'confidence' => round(min(1, max(0, (float) ($proposal['confidence'] ?? 0.5))), 2),
                'quantity' => (int) $quantity,
                'note' => (string) ($proposal['note'] ?? ''),
                'include' => (bool) ($proposal['include'] ?? true),
                'flag' => $flag,
                'reasoning' => (string) ($proposal['reasoning'] ?? ''),
            ];
        }

        return ['proposals' => $proposals, 'unresolved' => $unresolved];
    }

    private function parsePrompt($classes, $records): string
    {
        $classLines = $classes
            ->map(fn ($c) => "- {$c->name}: opening {$c->opening_count}, farmer's recorded closing {$c->closing_count}")
            ->implode("\n");

        $recordLines = $records
            ->map(fn ($r) => "{$r->id} | {$r->recorded_on->format('Y-m-d')} | {$r->source} | {$r->body}")
            ->implode("\n");

        return <<<PROMPT
        Farm: Kahikatea Downs (sheep and beef). Stock year 1 Jul 2025 - 30 Jun 2026.

        Stock classes:
        {$classLines}

        The paper trail, as `id | date | source | body`:
        {$recordLines}

        How to read these records:
        - A docking tally is the count of lambs born. Calves "on the ground" are cattle births.
        - Home kill ("killed 2 lambs for the freezer") is a death, not a sale.
        - Cull ewes are still Ewes; cull cows, calves, steers and heifers are all Cattle.
        - Trust the body over the source label. A row labelled "Sale docket" whose body says
          "purchase docket" is a purchase, which increases the count rather than decreasing it.
        - The same event can appear twice: the same docket number on two dates, or a docket and a
          diary note describing the same head count within a week or two. Emit it ONCE, listing
          every record id it came from, with flag "duplicate" (same docket) or "same_event"
          (docket plus diary), and include: false so a human confirms before it is keyed.
        - A later record can correct an earlier one. Emit the corrected quantity with flag
          "superseded" and include: false.
        - Turn vague quantities into numbers where the wording supports it ("a dozen" is 12, "Two"
          is 2) and flag those "quantity_estimated", keeping include: true with lower confidence.
          Where no defensible number exists, put the record in "unresolved" instead of guessing.
          Never invent a head count to make a tally balance.
        - note must cite where it came from, e.g. "Docket S-40102, 12 Dec 2025" or "Diary 19 Oct 2025".
        - confidence is how sure you are of the movement TYPE, from 0 to 1.

        "unresolved" is only for records you could not turn into a movement at all. If you already
        emitted a record as a flagged proposal, do not repeat it in "unresolved". Every entry must
        name at least one record id: no summaries, totals or reconciliation workings go in there.

        The opening and closing counts above are a sanity check, not a target. Never adjust or invent
        a quantity to make a tally balance - if the movements do not reach the recorded closing
        count, leave it unbalanced.

        Return JSON in exactly this shape and nothing else. Keep each "reasoning" to one sentence.

        {
          "proposals": [
            {
              "record_ids": [3],
              "stock_class": "Lambs",
              "type": "birth",
              "confidence": 0.98,
              "quantity": 1240,
              "note": "Docking tally, diary 6 Oct 2025",
              "include": true,
              "flag": null,
              "reasoning": "A docking tally is the count of lambs born."
            }
          ],
          "unresolved": [
            { "record_ids": [21], "reason": "Why this record could not be turned into a movement." }
          ]
        }
        PROMPT;
    }
}
