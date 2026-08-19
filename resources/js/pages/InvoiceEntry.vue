<script setup>
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import { money, shortDate } from '../format';

const invoices = ref([]);
const categories = ref([]);
const selected = ref(null);
const loading = ref(true);
const saving = ref(false);
const savedAt = ref(null);

// AI auto-fill state
const aiLoading = ref(false);
const aiError = ref('');
const aiSuccess = ref(false);

// Batch processing
const batchProcessing = ref(false);
const cancelBatch = ref(false);

// Upload state
const uploadedIds = ref(new Set());
const isDragging = ref(false);
const uploading = ref(false);

// Delete state
const deleting = ref(false);

// Chat state
const chatHistory = ref([]);
const chatInput = ref('');
const chatLoading = ref(false);

// The entry form being filled in for the selected invoice.
const form = ref(emptyForm());

function emptyForm() {
    return {
        supplier: '',
        invoice_number: '',
        gst_number: '',
        invoice_date: '',
        due_date: '',
        total: null,
        category_id: null,
        lines: [{ description: '', quantity: null, unit_price: null, amount: null }],
    };
}

onMounted(async () => {
    await fetchInvoices();
    loading.value = false;
});

async function fetchInvoices() {
    const { data } = await axios.get('/api/invoices');
    invoices.value = data.invoices;
    categories.value = data.categories;
}

function open(invoice) {
    selected.value = invoice;
    savedAt.value = null;
    aiError.value = '';
    aiSuccess.value = false;
    chatHistory.value = [];
    if (invoice.entered_at) {
        form.value = {
            supplier: invoice.supplier,
            invoice_number: invoice.invoice_number || '',
            gst_number: invoice.gst_number || '',
            invoice_date: invoice.invoice_date,
            due_date: invoice.due_date || '',
            total: invoice.total,
            category_id: invoice.category_id,
            lines: invoice.lines.map((l) => ({ 
                description: l.description, 
                quantity: l.quantity, 
                unit_price: l.unit_price, 
                amount: l.amount 
            })),
        };
    } else {
        form.value = emptyForm();
    }
}

function addLine() {
    form.value.lines.push({ description: '', quantity: null, unit_price: null, amount: null });
}

function removeLine(index) {
    form.value.lines.splice(index, 1);
}

const lineTotal = computed(() => form.value.lines.reduce((sum, l) => sum + (Number(l.amount) || 0), 0));

const unenteredCount = computed(() => invoices.value.filter(i => !i.entered_at).length);

// ── Wow Factor 1: Duplicate Detection ──────────────────────
const duplicateWarning = computed(() => {
    if (!form.value.supplier || !form.value.invoice_number || !selected.value) return null;
    const dup = invoices.value.find(i => 
        i.entered_at && 
        i.id !== selected.value.id &&
        i.supplier?.toLowerCase() === form.value.supplier.toLowerCase() && 
        i.invoice_number?.toLowerCase() === form.value.invoice_number.toLowerCase()
    );
    if (dup) {
        return `Warning: This invoice was already processed on ${shortDate(dup.entered_at)}.`;
    }
    return null;
});

// ── Wow Factor 2: GST Compliance Risk ──────────────────────
const gstComplianceWarning = computed(() => {
    const total = Number(form.value.total) || 0;
    const lines = lineTotal.value;
    if (total === 0 || lines === 0) return null;
    
    // If the total is > lines by roughly 15%, they charged GST
    const expectedGst = Math.abs(Math.round(lines * 0.15 * 100) / 100);
    const difference = Math.abs(Math.abs(total) - Math.abs(lines));
    
    // Check if difference is near 15% GST
    const chargedGst = difference > 0.10 && Math.abs(difference - expectedGst) <= 0.10;
    
    if (chargedGst && !form.value.gst_number) {
        return "GST Compliance Risk: Supplier charged GST but no GST number is printed on the document.";
    }
    return null;
});

// ── Wow Factor 5: Price Variance / Inflation Flagging ──────
function getInflationWarning(line) {
    if (!line.unit_price || !line.description) return null;
    
    // Find the exact same string in previous invoices
    for (const inv of invoices.value) {
        if (!inv.entered_at || inv.id === selected.value?.id) continue;
        
        for (const pastLine of inv.lines) {
            if (pastLine.unit_price && pastLine.description.toLowerCase() === line.description.toLowerCase()) {
                if (line.unit_price > pastLine.unit_price) {
                    const diff = line.unit_price - pastLine.unit_price;
                    const pct = Math.round((diff / pastLine.unit_price) * 100);
                    return {
                        pct,
                        supplier: inv.supplier,
                        date: inv.invoice_date,
                        pastPrice: pastLine.unit_price
                    };
                }
            }
        }
    }
    return null;
}

// ── Calculation validation ───────────────────────────────────
const validation = computed(() => {
    const total = Number(form.value.total) || 0;
    const linesSum = lineTotal.value;

    if (!total || !linesSum) return null;

    const expectedGst = Math.round(linesSum * 0.15 * 100) / 100;
    const expectedTotal = Math.round((linesSum + expectedGst) * 100) / 100;

    // Check magnitude difference
    const diffMagnitude = Math.abs(Math.abs(expectedTotal) - Math.abs(total));
    const isCreditLines = linesSum < 0;
    const isCreditTotal = total < 0;

    // Allow small rounding tolerance (up to $0.10)
    if (diffMagnitude <= 0.10) {
        // Math matches, but do the signs match?
        if (isCreditLines && !isCreditTotal) {
            return {
                ok: false,
                message: `Line items indicate a Credit Note (negative), but the Invoice Total is positive. Please change the total to ${money(expectedTotal)}.`,
            };
        }
        if (!isCreditLines && isCreditTotal) {
            return {
                ok: false,
                message: `Line items are positive, but the Invoice Total is negative. Please check the signs.`,
            };
        }

        return {
            ok: true,
            message: `Lines total ${money(linesSum)} + GST ${money(expectedGst)} = ${money(expectedTotal)}. Calculation verified.`,
        };
    } else {
        const diff = Math.abs(expectedTotal - total);
        return {
            ok: false,
            message: `Lines total ${money(linesSum)} + 15% GST = ${money(expectedTotal)}, but invoice total is ${money(total)}. Difference of ${money(diff)} — please check the line amounts or total.`,
        };
    }
});

async function save() {
    saving.value = true;
    const { data } = await axios.put(`/api/invoices/${selected.value.id}`, form.value);
    Object.assign(selected.value, data);
    saving.value = false;
    savedAt.value = new Date();
}

async function processAllUnentered() {
    if (!confirm('This will sequentially auto-parse and save all unentered invoices. Continue?')) return;
    batchProcessing.value = true;
    cancelBatch.value = false;
    
    const unentered = invoices.value.filter(i => !i.entered_at);
    
    for (const inv of unentered) {
        if (cancelBatch.value) break;
        
        open(inv);
        await autoFill();
        
        // Only save if AI was successful AND calculation validation passes perfectly
        if (aiSuccess.value && validation.value?.ok) {
            await save();
        }
    }
    
    batchProcessing.value = false;
    cancelBatch.value = false;
    selected.value = null;
    form.value = emptyForm();
}

async function resetInvoice() {
    if (!confirm('This will move the invoice back to pending and clear its data. Continue?')) return;
    saving.value = true;
    try {
        const { data } = await axios.post(`/api/invoices/${selected.value.id}/reset`);
        Object.assign(selected.value, data);
        form.value = emptyForm();
        savedAt.value = null;
    } catch (e) {
        aiError.value = 'Reset failed: ' + (e.response?.data?.message ?? e.message);
    } finally {
        saving.value = false;
    }
}

// ── Delete / skip invoice ────────────────────────────────────
async function deleteInvoice() {
    if (!selected.value) return;
    if (!confirm(`Remove "${selected.value.filename}" from the list? This cannot be undone.`)) return;

    deleting.value = true;
    try {
        await axios.delete(`/api/invoices/${selected.value.id}`);
        invoices.value = invoices.value.filter(i => i.id !== selected.value.id);
        selected.value = null;
        form.value = emptyForm();
    } catch (e) {
        aiError.value = 'Failed to delete: ' + (e.response?.data?.message ?? e.message);
    } finally {
        deleting.value = false;
    }
}

function skipToNext() {
    const currentIndex = invoices.value.findIndex(i => i.id === selected.value?.id);
    const next = invoices.value.find((inv, idx) => idx > currentIndex && !inv.entered_at);
    if (next) {
        open(next);
    } else {
        const first = invoices.value.find(i => !i.entered_at);
        if (first) open(first);
    }
}

// ── Wow Factor 4: Chat with this invoice ─────────────────────
async function askChat() {
    if (!chatInput.value.trim() || !selected.value || chatLoading.value) return;
    
    const question = chatInput.value;
    chatInput.value = '';
    chatHistory.value.push({ role: 'user', text: question });
    chatLoading.value = true;

    try {
        const { data } = await axios.post('/api/ai', {
            system: `You are an assistant helping an accountant answer questions about a scanned document. Answer concisely.
CRITICAL INSTRUCTION: You must format your response using ONLY standard HTML tags. 
Use these exact classes for styling:
- <b> for bold
- <ul class="list-disc pl-4 my-1"> for unordered lists
- <li> for list items
- <table class="w-full text-left border-collapse my-2 text-xs">
- <th class="border-b border-fg-muted-grey p-1 font-semibold">
- <td class="border-b border-fg-pale-grey p-1">
- <br> for newlines
DO NOT use markdown (e.g. no **, no -, no #). DO NOT wrap your response in \`\`\`html blocks. Output the raw HTML directly.`,
            prompt: `Here is the document text:\n\n${selected.value.raw_text}\n\nUser question: ${question}`,
        });
        
        let htmlText = data.text.trim();
        // Fallback cleanup if Claude still includes markdown codeblock formatting
        if (htmlText.startsWith('```html')) {
            htmlText = htmlText.replace(/^```html\n?/, '').replace(/\n?```$/, '');
        } else if (htmlText.startsWith('```')) {
            htmlText = htmlText.replace(/^```\n?/, '').replace(/\n?```$/, '');
        }
        
        chatHistory.value.push({ role: 'assistant', text: htmlText });
    } catch (e) {
        chatHistory.value.push({ role: 'assistant', text: `Error: ${e.message}` });
    } finally {
        chatLoading.value = false;
    }
}

// ── AI Auto-fill ─────────────────────────────────────────────
async function autoFill() {
    if (!selected.value) return;
    aiLoading.value = true;
    aiError.value = '';
    aiSuccess.value = false;

    const categoryList = categories.value.map(c => c.name).join(', ');

    const systemPrompt = `You are a highly accurate data extraction assistant for a New Zealand rural accounting practice.
Your task is to parse raw, messy OCR text from scanned invoices and credit notes into a strict JSON structure.
No matter what template the invoice uses, you must find and extract the Supplier Name, Invoice Number, and other fields.

Available accounting categories: [${categoryList}]

EXTRACTION RULES:
1. Dates: Convert the invoice date to strictly YYYY-MM-DD format.
   - Also look for a Due Date. If it says "20th of following month", calculate the exact YYYY-MM-DD based on the invoice date. If missing, return null.
2. Identifiers:
   - Extract the Invoice Number / Credit Note Number.
   - Extract the GST Number (often formatted like 11-223-344). If not found, return null explicitly.
3. Amounts:
   - Line item 'amount' must be GST-exclusive (net).
   - Try to extract 'quantity' and 'unit_price' for each line if present (e.g. "2.0 t @ 412.00"). If not, return null.
   - 'total' must be the final GST-inclusive amount (gross).
   - If the document is a CREDIT NOTE, all monetary values (lines and total) must be NEGATIVE.
   - If a specific line item is a refund, return, or credit within a normal invoice, make ONLY that line amount NEGATIVE.
4. Categorization: Pick the single most accurate category from the available list. Use these hints:
   - Fertiliser spreading/application = "Fertiliser"
   - Tractor/wagon repairs, engineering, parts = "Repairs"
   - Vet visits, drench, vaccine, dog food, farm merchandise = "Vet & Animal Health"
   - Calf meal, supplements, hay, hay cartage, ryegrass seed = "Feed"
   - Bulk diesel, petrol = "Fuel"

OUTPUT FORMAT:
Return ONLY a valid JSON object. Do not include any explanations, markdown formatting, or conversational text. Use this exact schema:
{
  "supplier": "string (Capitalize normally)",
  "invoice_number": "string | null",
  "gst_number": "string | null",
  "invoice_date": "YYYY-MM-DD",
  "due_date": "YYYY-MM-DD | null",
  "category": "Exact string from available categories",
  "lines": [
    {
      "description": "string (Clean up any OCR noise)",
      "quantity": 1.5,
      "unit_price": 40.00,
      "amount": 123.45
    }
  ],
  "total": 123.45
}`;

    try {
        const { data } = await axios.post('/api/ai', {
            system: systemPrompt,
            prompt: `Parse this scanned invoice text into structured data:\n\n${selected.value.raw_text}`,
        });

        let parsed;
        try {
            let text = data.text.trim();
            const jsonMatch = text.match(/```(?:json)?\s*([\s\S]*?)```/);
            if (jsonMatch) text = jsonMatch[1].trim();
            parsed = JSON.parse(text);
        } catch (e) {
            throw new Error('AI returned invalid JSON. Please try again or fill in manually.');
        }

        const cat = categories.value.find(
            c => c.name.toLowerCase() === parsed.category?.toLowerCase()
        );

        form.value = {
            supplier: parsed.supplier || '',
            invoice_number: parsed.invoice_number || '',
            gst_number: parsed.gst_number || '',
            invoice_date: parsed.invoice_date || '',
            due_date: parsed.due_date || '',
            total: parsed.total ?? null,
            category_id: cat ? cat.id : null,
            lines: (parsed.lines || []).map(l => ({
                description: l.description || '',
                quantity: l.quantity ?? null,
                unit_price: l.unit_price ?? null,
                amount: l.amount ?? null,
            })),
        };

        aiSuccess.value = true;

        if (!cat && parsed.category) {
            aiError.value = `Category "${parsed.category}" not recognised — please select manually.`;
        }
    } catch (e) {
        aiError.value = e.response?.data?.error ?? e.message;
    } finally {
        aiLoading.value = false;
    }
}

// ── File upload (drag & drop) ────────────────────────────────
function onDragOver(e) {
    e.preventDefault();
    isDragging.value = true;
}

function onDragLeave() {
    isDragging.value = false;
}

async function onDrop(e) {
    e.preventDefault();
    isDragging.value = false;
    const files = Array.from(e.dataTransfer.files).filter(f => f.name.endsWith('.txt'));
    if (files.length === 0) return;
    await uploadFiles(files);
}

async function onFileSelect(e) {
    const files = Array.from(e.target.files);
    if (files.length === 0) return;
    await uploadFiles(files);
    e.target.value = '';
}

async function uploadFiles(files) {
    uploading.value = true;
    const formData = new FormData();
    files.forEach(f => formData.append('files[]', f));

    try {
        const oldIds = new Set(invoices.value.map(i => i.id));
        await axios.post('/api/invoices/upload', formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        await fetchInvoices();
        invoices.value.forEach(i => {
            if (!oldIds.has(i.id)) uploadedIds.value.add(i.id);
        });
    } catch (e) {
        aiError.value = 'Upload failed: ' + (e.response?.data?.message ?? e.message);
    } finally {
        uploading.value = false;
    }
}
</script>

<template>
    <div>
        <div class="mb-4 flex items-center justify-between">
            <div>
                <h2 class="text-lg font-semibold">Invoice entry</h2>
                <p class="text-sm text-fg-mid-grey">
                    Key each supplier invoice into the system from its scanned text. Use <strong>Auto-fill with AI</strong> to parse instantly.
                </p>
            </div>
            <span
                v-if="!loading && unenteredCount > 0"
                class="shrink-0 rounded-full bg-fg-warning-bg px-3 py-1 text-xs font-medium text-fg-warning-text"
            >
                {{ unenteredCount }} still to enter
            </span>
        </div>

        <p v-if="loading" class="flex items-center gap-2 text-fg-light-grey">
            <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25" />
                <path d="M4 12a8 8 0 018-8" stroke="currentColor" stroke-width="3" stroke-linecap="round" class="opacity-75" />
            </svg>
            Loading invoices…
        </p>

        <div v-else class="grid grid-cols-1 gap-4 lg:grid-cols-4">
            <!-- Invoice list + upload zone -->
            <div>
                <!-- Drag & drop upload zone -->
                <div
                    class="mb-2 rounded-lg border-2 border-dashed p-3 text-center transition-colors"
                    :class="isDragging ? 'border-fg-main-blue bg-fg-main-blue-9' : 'border-fg-muted-grey bg-fg-super-pale-grey'"
                    @dragover="onDragOver"
                    @dragleave="onDragLeave"
                    @drop="onDrop"
                >
                    <div v-if="uploading" class="flex items-center justify-center gap-2 text-sm text-fg-mid-grey">
                        <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25" />
                            <path d="M4 12a8 8 0 018-8" stroke="currentColor" stroke-width="3" stroke-linecap="round" class="opacity-75" />
                        </svg>
                        Uploading…
                    </div>
                    <div v-else>
                        <p class="text-xs text-fg-mid-grey">
                            Drop <code class="rounded bg-fg-pale-grey px-1">.txt</code> invoice files here
                        </p>
                        <label class="mt-1 cursor-pointer text-xs font-medium text-fg-main-blue hover:text-fg-main-blue-hover hover:underline">
                            or browse files
                            <input type="file" accept=".txt" multiple class="hidden" @change="onFileSelect" />
                        </label>
                    </div>
                </div>

                <!-- Invoice list -->
                <div v-if="unenteredCount > 0" class="mb-2 p-3 border border-fg-main-blue-9 rounded-lg bg-fg-super-pale-grey text-center">
                    <div v-if="!batchProcessing">
                        <button 
                            @click="processAllUnentered" 
                            class="w-full py-2 rounded-lg bg-fg-main-blue text-white text-sm font-semibold hover:bg-fg-main-blue-hover transition-all shadow-sm"
                        >
                            Save all entries with AI
                        </button>
                        <p class="text-xs text-fg-mid-grey mt-1">Automatically parses and saves all unentered invoices.</p>
                    </div>
                    <div v-else class="flex flex-col gap-2">
                        <button 
                            @click="cancelBatch = true" 
                            :disabled="cancelBatch"
                            class="w-full py-2 rounded-lg bg-fg-danger text-white text-sm font-semibold hover:bg-fg-danger-dark disabled:opacity-50 transition-all shadow-sm flex items-center justify-center gap-2"
                        >
                            <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25" />
                                <path d="M4 12a8 8 0 018-8" stroke="currentColor" stroke-width="3" stroke-linecap="round" class="opacity-75" />
                            </svg>
                            {{ cancelBatch ? 'Stopping...' : 'Stop processing' }}
                        </button>
                        <p class="text-xs text-fg-danger mt-1 font-medium" v-if="cancelBatch">Stopping after current invoice completes...</p>
                    </div>
                </div>

                <div class="overflow-hidden rounded border border-fg-muted-grey bg-white">
                    <button
                        v-for="invoice in invoices"
                        :key="invoice.id"
                        class="block w-full border-b border-fg-pale-grey px-3 py-2 text-left text-sm hover:bg-fg-pale-grey"
                        :class="selected?.id === invoice.id ? 'bg-fg-main-blue-9' : ''"
                        @click="open(invoice)"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span class="truncate font-mono text-xs">{{ invoice.filename }}</span>
                            <div class="flex shrink-0 gap-1">
                                <span
                                    v-if="uploadedIds.has(invoice.id) && !invoice.entered_at"
                                    class="rounded-full bg-fg-warning-bg px-2 py-0.5 text-xs font-medium text-fg-warning-text"
                                >
                                    new
                                </span>
                                <span
                                    v-if="batchProcessing && invoice.id === selected?.id && !invoice.entered_at"
                                    class="rounded-full bg-fg-dark-blue px-2 py-0.5 text-xs text-white animate-pulse"
                                >
                                    loading...
                                </span>
                                <span
                                    v-if="invoice.entered_at"
                                    class="rounded-full bg-fg-positive-15 px-2 py-0.5 text-xs text-fg-positive-dark"
                                >
                                    entered
                                </span>
                            </div>
                        </div>
                    </button>
                </div>
            </div>

            <!-- Raw invoice text & Chat -->
            <div class="lg:col-span-2 flex flex-col h-full">
                <p v-if="!selected" class="rounded border border-dashed border-fg-muted-grey p-8 text-center text-fg-light-grey">
                    Select an invoice to view and enter it.
                </p>
                <div v-else class="flex flex-col flex-1">
                    <div class="mb-2 flex items-center justify-between">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-fg-mid-grey">Invoice preview</h3>
                        <div class="flex gap-2">
                            <button
                                class="rounded border border-fg-muted-grey px-2.5 py-1 text-xs text-fg-mid-grey hover:bg-fg-pale-grey hover:text-fg-dark-grey"
                                @click="skipToNext"
                                title="Skip to next unentered invoice"
                            >
                                Skip to next
                            </button>
                            <button
                                class="rounded border border-fg-danger/30 px-2.5 py-1 text-xs text-fg-danger-dark hover:bg-fg-danger-9"
                                :disabled="deleting || batchProcessing"
                                @click="deleteInvoice"
                                title="Remove this invoice from the list"
                            >
                                {{ deleting ? 'Removing…' : 'Remove' }}
                            </button>
                        </div>
                    </div>
                    <pre
                        class="overflow-x-auto max-h-64 rounded border border-fg-muted-grey bg-white p-4 font-mono text-xs leading-relaxed"
                        >{{ selected.raw_text }}</pre>

                    <!-- Chat with this Invoice -->
                    <div class="mt-4 flex-1 rounded border border-fg-muted-grey bg-fg-super-pale-grey flex flex-col">
                        <div class="border-b border-fg-muted-grey bg-white px-3 py-2 flex items-center gap-2 rounded-t">
                            <svg class="w-4 h-4 text-fg-main-blue" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"></path></svg>
                            <h4 class="text-xs font-semibold text-fg-dark-grey">Chat with this Document</h4>
                        </div>
                        <div class="flex-1 p-3 overflow-y-auto max-h-40 space-y-3">
                            <p v-if="chatHistory.length === 0" class="text-xs text-fg-mid-grey text-center mt-2">Ask a question like "Who signed for delivery?" or "What was the price per unit?"</p>
                            <div v-for="(msg, i) in chatHistory" :key="i" class="text-xs leading-relaxed" :class="msg.role === 'user' ? 'text-fg-dark-grey font-medium' : 'text-fg-mid-grey'">
                                <span class="font-bold text-fg-main-blue">{{ msg.role === 'user' ? 'You' : 'AI' }}:</span>
                                <span v-if="msg.role === 'user'">{{ msg.text }}</span>
                                <span v-else v-html="msg.text" class="block mt-1"></span>
                            </div>
                            <div v-if="chatLoading" class="text-xs text-fg-mid-grey animate-pulse">Thinking...</div>
                        </div>
                        <div class="p-2 border-t border-fg-muted-grey bg-white flex gap-2 rounded-b">
                            <input v-model="chatInput" @keyup.enter="askChat" class="flex-1 text-xs rounded border border-fg-muted-grey px-2 py-1.5 focus:border-fg-main-blue focus:outline-none" placeholder="Ask anything about the text above..." :disabled="chatLoading" />
                            <button @click="askChat" class="rounded bg-fg-main-blue px-3 py-1.5 text-xs font-medium text-white hover:bg-fg-main-blue-hover disabled:opacity-50" :disabled="chatLoading || !chatInput.trim()">Ask</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Entry form -->
            <div v-if="selected" class="rounded border border-fg-muted-grey bg-white p-4">
                <h3 class="mb-3 text-sm font-semibold">Entry form</h3>

                <!-- AI Auto-fill button -->
                <button
                    class="mb-3 w-full rounded px-4 py-2 text-sm font-medium text-white transition-all disabled:opacity-50"
                    :class="aiLoading || batchProcessing
                        ? 'bg-fg-dark-blue'
                        : 'bg-fg-dark-blue hover:bg-fg-dark-blue/90 shadow-sm hover:shadow'"
                    :disabled="aiLoading || batchProcessing || !selected"
                    @click="autoFill"
                >
                    <span v-if="aiLoading || batchProcessing" class="flex items-center justify-center gap-2">
                        <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25" />
                            <path d="M4 12a8 8 0 018-8" stroke="currentColor" stroke-width="3" stroke-linecap="round" class="opacity-75" />
                        </svg>
                        AI is reading the invoice…
                    </span>
                    <span v-else>Auto-fill with AI</span>
                </button>

                <!-- AI feedback messages -->
                <div v-if="aiSuccess && !aiError && !batchProcessing" class="mb-2 rounded border border-fg-positive-dark/20 bg-fg-positive-15 px-3 py-2 text-xs text-fg-positive-dark">
                    <p class="font-medium">AI auto-fill complete</p>
                    <p class="mt-0.5 opacity-80">Review the fields below and save when ready.</p>
                </div>
                <div v-if="aiError && !batchProcessing" class="mb-2 rounded border border-fg-danger-dark/20 bg-fg-danger-9 px-3 py-2 text-xs text-fg-danger-dark">
                    {{ aiError }}
                </div>

                <!-- Duplicate Warning -->
                <div v-if="duplicateWarning" class="mb-3 rounded border border-fg-danger-dark/40 bg-fg-danger-9 px-3 py-2 text-xs text-fg-danger-dark">
                    <div class="flex items-start gap-1.5">
                        <svg class="w-4 h-4 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                        <div>
                            <p class="font-bold">Duplicate Detection</p>
                            <p>{{ duplicateWarning }}</p>
                        </div>
                    </div>
                </div>

                <!-- GST Compliance Warning -->
                <div v-if="gstComplianceWarning" class="mb-3 rounded border border-fg-warning-text/40 bg-fg-warning-bg px-3 py-2 text-xs text-fg-warning-text">
                    <div class="flex items-start gap-1.5">
                        <svg class="w-4 h-4 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                        <div>
                            <p class="font-bold">Compliance Alert</p>
                            <p>{{ gstComplianceWarning }}</p>
                        </div>
                    </div>
                </div>

                <label class="block text-xs font-medium text-fg-mid-grey">Supplier</label>
                <input v-model="form.supplier" class="mb-2 w-full rounded border border-fg-muted-grey px-2 py-1 text-sm" />

                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-xs font-medium text-fg-mid-grey">Invoice #</label>
                        <input v-model="form.invoice_number" class="mb-2 w-full rounded border border-fg-muted-grey px-2 py-1 text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-fg-mid-grey">GST Number</label>
                        <input v-model="form.gst_number" placeholder="Optional" class="mb-2 w-full rounded border border-fg-muted-grey px-2 py-1 text-sm" />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-xs font-medium text-fg-mid-grey">Invoice Date</label>
                        <input v-model="form.invoice_date" type="date" class="mb-2 w-full rounded border border-fg-muted-grey px-2 py-1 text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-fg-mid-grey">Due Date</label>
                        <input v-model="form.due_date" type="date" class="mb-2 w-full rounded border border-fg-muted-grey px-2 py-1 text-sm" />
                    </div>
                </div>

                <label class="block text-xs font-medium text-fg-mid-grey">Category</label>
                <select v-model="form.category_id" class="mb-2 w-full rounded border border-fg-muted-grey px-2 py-1 text-sm">
                    <option :value="null">— choose —</option>
                    <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                </select>

                <label class="block text-xs font-medium text-fg-mid-grey mt-2">Line items (excl. GST)</label>
                <div v-for="(line, index) in form.lines" :key="index" class="mb-2 border-l-2 border-fg-pale-grey pl-2">
                    <div class="flex gap-1 mb-1">
                        <input
                            v-model="line.description"
                            placeholder="Description"
                            class="w-full rounded border border-fg-muted-grey px-2 py-1 text-xs"
                        />
                        <button
                            v-if="form.lines.length > 1"
                            class="px-1 text-fg-light-grey hover:text-fg-danger"
                            title="Remove line"
                            @click="removeLine(index)"
                        >
                            ✕
                        </button>
                    </div>
                    <div class="flex gap-1">
                        <input
                            v-model.number="line.quantity"
                            type="number"
                            step="0.01"
                            placeholder="Qty"
                            class="w-16 rounded border border-fg-muted-grey px-2 py-1 text-right font-mono text-xs"
                        />
                        <input
                            v-model.number="line.unit_price"
                            type="number"
                            step="0.01"
                            placeholder="Unit Price"
                            class="w-24 rounded border border-fg-muted-grey px-2 py-1 text-right font-mono text-xs"
                        />
                        <input
                            v-model.number="line.amount"
                            type="number"
                            step="0.01"
                            placeholder="Total amount"
                            class="w-24 ml-auto rounded border border-fg-muted-grey px-2 py-1 text-right font-mono text-xs font-bold"
                        />
                    </div>
                    <!-- Inflation Warning -->
                    <div v-if="getInflationWarning(line)" class="mt-1 flex items-center gap-1 text-xs text-fg-danger-dark bg-fg-danger-9 px-2 py-1 rounded">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path></svg>
                        <span>{{ getInflationWarning(line).pct }}% more expensive than {{ money(getInflationWarning(line).pastPrice) }} ({{ shortDate(getInflationWarning(line).date) }})</span>
                    </div>
                </div>
                <button class="mb-2 text-xs text-fg-main-blue hover:text-fg-main-blue-hover hover:underline" @click="addLine">+ add line</button>
                <p class="mb-2 text-xs text-fg-light-grey">Lines sum to {{ money(lineTotal) }}</p>

                <label class="block text-xs font-medium text-fg-mid-grey">Invoice total (incl. GST)</label>
                <input
                    v-model.number="form.total"
                    type="number"
                    step="0.01"
                    class="mb-2 w-full rounded border border-fg-muted-grey px-2 py-1 text-right font-mono text-sm"
                />

                <!-- Calculation validation -->
                <div
                    v-if="validation"
                    class="mb-3 rounded border px-3 py-2 text-xs"
                    :class="validation.ok
                        ? 'border-fg-positive-dark/20 bg-fg-positive-15 text-fg-positive-dark'
                        : 'border-fg-warning-text/20 bg-fg-warning-bg text-fg-warning-text'"
                >
                    <p class="font-medium">{{ validation.ok ? 'Calculation check passed' : 'Calculation discrepancy' }}</p>
                    <p class="mt-0.5 opacity-80">{{ validation.message }}</p>
                </div>

                <div class="flex gap-2">
                    <button
                        class="w-full rounded bg-fg-main-blue px-4 py-1.5 text-sm font-medium text-white hover:bg-fg-main-blue-hover disabled:opacity-50"
                        :disabled="saving || batchProcessing"
                        @click="save"
                    >
                        {{ saving ? 'Saving…' : 'Save entry' }}
                    </button>
                </div>
                <div class="mt-2 flex gap-2">
                    <button
                        v-if="selected.entered_at"
                        class="w-full rounded border border-fg-muted-grey bg-white px-4 py-1.5 text-sm font-medium text-fg-dark-grey hover:bg-fg-pale-grey disabled:opacity-50"
                        :disabled="saving || batchProcessing"
                        @click="resetInvoice"
                    >
                        Undo save
                    </button>
                    <button
                        v-else
                        class="w-full rounded border border-fg-muted-grey bg-white px-4 py-1.5 text-sm font-medium text-fg-dark-grey hover:bg-fg-pale-grey disabled:opacity-50"
                        :disabled="batchProcessing"
                        @click="form = emptyForm()"
                    >
                        Clear form
                    </button>
                </div>
                <p v-if="savedAt" class="mt-2 text-center text-xs text-fg-positive-dark">Saved</p>
            </div>
        </div>
    </div>
</template>
