export const DEFAULT_MIN_STEP_SECONDS = 300;
export const DETAIL_IMPROVEMENT_RATIO = 0.75;
export const PREFETCH_RATIO = 0.05;
export const MAX_CACHE_RANGES = 8;
export const MAX_EXPANSION_RANGE_SECONDS = 63244800;
export const FULL_RANGE_RATIO = 0.95;
export const EXPANSION_FAILURE_TTL_MS = 60000;
export const INITIAL_BUFFER_MULTIPLIER = 2;

function finiteNumber(value) {
    if (value == null || value === '') return null;

    const numeric = Number(value);

    return Number.isFinite(numeric) ? numeric : null;
}

export function epochToMs(value) {
    const numeric = finiteNumber(value);
    if (numeric == null) return null;

    return Math.abs(numeric) < 100000000000 ? numeric * 1000 : numeric;
}

export function epochToSeconds(value) {
    const numeric = finiteNumber(value);
    if (numeric == null) return null;

    return Math.abs(numeric) < 100000000000 ? numeric : Math.round(numeric / 1000);
}

function graphRangeSeconds(payload) {
    const from = epochToSeconds(payload?.graph?.from);
    const to   = epochToSeconds(payload?.graph?.to);

    if (from == null || to == null) return null;

    return { from: Math.min(from, to), to: Math.max(from, to) };
}

function epochToGraphSeconds(value, bounds) {
    const numeric = finiteNumber(value);
    if (numeric == null) return null;

    if (bounds && numeric >= bounds.from && numeric <= bounds.to) {
        return numeric;
    }

    const asSeconds = Math.round(numeric / 1000);
    if (bounds && asSeconds >= bounds.from && asSeconds <= bounds.to) {
        return asSeconds;
    }

    return epochToSeconds(numeric);
}

function clampRange(range, bounds) {
    if (!range || !bounds) return null;

    const from = Math.max(bounds.from, Math.min(range.from, range.to));
    const to   = Math.min(bounds.to, Math.max(range.from, range.to));

    if (from >= to) return null;

    return { from, to };
}

export function graphRangeFromPayload(payload) {
    return graphRangeSeconds(payload);
}

export function visibleRangeFromDataZoom(chart, fallbackPayload) {
    const zoom = chart?.getOption?.()?.dataZoom?.[0] ?? {};
    const bounds = graphRangeSeconds(fallbackPayload);
    const startValue = epochToGraphSeconds(zoom.startValue, bounds);
    const endValue   = epochToGraphSeconds(zoom.endValue, bounds);

    if (startValue != null && endValue != null) {
        return clampRange({ from: startValue, to: endValue }, bounds) ?? { from: Math.min(startValue, endValue), to: Math.max(startValue, endValue) };
    }

    const start = finiteNumber(zoom.start);
    const end   = finiteNumber(zoom.end);

    if (start != null && end != null && bounds) {
        const span = bounds.to - bounds.from;

        return clampRange({
            from: bounds.from + span * Math.min(start, end) / 100,
            to:   bounds.from + span * Math.max(start, end) / 100,
        }, bounds);
    }

    return bounds;
}

export function paddedRange(range, bounds, ratio = PREFETCH_RATIO) {
    if (!range || !bounds) return null;

    const span = range.to - range.from;
    const pad = span * ratio;

    return clampRange({
        from: Math.floor(range.from - pad),
        to:   Math.ceil(range.to + pad),
    }, bounds);
}

export function buildGraphDataUrl(baseUrl, params = {}) {
    const isAbsolute = /^[a-z][a-z0-9+.-]*:\/\//i.test(baseUrl);
    const origin = typeof window !== 'undefined' && window.location?.origin
        ? window.location.origin
        : 'http://localhost';
    const url = new URL(baseUrl, origin);

    ['from', 'to', 'width', 'height'].forEach((key) => {
        if (params[key] == null || params[key] === '') return;

        url.searchParams.set(key, String(Math.round(Number(params[key]))));
    });

    return isAbsolute ? url.toString() : `${url.pathname}${url.search}${url.hash}`;
}

export function rangeFromGraphDataUrl(baseUrl) {
    const origin = typeof window !== 'undefined' && window.location?.origin
        ? window.location.origin
        : 'http://localhost';
    const url = new URL(baseUrl, origin);
    const from = epochToSeconds(url.searchParams.get('from'));
    const to = epochToSeconds(url.searchParams.get('to'));

    if (from == null || to == null || from >= to) return null;

    return { from, to };
}

export function desiredStepMs(range, width, minStepSeconds = DEFAULT_MIN_STEP_SECONDS) {
    if (!range) return null;

    const graphWidth = Math.max(1, Math.round(Number(width) || 1));
    const stepSeconds = Math.max(minStepSeconds, Math.ceil((range.to - range.from) / graphWidth));

    return stepSeconds * 1000;
}

export function estimateSeriesStepMs(series) {
    const points = series?.data ?? [];
    const diffs = [];

    for (let i = 1; i < points.length; i++) {
        const previous = finiteNumber(points[i - 1]?.[0]);
        const current  = finiteNumber(points[i]?.[0]);
        if (previous == null || current == null) continue;

        const diff = Math.abs(current - previous);
        if (diff > 0) diffs.push(diff);
    }

    if (diffs.length === 0) return null;

    diffs.sort((a, b) => a - b);

    return diffs[Math.floor(diffs.length / 2)];
}

export function estimatePayloadStepMs(payload, range = null) {
    const rangeStartMs = range ? epochToMs(range.from) : null;
    const rangeEndMs   = range ? epochToMs(range.to) : null;
    const steps = (payload?.graph?.series ?? [])
        .map((series) => {
            if (rangeStartMs == null || rangeEndMs == null) return estimateSeriesStepMs(series);

            return estimateSeriesStepMs({
                ...series,
                data: (series.data ?? []).filter(([ts]) => ts >= rangeStartMs && ts <= rangeEndMs),
            });
        })
        .filter((step) => step != null);

    if (steps.length === 0) {
        const nominalStep = finiteNumber(payload?.graph?.step);

        return nominalStep != null ? nominalStep * 1000 : null;
    }

    return Math.min(...steps);
}

function rangesOverlap(a, b) {
    return a && b && a.from < b.to && b.from < a.to;
}

export function rangeOverlaps(a, b) {
    return rangesOverlap(a, b);
}

export function rangeSpan(range) {
    return range ? Math.max(0, range.to - range.from) : 0;
}

export function isFullRangeView(visibleRange, baseRange, ratio = FULL_RANGE_RATIO) {
    const visibleSpan = rangeSpan(visibleRange);
    const baseSpan = rangeSpan(baseRange);

    if (visibleSpan <= 0 || baseSpan <= 0) return false;

    return visibleSpan >= baseSpan * ratio;
}

export function computeExpandedRange(
    currentRange,
    {
        nowSeconds = Math.floor(Date.now() / 1000),
        originalTo = null,
        maxRangeSeconds = MAX_EXPANSION_RANGE_SECONDS,
    } = {}
) {
    if (!currentRange) return null;

    const span = rangeSpan(currentRange);
    if (span <= 0 || span >= maxRangeSeconds) return null;

    const nextSpan = Math.min(maxRangeSeconds, span * 2);
    const edgeTolerance = Math.max(DEFAULT_MIN_STEP_SECONDS, Math.ceil(span * 0.05));
    const anchorTo = [nowSeconds, originalTo]
        .filter((value) => value != null)
        .some((value) => Math.abs(currentRange.to - value) <= edgeTolerance);

    let from;
    let to;
    if (anchorTo) {
        to = currentRange.to;
        from = to - nextSpan;
    } else {
        const center = currentRange.from + span / 2;
        from = Math.floor(center - nextSpan / 2);
        to = from + nextSpan;
    }

    from = Math.round(from);
    to = Math.round(to);

    if (to - from > maxRangeSeconds) {
        if (anchorTo) {
            from = to - maxRangeSeconds;
        } else {
            const center = currentRange.from + span / 2;
            from = Math.round(center - maxRangeSeconds / 2);
            to = from + maxRangeSeconds;
        }
    }

    if (from === currentRange.from && to === currentRange.to) return null;

    return { from, to };
}

export function computeInitialBufferedRange(
    displayRange,
    {
        nowSeconds = Math.floor(Date.now() / 1000),
        maxRangeSeconds = MAX_EXPANSION_RANGE_SECONDS,
        multiplier = INITIAL_BUFFER_MULTIPLIER,
    } = {}
) {
    if (!displayRange) return null;

    const span = rangeSpan(displayRange);
    if (span <= 0) return null;

    const bufferedSpan = Math.min(maxRangeSeconds, Math.max(span, span * multiplier));
    const edgeTolerance = Math.max(DEFAULT_MIN_STEP_SECONDS, Math.ceil(span * 0.05));
    const anchorToNow = Math.abs(displayRange.to - nowSeconds) <= edgeTolerance;

    let from;
    let to;
    if (anchorToNow) {
        to = displayRange.to;
        from = to - bufferedSpan;
    } else {
        const center = displayRange.from + span / 2;
        from = Math.floor(center - bufferedSpan / 2);
        to = from + bufferedSpan;
    }

    return { from: Math.round(from), to: Math.round(to) };
}

export function zoomStateForRange(range) {
    if (!range) return null;

    return {
        startValue: epochToMs(range.from),
        endValue:   epochToMs(range.to),
    };
}

export function shouldSuppressExpansion(range, failedExpansionCache = [], nowMs = Date.now()) {
    return failedExpansionCache.some((entry) => {
        if (entry.expiresAt != null && entry.expiresAt <= nowMs) return false;

        return rangesOverlap(entry, range);
    });
}

export function retainOverlappingDetailRanges(detailCache, baseRange) {
    return detailCache.filter((entry) => rangesOverlap(entry, baseRange));
}

export function shouldFetchDetail(currentStepMs, desiredStepMs, noDetailCache = [], range = null) {
    if (currentStepMs == null || desiredStepMs == null) return false;
    if (desiredStepMs >= currentStepMs * DETAIL_IMPROVEMENT_RATIO) return false;

    return !noDetailCache.some((entry) => {
        if (!rangesOverlap(entry, range)) return false;

        const cachedDesiredStep = finiteNumber(entry.desiredStepMs);

        return cachedDesiredStep == null || desiredStepMs >= cachedDesiredStep * DETAIL_IMPROVEMENT_RATIO;
    });
}

export function isDetailFiner(currentStepMs, detailStepMs) {
    if (currentStepMs == null || detailStepMs == null) return false;

    return detailStepMs < currentStepMs * DETAIL_IMPROVEMENT_RATIO;
}

export function mergeDetailPayload(basePayload, detailPayload, cachedDetails = []) {
    const detailRanges = [...cachedDetails, { payload: detailPayload }];
    const mergedPayload = JSON.parse(JSON.stringify(basePayload));

    mergedPayload.graph.series = (basePayload?.graph?.series ?? []).map((baseSeries) => {
        let mergedData = [...(baseSeries.data ?? [])];

        detailRanges.forEach(({ payload }) => {
            const detailSeries = (payload?.graph?.series ?? []).find((series) => series.key === baseSeries.key);
            const detailData = detailSeries?.data ?? [];
            const fromMs = epochToMs(payload?.graph?.from);
            const toMs = epochToMs(payload?.graph?.to);

            if (detailData.length === 0 || fromMs == null || toMs == null) return;

            mergedData = mergedData
                .filter(([ts]) => ts < fromMs || ts > toMs)
                .concat(detailData);
        });

        mergedData.sort((a, b) => a[0] - b[0]);

        return { ...baseSeries, data: mergedData };
    });

    return mergedPayload;
}

export function trimCache(cache, maxRanges = MAX_CACHE_RANGES) {
    if (cache.length <= maxRanges) return cache;

    return cache.slice(cache.length - maxRanges);
}
