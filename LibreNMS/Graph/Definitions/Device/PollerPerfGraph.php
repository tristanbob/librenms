<?php

/**
 * PollerPerfGraph.php
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
use LibreNMS\Graph\Definitions\Templates\GraphTemplate;
use LibreNMS\Graph\GraphContext;
use LibreNMS\Graph\GraphSeriesDefinition;
use LibreNMS\Graph\MetricSeries;
use LibreNMS\Graph\RrdMetricBinding;

class PollerPerfGraph extends GraphTemplate
{
    public const GRAPH_TYPE = 'device_poller_perf';

    private const PALETTE = 'rainbow_stats_purple';

    public function __construct()
    {
        parent::__construct(
            graphType: self::GRAPH_TYPE,
            title:     'Poller Time',
            unit:      'seconds',
            display:   ['area' => true],
        );
    }

    public function series(GraphContext $context): array
    {
        $p = self::PALETTE;

        $series = [
            new GraphSeriesDefinition(
                name:        'Poller time',
                key:         'poller_time',
                unit:        $this->unit($context),
                color:       LibrenmsConfig::get("graph_colours.$p.0"),
                area:        true,
                areaOpacity: 0.2,
                bindings:    [
                    ...MetricSeries::gauge('device.poller.duration', new RrdMetricBinding(rrdName: 'poller-perf', ds: 'poller')),
                ],
            ),
        ];

        return array_merge($series, $this->trailingAverageSeries(
            $context,
            'poller_time',
            $p,
            fn (int $step) => [new RrdMetricBinding(rrdName: 'poller-perf', ds: 'poller', step: $step)],
        ));
    }
}
