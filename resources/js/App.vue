<script setup>
import { ref } from 'vue';
import Briefing from './pages/Briefing.vue';
import BankCoding from './pages/BankCoding.vue';
import Inbox from './pages/Inbox.vue';
import MonthlyReport from './pages/MonthlyReport.vue';
import InvoiceEntry from './pages/InvoiceEntry.vue';
import StockReconciliation from './pages/StockReconciliation.vue';
import AiExample from './pages/AiExample.vue';

// Plain tab navigation - no router needed for five pages.
const tabs = [
    { key: 'briefing', label: 'Briefing', component: Briefing },
    { key: 'bank', label: 'Bank coding', component: BankCoding },
    { key: 'inbox', label: 'Inbox', component: Inbox },
    { key: 'report', label: 'Monthly report', component: MonthlyReport },
    { key: 'invoices', label: 'Invoice entry', component: InvoiceEntry },
    { key: 'stock', label: 'Stock reconciliation', component: StockReconciliation },
    { key: 'ai', label: 'AI example', component: AiExample },
];

// Remember the open tab in the URL hash so refreshing keeps your place.
const activeTab = ref(tabs.find((t) => t.key === window.location.hash.slice(1)) ?? tabs[0]);

function openTab(tab) {
    activeTab.value = tab;
    window.location.hash = tab.key;
}
</script>

<template>
    <div class="min-h-screen">
        <header class="bg-fg-dark-blue text-white">
            <div class="mx-auto flex max-w-7xl items-center gap-4 px-4 py-4">
                <img :src="'/img/figured-logo-white.svg'" alt="Figured" class="h-9 shrink-0" />
                <div class="border-l border-white/20 pl-4">
                    <h1 class="text-xl font-semibold">Southdown Rural Accountants</h1>
                    <p class="text-sm text-fg-dark-blue-30">The Figured Great Code Muster — practice management, the manual way</p>
                </div>
            </div>
        </header>

        <nav class="border-b border-fg-muted-grey bg-white">
            <div class="mx-auto flex max-w-7xl gap-1 overflow-x-auto px-4">
                <button
                    v-for="tab in tabs"
                    :key="tab.key"
                    class="whitespace-nowrap border-b-2 px-3 py-3 text-sm font-medium"
                    :class="
                        activeTab.key === tab.key
                            ? 'border-fg-main-blue text-fg-main-blue'
                            : 'border-transparent text-fg-mid-grey hover:text-fg-dark-grey'
                    "
                    @click="openTab(tab)"
                >
                    {{ tab.label }}
                </button>
            </div>
        </nav>

        <main class="mx-auto max-w-7xl px-4 py-6">
            <!-- keep-alive so switching tabs doesn't lose in-progress work -->
            <KeepAlive>
                <component :is="activeTab.component" />
            </KeepAlive>
        </main>
    </div>
</template>
