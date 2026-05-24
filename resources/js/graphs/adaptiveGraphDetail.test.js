import { describe, test, expect } from 'vitest';
import {
    buildGraphDataUrl,
    computeExpandedRange,
    computeInitialBufferedRange,
    desiredStepMs,
    estimatePayloadStepMs,
    estimateSeriesStepMs,
    isDetailFiner,
    isFullRangeView,
    mergeDetailPayload,
    retainOverlappingDetailRanges,
    rangeFromGraphDataUrl,
    shouldFetchDetail,
    shouldSuppressExpansion,
    visibleRangeFromDataZoom,
    zoomStateForRange,
} from './adaptiveGraphDetail.js';

function fakeChart(dataZoom) {
    return {
        getOption: () => ({ dataZoom: [dataZoom] }),
    };
}

const BASE_PAYLOAD = {
    status: 'ok',
    graph: {
        from: 1000,
        to:   4600,
        step: 1200,
        series: [
            {
                key: 'in',
                name: 'In',
                data: [
                    [1000000, 1],
                    [2200000, 2],
                    [3400000, 3],
                    [4600000, 4],
                ],
            },
            {
                key: 'out',
                name: 'Out',
                data: [
                    [1000000, 4],
                    [2200000, 3],
                    [3400000, 2],
                    [4600000, 1],
                ],
            },
        ],
    },
};

describe('buildGraphDataUrl', () => {
    test('replaces graph query params while preserving unrelated params', () => {
        const url = buildGraphDataUrl('/graph-data/ports/1/graphs/port_bits?from=1&to=2&foo=bar', {
            from: 100,
            to: 200,
            width: 800,
        });

        expect(url).toBe('/graph-data/ports/1/graphs/port_bits?from=100&to=200&foo=bar&width=800');
    });

    test('extracts a range from graph data url params', () => {
        expect(rangeFromGraphDataUrl('/graph-data/ports/1/graphs/port_bits?from=100&to=200')).toEqual({
            from: 100,
            to:   200,
        });
    });
});

describe('visibleRangeFromDataZoom', () => {
    test('uses value-based dataZoom state', () => {
        const range = visibleRangeFromDataZoom(fakeChart({
            startValue: 1500000,
            endValue:   2500000,
        }), BASE_PAYLOAD);

        expect(range).toEqual({ from: 1500, to: 2500 });
    });

    test('uses percentage-based dataZoom state', () => {
        const range = visibleRangeFromDataZoom(fakeChart({
            start: 25,
            end:   75,
        }), BASE_PAYLOAD);

        expect(range).toEqual({ from: 1900, to: 3700 });
    });
});

describe('range expansion', () => {
    test('detects a view covering nearly the full loaded range', () => {
        expect(isFullRangeView({ from: 1000, to: 1950 }, { from: 1000, to: 2000 })).toBe(true);
        expect(isFullRangeView({ from: 1200, to: 1800 }, { from: 1000, to: 2000 })).toBe(false);
    });

    test('doubles the loaded span when expanding', () => {
        expect(computeExpandedRange({
            from: 1000,
            to: 4600,
        }, {
            nowSeconds: 10000,
            originalTo: 10000,
        })).toEqual({ from: -800, to: 6400 });
    });

    test('right-anchors expansion near now', () => {
        expect(computeExpandedRange({
            from: 1000,
            to: 4600,
        }, {
            nowSeconds: 4700,
            originalTo: 4600,
        })).toEqual({ from: -2600, to: 4600 });
    });

    test('expands historical ranges around their center', () => {
        expect(computeExpandedRange({
            from: 1000,
            to: 4600,
        }, {
            nowSeconds: 100000,
            originalTo: 100000,
        })).toEqual({ from: -800, to: 6400 });
    });

    test('clamps expansion to the backend maximum range', () => {
        const expanded = computeExpandedRange({
            from: 0,
            to: 4000,
        }, {
            nowSeconds: 4000,
            originalTo: 4000,
            maxRangeSeconds: 5000,
        });

        expect(expanded.to - expanded.from).toBe(5000);
        expect(expanded).toEqual({ from: -1000, to: 4000 });
    });

    test('builds a right-anchored initial buffer near now', () => {
        expect(computeInitialBufferedRange({
            from: 1000,
            to:   4600,
        }, {
            nowSeconds: 4600,
        })).toEqual({ from: -2600, to: 4600 });
    });

    test('builds a centered initial buffer for historical ranges', () => {
        expect(computeInitialBufferedRange({
            from: 1000,
            to:   4600,
        }, {
            nowSeconds: 100000,
        })).toEqual({ from: -800, to: 6400 });
    });

    test('creates value-based dataZoom state for a display range', () => {
        expect(zoomStateForRange({ from: 1000, to: 4600 })).toEqual({
            startValue: 1000000,
            endValue:   4600000,
        });
    });

    test('suppresses recently failed expansion ranges', () => {
        const cache = [{ from: 0, to: 1000, expiresAt: 2000 }];

        expect(shouldSuppressExpansion({ from: 500, to: 1500 }, cache, 1000)).toBe(true);
        expect(shouldSuppressExpansion({ from: 500, to: 1500 }, cache, 3000)).toBe(false);
    });

    test('retains only detail ranges overlapping the expanded base range', () => {
        const cache = [
            { from: 0, to: 1000, payload: {} },
            { from: 2000, to: 3000, payload: {} },
        ];

        expect(retainOverlappingDetailRanges(cache, { from: 500, to: 2500 })).toEqual(cache);
        expect(retainOverlappingDetailRanges(cache, { from: 1100, to: 1900 })).toEqual([]);
    });
});

describe('step estimation and fetch gating', () => {
    test('desired step follows range divided by width with minimum step', () => {
        expect(desiredStepMs({ from: 0, to: 3600 }, 1200)).toBe(300000);
        expect(desiredStepMs({ from: 0, to: 63072000 }, 1200)).toBe(52560000);
    });

    test('estimates series point spacing with the median gap', () => {
        const step = estimateSeriesStepMs({
            data: [
                [1000000, 1],
                [1300000, 2],
                [1600000, 3],
                [2800000, 4],
            ],
        });

        expect(step).toBe(300000);
    });

    test('estimates payload spacing in a selected range', () => {
        expect(estimatePayloadStepMs(BASE_PAYLOAD, { from: 1000, to: 4600 })).toBe(1200000);
    });

    test('fetches only when desired step is meaningfully finer', () => {
        expect(shouldFetchDetail(1200000, 300000, [], { from: 1000, to: 2000 })).toBe(true);
        expect(shouldFetchDetail(1200000, 1000000, [], { from: 1000, to: 2000 })).toBe(false);
    });

    test('no-detail cached ranges suppress redundant fetches', () => {
        const noDetailCache = [{ from: 1000, to: 2000, desiredStepMs: 300000 }];

        expect(shouldFetchDetail(1200000, 300000, noDetailCache, { from: 1200, to: 1800 })).toBe(false);
    });

    test('detects whether returned detail is actually finer', () => {
        expect(isDetailFiner(1200000, 300000)).toBe(true);
        expect(isDetailFiner(1200000, 1000000)).toBe(false);
    });
});

describe('mergeDetailPayload', () => {
    test('replaces coarse points inside the detail range and keeps outside points', () => {
        const detailPayload = {
            status: 'ok',
            graph: {
                from: 1800,
                to:   3000,
                step: 300,
                series: [{
                    key: 'in',
                    name: 'Inbound',
                    data: [
                        [1900000, 20],
                        [2200000, 21],
                        [2500000, 22],
                    ],
                }],
            },
        };

        const merged = mergeDetailPayload(BASE_PAYLOAD, detailPayload);

        expect(merged.graph.series[0].data).toEqual([
            [1000000, 1],
            [1900000, 20],
            [2200000, 21],
            [2500000, 22],
            [3400000, 3],
            [4600000, 4],
        ]);
        expect(merged.graph.series[1].data).toEqual(BASE_PAYLOAD.graph.series[1].data);
    });

    test('matches detail series by key rather than display name', () => {
        const detailPayload = {
            status: 'ok',
            graph: {
                from: 1800,
                to:   2400,
                series: [{
                    key: 'out',
                    name: 'Different label',
                    data: [[1900000, 99]],
                }],
            },
        };

        const merged = mergeDetailPayload(BASE_PAYLOAD, detailPayload);

        expect(merged.graph.series[1].data).toContainEqual([1900000, 99]);
    });
});
