# VictoriaMetrics — Developer Reference

This document covers the internal design of LibreNMS's VictoriaMetrics integration
for contributors adding or extending VM support in code. For operator setup,
configuration, and the RRD migration command, see
[Extensions/metrics/VictoriaMetrics](../Extensions/metrics/VictoriaMetrics.md).

The integration has two independent halves that can be enabled separately:

- **Write** — the poller dual-writes metrics in Prometheus text format while still
  writing RRD files as usual.
- **Read (graphs)** — when query is enabled, graph endpoints fetch data from
  VictoriaMetrics instead of RRD. RRD is always the automatic fallback.

---

## Architecture

```text
Poller write path:
  DataStoreFactory → VictoriaMetrics (datastore)
                          ├─ MetricMapper       measurement/field → metric name
                          ├─ LabelExtractor      tags → label set
                          └─ PrometheusTextFormatter → HTTP POST (batched)

Graph read path:
  GraphController → GraphDataBackendSelector
                          ├─ VictoriaMetricsGraphDataProvider
                          │    └─ builds MetricsQL → /api/v1/query_range → parses response
                          └─ RrdGraphDataProvider  (fallback)
```

The two halves share no runtime state. `VictoriaMetrics` (the datastore) and
`VictoriaMetricsGraphDataProvider` (the graph provider) are independent classes that
happen to talk to the same external service.

---

## Write Pipeline

### Write URL Resolution

The write URL is resolved in this order of precedence:

1. **Explicit host/port/path** — if `write_host` is set, the URL is assembled from
   `write_host`, `write_port` (or mode default), and `write_path` (or
   `/api/v1/import/prometheus`).
2. **Mode defaults** — when `write_host` is empty, `127.0.0.1` is used with port
   `8429` (vmagent) or `8428` (direct) and path `/api/v1/import/prometheus`.

Resolution is handled by `VictoriaMetrics::resolveWriteUrl()`, which is also called
by the migration command to reuse the same logic.

### Batching and Backoff

- Samples are buffered in memory and flushed when the batch reaches `batch_size`.
  The remaining batch is always flushed at the end of each poller run.
- Non-finite values (`NaN`, `Inf`) and unmapped field names are silently skipped.
- HTTP write failures are logged as warnings but do not crash the poller.
- A connection error disables the datastore for the remainder of the current poller
  process (60-second backoff window), preventing thundering-herd retries.

### Prometheus Text Format

Each sample is emitted as a Prometheus text exposition line with a millisecond Unix
timestamp:

```text
# TYPE librenms_port_if_in_octets_total counter
librenms_port_if_in_octets_total{source="librenms",device_id="1",hostname="router1",entity_type="port",ifIndex="7",ifName="Gi0/0"} 123456789 1779475200000
```

`PrometheusTextFormatter` handles name sanitisation and line assembly.
`MetricMapper` supplies the `# TYPE` hint.

---

## Metric Mapping Contract

The poller maps internal measurement/field pairs to explicit Prometheus metric names
via `VictoriaMetricsMetricCatalog`. Any measurement or field not in this catalog is skipped. All
names follow the `librenms_<entity>_<metric>_<unit>` convention.

The catalog is authoritative. When adding a VM binding to a graph definition, prefer
catalog-backed helpers such as `MetricSeries::gauge()` and `MetricSeries::rate()`.

### Device Metrics

| Measurement | Field | Metric Name | Type |
|-------------|-------|-------------|------|
| `poller-perf` | `poller` | `librenms_device_poller_duration_seconds` | gauge |

### Port Metrics

| Measurement | Field | Metric Name | Type |
|-------------|-------|-------------|------|
| `ports` | `INOCTETS` | `librenms_port_if_in_octets_total` | counter |
| `ports` | `OUTOCTETS` | `librenms_port_if_out_octets_total` | counter |
| `ports` | `INERRORS` | `librenms_port_if_in_errors_total` | counter |
| `ports` | `OUTERRORS` | `librenms_port_if_out_errors_total` | counter |
| `ports` | `INDISCARDS` | `librenms_port_if_in_discards_total` | counter |
| `ports` | `OUTDISCARDS` | `librenms_port_if_out_discards_total` | counter |
| `ports` | `ifInBits_rate` | `librenms_port_if_in_bits_per_second` | gauge |
| `ports` | `ifOutBits_rate` | `librenms_port_if_out_bits_per_second` | gauge |

---

## Label Scheme

Every written sample carries a fixed set of labels plus optional entity labels based
on which tags are present in the poller's `write()` call.

**Always present:**

| Label | Value |
|-------|-------|
| `source` | `"librenms"` |
| `device_id` | The LibreNMS device primary key as a string |
| `hostname` | The device hostname |
| `entity_type` | `device`, `port`, `sensor`, `mempool`, `storage`, or `processor` |

**Stable polling identity labels (included when non-empty):**

`ifIndex`, `ifName`, `sensor_class`, `sensor_type`, `sensor_index`,
`mempool_type`, `mempool_class`, `mempool_index`, `type`, `descr`,
`processor_type`, `processor_index`, `sla_nr`, `af`, `name`, and `module`.

**Intentionally excluded:** all `rrd_*` tags, database ID tags such as `port_id`,
`sensor_id`, `mempool_id`, and `storage_id`, `ifAlias`, and other high-cardinality
or RRD-specific fields. The exclusion list is enforced in `LabelExtractor`.

---

## Read / Graph Pipeline

### Fallback Logic

`GraphDataBackendSelector` wraps both providers. When `query_enabled` is `true`:

1. If the graph definition has no `VictoriaMetricsMetricBinding`, the selector falls
   back to RRD silently (`meta.source = "rrd"`).
2. If the VictoriaMetrics query fails (connection error, HTTP error, malformed
   response), the selector falls back to RRD and sets `meta.fallback_used = true`
   with a user-visible warning.
3. If `query_enabled` is `false`, RRD is always used with no overhead.

### MetricsQL Query Construction

`VictoriaMetricsGraphDataProvider::buildExpr()` constructs a MetricsQL selector or
expression from catalog-backed bindings. Counter metrics can be wrapped in
`rate()` by `MetricSeries::rate()`:

```
rate(librenms_port_if_in_errors_total{device_id="1",ifIndex="7"}[5m])
```

Gauge metrics query directly:

```
librenms_port_if_in_bits_per_second{device_id="1",ifIndex="7"}
```

All label keys listed by a binding are required. Ambiguous selectors that return
multiple series throw so the graph backend selector can fall back to RRD.

---

## Adding VM Bindings to a Graph Definition

Graph definitions that already have `RrdMetricBinding` entries can be extended for
VictoriaMetrics by using catalog-backed graph helpers where possible. Graph
definitions should reference LibreNMS metric catalog keys instead of repeating VM
metric names and labels.

```php
use LibreNMS\Graph\MetricSeries;

new GraphSeriesDefinition(
    name:     'In',
    key:      'port_bits_in',
    unit:     'bps',
    bindings: [
        ...MetricSeries::gauge(
            'port.if_in_bits_rate',
            new RrdMetricBinding(rrdName: 'port-id', ds: 'INOCTETS', transform: fn ($v) => $v * 8),
        ),
    ],
)
```

### `VictoriaMetricsMetricBinding` Parameters

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `metricName` | `string` | required | Prometheus metric name — usually supplied by `VictoriaMetricsMetricCatalog`. |
| `labelKeys` | `string[]` | `['device_id']` | Keys from `GraphQuery::$entities` to use as MetricsQL label matchers. All listed labels are required. |
| `transform` | `callable\|null` | `null` | Applied to each raw float value before the point is stored. |

### Checklist when adding VM bindings

1. Verify the metric exists in `VictoriaMetricsMetricCatalog`. Add it there if not —
   the write side, read side, and migration tooling must agree on the name.
2. Use stable polling identity labels from the catalog. Do not use database IDs such
   as `port_id`, `sensor_id`, `mempool_id`, or `storage_id` as VM labels.
3. Prefer MetricsQL expressions for rates, sums, and percentages instead of fetching
   multiple VM series and merging them in PHP.
4. Add a unit test asserting the binding produces the correct MetricsQL expression via
   `VictoriaMetricsGraphDataProvider::buildExpr()`.

---

## RRD Migration Internals

The `victoriametrics:migrate-rrd` command reads existing RRD files via `rrdtool fetch
AVERAGE` and backfills the data into VictoriaMetrics. For operator usage and the full
option reference, see [Extensions/metrics/VictoriaMetrics](../Extensions/metrics/VictoriaMetrics.md#migrating-historical-rrd-data).

### DERIVE → gauge mapping

`rrdtool fetch AVERAGE` on a DERIVE-type DS returns a **per-second rate**, not a raw
counter. The migration maps these losslessly to gauge metrics:

```
INOCTETS  (rate, octets/s) × 8  →  librenms_port_if_in_bits_per_second  (gauge)
OUTOCTETS (rate, octets/s) × 8  →  librenms_port_if_out_bits_per_second (gauge)
```

Counter metrics (INERRORS, OUTERRORS, INDISCARDS, OUTDISCARDS) are only written when
`--counters` is passed. The command synthesizes a monotonically increasing counter by
accumulating `rate × step` over the fetched samples. These synthetic counters recover
the original rate accurately when queried with `rate()`, but the absolute counter
value is an approximation that starts from zero at the beginning of available history.

`RrdMigrationMapper` is intentionally separate from `MetricMapper` because the
DERIVE→rate semantic of `rrdtool fetch AVERAGE` differs from the live polling field
semantics that `MetricMapper` handles.

### Command architecture

```text
MigrateRrd command
  └─ per device
       ├─ migratePollerPerf()   rrdtool fetch → gauge samples → queue
       └─ migratePorts()
            └─ per port
                 ├─ gaugeMappings()    INOCTETS/OUTOCTETS → bits_per_second gauges
                 └─ counterMappings()  (--counters only) synthesizeCounter()
                      └─ PrometheusTextFormatter → HTTP POST batches
```

Samples are buffered in memory and flushed in configurable batches (default 10 000)
via the same `PrometheusTextFormatter` and HTTP client used by the live poller.
Failed batches are counted and cause a non-zero exit code; partial success is still
committed.

---

## Development Setup

The `docker-compose.dev.yml` file includes VictoriaMetrics and vmagent services
pre-configured for local development:

```yaml
victoriametrics:
  image: victoriametrics/victoria-metrics:latest
  ports:
    - "8428:8428"

vmagent:
  image: victoriametrics/vmagent:latest
  ports:
    - "8429:8429"
  command:
    - "-httpListenAddr=0.0.0.0:8429"
    - "-remoteWrite.url=http://victoriametrics:8428/api/v1/write"
    - "-remoteWrite.tmpDataPath=/vmagent-remotewrite-data"
    - "-remoteWrite.maxDiskUsagePerURL=1GB"
```

The poller service depends on both. With the dev stack running, enable writes with
`lnms config:set victoriametrics.enable true`.

The VictoriaMetrics UI is accessible at `http://localhost:8428` for ad-hoc MetricsQL
queries and exploring stored time series.

---

## Source File Reference

| File | Purpose |
|------|---------|
| [LibreNMS/Data/Store/VictoriaMetrics.php](../../LibreNMS/Data/Store/VictoriaMetrics.php) | Datastore: batching, flushing, write URL resolution |
| [LibreNMS/Data/Store/VictoriaMetrics/MetricMapper.php](../../LibreNMS/Data/Store/VictoriaMetrics/MetricMapper.php) | Measurement/field → Prometheus metric name mapping |
| [LibreNMS/Data/Store/VictoriaMetrics/LabelExtractor.php](../../LibreNMS/Data/Store/VictoriaMetrics/LabelExtractor.php) | Tag → label set extraction for written samples |
| [LibreNMS/Data/Store/VictoriaMetrics/PrometheusTextFormatter.php](../../LibreNMS/Data/Store/VictoriaMetrics/PrometheusTextFormatter.php) | Prometheus text exposition formatting |
| [LibreNMS/Graph/VictoriaMetricsMetricBinding.php](../../LibreNMS/Graph/VictoriaMetricsMetricBinding.php) | Binding type for graph series definitions |
| [LibreNMS/Graph/VictoriaMetricsGraphDataProvider.php](../../LibreNMS/Graph/VictoriaMetricsGraphDataProvider.php) | Graph query provider: MetricsQL construction, HTTP fetch, response parsing |
| [LibreNMS/Graph/GraphDataBackendSelector.php](../../LibreNMS/Graph/GraphDataBackendSelector.php) | Selects RRD vs VictoriaMetrics provider; handles fallback |
| [app/Console/Commands/VictoriaMetrics/MigrateRrd.php](../../app/Console/Commands/VictoriaMetrics/MigrateRrd.php) | Migration command: orchestration, batching, HTTP flush |
| [LibreNMS/Data/Store/VictoriaMetrics/RrdMigrationMapper.php](../../LibreNMS/Data/Store/VictoriaMetrics/RrdMigrationMapper.php) | DS name → metric/transform mappings; counter synthesis |
| [resources/definitions/config_definitions.json](../../resources/definitions/config_definitions.json) | Config key schema for all `victoriametrics.*` settings |
