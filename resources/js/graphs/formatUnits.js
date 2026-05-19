const SI_PREFIXES = ['', 'k', 'M', 'G', 'T', 'P'];

export function formatValue(value, unit, decimals = 2) {
    if (value === null || value === undefined) return 'N/A';
    const absVal = Math.abs(value);
    const tier = absVal === 0 ? 0 : Math.min(
        Math.floor(Math.log10(absVal) / 3),
        SI_PREFIXES.length - 1
    );
    const scaled = value / Math.pow(1000, tier);
    return `${scaled.toFixed(decimals)} ${SI_PREFIXES[tier]}${unit}`;
}
