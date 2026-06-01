# Graph Rendering C4 Diagrams

C4 model of how LibreNMS renders ECharts graphs from time-series data held in
either the **RRD** datastore or **VictoriaMetrics**. The diagrams reflect the
actual classes described in `Graph-Data-Architecture.md`:
the backend-neutral definition layer, the `GraphDataBackendSelector` that picks a
backend, and the client-side ECharts renderer.

Diagrams use standard Mermaid `flowchart` and `sequenceDiagram` syntax so they
render reliably on GitHub and in IDE Markdown previewers (the experimental
`C4Context` syntax is not widely supported). They follow the C4 model levels:
context, container, component, and a dynamic view.

> **For reviewers:** the two diagrams immediately below frame the change in terms
> of *risk and additivity* — what stays the same, what is new. The C4 reference
> diagrams that follow describe the resulting structure in detail.

---

## Change Summary — What Is New vs. Unchanged

The new JSON graph-data API and ECharts renderer are **purely additive**. The
legacy RRDtool image endpoint and every default are untouched; there is no
cutover and no flag to flip for existing behaviour.

```mermaid
flowchart LR
    subgraph legacy["Existing path — UNCHANGED"]
        direction TB
        oldRoute["Legacy graph.php / image endpoint<br/>GET /devices/{id}/{type} -> PNG"]
        rrdtoolGraph["rrdtool graph<br/>(server-side PNG rendering)"]
        oldRoute --> rrdtoolGraph
    end

    subgraph shared["Shared — REUSED"]
        direction TB
        defs["GraphDefinition + RRD data sources<br/>(metric identity, DS names, thresholds)"]
        rrdfiles[("RRD files on disk")]
    end

    subgraph added["New path — ADDED (opt-in)"]
        direction TB
        newRoute["JSON graph-data API<br/>GET /graph-data/.../graphs/{type}"]
        provider["GraphDataProvider stack<br/>(BackendSelector + RRD/VM providers)"]
        echarts["ECharts renderer<br/>(resources/js/graphs/*)"]
        newRoute --> provider --> echarts
    end

    rrdtoolGraph --> rrdfiles
    provider --> rrdfiles
    oldRoute -.->|reuses metric identity| defs
    provider -.->|reuses metric identity| defs

    classDef unchanged fill:#d5e8d4,stroke:#82b366,color:#000;
    classDef sharedcls fill:#fff2cc,stroke:#d6b656,color:#000;
    classDef new fill:#dae8fc,stroke:#6c8ebf,color:#000;
    class oldRoute,rrdtoolGraph unchanged;
    class defs,rrdfiles sharedcls;
    class newRoute,provider,echarts new;
```

**Reviewer takeaways**

- **Green = unchanged:** the legacy image path keeps working exactly as before — same routes, same `rrdtool graph` PNG output, same defaults.
- **Yellow = reused:** both paths read the same RRD files and the same metric identity, so there is no data duplication or divergence.
- **Blue = new and opt-in:** nothing invokes the new path unless a client requests the new endpoint or the ECharts renderer is explicitly enabled.

---

## Backend Selection Flow

How a single `query()` call resolves to a datastore, including the automatic
fallback that guarantees RRD always answers if VictoriaMetrics cannot.

```mermaid
flowchart TD
    start(["query(GraphQuery)"]) --> enabled{"victoriametrics.<br/>query_enabled?"}

    enabled -->|false| rrd["RrdGraphDataProvider.query()"]
    enabled -->|true| vm["VictoriaMetricsGraphDataProvider.query()"]

    vm --> vmok{"outcome?"}
    vmok -->|success| vmres["GraphDataResult<br/>meta.source = victoriametrics"]
    vmok -->|"NoVmBindingException<br/>(graph has no VM binding)"| rrdSilent["RrdGraphDataProvider.query()<br/>silent fallback"]
    vmok -->|"RuntimeException<br/>(HTTP / parse error)"| rrdFallback["RrdGraphDataProvider.query()<br/>fallback_used = true + warning"]

    rrd --> rrdres["GraphDataResult<br/>meta.source = rrd"]
    rrdSilent --> rrdres
    rrdFallback --> rrdresWarn["GraphDataResult<br/>meta.source = rrd<br/>meta.fallback_used = true"]

    rrdres --> done(["JSON envelope to renderer"])
    rrdresWarn --> done
    vmres --> done

    classDef decision fill:#ffe6cc,stroke:#d79b00,color:#000;
    classDef rrdcls fill:#d5e8d4,stroke:#82b366,color:#000;
    classDef vmcls fill:#dae8fc,stroke:#6c8ebf,color:#000;
    class enabled,vmok decision;
    class rrd,rrdSilent,rrdFallback,rrdres,rrdresWarn rrdcls;
    class vm,vmres vmcls;
```

**Reviewer takeaways**

- **RRD is the default and the universal safety net:** if VM is disabled, errors, or lacks a binding for the graph, RRD answers.
- **Silent vs. surfaced fallback:** a missing VM binding (`NoVmBindingException`) is expected and silent; an unexpected VM failure (`RuntimeException`) still serves RRD but flags `meta.fallback_used` and adds a user-visible warning.
- **The client never branches on backend** — it only reads `meta.source` / `meta.fallback_used` for transparency.

---

## Level 1 — System Context

Who uses the system and which external data stores it depends on.

```mermaid
flowchart TB
    operator(["NMS Operator<br/><i>Person</i><br/>Views device, port, sensor<br/>and overview graphs"])

    librenms["LibreNMS<br/><i>Software System</i><br/>Serves web pages + normalized JSON<br/>graph-data API, renders ECharts graphs"]

    rrd[("RRD Datastore<br/><i>External</i><br/>.rrd files, read via rrdtool fetch CLI.<br/>Default, always-available backend")]
    vm[("VictoriaMetrics<br/><i>External</i><br/>Optional Prometheus-compatible TSDB.<br/>Enabled via victoriametrics.query_enabled")]
    devices["Monitored Devices<br/><i>External</i><br/>Routers, switches, servers, sensors"]

    operator -->|"Views graphs (HTTPS)"| librenms
    librenms -->|"Reads time series (rrdtool fetch)"| rrd
    librenms -->|"Reads time series (HTTP / MetricsQL)"| vm
    librenms -->|"Polls metrics (SNMP / ICMP)"| devices

    classDef person fill:#08427b,stroke:#052e56,color:#fff;
    classDef system fill:#1168bd,stroke:#0b4884,color:#fff;
    classDef ext fill:#999999,stroke:#6b6b6b,color:#fff;
    class operator person;
    class librenms system;
    class rrd,vm,devices ext;
```

---

## Level 2 — Containers

The runtime building blocks. The browser renderer and the Laravel app are the two
LibreNMS-owned containers; RRD and VictoriaMetrics are external stores.

```mermaid
flowchart TB
    operator(["NMS Operator<br/><i>Person, web browser</i>"])

    subgraph librenms["LibreNMS"]
        browser["ECharts Renderer<br/><i>JavaScript — resources/js/graphs/*</i><br/>Mounts on .lnms-echart elements, fetches the JSON<br/>envelope, draws charts via lazily imported ECharts"]
        web["LibreNMS Web Application<br/><i>PHP / Laravel (PHP-FPM)</i><br/>Serves Blade pages + /graph-data/* JSON API.<br/>Resolves GraphDataProvider, returns GraphDataResult"]
    end

    rrd[("RRD Datastore<br/>rrdtool + .rrd files")]
    vm[("VictoriaMetrics<br/>Prometheus-compatible TSDB")]

    operator -->|"Loads device/port/sensor pages (HTTPS)"| browser
    browser -->|"GET /graph-data/.../graphs/{type}?from&to&width<br/>(JSON over HTTPS)"| web
    web -->|"Reads samples (rrdtool fetch CLI)"| rrd
    web -->|"query_range, optional (HTTP / MetricsQL)"| vm

    classDef person fill:#08427b,stroke:#052e56,color:#fff;
    classDef container fill:#438dd5,stroke:#2e6295,color:#fff;
    classDef ext fill:#999999,stroke:#6b6b6b,color:#fff;
    class operator person;
    class browser,web container;
    class rrd,vm ext;
```

---

## Level 3a — Components: Backend (Laravel graph-data subsystem)

Inside the `LibreNMS\Graph` namespace. `GraphServiceProvider` binds the
`GraphDataProvider` interface to a `GraphDataBackendSelector`, which delegates to
the RRD or VictoriaMetrics provider.

```mermaid
flowchart TB
    browser["ECharts Renderer<br/><i>JavaScript</i><br/>Requests JSON graph data"]

    subgraph web["LibreNMS Web Application — LibreNMS\Graph"]
        route["Graph-data Routes + Handler<br/><i>Laravel route / api_functions</i><br/>Builds GraphQuery, resolves<br/>app(GraphDataProvider::class)"]
        selector["GraphDataBackendSelector<br/><i>implements GraphDataProvider</i><br/>Tries VM if enabled; falls back to RRD on<br/>NoVmBindingException / RuntimeException"]
        abstract["AbstractGraphDataProvider<br/><i>abstract base</i><br/>Resolves variables, builds result,<br/>evaluates markers, memoizes fetches"]
        rrdProvider["RrdGraphDataProvider<br/><i>extends AbstractGraphDataProvider</i><br/>Groups series by (file, step, cf)"]
        vmProvider["VictoriaMetricsGraphDataProvider<br/><i>extends AbstractGraphDataProvider</i><br/>Builds MetricsQL, batches, query_range"]
        registry["GraphDefinitionRegistry<br/><i>registry</i><br/>type -> GraphDefinition"]
        definitions["GraphDefinition implementations<br/><i>Definitions/**</i><br/>Series, RRD + VM bindings, markers"]
        result["GraphDataResult / GraphSeries<br/><i>DTOs</i><br/>Normalized JSON envelope"]
        rrdStore["Rrd store<br/><i>LibreNMS\Data\Store\Rrd</i><br/>Wraps rrdtool fetch"]
        http["Http util<br/><i>LibreNMS\Util\Http</i><br/>HTTP client for VM query API"]
    end

    rrd[("RRD Datastore<br/>rrdtool + .rrd files")]
    vm[("VictoriaMetrics<br/>TSDB")]

    browser -->|"JSON request (HTTPS)"| route
    route -->|"query(GraphQuery)"| selector
    selector -->|"query() — default / fallback"| rrdProvider
    selector -->|"query() — when enabled"| vmProvider
    rrdProvider -.->|extends| abstract
    vmProvider -.->|extends| abstract
    abstract -->|"definitionFor(type)"| registry
    registry -->|resolves| definitions
    abstract -->|builds| result
    rrdProvider -->|fetch series| rrdStore
    rrdStore -->|"rrdtool fetch (CLI)"| rrd
    vmProvider -->|query_range| http
    http -->|"GET /api/v1/query_range (HTTP)"| vm
    route -->|"GraphDataResult JSON"| browser

    classDef container fill:#438dd5,stroke:#2e6295,color:#fff;
    classDef component fill:#85bbf0,stroke:#5d82a8,color:#000;
    classDef ext fill:#999999,stroke:#6b6b6b,color:#fff;
    class browser container;
    class route,selector,abstract,rrdProvider,vmProvider,registry,definitions,result,rrdStore,http component;
    class rrd,vm ext;
```

---

## Level 3b — Components: Frontend (ECharts renderer)

Inside `resources/js/graphs/`. ECharts itself is loaded with a dynamic `import('echarts')`
so it adds zero bundle weight when the renderer is not used.

```mermaid
flowchart TB
    subgraph browser["ECharts Renderer — resources/js/graphs"]
        mount["mountEChartsGraphs.js<br/><i>entry point</i><br/>Finds .lnms-echart[data-graph-url], manages<br/>sizing/refresh/dark-mode, calls echarts.init"]
        client["graphDataClient.js<br/><i>fetch wrapper</i><br/>fetchGraphData(url): retrieves + parses envelope"]
        options["toEChartsOptions.js<br/><i>transform</i><br/>Maps envelope to ECharts option object + THEME"]
        legend["graphLegend.js<br/><i>HTML legend</i><br/>buildHtmlLegend(): per-series legend with stats"]
        units["formatUnits.js<br/><i>formatter</i><br/>Unit-aware formatting for tooltips/axes"]
        echarts["echarts<br/><i>dynamic import</i><br/>Canvas charting lib; chart.setOption(options)"]
    end

    web["LibreNMS Web Application<br/><i>PHP / Laravel</i><br/>Serves the JSON graph-data envelope"]

    mount -->|"fetchGraphData(url)"| client
    client -->|"GET /graph-data/... (JSON over HTTPS)"| web
    mount -->|"toEChartsOptions(payload)"| options
    options -->|"formatUnits()"| units
    mount -->|"buildHtmlLegend()"| legend
    mount -->|"init / setOption"| echarts

    classDef container fill:#438dd5,stroke:#2e6295,color:#fff;
    classDef component fill:#85bbf0,stroke:#5d82a8,color:#000;
    class web container;
    class mount,client,options,legend,units,echarts component;
```

---

## Dynamic View — Rendering one graph

End-to-end flow for a single graph, showing the RRD-vs-VictoriaMetrics branch and
the automatic fallback.

```mermaid
sequenceDiagram
    autonumber
    actor Operator
    participant Mount as mountEChartsGraphs.js
    participant Client as graphDataClient.js
    participant Route as /graph-data route
    participant Selector as GraphDataBackendSelector
    participant VM as VictoriaMetricsGraphDataProvider
    participant RRD as RrdGraphDataProvider
    participant Reg as GraphDefinitionRegistry
    participant Render as toEChartsOptions.js
    participant Chart as echarts

    Operator->>Mount: Open device page (.lnms-echart)
    Mount->>Client: fetchGraphData(url?from&to&width)
    Client->>Route: GET /graph-data/devices/{id}/graphs/{type}
    Route->>Selector: query(GraphQuery)

    alt victoriametrics.query_enabled
        Selector->>VM: query(GraphQuery)
        VM->>Reg: definitionFor(type) -> series + VM bindings
        alt has VM bindings
            VM-->>Selector: GraphDataResult (meta.source = victoriametrics)
        else NoVmBindingException
            Selector->>RRD: query(GraphQuery) [silent fallback]
            RRD-->>Selector: GraphDataResult (meta.source = rrd)
        else VM HTTP/parse error (RuntimeException)
            Selector->>RRD: query(GraphQuery) [fallback]
            RRD-->>Selector: GraphDataResult (fallback_used = true)
        end
    else RRD default
        Selector->>RRD: query(GraphQuery)
        RRD->>Reg: definitionFor(type) -> series + RRD bindings
        RRD-->>Selector: GraphDataResult (meta.source = rrd)
    end

    Selector-->>Route: GraphDataResult
    Route-->>Client: JSON envelope (series, y_axes, markers, meta)
    Client-->>Mount: parsed payload
    Mount->>Render: toEChartsOptions(payload)
    Render-->>Mount: ECharts option object
    Mount->>Chart: chart.setOption(options)
    Chart-->>Operator: Rendered interactive graph
```

---

## Notes

- **Single contract:** every backend implements `GraphDataProvider::query(GraphQuery): GraphDataResult`. The browser is unaware of which datastore served the data except via `meta.source` / `meta.fallback_used`.
- **Backend selection** is config-driven in `GraphDataBackendSelector`; RRD is the default and the universal fallback.
- **Definitions are backend-neutral:** each `GraphSeriesDefinition` carries both an `RrdMetricBinding` and an optional VictoriaMetrics binding, so one definition serves both stores.
- **Renderer is presentation-only:** ECharts JS does not recompute percentiles, totals or thresholds — those arrive pre-computed in the envelope.
