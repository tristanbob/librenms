import { describe, test, expect } from 'vitest';
import { toEChartsOptions } from './toEChartsOptions.js';
import { formatValue, formatNumber } from './formatUnits.js';

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

    test('dark mode applies exact RRD dark background color', () => {
        const opts = toEChartsOptions(FIXTURE, { dark: true });
        expect(opts.backgroundColor).toBe('#2e3338');
        expect(opts.xAxis.axisLabel.color).toBe('#f8f9f9');
    });

    test('light mode uses transparent background', () => {
        const opts = toEChartsOptions(FIXTURE, { dark: false });
        expect(opts.backgroundColor).toBe('transparent');
        expect(opts.xAxis.axisLabel.color).toBe('#000000');
    });

    test('echarts legend is always hidden (stats rendered as html below the canvas)', () => {
        expect(toEChartsOptions(FIXTURE).legend.show).toBe(false);
    });

    test('area opacity matches RRD colourAalpha hex 33 (~20%)', () => {
        const opts = toEChartsOptions(FIXTURE);
        expect(opts.series[0].areaStyle.opacity).toBe(0.2);
    });

    test('title is always hidden (section heading serves as title)', () => {
        const opts = toEChartsOptions(FIXTURE);
        expect(opts.title.show).toBe(false);
    });

    test('yAxis tick formatter uses formatNumber (no unit)', () => {
        const opts = toEChartsOptions(FIXTURE);
        // Should return a number + optional SI prefix, no unit word
        const result = opts.yAxis.axisLabel.formatter(3.0);
        expect(result).toBe('3.0');
        expect(result).not.toContain('seconds');
    });
});

describe('formatNumber', () => {
    test('returns compact number without unit', () => {
        expect(formatNumber(1500, 2)).toBe('1.50k');
        expect(formatNumber(3.0, 1)).toBe('3.0');
    });

    test('sub-1 values do not produce undefined (tier clamp fix)', () => {
        const result = formatNumber(0.5, 2);
        expect(result).not.toContain('undefined');
        expect(result).toBe('0.50');
    });

    test('zero does not produce undefined', () => {
        expect(formatNumber(0, 2)).toBe('0.00');
    });

    test('null returns N/A', () => {
        expect(formatNumber(null)).toBe('N/A');
    });
});

describe('formatValue', () => {
    test('sub-1 values do not produce undefined', () => {
        const result = formatValue(0.05, 'seconds', 2);
        expect(result).not.toContain('undefined');
        expect(result).toBe('0.05 seconds');
    });
});
