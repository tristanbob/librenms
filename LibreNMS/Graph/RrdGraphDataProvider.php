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
        $groups = [];
        foreach ($def->series($device, $query) as $seriesDef) {
            $binding      = $seriesDef->binding(RrdMetricBinding::SOURCE);
            $seriesQuery  = $query;
            $shiftMs      = 0;
            if ($binding instanceof ShiftBinding) {
                $shiftMs     = $binding->offsetSeconds * 1000;
                $seriesQuery = $query->withTimeRange(
                    $query->from - $binding->offsetSeconds,
                    $query->to - $binding->offsetSeconds,
                );
                $binding = $binding->inner;
            }
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
