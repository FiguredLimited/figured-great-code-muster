<script setup>
/*
 * The worked example for calling the AI proxy. Copy this pattern into any
 * page you want to automate:
 *
 *   const { data } = await axios.post('/api/ai', { system, prompt });
 *   data.text  // Claude's reply
 *
 * The backend adds the API key - frontend code never sees it.
 */
import axios from 'axios';
import { ref } from 'vue';

const system = ref('You are a helpful assistant at a rural accounting firm in the Waikato. Be brief.');
const prompt = ref('');
const responseText = ref('');
const error = ref('');
const loading = ref(false);

async function ask() {
    loading.value = true;
    error.value = '';
    responseText.value = '';
    try {
        const { data } = await axios.post('/api/ai', {
            system: system.value,
            prompt: prompt.value,
        });
        responseText.value = data.text;
    } catch (e) {
        error.value = e.response?.data?.error ?? e.message;
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <div class="mx-auto max-w-2xl">
        <div class="mb-4">
            <h2 class="text-lg font-semibold">AI example</h2>
            <p class="text-sm text-fg-mid-grey">
                A minimal worked example of calling Claude through this app's <code class="rounded bg-fg-pale-grey px-1">POST /api/ai</code>
                endpoint. This is the pattern to copy when you automate a page. The API key lives in
                <code class="rounded bg-fg-pale-grey px-1">.env</code> on the server — never put it in frontend code.
            </p>
        </div>

        <div class="space-y-3 rounded border border-fg-muted-grey bg-white p-4">
            <div>
                <label class="mb-1 block text-sm font-medium">System prompt (optional — sets the role)</label>
                <textarea
                    v-model="system"
                    rows="2"
                    class="w-full rounded border border-fg-muted-grey p-2 text-sm"
                ></textarea>
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium">Prompt</label>
                <textarea
                    v-model="prompt"
                    rows="5"
                    class="w-full rounded border border-fg-muted-grey p-2 text-sm"
                    placeholder="Try pasting in a messy bank transaction description and asking which category fits…"
                ></textarea>
            </div>

            <button
                class="rounded bg-fg-main-blue px-4 py-1.5 text-sm font-medium text-white hover:bg-fg-main-blue-hover disabled:opacity-50"
                :disabled="loading || !prompt.trim()"
                @click="ask"
            >
                {{ loading ? 'Thinking…' : 'Ask Claude' }}
            </button>

            <p v-if="error" class="rounded bg-fg-danger-9 p-3 text-sm text-fg-danger-dark">{{ error }}</p>
            <pre
                v-if="responseText"
                class="whitespace-pre-wrap rounded bg-fg-super-pale-grey p-3 font-sans text-sm leading-relaxed"
                >{{ responseText }}</pre
            >
        </div>
    </div>
</template>
