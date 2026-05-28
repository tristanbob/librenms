<?php

/**
 * RrdMigrationMapper.php
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

namespace LibreNMS\Data\Store\VictoriaMetrics;

/**
 * Maps RRD DS names to VictoriaMetrics MetricDefinitions for historical migration.
 *
 * Separate from MetricMapper because rrdtool fetch AVERAGE for DERIVE-type DSes
 * returns a per-second rate, not a raw counter. The gauge mappings here reflect
 * that semantic (rate × multiplier → gauge metric), while MetricMapper handles
 * live polling field names.
 *
 * Counter mappings synthesize approximate cumulative counters via integration
 * (rate × step). These are only used when --counters is passed to the command.
 * Graphs applying rate() to the synthesized counter recover the original rate.
 */
final class RrdMigrationMapper
{
    /**
     * Gauge mappings for port DS names.
     * Always migrated — DERIVE rate output maps losslessly to gauge metrics.
     *
     * @return array<string, array{MetricDefinition, callable(float): float}>
     */
    public static function gaugeMappings(): array
    {
        return [
            'INOCTETS' => [
                VictoriaMetricsMetricCatalog::getDefinition('port.if_in_bits_rate'),
                fn (float $v): float => $v * 8.0,
            ],
            'OUTOCTETS' => [
                VictoriaMetricsMetricCatalog::getDefinition('port.if_out_bits_rate'),
                fn (float $v): float => $v * 8.0,
            ],
        ];
    }

    /**
     * Counter synthesis mappings for port DS names.
     * Only used with --counters. Cumulative sum of (rate × step) produces an
     * approximate counter whose rate() equals the original RRD rate.
     *
     * @return array<string, array{MetricDefinition, callable(float, int): float}>
     */
    public static function counterMappings(): array
    {
        return [
            'INERRORS' => [
                VictoriaMetricsMetricCatalog::getDefinition('port.if_in_errors'),
                fn (float $rate, int $step): float => $rate * $step,
            ],
            'OUTERRORS' => [
                VictoriaMetricsMetricCatalog::getDefinition('port.if_out_errors'),
                fn (float $rate, int $step): float => $rate * $step,
            ],
            'INDISCARDS' => [
                VictoriaMetricsMetricCatalog::getDefinition('port.if_in_discards'),
                fn (float $rate, int $step): float => $rate * $step,
            ],
            'OUTDISCARDS' => [
                VictoriaMetricsMetricCatalog::getDefinition('port.if_out_discards'),
                fn (float $rate, int $step): float => $rate * $step,
            ],
        ];
    }

    /**
     * DS name in poller-perf RRDs.
     */
    public static function pollerPerfDs(): string
    {
        return 'poller';
    }

    /**
     * MetricDefinition for the poller duration gauge.
     */
    public static function pollerPerfMetric(): MetricDefinition
    {
        return VictoriaMetricsMetricCatalog::getDefinition('device.poller.duration');
    }

    /**
     * Synthesize a monotonically increasing counter from rate samples.
     *
     * Iterates $rateSamples in order, skipping null and negative rates, and
     * accumulates (rate × step) into a running total. Returns [tsMs, cumulative]
     * pairs. Callers should call this once per DS per port; the counter resets
     * to zero at the start of the available history, which is fine because graph
     * queries use rate() rather than the raw counter value.
     *
     * @param  list<array{int, float|null}>  $rateSamples  Output of parseRrdFetchOutput for one DS
     * @param  int  $stepSeconds  RRD consolidation step used during fetch
     * @return list<array{int, float}>
     */
    public static function synthesizeCounter(array $rateSamples, int $stepSeconds): array
    {
        $cumulative = 0.0;
        $result     = [];

        foreach ($rateSamples as [$tsMs, $rate]) {
            if ($rate === null || $rate < 0.0 || ! is_finite($rate)) {
                continue;
            }

            $cumulative += $rate * $stepSeconds;
            $result[]   = [$tsMs, $cumulative];
        }

        return $result;
    }
}
