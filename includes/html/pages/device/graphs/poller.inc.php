<?php

use App\Facades\LibrenmsConfig;

$renderer = LibrenmsConfig::get('graphs.renderer', 'rrd');

foreach ($graph_enable as $graph => $entry) {
    $graph_title = LibrenmsConfig::get("graph_types.device.$graph.descr");

    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">' . e($graph_title) . '</h3></div>';
    echo '<div class="panel-body">';

    if ($graph === 'poller_perf' && $renderer === 'echarts') {
        $hostname = e($device['hostname']);
        $data_url = "/api/v0/devices/$hostname/graphs/device_poller_perf/data";
        echo '<div'
            . ' class="lnms-echart"'
            . ' style="width: 100%; height: 300px;"'
            . ' data-graph-url="' . $data_url . '"'
            . ' data-refresh="300"'
            . '></div>';
    } else {
        $graph_array = [
            'device' => $device['device_id'],
            'type'   => 'device_' . $graph,
        ];
        echo "<div class='row'>";
        require 'includes/html/print-graphrow.inc.php';
        echo '</div>';
    }

    echo '</div></div>';
}
