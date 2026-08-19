# The Figured Great Code Muster

This is the challenge app for **The Figured Great Code Muster**.

Welcome to **Southdown Rural Accountants**, a small (fictional) farm accounting
practice. You're the new graduate adviser, and this is the practice's
management tool. It works. It is also *entirely manual*, and the grunt work is
eating the team alive.

Today's job: feel the pain, then fix some of it with AI.

## The scenario

The practice looks after three farms:

| Farm | Type | Farmer |
| --- | --- | --- |
| Riverbend Dairy Ltd | Dairy | Gary Preston |
| Kahikatea Downs | Sheep & Beef | Kate Molloy |
| Windrow Cropping Ltd | Arable (crops) | Bruce Tanner |

The app has five screens of classic accounting-practice grunt work: coding the
bank feed, answering client emails, writing monthly report commentary, keying
in supplier invoices, and reconciling stock numbers. Each screen has **one item
already completed** — that's the standard your output should match.

## Two-minute jargon cheat sheet

- **Farmer / adviser** — the farmer runs the farm and is the client; the
  adviser (you) does their numbers and answers their questions.
- **Coding** — assigning each bank transaction to an account like "Feed" or
  "Fuel" so reports mean something. Nothing to do with programming.
- **Budget vs actual** — what the farm *planned* to earn/spend each month vs
  what *actually* happened. The difference is the **variance**.
- **Sale docket** — the paper slip a stock agent issues when livestock are
  sold. Often the only record that a sale happened.
- **Stock reconciliation** — proving that opening animals + births + purchases
  − deaths − sales = closing animals. When it doesn't balance, something was
  missed (or counted twice).

## Setup

You need Git, PHP 8.3+, Composer, Node 20.12+, and Yarn. Do this **before the
day** — it's a download-heavy step and the session clock won't wait for it.
Starting from a fresh laptop:

### macOS

Install [Homebrew](https://brew.sh) (its installer also sets up the Xcode
Command Line Tools, which include Git), then the toolchain:

```bash
/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"
brew install php composer node yarn
```

### Windows

1. Open PowerShell and install Git: `winget install Git.Git`
2. Install [Laravel Herd](https://herd.laravel.com/windows) — the free
   version gives you PHP, Composer, and Node in one installer.
3. Open a **new** PowerShell window (so the PATH updates apply) and install
   Yarn: `npm install -g yarn`

### The app

In a fresh terminal:

```bash
git clone https://github.com/FiguredLimited/figured-great-code-muster.git
cd figured-great-code-muster
composer run setup   # installs everything, creates the DB, seeds the data
composer run dev     # starts the app at http://localhost:8000
```

Put the Anthropic API key a Figgie gives you into `.env`:

```
ANTHROPIC_API_KEY=sk-ant-...
```

If you ever want a clean slate: `php artisan migrate:fresh --seed`.

## The brief

1. **Research (the first ~50 minutes).** Do the grunt work by hand — code
   transactions, answer an email, write a month's commentary, key an invoice,
   reconcile a stock class. You will quickly hit things you don't understand:
   farming terms, tax concepts, how milk payments work. **You are not expected
   to know any of this.** Learning it — with AI, with the app's data, with
   whatever works — is part of the exercise. Keep track of how you learnt:
   you'll be asked to walk through it. One tip: the pages aren't islands.
   It's the same three farms everywhere, and the story runs through the whole
   app — what you need for one page often lives on another.
2. **Pick the worst grunt work.** As a team, choose one screen (or one slice of
   one screen) where AI would help the most. If you've spotted something a
   client clearly needs that *no* screen does, building that counts too —
   same rules.
3. **Automate it.** Build your AI feature into the page using the `/api/ai`
   endpoint. There are *no* AI features in the app yet — the AI Example tab is
   the only Claude-calling code in the repo, and it's there for you to copy.
4. **Demo it.** 3–4 minutes per team: the manual pain, what you had to learn
   (and how you learnt it), and your fix. Expect questions about your design
   decisions.

## Where things live

```
routes/api.php                    all API routes (one controller per page)
app/Http/Controllers/             thin controllers - modify freely
app/Http/Controllers/AiController.php   the AI proxy
resources/js/pages/               one Vue component per screen
resources/js/pages/AiExample.vue  the worked example to copy
data/                             the seeded fixture data (see data/README.md)
```

## The `/api/ai` endpoint

The backend proxies to Claude so the API key never reaches the browser.

```
POST /api/ai
{ "system": "optional system prompt", "prompt": "the user/task prompt" }

→ { "text": "Claude's reply" }
```

From a Vue component (this is exactly what `resources/js/pages/AiExample.vue` does):

```js
const { data } = await axios.post('/api/ai', {
    system: 'You are a rural accounting assistant.',
    prompt: 'Suggest a category for: RD1 HAMILTON -$2,478.26',
});
console.log(data.text);
```

Tips: put stable instructions in `system` and the task data in `prompt`; if
you want structured output, ask for JSON and parse it (defensively). You can
send data from the page (transactions, report lines…) inside the prompt.

## Scope

**Your deliverable:** one working AI-assisted improvement to one screen,
demoed on the seeded data. Be ready to explain and defend every design
decision you made.

**In scope**
- Modify anything in `resources/js/pages/` and `app/Http/Controllers/` — the
  code is yours for the afternoon.
- Add routes to `routes/api.php` if your feature needs a new endpoint.
- All AI calls go through `POST /api/ai` (or a new backend endpoint that uses
  the same `config('services.anthropic.key')` pattern).
- Slice the problem small. One field on one screen, working end-to-end, is a
  complete, demoable feature.
- Building a capability the app doesn't have yet, if that's where you think
  the real need is. New tab, new endpoint — go for it.

**Out of scope**
- Calling the Anthropic API directly from the browser, or moving the key
  anywhere out of `.env`. Instant disqualification, and rule #1 below.
- Using the provided API key outside this app. It powers `/api/ai` and
  nothing else — not Claude Code, Cursor, or any other tool. For AI-assisted
  coding and research, use your own accounts.
- New composer/yarn packages — you won't need them, and installs eat your
  build time.
- Auth, deployment, tests, refactoring the app's structure. Nobody is
  marking your architecture.
- Editing the seeded data in `data/` to make your problem easier. The mess
  *is* the exercise.

## What we're looking for

Roughly in order:

- **Problem choice** — real pain, a one-sentence "why", scoped to finishable.
- **What you learnt** — explain something you didn't know at 4pm, and how you
  checked it. We'll ask follow-ups.
- **It works** — end-to-end on the seeded data. Narrow-and-working beats
  broad-and-broken.
- **The demo** — pain → learning → fix, honest about limitations.

## Run sheet

| Time | What you're doing                                                                                                                                                                                                                                                                    |
| --- |--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| 4:00 – 4:10 | Kickoff. Scenario, teams, API keys handed out. Confirm `composer run dev` works and the AI Example tab responds before the clock really starts.                                                                                                                                      |
| 4:10 – 5:00 | **Research.** Do the manual grunt work on every page, and dig into what you don't understand — the terms, the tax concepts, how the money actually moves. Use AI to learn; keep track of what you asked and how you checked the answers. Take notes on what hurts and what looks odd. |
| 5:00 | **Checkpoint — pick your problem.** Two sentences per team: "We're automating X because Y" and "the thing we had to learn to do it properly is Z." Say it out loud to a Figgie.                                                                                                      |
| 5:00 – 5:30 | Build the thin end: get one AI call working on real page data (copy `AiExample.vue`'s pattern).                                                                                                                                                                                      |
| 5:30 | **Mid-point check.** If nothing works on screen yet, shrink scope now, not at 6:15.                                                                                                                                                                                                  |
| 5:30 – 6:20 | Build the rest: wire it into the page and handle the awkward cases you found in the data.                                                                                                                                                                                            |
| 6:00 | **Food arrives.** Grab a plate and keep building — demos still start at 6:30.                                                                                                                                                                                            |
| 6:20 | **Scope freeze.** Stop adding. Fix, polish, and rehearse the demo once end-to-end.                                                                                                                                                                                                   |
| 6:30 | **Pencils down.**                                                                                                                                                                                                                                                                    |
| 6:30 – 7:00 | Demos and debrief — 4–5 minutes per team: the manual pain → what you had to learn (and how) → your fix.                                                                                                                                                                              |
| 7:00 – 7:45 | Open — catch-ups, and overflow if demos run long.                                                                                                                                                                                                                              |
| 7:45 – 8:00 | **Wrap.** Thanks, pack down.                                                                                                                                                                                                                                      |
