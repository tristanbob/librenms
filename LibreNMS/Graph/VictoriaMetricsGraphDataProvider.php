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
 * @copyright  2026 LibreNMS Contributors
 */

namespace LibreNMS\Graph;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use LibreNMS\Config as LibrenmsConfig;
use LibreNMS\Util\Http;

class VictoriaMetricsGraphDataProvider extends AbstractGraphDataProvider
{
    private const DEFAULT_QUERY_URL = 'http://127.0.0.1:8428';

    private string $queryUrl;
    private float  $timeout;
    private bool   $verifySsl;

    public function __construct(GraphDefinitionRegistry $registry)
    {
        parent::__construct($registry);
        $this->queryUrl  = rtrim(LibrenmsConfig::get('victoriametrics.query_url', self::DEFAULT_QUERY_URL), '/');
        $this->timeout   = (float) LibrenmsConfig::get('victoriametrics.timeout', 2.0);
        $this->verifySsl = (bool) LibrenmsConfig::get('victoriametrics.verify_ssl', true);
    }

    protected function fillSeries(
        GraphDataResult $result,
        GraphDefinition $def,
        array           $device,
        GraphQuery      $query
    ): void {
        foreach ($def->series($device, $query) as $seriesDef) {
            $binding = $seriesDef->binding(VictoriaMetricsMetricBinding::SOURCE);
            if (! $binding instanceof VictoriaMetricsMetricBinding) {
                $result->addSeries($this->emptySeries($seriesDef));
                continue;
            }

            try {
                $raw    = $this->fetchRange(
                    self::buildExpr($binding, $query->entities),
                    $query->from,
                    $query->to,
                    $query->step
                );
                $series = $this->emptySeries($seriesDef);
                foreach ($raw as [$tsMs, $value]) {
                    if ($value !== null) {
                        $series->addPoint($tsMs, round($value, 4));
                    }
                }
                $result->addSeries($series);
            } catch (\RuntimeException $e) {
                Log::debug('VictoriaMetrics graph data fetch failed: ' . $e->getMessage());
                $result->addWarning("VictoriaMetrics query failed for series '{$seriesDef->key}'; empty returned.");
                $result->addSeries($this->emptySeries($seriesDef));
            }
        }

        $result->setSource(VictoriaMetricsMetricBinding::SOURCE);
    }

    /**
     * Build a MetricsQL selector expression from the binding and query entities.
     * Example: librenms_device_poller_duration_seconds{device_id="42"}
     */
    public static function buildExpr(VictoriaMetricsMetricBinding $binding, array $entities): string
    {
        $matchers = [];
        foreach ($binding->labelKeys as $key) {
            $value = $entities[$key] ?? null;
            if ($value !== null) {
                $escaped    = str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], (string) $value);
                $matchers[] = $key . '="' . $escaped . '"';
            }
        }

        return $matchers === []
            ? $binding->metricName
            : $binding->metricName . '{' . implode(',', $matchers) . '}';
    }

    /**
     * Query VictoriaMetrics /api/v1/query_range and return normalised data points.
     *
     * @return list<array{int, float|null}>  Each element is [timestampMs, value]
     * @throws \RuntimeException on connection failure, HTTP error, or unexpected response shape
     */
    private function fetchRange(string $expr, int $from, int $to, int $step): array
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
     * Expects exactly one result series. Returns an empty array when the result set is empty.
     * Logs a warning when multiple series are returned (label matchers were non-selective).
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

        if (count($results) > 1) {
            Log::warning(
                "VictoriaMetrics returned " . count($results) . " series for expr '{$expr}'; using first. " .
                "Check that label matchers uniquely identify one series."
            );
        }

        $values = $results[0]['values'] ?? [];
        $points = [];
        foreach ($values as [$ts, $val]) {
            $points[] = [(int) $ts * 1000, is_numeric($val) ? (float) $val : null];
        }

        return $points;
    }
}
