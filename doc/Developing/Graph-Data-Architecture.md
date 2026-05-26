# Graph Data Architecture

LibreNMS graph modernization is built around one rule: LibreNMS owns graph
semantics, metric backends provide samples, and renderers draw normalized graph
payloads.

## Definition Layer

Graph definitions describe a graph in LibreNMS terms — not renderer options or
raw RRDtool commands.

Two interfaces exist:

**`GraphDefinition`** — binding-based. Each series declares one or more
`RrdMetricBinding` (or `VictoriaMetricsMetricBinding`) entries. The backend
reads the named data sources and calls an optional per-series transform. This
interface works with both RRD and VictoriaMetrics and is the correct choice for
any graph that should support both backends.

**`GraphPlanDefinition extends GraphDefinition`** — expression-based. Each
series declares an expression tree (`def`, `scale`, `sum`, `divide`, `percent`,
`negate`, `percentile`, `total`, `shift`). The expression tree is evaluated
server-side by the RRD provider only. VictoriaMetrics does not implement
expression translation; any `GraphPlanDefinition` always uses RRD.

`GraphPlanDefinition` is appropriate when a series requires derived values that
cannot be expressed as a single metric binding (e.g., multi-DS math or
time-shifted averages). For straightforward one-to-one metric mappings, prefer
`GraphDefinition`.

A graph plan may also declare:

- typed variables, such as `duration`, `previous`, `inverse`, or `reducefactor`
- markers, such as thresholds and percentile lines
- renderer hints, such as area fill, stack group, line color, and y-axis bounds

## Backend Layer

`GraphDataBackendSelector` dispatches each query to VictoriaMetrics or RRD.
When VictoriaMetrics is enabled and the definition has no VM bindings (or is a
`GraphPlanDefinition`), a `NoVmBindingException` is raised and the selector
silently falls back to RRD — no warning is added to the response. An unexpected
VM failure (network error, parse error, etc.) raises `RuntimeException`, which
sets `meta.fallback_used = true` and adds a user-visible warning.

Missing RRD files or missing data sources must become structured payload
warnings rather than silent empty graphs.

## Renderer Layer

The browser receives normalized LibreNMS graph data. ECharts translates that
payload into chart options; it does not compute graph semantics such as
percentiles, totals, thresholds, billing math, or graph-variable behavior.

**Negate** (`series.style.negate`) is a display-layer concern. Data is stored
and fetched as positive values; the renderer flips the sign for visualization
(e.g., showing outbound traffic below zero on duplex graphs). This matches
legacy RRDtool's graph-layer CDEF behavior.

**Multi-Y-axis.** When a graph needs two incompatible units on separate axes,
the definition's `display()` method returns a `y_axes` key — an array of axis
objects, each with `unit`, `scale`, `min`, and `max`. Series that belong to the
secondary axis set `yAxisIndex: 1` (or higher) in their `GraphSeriesDefinition`.
The API response always carries `graph.y_axes` (an array); single-axis graphs
produce a one-element array derived from the top-level `unit`.

## API Response Caching

The graph data API sets `Cache-Control` headers based on whether the requested
time window has already passed:

- **Completed time range** (`to < now − 60 s`): `Cache-Control: public, max-age=300`
- **Live/current window**: `Cache-Control: no-store`

This allows CDNs and browser caches to reuse responses for historical queries
while always fetching fresh data for current-time graphs.

## Migration Guidance

A graph is considered **migrated** when it has a registered `GraphDefinition`
(or `GraphPlanDefinition`) class whose `graphType()` key resolves through the
registry. The legacy image endpoint continues to exist and serves graphs that
are not yet registered — there is no cutover date and no flag to flip.

When migrating a legacy graph:

1. Keep the existing image endpoint unchanged.
2. Preserve the existing graph type name and permission checks.
3. Choose `GraphDefinition` for metric-binding graphs (VM-compatible) or
   `GraphPlanDefinition` for expression-based graphs (RRD-only).
4. For `GraphPlanDefinition`: declare graph-specific request variables with
   defaults and bounds, then model RRD data and derived values as graph
   expressions.
5. Emit thresholds, percentiles, totals, and other reference lines as markers
   in the normalized payload.
6. Add tests for variables, RRD names, data sources, expressions, warnings, and
   representative API output.

Semantic parity is the target. The ECharts graph should match the legacy graph's
data, graph variables, thresholds, permissions, and major visual behavior;
pixel-perfect RRDtool image reproduction is not required.
