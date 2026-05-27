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

| Measurement | Field | Metric Name | Type | Identity Labels |
|-------------|-------|-------------|------|----------------|
| `poller-perf` | `poller` | `librenms_device_poller_duration_seconds` | gauge | `device_id` |
| `uptime` | `uptime` | `librenms_device_uptime_seconds` | gauge | `device_id` |
| `availability` | `availability` | `librenms_device_availability_percent` | gauge | `device_id`, `name` |

### Port Metrics

| Measurement | Field | Metric Name | Type | Identity Labels |
|-------------|-------|-------------|------|----------------|
| `ports` | `INOCTETS` | `librenms_port_if_in_octets_total` | counter | `device_id`, `ifIndex` |
| `ports` | `OUTOCTETS` | `librenms_port_if_out_octets_total` | counter | `device_id`, `ifIndex` |
| `ports` | `INERRORS` | `librenms_port_if_in_errors_total` | counter | `device_id`, `ifIndex` |
| `ports` | `OUTERRORS` | `librenms_port_if_out_errors_total` | counter | `device_id`, `ifIndex` |
| `ports` | `INDISCARDS` | `librenms_port_if_in_discards_total` | counter | `device_id`, `ifIndex` |
| `ports` | `OUTDISCARDS` | `librenms_port_if_out_discards_total` | counter | `device_id`, `ifIndex` |
| `ports` | `ifInBits_rate` | `librenms_port_if_in_bits_per_second` | gauge | `device_id`, `ifIndex` |
| `ports` | `ifOutBits_rate` | `librenms_port_if_out_bits_per_second` | gauge | `device_id`, `ifIndex` |

### Entity Metrics

| Entity | Measurement | Metric Name | Type | Identity Labels |
|--------|-------------|-------------|------|----------------|
| Processor | `processors` | `librenms_processor_usage_percent` | gauge | `device_id`, `processor_type`, `processor_index` |
| Memory | `mempool` | `librenms_mempool_used_bytes` | gauge | `device_id`, `mempool_type`, `mempool_class`, `mempool_index` |
| Memory | `mempool` | `librenms_mempool_free_bytes` | gauge | `device_id`, `mempool_type`, `mempool_class`, `mempool_index` |
| Storage | `storage` | `librenms_storage_used_bytes` | gauge | `device_id`, `type`, `descr` |
| Storage | `storage` | `librenms_storage_free_bytes` | gauge | `device_id`, `type`, `descr` |
| Sensor | `sensors` | `librenms_sensor_value` | gauge | `device_id`, `sensor_class`, `sensor_type`, `sensor_index` |
| Wireless | `wireless_sensor` | `librenms_wireless_sensor_value` | gauge | `device_id`, `sensor_class`, `sensor_type`, `sensor_index` |
| Disk I/O | `ucd_diskio` | `librenms_diskio_reads_total` | counter | `device_id`, `descr` |
| Disk I/O | `ucd_diskio` | `librenms_diskio_writes_total` | counter | `device_id`, `descr` |

### Network Statistics (device-scoped, identity label: `device_id` only)

Netstats metrics cover ICMP, IP, SNMP, TCP, and UDP MIB counters (measurements
`netstats-icmp`, `netstats-ip`, `netstats-snmp`, `netstats-tcp`, `netstats-udp`)
and IP system statistics for IPv4 and IPv6 (measurements `ipSystemStats-ipv4`,
`ipSystemStats-ipv6`). All are counter type. The catalog key prefix matches the
measurement: `netstats.icmpInMsgs`, `ipsystemstats.ipv4.InReceives`, etc.
The full list is in `VictoriaMetricsMetricCatalog`.

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

1. If the graph definition has no VM binding on any series (neither
   `VictoriaMetricsMetricBinding` nor `VictoriaMetricsExpressionBinding`), the
   selector falls back to RRD silently (`meta.source = "rrd"`).
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
VictoriaMetrics by using catalog-backed graph helpers. Graph definitions should
reference LibreNMS metric catalog keys instead of repeating VM metric names and labels.

The three `MetricSeries` helpers each return `[RrdMetricBinding, VictoriaMetricsBinding]`
and can be spread directly into `bindings:`.

### `MetricSeries::gauge()` — for gauge metrics

Use when the VM metric stores the value directly (no accumulation required):

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

### `MetricSeries::rate()` — for counter metrics

Use when the VM metric is a cumulative counter that must be wrapped in `rate()`:

```php
bindings: [
    ...MetricSeries::rate(
        'port.if_in_discards',
        new RrdMetricBinding(rrdName: $rrdName, ds: 'INDISCARDS'),
    ),
],
// Produces: rate(librenms_port_if_in_discards_total{device_id="1",ifIndex="7"}[5m])
```

### `MetricSeries::expression()` — for computed values

Use when the value requires MetricsQL arithmetic (percentage from two metrics,
rate of a counter where labels don't come from `$query->entities`, etc.):

```php
use LibreNMS\Data\Store\VictoriaMetrics\VictoriaMetricsMetricCatalog;
use LibreNMS\Graph\VictoriaMetricsGraphDataProvider;

$used = VictoriaMetricsMetricCatalog::get('storage.used');
$free = VictoriaMetricsMetricCatalog::get('storage.free');

bindings: [
    ...MetricSeries::expression(
        rrd: new RrdMetricBinding(['storage', $type, $descr], ['used', 'free'],
            transform: fn ($v) => ($v['used'] + $v['free']) > 0
                ? $v['used'] / ($v['used'] + $v['free']) * 100 : null),
        expressionBuilder: fn (array $entities): string =>
            "100 * $usedSel / ($usedSel + $freeSel)",
        labelKeys: ['device_id', 'type', 'descr'],
    ),
],
```

### Aggregate device graphs — closures that capture DB values

Device-level aggregate graphs (processor, mempool, storage, bits, diskio) iterate DB
models and build one series per entity. Their `$query->entities` contains only
`device_id` at query time — per-entity labels such as `ifIndex` or `processor_type`
are not available there. The solution is to use `MetricSeries::expression()` with a
closure that captures the per-entity DB values, and set `labelKeys: ['device_id']` so
only `device_id` is required from the query context:

```php
$entry = VictoriaMetricsMetricCatalog::get('processor.usage');

foreach ($processors as $processor) {
    new GraphSeriesDefinition(
        bindings: MetricSeries::expression(
            new RrdMetricBinding(['processor', $processor->processor_type, $processor->processor_index], 'usage'),
            function (array $entities) use ($processor, $entry): string {
                return VictoriaMetricsGraphDataProvider::buildSelector(
                    $entry->definition->name,
                    $entry->identityLabels,
                    [
                        'device_id'       => $entities['device_id'],
                        'processor_type'  => $processor->processor_type,
                        'processor_index' => (string) $processor->processor_index,
                    ],
                );
            },
            ['device_id'],  // only device_id validated against $entities
        ),
    );
}
```

The same pattern applies to `MempoolGraph`, `StorageGraph`, `BitsGraph`, and `DiskIoGraph`.

### Binding type reference

| Type | Created via | Use when |
|------|-------------|----------|
| `VictoriaMetricsMetricBinding` | `MetricSeries::gauge()` or `::catalog()` | Single metric, labels come directly from `$query->entities` |
| `VictoriaMetricsExpressionBinding` | `MetricSeries::rate()` or `::expression()` | Rate of a counter, multi-metric math, or labels captured in a closure |

### Checklist when adding VM bindings

1. Verify the metric exists in `VictoriaMetricsMetricCatalog`. Add it there if not —
   the write side, read side, and migration tooling must agree on the name.
2. Use stable polling identity labels from the catalog. Do not use database IDs such
   as `port_id`, `sensor_id`, `mempool_id`, or `storage_id` as VM labels.
3. Prefer MetricsQL expressions for rates, sums, and percentages instead of fetching
   multiple VM series and merging them in PHP.
4. For aggregate graphs where per-entity labels are not in `$query->entities`, use
   `MetricSeries::expression()` with a capturing closure and `labelKeys: ['device_id']`.
5. Add a unit test asserting `$series->binding('victoriametrics')` is non-null for
   each series that should have a VM binding.

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
| [LibreNMS/Graph/VictoriaMetricsMetricBinding.php](../../LibreNMS/Graph/VictoriaMetricsMetricBinding.php) | Binding: catalog key lookup, label validation |
| [LibreNMS/Graph/VictoriaMetricsExpressionBinding.php](../../LibreNMS/Graph/VictoriaMetricsExpressionBinding.php) | Binding: arbitrary MetricsQL expression via callable |
| [LibreNMS/Graph/MetricSeries.php](../../LibreNMS/Graph/MetricSeries.php) | Helpers: `gauge()`, `rate()`, `expression()` — return `[RrdBinding, VmBinding]` |
| [LibreNMS/Graph/VictoriaMetricsGraphDataProvider.php](../../LibreNMS/Graph/VictoriaMetricsGraphDataProvider.php) | Graph query provider: MetricsQL construction, HTTP fetch, response parsing |
| [LibreNMS/Graph/GraphDataBackendSelector.php](../../LibreNMS/Graph/GraphDataBackendSelector.php) | Selects RRD vs VictoriaMetrics provider; handles fallback |
| [app/Console/Commands/VictoriaMetrics/MigrateRrd.php](../../app/Console/Commands/VictoriaMetrics/MigrateRrd.php) | Migration command: orchestration, batching, HTTP flush |
| [LibreNMS/Data/Store/VictoriaMetrics/RrdMigrationMapper.php](../../LibreNMS/Data/Store/VictoriaMetrics/RrdMigrationMapper.php) | DS name → metric/transform mappings; counter synthesis |
| [resources/definitions/config_definitions.json](../../resources/definitions/config_definitions.json) | Config key schema for all `victoriametrics.*` settings |
