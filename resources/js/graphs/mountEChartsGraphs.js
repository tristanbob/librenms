import { fetchGraphData }  from './graphDataClient.js';
import { toEChartsOptions, THEME } from './toEChartsOptions.js';
import { buildHtmlLegend }  from './graphLegend.js';
import {
    buildGraphDataUrl,
    desiredStepMs,
    epochToMs,
    estimatePayloadStepMs,
    isDetailFiner,
    MAX_CACHE_RANGES,
    mergeDetailPayload,
    paddedRange,
    shouldFetchDetail,
    visibleRangeFromDataZoom,
} from './adaptiveGraphDetail.js';

const isDark = () => document.documentElement.classList.contains('dark');
const DENSE_SERIES_THRESHOLD = 12;
const DEFAULT_STEP_SECONDS = 300;
const MIN_ZOOM_POINTS = 10;
const ZOOM_SPAN_TOLERANCE_MS = 1;
const ADAPTIVE_DETAIL_DEBOUNCE_MS = 350;

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

function finiteNumber(value) {
    if (value == null || value === '') return null;

    const numeric = Number(value);

    return Number.isFinite(numeric) ? numeric : null;
}

function graphTimeExtentMs(payload) {
    const from = epochToMs(payload?.graph?.from);
    const to   = epochToMs(payload?.graph?.to);

    if (from == null || to == null) return null;

    return Math.abs(to - from);
}

function minZoomSpanMs(payload) {
    const step = finiteNumber(payload?.graph?.step) ?? DEFAULT_STEP_SECONDS;

    if (step <= 0) return null;

    return step * MIN_ZOOM_POINTS * 1000;
}

export function currentDataZoomSpanMs(chart, payload) {
    const zoom = chart?.getOption?.()?.dataZoom?.[0] ?? {};
    const startValue = finiteNumber(zoom.startValue);
    const endValue   = finiteNumber(zoom.endValue);

    if (startValue != null && endValue != null) {
        return Math.abs(endValue - startValue);
    }

    const start  = finiteNumber(zoom.start);
    const end    = finiteNumber(zoom.end);
    const extent = graphTimeExtentMs(payload);

    if (start != null && end != null && extent != null) {
        return Math.abs(end - start) / 100 * extent;
    }

    return extent;
}

export function shouldBlockMaxZoomWheel(event, chart, payload) {
    if (!event || event.deltaY >= 0) return false;

    const span = currentDataZoomSpanMs(chart, payload);
    const minSpan = minZoomSpanMs(payload);

    return span != null && minSpan != null && span <= minSpan + ZOOM_SPAN_TOLERANCE_MS;
}

export function mountEChartsGraphs() {
    const containers = document.querySelectorAll('.lnms-echart[data-graph-url]');

    containers.forEach((el) => {
        const url     = el.dataset.graphUrl;
        const refresh = parseInt(el.dataset.refresh || '0', 10);

        let chart       = null;
        let legendEl    = null;  // sibling <div> rendered below the chart canvas
        let basePayload  = null;  // full-range payload kept for zoom-out context
        let lastPayload = null;  // cached for redraws on theme change
        let detailCache = [];
        let noDetailCache = [];
        let adaptiveTimer = null;
        let adaptiveAbort = null;
        let cacheSequence = 0;
        let suppressAdaptiveFetch = false;
        let maxZoomWheelGuardAttached = false;

        function graphWidth() {
            return Math.max(1, Math.round(el.clientWidth || el.offsetWidth || 1200));
        }

        function graphHeight() {
            const parsed = parseInt(el.style.height || el.dataset.graphHeight || '300', 10);

            return Number.isFinite(parsed) && parsed > 0 ? parsed : 300;
        }

        function graphDataUrl(range = null) {
            return buildGraphDataUrl(url, {
                from: range?.from,
                to: range?.to,
                width: graphWidth(),
                height: graphHeight(),
            });
        }

        function attachMaxZoomWheelGuard() {
            if (maxZoomWheelGuardAttached) return;

            maxZoomWheelGuardAttached = true;
            el.addEventListener('wheel', (event) => {
                if (!shouldBlockMaxZoomWheel(event, chart, lastPayload)) return;

                event.preventDefault();
                event.stopImmediatePropagation();
            }, { capture: true, passive: false });
        }

        function zoomState() {
            const zoom = chart?.getOption?.()?.dataZoom?.[0] ?? {};
            const startValue = finiteNumber(zoom.startValue);
            const endValue   = finiteNumber(zoom.endValue);

            if (startValue != null && endValue != null) {
                return { startValue, endValue };
            }

            const start = finiteNumber(zoom.start);
            const end   = finiteNumber(zoom.end);

            return start != null && end != null ? { start, end } : null;
        }

        function restoreZoom(state) {
            if (!state) return;

            chart.dispatchAction({
                type: 'dataZoom',
                dataZoomIndex: 0,
                ...state,
            });
        }

        function applyChartOptions(preserveZoom = false) {
            if (!chart || !lastPayload) return;

            const state = preserveZoom ? zoomState() : null;
            const dark       = isDark();
            const hideLegend = el.dataset.hideLegend === 'true';

            suppressAdaptiveFetch = true;
            try {
                chart.setOption(toEChartsOptions(lastPayload, {
                    dark,
                    hideDataZoom: el.dataset.hideDatazoom === 'true',
                    hideTooltip:  el.dataset.hideTooltip  === 'true',
                    sparkline:    el.dataset.sparkline     === 'true',
                }), true);
                restoreZoom(state);
            } finally {
                suppressAdaptiveFetch = false;
            }

            if (legendEl) {
                legendEl.innerHTML = hideLegend ? '' : buildHtmlLegend(lastPayload.graph, dark);
                applyLegendSizing();
            }
        }

        function applyMergedPayload(preserveZoom = true) {
            lastPayload = mergeDetailPayload(basePayload, null, detailCache);
            applyChartOptions(preserveZoom);
        }

        function cacheRange(cache, entry) {
            cache.push({ ...entry, cacheSequence: ++cacheSequence });

            while (detailCache.length + noDetailCache.length > MAX_CACHE_RANGES) {
                const oldestDetail = detailCache[0]?.cacheSequence ?? Infinity;
                const oldestNoDetail = noDetailCache[0]?.cacheSequence ?? Infinity;

                if (oldestDetail <= oldestNoDetail) {
                    detailCache.shift();
                } else {
                    noDetailCache.shift();
                }
            }

            return cache;
        }

        function scheduleAdaptiveDetailFetch() {
            if (suppressAdaptiveFetch || el.dataset.hideDatazoom === 'true') return;
            if (!chart || !basePayload || document.hidden) return;

            clearTimeout(adaptiveTimer);
            adaptiveTimer = setTimeout(fetchAdaptiveDetail, ADAPTIVE_DETAIL_DEBOUNCE_MS);
        }

        async function fetchAdaptiveDetail() {
            const visibleRange = visibleRangeFromDataZoom(chart, basePayload);
            const fullRange = visibleRangeFromDataZoom(null, basePayload);
            const fetchRange = paddedRange(visibleRange, fullRange);
            const currentStep = estimatePayloadStepMs(lastPayload, visibleRange);
            const targetStep = desiredStepMs(fetchRange, graphWidth());

            if (!shouldFetchDetail(currentStep, targetStep, noDetailCache, fetchRange)) return;

            adaptiveAbort?.abort();
            adaptiveAbort = new AbortController();

            try {
                const detailPayload = await fetchGraphData(graphDataUrl(fetchRange), adaptiveAbort.signal);
                const detailStep = estimatePayloadStepMs(detailPayload, fetchRange);

                if (!isDetailFiner(currentStep, detailStep)) {
                    noDetailCache = cacheRange(noDetailCache, {
                        ...fetchRange,
                        desiredStepMs: targetStep,
                    });
                    return;
                }

                detailCache = cacheRange(detailCache, {
                    ...fetchRange,
                    desiredStepMs: targetStep,
                    payload: detailPayload,
                });
                applyMergedPayload(true);
            } catch (err) {
                if (err?.name === 'AbortError') return;

                console.error('ECharts adaptive detail fetch error:', err);
            }
        }

        function attachAdaptiveDetail() {
            if (el.dataset.hideDatazoom === 'true') return;

            chart.on('dataZoom', scheduleAdaptiveDetailFetch);
        }

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

        function redraw(preserveZoom = false) {
            applyChartOptions(preserveZoom);
        }

        async function load() {
            if (document.hidden) return;

            if (!chart) {
                const echarts = await import('echarts');
                // Don't use echarts built-in dark theme — we apply exact RRD colors ourselves
                chart = echarts.init(el, null, { renderer: 'canvas' });
                attachMaxZoomWheelGuard();
                attachAdaptiveDetail();
            }

            chart.showLoading('default', loadingOpts());
            try {
                basePayload = await fetchGraphData(graphDataUrl());
                lastPayload = basePayload;
                detailCache = [];
                noDetailCache = [];
                chart.hideLoading();

                // Insert or update the HTML legend sibling below the chart canvas.
                if (!legendEl) {
                    legendEl = document.createElement('div');
                    legendEl.className = 'lnms-echart-legend';
                    el.insertAdjacentElement('afterend', legendEl);
                }

                applyHeight();
                chart.resize();
                redraw(true);

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
        el.addEventListener('lnms:theme-change', () => redraw(true));

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
