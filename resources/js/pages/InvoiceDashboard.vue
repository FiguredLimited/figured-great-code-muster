<script setup>
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import { money, shortDate } from '../format';

const invoices = ref([]);
const categories = ref([]);
const loading = ref(true);
const expandedId = ref(null);

onMounted(async () => {
    const { data } = await axios.get('/api/invoices');
    invoices.value = data.invoices;
    categories.value = data.categories;
    loading.value = false;
});

function categoryName(id) {
    return categories.value.find(c => c.id === id)?.name ?? '—';
}

function togglePreview(id) {
    expandedId.value = expandedId.value === id ? null : id;
}

const entered = computed(() => invoices.value.filter(i => i.entered_at));
const pending = computed(() => invoices.value.filter(i => !i.entered_at));
const totalValue = computed(() => entered.value.reduce((sum, i) => sum + (i.total || 0), 0));

const filterType = ref('all'); // 'all', 'invoices', 'credits'

const filteredEntered = computed(() => {
    if (filterType.value === 'invoices') return entered.value.filter(i => i.total >= 0);
    if (filterType.value === 'credits') return entered.value.filter(i => i.total < 0);
    return entered.value;
});

const byCategory = computed(() => {
    const groups = {};
    entered.value.forEach(inv => {
        const name = categoryName(inv.category_id);
        if (!groups[name]) groups[name] = { name, count: 0, total: 0 };
        groups[name].count++;
        groups[name].total += inv.total || 0;
    });
    return Object.values(groups).sort((a, b) => Math.abs(b.total) - Math.abs(a.total));
});

// Wow Factor 3: Cashflow Forecasting
const upcomingPayables = computed(() => {
    return entered.value
        .filter(i => i.due_date && i.total > 0)
        .sort((a, b) => new Date(a.due_date) - new Date(b.due_date));
});

// Wow Factor 5: Inflation / Price Variance
const inflationFlags = computed(() => {
    const flags = [];
    entered.value.forEach(inv => {
        inv.lines.forEach(line => {
            if (!line.unit_price || !line.description) return;
            // Look for an older invoice with same description and lower price
            for (const pastInv of entered.value) {
                if (new Date(pastInv.invoice_date || pastInv.entered_at) >= new Date(inv.invoice_date || inv.entered_at)) continue;
                for (const pastLine of pastInv.lines) {
                    if (pastLine.unit_price && pastLine.description.toLowerCase() === line.description.toLowerCase()) {
                        if (line.unit_price > pastLine.unit_price) {
                            const diff = line.unit_price - pastLine.unit_price;
                            const pct = Math.round((diff / pastLine.unit_price) * 100);
                            flags.push({
                                currentInv: inv,
                                line,
                                pastInv,
                                pastLine,
                                pct
                            });
                        }
                    }
                }
            }
        });
    });
    // Deduplicate by current line ID
    return flags.filter((v, i, a) => a.findIndex(t => t.line.id === v.line.id) === i);
});
</script>

<template>
    <div>
        <div class="mb-6">
            <h2 class="text-lg font-semibold">Invoice dashboard</h2>
            <p class="text-sm text-fg-mid-grey">
                Overview of all invoices processed through AI-assisted parsing.
                Powered by <code class="rounded bg-fg-pale-grey px-1">Claude Sonnet 4.6</code> via
                <code class="rounded bg-fg-pale-grey px-1">POST /api/ai</code>.
            </p>
        </div>

        <p v-if="loading" class="flex items-center gap-2 text-fg-light-grey">
            <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25" />
                <path d="M4 12a8 8 0 018-8" stroke="currentColor" stroke-width="3" stroke-linecap="round" class="opacity-75" />
            </svg>
            Loading invoices…
        </p>

        <template v-else>
            <!-- Summary cards -->
            <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="rounded-lg border border-fg-muted-grey bg-white p-4 text-center">
                    <p class="text-2xl font-bold text-fg-dark-grey">{{ invoices.length }}</p>
                    <p class="text-xs text-fg-mid-grey">Total invoices</p>
                </div>
                <div class="rounded-lg border border-fg-muted-grey bg-white p-4 text-center">
                    <p class="text-2xl font-bold text-fg-positive-dark">{{ entered.length }}</p>
                    <p class="text-xs text-fg-mid-grey">Entered</p>
                </div>
                <div class="rounded-lg border border-fg-muted-grey bg-white p-4 text-center">
                    <p class="text-2xl font-bold" :class="pending.length > 0 ? 'text-fg-warning-text' : 'text-fg-positive-dark'">{{ pending.length }}</p>
                    <p class="text-xs text-fg-mid-grey">Pending</p>
                </div>
                <div class="rounded-lg border border-fg-muted-grey bg-white p-4 text-center">
                    <p class="text-2xl font-bold text-fg-main-blue">{{ money(totalValue) }}</p>
                    <p class="text-xs text-fg-mid-grey">Total value (incl. GST)</p>
                </div>
            </div>

            <!-- Entered invoices table -->
            <div v-if="entered.length > 0" class="mb-6">
                <div class="mb-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <h3 class="text-sm font-semibold text-fg-dark-grey">Entered invoices</h3>
                    
                    <!-- Filter buttons -->
                    <div class="inline-flex rounded-md shadow-sm" role="group">
                        <button
                            type="button"
                            class="px-4 py-1.5 text-xs font-medium rounded-l-lg border border-fg-muted-grey"
                            :class="filterType === 'all' ? 'bg-fg-dark-grey text-white border-fg-dark-grey z-10' : 'bg-white text-fg-dark-grey hover:bg-fg-pale-grey'"
                            @click="filterType = 'all'"
                        >
                            All
                        </button>
                        <button
                            type="button"
                            class="px-4 py-1.5 text-xs font-medium border-y border-r border-fg-muted-grey"
                            :class="filterType === 'invoices' ? 'bg-fg-dark-grey text-white border-fg-dark-grey z-10' : 'bg-white text-fg-dark-grey hover:bg-fg-pale-grey'"
                            @click="filterType = 'invoices'"
                        >
                            Invoices Only
                        </button>
                        <button
                            type="button"
                            class="px-4 py-1.5 text-xs font-medium rounded-r-lg border-y border-r border-fg-muted-grey"
                            :class="filterType === 'credits' ? 'bg-fg-dark-grey text-white border-fg-dark-grey z-10' : 'bg-white text-fg-dark-grey hover:bg-fg-pale-grey'"
                            @click="filterType = 'credits'"
                        >
                            Credit Notes/Refunds
                        </button>
                    </div>
                </div>

                <div class="overflow-hidden rounded-lg border border-fg-muted-grey bg-white">
                    <table class="w-full text-sm">
                        <thead class="border-b border-fg-pale-grey bg-fg-super-pale-grey text-left text-xs font-medium text-fg-mid-grey">
                            <tr>
                                <th class="px-4 py-2.5">File</th>
                                <th class="px-4 py-2.5">Supplier</th>
                                <th class="px-4 py-2.5">Date</th>
                                <th class="px-4 py-2.5">Category</th>
                                <th class="px-4 py-2.5">Lines</th>
                                <th class="px-4 py-2.5 text-right">Total (incl. GST)</th>
                                <th class="px-4 py-2.5 text-center">Preview</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="filteredEntered.length === 0">
                                <td colspan="7" class="px-4 py-6 text-center text-sm text-fg-mid-grey">
                                    No records found for this filter.
                                </td>
                            </tr>
                            <template v-for="inv in filteredEntered" :key="inv.id">
                                <tr
                                    class="border-b border-fg-pale-grey last:border-0 hover:bg-fg-pale-grey cursor-pointer"
                                    @click="togglePreview(inv.id)"
                                >
                                    <td class="px-4 py-2.5 font-mono text-xs">{{ inv.filename }}</td>
                                    <td class="px-4 py-2.5 font-medium">{{ inv.supplier }}</td>
                                    <td class="px-4 py-2.5 text-fg-mid-grey">{{ shortDate(inv.invoice_date) }}</td>
                                    <td class="px-4 py-2.5">
                                        <span class="inline-block rounded-full bg-fg-main-blue-9 px-2.5 py-0.5 text-xs font-medium text-fg-main-blue">
                                            {{ categoryName(inv.category_id) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-2.5 text-center text-fg-mid-grey">{{ inv.lines?.length || 0 }}</td>
                                    <td class="px-4 py-2.5 text-right font-mono" :class="inv.total < 0 ? 'text-fg-danger-dark' : ''">
                                        {{ money(inv.total) }}
                                    </td>
                                    <td class="px-4 py-2.5 text-center">
                                        <span class="text-xs text-fg-main-blue">{{ expandedId === inv.id ? 'Hide' : 'View' }}</span>
                                    </td>
                                </tr>
                                <!-- Expanded preview row -->
                                <tr v-if="expandedId === inv.id">
                                    <td colspan="7" class="border-b border-fg-pale-grey bg-fg-super-pale-grey px-4 py-3">
                                        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                                            <!-- Original invoice text -->
                                            <div>
                                                <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-fg-mid-grey">Original invoice</p>
                                                <pre class="max-h-64 overflow-auto rounded border border-fg-muted-grey bg-white p-3 font-mono text-xs leading-relaxed">{{ inv.raw_text }}</pre>
                                            </div>
                                            <!-- Entered data summary -->
                                            <div>
                                                <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-fg-mid-grey">Entered data</p>
                                                <div class="rounded border border-fg-muted-grey bg-white p-3 text-xs">
                                                    <div class="mb-2 grid grid-cols-2 gap-x-4 gap-y-1">
                                                        <span class="text-fg-mid-grey">Supplier</span>
                                                        <span class="font-medium">{{ inv.supplier }}</span>
                                                        <span class="text-fg-mid-grey">Date</span>
                                                        <span>{{ shortDate(inv.invoice_date) }}</span>
                                                        <span class="text-fg-mid-grey">Category</span>
                                                        <span>{{ categoryName(inv.category_id) }}</span>
                                                    </div>
                                                    <hr class="my-2 border-fg-pale-grey" />
                                                    <p class="mb-1 font-medium text-fg-mid-grey">Line items (excl. GST)</p>
                                                    <div
                                                        v-for="line in inv.lines"
                                                        :key="line.id"
                                                        class="flex justify-between py-0.5"
                                                    >
                                                        <span class="text-fg-dark-grey">{{ line.description }}</span>
                                                        <span class="font-mono" :class="line.amount < 0 ? 'text-fg-danger-dark' : ''">{{ money(line.amount) }}</span>
                                                    </div>
                                                    <hr class="my-2 border-fg-pale-grey" />
                                                    <div class="flex justify-between font-semibold">
                                                        <span>Total (incl. GST)</span>
                                                        <span class="font-mono" :class="inv.total < 0 ? 'text-fg-danger-dark' : ''">{{ money(inv.total) }}</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                        <tfoot class="border-t border-fg-muted-grey bg-fg-super-pale-grey">
                            <tr>
                                <td colspan="6" class="px-4 py-2.5 text-xs font-semibold text-fg-mid-grey">Total</td>
                                <td class="px-4 py-2.5 text-right font-mono font-semibold">{{ money(totalValue) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Category breakdown -->
            <div v-if="byCategory.length > 0" class="mb-6">
                <h3 class="mb-2 text-sm font-semibold text-fg-dark-grey">By category</h3>
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    <div
                        v-for="cat in byCategory"
                        :key="cat.name"
                        class="flex items-center justify-between rounded-lg border border-fg-muted-grey bg-white px-4 py-3"
                    >
                        <div>
                            <p class="text-sm font-medium">{{ cat.name }}</p>
                            <p class="text-xs text-fg-light-grey">{{ cat.count }} invoice{{ cat.count !== 1 ? 's' : '' }}</p>
                        </div>
                        <p class="font-mono text-sm font-semibold" :class="cat.total < 0 ? 'text-fg-danger-dark' : 'text-fg-dark-grey'">
                            {{ money(cat.total) }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- Wow Factor 5: Inflation Tracking -->
            <div v-if="inflationFlags.length > 0" class="mb-6">
                <h3 class="mb-2 text-sm font-semibold text-fg-dark-grey">📈 Inflation & Price Variance</h3>
                <div class="overflow-hidden rounded-lg border border-fg-danger/20 bg-white">
                    <table class="w-full text-sm">
                        <thead class="border-b border-fg-danger/20 bg-fg-danger-9 text-left text-xs font-medium text-fg-danger-dark">
                            <tr>
                                <th class="px-4 py-2.5">Item Description</th>
                                <th class="px-4 py-2.5">Supplier</th>
                                <th class="px-4 py-2.5 text-right">Previous Price</th>
                                <th class="px-4 py-2.5 text-right">Current Price</th>
                                <th class="px-4 py-2.5 text-right">Variance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="flag in inflationFlags" :key="'inflation-' + flag.line.id" class="border-b border-fg-pale-grey last:border-0 hover:bg-fg-pale-grey">
                                <td class="px-4 py-2.5 font-medium">{{ flag.line.description }}</td>
                                <td class="px-4 py-2.5 text-xs">{{ flag.currentInv.supplier }}</td>
                                <td class="px-4 py-2.5 text-right font-mono text-xs text-fg-mid-grey">
                                    {{ money(flag.pastLine.unit_price) }}
                                    <span class="block text-[10px]">on {{ shortDate(flag.pastInv.invoice_date) }}</span>
                                </td>
                                <td class="px-4 py-2.5 text-right font-mono text-xs font-semibold text-fg-dark-grey">
                                    {{ money(flag.line.unit_price) }}
                                    <span class="block text-[10px]">on {{ shortDate(flag.currentInv.invoice_date) }}</span>
                                </td>
                                <td class="px-4 py-2.5 text-right font-mono text-xs font-bold text-fg-danger-dark">
                                    +{{ flag.pct }}%
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Wow Factor 3: Cashflow Forecasting -->
            <div v-if="upcomingPayables.length > 0" class="mb-6">
                <h3 class="mb-2 text-sm font-semibold text-fg-dark-grey">🗓️ Cashflow Forecasting: Upcoming Payables</h3>
                <div class="overflow-hidden rounded-lg border border-fg-muted-grey bg-white p-4">
                    <div class="relative border-l-2 border-fg-pale-grey ml-3 space-y-6">
                        <div v-for="inv in upcomingPayables" :key="'payable-'+inv.id" class="relative pl-6">
                            <!-- Timeline dot -->
                            <div class="absolute -left-1.5 mt-1.5 h-3 w-3 rounded-full bg-fg-main-blue ring-4 ring-white"></div>
                            
                            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1 sm:gap-4">
                                <div>
                                    <p class="text-sm font-bold text-fg-dark-grey">{{ shortDate(inv.due_date) }}</p>
                                    <p class="text-xs text-fg-mid-grey">Due to <span class="font-medium text-fg-dark-grey">{{ inv.supplier }}</span></p>
                                </div>
                                <div class="font-mono text-sm font-bold text-fg-main-blue">
                                    {{ money(inv.total) }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pending invoices -->
            <div v-if="pending.length > 0">
                <h3 class="mb-2 text-sm font-semibold text-fg-dark-grey">Pending — not yet entered</h3>
                <div class="overflow-hidden rounded-lg border border-fg-muted-grey bg-white">
                    <div
                        v-for="inv in pending"
                        :key="inv.id"
                        class="flex items-center justify-between border-b border-fg-pale-grey px-4 py-2.5 last:border-0"
                    >
                        <span class="font-mono text-xs text-fg-mid-grey">{{ inv.filename }}</span>
                        <span class="rounded-full bg-fg-warning-bg px-2.5 py-0.5 text-xs font-medium text-fg-warning-text">
                            pending
                        </span>
                    </div>
                </div>
            </div>

            <!-- All done state -->
            <div v-if="pending.length === 0" class="rounded-lg border border-fg-positive-dark/20 bg-fg-positive-15 p-6 text-center">
                <p class="text-lg font-semibold text-fg-positive-dark">All invoices entered</p>
                <p class="text-sm text-fg-positive-dark/80">Every invoice has been parsed and saved successfully.</p>
            </div>
        </template>
    </div>
</template>
