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
use LibreNMS\Enum\Sensor as SensorClass;
use LibreNMS\Graph\GraphDefinition;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Graph\GraphSeriesDefinition;
use LibreNMS\Graph\RrdMetricBinding;

class DeviceSensorGraph implements GraphDefinition
{
    use \LibreNMS\Graph\DefaultVariables;

    // Matches the 7-color cycle used by includes/html/graphs/device/sensor.inc.php
    private const COLORS = ['CC0000', '008C00', '4096EE', '73880A', 'D01F3C', '36393D', 'FF0084'];

    public function __construct(private readonly SensorClass $sensorClass) {}

    public function graphType(): string { return 'device_' . $this->sensorClass->value; }

    public function id(array $device, GraphQuery $query): string
    {
        return $this->graphType() . ':' . $device['device_id'];
    }

    public function title(array $device): string
    {
        return $this->sensorClass->label();
    }

    public function subtitle(array $device, GraphQuery $query): string
    {
        return $device['hostname'] ?? '';
    }

    public function unit(array $device, GraphQuery $query): string
    {
        return $this->sensorClass->unit();
    }

    public function entityType(): string { return 'device'; }

    public function display(): array
    {
        return ['kind' => 'line', 'stacked' => false, 'area' => false, 'legend' => true];
    }

    public function series(array $device, GraphQuery $query): array
    {
        $sensors = Sensor::where('device_id', $device['device_id'])
            ->where('sensor_class', $this->sensorClass->value)
            ->orderBy('sensor_descr')
            ->get();

        return $sensors->values()->map(function (Sensor $sensor, int $i) use ($device) {
            $color = self::COLORS[$i % count(self::COLORS)];

            $isIpmi = $sensor->poller_type === 'ipmi'
                || LibrenmsConfig::getOsSetting($device['os'] ?? '', 'sensor_descr');
            $rrdKey  = $isIpmi ? $sensor->sensor_descr : $sensor->sensor_index;
            $rrdName = ['sensor', $this->sensorClass->value, $sensor->sensor_type, $rrdKey];

            return new GraphSeriesDefinition(
                name:      $sensor->sensor_descr,
                key:       'sensor_' . $sensor->sensor_id,
                unit:      $this->sensorClass->unit(),
                area:      false,
                color:     $color,
                lineWidth: 1.0,
                bindings:  [new RrdMetricBinding($rrdName, 'sensor')],
            );
        })->all();
    }

    public function markers(array $device, GraphQuery $query): array { return []; }

}
