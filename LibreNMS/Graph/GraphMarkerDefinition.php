<?php

/**
 * GraphMarkerDefinition.php
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

class GraphMarkerDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly PercentileBinding|TotalBinding|float|int $value,
        public readonly string $type = 'horizontal_line',
        public readonly ?string $severity = null,
        public readonly ?string $color = null,
        public readonly string $lineStyle = 'solid',
    ) {}

    public static function percentile(string $name, MetricBinding $inner, float $percentile, ?string $color = null): self
    {
        return new self($name, new PercentileBinding($inner, $percentile), color: $color);
    }

    /**
     * Returns two markers — one backed by RRD, one by VictoriaMetrics — so the
     * correct one is used regardless of which backend is active. Each backend
     * silently returns an empty result for the marker type it doesn't own.
     *
     * @return list<self>
     */
    public static function dualPercentile(
        string $name,
        RrdMetricBinding $rrd,
        string $catalogKey,
        float $percentile,
        ?string $color = null,
        mixed $vmTransform = null,
    ): array {
        return [
            self::percentile($name, $rrd, $percentile, $color),
            self::percentile($name, MetricSeries::vmBinding($catalogKey, $vmTransform), $percentile, $color),
        ];
    }
}
