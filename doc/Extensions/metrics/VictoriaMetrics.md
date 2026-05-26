# VictoriaMetrics

VictoriaMetrics differs from every other metric backend in LibreNMS: it can
**serve graphs directly inside the LibreNMS web UI**, not just forward data to an
external tool like Grafana. RRD remains the automatic fallback at every step, so you
can enable writes first and switch graph reads over only when you are ready.

---

## Requirements

- VictoriaMetrics >= 1.79 (standalone) or vmagent >= 1.79 for the relay topology
- `rrdtool` accessible on the poller host (only needed for the RRD migration command)

---

## Recommended topology

```text
LibreNMS poller  →  vmagent :8429 /api/v1/import/prometheus
                         ↓  remote_write
                    VictoriaMetrics :8428
                         ↑
                    LibreNMS web (graph reads)  →  /api/v1/query_range
```

Running vmagent as a local relay is the recommended production approach. It buffers
writes to disk during VictoriaMetrics downtime and retries automatically without
blocking the poller.

For small or development setups, the poller can write directly to VictoriaMetrics by
setting `write_mode` to `direct`.

---

## Step 1 — enable metric writes

!!! setting "poller/victoriametrics"
    ```bash
    lnms config:set victoriametrics.enable true
    lnms config:set victoriametrics.write_mode vmagent   # or: direct
    ```

| Setting | Default | Description |
|---------|---------|-------------|
| `victoriametrics.enable` | `false` | Master switch — must be `true` for any writes to occur. |
| `victoriametrics.write_mode` | `vmagent` | `vmagent` targets port 8429; `direct` targets port 8428. |
| `victoriametrics.write_host` | `""` | Override host (defaults to `127.0.0.1`). |
| `victoriametrics.write_port` | `""` | Override port (uses mode default when empty). |
| `victoriametrics.write_path` | `""` | Override import path (defaults to `/api/v1/import/prometheus`). |
| `victoriametrics.batch_size` | `500` | Samples buffered before flushing to the write endpoint. |
| `victoriametrics.timeout` | `10` | HTTP timeout in seconds for write requests. |
| `victoriametrics.verify_ssl` | `true` | Verify TLS certificate on the write endpoint. |
| `victoriametrics.debug` | `false` | Log each unmapped metric field at debug level. |

### vmagent setup

Install vmagent and run it alongside the poller. Minimal command:

```bash
vmagent \
  -httpListenAddr=127.0.0.1:8429 \
  -remoteWrite.url=http://victoriametrics:8428/api/v1/write \
  -remoteWrite.tmpDataPath=/var/lib/vmagent/librenms \
  -remoteWrite.maxDiskUsagePerURL=10GB
```

For a clustered VictoriaMetrics deployment, point `-remoteWrite.url` at vminsert:

```bash
-remoteWrite.url=http://vminsert:8480/insert/0/prometheus/api/v1/write
```

LibreNMS does not manage or restart vmagent — supervise it with systemd or your
existing process manager.

---

## Step 2 — enable graph reads

Once sufficient history has accumulated (at minimum a few polling cycles; practically
a few hours for useful-looking graphs), you can switch graph rendering to read from
VictoriaMetrics instead of RRD.

!!! setting "poller/victoriametrics"
    ```bash
    lnms config:set victoriametrics.query_enabled true
    lnms config:set victoriametrics.query_url 'http://127.0.0.1:8428'
    ```

| Setting | Default | Description |
|---------|---------|-------------|
| `victoriametrics.query_enabled` | `false` | Route graph data requests to VictoriaMetrics. Only shown in the config UI when `enable` is `true`. |
| `victoriametrics.query_url` | `http://127.0.0.1:8428` | Base URL of the VictoriaMetrics HTTP API. |

Set `query_url` to your standalone VictoriaMetrics address, or to vmselect in a
cluster (`http://vmselect:8481/select/0/prometheus`). Do not append
`/api/v1/query_range` — LibreNMS adds it automatically.

---

## Fallback behavior

RRD is always the fallback. When `query_enabled` is `true`, LibreNMS falls back to
RRD silently in two situations:

- **No VM binding** — the graph type does not yet have a VictoriaMetrics binding. Only
  some graph types are currently supported; all others serve from RRD as normal.
- **Query failure** — the VictoriaMetrics query fails (connection error, HTTP error,
  malformed response). A user-visible warning is shown alongside the graph in this case.

When `query_enabled` is `false`, RRD is always used with no overhead.

The graph JSON response includes a `meta.source` field — `"rrd"` or
`"victoriametrics"` — that identifies which backend served the data. When an automatic
fallback occurred, `meta.fallback_used` is set to `true`.

---

## What metrics are tracked

LibreNMS writes only metrics that have an explicit name mapping. Any measurement or
field not in the tables below is skipped silently.

### Device metrics

| Metric | Type | Description |
|--------|------|-------------|
| `librenms_device_poller_duration_seconds` | gauge | Time taken to complete a full poll of the device |

### Port metrics

| Metric | Type | Description |
|--------|------|-------------|
| `librenms_port_if_in_bits_per_second` | gauge | Inbound throughput (derived from INOCTETS rate × 8) |
| `librenms_port_if_out_bits_per_second` | gauge | Outbound throughput (derived from OUTOCTETS rate × 8) |
| `librenms_port_if_in_octets_total` | counter | Cumulative inbound octets |
| `librenms_port_if_out_octets_total` | counter | Cumulative outbound octets |
| `librenms_port_if_in_errors_total` | counter | Cumulative inbound errors |
| `librenms_port_if_out_errors_total` | counter | Cumulative outbound errors |
| `librenms_port_if_in_discards_total` | counter | Cumulative inbound discards |
| `librenms_port_if_out_discards_total` | counter | Cumulative outbound discards |

All metrics carry labels identifying the device (`device_id`, `hostname`) and the
specific port (`port_id`, `ifName`). See the
[developer reference](../../Developing/VictoriaMetrics.md#label-scheme) for the full
label specification.

---

## Migrating historical RRD data

The `victoriametrics:migrate-rrd` command reads existing RRD files and backfills data
into VictoriaMetrics so that graphs have history from day one rather than starting
empty at the cutover date.

```bash
# Always do a dry run first to confirm scope:
php artisan victoriametrics:migrate-rrd --dry-run

# Run the actual migration (all devices):
php artisan victoriametrics:migrate-rrd

# Migrate a single device:
php artisan victoriametrics:migrate-rrd --device=router1.example.com

# Restrict to a time window:
php artisan victoriametrics:migrate-rrd --start=2025-01-01 --end=2025-06-01

# Write directly to VictoriaMetrics (bypasses vmagent):
php artisan victoriametrics:migrate-rrd --url=http://victoriametrics:8428/api/v1/import/prometheus
```

### What gets migrated

By default the command migrates **gauge metrics** — data that maps losslessly from
`rrdtool fetch AVERAGE` output. Because RRD stores DERIVE-type datasources as a
per-second rate, the values are multiplied to produce the equivalent gauge:

| RRD file | DS | VictoriaMetrics metric |
|----------|----|------------------------|
| `poller-perf` | `poller` | `librenms_device_poller_duration_seconds` |
| `port-<id>` | `INOCTETS` | `librenms_port_if_in_bits_per_second` |
| `port-<id>` | `OUTOCTETS` | `librenms_port_if_out_bits_per_second` |

Pass `--counters` to also synthesize cumulative counters for error and discard
metrics. These are approximate — reconstructed by integrating the stored rate —
so the raw counter values should not be relied upon. However, graphs that use
`rate()` will reproduce the original rates accurately.

| RRD DS | VictoriaMetrics metric |
|--------|------------------------|
| `INERRORS` | `librenms_port_if_in_errors_total` |
| `OUTERRORS` | `librenms_port_if_out_errors_total` |
| `INDISCARDS` | `librenms_port_if_in_discards_total` |
| `OUTDISCARDS` | `librenms_port_if_out_discards_total` |

### Migration options

| Option | Default | Description |
|--------|---------|-------------|
| `--device` | `all` | Hostname, device ID, or `all`. |
| `--start` | _(oldest RRD data)_ | Start time passed to `rrdtool fetch` (any format rrdtool accepts). |
| `--end` | _(now)_ | End time passed to `rrdtool fetch`. |
| `--resolution` | `300` | Fetch resolution in seconds. |
| `--counters` | off | Also synthesize counter metrics from DERIVE DS data. |
| `--url` | _(from config)_ | Override the VictoriaMetrics import URL. |
| `--batch-size` | `10000` | Samples per HTTP POST. |
| `--timeout` | `30` | HTTP timeout in seconds. |
| `--dry-run` | off | Parse and count samples without sending any data. |

---

## Verifying it works

### Confirm writes are arriving

Open the VictoriaMetrics UI at `http://your-vm-host:8428` and run a MetricsQL query:

```
librenms_device_poller_duration_seconds
```

If samples appear, the write pipeline is working. If the result is empty after at
least one poller run, check that:

- `victoriametrics.enable` is `true`
- vmagent (if used) is running and can reach VictoriaMetrics — check its logs for
  remote write errors
- The poller has completed at least one run since the setting was enabled

### Confirm graph reads

Load any port graph in the LibreNMS web UI. The graph JSON API returns a `meta`
object identifying the data source:

```json
{"meta": {"source": "victoriametrics"}}
```

If `source` is `"rrd"` when you expect VictoriaMetrics, check that:

- `victoriametrics.query_enabled` is `true`
- `victoriametrics.query_url` points at a reachable VictoriaMetrics instance
- The graph type is in the supported set (not all graph types have VM bindings yet)
- There is data in VictoriaMetrics for the time range being graphed

---

## Further reading

- [VictoriaMetrics developer reference](../../Developing/VictoriaMetrics.md) — metric
  mapping contract, label scheme, MetricsQL query construction, adding VM bindings to
  graph definitions, RRD migration internals, and the full source file index.
- [VictoriaMetrics documentation](https://docs.victoriametrics.com/)
- [vmagent documentation](https://docs.victoriametrics.com/vmagent/)
