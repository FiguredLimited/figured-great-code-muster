<?php

namespace App\Http\Controllers;

use App\Models\BankTransaction;
use App\Models\Email;
use App\Models\Farm;
use App\Models\ReportLine;
use App\Models\StockClass;
use App\Models\StockMovement;
use App\Models\StockRecord;
use App\Services\Claude;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use RuntimeException;

class StockController extends Controller
{
    /**
     * What Claude is allowed to say. Anything outside these lists is dropped
     * before the suggestion reaches the browser.
     */
    private const TYPES = ['birth', 'purchase', 'death', 'sale'];

    private const FLAGS = ['duplicate', 'correction', 'estimate', 'mislabelled'];

    private const CONFIDENCE = ['high', 'medium', 'low'];

    private const CORROBORATION = ['confirmed', 'unconfirmed', 'contradicted'];

    /** This page is Kahikatea Downs; the corroborating evidence is scoped to it where it can be. */
    private const FARM = 'Kahikatea Downs';

    /** Bank narrations worth showing the model. The feed is not farm-scoped, so this is a net, not a filter. */
    private const BANK_KEYWORDS = ['LIVESTOCK', 'PGG', 'NZ FARMERS', 'CARRFIELDS', 'SALEYARD', 'STOCK AGENT'];

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
     * Read the raw paper trail and propose the movements it implies.
     *
     * The division of labour matters: Claude only *classifies* prose - which
     * stock class, which of the four movement types, how many, and whether a
     * record duplicates or corrects another. The arithmetic stays in code, on
     * both sides of the wire, so nothing can quietly balance a class by
     * inventing an animal. Nothing is written to the database here either -
     * every proposal goes back to the adviser to accept or reject.
     */
    public function suggestMovements(): JsonResponse
    {
        $classes = StockClass::with('movements')->orderBy('id')->get();
        $records = StockRecord::orderBy('recorded_on')->orderBy('id')->get();

        try {
            $reply = Claude::ask($this->systemPrompt(), $this->dataPrompt($classes, $records));
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        $decoded = Claude::decodeJson($reply);
        if ($decoded === null) {
            return response()->json(['error' => 'Could not read the suggestion as JSON. Try again.'], 502);
        }

        $proposals = $this->cleanProposals($decoded['proposals'] ?? [], $classes, $records);
        $skipped = $this->cleanSkipped($decoded['skipped'] ?? [], $records);
        [$proposals, $skipped] = $this->preferLatestDuplicates($proposals, $skipped, $records);

        return response()->json([
            'proposals' => $proposals,
            'skipped' => $skipped,
            'gaps' => $this->cleanGaps($decoded['gaps'] ?? []),
            'residuals' => $this->cleanResiduals($decoded['residuals'] ?? [], $classes, $records),
        ]);
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
        You are helping a rural accounting adviser in New Zealand reconcile a sheep
        and beef farm's livestock for the stock year 1 July 2025 - 30 June 2026.

        For each stock class the reconciliation is:
            opening + births + purchases - deaths - sales = closing

        Read the farm's raw paper trail - diary notes, sale dockets, text messages -
        and propose the stock movements it implies. You are also given corroborating
        evidence from elsewhere in the practice's records: the bank feed, the client's
        emails, and the farm's monthly income report. Check every proposal against it.

        You classify; the app does the arithmetic. Never invent, round or pad a
        movement to make a class balance. A class that does not balance is a real
        finding and the adviser needs to see it.

        THE RULE THAT MATTERS MOST
        A movement may only come from the paper trail. Every proposal must cite at
        least one paper trail record id in "source_record_ids". Money is not an
        animal: a bank deposit or a report figure can CONFIRM a movement you already
        read in the paper trail, but it can never CREATE one, and you must never
        divide a dollar amount by a price to arrive at a head count. Only a docket or
        a diary note tells you how many animals moved.

        If the other records show a sale or purchase that has no paper trail behind it
        at all, that is not a proposal. Put it in "gaps" so the adviser can go and ask
        the farmer for the missing docket.

        WHAT THE CORROBORATING EVIDENCE IS WORTH
        - The bank feed is NOT scoped to one farm - the practice's fixture feed mixes
          clients, and it contains dairy income that cannot be this farm's. Treat an
          exact amount match as strong corroboration and say so, but never treat it as
          proof, and never attribute a deposit to this farm on narration alone.
        - The monthly report lines ARE scoped to this farm. Income in a month with no
          docket behind it is a genuine gap worth raising.
        - Deposits are money in: sales. Payments out are purchases. A purchase docket
          will have no deposit, and that absence is itself confirmation it is a purchase.
        - Counting the deposits is the way to settle whether two identical dockets are
          one sale filed twice or two real sales. One deposit means one sale.

        Rules:
        - Every proposal is one of four types: birth, purchase, death, sale. Quantity
          is always a positive whole number - the type carries the direction.
        - Work out the stock class from the words used. Ewes, two-tooths and cull ewes
          are Ewes. Docked or sold lambs are Lambs. Cows, calves, heifers, steers,
          R2 steers and cull cows are Cattle.
        - Lambs born this year stay in the Lambs class for this reconciliation.
        - Animals lost to weather, illness, calving or lambing trouble are deaths. So
          is homekill - an animal killed for the freezer has left the flock.
        - One real-world event, one movement. The same event often appears twice: the
          same docket number entered on two dates, or a docket and a diary note
          describing the same mob leaving. Propose it once and put the other in
          "skipped" so the adviser can see what you ignored.
        - When two records repeat the SAME information - same docket number, same
          count, same price - cite the LATEST one and skip the earlier. The later
          entry is the one that survived the farmer's own filing, and it is usually
          the one the bank deposit sits against.
        - A later record can correct an earlier one. Propose only the corrected figure,
          flag it "correction", and put the superseded record in "skipped".
        - The "source" label on a record can be wrong. Read the body: a purchase docket
          filed under "Sale docket" is a purchase. Flag those "mislabelled".
        - Quantities may be words ("a dozen") or approximate ("about 15", "probably
          more up the back gully"). Use the best figure actually stated, flag it
          "estimate", and say in the reasoning exactly what is uncertain.
        - Movements already entered are listed for you. Do not propose them again.
        - If a record implies no movement, leave it out of all three lists.

        Reply with JSON and nothing else - no prose, no markdown fences:

        {
          "proposals": [
            {
              "stock_class_id": 1,
              "type": "sale",
              "quantity": 210,
              "note": "Docket S-40102, 12 Dec 2025",
              "source_record_ids": [8],
              "confidence": "high",
              "flag": null,
              "corroboration": "confirmed",
              "evidence": "Bank 12 Dec 2025 PGG LIVESTOCK PROCEEDS $26,880.00 matches the docket total exactly.",
              "reasoning": "One short sentence on how you read the record."
            }
          ],
          "skipped": [
            {
              "source_record_ids": [12],
              "flag": "duplicate",
              "reason": "Same docket S-40417 as record 11, and only one deposit of $25,560.00 in the bank."
            }
          ],
          "gaps": [
            {
              "title": "Short label for what is missing",
              "detail": "What the other records show, why there is no movement for it, and what to ask the farmer.",
              "evidence": "The specific bank line or report figure, with date and amount."
            }
          ],
          "residuals": [
            {
              "stock_class_id": 1,
              "likely_cause": "One sentence naming the most likely reason this class will not balance.",
              "source_record_ids": [21],
              "ask_the_farmer": "The question the adviser should put to the farmer to settle it."
            }
          ]
        }

        "residuals" is your read of which classes will still not balance once your
        proposals are keyed in, and why. Do NOT put a number in it - the app works out
        the size of any difference itself. Give a residual entry only where the records
        genuinely point at a cause; if a class should balance cleanly, leave it out.

        "note" is what gets saved against the movement: under 100 characters, pointing
        at the source document. "flag" is null unless one of duplicate, correction,
        estimate or mislabelled applies. "confidence" is high, medium or low.
        "corroboration" is "confirmed" if other records back this movement up,
        "contradicted" if they disagree with it, "unconfirmed" if nothing outside the
        paper trail speaks to it - which is normal and fine for deaths and births,
        since no money moves. Always fill in "evidence" with what you actually checked.
        PROMPT;
    }

    private function dataPrompt(Collection $classes, Collection $records): string
    {
        $classLines = $classes->map(function (StockClass $class) {
            $entered = $class->movements
                ->map(fn (StockMovement $m) => "{$m->type} {$m->quantity}".($m->note ? " ({$m->note})" : ''))
                ->implode('; ');

            return "id={$class->id} | {$class->name} | opening {$class->opening_count} "
                ."| closing recorded by the farmer {$class->closing_count} "
                .'| already entered: '.($entered !== '' ? $entered : 'none');
        })->implode("\n");

        $recordLines = $records->map(
            fn (StockRecord $r) => "id={$r->id} | {$r->recorded_on->format('Y-m-d')} | {$r->source} | {$r->body}"
        )->implode("\n");

        $evidence = $this->evidencePrompt();

        return <<<TEXT
        STOCK CLASSES
        $classLines

        THE PAPER TRAIL - the only thing a movement may be built from
        $recordLines

        $evidence
        TEXT;
    }

    /**
     * Corroborating records from the rest of the practice's database. None of
     * this may create a movement; it exists so the adviser is told whether the
     * money agrees with the paperwork.
     */
    private function evidencePrompt(): string
    {
        $bank = BankTransaction::where(function ($query) {
            foreach (self::BANK_KEYWORDS as $keyword) {
                $query->orWhere('description', 'like', "%{$keyword}%");
            }
        })->orderBy('transacted_on')->get()
            ->map(fn (BankTransaction $t) => sprintf(
                '%s | %s | %s%s',
                $t->transacted_on->format('Y-m-d'),
                $t->description,
                $t->amount < 0 ? '-' : '+',
                number_format(abs((float) $t->amount), 2)
            ))->implode("\n");

        $farm = Farm::where('name', self::FARM)->first();

        $reportLines = $farm
            ? ReportLine::where('farm_id', $farm->id)
                ->whereIn('category', ['Lamb Sales', 'Cattle Sales', 'Wool Income'])
                ->where('actual', '!=', 0)
                ->orderBy('month')->get()
                ->map(fn (ReportLine $l) => sprintf(
                    '%s | %s | actual $%s',
                    $l->month->format('Y-m'),
                    $l->category,
                    number_format((float) $l->actual, 2)
                ))->implode("\n")
            : '';

        // The client's own words, scoped by sender - this farm's emails only.
        $emails = Email::where('from_email', 'like', '%kahikateadowns%')
            ->orderBy('received_at')->get()
            ->map(fn (Email $e) => sprintf(
                "%s | %s | %s\n%s",
                $e->received_at->format('Y-m-d'),
                $e->from_name,
                $e->subject,
                trim($e->body)
            ))->implode("\n\n");

        return <<<TEXT
        CORROBORATING EVIDENCE - may confirm or contradict a movement, may never create one

        BANK FEED, livestock-related lines. WARNING: this feed is not scoped to one
        farm and demonstrably contains another client's dairy income. Amount matches
        are corroboration, not proof of ownership.
        $bank

        MONTHLY REPORT, this farm's livestock income (scoped to this farm, GST-exclusive
        and rounded, so use it to spot months with income but no paperwork - not to
        derive head counts)
        $reportLines

        EMAILS FROM THE CLIENT
        $emails
        TEXT;
    }

    /**
     * Keep only proposals that name a real class, a real type, a sane count -
     * and that cite at least one real paper trail record.
     *
     * That last condition is the load-bearing one. It is enforced here rather
     * than left to the prompt, so a movement can never originate in a bank
     * deposit or a report figure no matter what the model returns.
     */
    private function cleanProposals(mixed $proposals, Collection $classes, Collection $records): array
    {
        if (! is_array($proposals)) {
            return [];
        }

        $classIds = $classes->pluck('id')->all();
        $recordIds = $records->pluck('id')->all();

        return collect($proposals)
            ->filter(fn ($p) => is_array($p)
                && in_array((int) ($p['stock_class_id'] ?? 0), $classIds, true)
                && in_array($p['type'] ?? null, self::TYPES, true)
                && (int) ($p['quantity'] ?? 0) >= 1
                && $this->cleanRecordIds($p['source_record_ids'] ?? [], $recordIds) !== [])
            ->map(fn ($p) => [
                'stock_class_id' => (int) $p['stock_class_id'],
                'type' => $p['type'],
                'quantity' => (int) $p['quantity'],
                'note' => mb_substr(trim((string) ($p['note'] ?? '')), 0, 255),
                'source_record_ids' => $this->cleanRecordIds($p['source_record_ids'] ?? [], $recordIds),
                'confidence' => in_array($p['confidence'] ?? null, self::CONFIDENCE, true) ? $p['confidence'] : 'medium',
                'flag' => in_array($p['flag'] ?? null, self::FLAGS, true) ? $p['flag'] : null,
                'corroboration' => in_array($p['corroboration'] ?? null, self::CORROBORATION, true) ? $p['corroboration'] : 'unconfirmed',
                'evidence' => mb_substr(trim((string) ($p['evidence'] ?? '')), 0, 400),
                'reasoning' => mb_substr(trim((string) ($p['reasoning'] ?? '')), 0, 400),
            ])
            ->values()
            ->all();
    }

    private function cleanSkipped(mixed $skipped, Collection $records): array
    {
        if (! is_array($skipped)) {
            return [];
        }

        $recordIds = $records->pluck('id')->all();

        return collect($skipped)
            ->filter(fn ($s) => is_array($s) && $this->cleanRecordIds($s['source_record_ids'] ?? [], $recordIds) !== [])
            ->map(fn ($s) => [
                'source_record_ids' => $this->cleanRecordIds($s['source_record_ids'], $recordIds),
                'flag' => in_array($s['flag'] ?? null, self::FLAGS, true) ? $s['flag'] : 'duplicate',
                'reason' => mb_substr(trim((string) ($s['reason'] ?? '')), 0, 400),
            ])
            ->values()
            ->all();
    }

    /**
     * Findings from the rest of the database that have no paper trail behind
     * them. Informational only - these are never tickable, because there is no
     * document saying how many animals moved.
     */
    private function cleanGaps(mixed $gaps): array
    {
        if (! is_array($gaps)) {
            return [];
        }

        return collect($gaps)
            ->filter(fn ($g) => is_array($g) && trim((string) ($g['title'] ?? '')) !== '')
            ->map(fn ($g) => [
                'title' => mb_substr(trim((string) $g['title']), 0, 160),
                'detail' => mb_substr(trim((string) ($g['detail'] ?? '')), 0, 600),
                'evidence' => mb_substr(trim((string) ($g['evidence'] ?? '')), 0, 400),
            ])
            ->values()
            ->all();
    }

    /**
     * Where two records repeat the same information word for word, cite the
     * latest and skip the earlier ones.
     *
     * Enforced here rather than left to the prompt: the model reasons about
     * this correctly but has been observed citing the earlier record while
     * describing the later one, which leaves the same id both proposed and
     * skipped. Identical body text is the test - a docket and a diary note
     * describing one event read differently and stay the model's judgement.
     *
     * @return array{0: array, 1: array}
     */
    private function preferLatestDuplicates(array $proposals, array $skipped, Collection $records): array
    {
        $supersededBy = [];
        $records->groupBy(fn (StockRecord $r) => mb_strtolower(trim($r->body)))
            ->filter(fn (Collection $group) => $group->count() > 1)
            ->each(function (Collection $group) use (&$supersededBy) {
                $latest = $group->sortBy([['recorded_on', 'asc'], ['id', 'asc']])->last();
                foreach ($group as $record) {
                    if ($record->id !== $latest->id) {
                        $supersededBy[$record->id] = $latest->id;
                    }
                }
            });

        if ($supersededBy === []) {
            return [$proposals, $skipped];
        }

        // Point every citation at the surviving record.
        foreach ($proposals as $i => $proposal) {
            $proposals[$i]['source_record_ids'] = collect($proposal['source_record_ids'])
                ->map(fn (int $id) => $supersededBy[$id] ?? $id)
                ->unique()->values()->all();
        }

        // Cited and skipped must be disjoint: a record is either keyed in or it
        // is not. Remapping a citation can leave the surviving record sitting in
        // a skipped entry the model wrote against the earlier one, so strip it.
        $cited = collect($proposals)->flatMap(fn ($p) => $p['source_record_ids'])->unique()->all();
        $skipped = collect($skipped)
            ->map(function ($entry) use ($cited) {
                $entry['source_record_ids'] = array_values(array_diff($entry['source_record_ids'], $cited));

                return $entry;
            })
            ->filter(fn ($entry) => $entry['source_record_ids'] !== [])
            ->values()
            ->all();

        // Make sure every superseded record is listed exactly once, so the
        // adviser can see it was considered and set aside.
        $alreadySkipped = collect($skipped)->flatMap(fn ($s) => $s['source_record_ids'])->all();
        foreach ($supersededBy as $superseded => $latest) {
            if (! in_array($superseded, $alreadySkipped, true)) {
                $skipped[] = [
                    'source_record_ids' => [$superseded],
                    'flag' => 'duplicate',
                    'reason' => "Repeats record {$latest} word for word; the later filing is the one keyed in.",
                ];
            }
        }

        return [$proposals, $skipped];
    }

    /**
     * The model's read of why a class may not balance. Deliberately carries no
     * number: the size of a residual is arithmetic, and the app works it out
     * from the movements the adviser has actually ticked.
     */
    private function cleanResiduals(mixed $residuals, Collection $classes, Collection $records): array
    {
        if (! is_array($residuals)) {
            return [];
        }

        $classIds = $classes->pluck('id')->all();
        $recordIds = $records->pluck('id')->all();

        return collect($residuals)
            ->filter(fn ($r) => is_array($r) && in_array((int) ($r['stock_class_id'] ?? 0), $classIds, true))
            ->map(fn ($r) => [
                'stock_class_id' => (int) $r['stock_class_id'],
                'likely_cause' => mb_substr(trim((string) ($r['likely_cause'] ?? '')), 0, 400),
                'ask_the_farmer' => mb_substr(trim((string) ($r['ask_the_farmer'] ?? '')), 0, 400),
                'source_record_ids' => $this->cleanRecordIds($r['source_record_ids'] ?? [], $recordIds),
            ])
            ->unique('stock_class_id')
            ->values()
            ->all();
    }

    /** Drop any record id the model made up. */
    private function cleanRecordIds(mixed $ids, array $recordIds): array
    {
        return is_array($ids)
            ? collect($ids)->map(fn ($id) => (int) $id)->filter(fn ($id) => in_array($id, $recordIds, true))->values()->all()
            : [];
    }
}
