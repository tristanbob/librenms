<?php

/**
 * VictoriaMetricsBatchBinding.php
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

/**
 * Binding for aggregate graphs that share a single VictoriaMetrics query across
 * many series. All series with the same batchExpr($entities) string are fetched in
 * one request; each series identifies its result by matching demuxValues against the
 * metric labels returned by VictoriaMetrics.
 *
 * Example: device_bits with 48 ports uses one query per direction instead of 48.
 */
final readonly class VictoriaMetricsBatchBinding implements MetricBinding
{
    public const SOURCE = VictoriaMetricsMetricBinding::SOURCE;

    /**
     * @param callable(array<string,string>): string $batchExprBuilder
     *        Returns the shared MetricsQL expression (no per-series discriminator labels).
     *        Called once per unique expr value — results are cached for the graph request.
     * @param array<string,string> $demuxValues
     *        Label => value pairs that identify this series within the batch response.
     *        E.g. ['ifIndex' => '2'] or ['processor_type' => 'intel', 'processor_index' => '0']
     * @param string[] $labelKeys  Entity keys required when building the batch expression
     */
    public function __construct(
        public readonly mixed $batchExprBuilder,
        public readonly array $demuxValues,
        public readonly array $labelKeys = ['hostname'],
        public readonly mixed $transform = null,
    ) {
    }

    public function batchExpr(array $entities): string
    {
        return ($this->batchExprBuilder)($entities);
    }

    public function source(): string
    {
        return self::SOURCE;
    }
}
