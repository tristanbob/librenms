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
 * @copyright  2026 Tristan Rhodes
 * @author     Tristan Rhodes <tristan.rhodes@gmail.com>
 */

namespace LibreNMS\Graph;

use LibreNMS\Data\Store\Rrd;
use LibreNMS\Exceptions\RrdException;
use LibreNMS\RRD\RrdProcess;

class RrdGraphDataProvider extends AbstractGraphDataProvider
{
    public function __construct(
        private readonly Rrd $rrd,
        private readonly RrdProcess $rrdProcess,
        GraphDefinitionRegistry $registry,
    ) {
        parent::__construct($registry);
    }

    protected function fillSeries(
        GraphDataResult $result,
        GraphDefinition $def,
        GraphContext    $context
    ): void {
        $device = $context;
        $query  = $context->query;
        $groups = [];
        foreach ($def->series($context) as $seriesDef) {
            $rawBinding = $seriesDef->binding(RrdMetricBinding::SOURCE);
            [$binding, $seriesQuery, $shiftMs] = $rawBinding === null
                ? [null, $query, 0]
                : $this->unwrapShift($rawBinding, $query);
            if (! $binding instanceof RrdMetricBinding) {
                $result->addWarning("Series '{$seriesDef->key}' has no RRD binding; empty series returned.");
                $result->addSeries($this->emptySeries($seriesDef));
                continue;
            }

            $step          = $binding->step ?? $seriesQuery->step;
            $consolidation = strtoupper($binding->consolidation);
            $rrdFile       = $this->rrd->name($device['hostname'], $binding->rrdName);
            $key           = implode(':', [$rrdFile, $step, $consolidation, $shiftMs]);
            $groups[$key][] = [$seriesDef, $binding, $rrdFile, $step, $consolidation, $seriesQuery, $shiftMs];
        }

        $fetchFailed = false;
        foreach ($groups as $entries) {
            [, , $rrdFile, $step, $consolidation, $seriesQuery, $shiftMs] = $entries[0];

            $stepQuery = $step !== $seriesQuery->step
                ? $seriesQuery->withStep($step)
                : $seriesQuery;

            try {
                $allData = $this->fetchRrdData($rrdFile, $stepQuery, $consolidation);

                foreach ($entries as [$seriesDef, $binding]) {
                    $series = $this->emptySeries($seriesDef);

                    foreach ($this->pointsForBinding($allData, $binding) as [$tsMs, $value]) {
                        $series->addPoint($tsMs + $shiftMs, round($value, 4));
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

    /**
     * @inheritDoc
     */
    protected function evaluateBindingPoints(MetricBinding $binding, GraphContext $context): array
    {
        $device = $context;
        $query  = $context->query;
        $binding = $this->innerBinding($binding);

        if (! $binding instanceof RrdMetricBinding) {
            return [];
        }

        $step          = $binding->step ?? $query->step;
        $consolidation = strtoupper($binding->consolidation);
        $rrdFile       = $this->rrd->name($device['hostname'], $binding->rrdName);
        $stepQuery     = $step !== $query->step
            ? $query->withStep($step)
            : $query;

        try {
            $allData = $this->fetchRrdData($rrdFile, $stepQuery, $consolidation);
        } catch (\RuntimeException) {
            return [];
        }

        $points = [];
        foreach ($this->pointsForBinding($allData, $binding) as [$tsMs, $value]) {
            $points[$tsMs] = $value;
        }

        return $points;
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
     * Fetch all data sources from one RRD file for the given time range.
     *
     * @return array<string, list<array{int, float|null}>>
     */
    private function fetchRrdData(string $rrdFile, GraphQuery $query, string $consolidation): array
    {
        $cacheKey = implode('|', ['rrd', $rrdFile, $query->from, $query->to, $query->step, $consolidation]);

        return $this->memoizeFetch($cacheKey, function () use ($rrdFile, $query, $consolidation): array {
            // Keep RRD reads as explicit fetches so RRD and VictoriaMetrics receive the
            // same range/step contract. xport/graphv remain options for future graphs
            // that need RRDtool-side CDEF/VDEF parity rather than backend-neutral points.
            $command = $this->rrd->buildCommand('fetch', $rrdFile, [
                $consolidation,
                '--start', (string) $query->from,
                '--end', (string) $query->to,
                '--resolution', (string) $query->step,
            ]);

            return self::parseRrdFetchOutput($this->executeRrdFetch($command));
        });
    }

    private function executeRrdFetch(array $command): string
    {
        try {
            return $this->rrdProcess->run($this->formatRrdCommand($command));
        } catch (RrdException $e) {
            throw new \RuntimeException('rrdtool fetch failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param string[] $command
     */
    private function formatRrdCommand(array $command): string
    {
        return implode(' ', array_map(
            fn (string $arg) => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $arg) . '"',
            $command
        ));
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
                $val = $values[$i] ?? null;
                // rrdtool renders unknown/non-finite samples as NaN/-nan/nan/inf depending on
                // platform; is_numeric() rejects them all so they become a gap (null) rather
                // than (float) casting "-nan" to 0.0 and fabricating data the legacy graph omits.
                $result[$ds][] = [
                    $tsMs,
                    ($val === null || ! is_numeric($val)) ? null : (float) $val,
                ];
            }
        }

        return $result;
    }
}
