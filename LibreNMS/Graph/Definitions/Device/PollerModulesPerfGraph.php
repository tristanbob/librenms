<?php

/**
 * PollerModulesPerfGraph.php
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

use App\Facades\DeviceCache;
use LibreNMS\Config as LibrenmsConfig;
use LibreNMS\Graph\GraphDefinition;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Graph\GraphSeriesDefinition;
use LibreNMS\Graph\RrdMetricBinding;

class PollerModulesPerfGraph implements GraphDefinition
{
    public const GRAPH_TYPE = 'device_poller_modules_perf';

    public function graphType(): string
    {
        return self::GRAPH_TYPE;
    }

    public function id(array $device, GraphQuery $query): string
    {
        return self::GRAPH_TYPE . ':' . $device['device_id'];
    }

    public function title(array $device): string
    {
        return 'Poller Modules Performance';
    }

    public function subtitle(array $device, GraphQuery $query): string
    {
        return $device['hostname'];
    }

    public function unit(): string
    {
        return 'seconds';
    }

    public function entityType(): string
    {
        return 'device';
    }

    public function display(): array
    {
        return ['kind' => 'line', 'stacked' => true, 'area' => true, 'legend' => true];
    }

    public function series(array $device, GraphQuery $query): array
    {
        $modules = LibrenmsConfig::get('poller_modules', []);
        ksort($modules);

        $deviceModel = DeviceCache::get((int) $device['device_id']);
        $attribs     = $deviceModel->getAttribs();
        $os          = $device['os'] ?? '';

        $enabled = [];
        foreach ($modules as $module => $defaultEnabled) {
            $attrKey = 'poll_' . $module;
            if (isset($attribs[$attrKey])) {
                if ($attribs[$attrKey]) {
                    $enabled[] = $module;
                }
                // if explicitly disabled, skip
            } elseif ($defaultEnabled || LibrenmsConfig::getOsSetting($os, 'poller_modules.' . $module)) {
                $enabled[] = $module;
            }
        }

        $colors   = self::generatePalette(count($enabled));
        $series   = [];
        foreach ($enabled as $i => $module) {
            $hex = $colors[$i];
            $series[] = new GraphSeriesDefinition(
                name:        $module,
                key:         'module_' . str_replace('-', '_', $module),
                unit:        $this->unit(),
                color:       $hex,
                lineColor:   self::darken($hex, 0.7),
                area:        true,
                areaOpacity: 0.75,
                stack:       'modules',
                bindings:    [
                    new RrdMetricBinding(rrdName: ['poller-perf', $module], ds: 'poller'),
                ],
            );
        }

        return $series;
    }

    public function markers(array $device, GraphQuery $query): array
    {
        return [];
    }

    public function thresholds(array $device, GraphQuery $query): array
    {
        return [];
    }

    /**
     * Generate an N-color HSL palette with evenly spaced hues.
     *
     * @return list<string>  6-char lowercase hex strings (no leading #)
     */
    private static function generatePalette(int $count): array
    {
        if ($count === 0) {
            return [];
        }
        $colors = [];
        for ($i = 0; $i < $count; $i++) {
            $hue      = ($i * 360.0 / $count) % 360;
            $sat      = $i % 2 === 0 ? 72 : 58;
            $light    = 42;
            $colors[] = self::hslToHex($hue, $sat, $light);
        }

        return $colors;
    }

    /** Scale all RGB channels toward black by the given factor (0–1). */
    private static function darken(string $hex, float $factor): string
    {
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        return sprintf('%02x%02x%02x',
            (int) round($r * $factor),
            (int) round($g * $factor),
            (int) round($b * $factor),
        );
    }

    private static function hslToHex(float $h, float $s, float $l): string
    {
        $s /= 100;
        $l /= 100;
        $a = $s * min($l, 1 - $l);
        $f = static function (int $n) use ($h, $l, $a): int {
            $k = fmod($n + $h / 30, 12);
            return (int) round(($l - $a * max(-1.0, min($k - 3, 9 - $k, 1.0))) * 255);
        };

        return sprintf('%02x%02x%02x', $f(0), $f(8), $f(4));
    }
}
