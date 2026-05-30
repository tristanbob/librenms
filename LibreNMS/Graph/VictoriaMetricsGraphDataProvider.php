<?php

/**
 * VictoriaMetricsGraphDataProvider.php
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @link       https://www.librenms.org
 *
 * @copyright  2026 Tristan Rhodes
 * @author     Tristan Rhodes <tristan.rhodes@gmail.com>
 */

namespace LibreNMS\Graph;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use LibreNMS\Config as LibrenmsConfig;
use LibreNMS\Graph\Exception\NoVmBindingException;
use LibreNMS\Util\Http;

class VictoriaMetricsGraphDataProvider extends AbstractGraphDataProvider
{
    private const DEFAULT_QUERY_URL = 'http://127.0.0.1:8428';

    private readonly string $queryUrl;
    private readonly float  $timeout;
    private readonly bool   $verifySsl;

    public function __construct(GraphDefinitionRegistry $registry)
    {
        parent::__construct($registry);
        $this->queryUrl  = rtrim((string) LibrenmsConfig::get('victoriametrics.query_url', self::DEFAULT_QUERY_URL), '/');
        $this->timeout   = (float) LibrenmsConfig::get('victoriametrics.timeout', 10.0);
        $this->verifySsl = (bool) LibrenmsConfig::get('victoriametrics.verify_ssl', true);
    }

    protected function fillSeries(
        GraphDataResult $result,
        GraphDefinition $def,
        GraphContext    $context
    ): void {
        $query  = $context->query;
        $series = $def->series($context);

        $hasVmBinding = false;
        foreach ($series as $seriesDef) {
            if ($this->isVictoriaMetricsBinding($seriesDef->binding(VictoriaMetricsMetricBinding::SOURCE))) {
                $hasVmBinding = true;
                break;
            }
        }
        if (! $hasVmBinding) {
            throw new NoVmBindingException(
                "No VictoriaMetrics bindings defined for graph type '{$query->graphType}'; RRD should be used."
            );
        }

        // Pre-fetch all batch groups so each unique expression is queried once.
        $batchCache = $this->prefetchBatchGroups($series, $query);

        foreach ($series as $seriesDef) {
            $rawBinding = $seriesDef->binding(VictoriaMetricsMetricBinding::SOURCE);
            [$binding, $seriesQuery, $shiftMs] = $rawBinding === null
                ? [null, $query, 0]
                : $this->unwrapShift($rawBinding, $query);

            // Batch binding: route from pre-fetched results by demux label matching.
            if ($binding instanceof VictoriaMetricsBatchBinding) {
                $batchExpr   = $binding->batchExpr($query->entities);
                $batchResult = $this->demuxBatchResult($batchCache[$batchExpr] ?? [], $binding->demuxValues);
                $s           = $this->emptySeries($seriesDef);
                foreach ($batchResult as [$tsMs, $value]) {
                    if ($value !== null) {
                        if ($binding->transform !== null) {
                            $value = ($binding->transform)($value);
                        }
                        if ($value !== null && is_finite($value)) {
                            $s->addPoint($tsMs + $shiftMs, round($value, 4));
                        }
                    }
                }
                $result->addSeries($s);
                continue;
            }

            if (! $this->isVictoriaMetricsBinding($binding)) {
                $result->addSeries($this->emptySeries($seriesDef));
                continue;
            }

            try {
                $raw = $this->fetchRange(
                    self::buildExpr($binding, $query->entities),
                    $seriesQuery->from,
                    $seriesQuery->to,
                    $seriesQuery->step,
                );
                $s = $this->emptySeries($seriesDef);
                foreach ($raw as [$tsMs, $value]) {
                    if ($value !== null) {
                        if ($binding->transform !== null) {
                            $value = ($binding->transform)($value);
                        }

                        if ($value === null || ! is_finite($value)) {
                            continue;
                        }

                        $s->addPoint($tsMs + $shiftMs, round($value, 4));
                    }
                }
                $result->addSeries($s);
            } catch (\RuntimeException $e) {
                Log::debug('VictoriaMetrics graph data fetch failed: ' . $e->getMessage());
                throw $e;
            }
        }

        $result->setSource(VictoriaMetricsMetricBinding::SOURCE);
    }

    /**
     * @inheritDoc
     */
    protected function evaluateBindingPoints(MetricBinding $binding, GraphContext $context): array
    {
        $query = $context->query;
        $binding = $this->innerBinding($binding);

        if (! $this->isVictoriaMetricsBinding($binding)) {
            return [];
        }

        try {
            $raw = $this->fetchRange(
                self::buildExpr($binding, $query->entities),
                $query->from,
                $query->to,
                $query->step,
            );
        } catch (\RuntimeException) {
            return [];
        }

        $points = [];
        foreach ($raw as [$tsMs, $value]) {
            if ($value !== null && $binding->transform !== null) {
                $value = ($binding->transform)($value);
            }

            if ($value !== null && is_finite($value)) {
                $points[$tsMs] = $value;
            }
        }

        return $points;
    }

    /**
     * Build a MetricsQL selector expression from the binding and query entities.
     * Example: librenms_device_poller_duration_seconds{hostname="myhost"}
     */
    public static function buildExpr(MetricBinding $binding, array $entities): string
    {
        if ($binding instanceof VictoriaMetricsExpressionBinding) {
            self::assertRequiredLabels($binding->labelKeys, $entities);

            return $binding->expression($entities);
        }

        if (! $binding instanceof VictoriaMetricsMetricBinding) {
            throw new \RuntimeException('Unsupported VictoriaMetrics binding type.');
        }

        return self::buildSelector($binding->metricName, $binding->labelKeys, $entities);
    }

    /**
     * @param string[] $labelKeys
     */
    public static function buildSelector(string $metricName, array $labelKeys, array $entities): string
    {
        self::assertRequiredLabels($labelKeys, $entities);

        $matchers = [];
        foreach ($labelKeys as $key) {
            $value = $entities[$key] ?? null;
            if ($value !== null) {
                $escaped    = str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], (string) $value);
                $matchers[] = $key . '="' . $escaped . '"';
            }
        }

        return $matchers === []
            ? $metricName
            : $metricName . '{' . implode(',', $matchers) . '}';
    }

    /**
     * @param string[] $labelKeys
     */
    private static function assertRequiredLabels(array $labelKeys, array $entities): void
    {
        foreach ($labelKeys as $key) {
            if (! array_key_exists($key, $entities) || $entities[$key] === null || $entities[$key] === '') {
                throw new \RuntimeException("VictoriaMetrics query is missing required label '{$key}'.");
            }
        }
    }

    /**
     * @phpstan-assert-if-true VictoriaMetricsMetricBinding|VictoriaMetricsExpressionBinding $binding
     */
    private function isVictoriaMetricsBinding(?MetricBinding $binding): bool
    {
        return $binding instanceof VictoriaMetricsMetricBinding
            || $binding instanceof VictoriaMetricsExpressionBinding;
    }

    /**
     * Query VictoriaMetrics /api/v1/query_range and return normalised data points.
     *
     * @return list<array{int, float|null}>  Each element is [timestampMs, value]
     * @throws \RuntimeException on connection failure, HTTP error, or unexpected response shape
     */
    private function fetchRange(string $expr, int $from, int $to, int $step): array
    {
        $cacheKey = implode('|', ['vm', $expr, $from, $to, $step]);

        return $this->memoizeFetch($cacheKey, fn () => $this->fetchRangeUncached($expr, $from, $to, $step));
    }

    /**
     * @return list<array{int, float|null}>
     * @throws \RuntimeException on connection failure, HTTP error, or unexpected response shape
     */
    private function fetchRangeUncached(string $expr, int $from, int $to, int $step): array
    {
        try {
            $response = Http::client()
                ->timeout($this->timeout)
                ->withOptions(['verify' => $this->verifySsl])
                ->get($this->queryUrl . '/api/v1/query_range', [
                    'query' => $expr,
                    'start' => $from,
                    'end'   => $to,
                    'step'  => $step,
                ]);
        } catch (ConnectionException $e) {
            throw new \RuntimeException('VictoriaMetrics connection failed: ' . $e->getMessage(), 0, $e);
        }

        if ($response->failed()) {
            throw new \RuntimeException(
                "VictoriaMetrics HTTP {$response->status()} for expr '{$expr}': " . $response->body()
            );
        }

        return self::parseQueryRangeResponse($response->body(), $expr);
    }

    /**
     * Parse a Prometheus JSON matrix response into [tsMs, value] pairs.
     *
     * A single-entity binding is expected to resolve to one series. If more than one is
     * returned (e.g. ifIndex was reused so two physical ports share the same identity, or a
     * selector was under-constrained), we no longer throw: we select the most-recently-active
     * series and log a warning, so the graph still renders for the live entity.
     *
     * Selection rule (deterministic):
     *   1. pick the series whose newest non-null sample has the greatest timestamp;
     *   2. on a tie, pick the series with the lexicographically smallest JSON-encoded label set.
     *
     * Known failure mode (no surrogate key, identity = hostname+ifIndex): if an ifIndex is
     * reused by a different physical interface, the rule returns whichever interface reported
     * most recently and can stitch two interfaces' histories into one line. A hostname rename
     * simply yields no match (empty series) until the new name accrues data.
     *
     * @return list<array{int, float|null}>
     * @throws \RuntimeException on malformed JSON or unexpected resultType
     */
    public static function parseQueryRangeResponse(string $body, string $expr = ''): array
    {
        $decoded = json_decode($body, true);

        if (! is_array($decoded) || ($decoded['status'] ?? '') !== 'success') {
            throw new \RuntimeException(
                "VictoriaMetrics returned unexpected response for '{$expr}': " . substr($body, 0, 200)
            );
        }

        $resultType = $decoded['data']['resultType'] ?? null;
        if ($resultType !== 'matrix') {
            throw new \RuntimeException(
                "VictoriaMetrics returned resultType '{$resultType}' (expected 'matrix') for '{$expr}'."
            );
        }

        $results = $decoded['data']['result'] ?? [];
        if (count($results) === 0) {
            return [];
        }

        $series = count($results) === 1
            ? $results[0]
            : self::selectMostRecentSeries($results, $expr);

        $values = $series['values'] ?? [];
        $points = [];
        foreach ($values as [$ts, $val]) {
            $points[] = [(int) $ts * 1000, is_numeric($val) ? (float) $val : null];
        }

        return $points;
    }

    /**
     * Pick the most-recently-active series from an over-broad matrix result and warn.
     * See {@see parseQueryRangeResponse} for the rule and its failure mode.
     *
     * @param  list<array{metric?: array<string,string>, values?: list<array{int|string, string}>}> $results
     * @return array{metric?: array<string,string>, values?: list<array{int|string, string}>}
     */
    private static function selectMostRecentSeries(array $results, string $expr): array
    {
        $best = null;
        $bestTs = PHP_INT_MIN;
        $bestKey = null;

        foreach ($results as $result) {
            $newest = self::newestNonNullTimestamp($result['values'] ?? []);
            $key    = json_encode($result['metric'] ?? [], JSON_THROW_ON_ERROR);

            if ($best === null || $newest > $bestTs || ($newest === $bestTs && $key < $bestKey)) {
                $best    = $result;
                $bestTs  = $newest;
                $bestKey = $key;
            }
        }

        Log::warning(sprintf(
            'VictoriaMetrics returned %d series for expr %s; selected the most recently active one (labels %s). '
            . 'A single-entity selector should match one series - check for ifIndex reuse or an under-constrained query.',
            count($results),
            $expr,
            (string) $bestKey,
        ));

        return $best ?? $results[0];
    }

    /**
     * Greatest timestamp (seconds) among non-null samples, or PHP_INT_MIN if none.
     *
     * @param list<array{int|string, string}> $values
     */
    private static function newestNonNullTimestamp(array $values): int
    {
        $newest = PHP_INT_MIN;
        foreach ($values as [$ts, $val]) {
            if (is_numeric($val)) {
                $newest = max($newest, (int) $ts);
            }
        }

        return $newest;
    }

    /**
     * Parse a Prometheus JSON matrix response that may contain multiple series.
     * Used for batch queries where one expression returns one series per entity.
     *
     * @return list<array{metric: array<string,string>, points: list<array{int, float|null}>}>
     * @throws \RuntimeException on malformed JSON or unexpected resultType
     */
    public static function parseBatchQueryRangeResponse(string $body, string $expr = ''): array
    {
        $decoded = json_decode($body, true);

        if (! is_array($decoded) || ($decoded['status'] ?? '') !== 'success') {
            throw new \RuntimeException(
                "VictoriaMetrics returned unexpected response for '{$expr}': " . substr($body, 0, 200)
            );
        }

        $resultType = $decoded['data']['resultType'] ?? null;
        if ($resultType !== 'matrix') {
            throw new \RuntimeException(
                "VictoriaMetrics returned resultType '{$resultType}' (expected 'matrix') for '{$expr}'."
            );
        }

        $out = [];
        foreach ($decoded['data']['result'] ?? [] as $item) {
            $points = [];
            foreach ($item['values'] ?? [] as [$ts, $val]) {
                $points[] = [(int) $ts * 1000, is_numeric($val) ? (float) $val : null];
            }
            $out[] = ['metric' => (array) ($item['metric'] ?? []), 'points' => $points];
        }

        return $out;
    }

    /**
     * Pre-fetch all unique batch expressions for a series list, returning a cache
     * keyed by expression string. Series without a batch binding are skipped.
     *
     * @param  GraphSeriesDefinition[] $series
     * @return array<string, list<array{metric: array<string,string>, points: list<array{int, float|null}>}>>
     */
    private function prefetchBatchGroups(array $series, GraphQuery $query): array
    {
        $exprs = [];
        foreach ($series as $seriesDef) {
            $binding = $seriesDef->binding(VictoriaMetricsMetricBinding::SOURCE);
            if ($binding instanceof VictoriaMetricsBatchBinding) {
                $exprs[$binding->batchExpr($query->entities)] = true;
            }
        }

        $cache = [];
        foreach (array_keys($exprs) as $expr) {
            try {
                $cache[$expr] = $this->fetchBatchRange($expr, $query->from, $query->to, $query->step);
            } catch (\RuntimeException $e) {
                Log::debug('VictoriaMetrics batch fetch failed: ' . $e->getMessage());
                throw $e;
            }
        }

        return $cache;
    }

    /**
     * Find the single batch result whose metric labels contain all $demuxValues.
     * Returns an empty points list when no match is found (entity not in VM yet).
     *
     * @param  list<array{metric: array<string,string>, points: list<array{int, float|null}>}> $batchResults
     * @param  array<string,string> $demuxValues
     * @return list<array{int, float|null}>
     */
    private function demuxBatchResult(array $batchResults, array $demuxValues): array
    {
        foreach ($batchResults as $item) {
            foreach ($demuxValues as $label => $value) {
                if (($item['metric'][$label] ?? null) !== $value) {
                    continue 2;
                }
            }

            return $item['points'];
        }

        return [];
    }

    /**
     * @return list<array{metric: array<string,string>, points: list<array{int, float|null}>}>
     * @throws \RuntimeException on connection failure, HTTP error, or unexpected response shape
     */
    private function fetchBatchRange(string $expr, int $from, int $to, int $step): array
    {
        try {
            $response = Http::client()
                ->timeout($this->timeout)
                ->withOptions(['verify' => $this->verifySsl])
                ->get($this->queryUrl . '/api/v1/query_range', [
                    'query' => $expr,
                    'start' => $from,
                    'end'   => $to,
                    'step'  => $step,
                ]);
        } catch (ConnectionException $e) {
            throw new \RuntimeException('VictoriaMetrics connection failed: ' . $e->getMessage(), 0, $e);
        }

        if ($response->failed()) {
            throw new \RuntimeException(
                "VictoriaMetrics HTTP {$response->status()} for expr '{$expr}': " . $response->body()
            );
        }

        return self::parseBatchQueryRangeResponse($response->body(), $expr);
    }
}
