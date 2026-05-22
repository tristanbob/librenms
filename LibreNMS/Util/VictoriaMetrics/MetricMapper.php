<?php

/**
 * MetricMapper.php
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

namespace LibreNMS\Util\VictoriaMetrics;

class MetricMapper
{
    /**
     * @var array<string, array<string, array{name: string, type: string, unit: string}>>
     */
    private const MAP = [
        'poller-perf' => [
            'poller' => [
                'name' => 'librenms_device_poller_duration_seconds',
                'type' => 'gauge',
                'unit' => 'seconds',
            ],
        ],
        'ports' => [
            'INOCTETS' => [
                'name' => 'librenms_port_if_in_octets_total',
                'type' => 'counter',
                'unit' => 'octets',
            ],
            'OUTOCTETS' => [
                'name' => 'librenms_port_if_out_octets_total',
                'type' => 'counter',
                'unit' => 'octets',
            ],
            'INERRORS' => [
                'name' => 'librenms_port_if_in_errors_total',
                'type' => 'counter',
                'unit' => 'errors',
            ],
            'OUTERRORS' => [
                'name' => 'librenms_port_if_out_errors_total',
                'type' => 'counter',
                'unit' => 'errors',
            ],
            'INDISCARDS' => [
                'name' => 'librenms_port_if_in_discards_total',
                'type' => 'counter',
                'unit' => 'discards',
            ],
            'OUTDISCARDS' => [
                'name' => 'librenms_port_if_out_discards_total',
                'type' => 'counter',
                'unit' => 'discards',
            ],
            'ifInBits_rate' => [
                'name' => 'librenms_port_if_in_bits_per_second',
                'type' => 'gauge',
                'unit' => 'bits_per_second',
            ],
            'ifOutBits_rate' => [
                'name' => 'librenms_port_if_out_bits_per_second',
                'type' => 'gauge',
                'unit' => 'bits_per_second',
            ],
        ],
    ];

    public static function map(string $measurement, string $field): ?MetricDefinition
    {
        $definition = self::MAP[$measurement][$field] ?? null;

        return $definition === null
            ? null
            : new MetricDefinition($definition['name'], $definition['type'], $definition['unit']);
    }
}
