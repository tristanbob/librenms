import { fetchGraphData }   from './graphDataClient.js';
import { toEChartsOptions } from './toEChartsOptions.js';

export function mountEChartsGraphs() {
    const containers = document.querySelectorAll('.lnms-echart[data-graph-url]');

    containers.forEach((el) => {
        const url     = el.dataset.graphUrl;
        const refresh = parseInt(el.dataset.refresh || '0', 10);

        let chart = null;

        async function load() {
            if (document.hidden) return;

            // Dynamically import echarts so it only loads when a chart is on the page
            if (!chart) {
                const echarts = await import('echarts');
                chart = echarts.init(el, null, { renderer: 'canvas' });
            }

            chart.showLoading();
            try {
                const payload = await fetchGraphData(url);
                chart.hideLoading();
                chart.setOption(toEChartsOptions(payload), true);
            } catch (err) {
                chart.hideLoading();
                chart.showLoading('default', { text: 'Failed to load graph data.' });
                console.error('ECharts graph load error:', err);
            }
        }

        load();

        if (refresh > 0) {
            setInterval(load, refresh * 1000);
        }

        const ro = new ResizeObserver(() => chart && chart.resize());
        ro.observe(el);
    });
}
