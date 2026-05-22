<?php

use App\Facades\LibrenmsConfig;
use LibreNMS\Graph\GraphDataUrl;

$renderer = LibrenmsConfig::get('graphs.renderer', 'rrd');

foreach ($graph_enable as $graph => $entry) {
    $graph_title = LibrenmsConfig::get("graph_types.device.$graph.descr");

    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">' . e($graph_title) . '</h3></div>';
    echo '<div class="panel-body">';

    if ($graph === 'poller_perf' && $renderer === 'echarts') {
        $hostname = e($device['hostname']);
        $periods = session('widescreen')
            ? LibrenmsConfig::get('graphs.mini.widescreen')
            : LibrenmsConfig::get('graphs.mini.normal');

        echo '<div class="row">';
        foreach ($periods as $period => $period_text) {
            $from = LibrenmsConfig::get("time.$period");
            $to = time();
            $data_url = GraphDataUrl::device((int) $device['device_id'], 'device_poller_perf', ['from' => $from, 'to' => $to]);
            $detail_url = \LibreNMS\Util\Url::generate([
                'page'   => 'graphs',
                'device' => $device['device_id'],
                'type'   => 'device_' . $graph,
                'from'   => $from,
            ]);

            echo '<div class="col-md-3 col-sm-6 col-xs-12">';
            echo '<div'
                . ' class="lnms-echart"'
                . ' style="width: 100%; height: 150px;"'
                . ' data-graph-url="' . e($data_url) . '"'
                . ' data-link-url="' . e($detail_url) . '"'
                . ' data-refresh="300"'
                . ' data-hide-datazoom="true"'
                . '></div>';
            echo '</div>';
        }
        echo '</div>';
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
