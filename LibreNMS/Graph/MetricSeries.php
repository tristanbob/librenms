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
 * @copyright  2026 LibreNMS Contributors
 * @copyright  2026 Tristan
 */

namespace LibreNMS\Graph;

use LibreNMS\Data\Store\VictoriaMetrics\VictoriaMetricsMetricCatalog;

final class MetricSeries
{
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
