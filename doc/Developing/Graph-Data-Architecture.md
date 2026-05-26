# Graph Data Architecture

LibreNMS graph modernization is built around one rule: LibreNMS owns graph
semantics, metric backends provide samples, and renderers draw normalized graph
payloads.

## Definition Layer

Graph definitions should describe the graph in LibreNMS terms instead of
emitting renderer options or raw RRDtool commands. New long-term definitions
should implement `GraphPlanDefinition`.

A graph plan declares:

- typed variables, such as `duration`, `previous`, `inverse`, or `reducefactor`
- series expressions, such as `def`, `scale`, `sum`, `divide`, `percent`,
  `negate`, `percentile`, `total`, and `shift`
- markers, such as thresholds and percentile lines
- renderer hints, such as area fill, stack group, line color, and y-axis bounds

Existing `GraphDefinition` classes are still supported while graph families are
migrated. Convenience builders such as simple stats, duplex, multi-line, and
stacked-area graphs should be implemented on top of graph plans instead of
embedding backend or renderer behavior.

## Backend Layer

Backends evaluate graph plans. The RRD backend groups required data by RRD file,
consolidation, and step, then evaluates expressions server-side. Missing RRD
files or missing data sources must become structured payload warnings rather
than silent empty graphs.

VictoriaMetrics and future backends should implement the same graph semantics
where possible. If a backend cannot evaluate a graph plan, it should fail
clearly or defer to the configured fallback behavior.

## Renderer Layer

The browser receives normalized LibreNMS graph data. ECharts should translate
that payload into chart options; it should not compute graph semantics such as
percentiles, totals, thresholds, billing math, or graph-variable behavior.

This keeps rendered output consistent across backends and makes non-ECharts
renderers possible without duplicating graph math.

## Migration Guidance

When migrating a legacy graph:

1. Keep the existing image endpoint unchanged.
2. Preserve the existing graph type name and permission checks.
3. Declare graph-specific request variables with defaults and bounds.
4. Model RRD data and derived values as graph expressions.
5. Emit thresholds, percentiles, totals, and other reference lines as markers or
   statistics in the normalized payload.
6. Add tests for variables, RRD names, data sources, expressions, warnings, and
   representative API output.

Semantic parity is the target. The ECharts graph should match the legacy graph's
data, graph variables, thresholds, statistics, permissions, and major visual
behavior; pixel-perfect RRDtool image reproduction is not required.
