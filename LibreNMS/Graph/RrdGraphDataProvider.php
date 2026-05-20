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
use Symfony\Component\Process\Process;

class RrdGraphDataProvider
{
    /** @throws \RuntimeException if graph type unsupported */
    public function query(GraphQuery $query, array $device): GraphDataResult
    {
        return match ($query->graphType) {
            PollerPerfGraph::GRAPH_TYPE => $this->queryPollerPerf($query, $device),
            default => throw new \RuntimeException(
                "Graph type '{$query->graphType}' is not yet supported by the JSON graph data API."
            ),
        };
    }

    private function queryPollerPerf(GraphQuery $query, array $device): GraphDataResult
    {
        $def    = new PollerPerfGraph();
        $result = new GraphDataResult(
            id:       PollerPerfGraph::GRAPH_TYPE . ':' . $device['device_id'],
            type:     PollerPerfGraph::GRAPH_TYPE,
            title:    $def->title($device),
            subtitle: $device['hostname'],
            unit:     $def->unit(),
            from:     $query->from,
            to:       $query->to,
            step:     $query->step,
        );

        $palette = 'rainbow_stats_purple';

        $series = new GraphSeries(
            name:  $def->seriesName(),
            key:   'poller_time',
            unit:  $def->unit(),
            area:  true,
            color: LibrenmsConfig::get("graph_colours.$palette.0"),
        );

        $rrdFile = $def->rrdFile($device);

        try {
            $rows = $this->fetchRrdData($rrdFile, $def->dataSource(), $query);
            foreach ($rows as [$ts, $value]) {
                if ($value !== null && is_finite($value)) {
                    $series->addPoint($ts * 1000, round($value, 4));
                }
            }
            $result->addSeries($series);

            // Fetch average series only if primary fetch succeeded
            $timeDiff = $query->to - $query->from;

            // 1 Hour Average
            $series1h = new GraphSeries(
                name:  '1 hour avg',
                key:   'poller_time_1h',
                unit:  $def->unit(),
                area:  false,
                color: LibrenmsConfig::get("graph_colours.$palette.4"),
            );
            try {
                $query1h = new GraphQuery(
                    graphType: $query->graphType,
                    from:      $query->from,
                    to:        $query->to,
                    step:      3600,
                    width:     $query->width,
                    entities:  $query->entities,
                );
                $rows1h = $this->fetchRrdData($rrdFile, $def->dataSource(), $query1h);
                foreach ($rows1h as [$ts, $value]) {
                    if ($value !== null && is_finite($value)) {
                        $series1h->addPoint($ts * 1000, round($value, 4));
                    }
                }
                $result->addSeries($series1h);
            } catch (\RuntimeException $e) {
                // Ignore hourly fetch errors
            }

            // 1 Day Average (timeDiff >= 129600)
            if ($timeDiff >= 129600) {
                $series1d = new GraphSeries(
                    name:  '1 day avg',
                    key:   'poller_time_1d',
                    unit:  $def->unit(),
                    area:  false,
                    color: LibrenmsConfig::get("graph_colours.$palette.5"),
                );
                try {
                    $query1d = new GraphQuery(
                        graphType: $query->graphType,
                        from:      $query->from,
                        to:        $query->to,
                        step:      86400,
                        width:     $query->width,
                        entities:  $query->entities,
                    );
                    $rows1d = $this->fetchRrdData($rrdFile, $def->dataSource(), $query1d);
                    foreach ($rows1d as [$ts, $value]) {
                        if ($value !== null && is_finite($value)) {
                            $series1d->addPoint($ts * 1000, round($value, 4));
                        }
                    }
                    $result->addSeries($series1d);
                } catch (\RuntimeException $e) {
                    // Ignore daily fetch errors
                }
            }

            // 1 Week Average (timeDiff >= 691200)
            if ($timeDiff >= 691200) {
                $series1w = new GraphSeries(
                    name:  '1 week avg',
                    key:   'poller_time_1w',
                    unit:  $def->unit(),
                    area:  false,
                    color: LibrenmsConfig::get("graph_colours.$palette.6"),
                );
                try {
                    $query1w = new GraphQuery(
                        graphType: $query->graphType,
                        from:      $query->from,
                        to:        $query->to,
                        step:      604800,
                        width:     $query->width,
                        entities:  $query->entities,
                    );
                    $rows1w = $this->fetchRrdData($rrdFile, $def->dataSource(), $query1w);
                    foreach ($rows1w as [$ts, $value]) {
                        if ($value !== null && is_finite($value)) {
                            $series1w->addPoint($ts * 1000, round($value, 4));
                        }
                    }
                    $result->addSeries($series1w);
                } catch (\RuntimeException $e) {
                    // Ignore weekly fetch errors
                }
            }

        } catch (\RuntimeException $e) {
            // RRD file missing or fetch failed — return empty series
            $result->setFallback(true);
            $result->addSeries($series);
        }

        $result->setSource('rrd');

        return $result;
    }

    /**
     * Runs: rrdtool fetch <file> AVERAGE --start <from> --end <to> --resolution <step>
     * Returns array of [unix_timestamp, float|null].
     */
    private function fetchRrdData(string $rrdFile, string $ds, GraphQuery $query): array
    {
        $rrd     = app(\LibreNMS\Data\Store\Rrd::class);
        $command = $rrd->buildCommand('fetch', $rrdFile, [
            'AVERAGE',
            '--start', (string) $query->from,
            '--end',   (string) $query->to,
            '--resolution', (string) $query->step,
        ]);

        $rawOutput = $this->executeRrdFetch($command);

        return $this->parseRrdFetchOutput($rawOutput, $ds);
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

    private function parseRrdFetchOutput(string $output, string $targetDs): array
    {
        $lines  = explode("\n", trim($output));
        $header = array_shift($lines);
        array_shift($lines); // blank line

        $dsNames = preg_split('/\s+/', trim($header));
        $dsIndex = array_search($targetDs, $dsNames);
        if ($dsIndex === false) {
            return [];
        }

        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            [$tsRaw, $valuesRaw] = explode(':', $line, 2);
            $values = preg_split('/\s+/', trim($valuesRaw));
            $val    = $values[$dsIndex] ?? null;
            $rows[] = [
                (int) trim($tsRaw),
                ($val === null || strtolower($val) === 'nan') ? null : (float) $val,
            ];
        }

        return $rows;
    }
}
