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
 * @copyright  2026 Tristan Rhodes
 * @author     Tristan Rhodes <tristan.rhodes@gmail.com>
 */

namespace LibreNMS\Graph\Definitions\Device;

use App\Facades\LibrenmsConfig;
use App\Models\Port;
use LibreNMS\Graph\Definitions\Templates\GraphTemplate;
use LibreNMS\Graph\GraphContext;
use LibreNMS\Graph\GraphSeriesDefinition;
use LibreNMS\Graph\MetricSeries;
use LibreNMS\Graph\RrdMetricBinding;
use LibreNMS\Util\Rewrite;

class BitsGraph extends GraphTemplate
{
    public const GRAPH_TYPE = 'device_bits';

    private const IN_PALETTE = 'greens';
    private const OUT_PALETTE = 'purples';

    public function __construct()
    {
        parent::__construct(self::GRAPH_TYPE, 'Device Traffic', 'bps', ['stacked' => true, 'area' => true]);
    }

    public function series(GraphContext $context): array
    {
        $device         = $context;
        $toBits         = fn ($value) => $value * 8;
        $inSeries       = [];
        $outSeries      = [];
        $mirrorOutbound = $this->stackedMultiplier() === 1;
        $opacity        = $mirrorOutbound ? 0x88 / 0xff : 1.0;
        $ports          = $this->includedPorts($device);

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
                unit:        $this->unit($context),
                color:       $inColor,
                lineColor:   $inColor,
                area:        true,
                areaOpacity: $opacity,
                lineOpacity: $opacity,
                stack:       'device_bits_in',
                bindings:    MetricSeries::aggregate(
                    'port.if_in_bits_rate',
                    new RrdMetricBinding(rrdName: $rrdName, ds: 'INOCTETS', transform: $toBits),
                    ['ifIndex' => $ifIndex],
                ),
            );

            $outSeries[] = new GraphSeriesDefinition(
                name:        "$label Out",
                key:         "port_{$portId}_bits_out",
                unit:        $this->unit($context),
                color:       $outColor,
                lineColor:   $outColor,
                area:        true,
                areaOpacity: $opacity,
                lineOpacity: $opacity,
                stack:       'device_bits_out',
                negate:      ! $mirrorOutbound,
                bindings:    MetricSeries::aggregate(
                    'port.if_out_bits_rate',
                    new RrdMetricBinding(rrdName: $rrdName, ds: 'OUTOCTETS', transform: $toBits),
                    ['ifIndex' => $ifIndex],
                ),
            );
        }

        return [...$inSeries, ...$outSeries];
    }

    /**
     * Mirrors includes/html/graphs/device/bits.inc.php so the JSON graph includes
     * the same non-disabled, non-deleted ports as the legacy RRD image graph.
     */
    private function includedPorts(GraphContext $device): array
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

    private function isIgnoredPort(GraphContext $device, array $port): bool
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

}
