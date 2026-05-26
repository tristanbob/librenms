# Graph Data Architecture

LibreNMS separates graph concerns into three layers: definitions describe what a
graph means, backends fetch metric samples, and the renderer draws the result.
This separation means a graph definition written once works with any supported
metric backend and any renderer.

---

## Definition Layer

Every graph type implements `GraphDefinition`. A definition is a plain PHP class
with no framework dependencies; it declares what data a graph needs and how it
should be presented, without knowing how that data is fetched or drawn.

### Interface at a Glance

| Method | Returns | Purpose |
|--------|---------|---------|
| `graphType()` | `string` | Unique graph type key, e.g. `device_icmp_perf` |
| `id()` | `string` | Unique instance identifier (type + entity IDs) |
| `title()` | `string` | Display title |
| `subtitle()` | `string` | Display subtitle (usually hostname or entity name) |
| `unit()` | `string` | Primary unit label, e.g. `bps`, `ms`, `%` |
| `entityType()` | `string` | Owning entity: `device`, `port`, `sensor`, etc. |
| `variables()` | `GraphVariableDefinition[]` | Typed request parameters (default: `[]`) |
| `series()` | `GraphSeriesDefinition[]` | Data series to render |
| `markers()` | `array` | Horizontal reference lines |
| `display()` | `array` | Renderer hints (kind, area, stacked, y-axes) |

### Series and Metric Bindings

Each `GraphSeriesDefinition` carries one or more `MetricBinding` entries — one
per supported backend. At query time the active backend picks the binding whose
`source()` matches its own identifier and ignores the rest. This means a single
definition can support RRD today and VictoriaMetrics tomorrow without branching.

```php
new GraphSeriesDefinition(
    name:     'In',
    key:      'port_bits_in',
    unit:     'bps',
    bindings: [
        new RrdMetricBinding(rrdName: 'port-id', ds: 'INOCTETS', transform: fn ($v) => $v * 8),
        new VictoriaMetricsMetricBinding('ifInOctets', labelKeys: ['port_id']),
    ],
)
```

To add VictoriaMetrics support to an existing RRD-only graph, add a
`VictoriaMetricsMetricBinding` to each series — no other code changes required.

#### Multi-DS Math

When a series value requires arithmetic across multiple RRD data sources, pass
an array of DS names and a `transform` callable:

```php
new RrdMetricBinding(
    rrdName:   'icmp-perf',
    ds:        ['xmt', 'rcv'],
    transform: fn (array $v) => $v['xmt'] > 0
        ? (($v['xmt'] - $v['rcv']) / $v['xmt'] * 100)
        : null,
)
```

The same approach works for VictoriaMetrics if a single metric provides the
value directly; more complex multi-metric math requires separate series.

#### Stepped Averages

`RrdMetricBinding` accepts an optional `step` parameter. Requesting data at a
coarser step (e.g. 3600 s, 86400 s) produces smoothed average series alongside
the raw data — useful for long-window graphs where individual samples are noisy.

### Window-Level Aggregation Bindings

Three binding wrappers compute values that span the entire query window rather
than producing a point-per-timestamp time series.

**`PercentileBinding(MetricBinding $inner, float $percentile)`**
Wraps any binding and computes the Nth percentile over all non-null samples in
the window. Used as a `GraphMarkerDefinition` value to render a horizontal
reference line (e.g. "95th percentile = 42 Mbps"). Both backends fetch the inner
binding's data and compute the percentile application-side.

**`TotalBinding(MetricBinding $inner)`**
Same pattern; sums all samples to produce a total-usage value. Intended for
billing-style graphs.

**`ShiftBinding(MetricBinding $inner, int $offsetSeconds)`**
Used in a series binding (not a marker). Both backends fetch data from the time
range `[from − offset, to − offset]` and return the timestamps advanced forward
by `+offset`, so the series aligns with the current window. This produces a
"current vs. same period last week" overlay.

```php
// 95th-percentile marker
GraphMarkerDefinition::percentile('95th Percentile', $binding, 95, color: 'FF8800')

// Previous-week overlay series
new GraphSeriesDefinition(
    name:     'Last week',
    bindings: [new ShiftBinding(new RrdMetricBinding(...), offsetSeconds: 604800)],
)
```

### Variables

`variables()` returns `GraphVariableDefinition` objects that declare typed,
validated request parameters. Variables are resolved from the HTTP request before
`series()` or `markers()` are called, and are available via `$query->options`.

```php
public function variables(): array
{
    return [
        GraphVariableDefinition::integer('duration', default: 86400, min: 1, max: 604800),
    ];
}
```

Supported types: `integer` (with optional min/max clamping), `boolean`, and
`string` (with an allowed-values list).

### Markers

`markers()` returns horizontal reference lines. Each entry is either a plain
array (for static threshold values already known at definition time) or a
`GraphMarkerDefinition` object (for computed values such as percentiles):

```php
public function markers(array $device, GraphQuery $query): array
{
    return [
        // Static threshold from database
        ['type' => 'horizontal_line', 'name' => 'Warning', 'value' => $device['warn_limit'], 'severity' => 'warning'],

        // Computed percentile
        GraphMarkerDefinition::percentile('95th Percentile', new RrdMetricBinding(...), 95),
    ];
}
```

The backend evaluates `GraphMarkerDefinition` values (fetching the inner binding
and computing the aggregation) before they are serialized to the API response.

### Display Hints

`display()` returns a map of renderer hints. Common keys:

| Key | Type | Description |
|-----|------|-------------|
| `kind` | `'line'`\|`'bar'` | Default series type |
| `area` | `bool` | Fill under line series |
| `stacked` | `bool` | Stack series vertically |
| `legend` | `bool` | Show series legend |
| `y_axes` | `array` | Per-axis unit, scale, min, max (see below) |
| `yAxisMin` / `yAxisMax` | `float\|null` | Single-axis bounds shorthand |

#### Multi-Y-Axis

When a graph plots series with incompatible units (e.g. latency in ms and loss
in %), declare multiple axes in `display()` and set `yAxisIndex` on the series
that should use the non-primary axis:

```php
public function display(): array
{
    return [
        'y_axes' => [
            ['unit' => 'ms', 'scale' => 'linear', 'min' => null, 'max' => null],
            ['unit' => '%',  'scale' => 'linear', 'min' => 0,    'max' => 100],
        ],
    ];
}

// In series():
new GraphSeriesDefinition(name: 'Loss', yAxisIndex: 1, ...)
```

Single-axis graphs may omit `y_axes`; the backend synthesises a one-element
array from the top-level `unit` field.

---

## Backend Layer

`GraphDataBackendSelector` selects the active backend (RRD or VictoriaMetrics)
and calls `query()` on it. The selected backend:

1. Resolves graph variables from the request.
2. Iterates `series()` and evaluates each binding whose `source()` matches.
3. Evaluates `markers()`, resolving any `GraphMarkerDefinition` values.
4. Returns a `GraphDataResult` serialized to the JSON API response.

### RRD Backend

Groups series by RRD file, step, and consolidation function to minimise
`rrdtool fetch` invocations. Missing RRD files and missing data sources produce
structured warnings in `meta.warnings` rather than silent empty graphs.

### VictoriaMetrics Backend

Constructs a MetricsQL selector from the `VictoriaMetricsMetricBinding` and
queries `/api/v1/query_range`. If a definition has no VM bindings, a
`NoVmBindingException` is thrown and the selector silently falls back to RRD
with no user-visible warning. An unexpected VM failure (connection error, HTTP
error, malformed response) sets `meta.fallback_used = true` and adds a
user-visible warning.

---

## API Response Shape

```json
{
  "status": "ok",
  "graph": {
    "id":       "device_icmp_perf:42",
    "type":     "device_icmp_perf",
    "title":    "Ping Response",
    "subtitle": "router1.example.com",
    "unit":     "ms",
    "from":     1700000000,
    "to":       1700086400,
    "step":     300,
    "display":  { "kind": "line", "area": true, "legend": true },
    "variables": { "duration": 86400 },
    "y_axes":   [{ "unit": "ms", "scale": "linear", "min": null, "max": null }],
    "series":   [{ "name": "RTT", "key": "icmp_avg", "data": [[...], ...], "stats": {...} }],
    "markers":  [{ "type": "horizontal_line", "name": "Warning", "value": 150.0 }],
    "meta":     { "source": "rrd", "fallback_used": false, "warnings": [] }
  }
}
```

### Caching

The response carries `Cache-Control` headers based on whether the time window is
in the past:

- Completed range (`to < now − 60 s`): `Cache-Control: public, max-age=300`
- Live/current window: `Cache-Control: no-store`

---

## Renderer Layer

The frontend receives the normalized JSON payload above and renders it with
ECharts. The renderer is responsible only for visual presentation — it does not
re-compute percentiles, totals, thresholds, or any other graph semantics.

### Negate

`series.style.negate` is a display-layer flag. The underlying data is stored and
fetched as positive values; the renderer negates the sign at draw time to show a
series below zero (e.g. outbound traffic on a duplex graph).

### Tooltip

The tooltip formatter reads per-axis units from `y_axes` so that a dual-axis
graph shows the correct unit next to each series value.

---

## Adding a New Graph Type

1. Create a class implementing `GraphDefinition` in the appropriate namespace
   under `LibreNMS/Graph/Definitions/`.
2. Implement `series()` with `RrdMetricBinding` entries. Add
   `VictoriaMetricsMetricBinding` entries for any series that have a direct VM
   metric equivalent.
3. Implement `markers()` for threshold lines. Use `GraphMarkerDefinition::percentile()`
   for computed reference lines.
4. Implement `variables()` for any configurable request parameters.
5. Register the class in `GraphServiceProvider`.
6. Add unit tests covering variables, series bindings, marker values, and a
   representative API response snapshot.

The legacy RRDtool image endpoint for the same graph type continues to work
unchanged. Both endpoints coexist; there is no flag to flip and no cutover date.
Semantic parity — matching data, variables, thresholds, and major visual
behaviour — is the goal. Pixel-perfect reproduction of RRDtool images is not
required.
