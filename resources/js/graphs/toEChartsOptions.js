import { formatValue, formatNumber } from './formatUnits.js';

// Exact values from config_definitions.json rrdgraph_def_text / rrdgraph_def_text_dark
export const THEME = {
    dark: {
        background: '#2e3338',
        tooltip:    '#2e3338',
        grid:       '#292929',
        frame:      '#5e5e5e',
        font:       '#f8f9f9',
        sensorInk:  '#f2f2f2',
    },
    light: {
        background:  'transparent',
        tooltip:     '#ffffff',
        grid:        '#a5a5a5',
        frame:       '#5e5e5e',
        font:        '#000000',
        sensorInk:   '#272b30',
    },
};


const MONO = '"Courier New", Courier, monospace';
const DEFAULT_COLOR = '663399';
const DAYS_SHORT   = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const MONTHS_SHORT = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

function pad2(n) { return String(n).padStart(2, '0'); }

// ECharts auto-selects tick density and level (hour/day/month/year) based on chart
// width and data range. We only define the label format for each level.
function timeBounds(graph) {
    return {
        min: graph.from * 1000,
        max: graph.to * 1000,
    };
}

function buildXAxis(t, graph) {
    return {
        type:        'time',
        ...timeBounds(graph),
        boundaryGap: false,
        splitLine:   { show: true, lineStyle: { color: t.grid, type: 'solid' } },
        axisLine:    { lineStyle: { color: t.frame } },
        axisTick:    { lineStyle: { color: t.frame } },
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
    const defaultKind = ['line', 'bar'].includes(graph.display?.kind) ? graph.display.kind : 'line';

    // Renderer token used by PHP graph definitions for the legacy sensor line color.
    const resolveColor = (colorStr) =>
        colorStr === 'theme-ink' ? t.sensorInk : `#${colorStr ?? DEFAULT_COLOR}`;

    const series = graph.series.map((s, idx) => {
        const fillColor  = resolveColor(s.style?.color);
        const lineColor  = s.style?.lineColor ? resolveColor(s.style.lineColor) : fillColor;
        const seriesType = ['line', 'bar'].includes(s.type) ? s.type : defaultKind;
        const data       = s.style?.negate
            ? s.data.map(([t, v]) => [t, v != null ? -v : null])
            : s.data;
        return {
            name:      s.name,
            type:      seriesType,
            smooth:    false,
            symbol:    seriesType === 'line' ? 'circle' : undefined,
            showSymbol: seriesType === 'line' ? false : undefined,
            lineStyle: seriesType === 'line' ? { color: lineColor, width: s.style?.lineWidth ?? 1.25, opacity: s.style?.lineOpacity ?? 1.0 } : undefined,
            itemStyle: { color: fillColor },
            areaStyle: seriesType === 'line' && s.style?.area ? { color: fillColor, opacity: s.style.areaOpacity ?? 1.0 } : undefined,
            stack:     s.style?.stack ?? undefined,
            emphasis:  options.sparkline ? { disabled: true } : undefined,
            data,
        };
    });

    if (graph.markers.length > 0) {
        // Direction-aware severity colors matching RRD sensor/generic.inc.php threshold lines.
        // Wireless sensor limits use 'limit' (semi-transparent red matching #cc000060).
        const SEVERITY_COLOR = {
            low_critical:  '#00008b',
            low_warning:   '#005bdf',
            high_warning:  '#ffa420',
            high_critical: '#ff0000',
            critical:      '#FF0000',
            warning:       '#FF8800',
            limit:         '#cc0000',
        };
        const SEVERITY_OPACITY = { limit: 0.376 };
        series[0].markLine = {
            symbol: ['none', 'none'],
            label:  { position: 'end', formatter: '{b}', color: t.font },
            data: graph.markers.map(m => ({
                yAxis:     m.value,
                name:      m.name,
                lineStyle: {
                    color:   m.color ? resolveColor(m.color) : (SEVERITY_COLOR[m.severity] ?? '#FF0000'),
                    type:    m.lineStyle ?? 'dashed',
                    width:   1.5,
                    opacity: SEVERITY_OPACITY[m.severity] ?? 1.0,
                },
            })),
        };
    }

    return {
        backgroundColor: t.background,
        animation:       false,
        title:           { show: false },
        tooltip: options.hideTooltip ? { show: false } : {
            trigger:         'axis',
            axisPointer:     {
                type:       'line',
                snap:       true,
                lineStyle:  { color: t.frame, width: 1, type: 'solid' },
                label:      { show: false },
            },
            backgroundColor: t.tooltip,
            borderColor:     t.frame,
            textStyle:       { color: t.font, fontFamily: MONO, fontSize: 11 },
            formatter: (params) => {
                const ts      = new Date(params[0].value[0]);
                const negated = new Set(graph.series.filter(s => s.style?.negate).map(s => s.name));
                const lines   = params.map(p => {
                    const v = negated.has(p.seriesName) ? Math.abs(p.value[1]) : p.value[1];
                    return `${p.seriesName}: ${formatValue(v, graph.unit)}`;
                });
                return `${ts.toLocaleString()}<br>${lines.join('<br>')}`;
            },
        },
        legend: { show: false },
        grid: options.sparkline
            ? { top: 2, bottom: 2, left: 2, right: 2, containLabel: false }
            : { top: '5%', bottom: '5%', left: '7%', right: '3%', containLabel: true },
        xAxis: options.sparkline
            ? { type: 'time', ...timeBounds(graph), show: false }
            : buildXAxis(t, graph),
        yAxis: options.sparkline
            ? { type: 'value', show: false }
            : {
                type:         'value',
                name:         graph.unit.charAt(0).toUpperCase() + graph.unit.slice(1),
                nameRotate:   90,
                nameLocation: 'middle',
                nameGap:      40,
                nameTextStyle: { color: t.font, fontFamily: MONO, fontSize: 10 },
                splitLine:    { show: true, lineStyle: { color: t.grid, type: 'solid' } },
                axisLine:     { show: true, lineStyle: { color: t.frame } },
                axisTick:     { lineStyle: { color: t.frame } },
                axisLabel:    { formatter: v => formatNumber(v, 1), color: t.font, fontFamily: MONO, fontSize: 10 },
                min:          graph.y_axis?.min ?? undefined,
                max:          graph.y_axis?.max ?? undefined,
            },
        dataZoom: [],
        series,
    };
}
