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
            style: { area: true, stack: null, color: '663399', areaOpacity: 0.2 },
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

    test('honors bar display kind for series without an explicit type', () => {
        const fixture = {
            ...FIXTURE,
            graph: {
                ...FIXTURE.graph,
                display: { ...FIXTURE.graph.display, kind: 'bar' },
                series: [{ ...FIXTURE.graph.series[0], type: undefined }],
            },
        };
        const opts = toEChartsOptions(fixture);
        expect(opts.series[0].type).toBe('bar');
        expect(opts.series[0].areaStyle).toBeUndefined();
    });

    test('animation is false', () => {
        expect(toEChartsOptions(FIXTURE).animation).toBe(false);
    });

    test('xAxis type is time', () => {
        expect(toEChartsOptions(FIXTURE).xAxis.type).toBe('time');
    });

    test('xAxis does not pad the time range', () => {
        expect(toEChartsOptions(FIXTURE).xAxis.boundaryGap).toBe(false);
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

    test('warning markers use orange color', () => {
        const withWarning = {
            ...FIXTURE,
            graph: { ...FIXTURE.graph, markers: [{ value: 70, name: 'High warning', severity: 'warning' }] },
        };
        const opts = toEChartsOptions(withWarning);
        expect(opts.series[0].markLine.data[0].lineStyle.color).toBe('#FF8800');
    });

    test('critical markers use red color', () => {
        const withCritical = {
            ...FIXTURE,
            graph: { ...FIXTURE.graph, markers: [{ value: 80, name: 'High critical', severity: 'critical' }] },
        };
        const opts = toEChartsOptions(withCritical);
        expect(opts.series[0].markLine.data[0].lineStyle.color).toBe('#FF0000');
    });

    test('mixed severity markers each get their own color', () => {
        const withMixed = {
            ...FIXTURE,
            graph: {
                ...FIXTURE.graph,
                markers: [
                    { value: 10, name: 'Low critical',  severity: 'critical' },
                    { value: 20, name: 'Low warning',   severity: 'warning' },
                    { value: 70, name: 'High warning',  severity: 'warning' },
                    { value: 80, name: 'High critical', severity: 'critical' },
                ],
            },
        };
        const opts = toEChartsOptions(withMixed);
        const colors = opts.series[0].markLine.data.map(d => d.lineStyle.color);
        expect(colors).toEqual(['#FF0000', '#FF8800', '#FF8800', '#FF0000']);
    });

    test('marker without severity falls back to red', () => {
        const withNoSeverity = {
            ...FIXTURE,
            graph: { ...FIXTURE.graph, markers: [{ value: 5, name: 'Threshold' }] },
        };
        const opts = toEChartsOptions(withNoSeverity);
        expect(opts.series[0].markLine.data[0].lineStyle.color).toBe('#FF0000');
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

    test('echarts legend stays hidden when the payload requests a legend', () => {
        const fixture = {
            ...FIXTURE,
            graph: {
                ...FIXTURE.graph,
                display: { ...FIXTURE.graph.display, legend: true },
            },
        };

        const opts = toEChartsOptions(fixture);
        expect(opts.legend.show).toBe(false);
        expect(opts.grid.bottom).toBe('5%');
    });

    test('area opacity comes from style.areaOpacity', () => {
        const opts = toEChartsOptions(FIXTURE);
        expect(opts.series[0].areaStyle.opacity).toBe(0.2);
    });

    test('title is always hidden (section heading serves as title)', () => {
        const opts = toEChartsOptions(FIXTURE);
        expect(opts.title.show).toBe(false);
    });

    test('dataZoom is always an empty array', () => {
        const opts = toEChartsOptions(FIXTURE);
        expect(opts.dataZoom).toHaveLength(0);
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

describe('toEChartsOptions — two-series (port_bits)', () => {
    const TWO_SERIES = {
        status: 'ok',
        graph: {
            ...FIXTURE.graph,
            type:  'port_bits',
            unit:  'bps',
            series: [
                {
                    name: 'In',  key: 'bits_in',  type: 'line', unit: 'bps',
                    data: [[1000000000, 100000.0]],
                    style: { area: true, stack: null, color: '90B040', lineColor: '608720', areaOpacity: 1.0 },
                    stats: { min: 80000, max: 120000, avg: 100000, last: 100000 },
                },
                {
                    name: 'Out', key: 'bits_out', type: 'line', unit: 'bps',
                    data: [[1000000000, 50000.0]],
                    style: { area: true, stack: null, color: '8080C0', lineColor: '606090', areaOpacity: 1.0, negate: true },
                    stats: { min: 40000, max: 60000, avg: 50000, last: 50000 },
                },
            ],
            markers: [],
        },
    };

    test('produces two series for In and Out', () => {
        const opts = toEChartsOptions(TWO_SERIES);
        expect(opts.series).toHaveLength(2);
        expect(opts.series[0].name).toBe('In');
        expect(opts.series[1].name).toBe('Out');
    });

    test('uses lineColor for line and color for area fill', () => {
        const opts = toEChartsOptions(TWO_SERIES);
        expect(opts.series[0].lineStyle.color).toBe('#608720');
        expect(opts.series[0].itemStyle.color).toBe('#90B040');
        expect(opts.series[1].lineStyle.color).toBe('#606090');
        expect(opts.series[1].itemStyle.color).toBe('#8080C0');
    });

    test('area opacity comes from style.areaOpacity per series', () => {
        const opts = toEChartsOptions(TWO_SERIES);
        expect(opts.series[0].areaStyle.opacity).toBe(1.0);
        expect(opts.series[1].areaStyle.opacity).toBe(1.0);
    });

    test('line opacity defaults to fully opaque', () => {
        const opts = toEChartsOptions(TWO_SERIES);
        expect(opts.series[0].lineStyle.opacity).toBe(1.0);
    });

    test('line opacity comes from style.lineOpacity when present', () => {
        const fixture = {
            ...TWO_SERIES,
            graph: {
                ...TWO_SERIES.graph,
                series: [
                    { ...TWO_SERIES.graph.series[0], style: { ...TWO_SERIES.graph.series[0].style, lineOpacity: 0.5333333333333333 } },
                ],
            },
        };

        const opts = toEChartsOptions(fixture);
        expect(opts.series[0].lineStyle.opacity).toBe(0.5333333333333333);
    });

    test('tooltip formatter lists both series names', () => {
        const opts = toEChartsOptions(TWO_SERIES);
        const fakeParams = [
            { value: [1000000000, 100000], seriesName: 'In' },
            { value: [1000000000, -50000], seriesName: 'Out' },
        ];
        const html = opts.tooltip.formatter(fakeParams);
        expect(html).toContain('In');
        expect(html).toContain('Out');
    });

    test('Out series data is negated when style.negate is true', () => {
        const opts = toEChartsOptions(TWO_SERIES);
        expect(opts.series[1].data[0][1]).toBe(-50000.0);
        expect(opts.series[0].data[0][1]).toBe(100000.0);
    });

    test('tooltip shows absolute value for negated Out series', () => {
        const opts = toEChartsOptions(TWO_SERIES);
        const fakeParams = [
            { value: [1000000000, 100000], seriesName: 'In' },
            { value: [1000000000, -50000], seriesName: 'Out' },
        ];
        const html = opts.tooltip.formatter(fakeParams);
        expect(html).not.toContain('-50');
    });
});

describe('formatValue', () => {
    test('sub-1 values do not produce undefined', () => {
        const result = formatValue(0.05, 'seconds', 2);
        expect(result).not.toContain('undefined');
        expect(result).toBe('0.05 seconds');
    });
});
