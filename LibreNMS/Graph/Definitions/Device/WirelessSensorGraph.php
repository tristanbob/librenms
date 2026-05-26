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

namespace LibreNMS\Graph\Definitions\Device;

use App\Facades\LibrenmsConfig;
use App\Models\WirelessSensor;
use LibreNMS\Enum\WirelessSensorType;
use LibreNMS\Graph\GraphDefinition;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Graph\GraphSeriesDefinition;
use LibreNMS\Graph\RrdMetricBinding;

class WirelessSensorGraph implements GraphDefinition
{
    private const PREFIX = 'device_wireless_';

    public function __construct(private readonly WirelessSensorType $sensorClass) {}

    public function graphType(): string
    {
        return self::PREFIX . $this->sensorClass->value;
    }

    public function entityType(): string
    {
        return 'device';
    }

    public function id(array $device, GraphQuery $query): string
    {
        return $this->graphType() . ':' . $device['device_id'];
    }

    public function title(array $device): string
    {
        return __("wireless.{$this->sensorClass->value}.long");
    }

    public function subtitle(array $device, GraphQuery $query): string
    {
        return $device['hostname'] ?? '';
    }

    public function unit(array $device, GraphQuery $query): string
    {
        return match ($this->sensorClass) {
            WirelessSensorType::Frequency => 'Hz',
            WirelessSensorType::Distance => 'm',
            default => __("wireless.{$this->sensorClass->value}.unit"),
        };
    }

    public function display(): array
    {
        return ['kind' => 'line', 'stacked' => false, 'area' => false, 'legend' => true];
    }

    public function series(array $device, GraphQuery $query): array
    {
        return WirelessSensor::query()
            ->where('device_id', $device['device_id'])
            ->where('sensor_class', $this->sensorClass->value)
            ->orderBy('sensor_index')
            ->get()
            ->values()
            ->map(function (WirelessSensor $sensor, int $index) use ($device, $query): GraphSeriesDefinition {
                $color = $this->paletteColor($index);

                return new GraphSeriesDefinition(
                    name:      $sensor->sensor_descr ?? 'wireless',
                    key:       "wireless_{$sensor->sensor_id}",
                    unit:      $this->unit($device, $query),
                    color:     $color,
                    lineColor: $color,
                    lineWidth: 1.5,
                    bindings:  [new RrdMetricBinding(
                        rrdName: ['wireless-sensor', $this->sensorClass->value, $sensor->sensor_type ?? '', $sensor->sensor_index ?? ''],
                        ds:      'sensor',
                        transform: $this->valueTransform(),
                    )],
                );
            })
            ->all();
    }

    public function markers(array $device, GraphQuery $query): array
    {
        return [];
    }

    private function valueTransform(): ?callable
    {
        return match ($this->sensorClass) {
            WirelessSensorType::Frequency => fn (float $value): float => $value * 1000000,
            WirelessSensorType::Distance => fn (float $value): float => $value * 1000,
            default => null,
        };
    }

    private function paletteColor(int $index): string
    {
        $colors = (array) LibrenmsConfig::get('graph_colours.mixed', []);
        if ($colors === []) {
            return '0000cc';
        }

        return $colors[$index % count($colors)] ?? '0000cc';
    }
}
