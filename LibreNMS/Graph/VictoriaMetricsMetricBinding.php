<?php

/**
 * VictoriaMetricsMetricBinding.php
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

class VictoriaMetricsMetricBinding implements MetricBinding
{
    public const SOURCE = 'victoriametrics';

    /**
     * @param string        $metricName Prometheus metric name to query
     * @param string[]      $labelKeys  Keys from GraphQuery::$entities to use as MetricsQL label matchers
     * @param callable|null $transform  Applied to each raw value before storage
     */
    public function __construct(
        public readonly string $metricName,
        public readonly array  $labelKeys = ['device_id'],
        public readonly mixed  $transform = null,
    ) {}

    public function source(): string
    {
        return self::SOURCE;
    }
}
