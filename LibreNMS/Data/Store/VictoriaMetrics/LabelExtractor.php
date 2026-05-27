<?php

/**
 * LabelExtractor.php
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

namespace LibreNMS\Data\Store\VictoriaMetrics;

use App\Models\Device;

class LabelExtractor
{
    // Entity ID tags become labels when present; they also determine entity_type.
    private const ENTITY_ID_TAGS = [
        'port_id', 'sensor_id', 'service_id', 'app_id', 'bill_id',
        'mempool_id', 'storage_id',
    ];

    // Low-cardinality tags that are useful for browsing metrics without being required.
    private const EXTRA_TAGS = [
        'ifName', 'sensor_class', 'sensor_type', 'module',
        'af',             // IP version for ipSystemStats (ipv4/ipv6)
        'name',           // duration window for availability
        'descr',          // disk description for ucd_diskio
        'processor_type', 'processor_index',  // processor identity (no numeric ID in write path)
        'mempool_type', 'mempool_class',      // human context alongside mempool_id
    ];

    /**
     * Build the VictoriaMetrics label set for one write() call.
     *
     * Always includes: source, device_id, hostname, entity_type.
     * Conditionally includes entity ID tags and extra descriptive tags.
     * RRD-specific tags (rrd_def, rrd_name, etc.) are intentionally excluded.
     *
     * @param  Device $device  The polled device
     * @param  string $measurement  Unused at this layer; reserved for future per-measurement overrides
     * @param  array  $tags    Tags array as passed to Datastore::write()
     * @return array<string, string>
     */
    public static function extract(Device $device, string $measurement, array $tags): array
    {
        $labels = [
            'source'      => 'librenms',
            'device_id'   => (string) $device->device_id,
            'hostname'    => (string) $device->hostname,
            'entity_type' => self::deriveEntityType($tags),
        ];

        foreach (self::ENTITY_ID_TAGS as $key) {
            if (isset($tags[$key])) {
                $labels[$key] = (string) $tags[$key];
            }
        }

        foreach (self::EXTRA_TAGS as $key) {
            if (isset($tags[$key]) && $tags[$key] !== '' && $tags[$key] !== null) {
                $labels[$key] = (string) $tags[$key];
            }
        }

        return $labels;
    }

    private static function deriveEntityType(array $tags): string
    {
        $map = [
            'port_id'    => 'port',
            'sensor_id'  => 'sensor',
            'service_id' => 'service',
            'app_id'     => 'app',
            'bill_id'    => 'bill',
            'mempool_id' => 'mempool',
            'storage_id' => 'storage',
        ];

        foreach ($map as $key => $type) {
            if (isset($tags[$key])) {
                return $type;
            }
        }

        return 'device';
    }
}
