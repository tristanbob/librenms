import { formatValue, formatNumber } from './formatUnits.js';

// Exact values from config_definitions.json rrdgraph_def_text / rrdgraph_def_text_dark
export const THEME = {
    dark: {
        background: '#2e3338',
        tooltip:    '#2e3338',
        grid:       '#292929',
        frame:      '#5e5e5e',
        font:       '#f8f9f9',
    },
    light: {
        background:  'transparent',
        tooltip:     '#ffffff',
        grid:        '#a5a5a5',
        frame:       '#5e5e5e',
        font:        '#000000',
    },
};


const MONO = '"Courier New", Courier, monospace';

const DAYS_SHORT   = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const MONTHS_SHORT = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

function pad2(n) { return String(n).padStart(2, '0'); }

// ECharts auto-selects tick density and level (hour/day/month/year) based on chart
// width and data range. We only define the label format for each level.
function buildXAxis(t) {
    return {
        type:      'time',
        splitLine: { show: true, lineStyle: { color: t.grid, type: 'solid' } },
        axisLine:  { lineStyle: { color: t.frame } },
        axisTick:  { lineStyle: { color: t.frame } },
        axisLabel: {
            color: t.font, fontFamily: MONO, fontSize: 10,
            formatter: {
                year:   v => { const d = new Date(v); return `${MONTHS_SHORT[d.getMonth()]} '${String(d.getFullYear()).slice(2)}`; },
                month:  v => { const d = new Date(v); return `${MONTHS_SHORT[d.getMonth()]} '${String(d.getFullYear()).slice(2)}`; },
                day:    v => { const d = new Date(v); return `${d.getDate()} ${MONTHS_SHORT[d.getMonth()]}`; },
                hour:   v => { const d = new Date(v); return `${DAYS_SHORT[d.getDay()]} ${pad2(d.getHours())}:${pad2(d.getMinutes())}`; },
                minute: v => { const d = new Date(v); return `${DAYS_SHORT[d.getDay()]} ${pad2(d.getHours())}:${pad2(d.getMinutes())}`; },
            },
        },
    };
}

export function toEChartsOptions(payload, options = {}) {
    const graph = payload.graph;
    const t     = THEME[options.dark ? 'dark' : 'light'];

    const series = graph.series.map((s, idx) => {
        const fillColor = `#${s.style.color}`;
        const lineColor = s.style.lineColor ? `#${s.style.lineColor}` : fillColor;
        const data      = s.style.negate
            ? s.data.map(([t, v]) => [t, v != null ? -v : null])
            : s.data;
        return {
            name:      s.name,
            type:      'line',
            smooth:    false,
            symbol:    'none',
            lineStyle: { color: lineColor, width: 1.25 },
            itemStyle: { color: fillColor },
            areaStyle: s.style.area ? { color: fillColor, opacity: s.style.areaOpacity ?? 1.0 } : null,
            stack:     s.style.stack ?? undefined,
            data,
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
        tooltip: options.hideTooltip ? { show: false } : {
            trigger:         'axis',
            backgroundColor: t.tooltip,
            borderColor:     t.frame,
            textStyle:       { color: t.font, fontFamily: MONO, fontSize: 11 },
            formatter: (params) => {
                const ts      = new Date(params[0].value[0]);
                const negated = new Set(graph.series.filter(s => s.style.negate).map(s => s.name));
                const lines   = params.map(p => {
                    const v = negated.has(p.seriesName) ? Math.abs(p.value[1]) : p.value[1];
                    return `${p.seriesName}: ${formatValue(v, graph.unit)}`;
                });
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
        xAxis: buildXAxis(t),
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
