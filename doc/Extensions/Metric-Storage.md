hide_toc: true

# Metric storage

By default we ship all metrics to RRD files, either directly or via
[RRDCached](RRDCached.md). On top of this you can ship metrics to one or more
external backends.

Most backends are **write-only** — they receive a copy of the data and you use an
external tool like [Grafana](https://grafana.com/) to visualise it.
[VictoriaMetrics](metrics/VictoriaMetrics.md) is the exception: it can also serve
graphs directly inside LibreNMS as a replacement for RRD reads, with RRD as the
automatic fallback.

For further information on configuring LibreNMS to ship data to one of
the other backends then please see the documentation below.

- [Graphite](metrics/Graphite.md)
- [InfluxDB](metrics/InfluxDB.md)
- [InfluxDBv2](metrics/InfluxDBv2.md)
- [OpenTSDB](metrics/OpenTSDB.md)
- [Prometheus](metrics/Prometheus.md)
- [VictoriaMetrics](metrics/VictoriaMetrics.md)
