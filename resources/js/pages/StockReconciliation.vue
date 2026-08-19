<script setup>
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import { money, shortDate } from '../format';

const classes = ref([]);
const records = ref([]);
const farms = ref([]);
const selectedFarmId = ref(null);
const farmMenuOpen = ref(false);
const loading = ref(true);
const saving = ref(false);

const movementForm = ref({
    stock_class_id: null,
    type: 'sale',
    quantity: null,
    note: '',
});

// The AI review queue: proposals Claude read out of the paper trail, plus the
// records it deliberately ignored. Nothing here is saved until the adviser
// accepts it.
const suggesting = ref(false);
const accepting = ref(false);
const suggestError = ref('');
const reviewed = ref(false);
const proposals = ref([]);
const skipped = ref([]);
const gaps = ref([]);
const residuals = ref([]);
// Per stock class: has the adviser opted in to a balancing entry?
const balancing = ref({});
// What the last "key in" actually did. Captured at the moment of writing,
// because the review queue is emptied immediately afterwards.
const justDone = ref(null);

onMounted(load);

async function load() {
    const [{ data: stockData }, { data: farmData }] = await Promise.all([
        axios.get('/api/stock'),
        axios.get('/api/farms'),
    ]);
    classes.value = stockData.classes;
    records.value = stockData.records;
    farms.value = farmData;
    selectedFarmId.value = farmData.find((farm) => farm.name === 'Kahikatea Downs')?.id ?? farmData[0]?.id ?? null;
    if (!movementForm.value.stock_class_id) {
        movementForm.value.stock_class_id = stockData.classes[0]?.id ?? null;
    }
    loading.value = false;
}

// Tally per class: opening + births + purchases - deaths - sales - losses.
//
// Losses subtract exactly as deaths do, but they are counted on their own line.
// A death is an animal the farmer found and counted; a loss is stock that cannot
// be accounted for. Kate is claiming on the June storm through FMG, and an
// insurer is entitled to the confirmed figure, not the confirmed figure with the
// estimates folded into it.
function tally(stockClass) {
    const sum = (type) =>
        stockClass.movements.filter((m) => m.type === type).reduce((total, m) => total + m.quantity, 0);
    const calculated =
        stockClass.opening_count + sum('birth') + sum('purchase') - sum('death') - sum('sale') - sum('loss');
    return {
        births: sum('birth'),
        purchases: sum('purchase'),
        deaths: sum('death'),
        sales: sum('sale'),
        losses: sum('loss'),
        calculated,
        difference: calculated - stockClass.closing_count,
    };
}

// The same arithmetic, with the ticked proposals folded in - so the adviser can
// see where a class lands before anything is written.
function projected(stockClass) {
    const picked = proposals.value.filter((p) => p.selected && p.stock_class_id === stockClass.id);
    const sum = (type) => picked.filter((p) => p.type === type).reduce((total, p) => total + p.quantity, 0);
    const calculated =
        tally(stockClass).calculated + sum('birth') + sum('purchase') - sum('death') - sum('sale') - sum('loss');
    return {
        count: picked.length,
        calculated,
        difference: calculated - stockClass.closing_count,
    };
}

/*
 * The balancing entry. Its size is arithmetic, not judgement: whatever is left
 * over once the ticked proposals are in. The model never supplies this number -
 * it only tells us, via residuals, what most likely caused the gap.
 *
 * A surplus on paper means animals left without being recorded. That clears as a
 * loss, never as a death: nobody found these animals, and posting them as deaths
 * would put an unwitnessed number into the figure Kate claims on. A shortfall
 * clears as a birth.
 */
function balancingFor(stockClass) {
    const difference = projected(stockClass).difference;
    return {
        quantity: Math.abs(difference),
        type: difference > 0 ? 'loss' : 'birth',
    };
}

function residualFor(stockClass) {
    return residuals.value.find((r) => r.stock_class_id === stockClass.id);
}

// Where the class lands once any opted-in balancing entry is applied.
function settled(stockClass) {
    const base = projected(stockClass);
    if (balancing.value[stockClass.id] && base.difference !== 0) {
        return { ...base, difference: 0, balanced: true };
    }
    return { ...base, balanced: false };
}

/*
 * The discrepancy report. Three kinds of statement, kept visibly apart because
 * they are worth very different amounts:
 *
 *   1. The arithmetic. Certain, and computed here.
 *   2. The model's read of the paper trail, which cites the records it read.
 *   3. A standing checklist of what usually causes a gap in this direction.
 *
 * Only the first two are evidence. The checklist is a set of questions for the
 * adviser to put to the farmer - it is deliberately static, so nothing in it
 * can be mistaken for something the books actually say.
 */
const SURPLUS_CAUSES = [
    'Deaths nobody wrote down — storm or drought losses the farmer never walked out and counted.',
    'A private or cash sale with no docket. Ask whether anything went to a neighbour or direct to the works.',
    'Homekill or rations taken for the house and never recorded.',
    'Stock that moved to another class or another block and was tallied there instead.',
    'A closing muster that missed animals — scrub, back gullies, or a mob still out on lease.',
];

const SHORTFALL_CAUSES = [
    'Births nobody wrote down — a late lambing or calving tail the diary missed.',
    'A purchase whose invoice or docket has not been keyed in yet.',
    'Stock counted twice at the closing muster.',
    'A sale entered that never happened, or entered against the wrong class.',
];

const unreconciled = computed(() => classes.value.filter((c) => tally(c).difference !== 0));

function discrepancyFor(stockClass) {
    const { difference } = tally(stockClass);
    const size = Math.abs(difference);
    const surplus = difference > 0;

    return {
        difference,
        size,
        surplus,
        meaning: surplus
            ? `The movements account for ${size} head more than the farmer's closing tally. Either that many left the farm without a record, or the closing muster did not find them.`
            : `The farmer's closing tally is ${size} head higher than the movements account for. Either that many arrived without a record, or something has been counted twice.`,
        causes: surplus ? SURPLUS_CAUSES : SHORTFALL_CAUSES,
        residual: residualFor(stockClass),
        // Adjustments already posted against this class, so they are never
        // mistaken for stock somebody counted.
        adjustments: stockClass.movements.filter((m) => (m.note ?? '').startsWith('Balancing entry')),
    };
}

/*
 * Two checks the page can make on its own, with no AI call: they are pure
 * arithmetic over the paper trail and the bank feed.
 *
 * Sale dockets print their total ("= $26,880.00"). Purchases are excluded by
 * reading the body rather than the "source" label, because at least one
 * purchase in this trail is filed as a sale docket.
 */
const docketSales = computed(() =>
    records.value
        .filter((r) => !/purchase/i.test(r.body))
        .map((r) => ({ record: r, match: r.body.match(/=\s*\$([\d,]+\.\d{2})/) }))
        .filter((x) => x.match)
        .map((x) => ({ record: x.record, total: Number(x.match[1].replace(/,/g, '')) })),
);

const bankDeposits = computed(() => crossRecords.value.filter((r) => r.kind === 'Bank' && r.amount > 0));

const depositsFor = (total) => bankDeposits.value.filter((d) => Math.abs(d.amount - total) < 0.005);

// Money arriving that no docket explains. Never proof on its own — the feed is
// shared across the practice's clients — but always worth a question.
const depositsWithoutDocket = computed(() =>
    bankDeposits.value.filter((d) => !docketSales.value.some((s) => Math.abs(s.total - d.amount) < 0.005)),
);

// The reverse: the same total on two dockets but banked only once. That is the
// signature of one sale filed twice, and it inflates sales if both are keyed in.
const docketsBankedOnce = computed(() => {
    const byTotal = {};
    docketSales.value.forEach((s) => ((byTotal[s.total] ??= []).push(s.record)));

    return Object.entries(byTotal)
        .map(([total, rows]) => ({ total: Number(total), rows, inBank: depositsFor(Number(total)).length }))
        .filter((group) => group.rows.length > group.inBank);
});

const hasCrossChecks = computed(() => depositsWithoutDocket.value.length > 0 || docketsBankedOnce.value.length > 0);

const canSave = computed(
    () => movementForm.value.stock_class_id && movementForm.value.quantity > 0 && movementForm.value.type,
);

const selectedFarm = computed(() => farms.value.find((farm) => farm.id === selectedFarmId.value));

function selectFarm(farmId) {
    selectedFarmId.value = farmId;
    farmMenuOpen.value = false;
}

function closeFarmMenu(event) {
    if (!event.currentTarget.contains(event.relatedTarget)) {
        farmMenuOpen.value = false;
    }
}

async function addMovement() {
    saving.value = true;
    const { data } = await axios.post('/api/stock-movements', movementForm.value);
    classes.value.find((c) => c.id === data.stock_class_id).movements.push(data);
    movementForm.value.quantity = null;
    movementForm.value.note = '';
    saving.value = false;
}

async function removeMovement(stockClass, movement) {
    await axios.delete(`/api/stock-movements/${movement.id}`);
    stockClass.movements = stockClass.movements.filter((m) => m.id !== movement.id);
}

async function suggest() {
    suggesting.value = true;
    suggestError.value = '';
    try {
        const { data } = await axios.post('/api/stock/suggest-movements');
        // Everything starts ticked - flagged rows are for reading, not hunting.
        proposals.value = data.proposals.map((p, i) => ({ ...p, key: i, selected: true }));
        skipped.value = data.skipped;
        gaps.value = data.gaps ?? [];
        residuals.value = data.residuals ?? [];
        balancing.value = {};
        reviewed.value = true;
    } catch (e) {
        suggestError.value = e.response?.data?.error ?? e.message;
    } finally {
        suggesting.value = false;
    }
}

async function acceptSelected() {
    accepting.value = true;

    // Work these out before anything is written, so they reflect the position
    // the adviser was actually looking at when they ticked the box.
    const balancers = classes.value
        .filter((c) => balancing.value[c.id])
        .map((c) => ({ stockClass: c, ...balancingFor(c) }))
        .filter((b) => b.quantity > 0);

    const keyed = proposals.value.filter((p) => p.selected);

    for (const proposal of keyed) {
        const { data } = await axios.post('/api/stock-movements', {
            stock_class_id: proposal.stock_class_id,
            type: proposal.type,
            quantity: proposal.quantity,
            note: proposal.note,
        });
        classes.value.find((c) => c.id === data.stock_class_id).movements.push(data);
    }
    for (const balancer of balancers) {
        const { data } = await axios.post('/api/stock-movements', {
            stock_class_id: balancer.stockClass.id,
            type: balancer.type,
            quantity: balancer.quantity,
            note: `Balancing entry — ${balancer.quantity} unaccounted, not a counted death. Confirm with Kate`,
        });
        classes.value.find((c) => c.id === data.stock_class_id).movements.push(data);
    }

    // Counted after the writes, so "reconciles" reflects what is actually saved.
    justDone.value = {
        movements: keyed.length,
        head: keyed.reduce((total, p) => total + p.quantity, 0),
        cited: new Set(keyed.flatMap((p) => p.source_record_ids)).size,
        trail: records.value.length,
        confirmed: keyed.filter((p) => p.corroboration === 'confirmed').length,
        flagged: keyed.filter((p) => p.flag).length,
        setAside: skipped.value.length,
        gaps: gaps.value.length,
        balancers: balancers.length,
        balancerHead: balancers.reduce((total, b) => total + b.quantity, 0),
        reconciled: classes.value.filter((c) => tally(c).difference === 0).length,
        totalClasses: classes.value.length,
    };

    proposals.value = proposals.value.filter((p) => !p.selected);
    balancing.value = {};
    accepting.value = false;
}

function dismissReview() {
    proposals.value = [];
    skipped.value = [];
    gaps.value = [];
    residuals.value = [];
    balancing.value = {};
    reviewed.value = false;
}

const needsBalancing = computed(() => classes.value.some((c) => balancingFor(c).quantity > 0));

const selectedCount = computed(
    () => proposals.value.filter((p) => p.selected).length + classes.value.filter((c) => balancing.value[c.id] && balancingFor(c).quantity > 0).length,
);
const flaggedCount = computed(() => proposals.value.filter((p) => p.flag).length);

function setAll(selected) {
    proposals.value.forEach((p) => (p.selected = selected));
}

const recordsById = computed(() => Object.fromEntries(records.value.map((r) => [r.id, r])));
const className = (id) => classes.value.find((c) => c.id === id)?.name ?? '';

// Mark the paper trail with what the review queue did to each record.
const citedRecordIds = computed(() => new Set(proposals.value.flatMap((p) => p.source_record_ids)));
const skippedRecordIds = computed(() => new Set(skipped.value.flatMap((s) => s.source_record_ids)));

const sourceBadgeClass = {
    'Diary': 'bg-fg-warning-15 text-fg-warning-text',
    'Sale docket': 'bg-fg-light-blue-15 text-fg-light-blue',
    'Text message': 'bg-fg-brown-15 text-fg-brown',
};

const crossBadgeClass = {
    Bank: 'bg-fg-main-blue-15 text-fg-main-blue',
    Report: 'bg-fg-positive-15 text-fg-positive-dark',
    Email: 'bg-fg-brown-15 text-fg-brown',
    Invoice: 'bg-fg-light-blue-15 text-fg-light-blue',
};

const crossKinds = computed(() => ['All', ...new Set(crossRecords.value.map((r) => r.kind))]);
const visibleCrossRecords = computed(() =>
    crossFilter.value === 'All' ? crossRecords.value : crossRecords.value.filter((r) => r.kind === crossFilter.value),
);

const flagLabel = {
    duplicate: 'duplicate',
    correction: 'corrected figure',
    estimate: 'estimate',
    mislabelled: 'mislabelled source',
};

// How the rest of the database felt about each proposal.
const corroborationBadge = {
    confirmed: { label: 'confirmed by the books', class: 'bg-fg-positive-15 text-fg-positive-dark' },
    contradicted: { label: 'contradicted by the books', class: 'bg-fg-danger-15 text-fg-danger-dark' },
    unconfirmed: { label: 'no corroborating record', class: 'bg-fg-pale-grey text-fg-mid-grey' },
};
</script>

<template>
    <div>
        <!-- What the last "key in" did. Counts only - the honest measure of how
             much of this the adviser did not have to do by hand. -->
        <div v-if="justDone" class="mb-4 rounded border border-fg-positive bg-fg-positive-15 p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="font-semibold text-fg-positive-dark">
                        Done — {{ justDone.movements }} movement(s) keyed in,
                        {{ justDone.head.toLocaleString() }} head accounted for
                    </h3>
                    <p class="mt-0.5 text-sm text-fg-mid-grey">
                        Read out of {{ justDone.trail }} paper trail records and checked against the bank feed, the
                        monthly report and Kate's emails.
                        <span class="font-medium"
                            >{{ justDone.reconciled }} of {{ justDone.totalClasses }} classes now reconcile.</span
                        >
                    </p>
                </div>
                <button class="shrink-0 text-xs text-fg-light-grey hover:text-fg-dark-grey" @click="justDone = null">
                    Dismiss
                </button>
            </div>

            <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
                <div class="rounded bg-white px-3 py-2">
                    <div class="font-mono text-lg font-semibold">{{ justDone.movements }}</div>
                    <div class="text-xs text-fg-mid-grey">movements keyed in</div>
                </div>
                <div class="rounded bg-white px-3 py-2">
                    <div class="font-mono text-lg font-semibold">{{ justDone.cited }} / {{ justDone.trail }}</div>
                    <div class="text-xs text-fg-mid-grey">records turned into movements</div>
                </div>
                <div class="rounded bg-white px-3 py-2">
                    <div class="font-mono text-lg font-semibold">{{ justDone.confirmed }}</div>
                    <div class="text-xs text-fg-mid-grey">confirmed against the books</div>
                </div>
                <div class="rounded bg-white px-3 py-2">
                    <div class="font-mono text-lg font-semibold">{{ justDone.setAside }}</div>
                    <div class="text-xs text-fg-mid-grey">records set aside as duplicates</div>
                </div>
                <div class="rounded bg-white px-3 py-2">
                    <div class="font-mono text-lg font-semibold">{{ justDone.gaps }}</div>
                    <div class="text-xs text-fg-mid-grey">gaps raised to ask Kate about</div>
                </div>
            </div>

            <p v-if="justDone.flagged" class="mt-2 text-xs text-fg-mid-grey">
                {{ justDone.flagged }} of those needed a judgement call — duplicates, corrections, estimates or a
                mislabelled docket. They are listed against each movement below.
            </p>

            <!-- Called out separately: a balancing entry is not automation, it is
                 an adjustment the adviser opted into. -->
            <p v-if="justDone.balancers" class="mt-2 rounded bg-fg-warning-15 px-3 py-2 text-xs text-fg-warning-text">
                Includes {{ justDone.balancers }} balancing entry ({{ justDone.balancerHead }} head) posted as an
                unaccounted <span class="font-mono">loss</span>. That is an adjustment, not stock anyone counted — it
                stays on its own line until Kate confirms the number.
            </p>
        </div>

        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-lg font-semibold">Stock reconciliation —</h2>
                    <div
                        class="relative"
                        @focusout="closeFarmMenu"
                        @keydown.escape="farmMenuOpen = false"
                    >
                        <button
                            type="button"
                            aria-label="Farm"
                            aria-haspopup="listbox"
                            :aria-expanded="farmMenuOpen"
                            class="flex min-w-56 items-center justify-between gap-3 rounded border border-fg-muted-grey bg-white px-3 py-1.5 text-left text-sm hover:border-fg-main-blue disabled:opacity-50"
                            :disabled="loading || farms.length === 0"
                            @click="farmMenuOpen = !farmMenuOpen"
                        >
                            <span>{{ selectedFarm?.name ?? 'Select a farm' }}</span>
                            <span aria-hidden="true" class="text-xs text-fg-mid-grey">▾</span>
                        </button>
                        <div
                            v-if="farmMenuOpen"
                            role="listbox"
                            aria-label="Farm options"
                            class="absolute left-0 top-full z-30 mt-1 min-w-full overflow-hidden rounded border border-fg-muted-grey bg-white py-1 shadow-lg"
                        >
                            <button
                                v-for="farm in farms"
                                :key="farm.id"
                                type="button"
                                role="option"
                                :aria-selected="farm.id === selectedFarmId"
                                class="block w-full whitespace-nowrap px-3 py-2 text-left text-sm hover:bg-fg-pale-grey"
                                :class="farm.id === selectedFarmId ? 'bg-fg-main-blue-9 font-medium text-fg-main-blue' : ''"
                                @click="selectFarm(farm.id)"
                            >
                                {{ farm.name }}
                            </button>
                        </div>
                    </div>
                </div>
                <p class="text-sm text-fg-mid-grey">
                    Key stock movements in from the raw records (right) until each tally matches the farmer's recorded
                    closing count. Stock year 1 Jul 2025 – 30 Jun 2026. The lamb docking tally is entered as an example.
                </p>
            </div>
            <button
                class="shrink-0 rounded bg-fg-main-blue px-4 py-1.5 text-sm font-medium text-white hover:bg-fg-main-blue-hover disabled:opacity-50"
                :disabled="suggesting || loading"
                @click="suggest"
            >
                {{ suggesting ? 'Reading the paper trail…' : 'Read the paper trail' }}
            </button>
        </div>

        <p v-if="suggestError" class="mb-4 rounded bg-fg-danger-9 p-3 text-sm text-fg-danger-dark">
            {{ suggestError }}
        </p>

        <!-- The review queue. Nothing here has been saved yet. -->
        <div v-if="reviewed" class="mb-4 rounded border border-fg-main-blue-30 bg-fg-main-blue-9 p-4">
            <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                <h3 class="font-semibold">
                    Suggested from the paper trail
                    <span class="text-sm font-normal text-fg-mid-grey">
                        — {{ proposals.length }} movement(s)<span v-if="flaggedCount">, {{ flaggedCount }} needing a look</span>
                        <span v-if="skipped.length">, {{ skipped.length }} record(s) ignored</span>
                        <span v-if="gaps.length">, {{ gaps.length }} gap(s) found elsewhere in the books</span>
                    </span>
                </h3>
                <div class="flex items-center gap-3 text-xs">
                    <button class="text-fg-main-blue hover:underline" @click="setAll(true)">Select all</button>
                    <button class="text-fg-main-blue hover:underline" @click="setAll(false)">Select none</button>
                    <button class="text-fg-light-grey hover:text-fg-dark-grey" @click="dismissReview">Dismiss</button>
                </div>
            </div>

            <!-- Where each class lands if the ticked rows go in. Shown whenever a
                 class is out of balance too, so the balancing entry stays reachable
                 once every movement has already been keyed in. -->
            <div v-if="proposals.length || needsBalancing" class="mb-3 grid grid-cols-1 gap-2 sm:grid-cols-3">
                <div
                    v-for="stockClass in classes"
                    :key="stockClass.id"
                    class="rounded border bg-white px-3 py-2 text-sm"
                    :class="settled(stockClass).difference === 0 ? 'border-fg-positive' : 'border-fg-warning'"
                >
                    <div class="font-medium">{{ stockClass.name }}</div>
                    <div class="text-xs text-fg-mid-grey">
                        {{ projected(stockClass).count }} suggestion(s) → closing
                        <span class="font-mono">{{ projected(stockClass).calculated.toLocaleString() }}</span>
                        vs <span class="font-mono">{{ stockClass.closing_count.toLocaleString() }}</span>
                    </div>
                    <div
                        class="mt-0.5 text-xs font-medium"
                        :class="settled(stockClass).difference === 0 ? 'text-fg-positive-dark' : 'text-fg-warning-text'"
                    >
                        {{
                            settled(stockClass).balanced
                                ? 'would reconcile, with a balancing entry'
                                : settled(stockClass).difference === 0
                                  ? 'would reconcile'
                                  : `would still be out by ${settled(stockClass).difference > 0 ? '+' : ''}${settled(stockClass).difference}`
                        }}
                    </div>

                    <!-- Offered only where the paperwork genuinely leaves a gap.
                         Off by default: this is an adjustment, not a counted animal. -->
                    <div v-if="balancingFor(stockClass).quantity > 0" class="mt-1.5 border-t border-fg-pale-grey pt-1.5">
                        <label class="flex items-start gap-1.5 text-xs">
                            <input v-model="balancing[stockClass.id]" type="checkbox" class="mt-0.5 shrink-0" />
                            <span>
                                Post a balancing entry:
                                <span class="font-mono">{{ balancingFor(stockClass).type }} × {{ balancingFor(stockClass).quantity }}</span>
                            </span>
                        </label>
                        <p v-if="residualFor(stockClass)?.likely_cause" class="mt-1 text-xs text-fg-mid-grey">
                            {{ residualFor(stockClass).likely_cause }}
                        </p>
                        <p v-if="residualFor(stockClass)?.ask_the_farmer" class="mt-0.5 text-xs italic text-fg-light-grey">
                            Ask Kate: {{ residualFor(stockClass).ask_the_farmer }}
                        </p>
                    </div>
                </div>
            </div>

            <p
                v-if="!proposals.length"
                class="mb-3 rounded border border-fg-muted-grey bg-white p-3 text-sm text-fg-mid-grey"
            >
                Nothing new to key in — every movement in the paper trail is already entered.
                <span v-if="needsBalancing">Any difference left is shown above.</span>
            </p>

            <!-- One row per proposed movement. -->
            <ul class="space-y-1.5">
                <li
                    v-for="proposal in proposals"
                    :key="proposal.key"
                    class="rounded border border-fg-muted-grey bg-white p-2.5 text-sm"
                >
                    <label class="flex items-start gap-2.5">
                        <input v-model="proposal.selected" type="checkbox" class="mt-1 shrink-0" />
                        <span class="grow">
                            <span class="flex flex-wrap items-center gap-2">
                                <span class="font-medium">{{ className(proposal.stock_class_id) }}</span>
                                <span class="rounded bg-fg-pale-grey px-1.5 py-0.5 font-mono text-xs">
                                    {{ proposal.type }} × {{ proposal.quantity.toLocaleString() }}
                                </span>
                                <span v-if="proposal.note" class="text-xs text-fg-mid-grey">{{ proposal.note }}</span>
                                <span
                                    v-if="proposal.flag"
                                    class="rounded-full bg-fg-warning-15 px-2 py-0.5 text-xs font-medium text-fg-warning-text"
                                >
                                    {{ flagLabel[proposal.flag] }}
                                </span>
                                <span
                                    v-if="proposal.confidence !== 'high'"
                                    class="rounded-full bg-fg-pale-grey px-2 py-0.5 text-xs text-fg-mid-grey"
                                >
                                    {{ proposal.confidence }} confidence
                                </span>
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="corroborationBadge[proposal.corroboration].class"
                                >
                                    {{ corroborationBadge[proposal.corroboration].label }}
                                </span>
                            </span>
                            <span v-if="proposal.evidence" class="mt-0.5 block text-xs text-fg-mid-grey">
                                <span class="font-medium">Checked:</span> {{ proposal.evidence }}
                            </span>
                            <span v-if="proposal.reasoning" class="mt-0.5 block text-xs text-fg-mid-grey">
                                {{ proposal.reasoning }}
                            </span>
                            <span
                                v-for="id in proposal.source_record_ids"
                                :key="id"
                                class="mt-1 block border-l-2 border-fg-muted-grey pl-2 text-xs text-fg-light-grey"
                            >
                                {{ shortDate(recordsById[id]?.recorded_on) }} · {{ recordsById[id]?.source }} —
                                {{ recordsById[id]?.body }}
                            </span>
                        </span>
                    </label>
                </li>
            </ul>

            <!-- What it chose not to key in, and why. -->
            <details v-if="skipped.length" class="mt-3">
                <summary class="cursor-pointer text-xs font-medium text-fg-mid-grey">
                    {{ skipped.length }} record(s) deliberately ignored
                </summary>
                <ul class="mt-1.5 space-y-1">
                    <li
                        v-for="(item, i) in skipped"
                        :key="i"
                        class="rounded border border-fg-muted-grey bg-white p-2 text-xs"
                    >
                        <span class="rounded-full bg-fg-warning-15 px-2 py-0.5 font-medium text-fg-warning-text">
                            {{ flagLabel[item.flag] }}
                        </span>
                        <span class="ml-1.5">{{ item.reason }}</span>
                        <span
                            v-for="id in item.source_record_ids"
                            :key="id"
                            class="mt-1 block border-l-2 border-fg-muted-grey pl-2 text-fg-light-grey"
                        >
                            {{ shortDate(recordsById[id]?.recorded_on) }} · {{ recordsById[id]?.source }} —
                            {{ recordsById[id]?.body }}
                        </span>
                    </li>
                </ul>
            </details>

            <!-- Found in the bank feed / report / emails, with no paper trail behind it.
                 Deliberately not tickable: no document says how many animals moved. -->
            <div v-if="gaps.length" class="mt-3 rounded border border-fg-warning bg-fg-warning-15 p-3">
                <h4 class="text-sm font-semibold text-fg-warning-text">
                    {{ gaps.length }} thing(s) the rest of the books show, with no paperwork behind them
                </h4>
                <ul class="mt-1.5 space-y-1.5">
                    <li v-for="(gap, i) in gaps" :key="i" class="rounded bg-white p-2 text-xs">
                        <p class="font-medium">{{ gap.title }}</p>
                        <p class="mt-0.5 text-fg-mid-grey">{{ gap.detail }}</p>
                        <p v-if="gap.evidence" class="mt-1 border-l-2 border-fg-muted-grey pl-2 text-fg-light-grey">
                            {{ gap.evidence }}
                        </p>
                    </li>
                </ul>
                <p class="mt-2 text-xs text-fg-warning-text">
                    These cannot be keyed in — money proves a sale happened, not how many animals left.
                    Ask Kate for the docket.
                </p>
            </div>

            <div class="mt-3 flex items-center gap-3">
                <button
                    class="rounded bg-fg-main-blue px-4 py-1.5 text-sm font-medium text-white hover:bg-fg-main-blue-hover disabled:opacity-50"
                    :disabled="!selectedCount || accepting"
                    @click="acceptSelected"
                >
                    {{ accepting ? 'Keying in…' : `Key in ${selectedCount} movement(s)` }}
                </button>
                <p class="text-xs text-fg-light-grey">
                    Suggestions only — nothing is saved until you key it in. Every movement is built from the paper
                    trail and checked against the bank feed, the monthly report and Kate's emails. A balancing entry
                    is an adjustment, not a counted animal — it posts as a <span class="font-mono">loss</span>, on its
                    own line and under its own note, so it never inflates the deaths Kate is claiming on.
                </p>
            </div>
        </div>

        <p v-if="loading" class="text-fg-light-grey">Loading…</p>

        <div v-else class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <div class="space-y-4">
                <!-- Tally table per stock class -->
                <div
                    v-for="stockClass in classes"
                    :key="stockClass.id"
                    class="rounded border border-fg-muted-grey bg-white p-4"
                >
                    <div class="mb-2 flex items-center justify-between">
                        <h3 class="font-semibold">{{ stockClass.name }}</h3>
                        <span
                            class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                            :class="
                                tally(stockClass).difference === 0
                                    ? 'bg-fg-positive-15 text-fg-positive-dark'
                                    : 'bg-fg-danger-15 text-fg-danger-dark'
                            "
                        >
                            {{
                                tally(stockClass).difference === 0
                                    ? 'reconciled'
                                    : `out by ${tally(stockClass).difference > 0 ? '+' : ''}${tally(stockClass).difference}`
                            }}
                        </span>
                    </div>

                    <table class="w-full text-sm">
                        <tbody>
                            <tr class="border-t border-fg-pale-grey">
                                <td class="py-1 text-fg-mid-grey">Opening (1 Jul 2025)</td>
                                <td class="py-1 text-right font-mono">{{ stockClass.opening_count.toLocaleString() }}</td>
                            </tr>
                            <tr class="border-t border-fg-pale-grey">
                                <td class="py-1 text-fg-mid-grey">+ Births</td>
                                <td class="py-1 text-right font-mono">{{ tally(stockClass).births.toLocaleString() }}</td>
                            </tr>
                            <tr class="border-t border-fg-pale-grey">
                                <td class="py-1 text-fg-mid-grey">+ Purchases</td>
                                <td class="py-1 text-right font-mono">{{ tally(stockClass).purchases.toLocaleString() }}</td>
                            </tr>
                            <tr class="border-t border-fg-pale-grey">
                                <td class="py-1 text-fg-mid-grey">− Deaths</td>
                                <td class="py-1 text-right font-mono">{{ tally(stockClass).deaths.toLocaleString() }}</td>
                            </tr>
                            <tr class="border-t border-fg-pale-grey">
                                <td class="py-1 text-fg-mid-grey">− Sales</td>
                                <td class="py-1 text-right font-mono">{{ tally(stockClass).sales.toLocaleString() }}</td>
                            </tr>
                            <tr class="border-t border-fg-pale-grey">
                                <td class="py-1 text-fg-mid-grey">
                                    − Losses
                                    <span
                                        class="text-fg-light-grey"
                                        title="Stock that cannot be accounted for — missing, strayed or estimated. Kept off the Deaths line because only counted deaths can be claimed."
                                    >
                                        (unaccounted)
                                    </span>
                                </td>
                                <td class="py-1 text-right font-mono">{{ tally(stockClass).losses.toLocaleString() }}</td>
                            </tr>
                            <tr class="border-t border-fg-muted-grey font-medium">
                                <td class="py-1">= Calculated closing</td>
                                <td class="py-1 text-right font-mono">{{ tally(stockClass).calculated.toLocaleString() }}</td>
                            </tr>
                            <tr>
                                <td class="py-1 text-fg-mid-grey">Recorded closing (tally book)</td>
                                <td class="py-1 text-right font-mono">{{ stockClass.closing_count.toLocaleString() }}</td>
                            </tr>
                        </tbody>
                    </table>

                    <p v-if="projected(stockClass).count" class="mt-2 rounded bg-fg-main-blue-9 px-2 py-1 text-xs text-fg-mid-grey">
                        With {{ projected(stockClass).count }} pending suggestion(s):
                        <span class="font-mono">{{ projected(stockClass).calculated.toLocaleString() }}</span>
                        —
                        <span :class="settled(stockClass).difference === 0 ? 'text-fg-positive-dark' : 'text-fg-warning-text'">{{
                            settled(stockClass).balanced
                                ? 'reconciles with a balancing entry'
                                : settled(stockClass).difference === 0
                                  ? 'reconciles'
                                  : `out by ${settled(stockClass).difference > 0 ? '+' : ''}${settled(stockClass).difference}`
                        }}</span>
                    </p>

                    <details v-if="stockClass.movements.length" class="mt-2">
                        <summary class="cursor-pointer text-xs text-fg-light-grey">
                            {{ stockClass.movements.length }} movement(s) entered
                        </summary>
                        <ul class="mt-1 space-y-1">
                            <li
                                v-for="movement in stockClass.movements"
                                :key="movement.id"
                                class="flex items-center justify-between rounded bg-fg-super-pale-grey px-2 py-1 text-xs"
                            >
                                <span>
                                    <span class="font-medium capitalize">{{ movement.type }}</span>
                                    × {{ movement.quantity.toLocaleString() }}
                                    <span v-if="movement.note" class="text-fg-light-grey">— {{ movement.note }}</span>
                                </span>
                                <button
                                    class="ml-2 text-fg-light-grey hover:text-fg-danger"
                                    title="Delete movement"
                                    @click="removeMovement(stockClass, movement)"
                                >
                                    ✕
                                </button>
                            </li>
                        </ul>
                    </details>
                </div>

                <!-- Why the numbers do not meet. Evidence first, then the standing
                     questions - never mixed together, because they are not worth the
                     same. Works with no AI call; the model's read is folded in when
                     the paper trail has been read. -->
                <div class="rounded border border-fg-muted-grey bg-white p-4">
                    <h3 class="text-sm font-semibold">
                        Possible reasons for the discrepancies
                        <span class="font-normal text-fg-light-grey">
                            — {{ unreconciled.length }} of {{ classes.length }} class(es) not reconciling
                        </span>
                    </h3>

                    <p
                        v-if="!unreconciled.length"
                        class="mt-2 rounded bg-fg-positive-15 px-3 py-2 text-sm text-fg-positive-dark"
                    >
                        Every class reconciles. Check the Losses line before signing anything off — an unaccounted loss
                        balances the arithmetic but is not a number the farmer has confirmed.
                    </p>

                    <div
                        v-for="stockClass in unreconciled"
                        :key="stockClass.id"
                        class="mt-3 rounded border border-fg-warning bg-fg-warning-15 p-3"
                    >
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <h4 class="font-medium">{{ stockClass.name }}</h4>
                            <span class="font-mono text-xs text-fg-warning-text">
                                {{ tally(stockClass).calculated.toLocaleString() }} calculated vs
                                {{ stockClass.closing_count.toLocaleString() }} recorded ·
                                {{ discrepancyFor(stockClass).difference > 0 ? '+' : ''
                                }}{{ discrepancyFor(stockClass).difference }}
                            </span>
                        </div>

                        <p class="mt-1 text-sm text-fg-dark-grey">{{ discrepancyFor(stockClass).meaning }}</p>

                        <!-- The model's read. Cited, so the adviser can check it. -->
                        <div v-if="discrepancyFor(stockClass).residual" class="mt-2 rounded bg-white p-2 text-xs">
                            <p class="font-medium">What the paper trail points at</p>
                            <p class="mt-0.5 text-fg-mid-grey">
                                {{ discrepancyFor(stockClass).residual.likely_cause }}
                            </p>
                            <p
                                v-if="discrepancyFor(stockClass).residual.ask_the_farmer"
                                class="mt-1 font-medium text-fg-dark-grey"
                            >
                                Ask Kate: {{ discrepancyFor(stockClass).residual.ask_the_farmer }}
                            </p>
                            <span
                                v-for="id in discrepancyFor(stockClass).residual.source_record_ids"
                                :key="id"
                                class="mt-1 block border-l-2 border-fg-muted-grey pl-2 text-fg-light-grey"
                            >
                                {{ shortDate(recordsById[id]?.recorded_on) }} · {{ recordsById[id]?.source }} —
                                {{ recordsById[id]?.body }}
                            </span>
                        </div>
                        <p v-else-if="!reviewed" class="mt-2 text-xs italic text-fg-mid-grey">
                            Read the paper trail to get its own read on this gap.
                        </p>

                        <!-- Adjustments already posted, so they can never be read as -->
                        <!-- animals somebody counted. -->
                        <div v-if="discrepancyFor(stockClass).adjustments.length" class="mt-2 rounded bg-white p-2 text-xs">
                            <p class="font-medium">Already adjusted, still unconfirmed</p>
                            <p
                                v-for="adjustment in discrepancyFor(stockClass).adjustments"
                                :key="adjustment.id"
                                class="mt-0.5 text-fg-mid-grey"
                            >
                                <span class="font-mono">{{ adjustment.type }} × {{ adjustment.quantity }}</span> —
                                {{ adjustment.note }}
                            </p>
                        </div>

                        <details class="mt-2">
                            <summary class="cursor-pointer text-xs font-medium text-fg-warning-text">
                                {{ discrepancyFor(stockClass).causes.length }} usual causes to rule out
                            </summary>
                            <ul class="mt-1 list-disc space-y-0.5 pl-5 text-xs text-fg-mid-grey">
                                <li v-for="(cause, i) in discrepancyFor(stockClass).causes" :key="i">{{ cause }}</li>
                            </ul>
                            <p class="mt-1 text-xs italic text-fg-light-grey">
                                General questions for the farmer, not findings — nothing in this list came from the
                                books.
                            </p>
                        </details>
                    </div>

                    <!-- Pure arithmetic over the paper trail and the bank feed. No AI. -->
                    <div v-if="hasCrossChecks" class="mt-3 rounded border border-fg-main-blue-30 bg-fg-main-blue-9 p-3">
                        <p class="text-xs font-semibold">Cross-checks against the rest of the books</p>

                        <div v-if="depositsWithoutDocket.length" class="mt-1.5 text-xs">
                            <p class="font-medium">
                                {{ depositsWithoutDocket.length }} livestock deposit(s) with no docket behind them
                            </p>
                            <p
                                v-for="(deposit, i) in depositsWithoutDocket"
                                :key="i"
                                class="mt-0.5 border-l-2 border-fg-muted-grey pl-2 text-fg-mid-grey"
                            >
                                {{ shortDate(deposit.recorded_on) }} · {{ deposit.title }} ·
                                <span class="font-mono">{{ money(deposit.amount) }}</span>
                            </p>
                            <p class="mt-1 text-fg-mid-grey">
                                Money the books show arriving that no piece of paper explains. This feed is shared
                                across the practice's clients, so it is a question for Kate, never a movement.
                            </p>
                        </div>

                        <div v-if="docketsBankedOnce.length" class="mt-2 text-xs">
                            <p class="font-medium">
                                {{ docketsBankedOnce.length }} docket total(s) filed more often than they were banked
                            </p>
                            <div v-for="(group, i) in docketsBankedOnce" :key="i" class="mt-0.5">
                                <p class="text-fg-mid-grey">
                                    <span class="font-mono">{{ money(group.total) }}</span> — on
                                    {{ group.rows.length }} records, {{ group.inBank }} matching deposit(s).
                                </p>
                                <p
                                    v-for="row in group.rows"
                                    :key="row.id"
                                    class="border-l-2 border-fg-muted-grey pl-2 text-fg-light-grey"
                                >
                                    {{ shortDate(row.recorded_on) }} · {{ row.source }} — {{ row.body }}
                                </p>
                            </div>
                            <p class="mt-1 text-fg-mid-grey">
                                One deposit means one sale. Keying in both would overstate sales and pull the class the
                                other way.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- New movement form -->
                <div class="rounded border border-fg-muted-grey bg-white p-4">
                    <h3 class="mb-2 text-sm font-semibold">Key in a movement</h3>
                    <div class="flex flex-wrap items-end gap-2">
                        <div>
                            <label class="block text-xs font-medium text-fg-mid-grey">Stock class</label>
                            <select
                                v-model="movementForm.stock_class_id"
                                class="rounded border border-fg-muted-grey px-2 py-1 text-sm"
                            >
                                <option v-for="c in classes" :key="c.id" :value="c.id">{{ c.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-fg-mid-grey">Type</label>
                            <select v-model="movementForm.type" class="rounded border border-fg-muted-grey px-2 py-1 text-sm">
                                <option value="birth">Birth</option>
                                <option value="purchase">Purchase</option>
                                <option value="death">Death (counted)</option>
                                <option value="sale">Sale</option>
                                <option value="loss">Loss (unaccounted)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-fg-mid-grey">Quantity</label>
                            <input
                                v-model.number="movementForm.quantity"
                                type="number"
                                min="1"
                                class="w-24 rounded border border-fg-muted-grey px-2 py-1 text-right text-sm"
                            />
                        </div>
                        <div class="grow">
                            <label class="block text-xs font-medium text-fg-mid-grey">Note (source record)</label>
                            <input
                                v-model="movementForm.note"
                                placeholder="e.g. docket S-40102"
                                class="w-full rounded border border-fg-muted-grey px-2 py-1 text-sm"
                            />
                        </div>
                        <button
                            class="rounded bg-fg-main-blue px-4 py-1.5 text-sm font-medium text-white hover:bg-fg-main-blue-hover disabled:opacity-50"
                            :disabled="!canSave || saving"
                            @click="addMovement"
                        >
                            Add
                        </button>
                    </div>
                </div>
            </div>

            <!-- Raw source records -->
            <div class="rounded border border-fg-muted-grey bg-white">
                <h3 class="border-b border-fg-pale-grey px-4 py-2 text-sm font-semibold">The paper trail</h3>
                <ul>
                    <li
                        v-for="record in records"
                        :key="record.id"
                        class="border-b border-fg-pale-grey px-4 py-2 text-sm"
                        :class="{
                            'border-l-4 border-l-fg-main-blue': citedRecordIds.has(record.id),
                            'border-l-4 border-l-fg-warning': skippedRecordIds.has(record.id),
                        }"
                    >
                        <div class="mb-0.5 flex items-center gap-2">
                            <span class="text-xs text-fg-light-grey">{{ shortDate(record.recorded_on) }}</span>
                            <span class="rounded-full px-2 py-0.5 text-xs" :class="sourceBadgeClass[record.source]">
                                {{ record.source }}
                            </span>
                            <span v-if="skippedRecordIds.has(record.id)" class="text-xs font-medium text-fg-warning-text">
                                ignored
                            </span>
                        </div>
                        <p class="leading-snug">{{ record.body }}</p>
                    </li>
                </ul>

                <!-- The second paper trail. Everything the reconciliation was checked
                     against, pulled straight from the rest of the practice's database.
                     This is the same set of rows the model is given. -->
                <div class="border-t-4 border-fg-pale-grey">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-fg-pale-grey px-4 py-2">
                        <h3 class="text-sm font-semibold">
                            Cross-referenced records
                            <span class="font-normal text-fg-light-grey">— {{ crossRecords.length }} from the rest of the books</span>
                        </h3>
                        <div class="flex flex-wrap gap-1">
                            <button
                                v-for="kind in crossKinds"
                                :key="kind"
                                class="rounded-full px-2 py-0.5 text-xs"
                                :class="
                                    crossFilter === kind
                                        ? 'bg-fg-dark-blue text-white'
                                        : 'bg-fg-pale-grey text-fg-mid-grey hover:bg-fg-muted-grey'
                                "
                                @click="crossFilter = kind"
                            >
                                {{ kind }}
                            </button>
                        </div>
                    </div>

                    <p class="border-b border-fg-pale-grey bg-fg-super-pale-grey px-4 py-1.5 text-xs text-fg-mid-grey">
                        Corroboration only — these can confirm or contradict a movement, but never create one.
                    </p>

                    <ul>
                        <li
                            v-for="(record, i) in visibleCrossRecords"
                            :key="i"
                            class="border-b border-fg-pale-grey px-4 py-2 text-sm"
                        >
                            <div class="mb-0.5 flex flex-wrap items-center gap-2">
                                <span class="text-xs text-fg-light-grey">{{
                                    record.recorded_on ? shortDate(record.recorded_on) : 'undated'
                                }}</span>
                                <span class="rounded-full px-2 py-0.5 text-xs" :class="crossBadgeClass[record.kind]">
                                    {{ record.kind }}
                                </span>
                                <span
                                    v-if="!record.scoped"
                                    class="rounded-full bg-fg-warning-15 px-2 py-0.5 text-xs text-fg-warning-text"
                                    title="bank_transactions has no farm column — this feed carries more than one client"
                                >
                                    not farm-scoped
                                </span>
                                <span
                                    v-if="record.amount !== null"
                                    class="ml-auto font-mono text-xs"
                                    :class="record.amount < 0 ? 'text-fg-danger-dark' : 'text-fg-positive-dark'"
                                >
                                    {{ money(record.amount) }}
                                </span>
                            </div>
                            <p class="font-medium leading-snug">{{ record.title }}</p>
                            <p class="text-xs leading-snug text-fg-mid-grey">{{ record.body }}</p>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</template>
