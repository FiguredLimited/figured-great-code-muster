<?php

namespace App\Http\Controllers;

use App\Models\BankTransaction;
use App\Models\Email;
use App\Models\Farm;
use App\Models\Invoice;
use App\Models\ReportLine;
use App\Models\StockClass;
use App\Models\StockMovement;
use App\Models\StockRecord;
use App\Services\Claude;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;
use RuntimeException;

class StockController extends Controller
{
    /**
     * What Claude is allowed to say. Anything outside these lists is dropped
     * before the suggestion reaches the browser.
     */
    private const TYPES = ['birth', 'purchase', 'death', 'sale', 'loss'];

    private const FLAGS = ['duplicate', 'correction', 'estimate', 'mislabelled'];

    private const CONFIDENCE = ['high', 'medium', 'low'];

    private const CORROBORATION = ['confirmed', 'unconfirmed', 'contradicted'];

    /** This page is Kahikatea Downs; the corroborating evidence is scoped to it where it can be. */
    private const FARM = 'Kahikatea Downs';

    /** Bank narrations worth showing the model. The feed is not farm-scoped, so this is a net, not a filter. */
    private const BANK_KEYWORDS = ['LIVESTOCK', 'PGG', 'NZ FARMERS', 'CARRFIELDS', 'SALEYARD', 'STOCK AGENT'];

    /** This farm's livestock income lines in the monthly report. */
    private const STOCK_INCOME = ['Lamb Sales', 'Cattle Sales', 'Wool Income'];

    public function index(): JsonResponse
    {
        return response()->json([
            'classes' => StockClass::with('movements')->orderBy('id')->get(),
            'records' => StockRecord::orderBy('recorded_on')->orderBy('id')->get(),
            'cross_records' => $this->crossReferences(),
        ]);
    }

    public function storeMovement(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'stock_class_id' => ['required', 'exists:stock_classes,id'],
            'type' => ['required', 'in:birth,purchase,death,sale,loss'],
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
     * stock class, which of the five movement types, how many, and whether a
     * record duplicates or corrects another. The arithmetic stays in code, on
     * both sides of the wire, so nothing can quietly balance a class by
     * inventing an animal. Nothing is written to the database here either -
     * every proposal goes back to the adviser to accept or reject.
     */
    public function suggestMovements(Request $request): JsonResponse
    {
        $classes = StockClass::with('movements')->orderBy('id')->get();
        $records = StockRecord::orderBy('recorded_on')->orderBy('id')->get();
        $data = $this->dataPrompt($classes, $records);

        // Same books in, same answer out. The fingerprint covers everything the
        // prompt is built from, so keying a movement in invalidates it at once.
        $key = 'stock-suggestions:'.hash('xxh128', $data);
        if (! $request->boolean('refresh') && ($hit = Cache::get($key))) {
            return response()->json($hit + ['cached' => true]);
        }

        // Two passes, sent together: one returns movements to key in, the other
        // the advisory findings. Splitting them halves the longest reply, and
        // they generate concurrently, so the wall clock is the slower of the two
        // rather than the sum.
        try {
            $replies = Claude::askMany([
                'movements' => ['system' => $this->movementsSystemPrompt(), 'prompt' => $data],
                'findings' => ['system' => $this->findingsSystemPrompt(), 'prompt' => $data],
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        $movements = Claude::decodeJson($replies['movements']);
        $findings = Claude::decodeJson($replies['findings']);
        if ($movements === null && $findings === null) {
            return response()->json(['error' => 'Could not read the suggestion as JSON. Try again.'], 502);
        }

        $proposals = $this->cleanProposals($movements['proposals'] ?? [], $classes, $records);
        $skipped = $this->cleanSkipped($movements['skipped'] ?? [], $records);
        [$proposals, $skipped] = $this->preferLatestDuplicates($proposals, $skipped, $records);

        $payload = [
            'proposals' => $proposals,
            'skipped' => $skipped,
            'gaps' => $this->cleanGaps($findings['gaps'] ?? []),
            'residuals' => $this->cleanResiduals($findings['residuals'] ?? [], $classes, $records),
        ];

        Cache::put($key, $payload, now()->addHours(6));

        return response()->json($payload + ['cached' => false]);
    }

    private function domainRules(): string
    {
        return <<<'PROMPT'
        You are helping a rural accounting adviser in New Zealand reconcile a sheep
        and beef farm's livestock for the stock year 1 July 2025 - 30 June 2026.

        For each stock class the reconciliation is:
            opening + births + purchases - deaths - sales - losses = closing

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

        DEATH OR LOSS - THE FIFTH TYPE
        Not every animal that leaves the farm is sold or found dead, and the two are
        not the same thing on paper.

        - "death" is a COUNTED animal. A carcass found, a ewe put down, a homekill.
          The farmer states the number because they saw the animals.
        - "loss" is stock that CANNOT BE ACCOUNTED FOR. Missing after a storm or a
          flood, strayed, drowned in a creek, or a tally the farmer has estimated
          rather than walked out and counted. The tell is hedging language: "about",
          "probably more", "will know for sure at crutching", "somewhere up the back".

        Use "loss" whenever the record itself says the count is not final. It subtracts
        exactly as a death does, so the arithmetic is unchanged - but the two sit on
        separate lines, and that separation is the point. Deaths are the figure that
        goes to an insurer or into a tax return; losses are unconfirmed and cannot be
        claimed. Filing an estimate as a death makes the page look tidier and quietly
        overstates a number the client may be about to swear to. Never do it.

        Cross-check a loss like anything else:
        - No money moves when stock is lost, so nothing in the bank feed will confirm
          one. "unconfirmed" is the correct verdict and is not a weakness in the
          finding - say so in "evidence".
        - Check anyway. A deposit that lines up with the date and roughly the head
          count means those animals were SOLD, not lost. That is "contradicted" and
          the adviser needs to see it.
        - A loss is still a movement and still obeys THE RULE THAT MATTERS MOST: it
          needs a paper trail record behind it, its quantity is the figure the record
          actually states, and it is flagged "estimate". You may NEVER propose a loss
          the records do not describe, and never pick its quantity to make a class
          balance. Where a record hints at more than it counts, the remainder belongs
          in "residuals", not in a proposal.

        Rules:
        - Every proposal is one of five types: birth, purchase, death, sale, loss.
          Quantity is always a positive whole number - the type carries the direction.
          Births and purchases add; deaths, sales and losses subtract.
        - Work out the stock class from the words used. Ewes, two-tooths and cull ewes
          are Ewes. Docked or sold lambs are Lambs. Cows, calves, heifers, steers,
          R2 steers and cull cows are Cattle.
        - Lambs born this year stay in the Lambs class for this reconciliation.
        - Animals lost to weather, illness, calving or lambing trouble are deaths,
          provided the farmer counted them. So is homekill - an animal killed for the
          freezer has left the flock. See DEATH OR LOSS above for the ones they did
          not count.
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

        PROMPT;
    }

    /** Task and schema for the call that produces movements to key in. */
    private function movementsSystemPrompt(): string
    {
        return $this->domainRules()."\n\n".<<<'PROMPT'
        YOUR TASK
        Return only the movements to key in, and the records you set aside. Do not
        report gaps or residuals in this reply - a second pass handles those.

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
              "evidence": "Bank 12 Dec 2025 PGG LIVESTOCK PROCEEDS $26,880.00 matches the docket exactly.",
              "reasoning": "How you read the record."
            }
          ],
          "skipped": [
            {
              "source_record_ids": [12],
              "flag": "duplicate",
              "reason": "Same docket S-40417 as record 11, and only one deposit of $25,560.00 in the bank."
            }
          ]
        }

        "note" is what gets saved against the movement: under 100 characters, pointing
        at the source document. "flag" is null unless one of duplicate, correction,
        estimate or mislabelled applies. "confidence" is high, medium or low.
        "corroboration" is "confirmed" if other records back this movement up,
        "contradicted" if they disagree with it, "unconfirmed" if nothing outside the
        paper trail speaks to it - which is normal and fine for births, deaths and
        losses, since no money moves.

        Keep "reasoning" and "evidence" to ONE short sentence each. They are read at a
        glance in a review queue, not filed. Never pad them.
        PROMPT;
    }

    /** Task and schema for the call that produces advisory findings. */
    private function findingsSystemPrompt(): string
    {
        return $this->domainRules()."\n\n".<<<'PROMPT'
        YOUR TASK
        Do not return movements - a separate pass keys those in. Report only what the
        adviser should chase up: things the rest of the books show that the paper trail
        does not support, and why a class may not balance.

        Reply with JSON and nothing else - no prose, no markdown fences:

        {
          "gaps": [
            {
              "title": "Short label for what is missing",
              "detail": "What the books show, why there is no movement, and what to ask.",
              "evidence": "The specific bank line or report figure, with date and amount."
            }
          ],
          "residuals": [
            {
              "stock_class_id": 1,
              "likely_cause": "One sentence naming the most likely reason this class will not balance.",
              "source_record_ids": [21],
              "ask_the_farmer": "The question that would settle it."
            }
          ]
        }

        BE BRIEF. This is a worklist, not a report.
        - At most 6 gaps, the most material first, one per underlying issue. Do not
          raise a separate gap for every month of a recurring pattern - group them.
        - "detail" is at most two short sentences. "evidence" is a single line of dates
          and amounts, not an argument.
        - Small timing or rounding differences between a report figure and a docket are
          not gaps. Raise a gap only where stock may actually have moved unrecorded.

        "residuals" is your read of which classes will still not balance, and why. Do
        NOT put a number in it - the app works out the size of any difference itself.
        Give a residual only where the records genuinely point at a cause; if a class
        should balance cleanly, leave it out.
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
     * Corroborating records from the rest of the practice's database: the bank
     * feed, this farm's monthly income lines, the client's emails and their
     * supplier invoices.
     *
     * The page and the prompt both read this one method, so what the adviser
     * sees in the second paper trail is exactly what Claude was given - the
     * "Checked:" line on a suggestion can always be traced to a row on screen.
     *
     * "scoped" says whether a record can be proven to belong to this farm.
     * Bank rows cannot: bank_transactions has no farm_id and the fixture feed
     * demonstrably carries another client's dairy income.
     */
    private function crossReferences(): Collection
    {
        $farm = Farm::where('name', self::FARM)->first();

        $bank = BankTransaction::where(function ($query) {
            foreach (self::BANK_KEYWORDS as $keyword) {
                $query->orWhere('description', 'like', "%{$keyword}%");
            }
        })->orderBy('transacted_on')->get()
            ->map(fn (BankTransaction $t) => [
                'kind' => 'Bank',
                'recorded_on' => $t->transacted_on->format('Y-m-d'),
                'title' => $t->description,
                'body' => $t->amount < 0 ? 'Payment out of the account.' : 'Deposit into the account.',
                'amount' => (float) $t->amount,
                'scoped' => false,
            ]);

        $report = $farm
            ? ReportLine::where('farm_id', $farm->id)
                ->whereIn('category', self::STOCK_INCOME)
                ->where('actual', '!=', 0)
                ->orderBy('month')->get()
                ->map(fn (ReportLine $l) => [
                    'kind' => 'Report',
                    'recorded_on' => $l->month->format('Y-m-d'),
                    'title' => $l->category.' - '.$l->month->format('M Y'),
                    'body' => 'Budget $'.number_format((float) $l->budget, 2)
                        .', actual $'.number_format((float) $l->actual, 2).'. GST-exclusive and rounded.',
                    'amount' => (float) $l->actual,
                    'scoped' => true,
                ])
            : collect();

        $emails = Email::where('from_email', 'like', '%kahikateadowns%')
            ->orderBy('received_at')->get()
            ->map(fn (Email $e) => [
                'kind' => 'Email',
                'recorded_on' => $e->received_at->format('Y-m-d'),
                'title' => $e->from_name.' - '.$e->subject,
                'body' => trim($e->body),
                'amount' => null,
                'scoped' => true,
            ]);

        // Invoices are keyed in by hand, so invoice_date is usually still null;
        // fall back to the date printed on the scan.
        $invoices = Invoice::where('raw_text', 'like', '%KAHIKATEA%')->get()
            ->map(fn (Invoice $i) => [
                'kind' => 'Invoice',
                'recorded_on' => $i->invoice_date?->format('Y-m-d') ?? $this->dateFromScan($i->raw_text),
                'title' => $i->supplier ?? trim(strtok($i->raw_text, "\n")),
                'body' => $this->invoiceSummary($i->raw_text),
                'amount' => $i->total !== null ? -1 * (float) $i->total : null,
                'scoped' => true,
            ]);

        return $bank->concat($report)->concat($emails)->concat($invoices)
            ->sortBy([['recorded_on', 'asc'], ['kind', 'asc']])
            ->values();
    }

    /** Pull "Date: 28/03/2026" off an invoice scan nobody has keyed in yet. */
    private function dateFromScan(string $rawText): ?string
    {
        if (preg_match('/Date:\s*(\d{2})\/(\d{2})\/(\d{4})/', $rawText, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }

        return null;
    }

    /** The line items off an invoice scan, without the letterhead and totals. */
    private function invoiceSummary(string $rawText): string
    {
        $summary = collect(preg_split('/\R/', $rawText))
            ->map(fn (string $line) => trim($line))
            ->filter(fn (string $line) => $line !== ''
                && ! str_starts_with($line, '---')
                && ! preg_match('/^(Tax Invoice|GST No\.|Invoice:|Date:|Client:|Account:|Subtotal|GST 15%|TOTAL DUE|Please quote|Direct credit)/i', $line))
            ->slice(1)
            ->implode('; ');

        return mb_substr($summary, 0, 400);
    }

    /** The same records again, flattened into the prompt. */
    private function evidencePrompt(): string
    {
        $rows = $this->crossReferences()
            ->map(fn (array $r) => sprintf(
                '%s | %-7s | %s%s | %s%s',
                $r['recorded_on'] ?? 'undated',
                $r['kind'],
                $r['title'],
                $r['amount'] !== null
                    ? ' | '.($r['amount'] < 0 ? '-' : '+').number_format(abs($r['amount']), 2)
                    : '',
                $r['body'],
                $r['scoped'] ? '' : ' [NOT FARM-SCOPED]'
            ))
            ->implode("\n");

        return <<<TEXT
        CORROBORATING EVIDENCE - may confirm or contradict a movement, may never create one

        Rows marked [NOT FARM-SCOPED] come from the practice's shared bank feed, which
        has no farm column and demonstrably carries another client's dairy income. An
        exact amount match there is strong corroboration, never proof of ownership.
        Report, Email and Invoice rows are this farm's.

        $rows
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
