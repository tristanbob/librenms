<?php

/**
 * GraphDataUrl.php
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

namespace LibreNMS\Graph;

class GraphDataUrl
{
    public static function device(int $deviceId, string $graphType, array $query = []): string
    {
        return self::withQuery("/graph-data/devices/$deviceId/graphs/$graphType", $query);
    }

    public static function port(int $portId, string $graphType, array $query = []): string
    {
        return self::withQuery("/graph-data/ports/$portId/graphs/$graphType", $query);
    }

    public static function sensor(int $deviceId, int $sensorId, string $graphType, array $query = []): string
    {
        return self::withQuery("/graph-data/devices/$deviceId/sensors/$sensorId/graphs/$graphType", $query);
    }

    public static function wireless(int $deviceId, int $sensorId, string $graphType, array $query = []): string
    {
        return self::withQuery("/graph-data/devices/$deviceId/wireless/$sensorId/graphs/$graphType", $query);
    }

    private static function withQuery(string $path, array $query): string
    {
        $query = array_filter($query, fn ($value) => $value !== null);

        return $query === [] ? $path : $path . '?' . http_build_query($query);
    }
}
