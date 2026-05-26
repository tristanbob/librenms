<?php

namespace LibreNMS\Graph\Definitions\Legacy;

use LibreNMS\Graph\GraphQuery;
use LibreNMS\Graph\GraphSeriesDefinition;
use LibreNMS\Graph\RrdMetricBinding;

class DuplexGraph extends LegacyGraph
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
    ) {
        parent::__construct($graphType, $title, $unit, $display + ['kind' => 'line', 'area' => true]);
    }

    public function series(array $device, GraphQuery $query): array
    {
        $mirror = $this->stackedMultiplier() > 0;

        return [
            new GraphSeriesDefinition(
                name: 'In',
                key: str_replace('-', '_', $this->graphType) . '_in',
                unit: $this->unit($device, $query),
                color: $this->inArea,
                lineColor: $this->inLine,
                area: true,
                areaOpacity: $mirror ? 0x88 / 0xff : 1.0,
                bindings: [new RrdMetricBinding($this->rrdNameIn, $this->dsIn, transform: $this->transform)],
            ),
            new GraphSeriesDefinition(
                name: 'Out',
                key: str_replace('-', '_', $this->graphType) . '_out',
                unit: $this->unit($device, $query),
                color: $this->outArea,
                lineColor: $this->outLine,
                area: true,
                areaOpacity: $mirror ? 0x88 / 0xff : 1.0,
                negate: ! $mirror,
                bindings: [new RrdMetricBinding($this->rrdNameOut, $this->dsOut, transform: $this->transform)],
            ),
        ];
    }
}
