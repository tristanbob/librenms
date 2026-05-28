const CACHE_TTL_MS = 5 * 60 * 1000;
const cache = new Map();

// Width/height affect server-side step size but not graph identity. Strip them
// from the key so a chart mounted while hidden (clientWidth=0 → width=1200
// fallback) hits the same cache entry as the same chart mounted while visible
// (width=320). Whichever resolution was fetched first is good enough for both.
function cacheKey(url) {
    try {
        const base = window.location?.origin ?? 'http://localhost';
        const u = new URL(url, base);
        u.searchParams.delete('width');
        u.searchParams.delete('height');
        u.searchParams.sort();
        return u.pathname + u.search;
    } catch {
        return url;
    }
}

export async function fetchGraphData(url, signal) {
    const key = cacheKey(url);
    const hit = cache.get(key);

    if (hit) {
        // In-flight: share the same promise to prevent duplicate concurrent fetches
        if (hit.promise) return hit.promise;
        // Resolved: return cached data if still fresh
        if (Date.now() - hit.ts < CACHE_TTL_MS) return hit.data;
    }

    const promise = fetch(url, { signal })
        .then(resp => {
            if (!resp.ok) throw new Error(`Graph data fetch failed: ${resp.status}`);
            return resp.json();
        })
        .then(data => {
            cache.set(key, { data, ts: Date.now() });
            return data;
        })
        .catch(err => {
            cache.delete(key);
            throw err;
        });

    cache.set(key, { promise });
    return promise;
}
