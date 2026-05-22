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

class RrdGraphDataProvider implements GraphDataProvider
{
    public function __construct(
        private readonly Rrd $rrd,
        private readonly GraphDefinitionRegistry $registry,
    ) {}

    /** @throws \RuntimeException if the graph type is not registered or device_id is missing */
    public function query(GraphQuery $query): GraphDataResult
    {
        $deviceId = $query->entities['device_id'] ?? null;
        if ($deviceId === null) {
            throw new \RuntimeException(
                "GraphQuery is missing 'device_id' in entities for graph type '{$query->graphType}'."
            );
        }

        $device = device_by_id_cache($deviceId);
        $def = $this->registry->definitionFor($query->graphType);

        $result = new GraphDataResult(
            id: $def->id($device, $query),
            type: $query->graphType,
            title: $def->title($device),
            subtitle: $def->subtitle($device, $query),
            unit: $def->unit(),
            from: $query->from,
            to: $query->to,
            step: $query->step,
        );
        $result->setDisplay(array_merge(
            ['renderer' => 'timeseries', 'legend' => true, 'tooltip' => true],
            $def->display()
        ));
        foreach ($def->markers($device, $query) as $marker) {
            $result->addMarker($marker);
        }
        foreach ($def->thresholds($device, $query) as $threshold) {
            $result->addThreshold($threshold);
        }

        $groups = [];
        foreach ($def->series($device, $query) as $seriesDef) {
            $binding = $seriesDef->binding(RrdMetricBinding::SOURCE);
            if (! $binding instanceof RrdMetricBinding) {
                $result->addWarning("Series '{$seriesDef->key}' has no RRD binding; empty series returned.");
                $result->addSeries($this->emptySeries($seriesDef));
                continue;
            }

            $step = $binding->step ?? $query->step;
            $consolidation = strtoupper($binding->consolidation);
            $rrdFile = $this->rrd->name($device['hostname'], $binding->rrdName);
            $key = implode(':', [$rrdFile, $step, $consolidation]);
            $groups[$key][] = [$seriesDef, $binding, $rrdFile, $step, $consolidation];
        }

        $emptyReasons = [];
        foreach ($groups as $entries) {
            [, , $rrdFile, $step, $consolidation] = $entries[0];

            $stepQuery = $step !== $query->step
                ? $query->withStep($step)
                : $query;

            try {
                $allData = $this->fetchRrdData($rrdFile, $stepQuery, $consolidation);

                foreach ($entries as [$seriesDef, $binding]) {
                    $series = $this->emptySeries($seriesDef);

                    foreach ($allData[$binding->ds] ?? [] as [$tsMs, $value]) {
                        if ($value !== null && is_finite($value)) {
                            if ($binding->transform !== null) {
                                $value = ($binding->transform)($value);
                            }
                            $series->addPoint($tsMs, round($value, 4));
                        }
                    }

                    $result->addSeries($series);
                }
            } catch (\RuntimeException $e) {
                \Log::debug('RRD graph data fetch failed: ' . $e->getMessage());
                $emptyReasons[] = 'rrd_fetch_failed';
                foreach ($entries as [$seriesDef]) {
                    $result->addSeries($this->emptySeries($seriesDef));
                }
            }
        }

        $result->setSource(RrdMetricBinding::SOURCE);
        if ($emptyReasons !== []) {
            $result->setEmptyReason('rrd_fetch_failed');
            $result->addWarning('One or more RRD files could not be read; empty series returned.');
        }

        return $result;
    }

    private function emptySeries(GraphSeriesDefinition $seriesDef): GraphSeries
    {
        return new GraphSeries(
            name: $seriesDef->name,
            key: $seriesDef->key,
            unit: $seriesDef->unit,
            type: $seriesDef->type,
            area: $seriesDef->area,
            stack: $seriesDef->stack,
            color: $seriesDef->color,
            lineColor: $seriesDef->lineColor,
            areaOpacity: $seriesDef->areaOpacity,
            negate: $seriesDef->negate,
        );
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
        $rrdDir = LibrenmsConfig::get('rrd_dir', LibrenmsConfig::get('install_dir') . '/rrd');

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
        $lines = explode("\n", trim($output));
        $header = array_shift($lines);
        if ($header === null || trim($header) === '') {
            return [];
        }

        if (($lines[0] ?? null) !== null && trim($lines[0]) === '') {
            array_shift($lines);
        }

        $dsNames = preg_split('/\s+/', trim($header));
        $result = array_fill_keys($dsNames, []);

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            [$tsRaw, $valuesRaw] = explode(':', $line, 2);
            $values = preg_split('/\s+/', trim($valuesRaw));
            $tsMs = (int) trim($tsRaw) * 1000;

            foreach ($dsNames as $i => $ds) {
                $val = $values[$i] ?? null;
                $result[$ds][] = [
                    $tsMs,
                    ($val === null || strtolower($val) === 'nan') ? null : (float) $val,
                ];
            }
        }

        return $result;
    }
}
