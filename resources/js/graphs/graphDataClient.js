export async function fetchGraphData(url, signal) {
    const resp = await fetch(url, { signal });
    if (!resp.ok) throw new Error(`Graph data fetch failed: ${resp.status}`);
    return resp.json();
}
