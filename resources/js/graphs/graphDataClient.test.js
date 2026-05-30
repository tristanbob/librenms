import { describe, test, expect, vi, beforeEach, afterEach } from 'vitest';

function mockResponse(data, cacheControl) {
    return {
        ok: true,
        headers: { get: (h) => h === 'Cache-Control' ? cacheControl : null },
        json: () => Promise.resolve(data),
    };
}

function mockFetch(data, cacheControl) {
    return vi.fn(() => Promise.resolve(mockResponse(data, cacheControl)));
}

async function freshFetchGraphData() {
    vi.resetModules();
    const mod = await import('./graphDataClient.js');
    return mod.fetchGraphData;
}

beforeEach(() => {
    vi.useFakeTimers();
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('fetchGraphData', () => {
    test('cache miss fetches, stores, and returns data', async () => {
        const fetchGraphData = await freshFetchGraphData();
        vi.stubGlobal('fetch', mockFetch({ value: 1 }, 'private, max-age=300'));

        const result = await fetchGraphData('/api/graph?from=0&to=0');

        expect(result).toEqual({ value: 1 });
        expect(fetch).toHaveBeenCalledTimes(1);
    });

    test('cache hit within TTL returns cached data without re-fetching', async () => {
        const fetchGraphData = await freshFetchGraphData();
        vi.stubGlobal('fetch', mockFetch({ value: 1 }, 'private, max-age=300'));

        await fetchGraphData('/api/graph?from=0&to=0');
        const result = await fetchGraphData('/api/graph?from=0&to=0');

        expect(result).toEqual({ value: 1 });
        expect(fetch).toHaveBeenCalledTimes(1);
    });

    test('no-store response is never cached — every call re-fetches', async () => {
        const fetchGraphData = await freshFetchGraphData();
        vi.stubGlobal('fetch', mockFetch({ value: 1 }, 'no-store'));

        await fetchGraphData('/api/graph?from=0&to=0');
        await fetchGraphData('/api/graph?from=0&to=0');

        expect(fetch).toHaveBeenCalledTimes(2);
    });

    test('max-age TTL is respected — no re-fetch before expiry, re-fetch after', async () => {
        const fetchGraphData = await freshFetchGraphData();
        vi.stubGlobal('fetch', mockFetch({ value: 1 }, 'private, max-age=10'));

        await fetchGraphData('/api/graph?from=0&to=0');

        vi.advanceTimersByTime(9_000);
        await fetchGraphData('/api/graph?from=0&to=0');
        expect(fetch).toHaveBeenCalledTimes(1);

        vi.advanceTimersByTime(2_000); // 11 s total
        await fetchGraphData('/api/graph?from=0&to=0');
        expect(fetch).toHaveBeenCalledTimes(2);
    });

    test('cache expiry causes re-fetch after default TTL (300 s fallback)', async () => {
        const fetchGraphData = await freshFetchGraphData();
        vi.stubGlobal('fetch', mockFetch({ value: 1 }, 'private, max-age=300'));

        await fetchGraphData('/api/graph?from=0&to=0');

        vi.advanceTimersByTime(299_000);
        await fetchGraphData('/api/graph?from=0&to=0');
        expect(fetch).toHaveBeenCalledTimes(1);

        vi.advanceTimersByTime(2_000); // 301 s total
        await fetchGraphData('/api/graph?from=0&to=0');
        expect(fetch).toHaveBeenCalledTimes(2);
    });

    test('two concurrent calls share one fetch (in-flight deduplication)', async () => {
        const fetchGraphData = await freshFetchGraphData();

        let resolve;
        const deferred = new Promise(r => { resolve = r; });
        vi.stubGlobal('fetch', vi.fn(() => deferred.then(() => mockResponse({ value: 1 }, 'private, max-age=300'))));

        const p1 = fetchGraphData('/api/graph?from=0&to=0');
        const p2 = fetchGraphData('/api/graph?from=0&to=0');

        resolve();
        const [r1, r2] = await Promise.all([p1, p2]);

        expect(fetch).toHaveBeenCalledTimes(1);
        expect(r1).toEqual({ value: 1 });
        expect(r2).toEqual({ value: 1 });
    });

    test('fetch error removes cache entry — next call re-fetches', async () => {
        const fetchGraphData = await freshFetchGraphData();

        const fetchMock = vi.fn()
            .mockRejectedValueOnce(new Error('network'))
            .mockResolvedValueOnce(mockResponse({ value: 2 }, 'private, max-age=300'));
        vi.stubGlobal('fetch', fetchMock);

        await expect(fetchGraphData('/api/graph?from=0&to=0')).rejects.toThrow('network');

        const result = await fetchGraphData('/api/graph?from=0&to=0');
        expect(result).toEqual({ value: 2 });
        expect(fetch).toHaveBeenCalledTimes(2);
    });

    test('max cache size evicts oldest entry', async () => {
        const fetchGraphData = await freshFetchGraphData();
        vi.stubGlobal('fetch', mockFetch({ value: 1 }, 'private, max-age=300'));

        // Fill 50 distinct entries
        for (let i = 0; i < 50; i++) {
            await fetchGraphData(`/api/graph?i=${i}`);
        }
        expect(fetch).toHaveBeenCalledTimes(50);

        // The 51st entry triggers eviction of i=0
        await fetchGraphData('/api/graph?i=50');
        expect(fetch).toHaveBeenCalledTimes(51);

        // i=0 was evicted — this must go to the network
        await fetchGraphData('/api/graph?i=0');
        expect(fetch).toHaveBeenCalledTimes(52);

        // i=2 was not evicted — still cached (i=1 was evicted when i=0 was re-added)
        await fetchGraphData('/api/graph?i=2');
        expect(fetch).toHaveBeenCalledTimes(52);
    });
});
