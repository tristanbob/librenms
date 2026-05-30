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
 * @copyright  2026 Tristan Rhodes
 * @author     Tristan Rhodes <tristan.rhodes@gmail.com>
 */

namespace LibreNMS\Graph\Definitions\Device;

use App\Models\WirelessSensor;
use LibreNMS\Data\Store\VictoriaMetrics\VictoriaMetricsMetricCatalog;
use LibreNMS\Enum\WirelessSensorType;
use LibreNMS\Graph\Definitions\Templates\GraphTemplate;
use LibreNMS\Graph\GraphContext;
use LibreNMS\Graph\GraphSeriesDefinition;
use LibreNMS\Graph\MetricSeries;
use LibreNMS\Graph\RrdMetricBinding;
use LibreNMS\Graph\VictoriaMetricsGraphDataProvider;

class WirelessSensorGraph extends GraphTemplate
{
    private const PREFIX = 'device_wireless_';

    public function __construct(private readonly WirelessSensorType $sensorClass)
    {
        parent::__construct(
            graphType: self::PREFIX . $sensorClass->value,
            title: __("wireless.{$sensorClass->value}.long"),
            unit: match ($sensorClass) {
                WirelessSensorType::Frequency => 'Hz',
                WirelessSensorType::Distance  => 'm',
                default                       => __("wireless.{$sensorClass->value}.unit"),
            },
        );
    }

    public function series(GraphContext $context): array
    {
        $device = $context;
        $wsEntry = VictoriaMetricsMetricCatalog::get('wireless_sensor.value');

        return WirelessSensor::query()
            ->where('device_id', $device['device_id'])
            ->where('sensor_class', $this->sensorClass->value)
            ->orderBy('sensor_index')
            ->get()
            ->values()
            ->map(function (WirelessSensor $sensor, int $index) use ($wsEntry): GraphSeriesDefinition {
                $color = $this->paletteColor('mixed', $index, '0000cc');

                return new GraphSeriesDefinition(
                    name:      $sensor->sensor_descr ?? 'wireless',
                    key:       "wireless_{$sensor->sensor_id}",
                    unit:      $this->unit,
                    color:     $color,
                    lineColor: $color,
                    lineWidth: 1.5,
                    bindings:  MetricSeries::expression(
                        new RrdMetricBinding(
                            rrdName: ['wireless-sensor', $this->sensorClass->value, $sensor->sensor_type ?? '', $sensor->sensor_index ?? ''],
                            ds:      'sensor',
                            transform: $this->valueTransform(),
                        ),
                        fn (array $entities): string => VictoriaMetricsGraphDataProvider::buildSelector(
                            $wsEntry->definition->name,
                            $wsEntry->identityLabels,
                            [
                                'hostname'     => $entities['hostname'],
                                'sensor_class' => $this->sensorClass->value,
                                'sensor_type'  => $sensor->sensor_type ?? '',
                                'sensor_index' => (string) ($sensor->sensor_index ?? ''),
                            ],
                        ),
                        ['hostname'],
                    ),
                );
            })
            ->all();
    }

    private function valueTransform(): ?callable
    {
        return match ($this->sensorClass) {
            WirelessSensorType::Frequency => fn (float $value): float => $value * 1000000,
            WirelessSensorType::Distance  => fn (float $value): float => $value * 1000,
            default                       => null,
        };
    }
}
