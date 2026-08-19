# Stock Reconciliation Report Plan

## Goal

Generate an AI-assisted stock reconciliation report from the parser team's
`proposals` JSON. Confirmed proposals are included in totals, while proposals
with `include: false` are excluded from totals and clearly listed for human
review.

## Data contract

Each proposal is expected to contain:

- `record_ids`
- `stock_class` and `stock_class_id`
- `type`: `birth`, `purchase`, `death`, or `sale`
- `quantity`
- `confidence`
- `include`
- `flag`, `note`, and `reasoning`

The existing stock movements returned by `GET /api/stock` remain the source of
truth for movements that have already been keyed. Parser proposals matching an
existing movement must not be counted a second time.

## Implementation

1. Add reusable report preparation helpers that validate parser JSON, separate
   confirmed and review proposals, detect already-keyed movements, and calculate
   reconciliation totals deterministically.
2. Add a report prompt that tells Claude to explain the supplied calculations
   without changing, inventing, or rebalancing any number.
3. Add a report panel to the Stock Reconciliation page and consume the live
   response from `POST /api/stock/parse`.
4. Show the generated report, confirmed/excluded counts, and every item requiring
   review. Preserve the parser's flag and reasoning in the UI.
5. Handle malformed JSON and API failures without losing the pasted parser data.

## Verification

- Build the Vue application.
- Verify `include: true` proposals affect totals and `include: false` proposals do
  not.
- Verify review items appear in notes.
- Verify the existing 1,240-lamb docking movement is not counted twice.
- Verify Claude receives pre-calculated totals rather than being asked to do the
  arithmetic itself.

## Status

- Completed the proposal parser, deterministic calculations, duplicate protection,
  report prompt, review notes, and page UI.
- Verified the production frontend build and the PHP test suite.
- Verified the page with the parser team's sample JSON: 14 proposals were added,
  3 were sent to review, and the existing lamb movement was not counted twice.
- Pending: add a real `ANTHROPIC_API_KEY` to `.env` to verify the final Claude-written
  commentary.
- Integrated the report generator with the parser team's live response from
  `POST /api/stock/parse`; no manual JSON paste step is required.
