<script setup>
import axios from 'axios';
import { computed, onMounted, ref, watch } from 'vue';
import { money, monthName } from '../format';

const farms = ref([]);
const selectedFarmId = ref(null);
const lines = ref([]);
const commentaries = ref([]);
const weather = ref([]);
const months = ref([]);
const selectedMonth = ref(null);
const commentaryDraft = ref('');
const saving = ref(false);
const savedAt = ref(null);
const loading = ref(true);

onMounted(async () => {
    const { data } = await axios.get('/api/farms');
    farms.value = data;
    selectedFarmId.value = data[0]?.id ?? null;
});

watch(selectedFarmId, loadReport);
watch(selectedMonth, loadCommentaryDraft);

async function loadReport() {
    loading.value = true;
    const { data } = await axios.get(`/api/farms/${selectedFarmId.value}/report`);
    lines.value = data.lines;
    commentaries.value = data.commentaries;
    weather.value = data.weather;
    months.value = [...new Set(data.lines.map((l) => l.month))];
    // Keep the selected month if the new farm has it; otherwise start at the first.
    if (!months.value.includes(selectedMonth.value)) {
        selectedMonth.value = months.value[0];
    } else {
        loadCommentaryDraft();
    }
    loading.value = false;
}

function loadCommentaryDraft() {
    const existing = commentaries.value.find((c) => c.month === selectedMonth.value);
    commentaryDraft.value = existing?.body ?? '';
    savedAt.value = null;
}

const monthLines = computed(() => lines.value.filter((l) => l.month === selectedMonth.value));

const monthWeather = computed(() => weather.value.find((w) => w.month === selectedMonth.value));

function variance(line) {
    return line.actual - line.budget;
}

function variancePercent(line) {
    if (line.budget === 0) return null;
    return ((line.actual - line.budget) / line.budget) * 100;
}

async function saveCommentary() {
    saving.value = true;
    const { data } = await axios.put(`/api/farms/${selectedFarmId.value}/commentary`, {
        month: selectedMonth.value,
        body: commentaryDraft.value,
    });
    // Keep the local cache in sync so switching months and back shows the save.
    const index = commentaries.value.findIndex((c) => c.month === selectedMonth.value);
    if (index >= 0) commentaries.value[index] = data;
    else commentaries.value.push(data);
    saving.value = false;
    savedAt.value = new Date();
}
</script>

<template>
    <div>
        <div class="mb-4">
            <h2 class="text-lg font-semibold">Monthly report</h2>
            <p class="text-sm text-fg-mid-grey">
                Budget vs actual by month. Write commentary for each farm and month — June 2025 for Riverbend is done
                as an example.
            </p>
        </div>

        <div class="mb-4 flex flex-wrap gap-3">
            <select v-model="selectedFarmId" class="rounded border border-fg-muted-grey bg-white px-3 py-1.5 text-sm">
                <option v-for="farm in farms" :key="farm.id" :value="farm.id">
                    {{ farm.name }} ({{ farm.type }} — {{ farm.owner_name }})
                </option>
            </select>
            <select v-model="selectedMonth" class="rounded border border-fg-muted-grey bg-white px-3 py-1.5 text-sm">
                <option v-for="month in months" :key="month" :value="month">{{ monthName(month) }}</option>
            </select>
        </div>

        <p v-if="loading" class="text-fg-light-grey">Loading…</p>

        <div v-else class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <div class="overflow-x-auto rounded border border-fg-muted-grey bg-white lg:col-span-2">
                <table class="w-full text-sm">
                    <thead class="bg-fg-super-pale-grey text-left text-fg-mid-grey">
                        <tr>
                            <th class="px-3 py-2 font-medium">Category</th>
                            <th class="px-3 py-2 text-right font-medium">Budget</th>
                            <th class="px-3 py-2 text-right font-medium">Actual</th>
                            <th class="px-3 py-2 text-right font-medium">Variance</th>
                            <th class="px-3 py-2 text-right font-medium">%</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="line in monthLines" :key="line.id" class="border-t border-fg-pale-grey hover:bg-fg-pale-grey">
                            <td class="px-3 py-1.5">{{ line.category }}</td>
                            <td class="px-3 py-1.5 text-right font-mono text-xs">{{ money(line.budget) }}</td>
                            <td class="px-3 py-1.5 text-right font-mono text-xs">{{ money(line.actual) }}</td>
                            <td
                                class="px-3 py-1.5 text-right font-mono text-xs"
                                :class="Math.abs(variance(line)) > line.budget * 0.1 && line.budget > 0 ? 'font-semibold text-fg-warning-text' : ''"
                            >
                                {{ money(variance(line)) }}
                            </td>
                            <td class="px-3 py-1.5 text-right font-mono text-xs text-fg-light-grey">
                                {{ variancePercent(line) === null ? '—' : variancePercent(line).toFixed(1) + '%' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="space-y-4">
            <div class="rounded border border-fg-muted-grey bg-white p-4">
                <label class="mb-1 block text-sm font-medium">Commentary — {{ monthName(selectedMonth) }}</label>
                <p class="mb-2 text-xs text-fg-light-grey">
                    What happened this month, and does anything need following up with the farmer?
                </p>
                <textarea
                    v-model="commentaryDraft"
                    rows="12"
                    class="w-full rounded border border-fg-muted-grey p-2 text-sm"
                    placeholder="Write the month's commentary…"
                ></textarea>
                <div class="mt-2 flex items-center gap-3">
                    <button
                        class="rounded bg-fg-main-blue px-4 py-1.5 text-sm font-medium text-white hover:bg-fg-main-blue-hover disabled:opacity-50"
                        :disabled="saving || !commentaryDraft.trim()"
                        @click="saveCommentary"
                    >
                        {{ saving ? 'Saving…' : 'Save commentary' }}
                    </button>
                    <span v-if="savedAt" class="text-xs text-fg-positive-dark">Saved ✓</span>
                </div>
            </div>

            <!-- Regional climate summary for the selected month -->
            <div v-if="monthWeather" class="rounded border border-fg-muted-grey bg-white p-4">
                <h3 class="text-sm font-semibold">Regional conditions — {{ monthName(selectedMonth) }}</h3>
                <dl class="mt-2 grid grid-cols-3 gap-2 text-center">
                    <div class="rounded bg-fg-super-pale-grey p-2">
                        <dt class="text-xs text-fg-light-grey">Rainfall</dt>
                        <dd class="text-sm font-medium">
                            {{ monthWeather.rainfall_mm }} mm
                            <span class="block text-xs font-normal text-fg-light-grey">
                                {{ monthWeather.rainfall_pct_normal }}% of normal
                            </span>
                        </dd>
                    </div>
                    <div class="rounded bg-fg-super-pale-grey p-2">
                        <dt class="text-xs text-fg-light-grey">Mean temp</dt>
                        <dd class="text-sm font-medium">{{ monthWeather.mean_temp_c }}°C</dd>
                    </div>
                    <div class="rounded bg-fg-super-pale-grey p-2">
                        <dt class="text-xs text-fg-light-grey">Soil moisture deficit</dt>
                        <dd class="text-sm font-medium">{{ monthWeather.soil_moisture_deficit_mm }} mm</dd>
                    </div>
                </dl>
                <p class="mt-2 text-xs leading-relaxed text-fg-mid-grey">{{ monthWeather.notes }}</p>
            </div>
            </div>
        </div>
    </div>
</template>
