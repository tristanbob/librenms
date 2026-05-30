const CACHE_TTL_MS = 5 * 60 * 1000;
const MAX_CACHE_SIZE = 50;
const cache = new Map();

function parseCacheControlTtl(header) {
    if (!header || /no-store/.test(header)) return 0;
    const m = header.match(/max-age=(\d+)/);
    return m ? parseInt(m[1], 10) * 1000 : CACHE_TTL_MS;
}

function evictIfNeeded() {
    while (cache.size > MAX_CACHE_SIZE) {
        cache.delete(cache.keys().next().value);
    }
}

function cacheKey(url) {
    try {
        const base = window.location?.origin ?? 'http://localhost';
        const u = new URL(url, base);
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
        if (hit.promise) return hit.promise;
        if (Date.now() - hit.ts < (hit.ttl ?? CACHE_TTL_MS)) return hit.data;
    }

    const promise = fetch(url, { signal })
        .then(resp => {
            if (!resp.ok) throw new Error(`Graph data fetch failed: ${resp.status}`);
            const ttlMs = parseCacheControlTtl(resp.headers.get('Cache-Control'));
            return resp.json().then(data => ({ data, ttlMs }));
        })
        .then(({ data, ttlMs }) => {
            if (ttlMs !== 0) {
                cache.set(key, { data, ts: Date.now(), ttl: ttlMs });
                evictIfNeeded();
            } else {
                cache.delete(key);
            }
            return data;
        })
        .catch(err => {
            cache.delete(key);
            throw err;
        });

    cache.set(key, { promise });
    return promise;
}
