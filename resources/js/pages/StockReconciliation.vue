<script setup>
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import { shortDate } from '../format';

const classes = ref([]);
const records = ref([]);
const loading = ref(true);
const saving = ref(false);

const movementForm = ref({
    stock_class_id: null,
    type: 'sale',
    quantity: null,
    note: '',
});

onMounted(load);

async function load() {
    const { data } = await axios.get('/api/stock');
    classes.value = data.classes;
    records.value = data.records;
    if (!movementForm.value.stock_class_id) {
        movementForm.value.stock_class_id = data.classes[0]?.id ?? null;
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

const canSave = computed(
    () => movementForm.value.stock_class_id && movementForm.value.quantity > 0 && movementForm.value.type,
);

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

// Hand the whole paper trail to Claude and get back proposed movements, each
// with a confidence score and a flag on anything that needs a human eye.
const parsing = ref(false);
const parseError = ref('');
const parseResult = ref(null);
const copied = ref(false);

async function parseRecords() {
    parsing.value = true;
    parseError.value = '';
    parseResult.value = null;
    try {
        const { data } = await axios.post('/api/stock/parse');
        parseResult.value = data;
    } catch (e) {
        const body = e.response?.data;
        parseError.value = body?.raw ? `${body.error}\n\n${body.raw}` : (body?.error ?? e.message);
    } finally {
        parsing.value = false;
    }
}

const parseJson = computed(() => (parseResult.value ? JSON.stringify(parseResult.value, null, 2) : ''));

async function copyJson() {
    await navigator.clipboard.writeText(parseJson.value);
    copied.value = true;
    setTimeout(() => (copied.value = false), 1500);
}

const sourceBadgeClass = {
    'Diary': 'bg-fg-warning-15 text-fg-warning-text',
    'Sale docket': 'bg-fg-light-blue-15 text-fg-light-blue',
    'Text message': 'bg-fg-brown-15 text-fg-brown',
};
</script>

<template>
    <div>
        <div class="mb-4">
            <h2 class="text-lg font-semibold">Stock reconciliation — Kahikatea Downs</h2>
            <p class="text-sm text-fg-mid-grey">
                Key stock movements in from the raw records (right) until each tally matches the farmer's recorded
                closing count. Stock year 1 Jul 2025 – 30 Jun 2026. The lamb docking tally is entered as an example.
            </p>
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

            <div class="space-y-4">
                <!-- Raw source records -->
                <div class="rounded border border-fg-muted-grey bg-white">
                    <div class="flex items-center justify-between gap-2 border-b border-fg-pale-grey px-4 py-2">
                        <h3 class="text-sm font-semibold">The paper trail</h3>
                        <button
                            class="rounded bg-fg-main-blue px-3 py-1 text-xs font-medium text-white hover:bg-fg-main-blue-hover disabled:opacity-50"
                            :disabled="parsing"
                            @click="parseRecords"
                        >
                            {{ parsing ? 'Parsing…' : 'Parse the paper trail' }}
                        </button>
                    </div>
                    <ul>
                        <li
                            v-for="record in records"
                            :key="record.id"
                            class="border-b border-fg-pale-grey px-4 py-2 text-sm"
                        >
                            <div class="mb-0.5 flex items-center gap-2">
                                <span class="text-xs text-fg-light-grey">{{ shortDate(record.recorded_on) }}</span>
                                <span class="rounded-full px-2 py-0.5 text-xs" :class="sourceBadgeClass[record.source]">
                                    {{ record.source }}
                                </span>
                            </div>
                            <p class="leading-snug">{{ record.body }}</p>
                        </li>
                    </ul>
                </div>

                <!-- Proposed movements, as JSON, ready to be keyed in -->
                <p v-if="parseError" class="rounded bg-fg-danger-9 p-3 text-sm whitespace-pre-wrap text-fg-danger-dark">
                    {{ parseError }}
                </p>

                <div v-if="parseResult" class="rounded border border-fg-muted-grey bg-white">
                    <div class="flex items-center justify-between gap-2 border-b border-fg-pale-grey px-4 py-2">
                        <h3 class="text-sm font-semibold">
                            Proposed movements
                            <span class="font-normal text-fg-light-grey">
                                — {{ parseResult.proposals.length }} proposed,
                                {{ parseResult.unresolved.length }} unresolved
                            </span>
                        </h3>
                        <button
                            class="rounded border border-fg-muted-grey px-3 py-1 text-xs font-medium hover:bg-fg-super-pale-grey"
                            @click="copyJson"
                        >
                            {{ copied ? 'Copied' : 'Copy JSON' }}
                        </button>
                    </div>
                    <pre class="overflow-x-auto p-4 text-xs leading-relaxed">{{ parseJson }}</pre>
                </div>
            </div>
        </div>
    </div>
</template>
