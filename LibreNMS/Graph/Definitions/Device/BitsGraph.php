<?php

/**
 * BitsGraph.php
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

use App\Facades\LibrenmsConfig;
use App\Models\Port;
use LibreNMS\Data\Store\VictoriaMetrics\VictoriaMetricsMetricCatalog;
use LibreNMS\Graph\GraphDefinition;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Graph\GraphSeriesDefinition;
use LibreNMS\Graph\MetricSeries;
use LibreNMS\Graph\RrdMetricBinding;
use LibreNMS\Graph\VictoriaMetricsGraphDataProvider;
use LibreNMS\Util\Rewrite;

class BitsGraph implements GraphDefinition
{
    use \LibreNMS\Graph\DefaultVariables;

    public const GRAPH_TYPE = 'device_bits';

    private const IN_PALETTE = 'greens';
    private const OUT_PALETTE = 'purples';

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
        return 'Device Traffic';
    }

    public function subtitle(array $device, GraphQuery $query): string
    {
        return $device['hostname'];
    }

    public function unit(array $device, GraphQuery $query): string
    {
        return 'bps';
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
        $toBits         = fn ($value) => $value * 8;
        $inSeries       = [];
        $outSeries      = [];
        $mirrorOutbound = $this->isMirrorStacked();
        $opacity        = $mirrorOutbound ? 0x88 / 0xff : 1.0;
        $ports          = $this->includedPorts($device);
        $inEntry        = VictoriaMetricsMetricCatalog::get('port.if_in_bits_rate');
        $outEntry       = VictoriaMetricsMetricCatalog::get('port.if_out_bits_rate');

        foreach ($ports as $i => $port) {
            $portId   = (int) $port['port_id'];
            $label    = Rewrite::shortenIfName($port['label']);
            $rrdName  = "port-id$portId";
            $ifIndex  = (string) $port['ifIndex'];
            $inColor  = $this->paletteColor(self::IN_PALETTE, $i, '91B13C');
            $outColor = $this->paletteColor(self::OUT_PALETTE, $i, '8080BD');

            $inSeries[] = new GraphSeriesDefinition(
                name:        "$label In",
                key:         "port_{$portId}_bits_in",
                unit:        $this->unit($device, $query),
                color:       $inColor,
                lineColor:   $inColor,
                area:        true,
                areaOpacity: $opacity,
                lineOpacity: $opacity,
                stack:       'device_bits_in',
                bindings:    MetricSeries::expression(
                    new RrdMetricBinding(rrdName: $rrdName, ds: 'INOCTETS', transform: $toBits),
                    fn (array $entities) => VictoriaMetricsGraphDataProvider::buildSelector(
                        $inEntry->definition->name,
                        $inEntry->identityLabels,
                        ['hostname' => $entities['hostname'], 'ifIndex' => $ifIndex],
                    ),
                    ['hostname'],
                ),
            );

            $outSeries[] = new GraphSeriesDefinition(
                name:        "$label Out",
                key:         "port_{$portId}_bits_out",
                unit:        $this->unit($device, $query),
                color:       $outColor,
                lineColor:   $outColor,
                area:        true,
                areaOpacity: $opacity,
                lineOpacity: $opacity,
                stack:       'device_bits_out',
                negate:      ! $mirrorOutbound,
                bindings:    MetricSeries::expression(
                    new RrdMetricBinding(rrdName: $rrdName, ds: 'OUTOCTETS', transform: $toBits),
                    fn (array $entities) => VictoriaMetricsGraphDataProvider::buildSelector(
                        $outEntry->definition->name,
                        $outEntry->identityLabels,
                        ['hostname' => $entities['hostname'], 'ifIndex' => $ifIndex],
                    ),
                    ['hostname'],
                ),
            );
        }

        return [...$inSeries, ...$outSeries];
    }

    public function markers(array $device, GraphQuery $query): array
    {
        return [];
    }

    /**
     * Mirrors includes/html/graphs/device/bits.inc.php so the JSON graph includes
     * the same non-disabled, non-deleted ports as the legacy RRD image graph.
     */
    private function includedPorts(array $device): array
    {
        return Port::query()
            ->where('device_id', $device['device_id'])
            ->where('disabled', 0)
            ->where('deleted', 0)
            ->orderBy('ifIndex')
            ->get()
            ->map(fn (Port $port) => $port->toArray())
            ->filter(fn (array $port) => ! $this->isIgnoredPort($device, $port))
            ->map(fn (array $port) => cleanPort($port, $device))
            ->values()
            ->all();
    }

    private function isIgnoredPort(array $device, array $port): bool
    {
        $os = $device['os'] ?? '';

        foreach ((array) LibrenmsConfig::get('device_traffic_iftype', []) as $iftype) {
            if ($os === 'asa' && in_array($iftype, ['/virtual/', '/l2vlan/'])) {
                continue;
            }

            if (preg_match($iftype . 'i', (string) ($port['ifType'] ?? ''))) {
                return true;
            }
        }

        foreach ((array) LibrenmsConfig::get('device_traffic_descr', []) as $ifdescr) {
            if (
                preg_match($ifdescr . 'i', (string) ($port['ifDescr'] ?? '')) ||
                preg_match($ifdescr . 'i', (string) ($port['ifName'] ?? ''))
            ) {
                return true;
            }
        }

        return false;
    }

    private function paletteColor(string $palette, int $index, string $fallback): string
    {
        $colors = (array) LibrenmsConfig::get("graph_colours.$palette", []);
        if ($colors === []) {
            return $fallback;
        }

        return $colors[$index % count($colors)] ?? $fallback;
    }

    private function isMirrorStacked(): bool
    {
        return LibrenmsConfig::get('webui.graph_stacked') == true;
    }
}
