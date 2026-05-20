import { fetchGraphData }  from './graphDataClient.js';
import { toEChartsOptions, THEME } from './toEChartsOptions.js';
import { buildHtmlLegend }  from './graphLegend.js';

const isDark = () => document.documentElement.classList.contains('dark');

function loadingOpts(text = '') {
    const t = THEME[isDark() ? 'dark' : 'light'];
    return {
        text,
        maskColor:  t.background === 'transparent' ? 'rgba(255,255,255,0.85)' : t.background,
        textColor:  t.font,
        color:      t.font,
    };
}

export function mountEChartsGraphs() {
    const containers = document.querySelectorAll('.lnms-echart[data-graph-url]');

    containers.forEach((el) => {
        const url     = el.dataset.graphUrl;
        const refresh = parseInt(el.dataset.refresh || '0', 10);

        let chart       = null;
        let legendEl    = null;  // sibling <div> rendered below the chart canvas
        let lastPayload = null;  // cached for redraws on theme change

        function applyHeight() {
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
            }), true);
            if (legendEl) {
                legendEl.innerHTML = hideLegend ? '' : buildHtmlLegend(lastPayload.graph, dark);
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
