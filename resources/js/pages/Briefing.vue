<script setup>
// Static briefing page - the README's scenario, scope, run sheet and rules,
// in the app so nobody has to alt-tab to find out what they're meant to do.

const farms = [
    { name: 'Riverbend Dairy Ltd', type: 'Dairy', farmer: 'Gary Preston' },
    { name: 'Kahikatea Downs', type: 'Sheep & Beef', farmer: 'Kate Molloy' },
    { name: 'Windrow Cropping Ltd', type: 'Arable (crops)', farmer: 'Bruce Tanner' },
];

const jargon = [
    ['Farmer / adviser', 'The farmer runs the farm and is the client; the adviser (you) does their numbers and answers their questions.'],
    ['Coding', 'Assigning each bank transaction to an account like "Feed" or "Fuel" so reports mean something. Nothing to do with programming.'],
    ['Budget vs actual', 'What the farm planned to earn/spend each month vs what actually happened. The difference is the variance.'],
    ['Sale docket', 'The paper slip a stock agent issues when livestock are sold. Often the only record that a sale happened.'],
    ['Stock reconciliation', 'Proving opening animals + births + purchases − deaths − sales = closing animals. When it doesn\'t balance, something was missed (or counted twice).'],
];

const runSheet = [
    ['4:00 – 4:10', 'Kickoff. Scenario, teams, API keys handed out.'],
    ['4:10 – 5:00', 'Research. Do the manual grunt work on every page, and dig into what you don\'t understand — the terms, the tax concepts, how the money actually moves. Use AI to learn; keep track of what you asked and how you checked the answers.'],
    ['5:00', 'Checkpoint — pick your problem. Two sentences: "We\'re automating X because Y" and "the thing we had to learn to do it properly is Z."'],
    ['5:00 – 5:30', 'Build the thin end: get one AI call working on real page data (copy the AI Example pattern).'],
    ['5:30', 'Mid-point check. Nothing working on screen yet? Shrink scope now.'],
    ['5:30 – 6:20', 'Build the rest: wire it into the page and handle the awkward cases you found in the data.'],
    ['6:00', 'Food arrives. Grab a plate and keep building — demos still start at 6:30.'],
    ['6:20', 'Scope freeze. Stop adding. Fix, polish, rehearse the demo once end-to-end.'],
    ['6:30', 'Pencils down.'],
    ['6:30 – 7:00', 'Demos and debrief — 4–5 minutes per team: the manual pain → what you had to learn (and how) → your fix.'],
    ['7:00 – 7:45', 'Open — catch-ups, and overflow if demos run long.'],
    ['7:45 – 8:00', 'Wrap. Thanks, pack down.'],
];
</script>

<template>
    <div class="mx-auto max-w-3xl space-y-6">
        <!-- Scenario -->
        <section class="rounded border border-fg-muted-grey bg-white p-5">
            <h2 class="text-lg font-semibold">The scenario</h2>
            <p class="mt-2 text-sm leading-relaxed text-fg-dark-grey">
                Welcome to <strong>The Figured Great Code Muster</strong> — and to
                <strong>Southdown Rural Accountants</strong>, a small (fictional) farm accounting practice.
                You're the new graduate adviser, and this is the practice's management tool. It works. It is also
                <em>entirely manual</em>, and the grunt work is eating the team alive. Today: feel the pain, then fix
                some of it with AI.
            </p>
            <table class="mt-3 w-full text-sm">
                <thead class="text-left text-fg-light-grey">
                    <tr>
                        <th class="py-1 font-medium">Farm</th>
                        <th class="py-1 font-medium">Type</th>
                        <th class="py-1 font-medium">Farmer</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="farm in farms" :key="farm.name" class="border-t border-fg-pale-grey">
                        <td class="py-1">{{ farm.name }}</td>
                        <td class="py-1">{{ farm.type }}</td>
                        <td class="py-1">{{ farm.farmer }}</td>
                    </tr>
                </tbody>
            </table>
            <p class="mt-3 text-xs text-fg-light-grey">
                Everything in this app is fictional — the farms, the people, the emails, the numbers.
            </p>
        </section>

        <!-- The brief -->
        <section class="rounded border border-fg-muted-grey bg-white p-5">
            <h2 class="text-lg font-semibold">The brief</h2>
            <ol class="mt-2 list-decimal space-y-2 pl-5 text-sm leading-relaxed text-fg-dark-grey">
                <li>
                    <strong>Research (the first ~50 minutes).</strong> Do the grunt work by hand — that's how you find out
                    where it hurts. You'll quickly hit things you don't understand: farming terms, tax concepts, how
                    milk payments work. <strong>You're not expected to know any of it.</strong> Learning it — with AI,
                    with the app's data, with whatever works — is part of the exercise. Keep track of how you learnt:
                    you'll be asked to walk through it. One tip: the pages aren't islands. It's the same three farms
                    everywhere, and the story runs through the whole app — what you need for one page often lives on
                    another.
                </li>
                <li>
                    <strong>Pick the worst grunt work.</strong> One screen, or one slice of one screen. If you've
                    spotted something a client clearly needs that <em>no</em> screen does, building that counts too.
                </li>
                <li>
                    <strong>Automate it</strong> using the <code class="rounded bg-fg-pale-grey px-1">POST /api/ai</code>
                    endpoint. The AI Example tab is the pattern to copy — it's the only AI code in the app so far.
                </li>
                <li>
                    <strong>Demo it.</strong> 3–4 minutes per team: the manual pain, what you had to learn (and how
                    you learnt it), and your fix. Expect questions about your design decisions.
                </li>
            </ol>
        </section>

        <!-- Scope -->
        <section class="rounded border border-fg-muted-grey bg-white p-5">
            <h2 class="text-lg font-semibold">Scope</h2>
            <p class="mt-2 text-sm text-fg-dark-grey">
                <strong>Your deliverable:</strong> one working AI-assisted improvement to one screen, demoed on the
                seeded data. Be ready to explain and defend every design decision you made.
            </p>
            <div class="mt-3 grid gap-4 sm:grid-cols-2">
                <div class="rounded border border-fg-positive-15 bg-fg-positive-9 p-3">
                    <h3 class="text-sm font-semibold text-fg-positive-dark">In scope</h3>
                    <ul class="mt-1 list-disc space-y-1 pl-4 text-sm text-fg-dark-grey">
                        <li>Anything in <code>resources/js/pages/</code> and <code>app/Http/Controllers/</code></li>
                        <li>New routes in <code>routes/api.php</code> if you need them</li>
                        <li>All AI calls through <code>POST /api/ai</code></li>
                        <li>Slicing small — one field on one screen, working end-to-end, is a complete feature</li>
                        <li>Building a capability the app doesn't have yet — new tab, new endpoint, go for it</li>
                    </ul>
                </div>
                <div class="rounded border border-fg-danger-15 bg-fg-danger-9 p-3">
                    <h3 class="text-sm font-semibold text-fg-danger-dark">Out of scope</h3>
                    <ul class="mt-1 list-disc space-y-1 pl-4 text-sm text-fg-dark-grey">
                        <li>Calling Anthropic from the browser / moving the key out of <code>.env</code></li>
                        <li>New composer or yarn packages</li>
                        <li>Auth, deployment, tests, restructuring the app</li>
                        <li>Editing the seeded data to make your problem easier — the mess <em>is</em> the exercise</li>
                    </ul>
                </div>
            </div>
        </section>

        <!-- Jargon -->
        <section class="rounded border border-fg-muted-grey bg-white p-5">
            <h2 class="text-lg font-semibold">Two-minute jargon cheat sheet</h2>
            <dl class="mt-2 space-y-2 text-sm">
                <div v-for="[term, definition] in jargon" :key="term">
                    <dt class="font-medium">{{ term }}</dt>
                    <dd class="text-fg-mid-grey">{{ definition }}</dd>
                </div>
            </dl>
        </section>

        <!-- Run sheet -->
        <section class="rounded border border-fg-muted-grey bg-white p-5">
            <h2 class="text-lg font-semibold">Time management guideline</h2>
            <table class="mt-2 w-full text-sm">
                <tbody>
                    <tr v-for="[time, what] in runSheet" :key="time" class="border-t border-fg-pale-grey first:border-t-0">
                        <td class="w-28 py-1.5 pr-3 align-top font-mono text-xs text-fg-light-grey">{{ time }}</td>
                        <td class="py-1.5 leading-snug text-fg-dark-grey">{{ what }}</td>
                    </tr>
                </tbody>
            </table>
        </section>
    </div>
</template>
