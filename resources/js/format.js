// Small shared formatting helpers.

/** Format a number as NZ dollars, e.g. 1234.5 -> "$1,234.50". */
export function money(value) {
    return new Intl.NumberFormat('en-NZ', {
        style: 'currency',
        currency: 'NZD',
    }).format(value);
}

/** Format an ISO date string as e.g. "14 Feb 2026". */
export function shortDate(iso) {
    return new Date(iso).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

/** Format an ISO month string as e.g. "Feb 2026". */
export function monthName(iso) {
    return new Date(iso).toLocaleDateString('en-NZ', {
        month: 'short',
        year: 'numeric',
    });
}
