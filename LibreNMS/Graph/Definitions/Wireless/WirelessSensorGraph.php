<?php

/**
 * WirelessSensorGraph.php
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

namespace LibreNMS\Graph\Definitions\Wireless;

use LibreNMS\Graph\GraphDefinition;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Graph\GraphSeriesDefinition;
use LibreNMS\Graph\RrdMetricBinding;

class WirelessSensorGraph implements GraphDefinition
{
    private const UNIT_MAP = [
        'clients'      => '',
        'rssi'         => 'dBm',
        'signal'       => 'dBm',
        'snr'          => 'dB',
        'noise-floor'  => 'dBm',
        'quality'      => '%',
        'utilization'  => '%',
        'rate'         => 'bps',
        'mse'          => '',
        'ber'          => '',
        'sinr'         => 'dB',
        'cinr'         => 'dB',
        'power'        => 'dBm',
        'errors'       => '',
        'frequency'    => 'MHz',
        'distance'     => 'm',
        'capacity'     => 'bps',
        'ccq'          => '%',
    ];

    public function graphType(): string { return 'wireless_sensor'; }

    public function entityType(): string { return 'wireless_sensor'; }

    public function unit(array $device, GraphQuery $query): string
    {
        return self::UNIT_MAP[$query->entities['sensor_class'] ?? ''] ?? '';
    }

    public function id(array $device, GraphQuery $query): string
    {
        return 'wireless_' . ($query->entities['sensor_class'] ?? '') . ':' . ($query->entities['sensor_id'] ?? '');
    }

    public function title(array $device): string
    {
        return 'Wireless Sensor';
    }

    public function subtitle(array $device, GraphQuery $query): string
    {
        return ($device['hostname'] ?? '') . ' — ' . ($query->entities['sensor_descr'] ?? '');
    }

    public function series(array $device, GraphQuery $query): array
    {
        $e       = $query->entities;
        $rrdName = ['wireless-sensor', $e['sensor_class'] ?? '', $e['sensor_type'] ?? '', $e['sensor_index'] ?? ''];
        $unit    = self::UNIT_MAP[$e['sensor_class'] ?? ''] ?? ($e['sensor_class'] ?? '');

        return [new GraphSeriesDefinition(
            name:     $e['sensor_descr'] ?? 'wireless',
            key:      'sensor',
            unit:     $unit,
            area:     true,
            bindings: [new RrdMetricBinding($rrdName, 'sensor')],
        )];
    }

    public function markers(array $device, GraphQuery $query): array
    {
        $e       = $query->entities;
        $markers = [];

        if (isset($e['sensor_limit_low']) && $e['sensor_limit_low'] !== null) {
            $markers[] = ['type' => 'horizontal_line', 'name' => 'Low critical', 'value' => (float) $e['sensor_limit_low'], 'severity' => 'critical'];
        }
        if (isset($e['sensor_limit_low_warn']) && $e['sensor_limit_low_warn'] !== null) {
            $markers[] = ['type' => 'horizontal_line', 'name' => 'Low warning', 'value' => (float) $e['sensor_limit_low_warn'], 'severity' => 'warning'];
        }
        if (isset($e['sensor_limit_warn']) && $e['sensor_limit_warn'] !== null) {
            $markers[] = ['type' => 'horizontal_line', 'name' => 'High warning', 'value' => (float) $e['sensor_limit_warn'], 'severity' => 'warning'];
        }
        if (isset($e['sensor_limit']) && $e['sensor_limit'] !== null) {
            $markers[] = ['type' => 'horizontal_line', 'name' => 'High critical', 'value' => (float) $e['sensor_limit'], 'severity' => 'critical'];
        }

        return $markers;
    }

    public function thresholds(array $device, GraphQuery $query): array
    {
        return [];
    }

    public function display(): array
    {
        return ['kind' => 'line', 'stacked' => false, 'area' => true];
    }
}
