import { formatNumber } from './formatUnits.js';
import { THEME } from './toEChartsOptions.js';

function seriesStats(s) {
    if (s.stats) return s.stats;
    const vals = s.data.map(([, v]) => v).filter(v => v != null && !isNaN(v));
    if (!vals.length) return { last: null, min: null, max: null, avg: null };
    return {
        last: vals[vals.length - 1],
        min:  vals.reduce((a, b) => a < b ? a : b),
        max:  vals.reduce((a, b) => a > b ? a : b),
        avg:  vals.reduce((a, b) => a + b, 0) / vals.length,
    };
}

function escapeHtml(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

const fmt = (v) => v == null ? 'N/A' : formatNumber(v, 2);
const DEFAULT_COLOR = '663399';

// Fixed-width stat cell: right-aligned, matches rrdtool GPRINT column width.
const STAT = 'text-align:right;padding:0 5px;white-space:nowrap;min-width:52px;';

export function buildHtmlLegend(graph, dark) {
    const t = THEME[dark ? 'dark' : 'light'];

    const headerRow = `<tr>
      <td style="padding:0 8px 0 0;white-space:nowrap;opacity:0.65;"></td>
      <td style="${STAT}opacity:0.65;">Now</td>
      <td style="${STAT}opacity:0.65;">Min</td>
      <td style="${STAT}opacity:0.65;">Max</td>
      <td style="${STAT}opacity:0.65;">Avg</td>
    </tr>`;

    const dataRows = graph.series.map((s, idx) => {
        const color  = `#${s.style?.color ?? DEFAULT_COLOR}`;
        const st     = seriesStats(s);
        // Swatch: solid border (the line) with semi-transparent fill (the area) — matches LINE+AREA rendering.
        const swatch = `<span style="display:inline-block;width:14px;height:10px;`
            + `border-top:2px solid ${color};border-bottom:2px solid ${color};`
            + `background-color:${color};opacity:0.8;`
            + `vertical-align:middle;margin-right:5px;box-sizing:border-box;"></span>`;
        return `<tr>
          <td style="padding:0 8px 0 0;white-space:nowrap;">${swatch}${escapeHtml(s.name)}</td>
          <td style="${STAT}">${fmt(st.last)}</td>
          <td style="${STAT}">${fmt(st.min)}</td>
          <td style="${STAT}">${fmt(st.max)}</td>
          <td style="${STAT}">${fmt(st.avg)}</td>
        </tr>`;
    }).join('');

    return `<div style="background:${t.background};padding:2px 4px 6px 4px;`
        + `font-family:'Courier New',Courier,monospace;font-size:11px;color:${t.font};line-height:1.6;">`
        + `<table style="border-collapse:collapse;border-spacing:0;">`
        + `<thead>${headerRow}</thead>`
        + `<tbody>${dataRows}</tbody>`
        + `</table></div>`;
}
