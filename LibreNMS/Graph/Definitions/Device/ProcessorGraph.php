<?php

namespace LibreNMS\Graph\Definitions\Device;

use App\Facades\LibrenmsConfig;
use App\Models\Processor;
use LibreNMS\Data\Store\VictoriaMetrics\VictoriaMetricsMetricCatalog;
use LibreNMS\Graph\Definitions\Templates\GraphTemplate;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Graph\GraphSeriesDefinition;
use LibreNMS\Graph\MetricSeries;
use LibreNMS\Graph\RrdMetricBinding;
use LibreNMS\Graph\VictoriaMetricsGraphDataProvider;

class ProcessorGraph extends GraphTemplate
{
    public const GRAPH_TYPE = 'device_processor';

    public function __construct()
    {
        parent::__construct(self::GRAPH_TYPE, 'CPU Usage', '%', ['area' => true]);
    }

    public function series(array $device, GraphQuery $query): array
    {
        $processors = Processor::query()
            ->where('device_id', $device['device_id'])
            ->orderBy('processor_type')
            ->orderBy('processor_index')
            ->get();

        $colors     = (array) LibrenmsConfig::get('graph_colours.mixed', LibrenmsConfig::get('graph_colours.oranges', []));
        $stacked    = (bool) LibrenmsConfig::getOsSetting($device['os'] ?? '', 'processor_stacked');
        $usageEntry = VictoriaMetricsMetricCatalog::get('processor.usage');

        return $processors->values()->map(function (Processor $processor, int $i) use ($colors, $stacked, $usageEntry) {
            $color = $colors[$i % max(count($colors), 1)] ?? 'CC0000';
            $name  = short_hrDeviceDescr($processor->processor_descr);

            return new GraphSeriesDefinition(
                name:        $name,
                key:         'processor_' . $processor->processor_id,
                unit:        '%',
                area:        true,
                stack:       $stacked ? 'processor_usage' : null,
                color:       $color,
                areaOpacity: 0.25,
                bindings:    MetricSeries::expression(
                    new RrdMetricBinding(
                        rrdName: ['processor', $processor->processor_type, $processor->processor_index],
                        ds: 'usage',
                    ),
                    function (array $entities) use ($processor, $usageEntry): string {
                        return VictoriaMetricsGraphDataProvider::buildSelector(
                            $usageEntry->definition->name,
                            $usageEntry->identityLabels,
                            [
                                'hostname'        => $entities['hostname'],
                                'processor_type'  => $processor->processor_type,
                                'processor_index' => (string) $processor->processor_index,
                            ],
                        );
                    },
                    ['hostname'],
                ),
            );
        })->all();
    }

}
