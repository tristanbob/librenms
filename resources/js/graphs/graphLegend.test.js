import { describe, test, expect } from 'vitest';
import { buildHtmlLegend } from './graphLegend.js';

const GRAPH = {
    series: [{
        name:  'Poller time',
        key:   'poller_time',
        type:  'line',
        unit:  'seconds',
        data:  [[1000000000, 12.5]],
        style: { area: true, stack: null },
        stats: { last: 12.5, min: 10, max: 15, avg: 12.5 },
    }],
};

describe('buildHtmlLegend', () => {
    test('includes Now / Min / Max / Avg column headers', () => {
        const html = buildHtmlLegend(GRAPH, false);
        expect(html).toContain('Now');
        expect(html).toContain('Min');
        expect(html).toContain('Max');
        expect(html).toContain('Avg');
    });

    test('shows series name and formatted stats', () => {
        const html = buildHtmlLegend(GRAPH, false);
        expect(html).toContain('Poller time');
        expect(html).toContain('12.50');  // last and avg
        expect(html).toContain('10.00');  // min
        expect(html).toContain('15.00');  // max
    });

    test('uses dark background in dark mode', () => {
        const html = buildHtmlLegend(GRAPH, true);
        expect(html).toContain('#2e3338');
    });

    test('uses transparent background in light mode', () => {
        const html = buildHtmlLegend(GRAPH, false);
        expect(html).toContain('transparent');
    });

    test('applies first DEFAULT_COLORS color to swatch when no style.color set', () => {
        const html = buildHtmlLegend(GRAPH, false);
        expect(html).toContain('#663399');
    });

    test('uses style.color from series when provided', () => {
        const graph = {
            series: [{ ...GRAPH.series[0], style: { ...GRAPH.series[0].style, color: 'FF0000' } }],
        };
        const html = buildHtmlLegend(graph, false);
        expect(html).toContain('#FF0000');
    });

    test('escapes HTML in series name', () => {
        const graph = { series: [{ ...GRAPH.series[0], name: '<script>alert(1)</script>' }] };
        const html  = buildHtmlLegend(graph, false);
        expect(html).not.toContain('<script>');
        expect(html).toContain('&lt;script&gt;');
    });

    test('computes stats from data when stats field is absent', () => {
        const graph = {
            series: [{ ...GRAPH.series[0], stats: null, data: [[0, 5], [1, 10], [2, 15]] }],
        };
        const html = buildHtmlLegend(graph, false);
        expect(html).toContain('15.00');  // max
        expect(html).toContain('5.00');   // min
    });

    test('renders N/A when series data is empty', () => {
        const graph = {
            series: [{ ...GRAPH.series[0], stats: null, data: [] }],
        };
        const html = buildHtmlLegend(graph, false);
        expect(html).toContain('N/A');
    });

    test('renders multiple series rows', () => {
        const graph = {
            series: [
                { ...GRAPH.series[0], name: 'Series A' },
                { ...GRAPH.series[0], name: 'Series B', stats: { last: 1, min: 0, max: 2, avg: 1 } },
            ],
        };
        const html = buildHtmlLegend(graph, false);
        expect(html).toContain('Series A');
        expect(html).toContain('Series B');
    });
});
