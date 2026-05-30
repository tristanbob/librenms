<?php

/**
 * MetricSeries.php
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

use LibreNMS\Data\Store\VictoriaMetrics\MetricCatalogEntry;
use LibreNMS\Metrics\MetricCatalog;

/**
 * Builds the (RRD, VictoriaMetrics) binding pair for a graph series from a single
 * backend-neutral catalog key. A graph definition row only needs to name the metric
 * and supply the RRD coordinates (the RRD file name is entity-derived and cannot live
 * in the catalog); whether the VictoriaMetrics side is a gauge, a counter rate, or a
 * batched aggregate is decided here from the catalog entry's type, not by the row.
 */
final class MetricSeries
{
    /**
     * Unified entry point. Resolves the VictoriaMetrics binding kind from the catalog:
     *   - $demuxValues given  -> batched aggregate (one query per shared expression)
     *   - entry is a counter  -> rate(selector[window])
     *   - entry is a gauge    -> plain selector
     *
     * @param array<string,string>|null $demuxValues per-series discriminator labels for aggregate graphs
     * @return array{0: RrdMetricBinding, 1: MetricBinding}
     */
    public static function metric(
        string $catalogKey,
        RrdMetricBinding $rrd,
        ?array $demuxValues = null,
        ?string $window = null,
        mixed $transform = null,
    ): array {
        $entry = self::entry($catalogKey);

        if ($demuxValues !== null) {
            return [$rrd, self::batchBinding($entry, $demuxValues, $window, $transform)];
        }

        return [$rrd, self::vmBinding($catalogKey, $transform, $window)];
    }

    /**
     * Resolve the VictoriaMetrics binding for a single-series catalog key. The kind is
     * derived from the catalog, not the caller: counter -> rate(selector[window]),
     * gauge -> plain selector. Shared by series and percentile markers so every VM read
     * site agrees on the expression.
     *
     * NOTE: catalog entries flagged `derived` (e.g. octets->bits) are intentionally read
     * as their stored gauge here rather than recomputed from the input counter. The RRD->VM
     * migration can only backfill the pre-computed rate/bits gauge (RRD persists consolidated
     * rates, not the raw monotonic counter), so deriving at read time would lose all migrated
     * history. The stored gauge stays canonical for those series.
     */
    public static function vmBinding(string $catalogKey, mixed $transform = null, ?string $window = null): MetricBinding
    {
        $entry = self::entry($catalogKey);

        if ($entry->definition->type === 'counter') {
            return self::rateBinding($entry, $window, $transform);
        }

        return VictoriaMetricsMetricBinding::catalog($catalogKey, $transform);
    }

    /**
     * Aggregate binding for multi-entity device graphs (e.g. per-port, per-processor).
     * Thin wrapper over {@see metric()} kept for existing call sites.
     *
     * @param array<string,string> $demuxValues
     */
    public static function aggregate(
        string $catalogKey,
        RrdMetricBinding $rrd,
        array $demuxValues,
        ?string $window = '5m',
        mixed $transform = null,
    ): array {
        return self::metric($catalogKey, $rrd, $demuxValues, $window, $transform);
    }

    public static function gauge(string $catalogKey, RrdMetricBinding $rrd, mixed $transform = null): array
    {
        return [$rrd, self::vmBinding($catalogKey, $transform)];
    }

    public static function rate(string $catalogKey, RrdMetricBinding $rrd, ?string $window = null, mixed $transform = null): array
    {
        return [$rrd, self::rateBinding(self::entry($catalogKey), $window, $transform)];
    }

    public static function expression(RrdMetricBinding $rrd, callable $expressionBuilder, array $labelKeys = ['hostname'], mixed $transform = null): array
    {
        return [
            $rrd,
            new VictoriaMetricsExpressionBinding($expressionBuilder, $labelKeys, $transform),
        ];
    }

    private static function entry(string $catalogKey): MetricCatalogEntry
    {
        $entry = MetricCatalog::get($catalogKey);
        if ($entry === null) {
            throw new \InvalidArgumentException("Unknown metric catalog key '{$catalogKey}'.");
        }

        return $entry;
    }

    private static function rateBinding(MetricCatalogEntry $entry, ?string $window, mixed $transform): VictoriaMetricsExpressionBinding
    {
        $window ??= '5m';

        return new VictoriaMetricsExpressionBinding(
            expressionBuilder: fn (array $entities): string => sprintf(
                'rate(%s[%s])',
                VictoriaMetricsGraphDataProvider::buildSelector($entry->definition->name, $entry->identityLabels, $entities),
                $window,
            ),
            labelKeys: $entry->identityLabels,
            transform: $transform,
        );
    }

    /**
     * @param array<string,string> $demuxValues
     */
    private static function batchBinding(MetricCatalogEntry $entry, array $demuxValues, ?string $window, mixed $transform): VictoriaMetricsBatchBinding
    {
        $batchLabels = array_values(array_diff($entry->identityLabels, array_keys($demuxValues)));

        return new VictoriaMetricsBatchBinding(
            batchExprBuilder: static function (array $entities) use ($entry, $batchLabels, $window): string {
                $selector = VictoriaMetricsGraphDataProvider::buildSelector(
                    $entry->definition->name,
                    $batchLabels,
                    $entities,
                );

                return $entry->definition->type === 'counter'
                    ? sprintf('rate(%s[%s])', $selector, $window ?? '5m')
                    : $selector;
            },
            demuxValues: $demuxValues,
            labelKeys: $batchLabels,
            transform: $transform,
        );
    }
}
