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
 * @copyright  2026 Tristan Rhodes
 * @author     Tristan Rhodes <tristan.rhodes@gmail.com>
 */

namespace LibreNMS\Data\Store\VictoriaMetrics;

use App\Models\Device;

class LabelExtractor
{
    // Stable polling identity and low-cardinality context labels. Database IDs are
    // intentionally excluded because LibreNMS does not soft-delete every entity.
    private const EXTRA_TAGS = [
        'ifIndex', 'ifName',
        'sensor_class', 'sensor_type', 'sensor_index',
        'module',
        'af',             // IP version for ipSystemStats (ipv4/ipv6)
        'name',           // duration window for availability
        'descr', 'type',
        'sla_nr',
        'processor_type', 'processor_index',  // processor identity (no numeric ID in write path)
        'mempool_type', 'mempool_class', 'mempool_index',
        'supply_type', 'supply_index',         // printer supply identity
    ];

    /**
     * Build the VictoriaMetrics label set for one write() call.
     *
     * Always includes: source, hostname, entity_type.
     * Conditionally includes stable polling identity and extra descriptive tags.
     * RRD-specific tags (rrd_def, rrd_name, etc.) are intentionally excluded.
     * Database ID tags (including device_id) are intentionally excluded from VM labels.
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
            'hostname'    => (string) $device->hostname,
            'entity_type' => self::deriveEntityType($tags),
        ];

        foreach (self::EXTRA_TAGS as $key) {
            if (isset($tags[$key]) && $tags[$key] !== '') {
                $labels[$key] = (string) $tags[$key];
            }
        }

        if (! isset($labels['descr']) && isset($tags['diskio_descr']) && $tags['diskio_descr'] !== '') {
            $labels['descr'] = (string) $tags['diskio_descr'];
        }

        return $labels;
    }

    private static function deriveEntityType(array $tags): string
    {
        if (isset($tags['ifIndex'])) {
            return 'port';
        }

        if (isset($tags['sensor_class'], $tags['sensor_type'], $tags['sensor_index'])) {
            return 'sensor';
        }

        if (isset($tags['mempool_type'], $tags['mempool_class'], $tags['mempool_index'])) {
            return 'mempool';
        }

        if (isset($tags['supply_type'], $tags['supply_index'])) {
            return 'printer_supply';
        }

        if (isset($tags['type'], $tags['descr'])) {
            return 'storage';
        }

        if (isset($tags['processor_type'], $tags['processor_index'])) {
            return 'processor';
        }

        return 'device';
    }
}
