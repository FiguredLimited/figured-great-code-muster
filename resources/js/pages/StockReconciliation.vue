<script setup>
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import { shortDate } from '../format';

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

// Tally per class: opening + births + purchases - deaths - sales.
function tally(stockClass) {
    const sum = (type) =>
        stockClass.movements.filter((m) => m.type === type).reduce((total, m) => total + m.quantity, 0);
    const calculated = stockClass.opening_count + sum('birth') + sum('purchase') - sum('death') - sum('sale');
    return {
        births: sum('birth'),
        purchases: sum('purchase'),
        deaths: sum('death'),
        sales: sum('sale'),
        calculated,
        difference: calculated - stockClass.closing_count,
    };
}

// The same arithmetic, with the ticked proposals folded in - so the adviser can
// see where a class lands before anything is written.
function projected(stockClass) {
    const picked = proposals.value.filter((p) => p.selected && p.stock_class_id === stockClass.id);
    const sum = (type) => picked.filter((p) => p.type === type).reduce((total, p) => total + p.quantity, 0);
    const calculated = tally(stockClass).calculated + sum('birth') + sum('purchase') - sum('death') - sum('sale');
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
 * A surplus on paper means animals left without being recorded, so it clears as
 * a death; a shortfall clears as a birth.
 */
function balancingFor(stockClass) {
    const difference = projected(stockClass).difference;
    return {
        quantity: Math.abs(difference),
        type: difference > 0 ? 'death' : 'birth',
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

    for (const proposal of proposals.value.filter((p) => p.selected)) {
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
            note: `Balancing entry — ${balancer.quantity} unaccounted, pending confirmation`,
        });
        classes.value.find((c) => c.id === data.stock_class_id).movements.push(data);
    }

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

            <!-- Where each class lands if the ticked rows go in. -->
            <div v-if="proposals.length" class="mb-3 grid grid-cols-1 gap-2 sm:grid-cols-3">
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
                    is an adjustment, not a counted animal — it is saved under its own note so it stays visible.
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
                                <option value="death">Death</option>
                                <option value="sale">Sale</option>
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
            </div>
        </div>
    </div>
</template>
