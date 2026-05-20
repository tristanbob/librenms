const SI_PREFIXES = ['', 'k', 'M', 'G', 'T', 'P'];

function tier(absVal) {
    if (absVal === 0) return 0;
    return Math.max(0, Math.min(
        Math.floor(Math.log10(absVal) / 3),
        SI_PREFIXES.length - 1
    ));
}

// Number + SI prefix only, no unit — for axis ticks and compact legend columns.
export function formatNumber(value, decimals = 2) {
    if (value === null || value === undefined || isNaN(value)) return 'N/A';
    const t = tier(Math.abs(value));
    const scaled = value / Math.pow(1000, t);
    return `${scaled.toFixed(decimals)}${SI_PREFIXES[t]}`;
}

// Number + SI prefix + unit — for tooltips and axis name labels.
export function formatValue(value, unit, decimals = 2) {
    if (value === null || value === undefined || isNaN(value)) return 'N/A';
    const t = tier(Math.abs(value));
    const scaled = value / Math.pow(1000, t);
    return `${scaled.toFixed(decimals)} ${SI_PREFIXES[t]}${unit}`;
}
