<script setup>
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import { money, shortDate } from '../format';

const categories = ref([]);
const transactions = ref([]);
const loading = ref(true);
const savingId = ref(null);

const uncodedCount = computed(() => transactions.value.filter((t) => !t.category_id).length);

onMounted(async () => {
    const { data } = await axios.get('/api/bank-transactions');
    categories.value = data.categories;
    transactions.value = data.transactions;
    loading.value = false;
});

async function saveCategory(transaction) {
    savingId.value = transaction.id;
    await axios.patch(`/api/bank-transactions/${transaction.id}`, {
        category_id: transaction.category_id,
    });
    savingId.value = null;
}
</script>

<template>
    <div>
        <div class="mb-4 flex items-end justify-between">
            <div>
                <h2 class="text-lg font-semibold">Bank coding</h2>
                <p class="text-sm text-fg-mid-grey">
                    Code each bank feed line to an account. Last period (Dec–Jan) was coded by the senior adviser;
                    everything since is yours.
                </p>
            </div>
            <p class="rounded-full bg-fg-warning-15 px-2.5 py-1 text-xs font-medium whitespace-nowrap text-fg-warning-text">
                {{ uncodedCount }} still to code
            </p>
        </div>

        <p v-if="loading" class="text-fg-light-grey">Loading…</p>

        <div v-else class="overflow-x-auto rounded border border-fg-muted-grey bg-white">
            <table class="w-full text-sm">
                <thead class="bg-fg-super-pale-grey text-left text-fg-mid-grey">
                    <tr>
                        <th class="px-3 py-2 font-medium">Date</th>
                        <th class="px-3 py-2 font-medium">Description</th>
                        <th class="px-3 py-2 text-right font-medium">Amount</th>
                        <th class="px-3 py-2 font-medium">Category</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="txn in transactions"
                        :key="txn.id"
                        class="border-t border-fg-pale-grey hover:bg-fg-pale-grey"
                        :class="txn.category_id ? 'bg-fg-main-blue-9' : ''"
                    >
                        <td class="whitespace-nowrap px-3 py-1.5 text-fg-mid-grey">{{ shortDate(txn.transacted_on) }}</td>
                        <td class="px-3 py-1.5 font-mono text-xs">{{ txn.description }}</td>
                        <td
                            class="whitespace-nowrap px-3 py-1.5 text-right font-mono text-xs"
                            :class="txn.amount < 0 ? 'text-fg-danger' : ''"
                        >
                            {{ money(txn.amount) }}
                        </td>
                        <td class="px-3 py-1.5">
                            <select
                                v-model="txn.category_id"
                                class="w-48 rounded border border-fg-muted-grey bg-white px-2 py-1 text-sm"
                                :disabled="savingId === txn.id"
                                @change="saveCategory(txn)"
                            >
                                <option :value="null">— uncoded —</option>
                                <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                            </select>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
