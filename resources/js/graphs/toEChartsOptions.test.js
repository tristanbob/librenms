import { describe, test, expect } from 'vitest';
import { toEChartsOptions } from './toEChartsOptions.js';

const FIXTURE = {
    status: 'ok',
    graph: {
        type:     'device_poller_perf',
        title:    'Poller Performance',
        subtitle: 'router1',
        unit:     'seconds',
        from:     1000000,
        to:       1003600,
        step:     300,
        display:  { renderer: 'timeseries', kind: 'line', stacked: false, area: true },
        x_axis:   { type: 'time' },
        y_axis:   { unit: 'seconds', scale: 'linear', min: null, max: null },
        series: [{
            name:  'Poller time',
            key:   'poller_time',
            type:  'line',
            unit:  'seconds',
            data:  [[1000000000, 12.5]],
            style: { area: true, stack: null },
            stats: { min: 10, max: 15, avg: 12.5, last: 12.5 },
        }],
        markers:    [],
        thresholds: [],
        meta: { source: 'rrd', fallback_used: false, generated_at: 1003600 },
    },
};

describe('toEChartsOptions', () => {
    test('produces one series per graph series', () => {
        const opts = toEChartsOptions(FIXTURE);
        expect(opts.series).toHaveLength(1);
        expect(opts.series[0].type).toBe('line');
        expect(opts.series[0].data).toEqual([[1000000000, 12.5]]);
    });

    test('animation is false', () => {
        expect(toEChartsOptions(FIXTURE).animation).toBe(false);
    });

    test('xAxis type is time', () => {
        expect(toEChartsOptions(FIXTURE).xAxis.type).toBe('time');
    });

    test('markLine is added when markers are present', () => {
        const withMarker = {
            ...FIXTURE,
            graph: {
                ...FIXTURE.graph,
                markers: [{ value: 5, name: 'Warning' }],
            },
        };
        const opts = toEChartsOptions(withMarker);
        expect(opts.series[0].markLine.data).toHaveLength(1);
        expect(opts.series[0].markLine.data[0].yAxis).toBe(5);
    });

    test('no markLine when markers array is empty', () => {
        const opts = toEChartsOptions(FIXTURE);
        expect(opts.series[0].markLine).toBeUndefined();
    });
});
