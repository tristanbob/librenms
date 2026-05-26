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

use LibreNMS\Enum\WirelessSensorType;
use LibreNMS\Graph\GraphDefinition;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Graph\GraphSeriesDefinition;
use LibreNMS\Graph\RrdMetricBinding;

class WirelessSensorGraph implements GraphDefinition
{
    private bool $transformComputed = false;
    /** @var callable|null */
    private $transform = null;

    public function __construct(private readonly WirelessSensorType $sensorClass) {}

    public function graphType(): string { return 'wireless_' . $this->sensorClass->value; }

    public function entityType(): string { return 'wireless_sensor'; }

    public function unit(array $device, GraphQuery $query): string
    {
        return __("wireless.{$this->sensorClass->value}.unit");
    }

    public function id(array $device, GraphQuery $query): string
    {
        return $this->graphType() . ':' . ($query->entities['sensor_id'] ?? '');
    }

    public function title(array $device): string
    {
        return __("wireless.{$this->sensorClass->value}.long");
    }

    public function subtitle(array $device, GraphQuery $query): string
    {
        return ($device['hostname'] ?? '') . ' — ' . ($query->entities['sensor_descr'] ?? '');
    }

    public function series(array $device, GraphQuery $query): array
    {
        $e       = $query->entities;
        $rrdName = ['wireless-sensor', $this->sensorClass->value, $e['sensor_type'] ?? '', $e['sensor_index'] ?? ''];
        $unit    = $this->unit($device, $query);

        return [new GraphSeriesDefinition(
            name:        $e['sensor_descr'] ?? 'wireless',
            key:         'sensor',
            unit:        $unit,
            area:        $this->hasAreaFill(),
            color:       '0000cc',
            lineWidth:   1.5,
            areaOpacity: 0.333,
            bindings:    [new RrdMetricBinding($rrdName, 'sensor', transform: $this->valueTransform())],
        )];
    }

    public function markers(array $device, GraphQuery $query): array
    {
        $e       = $query->entities;
        $markers = [];

        if (isset($e['sensor_limit_low']) && $e['sensor_limit_low'] !== null) {
            $markers[] = $this->marker('Low limit', $e['sensor_limit_low'], 'limit');
        }
        if (isset($e['sensor_limit']) && $e['sensor_limit'] !== null) {
            $markers[] = $this->marker('High limit', $e['sensor_limit'], 'limit');
        }

        return $markers;
    }

    public function display(): array
    {
        return ['kind' => 'line', 'stacked' => false, 'area' => true];
    }

    private function marker(string $name, mixed $value, string $severity): array
    {
        $value = (float) $value;
        $transform = $this->valueTransform();
        if ($transform !== null) {
            $value = $transform($value);
        }

        return ['type' => 'horizontal_line', 'name' => $name, 'value' => $value, 'severity' => $severity];
    }

    private function valueTransform(): ?callable
    {
        if (! $this->transformComputed) {
            $this->transform = $this->computeTransform();
            $this->transformComputed = true;
        }

        return $this->transform;
    }

    private function computeTransform(): ?callable
    {
        return match ($this->sensorClass) {
            WirelessSensorType::Distance => fn (float $value): float => $value * 1000,
            default => null,
        };
    }

    // Matches RRD wireless-sensor.inc.php: area fill only when scale_min >= 0
    private function hasAreaFill(): bool
    {
        return match ($this->sensorClass) {
            WirelessSensorType::NoiseFloor,
            WirelessSensorType::Ssr,
            WirelessSensorType::Power,
            WirelessSensorType::Mse,
            WirelessSensorType::Channel,
            WirelessSensorType::Mcs,
            WirelessSensorType::Xpi => false,
            default => true,
        };
    }
}
