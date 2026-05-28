<?php

/**
 * SensorGraph.php
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

namespace LibreNMS\Graph\Definitions\Sensor;

use App\Facades\LibrenmsConfig;
use App\Models\UserPref;
use LibreNMS\Enum\Sensor as SensorClass;
use LibreNMS\Graph\Definitions\Templates\SensorBaseGraph;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Graph\GraphSeriesDefinition;
use LibreNMS\Graph\MetricSeries;
use LibreNMS\Graph\RrdMetricBinding;
use LibreNMS\Util\Rewrite;

class SensorGraph extends SensorBaseGraph
{
    public function __construct(private readonly SensorClass $sensorClass) {}

    public function graphType(): string { return 'sensor_' . $this->sensorClass->value; }

    public function entityType(): string { return 'sensor'; }

    public function unit(array $device, GraphQuery $query): string
    {
        return $this->sensorClass->unit();
    }

    public function title(array $device): string
    {
        return $this->sensorClass->label();
    }

    public function series(array $device, GraphQuery $query): array
    {
        $e = $query->entities;
        $isIpmi = ($e['poller_type'] ?? '') === 'ipmi'
            || LibrenmsConfig::getOsSetting($device['os'] ?? '', 'sensor_descr');
        $rrdKey  = $isIpmi ? ($e['sensor_descr'] ?? '') : ($e['sensor_index'] ?? '');
        $rrdName = ['sensor', $this->sensorClass->value, $e['sensor_type'] ?? '', $rrdKey];
        $unit    = $this->unit($device, $query);

        return [new GraphSeriesDefinition(
            name:      $e['sensor_descr'] ?? 'sensor',
            key:       'sensor',
            unit:      $unit,
            area:      false,
            color:     'theme-ink',
            lineWidth: 2.0,
            bindings:  [
                ...MetricSeries::gauge('sensor.value', new RrdMetricBinding($rrdName, 'sensor', transform: $this->valueTransform()), $this->valueTransform()),
            ],
        )];
    }

    public function markers(array $device, GraphQuery $query): array
    {
        $e       = $query->entities;
        $markers = [];

        if (isset($e['sensor_limit_low'])) {
            $markers[] = $this->marker('Low critical', $e['sensor_limit_low'], 'low_critical');
        }
        if (isset($e['sensor_limit_low_warn'])) {
            $markers[] = $this->marker('Low warning', $e['sensor_limit_low_warn'], 'low_warning');
        }
        if (isset($e['sensor_limit_warn'])) {
            $markers[] = $this->marker('High warning', $e['sensor_limit_warn'], 'high_warning');
        }
        if (isset($e['sensor_limit'])) {
            $markers[] = $this->marker('High critical', $e['sensor_limit'], 'high_critical');
        }

        return $markers;
    }

    protected function computeTransform(): ?callable
    {
        if ($this->sensorClass !== SensorClass::Temperature) {
            return null;
        }

        /** @var ?\App\Models\User $user */
        $user = auth()->user();
        if (! $user || UserPref::getPref($user, 'temp_units') !== 'f') {
            return null;
        }

        return Rewrite::celsiusToFahrenheit(...);
    }
}
