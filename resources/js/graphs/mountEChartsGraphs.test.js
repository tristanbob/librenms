import { describe, test, expect } from 'vitest';
import { currentDataZoomSpanMs, shouldBlockMaxZoomWheel } from './mountEChartsGraphs.js';

const PAYLOAD = {
    graph: {
        from: 1000000,
        to:   1003600,
        step: 300,
    },
};

function fakeChart(dataZoom) {
    return {
        getOption: () => ({ dataZoom: [dataZoom] }),
    };
}

describe('currentDataZoomSpanMs', () => {
    test('uses dataZoom startValue and endValue when present', () => {
        const span = currentDataZoomSpanMs(fakeChart({
            startValue: 1000000000,
            endValue:   1000300000,
        }), PAYLOAD);

        expect(span).toBe(300000);
    });

    test('falls back to percentage span over the graph time range', () => {
        const span = currentDataZoomSpanMs(fakeChart({
            start: 25,
            end:   75,
        }), PAYLOAD);

        expect(span).toBe(1800000);
    });

    test('falls back to full graph span when dataZoom range is absent', () => {
        const span = currentDataZoomSpanMs(fakeChart({}), PAYLOAD);

        expect(span).toBe(3600000);
    });

    test('ignores null startValue and endValue from unset dataZoom state', () => {
        const span = currentDataZoomSpanMs(fakeChart({
            startValue: null,
            endValue:   null,
            start:      0,
            end:        100,
        }), PAYLOAD);

        expect(span).toBe(3600000);
    });
});

describe('shouldBlockMaxZoomWheel', () => {
    test('blocks zoom-in wheel events at the minimum zoom span', () => {
        const event = { deltaY: -1 };
        const chart = fakeChart({
            startValue: 1000000000,
            endValue:   1003000000,
        });

        expect(shouldBlockMaxZoomWheel(event, chart, PAYLOAD)).toBe(true);
    });

    test('allows zoom-out wheel events at the minimum zoom span', () => {
        const event = { deltaY: 1 };
        const chart = fakeChart({
            startValue: 1000000000,
            endValue:   1003000000,
        });

        expect(shouldBlockMaxZoomWheel(event, chart, PAYLOAD)).toBe(false);
    });

    test('allows zoom-in wheel events above the minimum zoom span', () => {
        const event = { deltaY: -1 };
        const chart = fakeChart({
            startValue: 1000000000,
            endValue:   1003300000,
        });

        expect(shouldBlockMaxZoomWheel(event, chart, PAYLOAD)).toBe(false);
    });
});
