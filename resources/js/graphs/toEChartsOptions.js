import { formatValue, formatNumber } from './formatUnits.js';

// Exact values from config_definitions.json rrdgraph_def_text / rrdgraph_def_text_dark
export const THEME = {
    dark: {
        background: '#2e3338',
        grid:       '#292929',
        frame:      '#5e5e5e',
        font:       '#f8f9f9',
    },
    light: {
        background: 'transparent',
        grid:       '#a5a5a5',
        frame:      '#5e5e5e',
        font:       '#000000',
    },
};

// Exact order from graph_colours.rainbow_stats_purple in config_definitions.json
export const DEFAULT_COLORS = [
    '#663399', '#22CCBB', '#00BBCC', '#0099CC', '#3366BB',
    '#AA3355', '#881177', '#CC6666', '#EEDD00', '#44DD88', '#99DD55',
];

const MONO = '"Courier New", Courier, monospace';

export function toEChartsOptions(payload, options = {}) {
    const graph = payload.graph;
    const t     = THEME[options.dark ? 'dark' : 'light'];

    const series = graph.series.map((s, idx) => {
        const color = s.style.color ? `#${s.style.color}` : DEFAULT_COLORS[idx % DEFAULT_COLORS.length];
        return {
            name:      s.name,
            type:      'line',
            smooth:    false,
            symbol:    'none',
            lineStyle: { color, width: 1.25 },
            itemStyle: { color },
            // area alpha 0x33 == 20% — matches RRD colourAalpha default
            areaStyle: s.style.area ? { color, opacity: 0.2 } : null,
            stack:     s.style.stack ?? undefined,
            data:      s.data,
        };
    });

    if (graph.markers.length > 0) {
        series[0].markLine = {
            symbol:    ['none', 'none'],
            label:     { position: 'end', formatter: '{b}', color: t.font },
            lineStyle: { color: '#ff0000', type: 'dashed', width: 1.5 },
            data:      graph.markers.map(m => ({ yAxis: m.value, name: m.name })),
        };
    }

    return {
        backgroundColor: t.background,
        animation:       false,
        title:           { show: false },
        tooltip: {
            trigger:         'axis',
            backgroundColor: t.background,
            borderColor:     t.frame,
            textStyle:       { color: t.font, fontFamily: MONO, fontSize: 11 },
            formatter: (params) => {
                const ts    = new Date(params[0].value[0]);
                const lines = params.map(p => `${p.seriesName}: ${formatValue(p.value[1], graph.unit)}`);
                return `${ts.toLocaleString()}<br>${lines.join('<br>')}`;
            },
        },
        legend: { show: false },
        grid: {
            top:          '5%',
            bottom:       '5%',
            left:         '7%',
            right:        '3%',
            containLabel: true,
        },
        xAxis: {
            type:      'time',
            splitLine: { show: true, lineStyle: { color: t.grid, type: 'solid' } },
            axisLine:  { lineStyle: { color: t.frame } },
            axisTick:  { lineStyle: { color: t.frame } },
            axisLabel: { color: t.font, fontFamily: MONO, fontSize: 10 },
        },
        yAxis: {
            type:         'value',
            name:         graph.unit.charAt(0).toUpperCase() + graph.unit.slice(1),
            nameRotate:   90,
            nameLocation: 'middle',
            nameGap:      40,
            nameTextStyle: { color: t.font, fontFamily: MONO, fontSize: 10 },
            splitLine:    { show: true, lineStyle: { color: t.grid, type: 'solid' } },
            axisLine:     { show: true, lineStyle: { color: t.frame } },
            axisTick:     { lineStyle: { color: t.frame } },
            // Ticks: number + SI prefix only — unit text lives on the axis name label
            axisLabel:    { formatter: v => formatNumber(v, 1), color: t.font, fontFamily: MONO, fontSize: 10 },
        },
        dataZoom: options.hideDataZoom ? [] : [{ type: 'inside' }],
        series,
    };
}
