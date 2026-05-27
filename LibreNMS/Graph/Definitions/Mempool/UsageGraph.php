<?php

namespace LibreNMS\Graph\Definitions\Mempool;

use LibreNMS\Data\Store\VictoriaMetrics\VictoriaMetricsMetricCatalog;
use LibreNMS\Graph\GraphDefinition;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Graph\GraphSeriesDefinition;
use LibreNMS\Graph\MetricSeries;
use LibreNMS\Graph\RrdMetricBinding;
use LibreNMS\Graph\VictoriaMetricsGraphDataProvider;

class UsageGraph implements GraphDefinition
{
    use \LibreNMS\Graph\DefaultVariables;

    public const GRAPH_TYPE = 'mempool_usage';

    public function graphType(): string { return self::GRAPH_TYPE; }

    public function id(array $device, GraphQuery $query): string
    {
        return self::GRAPH_TYPE . ':' . ($query->entities['mempool_id'] ?? '');
    }

    public function title(array $device): string { return 'Memory Usage'; }

    public function subtitle(array $device, GraphQuery $query): string
    {
        return ($device['hostname'] ?? '') . ' - ' . ($query->entities['mempool_descr'] ?? '');
    }

    public function unit(array $device, GraphQuery $query): string { return '%'; }

    public function entityType(): string { return 'mempool'; }

    public function display(): array
    {
        return ['kind' => 'line', 'stacked' => false, 'area' => true];
    }

    public function series(array $device, GraphQuery $query): array
    {
        $e = $query->entities;

        return [new GraphSeriesDefinition(
            name: $e['mempool_descr'] ?? 'memory',
            key: 'mempool',
            unit: '%',
            area: true,
            color: 'CC0000',
            areaOpacity: 0.25,
            bindings: MetricSeries::expression(
                new RrdMetricBinding(
                    rrdName: ['mempool', $e['mempool_type'] ?? '', $e['mempool_class'] ?? '', $e['mempool_index'] ?? ''],
                    ds: ['used', 'free'],
                    transform: fn (array $v) => ($v['used'] + $v['free']) > 0 ? ($v['used'] / ($v['used'] + $v['free']) * 100) : null,
                ),
                fn (array $entities): string => self::usageExpression($entities),
                ['device_id', 'mempool_type', 'mempool_class', 'mempool_index'],
            ),
        )];
    }

    public function markers(array $device, GraphQuery $query): array { return []; }

    private static function usageExpression(array $entities): string
    {
        $used = VictoriaMetricsMetricCatalog::get('mempool.used');
        $free = VictoriaMetricsMetricCatalog::get('mempool.free');

        $usedSelector = VictoriaMetricsGraphDataProvider::buildSelector($used->definition->name, $used->identityLabels, $entities);
        $freeSelector = VictoriaMetricsGraphDataProvider::buildSelector($free->definition->name, $free->identityLabels, $entities);

        return "100 * {$usedSelector} / ({$usedSelector} + {$freeSelector})";
    }

}
