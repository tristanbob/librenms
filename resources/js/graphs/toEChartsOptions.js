import { formatValue } from './formatUnits.js';

export function toEChartsOptions(payload) {
    const graph = payload.graph;

    const series = graph.series.map((s) => ({
        name:      s.name,
        type:      'line',
        smooth:    false,
        symbol:    'none',
        areaStyle: s.style.area ? {} : null,
        stack:     s.style.stack ?? undefined,
        data:      s.data,
    }));

    if (graph.markers.length > 0) {
        series[0].markLine = {
            data: graph.markers.map((m) => ({ yAxis: m.value, name: m.name })),
        };
    }

    return {
        animation: false,
        title: {
            text:      graph.title,
            subtext:   graph.subtitle,
            textStyle: { fontSize: 13 },
        },
        tooltip: {
            trigger:   'axis',
            formatter: (params) => {
                const ts    = new Date(params[0].value[0]);
                const lines = params.map(
                    (p) => `${p.seriesName}: ${formatValue(p.value[1], graph.unit)}`
                );
                return `${ts.toLocaleString()}<br>${lines.join('<br>')}`;
            },
        },
        legend:   { type: 'scroll' },
        xAxis:    { type: 'time' },
        yAxis:    {
            type:      'value',
            axisLabel: { formatter: (v) => formatValue(v, graph.unit, 1) },
        },
        dataZoom: [{ type: 'inside' }],
        series,
    };
}
