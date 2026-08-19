const MOVEMENT_TYPES = ['birth', 'purchase', 'death', 'sale'];

export function parseProposalPayload(rawPayload) {
    const payload = typeof rawPayload === 'string' ? JSON.parse(rawPayload) : rawPayload;
    const proposals = Array.isArray(payload) ? payload : payload?.proposals;

    if (!Array.isArray(proposals)) {
        throw new Error('Expected an object containing a proposals array.');
    }

    return proposals.map((proposal, index) => normalizeProposal(proposal, index));
}

function normalizeProposal(proposal, index) {
    if (!proposal || typeof proposal !== 'object') {
        throw new Error(`Proposal ${index + 1} must be an object.`);
    }

    const quantity = Number(proposal.quantity);
    const stockClassId = Number(proposal.stock_class_id);
    const confidence = Number(proposal.confidence);

    if (!Number.isInteger(stockClassId) || stockClassId < 1) {
        throw new Error(`Proposal ${index + 1} has an invalid stock_class_id.`);
    }
    if (!MOVEMENT_TYPES.includes(proposal.type)) {
        throw new Error(`Proposal ${index + 1} has an invalid movement type.`);
    }
    if (!Number.isInteger(quantity) || quantity < 1) {
        throw new Error(`Proposal ${index + 1} has an invalid quantity.`);
    }
    if (typeof proposal.include !== 'boolean') {
        throw new Error(`Proposal ${index + 1} must have a Boolean include value.`);
    }

    return {
        record_ids: Array.isArray(proposal.record_ids) ? proposal.record_ids : [],
        stock_class: String(proposal.stock_class ?? ''),
        stock_class_id: stockClassId,
        type: proposal.type,
        confidence: Number.isFinite(confidence) ? confidence : null,
        quantity,
        note: String(proposal.note ?? ''),
        include: proposal.include,
        flag: proposal.flag ? String(proposal.flag) : null,
        reasoning: String(proposal.reasoning ?? ''),
    };
}

export function prepareReconciliationReport(stockClasses, proposals, unresolved = []) {
    const classById = new Map(stockClasses.map((stockClass) => [Number(stockClass.id), stockClass]));

    proposals.forEach((proposal) => {
        if (!classById.has(proposal.stock_class_id)) {
            throw new Error(`Unknown stock_class_id ${proposal.stock_class_id} in parser output.`);
        }
    });

    const reviewProposals = proposals.filter((proposal) => !proposal.include);
    const includedProposals = proposals.filter((proposal) => proposal.include);
    const unresolvedRecords = normalizeUnresolved(unresolved);
    const alreadyKeyed = includedProposals.filter((proposal) =>
        isAlreadyKeyed(classById.get(proposal.stock_class_id), proposal),
    );
    const acceptedProposals = includedProposals.filter((proposal) => !alreadyKeyed.includes(proposal));

    const classes = stockClasses.map((stockClass) => {
        const existingMovements = stockClass.movements ?? [];
        const proposedMovements = acceptedProposals.filter(
            (proposal) => proposal.stock_class_id === Number(stockClass.id),
        );
        const allIncludedMovements = [...existingMovements, ...proposedMovements];
        const sum = (type) =>
            allIncludedMovements
                .filter((movement) => movement.type === type)
                .reduce((total, movement) => total + Number(movement.quantity), 0);

        const births = sum('birth');
        const purchases = sum('purchase');
        const deaths = sum('death');
        const sales = sum('sale');
        const calculatedClosing = Number(stockClass.opening_count) + births + purchases - deaths - sales;
        const difference = calculatedClosing - Number(stockClass.closing_count);

        return {
            stock_class: stockClass.name,
            opening: Number(stockClass.opening_count),
            births,
            purchases,
            deaths,
            sales,
            calculated_closing: calculatedClosing,
            recorded_closing: Number(stockClass.closing_count),
            difference,
            status: difference === 0 ? 'reconciled' : 'unreconciled',
        };
    });

    return {
        classes,
        accepted_proposals: acceptedProposals,
        review_proposals: reviewProposals,
        unresolved: unresolvedRecords,
        already_keyed: alreadyKeyed,
        counts: {
            parser_proposals: proposals.length,
            included_by_parser: includedProposals.length,
            added_to_report: acceptedProposals.length,
            needs_review: reviewProposals.length + unresolvedRecords.length,
            already_keyed: alreadyKeyed.length,
        },
    };
}

function normalizeUnresolved(unresolved) {
    if (!Array.isArray(unresolved)) {
        return [];
    }

    return unresolved.map((item) => ({
        record_ids: Array.isArray(item?.record_ids) ? item.record_ids : [],
        reason: String(item?.reason ?? 'No reason supplied.'),
    }));
}

function isAlreadyKeyed(stockClass, proposal) {
    return (stockClass.movements ?? []).some(
        (movement) => movement.type === proposal.type && Number(movement.quantity) === proposal.quantity,
    );
}

export function buildReconciliationPrompt(reportData) {
    return `Write a concise livestock reconciliation report for a New Zealand rural accountant.

The JSON below contains deterministic calculations made by the application. Treat every number as fixed. Do not recalculate, alter, invent, or omit any movement in an attempt to make a stock class reconcile.

Report requirements:
1. Start with a short overall summary.
2. Give each stock class its own section and state its opening count, births, purchases, deaths, sales, calculated closing, recorded closing, difference, and status.
3. Include a clearly labelled "Needs review" section. Every item in review_proposals and unresolved must appear there and must not be described as included in confirmed totals.
4. Explain each review proposal's record IDs, proposed movement, confidence, flag, and reasoning in plain language. For unresolved records, explain the supplied reason.
5. Mention already_keyed items separately so the reader knows they were counted through existing movements and not added twice.
6. If a stock class is unreconciled, state the exact difference without proposing an invented balancing movement.
7. Use professional, direct language and plain text. Do not use a Markdown table.

Reconciliation data:
${JSON.stringify(reportData, null, 2)}`;
}
