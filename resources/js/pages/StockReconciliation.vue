<script setup>
import axios from "axios";
import { computed, onMounted, ref } from "vue";
import { shortDate } from "../format";
import {
    buildReconciliationPrompt,
    parseProposalPayload,
    prepareReconciliationReport,
} from "../stockReconciliationReport";

const classes = ref([]);
const records = ref([]);
const loading = ref(true);
const saving = ref(false);
const openClasses = ref(new Set());
const reportLoading = ref(false);
const reportError = ref("");
const reportText = ref("");
const reportData = ref(null);

function toggleClass(id) {
    const next = new Set(openClasses.value);
    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }
    openClasses.value = next;
}

const movementForm = ref({
    stock_class_id: null,
    type: "sale",
    quantity: null,
    note: "",
});

onMounted(load);

async function load() {
    const { data } = await axios.get("/api/stock");
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
        stockClass.movements
            .filter((m) => m.type === type)
            .reduce((total, m) => total + m.quantity, 0);
    const calculated =
        stockClass.opening_count +
        sum("birth") +
        sum("purchase") -
        sum("death") -
        sum("sale");
    return {
        births: sum("birth"),
        purchases: sum("purchase"),
        deaths: sum("death"),
        sales: sum("sale"),
        calculated,
        difference: calculated - stockClass.closing_count,
    };
}

const canSave = computed(
    () =>
        movementForm.value.stock_class_id &&
        movementForm.value.quantity > 0 &&
        movementForm.value.type,
);

async function addMovement() {
    saving.value = true;
    const { data } = await axios.post(
        "/api/stock-movements",
        movementForm.value,
    );
    classes.value
        .find((c) => c.id === data.stock_class_id)
        .movements.push(data);
    movementForm.value.quantity = null;
    movementForm.value.note = "";
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
    reportError.value = "";
    reportText.value = "";
    reportData.value = null;
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
    delete keyedByMovement.value[movement.id];
}

// Hand the whole paper trail to Claude and get back proposed movements, each
// with a confidence score and a flag on anything that needs a human eye.
const parsing = ref(false);
const parseError = ref('');
const parseResult = ref(null);
const proposals = ref([]);
const copied = ref(false);
const applyingUid = ref(null);
const acceptedCount = ref(0);
const acceptTotal = ref(0);
const acceptingAll = ref(false);

async function parseRecords() {
    parsing.value = true;
    parseError.value = '';
    parseResult.value = null;
    proposals.value = [];
    try {
        const { data } = await axios.post('/api/stock/parse');
        parseResult.value = data;
        // Proposals have no id, so give each one a stable key to render against.
        proposals.value = data.proposals.map((p, i) => ({
            ...p,
            uid: i,
            forReport: false,
            sentToReview: false,
        }));
        // Movements already in the books (the seeded docking tally) get linked to
        // their records up front, so the paper trail shows them as keyed.
        for (const proposal of proposals.value) {
            const movement = matchingMovement(proposal);
            if (movement) {
                keyedByMovement.value[movement.id] = proposal.record_ids;
            }
        }
    } catch (e) {
        const body = e.response?.data;
        parseError.value = body?.raw ? `${body.error}\n\n${body.raw}` : (body?.error ?? e.message);
    } finally {
        parsing.value = false;
    }
}

// A movement matching this proposal is already in the books - the seeded docking
// tally is the obvious one. Accepting it again would silently double the count.
function matchingMovement(proposal) {
    const stockClass = classes.value.find((c) => c.id === proposal.stock_class_id);
    return stockClass?.movements.find(
        (m) => m.type === proposal.type && m.quantity === proposal.quantity,
    );
}

function alreadyKeyed(proposal) {
    return !!matchingMovement(proposal);
}

// Which raw records have made it into the tally, tracked per movement so deleting
// a movement takes its records back out of the "in tally" state.
const keyedByMovement = ref({});

const keyedRecordIds = computed(() => new Set(Object.values(keyedByMovement.value).flat()));

// Anything Claude flagged, was shaky about, or that looks already entered goes to
// a human. Judged on what Claude originally said, so editing a card in the review
// list does not make it jump into the accept-all pile mid-edit.
const REVIEW_CONFIDENCE = 0.85;

function needsReview(proposal) {
    return (
        proposal.sentToReview ||
        !proposal.include ||
        proposal.flag !== null ||
        proposal.confidence < REVIEW_CONFIDENCE ||
        alreadyKeyed(proposal)
    );
}

const readyProposals = computed(() => proposals.value.filter((p) => !needsReview(p)));
const reviewProposals = computed(() => proposals.value.filter((p) => needsReview(p)));
const reportCount = computed(() => proposals.value.filter((p) => p.forReport).length);

const flagLabel = {
    duplicate: 'Possible duplicate',
    superseded: 'Superseded by a later record',
    same_event: 'Same event in two records',
    source_mislabelled: 'Source label looks wrong',
    quantity_estimated: 'Quantity estimated',
};

// Hard flags mean "this is probably wrong"; soft ones mean "check the number".
const hardFlags = ['duplicate', 'superseded', 'same_event', 'source_mislabelled'];

// Claude's own flag wins over the already-keyed hint: two genuinely different
// movements can share a class, type and quantity (the 38 steers and the 38 cull
// cows both do), so applying one must not relabel the other.
function reviewBadge(proposal) {
    if (proposal.flag) {
        return { label: flagLabel[proposal.flag] ?? proposal.flag, hard: hardFlags.includes(proposal.flag) };
    }
    if (alreadyKeyed(proposal)) {
        return { label: 'Already keyed', hard: false };
    }
    if (proposal.confidence < REVIEW_CONFIDENCE) {
        return { label: 'Low confidence', hard: false };
    }
    return { label: 'Held for review', hard: false };
}

const recordsById = computed(() => Object.fromEntries(records.value.map((r) => [r.id, r])));

// The raw records behind a proposal, so the reviewer can judge it without
// scrolling back up to the paper trail.
function sourceRecords(proposal) {
    return proposal.record_ids.map((id) => recordsById.value[id]).filter(Boolean);
}

async function applyProposal(proposal) {
    applyingUid.value = proposal.uid;
    parseError.value = '';
    try {
        const { data } = await axios.post('/api/stock-movements', {
            stock_class_id: proposal.stock_class_id,
            type: proposal.type,
            quantity: proposal.quantity,
            note: proposal.note,
        });
        classes.value.find((c) => c.id === data.stock_class_id).movements.push(data);
        keyedByMovement.value[data.id] = proposal.record_ids;
        dismissProposal(proposal);
    } catch (e) {
        parseError.value = e.response?.data?.message ?? e.message;
    } finally {
        applyingUid.value = null;
    }
}

// One at a time so a failure part-way through leaves the rest of the queue intact.
async function acceptAll() {
    // Snapshot the queue: applying a proposal removes it from readyProposals.
    const queue = [...readyProposals.value];
    acceptingAll.value = true;
    acceptedCount.value = 0;
    acceptTotal.value = queue.length;
    for (const proposal of queue) {
        await applyProposal(proposal);
        if (parseError.value) break;
        acceptedCount.value += 1;
    }
    acceptingAll.value = false;
}

function dismissProposal(proposal) {
    proposals.value = proposals.value.filter((p) => p.uid !== proposal.uid);
}

// Pulling a row out of the accept-all batch parks it in the review list rather
// than throwing it away - it still has to be dealt with.
function sendToReview(proposal) {
    proposal.sentToReview = true;
}

const parseJson = computed(() => (parseResult.value ? JSON.stringify(parseResult.value, null, 2) : ''));

async function copyJson() {
    await navigator.clipboard.writeText(parseJson.value);
    copied.value = true;
    setTimeout(() => (copied.value = false), 1500);
}

async function generateReport() {
    reportLoading.value = true;
    reportError.value = "";
    reportText.value = "";
    reportData.value = null;

    try {
        const proposals = parseProposalPayload(parseResult.value);
        const preparedReport = prepareReconciliationReport(
            classes.value,
            proposals,
            parseResult.value.unresolved,
        );
        reportData.value = preparedReport;
        const { data } = await axios.post("/api/ai", {
            system: "You are a careful livestock reconciliation assistant. Report only the supplied facts and calculations.",
            prompt: buildReconciliationPrompt(preparedReport),
        });

        reportText.value = data.text;
    } catch (error) {
        reportError.value = error.response?.data?.error ?? error.message;
    } finally {
        reportLoading.value = false;
    }
}

const sourceBadgeClass = {
    Diary: "bg-fg-warning-15 text-fg-warning-text",
    "Sale docket": "bg-fg-light-blue-15 text-fg-light-blue",
    "Text message": "bg-fg-brown-15 text-fg-brown",
};
</script>

<template>
    <div>
        <div class="mb-4">
            <h2 class="text-lg font-semibold">
                Stock reconciliation — Kahikatea Downs
            </h2>
            <p class="text-sm text-fg-mid-grey">
                Key stock movements in from the raw records (right) until each
                tally matches the farmer's recorded closing count. Stock year 1
                Jul 2025 – 30 Jun 2026. The lamb docking tally is entered as an
                example.
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
                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-2"
                        :class="{ 'mb-2': openClasses.has(stockClass.id) }"
                        @click="toggleClass(stockClass.id)"
                    >
                        <span class="flex items-center gap-2">
                            <svg
                                class="h-3.5 w-3.5 shrink-0 text-fg-light-grey transition-transform"
                                :class="{
                                    '-rotate-90': !openClasses.has(
                                        stockClass.id,
                                    ),
                                }"
                                viewBox="0 0 20 20"
                                fill="currentColor"
                            >
                                <path
                                    fill-rule="evenodd"
                                    d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z"
                                    clip-rule="evenodd"
                                />
                            </svg>
                            <h3 class="font-semibold">{{ stockClass.name }}</h3>
                        </span>
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
                                    ? "reconciled"
                                    : `out by ${tally(stockClass).difference > 0 ? "+" : ""}${tally(stockClass).difference}`
                            }}
                        </span>
                    </button>

                    <div v-show="openClasses.has(stockClass.id)">
                        <table class="w-full text-sm">
                            <tbody>
                                <tr class="border-t border-fg-pale-grey">
                                    <td class="py-1 text-fg-mid-grey">
                                        Opening (1 Jul 2025)
                                    </td>
                                    <td class="py-1 text-right font-mono">
                                        {{
                                            stockClass.opening_count.toLocaleString()
                                        }}
                                    </td>
                                </tr>
                                <tr class="border-t border-fg-pale-grey">
                                    <td class="py-1 text-fg-mid-grey">
                                        + Births
                                    </td>
                                    <td class="py-1 text-right font-mono">
                                        {{
                                            tally(
                                                stockClass,
                                            ).births.toLocaleString()
                                        }}
                                    </td>
                                </tr>
                                <tr class="border-t border-fg-pale-grey">
                                    <td class="py-1 text-fg-mid-grey">
                                        + Purchases
                                    </td>
                                    <td class="py-1 text-right font-mono">
                                        {{
                                            tally(
                                                stockClass,
                                            ).purchases.toLocaleString()
                                        }}
                                    </td>
                                </tr>
                                <tr class="border-t border-fg-pale-grey">
                                    <td class="py-1 text-fg-mid-grey">
                                        − Deaths
                                    </td>
                                    <td class="py-1 text-right font-mono">
                                        {{
                                            tally(
                                                stockClass,
                                            ).deaths.toLocaleString()
                                        }}
                                    </td>
                                </tr>
                                <tr class="border-t border-fg-pale-grey">
                                    <td class="py-1 text-fg-mid-grey">
                                        − Sales
                                    </td>
                                    <td class="py-1 text-right font-mono">
                                        {{
                                            tally(
                                                stockClass,
                                            ).sales.toLocaleString()
                                        }}
                                    </td>
                                </tr>
                                <tr
                                    class="border-t border-fg-muted-grey font-medium"
                                >
                                    <td class="py-1">= Calculated closing</td>
                                    <td class="py-1 text-right font-mono">
                                        {{
                                            tally(
                                                stockClass,
                                            ).calculated.toLocaleString()
                                        }}
                                    </td>
                                </tr>
                                <tr>
                                    <td class="py-1 text-fg-mid-grey">
                                        Recorded closing (tally book)
                                    </td>
                                    <td class="py-1 text-right font-mono">
                                        {{
                                            stockClass.closing_count.toLocaleString()
                                        }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>

                        <details
                            v-if="stockClass.movements.length"
                            class="mt-2"
                        >
                            <summary
                                class="cursor-pointer text-xs text-fg-light-grey"
                            >
                                {{ stockClass.movements.length }} movement(s)
                                entered
                            </summary>
                            <ul class="mt-1 space-y-1">
                                <li
                                    v-for="movement in stockClass.movements"
                                    :key="movement.id"
                                    class="flex items-center justify-between rounded bg-fg-super-pale-grey px-2 py-1 text-xs"
                                >
                                    <span>
                                        <span class="font-medium capitalize">{{
                                            movement.type
                                        }}</span>
                                        ×
                                        {{ movement.quantity.toLocaleString() }}
                                        <span
                                            v-if="movement.note"
                                            class="text-fg-light-grey"
                                            >— {{ movement.note }}</span
                                        >
                                    </span>
                                    <button
                                        class="ml-2 text-fg-light-grey hover:text-fg-danger"
                                        title="Delete movement"
                                        @click="
                                            removeMovement(stockClass, movement)
                                        "
                                    >
                                        ✕
                                    </button>
                                </li>
                            </ul>
                        </details>
                    </div>
                </div>

                <!-- New movement form -->
                <div class="rounded border border-fg-muted-grey bg-white p-4">
                    <h3 class="mb-2 text-sm font-semibold">
                        Manually key in a movement
                    </h3>
                    <div class="flex flex-wrap items-end gap-2">
                        <div>
                            <label
                                class="block text-xs font-medium text-fg-mid-grey"
                                >Stock class</label
                            >
                            <select
                                v-model="movementForm.stock_class_id"
                                class="rounded border border-fg-muted-grey px-2 py-1 text-sm"
                            >
                                <option
                                    v-for="c in classes"
                                    :key="c.id"
                                    :value="c.id"
                                >
                                    {{ c.name }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <label
                                class="block text-xs font-medium text-fg-mid-grey"
                                >Type</label
                            >
                            <select
                                v-model="movementForm.type"
                                class="rounded border border-fg-muted-grey px-2 py-1 text-sm"
                            >
                                <option value="birth">Birth</option>
                                <option value="purchase">Purchase</option>
                                <option value="death">Death</option>
                                <option value="sale">Sale</option>
                            </select>
                        </div>
                        <div>
                            <label
                                class="block text-xs font-medium text-fg-mid-grey"
                                >Quantity</label
                            >
                            <input
                                v-model.number="movementForm.quantity"
                                type="number"
                                min="1"
                                class="w-24 rounded border border-fg-muted-grey px-2 py-1 text-right text-sm"
                            />
                        </div>
                        <div class="grow">
                            <label
                                class="block text-xs font-medium text-fg-mid-grey"
                                >Note (source record)</label
                            >
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
                        <h3 class="text-sm font-semibold">
                            The paper trail
                            <span v-if="keyedRecordIds.size" class="font-normal text-fg-light-grey">
                                — {{ keyedRecordIds.size }} of {{ records.length }} in the tally
                            </span>
                        </h3>
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
                            :class="keyedRecordIds.has(record.id) && 'bg-fg-positive-9'"
                        >
                            <div class="mb-0.5 flex items-center gap-2">
                                <span class="text-xs text-fg-light-grey">{{ shortDate(record.recorded_on) }}</span>
                                <span class="rounded-full px-2 py-0.5 text-xs" :class="sourceBadgeClass[record.source]">
                                    {{ record.source }}
                                </span>
                                <span
                                    v-if="keyedRecordIds.has(record.id)"
                                    class="rounded-full bg-fg-positive-15 px-2 py-0.5 text-xs font-medium text-fg-positive-dark"
                                >
                                    ✓ in tally
                                </span>
                            </div>
                            <p class="leading-snug">{{ record.body }}</p>
                        </li>
                    </ul>
                </div>

                <p v-if="parseError" class="rounded bg-fg-danger-9 p-3 text-sm whitespace-pre-wrap text-fg-danger-dark">
                    {{ parseError }}
                </p>
            </div>
        </div>

        <!-- What Claude made of the paper trail: the confident ones in a batch,
             the rest as editable cards for the accountant to judge. -->
        <div v-if="parseResult && !loading" class="mt-4 space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-sm font-semibold">
                    Proposed movements
                    <span class="font-normal text-fg-light-grey">
                        — {{ readyProposals.length }} ready · {{ reviewProposals.length }} need review
                        <span v-if="reportCount"> · {{ reportCount }} flagged for report</span>
                    </span>
                </h3>
                <button
                    class="rounded border border-fg-muted-grey px-3 py-1 text-xs font-medium hover:bg-fg-super-pale-grey"
                    @click="copyJson"
                >
                    {{ copied ? 'Copied' : 'Copy JSON' }}
                </button>
            </div>

            <p v-if="!proposals.length" class="rounded border border-fg-muted-grey bg-white p-4 text-sm text-fg-mid-grey">
                Every proposal has been actioned.
            </p>

            <!-- Confident enough to key in a batch -->
            <div v-if="readyProposals.length" class="rounded border border-fg-muted-grey bg-white">
                <div class="flex items-center justify-between gap-2 border-b border-fg-pale-grey px-4 py-2">
                    <h4 class="text-sm font-semibold">Ready to accept ({{ readyProposals.length }})</h4>
                    <button
                        class="rounded bg-fg-main-blue px-4 py-1.5 text-sm font-medium text-white hover:bg-fg-main-blue-hover disabled:opacity-50"
                        :disabled="acceptingAll"
                        @click="acceptAll"
                    >
                        {{
                            acceptingAll
                                ? `Accepting ${acceptedCount + 1} of ${acceptTotal}…`
                                : `Accept all ${readyProposals.length}`
                        }}
                    </button>
                </div>
                <table class="w-full text-sm">
                    <tbody>
                        <tr
                            v-for="proposal in readyProposals"
                            :key="proposal.uid"
                            class="border-b border-fg-pale-grey last:border-b-0"
                        >
                            <td class="py-1.5 pl-4 font-medium">{{ proposal.stock_class }}</td>
                            <td class="py-1.5 capitalize">{{ proposal.type }}</td>
                            <td class="py-1.5 text-right font-mono">{{ proposal.quantity.toLocaleString() }}</td>
                            <td class="w-full py-1.5 pl-4 text-fg-mid-grey">{{ proposal.note }}</td>
                            <td class="py-1.5 pr-2 text-right font-mono text-xs text-fg-light-grey">
                                {{ Math.round(proposal.confidence * 100) }}%
                            </td>
                            <td class="py-1.5 pr-4 text-right">
                                <button
                                    class="text-fg-light-grey hover:text-fg-main-blue"
                                    title="Move to needs review"
                                    @click="sendToReview(proposal)"
                                >
                                    ✕
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Everything Claude was not sure about -->
            <div v-if="reviewProposals.length" class="space-y-3">
                <h4 class="text-sm font-semibold">Needs review ({{ reviewProposals.length }})</h4>

                <div
                    v-for="proposal in reviewProposals"
                    :key="proposal.uid"
                    class="rounded border border-fg-muted-grey bg-white p-4"
                >
                    <div class="mb-2 flex items-start justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <span
                                class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                                :class="
                                    reviewBadge(proposal).hard
                                        ? 'bg-fg-danger-15 text-fg-danger-dark'
                                        : 'bg-fg-warning-15 text-fg-warning-text'
                                "
                            >
                                {{ reviewBadge(proposal).label }}
                            </span>
                            <span class="font-mono text-xs text-fg-light-grey">
                                {{ Math.round(proposal.confidence * 100) }}% confident
                            </span>
                        </div>
                        <button
                            class="text-fg-light-grey hover:text-fg-danger"
                            title="Discard proposal"
                            @click="dismissProposal(proposal)"
                        >
                            ✕
                        </button>
                    </div>

                    <p v-if="proposal.reasoning" class="mb-2 text-sm text-fg-mid-grey">{{ proposal.reasoning }}</p>

                    <!-- The raw records this came from, so the call can be checked -->
                    <ul class="mb-3 space-y-1 rounded bg-fg-super-pale-grey p-2">
                        <li v-for="record in sourceRecords(proposal)" :key="record.id" class="text-xs">
                            <span class="text-fg-light-grey">{{ shortDate(record.recorded_on) }}</span>
                            <span
                                class="ml-1 rounded-full px-2 py-0.5"
                                :class="sourceBadgeClass[record.source]"
                                >{{ record.source }}</span
                            >
                            <span class="ml-1">{{ record.body }}</span>
                        </li>
                    </ul>

                    <div class="flex flex-wrap items-end gap-2">
                        <div>
                            <label class="block text-xs font-medium text-fg-mid-grey">Stock class</label>
                            <select
                                v-model="proposal.stock_class_id"
                                class="rounded border border-fg-muted-grey px-2 py-1 text-sm"
                            >
                                <option v-for="c in classes" :key="c.id" :value="c.id">{{ c.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-fg-mid-grey">Type</label>
                            <select
                                v-model="proposal.type"
                                class="rounded border border-fg-muted-grey px-2 py-1 text-sm"
                            >
                                <option value="birth">Birth</option>
                                <option value="purchase">Purchase</option>
                                <option value="death">Death</option>
                                <option value="sale">Sale</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-fg-mid-grey">Quantity</label>
                            <input
                                v-model.number="proposal.quantity"
                                type="number"
                                min="1"
                                class="w-24 rounded border border-fg-muted-grey px-2 py-1 text-right text-sm"
                            />
                        </div>
                        <div class="grow">
                            <label class="block text-xs font-medium text-fg-mid-grey">Note (source record)</label>
                            <input
                                v-model="proposal.note"
                                class="w-full rounded border border-fg-muted-grey px-2 py-1 text-sm"
                            />
                        </div>
                        <button
                            class="rounded bg-fg-main-blue px-4 py-1.5 text-sm font-medium text-white hover:bg-fg-main-blue-hover disabled:opacity-50"
                            :disabled="applyingUid === proposal.uid || !proposal.quantity"
                            @click="applyProposal(proposal)"
                        >
                            {{ applyingUid === proposal.uid ? 'Applying…' : 'Apply' }}
                        </button>
                    </div>

                    <label class="mt-2 flex items-center gap-2 text-xs text-fg-mid-grey">
                        <input v-model="proposal.forReport" type="checkbox" />
                        Add to client report
                    </label>
                </div>
            </div>

            <!-- Records Claude could not turn into a movement at all -->
            <div v-if="parseResult.unresolved.length" class="rounded border border-fg-muted-grey bg-white">
                <h4 class="border-b border-fg-pale-grey px-4 py-2 text-sm font-semibold">
                    Unresolved ({{ parseResult.unresolved.length }})
                </h4>
                <ul>
                    <li
                        v-for="(item, i) in parseResult.unresolved"
                        :key="i"
                        class="border-b border-fg-pale-grey px-4 py-2 text-sm last:border-b-0"
                    >
                        <p v-for="record in sourceRecords(item)" :key="record.id" class="text-xs text-fg-light-grey">
                            {{ shortDate(record.recorded_on) }} · {{ record.body }}
                        </p>
                        <p class="mt-0.5 text-fg-mid-grey">{{ item.reason }}</p>
                    </li>
                </ul>
            </div>

            <details>
                <summary class="cursor-pointer text-xs text-fg-light-grey">Raw JSON</summary>
                <pre class="mt-1 overflow-x-auto rounded bg-fg-super-pale-grey p-3 text-xs leading-relaxed">{{ parseJson }}</pre>
            </details>
        </div>

        <section v-if="parseResult" class="mt-4 rounded border border-fg-muted-grey bg-white p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="text-sm font-semibold">AI reconciliation report</h3>
                    <p class="text-xs text-fg-mid-grey">
                        Generate a report directly from the parsed proposals. Confirmed proposals are included in the
                        calculations; excluded and unresolved records are listed for review.
                    </p>
                </div>
                <button
                    class="rounded bg-fg-main-blue px-4 py-1.5 text-sm font-medium text-white hover:bg-fg-main-blue-hover disabled:opacity-50"
                    :disabled="reportLoading"
                    @click="generateReport"
                >
                    {{ reportLoading ? 'Generating report…' : 'Generate reconciliation report' }}
                </button>
            </div>

            <p v-if="reportError" class="mt-3 rounded bg-fg-danger-9 p-3 text-sm text-fg-danger-dark">
                {{ reportError }}
            </p>

            <div v-if="reportData" class="mt-4 space-y-4">
                <div class="flex flex-wrap gap-2 text-xs">
                    <span class="rounded-full bg-fg-positive-15 px-2.5 py-1 text-fg-positive-dark">
                        {{ reportData.counts.added_to_report }} confirmed proposal(s) added
                    </span>
                    <span class="rounded-full bg-fg-warning-15 px-2.5 py-1 text-fg-warning-text">
                        {{ reportData.counts.needs_review }} proposal(s) need review
                    </span>
                    <span class="rounded-full bg-fg-super-pale-grey px-2.5 py-1 text-fg-mid-grey">
                        {{ reportData.counts.already_keyed }} already keyed
                    </span>
                </div>

                <div v-if="reportData.review_proposals.length || reportData.unresolved.length">
                    <h4 class="mb-2 text-sm font-semibold">Needs review</h4>
                    <ul class="space-y-2">
                        <li
                            v-for="proposal in reportData.review_proposals"
                            :key="proposal.record_ids.join('-')"
                            class="rounded bg-fg-warning-15 p-3 text-xs"
                        >
                            <p class="font-medium">
                                Records {{ proposal.record_ids.join(', ') || 'not supplied' }}:
                                {{ proposal.stock_class }} {{ proposal.type }} × {{ proposal.quantity }}
                            </p>
                            <p class="mt-1 text-fg-mid-grey">
                                Confidence: {{ proposal.confidence ?? 'not supplied' }} · Flag: {{ proposal.flag ?? 'none' }}
                            </p>
                            <p class="mt-1">{{ proposal.reasoning || proposal.note }}</p>
                        </li>
                        <li
                            v-for="item in reportData.unresolved"
                            :key="`unresolved-${item.record_ids.join('-')}`"
                            class="rounded bg-fg-warning-15 p-3 text-xs"
                        >
                            <p class="font-medium">
                                Records {{ item.record_ids.join(', ') || 'not supplied' }}: unresolved
                            </p>
                            <p class="mt-1">{{ item.reason }}</p>
                        </li>
                    </ul>
                </div>

                <div v-if="reportText">
                    <h4 class="mb-2 text-sm font-semibold">Generated report</h4>
                    <pre class="whitespace-pre-wrap rounded bg-fg-super-pale-grey p-3 font-sans text-sm leading-relaxed">{{ reportText }}</pre>
                </div>
            </div>
        </section>
    </div>
</template>
