<?php

/**
 * DuplexGraph.php
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

namespace LibreNMS\Graph\Definitions\Templates;

use LibreNMS\Graph\GraphContext;
use LibreNMS\Graph\GraphSeriesDefinition;
use LibreNMS\Graph\MetricSeries;
use LibreNMS\Graph\RrdMetricBinding;

class DuplexGraph extends GraphTemplate
{
    public function __construct(
        string $graphType,
        string $title,
        string $unit,
        private readonly string|array $rrdNameIn,
        private readonly string|array $rrdNameOut,
        private readonly string $dsIn,
        private readonly string $dsOut,
        private readonly mixed $transform = null,
        private readonly string $inArea = '90B040',
        private readonly string $inLine = '608720',
        private readonly string $outArea = '8080C0',
        private readonly string $outLine = '606090',
        array $display = [],
        private readonly ?string $metricIn = null,
        private readonly ?string $metricOut = null,
    ) {
        parent::__construct($graphType, $title, $unit, $display + ['kind' => 'line', 'area' => true]);
    }

    public function series(GraphContext $context): array
    {
        $mirror = $this->stackedMultiplier() > 0;
        $rrdIn = new RrdMetricBinding($this->rrdNameIn, $this->dsIn, transform: $this->transform);
        $rrdOut = new RrdMetricBinding($this->rrdNameOut, $this->dsOut, transform: $this->transform);
        $bindingsIn = $this->metricIn === null ? [$rrdIn] : $this->metricBindings($this->metricIn, $rrdIn);
        $bindingsOut = $this->metricOut === null ? [$rrdOut] : $this->metricBindings($this->metricOut, $rrdOut);

        return [
            new GraphSeriesDefinition(
                name: 'In',
                key: str_replace('-', '_', $this->graphType) . '_in',
                unit: $this->unit($context),
                color: $this->inArea,
                lineColor: $this->inLine,
                area: true,
                areaOpacity: $mirror ? 0x88 / 0xff : 1.0,
                bindings: $bindingsIn,
            ),
            new GraphSeriesDefinition(
                name: 'Out',
                key: str_replace('-', '_', $this->graphType) . '_out',
                unit: $this->unit($context),
                color: $this->outArea,
                lineColor: $this->outLine,
                area: true,
                areaOpacity: $mirror ? 0x88 / 0xff : 1.0,
                negate: ! $mirror,
                bindings: $bindingsOut,
            ),
        ];
    }

    private function metricBindings(string $metric, RrdMetricBinding $rrd): array
    {
        // VM binding kind (gauge vs counter-rate) is derived from the metric catalog.
        return MetricSeries::metric($metric, $rrd, transform: $this->transform);
    }
}
