# Graph Metric Identity

This page documents how a time series is identified across the graph stack, why
LibreNMS does **not** use database row IDs as metric labels, and the failure
modes that follow from that decision. It applies to the VictoriaMetrics read and
write paths; RRD identifies a series by file path and is unaffected.

---

## The identity model

A series is identified by the device **hostname** plus the entity's **natural
keys** (the stable SNMP/index values), never by LibreNMS database row IDs.

| Entity | Identity labels |
|--------|-----------------|
| device | `hostname` |
| port | `hostname`, `ifIndex` |
| sensor / wireless sensor | `hostname`, `sensor_class`, `sensor_type`, `sensor_index` |
| mempool | `hostname`, `mempool_type`, `mempool_class`, `mempool_index` |
| processor | `hostname`, `processor_type`, `processor_index` |
| storage / diskio | `hostname`, `type`, `descr` |

The single source of truth for which labels are *identity* (used as query
matchers) versus *descriptive* (written for humans, never queried) is
`LibreNMS\Metrics\MetricCatalog`. The writer's label allowlist
(`LabelExtractor`) and the reader's selectors both derive from the same catalog
entry, so they cannot drift apart.

Descriptive labels (e.g. `ifName`, `module`, `af`) are written so a human or
Grafana can read them, but the LibreNMS read path never uses them to select a
series.

---

## Why not database IDs

`device_id`, `port_id`, and `sensor_id` look like the obvious join key, but they
are **not stable** under LibreNMS' current data model:

- Most LibreNMS entities are hard-deleted, not soft-deleted. A port that is
  deleted and rediscovered (re-cabling, re-indexing, a config change) gets a
  **new** `port_id` while representing the same physical interface.
- A new ID silently detaches the entity from its historical samples, so a graph
  would show a break or lose history with no error.

Using IDs as the canonical metric label would therefore require implementing
soft-deletes broadly across LibreNMS first. Until that exists, IDs are not a
safe identity. Natural keys (`ifIndex`, sensor index, ...) survive
delete/rediscover and are the lesser evil.

---

## Failure modes (accepted trade-offs)

Keying on `hostname` + natural key is stable across the common cases but has two
known breakage modes. The read path is built to degrade gracefully rather than
error.

### ifIndex reuse

`ifIndex` is only unique and stable *per device, at a point in time*. If an
interface is removed and a different physical interface later takes the same
`ifIndex`, the two share an identity. A range query that spans the reuse can
return two series for one selector.

`VictoriaMetricsGraphDataProvider::parseQueryRangeResponse()` handles this
deterministically instead of throwing:

1. select the series whose newest non-null sample has the greatest timestamp;
2. on a tie, select the series with the lexicographically smallest JSON-encoded
   label set.

A warning is logged when this happens. The consequence: the graph renders for
the most-recently-active interface, but a query spanning the reuse boundary can
**stitch two interfaces' histories into one line**. There is no surrogate key to
disambiguate, so this is accepted, logged, and documented rather than fixed.

### Hostname rename

`hostname` is the device identity, so renaming a device starts a new series. The
old samples remain under the old hostname but are no longer matched; graphs for
the renamed device read empty until new data accrues. Backfilling or relabelling
old samples after a rename is out of scope.

---

## Relationship to stored rates

Some series (e.g. `port.if_in_bits_rate`) are modelled in the catalog as
`derived` from a counter input (octets → bits). They are nonetheless **stored**
as their own gauge rather than recomputed from the counter at read time. This is
deliberate: the RRD → VictoriaMetrics migration can only backfill the
pre-computed rate/bits gauge, because RRD persists consolidated rates and not the
raw monotonic counter. Deriving at read time would lose all migrated history for
those series, so the stored gauge stays canonical.
