<?php

/**
 * RrdGraphDataProvider.php
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

use LibreNMS\Config as LibrenmsConfig;
use LibreNMS\Data\Store\Rrd;
use Symfony\Component\Process\Process;

class RrdGraphDataProvider extends AbstractGraphDataProvider
{
    public function __construct(
        private readonly Rrd $rrd,
        GraphDefinitionRegistry $registry,
    ) {
        parent::__construct($registry);
    }

    protected function fillSeries(
        GraphDataResult $result,
        GraphDefinition $def,
        array           $device,
        GraphQuery      $query
    ): void {
        if ($def instanceof GraphPlanDefinition) {
            $this->fillSeriesFromPlan($result, $def, $device, $query);

            return;
        }

        $groups = [];
        foreach ($def->series($device, $query) as $seriesDef) {
            $binding = $seriesDef->binding(RrdMetricBinding::SOURCE);
            if (! $binding instanceof RrdMetricBinding) {
                $result->addWarning("Series '{$seriesDef->key}' has no RRD binding; empty series returned.");
                $result->addSeries($this->emptySeries($seriesDef));
                continue;
            }

            $step          = $binding->step ?? $query->step;
            $consolidation = strtoupper($binding->consolidation);
            $rrdFile       = $this->rrd->name($device['hostname'], $binding->rrdName);
            $key           = implode(':', [$rrdFile, $step, $consolidation]);
            $groups[$key][] = [$seriesDef, $binding, $rrdFile, $step, $consolidation];
        }

        $fetchFailed = false;
        foreach ($groups as $entries) {
            [, , $rrdFile, $step, $consolidation] = $entries[0];

            $stepQuery = $step !== $query->step
                ? $query->withStep($step)
                : $query;

            try {
                $allData = $this->fetchRrdData($rrdFile, $stepQuery, $consolidation);

                foreach ($entries as [$seriesDef, $binding]) {
                    $series = $this->emptySeries($seriesDef);

                    foreach ($this->pointsForBinding($allData, $binding) as [$tsMs, $value]) {
                        $series->addPoint($tsMs, round($value, 4));
                    }

                    $result->addSeries($series);
                }
            } catch (\RuntimeException $e) {
                \Log::debug('RRD graph data fetch failed: ' . $e->getMessage());
                $fetchFailed = true;
                foreach ($entries as [$seriesDef]) {
                    $result->addSeries($this->emptySeries($seriesDef));
                }
            }
        }

        $result->setSource(RrdMetricBinding::SOURCE);
        if ($fetchFailed) {
            $result->setEmptyReason('rrd_fetch_failed');
            $result->addWarning('One or more RRD files could not be read; empty series returned.');
        }
    }

    private function fillSeriesFromPlan(
        GraphDataResult $result,
        GraphPlanDefinition $def,
        array $device,
        GraphQuery $query
    ): void {
        $plan = $def->expressions($device, $query);
        $cache = [];

        foreach ($plan->series as $seriesDef) {
            $series = $this->emptySeries($seriesDef);
            if (! $seriesDef->expression instanceof GraphExpression) {
                $result->addWarning("Series '{$seriesDef->key}' has no graph expression; empty series returned.");
                $result->addSeries($series);
                continue;
            }

            foreach ($this->evaluateExpression($seriesDef->expression, $device, $query, $cache, $result) as $tsMs => $value) {
                if ($value !== null && is_finite($value)) {
                    $series->addPoint((int) $tsMs, round($value, 4));
                }
            }

            $result->addSeries($series);
        }

        foreach ($plan->markers as $marker) {
            if ($marker instanceof GraphMarkerDefinition) {
                $value = is_numeric($marker->value)
                    ? (float) $marker->value
                    : $this->evaluateScalarExpression($marker->value, $device, $query, $cache, $result);
                if ($value === null || ! is_finite($value)) {
                    continue;
                }
                $result->addMarker(array_filter([
                    'type' => $marker->type,
                    'name' => $marker->name,
                    'value' => round($value, 4),
                    'severity' => $marker->severity,
                    'color' => $marker->color,
                    'lineStyle' => $marker->lineStyle,
                ], fn ($value) => $value !== null));
                continue;
            }

            $result->addMarker($marker);
        }

        $result->setSource(RrdMetricBinding::SOURCE);
    }

    /**
     * @param array<string, list<array{int, float|null}>> $allData
     * @return list<array{int, float}>
     */
    private function pointsForBinding(array $allData, RrdMetricBinding $binding): array
    {
        if (is_string($binding->ds)) {
            $points = [];
            foreach ($allData[$binding->ds] ?? [] as [$tsMs, $value]) {
                if ($value === null || ! is_finite($value)) {
                    continue;
                }
                if ($binding->transform !== null) {
                    $value = ($binding->transform)($value);
                }
                if ($value !== null && is_finite($value)) {
                    $points[] = [$tsMs, (float) $value];
                }
            }

            return $points;
        }

        $dsNames = $binding->ds;
        $firstDs = reset($dsNames);
        if (! is_string($firstDs)) {
            return [];
        }

        $points = [];
        foreach ($allData[$firstDs] ?? [] as $i => [$tsMs]) {
            $values = [];
            foreach ($dsNames as $ds) {
                $value = $allData[$ds][$i][1] ?? null;
                if ($value === null || ! is_finite($value)) {
                    continue 2;
                }
                $values[$ds] = $value;
            }

            $value = $binding->transform !== null ? ($binding->transform)($values) : null;
            if ($value !== null && is_finite($value)) {
                $points[] = [$tsMs, (float) $value];
            }
        }

        return $points;
    }

    /**
     * @return array<int, float|null>
     */
    private function evaluateExpression(
        GraphExpression $expression,
        array $device,
        GraphQuery $query,
        array &$cache,
        GraphDataResult $result,
    ): array {
        $args = $expression->arguments;

        return match ($expression->type) {
            'def' => $this->evaluateDef($args, $device, $query, $cache, $result),
            'scale' => $this->mapExpression(
                $this->evaluateExpression($args['expression'], $device, $query, $cache, $result),
                fn (float $value): float => $value * $args['factor']
            ),
            'sum' => $this->combineExpressions($args['expressions'], $device, $query, $cache, $result, fn (array $values): float => array_sum($values)),
            'max' => $this->combineExpressions($args['expressions'], $device, $query, $cache, $result, fn (array $values): float => max($values)),
            'divide' => $this->divideExpressions($args['numerator'], $args['denominator'], $device, $query, $cache, $result),
            'shift' => $this->shiftExpression($args['expression'], (int) $args['seconds'], $device, $query, $cache, $result),
            default => [],
        };
    }

    private function evaluateScalarExpression(
        GraphExpression $expression,
        array $device,
        GraphQuery $query,
        array &$cache,
        GraphDataResult $result,
    ): ?float {
        $args = $expression->arguments;
        if ($expression->type === 'percentile') {
            $values = array_values(array_filter(
                $this->evaluateExpression($args['expression'], $device, $query, $cache, $result),
                fn ($value) => $value !== null && is_finite($value)
            ));
            sort($values, SORT_NUMERIC);
            if ($values === []) {
                return null;
            }

            $idx = (count($values) - 1) * ((float) $args['percentile'] / 100);
            $lo = (int) floor($idx);
            $hi = (int) ceil($idx);

            return $lo === $hi ? $values[$lo] : $values[$lo] + ($values[$hi] - $values[$lo]) * ($idx - $lo);
        }

        if ($expression->type === 'total') {
            return array_sum(array_filter(
                $this->evaluateExpression($args['expression'], $device, $query, $cache, $result),
                fn ($value) => $value !== null && is_finite($value)
            ));
        }

        $values = array_filter(
            $this->evaluateExpression($expression, $device, $query, $cache, $result),
            fn ($value) => $value !== null && is_finite($value)
        );

        return $values === [] ? null : (float) end($values);
    }

    private function evaluateDef(
        array $args,
        array $device,
        GraphQuery $query,
        array &$cache,
        GraphDataResult $result,
    ): array {
        $step = $args['step'] ?? $query->step;
        $consolidation = strtoupper($args['consolidation'] ?? 'AVERAGE');
        $rrdFile = $this->rrd->name($device['hostname'], $args['rrdName']);
        $key = implode(':', [$rrdFile, $step, $consolidation]);

        if (! array_key_exists($key, $cache)) {
            try {
                $cache[$key] = $this->fetchRrdData($rrdFile, $step === $query->step ? $query : $query->withStep($step), $consolidation);
            } catch (\RuntimeException $e) {
                \Log::debug('RRD graph data fetch failed: ' . $e->getMessage());
                $result->setEmptyReason('rrd_fetch_failed');
                $result->addWarning("RRD file could not be read for graph data: {$rrdFile}");
                $cache[$key] = [];
            }
        }

        $ds = $args['ds'];
        if (! array_key_exists($ds, $cache[$key])) {
            $result->addWarning("RRD data source '{$ds}' is missing in {$rrdFile}.");

            return [];
        }

        $points = [];
        foreach ($cache[$key][$ds] as [$tsMs, $value]) {
            $points[$tsMs] = $value === null || ! is_finite($value) ? null : (float) $value;
        }

        return $points;
    }

    private function mapExpression(array $points, callable $callback): array
    {
        foreach ($points as $tsMs => $value) {
            $points[$tsMs] = $value === null ? null : $callback($value);
        }

        return $points;
    }

    private function combineExpressions(
        array $expressions,
        array $device,
        GraphQuery $query,
        array &$cache,
        GraphDataResult $result,
        callable $callback,
    ): array {
        $sets = array_map(fn (GraphExpression $expression) => $this->evaluateExpression($expression, $device, $query, $cache, $result), $expressions);
        $timestamps = array_unique(array_merge(...array_map('array_keys', $sets)));
        sort($timestamps);
        $points = [];
        foreach ($timestamps as $tsMs) {
            $values = [];
            foreach ($sets as $set) {
                if (! isset($set[$tsMs]) || $set[$tsMs] === null) {
                    $points[$tsMs] = null;
                    continue 2;
                }
                $values[] = $set[$tsMs];
            }
            $points[$tsMs] = $callback($values);
        }

        return $points;
    }

    private function divideExpressions(
        GraphExpression $numerator,
        GraphExpression $denominator,
        array $device,
        GraphQuery $query,
        array &$cache,
        GraphDataResult $result,
    ): array {
        $left = $this->evaluateExpression($numerator, $device, $query, $cache, $result);
        $right = $this->evaluateExpression($denominator, $device, $query, $cache, $result);
        $timestamps = array_unique(array_merge(array_keys($left), array_keys($right)));
        sort($timestamps);
        $points = [];
        foreach ($timestamps as $tsMs) {
            $den = $right[$tsMs] ?? null;
            $num = $left[$tsMs] ?? null;
            $points[$tsMs] = $num === null || $den === null || $den == 0.0 ? null : $num / $den;
        }

        return $points;
    }

    private function shiftExpression(
        GraphExpression $expression,
        int $seconds,
        array $device,
        GraphQuery $query,
        array &$cache,
        GraphDataResult $result,
    ): array {
        $shifted = [];
        foreach ($this->evaluateExpression($expression, $device, $query, $cache, $result) as $tsMs => $value) {
            $shifted[$tsMs + ($seconds * 1000)] = $value;
        }

        return $shifted;
    }

    /**
     * Fetch all data sources from one RRD file for the given time range.
     *
     * @return array<string, list<array{int, float|null}>>
     */
    private function fetchRrdData(string $rrdFile, GraphQuery $query, string $consolidation): array
    {
        $command = $this->rrd->buildCommand('fetch', $rrdFile, [
            $consolidation,
            '--start', (string) $query->from,
            '--end', (string) $query->to,
            '--resolution', (string) $query->step,
        ]);

        return self::parseRrdFetchOutput($this->executeRrdFetch($command));
    }

    private function executeRrdFetch(array $command): string
    {
        $rrdtool = LibrenmsConfig::get('rrdtool', 'rrdtool');
        $rrdDir  = LibrenmsConfig::get('rrd_dir', LibrenmsConfig::get('install_dir') . '/rrd');

        $proc = new Process(array_merge([$rrdtool], $command), $rrdDir);
        $proc->run();

        if (! $proc->isSuccessful()) {
            throw new \RuntimeException('rrdtool fetch failed: ' . $proc->getErrorOutput());
        }

        return $proc->getOutput();
    }

    /**
     * Parse rrdtool fetch output into per-DS data arrays.
     *
     * @return array<string, list<array{int, float|null}>>
     */
    public static function parseRrdFetchOutput(string $output): array
    {
        $lines  = explode("\n", trim($output));
        $header = array_shift($lines);
        if ($header === null || trim($header) === '') {
            return [];
        }

        if (($lines[0] ?? null) !== null && trim($lines[0]) === '') {
            array_shift($lines);
        }

        $dsNames = preg_split('/\s+/', trim($header));
        $result  = array_fill_keys($dsNames, []);

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            [$tsRaw, $valuesRaw] = explode(':', $line, 2);
            $values = preg_split('/\s+/', trim($valuesRaw));
            $tsMs   = (int) trim($tsRaw) * 1000;

            foreach ($dsNames as $i => $ds) {
                $val           = $values[$i] ?? null;
                $result[$ds][] = [
                    $tsMs,
                    ($val === null || strtolower($val) === 'nan') ? null : (float) $val,
                ];
            }
        }

        return $result;
    }
}
