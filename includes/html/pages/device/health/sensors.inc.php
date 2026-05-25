<?php

use App\Facades\LibrenmsConfig;
use App\Models\Sensor;
use LibreNMS\Enum\Severity;
use LibreNMS\Graph\GraphDataUrl;
use LibreNMS\Util\Html;
use LibreNMS\Util\Url;

$row = 0;
$unit ??= $class->unit();
$graph_type ??= 'sensor_' . $class->value;

$sensors = Sensor::where('sensor_class', $class)->where('device_id', $device['device_id'])->orderBy('sensor_descr')->get();

foreach ($sensors as $sensor) {
    if (! is_int($row++ / 2)) {
        $row_colour = LibrenmsConfig::get('list_colour.even');
    } else {
        $row_colour = LibrenmsConfig::get('list_colour.odd');
    }

    if ($sensor['poller_type'] == 'ipmi') {
        $sensor_descr = e(ipmiSensorName($device['hardware'], $sensor['sensor_descr']));
    } else {
        $sensor_descr = e($sensor['sensor_descr']);
    }

    $sensor_current = Html::severityToLabel($sensor->currentStatus(), __('Current') . ': ' . $sensor->formatValue());

    echo "<div class='panel panel-default'>
        <div class='panel-heading'>
        <h3 class='panel-title'>$sensor_descr <div class='pull-right'>$sensor_current";

    //Display low and high limit if they are not null (format_si() is changing null to '0')
    if (! is_null($sensor->sensor_limit_low)) {
        echo ' ' . Html::severityToLabel(Severity::Unknown, __('Low Limit') . ': ' . $sensor->formatValue('sensor_limit_low'));
    }
    if (! is_null($sensor->sensor_limit_low_warn)) {
        echo ' ' . Html::severityToLabel(Severity::Unknown, __('Low Warning') . ': ' . $sensor->formatValue('sensor_limit_low_warn'));
    }
    if (! is_null($sensor->sensor_limit_warn)) {
        echo ' ' . Html::severityToLabel(Severity::Unknown, __('High Warning') . ': ' . $sensor->formatValue('sensor_limit_warn'));
    }
    if (! is_null($sensor->sensor_limit)) {
        echo ' ' . Html::severityToLabel(Severity::Unknown, __('High Limit') . ': ' . $sensor->formatValue('sensor_limit'));
    }

    echo '</div></h3>
        </div>';
    echo "<div class='panel-body'>";

    $renderer = LibrenmsConfig::get('graphs.renderer', 'rrd');
    if ($renderer === 'echarts') {
        $periods = LibrenmsConfig::get('graphs.mini.normal');
        $echartsGraphType = 'sensor_' . $sensor->sensor_class;
        echo '<div class="row">';
        foreach ($periods as $period => $period_text) {
            $from    = LibrenmsConfig::get("time.$period");
            $to      = time();
            $dataUrl = GraphDataUrl::sensor((int) $device['device_id'], $sensor->sensor_id, $echartsGraphType, ['from' => $from, 'to' => $to]);
            $linkUrl = Url::generate(['page' => 'graphs', 'type' => $graph_type, 'id' => $sensor->sensor_id, 'from' => $from, 'to' => $to]);
            echo '<div class="col-md-3 col-sm-6 col-xs-12">';
            echo '<a href="' . e($linkUrl) . '">';
            echo '<div class="lnms-echart" style="width:100%;height:150px" data-graph-url="' . e($dataUrl) . '" data-link-url="' . e($linkUrl) . '"></div>';
            echo '</a></div>';
        }
        echo '</div>';
    } else {
        $graph_array['id']   = $sensor['sensor_id'];
        $graph_array['type'] = $graph_type;
        include 'includes/html/print-graphrow.inc.php';
    }

    echo '</div></div>';
}
