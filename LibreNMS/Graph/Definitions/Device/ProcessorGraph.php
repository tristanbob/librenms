<?php

namespace LibreNMS\Graph\Definitions\Device;

use App\Facades\LibrenmsConfig;
use App\Models\Processor;
use LibreNMS\Graph\GraphDefinition;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Graph\GraphSeriesDefinition;
use LibreNMS\Graph\RrdMetricBinding;

class ProcessorGraph implements GraphDefinition
{
    public const GRAPH_TYPE = 'device_processor';

    public function graphType(): string { return self::GRAPH_TYPE; }

    public function id(array $device, GraphQuery $query): string
    {
        return self::GRAPH_TYPE . ':' . $device['device_id'];
    }

    public function title(array $device): string { return 'CPU Usage'; }

    public function subtitle(array $device, GraphQuery $query): string
    {
        return $device['hostname'] ?? '';
    }

    public function unit(array $device, GraphQuery $query): string { return '%'; }

    public function entityType(): string { return 'device'; }

    public function display(): array
    {
        return ['kind' => 'line', 'stacked' => false, 'area' => true, 'legend' => true];
    }

    public function series(array $device, GraphQuery $query): array
    {
        $processors = Processor::query()
            ->where('device_id', $device['device_id'])
            ->orderBy('processor_type')
            ->orderBy('processor_index')
            ->get();

        $colors = (array) LibrenmsConfig::get('graph_colours.mixed', LibrenmsConfig::get('graph_colours.oranges', []));
        $stacked = (bool) LibrenmsConfig::getOsSetting($device['os'] ?? '', 'processor_stacked');

        return $processors->values()->map(function (Processor $processor, int $i) use ($colors, $stacked) {
            $color = $colors[$i % max(count($colors), 1)] ?? 'CC0000';
            $name = short_hrDeviceDescr($processor->processor_descr);

            return new GraphSeriesDefinition(
                name: $name,
                key: 'processor_' . $processor->processor_id,
                unit: '%',
                area: true,
                stack: $stacked ? 'processor_usage' : null,
                color: $color,
                areaOpacity: 0.25,
                bindings: [new RrdMetricBinding(
                    rrdName: ['processor', $processor->processor_type, $processor->processor_index],
                    ds: 'usage',
                )],
            );
        })->all();
    }

    public function markers(array $device, GraphQuery $query): array { return []; }

    public function thresholds(array $device, GraphQuery $query): array { return []; }
}
