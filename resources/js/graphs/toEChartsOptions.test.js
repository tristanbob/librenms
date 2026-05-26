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
        y_axes:   [{ unit: 'seconds', scale: 'linear', min: null, max: null }],
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

    test('line series keep hover symbols for native cursor interaction', () => {
        const opts = toEChartsOptions(FIXTURE);
        expect(opts.series[0].symbol).toBe('circle');
        expect(opts.series[0].showSymbol).toBe(false);
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

    test('tooltip uses a native axis pointer line', () => {
        const opts = toEChartsOptions(FIXTURE);
        expect(opts.tooltip.trigger).toBe('axis');
        expect(opts.tooltip.axisPointer.type).toBe('line');
        expect(opts.tooltip.axisPointer.snap).toBe(true);
    });

    test('xAxis type is time', () => {
        expect(toEChartsOptions(FIXTURE).xAxis.type).toBe('time');
    });

    test('xAxis does not pad the time range', () => {
        expect(toEChartsOptions(FIXTURE).xAxis.boundaryGap).toBe(false);
    });

    test('xAxis is pinned to requested graph range even when data starts later', () => {
        const opts = toEChartsOptions(FIXTURE);
        expect(opts.xAxis.min).toBe(FIXTURE.graph.from * 1000);
        expect(opts.xAxis.max).toBe(FIXTURE.graph.to * 1000);
    });

    test('sparkline xAxis is also pinned to requested graph range', () => {
        const opts = toEChartsOptions(FIXTURE, { sparkline: true });
        expect(opts.xAxis.min).toBe(FIXTURE.graph.from * 1000);
        expect(opts.xAxis.max).toBe(FIXTURE.graph.to * 1000);
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
        expect(opts.series[opts.series.length - 1].markLine.data).toHaveLength(1);
        expect(opts.series[opts.series.length - 1].markLine.data[0].yAxis).toBe(5);
    });

    test('no markLine when markers array is empty', () => {
        const opts = toEChartsOptions(FIXTURE);
        expect(opts.series[opts.series.length - 1].markLine).toBeUndefined();
    });

    test('markers render without crash when all data series are empty', () => {
        const emptyData = {
            ...FIXTURE,
            graph: {
                ...FIXTURE.graph,
                series:  [],
                markers: [{ value: 80, name: 'High critical', severity: 'critical' }],
            },
        };
        const opts = toEChartsOptions(emptyData);
        const markerSeries = opts.series[opts.series.length - 1];
        expect(markerSeries.markLine.data).toHaveLength(1);
        expect(markerSeries.data).toEqual([]);
    });

    test('warning markers use orange color', () => {
        const withWarning = {
            ...FIXTURE,
            graph: { ...FIXTURE.graph, markers: [{ value: 70, name: 'High warning', severity: 'warning' }] },
        };
        const opts = toEChartsOptions(withWarning);
        expect(opts.series[opts.series.length - 1].markLine.data[0].lineStyle.color).toBe('#FF8800');
    });

    test('critical markers use red color', () => {
        const withCritical = {
            ...FIXTURE,
            graph: { ...FIXTURE.graph, markers: [{ value: 80, name: 'High critical', severity: 'critical' }] },
        };
        const opts = toEChartsOptions(withCritical);
        expect(opts.series[opts.series.length - 1].markLine.data[0].lineStyle.color).toBe('#FF0000');
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
        const colors = opts.series[opts.series.length - 1].markLine.data.map(d => d.lineStyle.color);
        expect(colors).toEqual(['#FF0000', '#FF8800', '#FF8800', '#FF0000']);
    });

    test('direction-aware sensor severity markers use RRD threshold colors', () => {
        const withSensorMarkers = {
            ...FIXTURE,
            graph: {
                ...FIXTURE.graph,
                markers: [
                    { value: 5,  name: 'Low critical',  severity: 'low_critical' },
                    { value: 10, name: 'Low warning',   severity: 'low_warning' },
                    { value: 70, name: 'High warning',  severity: 'high_warning' },
                    { value: 80, name: 'High critical', severity: 'high_critical' },
                ],
            },
        };
        const opts = toEChartsOptions(withSensorMarkers);
        const colors = opts.series[opts.series.length - 1].markLine.data.map(d => d.lineStyle.color);
        expect(colors).toEqual(['#00008b', '#005bdf', '#ffa420', '#ff0000']);
    });

    test('limit severity uses semi-transparent red matching wireless RRD #cc000060', () => {
        const withLimit = {
            ...FIXTURE,
            graph: { ...FIXTURE.graph, markers: [{ value: 100, name: 'High limit', severity: 'limit' }] },
        };
        const opts = toEChartsOptions(withLimit);
        const line = opts.series[opts.series.length - 1].markLine.data[0].lineStyle;
        expect(line.color).toBe('#cc0000');
        expect(line.opacity).toBeCloseTo(0.376, 2);
    });

    test('non-limit markers have full opacity', () => {
        const withCritical = {
            ...FIXTURE,
            graph: { ...FIXTURE.graph, markers: [{ value: 80, name: 'High critical', severity: 'high_critical' }] },
        };
        const opts = toEChartsOptions(withCritical);
        expect(opts.series[opts.series.length - 1].markLine.data[0].lineStyle.opacity).toBe(1.0);
    });

    test('theme-ink color in light mode resolves to legacy sensor line color #272b30', () => {
        const themeInkFixture = {
            ...FIXTURE,
            graph: {
                ...FIXTURE.graph,
                series: [{ ...FIXTURE.graph.series[0], style: { ...FIXTURE.graph.series[0].style, color: 'theme-ink' } }],
            },
        };
        const opts = toEChartsOptions(themeInkFixture, { dark: false });
        expect(opts.series[0].lineStyle.color).toBe('#272b30');
        expect(opts.series[0].itemStyle.color).toBe('#272b30');
    });

    test('theme-ink color in dark mode resolves to legacy sensor line color #f2f2f2', () => {
        const themeInkFixture = {
            ...FIXTURE,
            graph: {
                ...FIXTURE.graph,
                series: [{ ...FIXTURE.graph.series[0], style: { ...FIXTURE.graph.series[0].style, color: 'theme-ink' } }],
            },
        };
        const opts = toEChartsOptions(themeInkFixture, { dark: true });
        expect(opts.series[0].lineStyle.color).toBe('#f2f2f2');
        expect(opts.series[0].itemStyle.color).toBe('#f2f2f2');
    });

    test('theme-ink lineColor token resolves when fill color differs', () => {
        const themeInkFixture = {
            ...FIXTURE,
            graph: {
                ...FIXTURE.graph,
                series: [{ ...FIXTURE.graph.series[0], style: { ...FIXTURE.graph.series[0].style, color: '0000cc', lineColor: 'theme-ink' } }],
            },
        };
        const opts = toEChartsOptions(themeInkFixture, { dark: false });
        expect(opts.series[0].lineStyle.color).toBe('#272b30');
        expect(opts.series[0].itemStyle.color).toBe('#0000cc');
    });

    test('lineWidth comes from style.lineWidth', () => {
        const fixture = {
            ...FIXTURE,
            graph: {
                ...FIXTURE.graph,
                series: [{ ...FIXTURE.graph.series[0], style: { ...FIXTURE.graph.series[0].style, lineWidth: 2.0 } }],
            },
        };
        const opts = toEChartsOptions(fixture);
        expect(opts.series[0].lineStyle.width).toBe(2.0);
    });

    test('lineWidth defaults to 1.25 when not set', () => {
        const opts = toEChartsOptions(FIXTURE);
        expect(opts.series[0].lineStyle.width).toBe(1.25);
    });

    test('marker without severity falls back to red', () => {
        const withNoSeverity = {
            ...FIXTURE,
            graph: { ...FIXTURE.graph, markers: [{ value: 5, name: 'Threshold' }] },
        };
        const opts = toEChartsOptions(withNoSeverity);
        expect(opts.series[opts.series.length - 1].markLine.data[0].lineStyle.color).toBe('#FF0000');
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
        const result = opts.yAxis[0].axisLabel.formatter(3.0);
        expect(result).toBe('3.0');
        expect(result).not.toContain('seconds');
    });

    test('yAxis honors normalized min and max hints', () => {
        const opts = toEChartsOptions({
            ...FIXTURE,
            graph: { ...FIXTURE.graph, y_axes: [{ unit: 'seconds', scale: 'linear', min: 0, max: 100 }] },
        });

        expect(opts.yAxis[0].min).toBe(0);
        expect(opts.yAxis[0].max).toBe(100);
    });

    test('dual-axis graph produces two yAxis entries and assigns yAxisIndex to series', () => {
        const dualAxis = {
            ...FIXTURE,
            graph: {
                ...FIXTURE.graph,
                y_axes: [
                    { unit: 'ms', scale: 'linear', min: null, max: null },
                    { unit: '%',  scale: 'linear', min: 0,    max: 100  },
                ],
                series: [
                    { ...FIXTURE.graph.series[0], name: 'RTT', yAxisIndex: 0 },
                    { ...FIXTURE.graph.series[0], name: 'Loss', yAxisIndex: 1 },
                ],
            },
        };
        const opts = toEChartsOptions(dualAxis);
        expect(opts.yAxis).toHaveLength(2);
        expect(opts.yAxis[0].name).toBe('Ms');
        expect(opts.yAxis[1].name).toBe('%');
        expect(opts.series[0].yAxisIndex).toBe(0);
        expect(opts.series[1].yAxisIndex).toBe(1);
    });

    test('server-side percentile markers render as markLine entries', () => {
        const opts = toEChartsOptions({
            ...FIXTURE,
            graph: {
                ...FIXTURE.graph,
                markers: [
                    { type: 'horizontal_line', name: '25th Percentile', value: 1.75, color: '111111', lineStyle: 'solid' },
                    { type: 'horizontal_line', name: '50th Percentile', value: 2.5, color: '222222', lineStyle: 'solid' },
                    { type: 'horizontal_line', name: '75th Percentile', value: 3.25, color: '333333', lineStyle: 'solid' },
                ],
            },
        });

        expect(opts.series[opts.series.length - 1].markLine.data).toHaveLength(3);
        expect(opts.series[opts.series.length - 1].markLine.data.map(line => line.name)).toEqual([
            '25th Percentile',
            '50th Percentile',
            '75th Percentile',
        ]);
    });

    test('marker colors come from normalized payload metadata', () => {
        const opts = toEChartsOptions({
            ...FIXTURE,
            graph: {
                ...FIXTURE.graph,
                markers: [
                    { type: 'horizontal_line', name: '25th Percentile', value: 1.75, color: '111111', lineStyle: 'solid' },
                    { type: 'horizontal_line', name: '50th Percentile', value: 2.5, color: '222222', lineStyle: 'solid' },
                    { type: 'horizontal_line', name: '75th Percentile', value: 3.25, color: '333333', lineStyle: 'solid' },
                ],
            },
        });

        expect(opts.series[opts.series.length - 1].markLine.data.map(line => line.lineStyle.color)).toEqual([
            '#111111',
            '#222222',
            '#333333',
        ]);
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
