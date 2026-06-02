<?php

/**
 * DeviceSensorGraph.php
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

use App\Facades\LibrenmsConfig;
use App\Models\Sensor;
use LibreNMS\Data\Store\VictoriaMetrics\VictoriaMetricsMetricCatalog;
use LibreNMS\Enum\Sensor as SensorClass;
use LibreNMS\Graph\Definitions\Templates\GraphTemplate;
use LibreNMS\Graph\GraphContext;
use LibreNMS\Graph\GraphSeriesDefinition;
use LibreNMS\Graph\MetricSeries;
use LibreNMS\Graph\RrdMetricBinding;
use LibreNMS\Graph\VictoriaMetricsGraphDataProvider;

class DeviceSensorGraph extends GraphTemplate
{
    // Matches the 7-color cycle used by includes/html/graphs/device/sensor.inc.php
    private const COLORS = ['CC0000', '008C00', '4096EE', '73880A', 'D01F3C', '36393D', 'FF0084'];

    public function __construct(private readonly SensorClass $sensorClass)
    {
        parent::__construct(
            graphType: 'device_' . $sensorClass->value,
            title: $sensorClass->label(),
            unit: $sensorClass->unit(),
        );
    }

    public function series(GraphContext $context): array
    {
        $device = $context;
        $sensorEntry = VictoriaMetricsMetricCatalog::get('sensor.value');

        return Sensor::where('device_id', $device['device_id'])
            ->where('sensor_class', $this->sensorClass->value)
            ->orderBy('sensor_descr')
            ->get()
            ->values()
            ->map(function (Sensor $sensor, int $i) use ($device, $sensorEntry): GraphSeriesDefinition {
                $color = self::COLORS[$i % count(self::COLORS)];
                $isIpmi = $sensor->poller_type === 'ipmi'
                    || LibrenmsConfig::getOsSetting($device['os'] ?? '', 'sensor_descr');
                $rrdKey = $isIpmi ? $sensor->sensor_descr : $sensor->sensor_index;
                $rrdName = ['sensor', $this->sensorClass->value, $sensor->sensor_type, $rrdKey];

                return new GraphSeriesDefinition(
                    name:      $sensor->sensor_descr,
                    key:       'sensor_' . $sensor->sensor_id,
                    unit:      $this->unit,
                    area:      false,
                    color:     $color,
                    lineWidth: 1.0,
                    bindings:  MetricSeries::expression(
                        new RrdMetricBinding($rrdName, 'sensor'),
                        fn (array $entities): string => VictoriaMetricsGraphDataProvider::buildSelector(
                            $sensorEntry->definition->name,
                            $sensorEntry->identityLabels,
                            [
                                'hostname'     => $entities['hostname'],
                                'sensor_class' => $this->sensorClass->value,
                                'sensor_type'  => $sensor->sensor_type,
                                'sensor_index' => (string) $sensor->sensor_index,
                            ],
                        ),
                        ['hostname'],
                    ),
                );
            })
            ->all();
    }
}
