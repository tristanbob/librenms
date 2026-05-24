<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Models\Port;
use Illuminate\Console\Command;
use LibreNMS\Config as LibrenmsConfig;
use LibreNMS\Graph\GraphDataBackendSelector;
use LibreNMS\Graph\GraphDataResult;
use LibreNMS\Graph\GraphDefinitionRegistry;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Graph\RrdGraphDataProvider;
use LibreNMS\Graph\VictoriaMetricsGraphDataProvider;

class GraphCompareCommand extends Command
{
    protected $signature = 'graph:compare
        {graph_type : JSON graph type, for example port_bits or device_poller_perf}
        {--device= : Device id or hostname. Defaults to the first device.}
        {--port= : Port id, required for port graphs unless a device has a port.}
        {--from= : Unix timestamp. Defaults to 24 hours ago.}
        {--to= : Unix timestamp. Defaults to now.}
        {--width=1200 : Graph width used to derive the default step.}
        {--height=300 : Graph height.}
        {--step= : Explicit step in seconds.}
        {--iterations=3 : Timed iterations per provider.}
        {--warmup=1 : Untimed warmup iterations per provider.}
        {--query-url= : VictoriaMetrics base URL, for example http://victoriametrics:8428.}
        {--abs-tolerance=0.001 : Allowed absolute difference per matched point.}
        {--percent-tolerance=5 : Allowed percent difference per matched point.}
        {--min-match-ratio=0.95 : Required matched RRD point ratio for each series.}
        {--max-skew= : Allowed timestamp skew in seconds. Defaults to half the query step.}
        {--json : Emit machine-readable JSON.}';

    protected $description = 'Compare RRD and VictoriaMetrics graph data timing and accuracy for one graph.';

    public function handle(
        GraphDefinitionRegistry $registry,
        RrdGraphDataProvider $rrdProvider,
    ): int {
        require_once base_path('includes/common.php');

        $originalQueryEnabled = LibrenmsConfig::get('victoriametrics.query_enabled', false);
        $originalQueryUrl = LibrenmsConfig::get('victoriametrics.query_url', null);

        $graphType = (string) $this->argument('graph_type');
        $def = $registry->definitionFor($graphType);

        try {
            if ($this->option('query-url')) {
                LibrenmsConfig::set('victoriametrics.query_url', rtrim((string) $this->option('query-url'), '/'));
            }

            $query = $this->makeQuery($def->entityType(), $graphType);
            $vmProvider = new VictoriaMetricsGraphDataProvider($registry);

            $rrd = $this->measure('rrd', fn () => $rrdProvider->query($query));
            $vmError = null;
            try {
                $vm = $this->measure('victoriametrics', fn () => $vmProvider->query($query));
            } catch (\RuntimeException $e) {
                $vm = null;
                $vmError = $e->getMessage();
            }

            $comparison = $vm === null
                ? [
                    'series' => [],
                    'summary' => [
                        'failed' => false,
                        'series_count' => 0,
                        'matched_points' => 0,
                        'max_abs_delta' => null,
                        'max_percent_delta' => null,
                        'abs_tolerance' => (float) $this->option('abs-tolerance'),
                        'percent_tolerance' => (float) $this->option('percent-tolerance'),
                        'min_match_ratio' => (float) $this->option('min-match-ratio'),
                        'max_skew_seconds' => (int) (($this->option('max-skew') !== null) ? $this->option('max-skew') : max(1, (int) floor($query->step / 2))),
                    ],
                ]
                : $this->compareResults(
                    $rrd['result'],
                    $vm['result'],
                    (float) $this->option('abs-tolerance'),
                    (float) $this->option('percent-tolerance'),
                    (float) $this->option('min-match-ratio'),
                    (int) (($this->option('max-skew') !== null) ? $this->option('max-skew') : max(1, (int) floor($query->step / 2))),
                );

            $selectorChecks = $this->selectorChecks($rrdProvider, $vmProvider, $query);

            $report = [
                'graph_type' => $graphType,
                'scope' => $query->scope,
                'entities' => $query->entities,
                'range' => [
                    'from' => $query->from,
                    'to' => $query->to,
                    'step' => $query->step,
                    'width' => $query->width,
                    'height' => $query->height,
                ],
                'timing_ms' => [
                    'rrd' => $rrd['timing_ms'],
                    'victoriametrics' => $vm['timing_ms'] ?? null,
                ],
                'meta' => [
                    'rrd' => $rrd['result']->toArray()['graph']['meta'],
                    'victoriametrics' => $vm === null ? null : $vm['result']->toArray()['graph']['meta'],
                ],
                'direct_victoriametrics_error' => $vmError,
                'series' => $comparison['series'],
                'summary' => $comparison['summary'],
                'selector_checks' => $selectorChecks,
            ];

            if ($this->option('json')) {
                $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->printReport($report);
            }

            $selectorFailed = $vm === null
                && ! ($selectorChecks['query_enabled']['source'] === 'rrd' && $selectorChecks['query_enabled']['fallback_used']);

            return ($comparison['summary']['failed'] || $selectorFailed) ? 1 : 0;
        } finally {
            LibrenmsConfig::set('victoriametrics.query_enabled', $originalQueryEnabled);
            if ($originalQueryUrl !== null) {
                LibrenmsConfig::set('victoriametrics.query_url', $originalQueryUrl);
            }
        }
    }

    private function makeQuery(string $scope, string $graphType): GraphQuery
    {
        $to = (int) ($this->option('to') ?: time());
        $from = (int) ($this->option('from') ?: $to - 86400);
        $width = (int) $this->option('width');
        $height = (int) $this->option('height');
        $step = $this->option('step') !== null ? (int) $this->option('step') : null;

        if ($scope === 'port') {
            $port = $this->resolvePort();

            return new GraphQuery(
                'port',
                $graphType,
                $from,
                $to,
                $width,
                $height,
                [
                    'device_id' => $port->device_id,
                    'port_id' => $port->port_id,
                    'port_name' => $port->ifName ?: $port->ifDescr,
                ],
                step: $step,
            );
        }

        $device = $this->resolveDevice();

        return new GraphQuery(
            'device',
            $graphType,
            $from,
            $to,
            $width,
            $height,
            ['device_id' => $device->device_id],
            step: $step,
        );
    }

    private function resolveDevice(): Device
    {
        $device = $this->option('device');
        $query = Device::query();

        if ($device !== null) {
            ctype_digit((string) $device)
                ? $query->where('device_id', (int) $device)
                : $query->where('hostname', (string) $device);
        }

        return $query->orderBy('device_id')->firstOrFail();
    }

    private function resolvePort(): Port
    {
        $query = Port::query();

        if ($this->option('port') !== null) {
            return $query->where('port_id', (int) $this->option('port'))->firstOrFail();
        }

        if ($this->option('device') !== null) {
            $device = $this->resolveDevice();
            $query->where('device_id', $device->device_id);
        }

        return $query
            ->orderByRaw("CASE WHEN ifName = 'lo' OR ifDescr = 'lo' THEN 1 ELSE 0 END")
            ->orderBy('port_id')
            ->firstOrFail();
    }

    /**
     * @return array{result: GraphDataResult, timing_ms: array{min: float, max: float, avg: float, samples: list<float>}}
     */
    private function measure(string $name, callable $query): array
    {
        $warmup = max(0, (int) $this->option('warmup'));
        for ($i = 0; $i < $warmup; $i++) {
            $query();
        }

        $iterations = max(1, (int) $this->option('iterations'));
        $samples = [];
        $result = null;
        for ($i = 0; $i < $iterations; $i++) {
            $start = hrtime(true);
            $result = $query();
            $samples[] = round((hrtime(true) - $start) / 1_000_000, 3);
        }

        if (! $result instanceof GraphDataResult) {
            throw new \RuntimeException("Graph provider {$name} did not return a graph result.");
        }

        return [
            'result' => $result,
            'timing_ms' => [
                'min' => min($samples),
                'max' => max($samples),
                'avg' => round(array_sum($samples) / count($samples), 3),
                'samples' => $samples,
            ],
        ];
    }

    private function selectorChecks(
        RrdGraphDataProvider $rrdProvider,
        VictoriaMetricsGraphDataProvider $vmProvider,
        GraphQuery $query
    ): array {
        $selector = new GraphDataBackendSelector($rrdProvider, $vmProvider);

        LibrenmsConfig::set('victoriametrics.query_enabled', false);
        $rrdSelected = $selector->query($query)->toArray()['graph']['meta'];

        LibrenmsConfig::set('victoriametrics.query_enabled', true);
        $vmSelected = $selector->query($query)->toArray()['graph']['meta'];

        return [
            'query_disabled' => [
                'source' => $rrdSelected['source'],
                'fallback_used' => $rrdSelected['fallback_used'],
                'warnings' => $rrdSelected['warnings'],
            ],
            'query_enabled' => [
                'source' => $vmSelected['source'],
                'fallback_used' => $vmSelected['fallback_used'],
                'warnings' => $vmSelected['warnings'],
            ],
        ];
    }

    private function compareResults(
        GraphDataResult $rrd,
        GraphDataResult $vm,
        float $absTolerance,
        float $percentTolerance,
        float $minMatchRatio,
        int $maxSkewSeconds,
    ): array {
        $rrdSeries = $this->seriesByKey($rrd);
        $vmSeries = $this->seriesByKey($vm);
        $keys = array_values(array_unique(array_merge(array_keys($rrdSeries), array_keys($vmSeries))));
        sort($keys);

        $seriesReports = [];
        $failed = false;
        $worstAbs = 0.0;
        $worstPercent = 0.0;
        $matchedTotal = 0;

        foreach ($keys as $key) {
            $report = $this->compareSeries(
                $key,
                $rrdSeries[$key]['data'] ?? [],
                $vmSeries[$key]['data'] ?? [],
                $absTolerance,
                $percentTolerance,
                $minMatchRatio,
                $maxSkewSeconds * 1000,
            );

            $failed = $failed || $report['failed'];
            $worstAbs = max($worstAbs, $report['max_abs_delta'] ?? 0.0);
            $worstPercent = max($worstPercent, $report['max_percent_delta'] ?? 0.0);
            $matchedTotal += $report['matched_points'];
            $seriesReports[] = $report;
        }

        return [
            'series' => $seriesReports,
            'summary' => [
                'failed' => $failed,
                'series_count' => count($seriesReports),
                'matched_points' => $matchedTotal,
                'max_abs_delta' => round($worstAbs, 6),
                'max_percent_delta' => round($worstPercent, 6),
                'abs_tolerance' => $absTolerance,
                'percent_tolerance' => $percentTolerance,
                'min_match_ratio' => $minMatchRatio,
                'max_skew_seconds' => $maxSkewSeconds,
            ],
        ];
    }

    private function seriesByKey(GraphDataResult $result): array
    {
        $series = [];
        foreach ($result->toArray()['graph']['series'] as $entry) {
            $series[$entry['key']] = $entry;
        }

        return $series;
    }

    private function compareSeries(
        string $key,
        array $rrdPoints,
        array $vmPoints,
        float $absTolerance,
        float $percentTolerance,
        float $minMatchRatio,
        int $maxSkewMs,
    ): array {
        $vmByTimestamp = [];
        foreach ($vmPoints as [$ts, $value]) {
            $vmByTimestamp[(int) $ts] = (float) $value;
        }

        $usedVm = [];
        $absDeltas = [];
        $percentDeltas = [];
        $exceeded = 0;
        $matched = 0;
        $missingVm = 0;

        foreach ($rrdPoints as [$rrdTs, $rrdValue]) {
            $matchTs = $this->nearestTimestamp((int) $rrdTs, $vmByTimestamp, $usedVm, $maxSkewMs);
            if ($matchTs === null) {
                $missingVm++;
                continue;
            }

            $usedVm[$matchTs] = true;
            $matched++;
            $vmValue = $vmByTimestamp[$matchTs];
            $absDelta = abs((float) $rrdValue - $vmValue);
            $percentDelta = abs((float) $rrdValue) > 0.000001
                ? ($absDelta / abs((float) $rrdValue)) * 100
                : ($absDelta > 0.000001 ? 100.0 : 0.0);

            $absDeltas[] = $absDelta;
            $percentDeltas[] = $percentDelta;

            if ($absDelta > $absTolerance && $percentDelta > $percentTolerance) {
                $exceeded++;
            }
        }

        $extraVm = count($vmPoints) - count($usedVm);
        $matchRatio = count($rrdPoints) > 0 ? $matched / count($rrdPoints) : ($matched > 0 ? 1.0 : 0.0);

        return [
            'key' => $key,
            'rrd_points' => count($rrdPoints),
            'victoriametrics_points' => count($vmPoints),
            'matched_points' => $matched,
            'match_ratio' => round($matchRatio, 6),
            'missing_victoriametrics_points' => $missingVm,
            'extra_victoriametrics_points' => $extraVm,
            'avg_abs_delta' => $absDeltas === [] ? null : round(array_sum($absDeltas) / count($absDeltas), 6),
            'max_abs_delta' => $absDeltas === [] ? null : round(max($absDeltas), 6),
            'avg_percent_delta' => $percentDeltas === [] ? null : round(array_sum($percentDeltas) / count($percentDeltas), 6),
            'max_percent_delta' => $percentDeltas === [] ? null : round(max($percentDeltas), 6),
            'exceeded_tolerance_points' => $exceeded,
            'failed' => $matched === 0 || $matchRatio < $minMatchRatio || $exceeded > 0,
        ];
    }

    private function nearestTimestamp(int $timestamp, array $candidates, array $used, int $maxSkewMs): ?int
    {
        if (isset($candidates[$timestamp]) && ! isset($used[$timestamp])) {
            return $timestamp;
        }

        $nearest = null;
        $nearestDistance = $maxSkewMs + 1;
        foreach ($candidates as $candidate => $_) {
            if (isset($used[$candidate])) {
                continue;
            }

            $distance = abs($timestamp - (int) $candidate);
            if ($distance <= $maxSkewMs && $distance < $nearestDistance) {
                $nearest = (int) $candidate;
                $nearestDistance = $distance;
            }
        }

        return $nearest;
    }

    private function printReport(array $report): void
    {
        $this->line("Graph: {$report['graph_type']} ({$report['scope']})");
        $this->line('Entities: ' . json_encode($report['entities'], JSON_UNESCAPED_SLASHES));
        $this->line("Range: {$report['range']['from']} -> {$report['range']['to']} step={$report['range']['step']}");
        $this->newLine();

        $timingRows = [
            ['rrd', $report['timing_ms']['rrd']['min'], $report['timing_ms']['rrd']['avg'], $report['timing_ms']['rrd']['max'], implode(', ', $report['timing_ms']['rrd']['samples'])],
        ];
        if ($report['timing_ms']['victoriametrics'] !== null) {
            $timingRows[] = ['victoriametrics', $report['timing_ms']['victoriametrics']['min'], $report['timing_ms']['victoriametrics']['avg'], $report['timing_ms']['victoriametrics']['max'], implode(', ', $report['timing_ms']['victoriametrics']['samples'])];
        }
        $this->table(['provider', 'min ms', 'avg ms', 'max ms', 'samples'], $timingRows);

        if ($report['direct_victoriametrics_error'] !== null) {
            $this->warn('Direct VictoriaMetrics query skipped/failed: ' . $report['direct_victoriametrics_error']);
        }

        if ($report['series'] !== []) {
            $this->table([
                'series',
                'rrd',
                'vm',
                'matched',
                'match %',
                'missing vm',
                'extra vm',
                'avg abs',
                'max abs',
                'avg %',
                'max %',
                'bad points',
                'status',
            ], array_map(fn ($series) => [
                $series['key'],
                $series['rrd_points'],
                $series['victoriametrics_points'],
                $series['matched_points'],
                round($series['match_ratio'] * 100, 2),
                $series['missing_victoriametrics_points'],
                $series['extra_victoriametrics_points'],
                $series['avg_abs_delta'] ?? 'n/a',
                $series['max_abs_delta'] ?? 'n/a',
                $series['avg_percent_delta'] ?? 'n/a',
                $series['max_percent_delta'] ?? 'n/a',
                $series['exceeded_tolerance_points'],
                $series['failed'] ? 'fail' : 'ok',
            ], $report['series']));
        }

        $this->line('Selector query_disabled: source=' . $report['selector_checks']['query_disabled']['source'] . ' fallback=' . ($report['selector_checks']['query_disabled']['fallback_used'] ? 'true' : 'false'));
        $this->line('Selector query_enabled:  source=' . $report['selector_checks']['query_enabled']['source'] . ' fallback=' . ($report['selector_checks']['query_enabled']['fallback_used'] ? 'true' : 'false'));
        foreach ($report['selector_checks']['query_enabled']['warnings'] as $warning) {
            $this->warn('VM selector warning: ' . $warning);
        }

        $summary = $report['summary'];
        $this->newLine();
        $this->line('Summary: matched=' . $summary['matched_points'] . ' max_abs_delta=' . ($summary['max_abs_delta'] ?? 'n/a') . ' max_percent_delta=' . ($summary['max_percent_delta'] ?? 'n/a'));
        if ($summary['failed']) {
            $this->error('Comparison failed tolerance or had no matched points for at least one series.');
        } else {
            $this->info('Comparison passed.');
        }
    }
}
