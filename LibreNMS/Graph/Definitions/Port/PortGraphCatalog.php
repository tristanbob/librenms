<?php

/**
 * PortGraphCatalog.php
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

namespace LibreNMS\Graph\Definitions\Port;

use App\Facades\LibrenmsConfig;
use LibreNMS\Graph\Definitions\Templates\PortLineGraph;
use LibreNMS\Graph\GraphContext;
use LibreNMS\Graph\GraphDefinition;
use LibreNMS\Graph\GraphMarkerDefinition;
use LibreNMS\Graph\ProvidesGraphDefinitions;
use LibreNMS\Graph\RrdMetricBinding;

class PortGraphCatalog implements ProvidesGraphDefinitions
{
    /** @return GraphDefinition[] */
    public static function definitions(): array
    {
        return [
            new PortLineGraph('port_bits', 'Traffic', 'bps', [
                ['name' => 'In',  'key' => 'bits_in',  'ds' => 'INOCTETS',  'metric' => 'port.if_in_bits_rate',  'transform' => fn ($v) => $v * 8, 'color' => '90B040', 'lineColor' => '608720'],
                ['name' => 'Out', 'key' => 'bits_out', 'ds' => 'OUTOCTETS', 'metric' => 'port.if_out_bits_rate', 'transform' => fn ($v) => $v * 8, 'color' => '8080C0', 'lineColor' => '606090', 'negate' => true],
            ], markerBuilder: static function (GraphContext $context): array {
                $percentile = (float) LibrenmsConfig::get('percentile_value', 95);
                if ($percentile <= 0 || $percentile > 100) {
                    return [];
                }
                $portId = $context->query->entities['port_id'];
                $rrdName = "port-id$portId";
                $label = rtrim(rtrim((string) $percentile, '0'), '.');

                return [
                    ...GraphMarkerDefinition::dualPercentile("{$label}th percentile in", new RrdMetricBinding(rrdName: $rrdName, ds: 'INOCTETS', transform: fn ($v) => $v * 8), 'port.if_in_bits_rate', $percentile, 'aa0000'),
                    ...GraphMarkerDefinition::dualPercentile("{$label}th percentile out", new RrdMetricBinding(rrdName: $rrdName, ds: 'OUTOCTETS', transform: fn ($v) => $v * -8), 'port.if_out_bits_rate', $percentile, 'aa0000', fn ($v) => $v * -1),
                ];
            }),

            new PortLineGraph('port_packets', 'Packets', 'pps', [
                ['name' => 'In',  'key' => 'packets_in',  'ds' => 'INUCASTPKTS',  'metric' => 'port.if_in_ucast_pkts',  'color' => 'AA66AA', 'lineColor' => '330033'],
                ['name' => 'Out', 'key' => 'packets_out', 'ds' => 'OUTUCASTPKTS', 'metric' => 'port.if_out_ucast_pkts', 'color' => 'FFDD88', 'lineColor' => 'FF6600', 'negate' => true],
            ]),

            new PortLineGraph('port_discards', 'Discards', 'dps', [
                ['name' => 'In',  'key' => 'discards_in',  'ds' => 'INDISCARDS',  'metric' => 'port.if_in_discards',  'color' => 'FF8800', 'lineColor' => 'CC6600'],
                ['name' => 'Out', 'key' => 'discards_out', 'ds' => 'OUTDISCARDS', 'metric' => 'port.if_out_discards', 'color' => 'FFBB44', 'lineColor' => 'CC8811', 'negate' => true],
            ]),

            new PortLineGraph('port_errors', 'Errors & Discards', 'pps', [
                ['name' => 'Errors In',    'key' => 'errors_in',    'ds' => 'INERRORS',   'metric' => 'port.if_in_errors',   'color' => 'FF3300', 'lineColor' => 'CC2200', 'stack' => 'in'],
                ['name' => 'Errors Out',   'key' => 'errors_out',   'ds' => 'OUTERRORS',  'metric' => 'port.if_out_errors',  'color' => 'FF6633', 'lineColor' => 'CC4411', 'stack' => 'out', 'negate' => true],
                ['name' => 'Discards In',  'key' => 'discards_in',  'ds' => 'INDISCARDS', 'metric' => 'port.if_in_discards', 'color' => '805080', 'lineColor' => '603060', 'stack' => 'in'],
                ['name' => 'Discards Out', 'key' => 'discards_out', 'ds' => 'OUTDISCARDS', 'metric' => 'port.if_out_discards', 'color' => 'C0A060', 'lineColor' => '907030', 'stack' => 'out', 'negate' => true],
            ]),
        ];
    }
}
