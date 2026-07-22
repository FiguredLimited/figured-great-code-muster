<script setup>
import axios from 'axios';
import { onMounted, ref } from 'vue';

const emails = ref([]);
const selected = ref(null);
const draft = ref('');
const sending = ref(false);
const loading = ref(true);

onMounted(async () => {
    const { data } = await axios.get('/api/emails');
    emails.value = data;
    loading.value = false;
});

function open(email) {
    selected.value = email;
    draft.value = '';
}

async function sendReply() {
    if (!draft.value.trim()) return;
    sending.value = true;
    const { data } = await axios.post(`/api/emails/${selected.value.id}/reply`, {
        body: draft.value,
    });
    // Update the list with the saved reply.
    Object.assign(selected.value, data);
    draft.value = '';
    sending.value = false;
}

function formatDateTime(iso) {
    return new Date(iso).toLocaleString('en-NZ', {
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
}
</script>

<template>
    <div>
        <div class="mb-4">
            <h2 class="text-lg font-semibold">Inbox</h2>
            <p class="text-sm text-fg-mid-grey">
                Client emails waiting on a reply. Nothing actually sends — replies just get saved. One is already
                answered as an example.
            </p>
        </div>

        <p v-if="loading" class="text-fg-light-grey">Loading…</p>

        <div v-else class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <!-- Email list -->
            <div class="overflow-hidden rounded border border-fg-muted-grey bg-white">
                <button
                    v-for="email in emails"
                    :key="email.id"
                    class="block w-full border-b border-fg-pale-grey px-3 py-2 text-left hover:bg-fg-pale-grey"
                    :class="selected?.id === email.id ? 'bg-fg-main-blue-9' : ''"
                    @click="open(email)"
                >
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium">{{ email.from_name }}</span>
                        <span
                            v-if="email.replied_at"
                            class="rounded-full bg-fg-positive-15 px-2 py-0.5 text-xs text-fg-positive-dark"
                        >
                            replied
                        </span>
                    </div>
                    <p class="truncate text-sm text-fg-dark-grey">{{ email.subject }}</p>
                    <p class="text-xs text-fg-light-grey">{{ formatDateTime(email.received_at) }}</p>
                </button>
            </div>

            <!-- Reading pane -->
            <div class="md:col-span-2">
                <p v-if="!selected" class="rounded border border-dashed border-fg-muted-grey p-8 text-center text-fg-light-grey">
                    Select an email to read it.
                </p>

                <div v-else class="space-y-4">
                    <div class="rounded border border-fg-muted-grey bg-white p-4">
                        <p class="text-sm text-fg-light-grey">
                            From <span class="font-medium text-fg-dark-grey">{{ selected.from_name }}</span>
                            &lt;{{ selected.from_email }}&gt; — {{ formatDateTime(selected.received_at) }}
                        </p>
                        <h3 class="mt-1 font-semibold">{{ selected.subject }}</h3>
                        <p class="mt-3 whitespace-pre-wrap text-sm leading-relaxed">{{ selected.body }}</p>
                    </div>

                    <div v-if="selected.reply_body" class="rounded border border-fg-main-blue-30 bg-fg-main-blue-9 p-4">
                        <p class="text-sm font-medium text-fg-dark-blue">
                            Your reply — sent {{ formatDateTime(selected.replied_at) }}
                        </p>
                        <p class="mt-2 whitespace-pre-wrap text-sm leading-relaxed">{{ selected.reply_body }}</p>
                    </div>

                    <div class="rounded border border-fg-muted-grey bg-white p-4">
                        <label class="mb-1 block text-sm font-medium">
                            {{ selected.reply_body ? 'Send a new reply (replaces the old one)' : 'Reply' }}
                        </label>
                        <textarea
                            v-model="draft"
                            rows="6"
                            class="w-full rounded border border-fg-muted-grey p-2 text-sm"
                            placeholder="Write your reply…"
                        ></textarea>
                        <button
                            class="mt-2 rounded bg-fg-main-blue px-4 py-1.5 text-sm font-medium text-white hover:bg-fg-main-blue-hover disabled:opacity-50"
                            :disabled="sending || !draft.trim()"
                            @click="sendReply"
                        >
                            {{ sending ? 'Sending…' : 'Send' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
