<?php

/**
 * DeviceSensorGraphDefinitionResolver.php
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

use LibreNMS\Enum\Sensor as SensorClass;
use LibreNMS\Graph\GraphDefinition;
use LibreNMS\Graph\GraphDefinitionResolver;

class DeviceSensorGraphDefinitionResolver implements GraphDefinitionResolver
{
    private const PREFIX = 'device_';

    public function supports(string $graphType): bool
    {
        return $this->sensorClass($graphType) !== null;
    }

    public function definitionFor(string $graphType): GraphDefinition
    {
        $sensorClass = $this->sensorClass($graphType);
        if ($sensorClass === null) {
            throw new \RuntimeException(
                "Graph type '{$graphType}' is not yet supported by the JSON graph data API."
            );
        }

        return new DeviceSensorGraph($sensorClass);
    }

    private function sensorClass(string $graphType): ?SensorClass
    {
        if (! str_starts_with($graphType, self::PREFIX)) {
            return null;
        }

        return SensorClass::tryFrom(substr($graphType, strlen(self::PREFIX)));
    }
}
