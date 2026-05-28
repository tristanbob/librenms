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

use LibreNMS\Data\Store\VictoriaMetrics\VictoriaMetricsMetricCatalog;

final class MetricSeries
{
    /**
     * Aggregate binding for multi-entity device graphs (e.g. per-port, per-processor).
     *
     * Issues ONE VictoriaMetrics query for all series that share the same batch
     * expression, then routes each result series to the matching GraphSeriesDefinition
     * using $demuxValues as the discriminator.
     *
     * @param array<string,string> $demuxValues
     *        Per-series label matchers that distinguish this entity from others in the
     *        batch result. E.g. ['ifIndex' => '2'] or ['processor_type' => 'X', 'processor_index' => '0'].
     *        These labels are omitted from the shared batch expression so all entities
     *        are returned in one query.
     */
    public static function aggregate(
        string $catalogKey,
        RrdMetricBinding $rrd,
        array $demuxValues,
        ?string $window = '5m',
        mixed $transform = null,
    ): array {
        $entry = VictoriaMetricsMetricCatalog::get($catalogKey);
        if ($entry === null) {
            throw new \InvalidArgumentException("Unknown VictoriaMetrics metric catalog key '{$catalogKey}'.");
        }

        $demuxLabels = array_keys($demuxValues);
        $batchLabels = array_values(array_diff($entry->identityLabels, $demuxLabels));

        return [
            $rrd,
            new VictoriaMetricsBatchBinding(
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
            ),
        ];
    }

    public static function gauge(string $catalogKey, RrdMetricBinding $rrd, mixed $transform = null): array
    {
        return [$rrd, VictoriaMetricsMetricBinding::catalog($catalogKey, $transform)];
    }

    public static function rate(string $catalogKey, RrdMetricBinding $rrd, ?string $window = null, mixed $transform = null): array
    {
        $entry = VictoriaMetricsMetricCatalog::get($catalogKey);
        if ($entry === null) {
            throw new \InvalidArgumentException("Unknown VictoriaMetrics metric catalog key '{$catalogKey}'.");
        }

        $window ??= '5m';

        return [
            $rrd,
            new VictoriaMetricsExpressionBinding(
                expressionBuilder: fn (array $entities): string => sprintf(
                    'rate(%s[%s])',
                    VictoriaMetricsGraphDataProvider::buildSelector($entry->definition->name, $entry->identityLabels, $entities),
                    $window,
                ),
                labelKeys: $entry->identityLabels,
                transform: $transform,
            ),
        ];
    }

    public static function expression(RrdMetricBinding $rrd, callable $expressionBuilder, array $labelKeys = ['hostname'], mixed $transform = null): array
    {
        return [
            $rrd,
            new VictoriaMetricsExpressionBinding($expressionBuilder, $labelKeys, $transform),
        ];
    }
}
