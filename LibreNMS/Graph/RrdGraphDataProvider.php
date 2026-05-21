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
 * @copyright  2024 LibreNMS Contributors
 */

namespace LibreNMS\Graph;

use LibreNMS\Config as LibrenmsConfig;
use LibreNMS\Graph\Definitions\Device\PollerPerfGraph;
use LibreNMS\Graph\Definitions\Port\BitsGraph;
use Symfony\Component\Process\Process;

class RrdGraphDataProvider implements DataProvider
{
    /**
     * Registry of supported graph types.
     * Add an entry here and create the matching GraphDefinition class to support a new graph.
     */
    private static array $definitions = [
        PollerPerfGraph::GRAPH_TYPE => PollerPerfGraph::class,
        BitsGraph::GRAPH_TYPE       => BitsGraph::class,
    ];

    /** @throws \RuntimeException if the graph type is not registered */
    public function query(GraphQuery $query, array $device): GraphDataResult
    {
        $def = $this->resolveDefinition($query->graphType);

        $result = new GraphDataResult(
            id:       $def->id($device, $query),
            type:     $query->graphType,
            title:    $def->title($device),
            subtitle: $def->subtitle($device, $query),
            unit:     $def->unit(),
            from:     $query->from,
            to:       $query->to,
            step:     $query->step,
        );

        // Group series by (rrdFile, step) so each unique combination costs one rrdtool call.
        $groups = [];
        foreach ($def->series($device, $query) as $seriesDef) {
            $step = $seriesDef->step ?? $query->step;
            $key  = $seriesDef->rrdFile . ':' . $step;
            $groups[$key][] = [$seriesDef, $step];
        }

        $emptyReasons = [];
        foreach ($groups as $entries) {
            /** @var SeriesDefinition $firstDef */
            [$firstDef, $step] = $entries[0];

            $stepQuery = $step !== $query->step
                ? new GraphQuery($query->graphType, $query->from, $query->to, $step, $query->width, $query->entities)
                : $query;

            try {
                $allData = $this->fetchRrdData($firstDef->rrdFile, $stepQuery);

                foreach ($entries as [$seriesDef]) {
                    $series = new GraphSeries(
                        name:        $seriesDef->name,
                        key:         $seriesDef->key,
                        unit:        $def->unit(),
                        area:        $seriesDef->area,
                        stack:       $seriesDef->stack,
                        color:       $seriesDef->color,
                        lineColor:   $seriesDef->lineColor,
                        areaOpacity: $seriesDef->areaOpacity,
                        negate:      $seriesDef->negate,
                    );

                    foreach ($allData[$seriesDef->ds] ?? [] as [$tsMs, $value]) {
                        if ($value !== null && is_finite($value)) {
                            if ($seriesDef->transform !== null) {
                                $value = ($seriesDef->transform)($value);
                            }
                            $series->addPoint($tsMs, round($value, 4));
                        }
                    }

                    $result->addSeries($series);
                }
            } catch (\RuntimeException $e) {
                // RRD file missing or fetch failed — add empty series so the frontend
                // still receives the correct series names and can show a "no data" state.
                \Log::debug('RRD graph data fetch failed: ' . $e->getMessage());
                $emptyReasons[] = 'rrd_fetch_failed';
                foreach ($entries as [$seriesDef]) {
                    $result->addSeries(new GraphSeries(
                        name:        $seriesDef->name,
                        key:         $seriesDef->key,
                        unit:        $def->unit(),
                        area:        $seriesDef->area,
                        stack:       $seriesDef->stack,
                        color:       $seriesDef->color,
                        lineColor:   $seriesDef->lineColor,
                        areaOpacity: $seriesDef->areaOpacity,
                        negate:      $seriesDef->negate,
                    ));
                }
            }
        }

        $result->setSource('rrd');
        if ($emptyReasons !== []) {
            $result->setEmptyReason('rrd_fetch_failed');
            $result->addWarning('One or more RRD files could not be read; empty series returned.');
        }

        return $result;
    }

    private function resolveDefinition(string $graphType): GraphDefinition
    {
        $class = self::$definitions[$graphType] ?? null;
        if ($class === null) {
            throw new \RuntimeException(
                "Graph type '{$graphType}' is not yet supported by the JSON graph data API."
            );
        }

        return new $class();
    }

    /**
     * Fetch all data sources from one RRD file for the given time range.
     *
     * Returns an array keyed by DS name; each value is a list of [timestamp_ms, float|null].
     *
     * @return array<string, list<array{int, float|null}>>
     */
    private function fetchRrdData(string $rrdFile, GraphQuery $query): array
    {
        $rrd     = app(\LibreNMS\Data\Store\Rrd::class);
        $command = $rrd->buildCommand('fetch', $rrdFile, [
            'AVERAGE',
            '--start',      (string) $query->from,
            '--end',        (string) $query->to,
            '--resolution', (string) $query->step,
        ]);

        $rawOutput = $this->executeRrdFetch($command);

        return $this->parseRrdFetchOutput($rawOutput);
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
     * rrdtool fetch prints:
     *   ds1     ds2
     *
     *   1234567890: 1.23e+00 4.56e+00
     *   ...
     *
     * @return array<string, list<array{int, float|null}>>
     */
    private function parseRrdFetchOutput(string $output): array
    {
        $lines   = explode("\n", trim($output));
        $header  = array_shift($lines);
        array_shift($lines); // blank line between header and data

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
                $val          = $values[$i] ?? null;
                $result[$ds][] = [
                    $tsMs,
                    ($val === null || strtolower($val) === 'nan') ? null : (float) $val,
                ];
            }
        }

        return $result;
    }
}
