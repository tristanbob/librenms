import { fetchGraphData }  from './graphDataClient.js';
import { toEChartsOptions, THEME } from './toEChartsOptions.js';
import { buildHtmlLegend }  from './graphLegend.js';

const isDark = () => document.documentElement.classList.contains('dark');
const DENSE_SERIES_THRESHOLD = 12;

function loadingOpts(text = '') {
    const t = THEME[isDark() ? 'dark' : 'light'];
    return {
        text,
            maskColor:  t.background === 'transparent' ? 'rgba(255,255,255,0.85)' : t.background,
            textColor:  t.font,
            color:      t.font,
    };
}

function seriesCount(payload) {
    return payload?.graph?.series?.length ?? 0;
}

export function mountEChartsGraphs() {
    const containers = document.querySelectorAll('.lnms-echart[data-graph-url]');

    containers.forEach((el) => {
        const url     = el.dataset.graphUrl;
        const refresh = parseInt(el.dataset.refresh || '0', 10);

        let chart       = null;
        let legendEl    = null;  // sibling <div> rendered below the chart canvas
        let lastPayload = null;  // cached for redraws on theme change

        function sizingProfile() {
            const dense = seriesCount(lastPayload) >= DENSE_SERIES_THRESHOLD;

            return {
                chartMin:  dense ? 380 : 280,
                chartMax:  dense ? 560 : 460,
                legendMax: dense ? 180 : 120,
            };
        }

        function applyLegendSizing() {
            if (!legendEl) return;

            if (el.dataset.fillViewport === 'true') {
                legendEl.style.maxHeight = `${sizingProfile().legendMax}px`;
                legendEl.style.overflowY = 'auto';
                legendEl.style.overflowX = 'auto';
                legendEl.style.marginTop = '4px';
                return;
            }

            legendEl.style.maxHeight = '';
            legendEl.style.overflowY = '';
            legendEl.style.overflowX = '';
            legendEl.style.marginTop = '';
        }

        function applyHeight() {
            if (el.dataset.sparkline === 'true') return;
            if (el.dataset.fillViewport === 'true') {
                const { chartMin, chartMax, legendMax } = sizingProfile();
                const viewportBudget = window.innerHeight - el.getBoundingClientRect().top - 20;
                const available      = viewportBudget - legendMax;
                const chartPx        = Math.min(chartMax, Math.max(chartMin, available));
                el.style.height      = `${chartPx}px`;
                return;
            }
            const chartPx   = Math.round(el.offsetWidth / 2.15);
            el.style.height = `${chartPx}px`;
        }

        function redraw() {
            if (!chart || !lastPayload) return;
            const dark       = isDark();
            const hideLegend = el.dataset.hideLegend === 'true';
            chart.setOption(toEChartsOptions(lastPayload, {
                dark,
                hideDataZoom: el.dataset.hideDatazoom === 'true',
                hideTooltip:  el.dataset.hideTooltip  === 'true',
                sparkline:    el.dataset.sparkline     === 'true',
            }), true);
            if (legendEl) {
                legendEl.innerHTML = hideLegend ? '' : buildHtmlLegend(lastPayload.graph, dark);
                applyLegendSizing();
            }
        }

        async function load() {
            if (document.hidden) return;

            if (!chart) {
                const echarts = await import('echarts');
                // Don't use echarts built-in dark theme — we apply exact RRD colors ourselves
                chart = echarts.init(el, null, { renderer: 'canvas' });
            }

            chart.showLoading('default', loadingOpts());
            try {
                lastPayload = await fetchGraphData(url);
                chart.hideLoading();

                // Insert or update the HTML legend sibling below the chart canvas.
                if (!legendEl) {
                    legendEl = document.createElement('div');
                    legendEl.className = 'lnms-echart-legend';
                    el.insertAdjacentElement('afterend', legendEl);
                }

                applyHeight();
                chart.resize();
                redraw();

                // Second pass: legend is now populated, so fill-viewport height is exact.
                if (el.dataset.fillViewport === 'true') {
                    applyHeight();
                    chart.resize();
                }

                if (el.dataset.linkUrl && !el.dataset.linkHandlerAttached) {
                    el.dataset.linkHandlerAttached = 'true';
                    el.style.cursor = 'pointer';
                    chart.getZr().on('click', () => { window.location.href = el.dataset.linkUrl; });
                }
            } catch (err) {
                chart.hideLoading();
                chart.showLoading('default', loadingOpts('Failed to load graph data.'));
                console.error('ECharts graph load error:', err);
            }
        }

        load();

        if (refresh > 0) {
            setInterval(load, refresh * 1000);
        }

        // Redraw (no re-fetch) when LibreNMS toggles dark/light mode.
        el.addEventListener('lnms:theme-change', redraw);

        const ro = new ResizeObserver(() => {
            if (!chart) return;
            applyHeight();
            chart.resize();
        });
        ro.observe(el);

        if (el.dataset.fillViewport === 'true') {
            window.addEventListener('resize', () => {
                if (!chart) return;
                applyHeight();
                chart.resize();
            }, { passive: true });
        }
    });

    // applySiteStyle() in librenms.js toggles `dark` on <html class> with no custom event.
    // Watch for that class change and notify all mounted charts.
    const mo = new MutationObserver(() => {
        document.querySelectorAll('.lnms-echart[data-graph-url]').forEach(el => {
            el.dispatchEvent(new CustomEvent('lnms:theme-change'));
        });
    });
    mo.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
}
