<?php

use App\Facades\LibrenmsConfig;
use LibreNMS\Graph\GraphDataUrl;
use LibreNMS\Util\Url;

if (Rrd::checkRrdExists(get_port_rrdfile_path($device['hostname'], $port['port_id']))) {
    $renderer = LibrenmsConfig::get('graphs.renderer', 'rrd');

    echo '<div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title">Interface Traffic</h3>
            </div>';

    echo '<div class="panel-body">';
    if ($renderer === 'echarts') {
        $periods = session('widescreen')
            ? LibrenmsConfig::get('graphs.mini.widescreen')
            : LibrenmsConfig::get('graphs.mini.normal');

        $portId = (int) $port['port_id'];
        echo '<div class="row">';
        foreach ($periods as $period => $period_text) {
            $from    = LibrenmsConfig::get("time.$period");
            $to      = time();
            $dataUrl = GraphDataUrl::port($portId, 'port_bits', ['from' => $from, 'to' => $to]);
            $linkUrl = Url::generate(['page' => 'graphs', 'type' => 'port_bits', 'id' => $portId, 'from' => $from, 'to' => $to]);

            echo '<div class="col-md-3 col-sm-6 col-xs-12">';
            echo '<div'
                . ' class="lnms-echart"'
                . ' style="width: 100%; height: 200px;"'
                . ' data-graph-url="' . e($dataUrl) . '"'
                . ' data-link-url="' . e($linkUrl) . '"'
                . ' data-hide-datazoom="true"'
                . '></div>';
            echo '</div>';
        }
        echo '</div>';
    } else {
        $graph_type = 'port_bits';
        include 'includes/html/print-interface-graphs.inc.php';
    }
    echo '</div></div>';

    echo '<div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title">Interface Packets</h3>
            </div>';
    echo '<div class="panel-body">';
    if ($renderer === 'echarts') {
        $periods = session('widescreen')
            ? LibrenmsConfig::get('graphs.mini.widescreen')
            : LibrenmsConfig::get('graphs.mini.normal');

        echo '<div class="row">';
        foreach ($periods as $period => $period_text) {
            $from    = LibrenmsConfig::get("time.$period");
            $to      = time();
            $dataUrl = GraphDataUrl::port($portId, 'port_packets', ['from' => $from, 'to' => $to]);
            $linkUrl = Url::generate(['page' => 'graphs', 'type' => 'port_packets', 'id' => $portId, 'from' => $from, 'to' => $to]);

            echo '<div class="col-md-3 col-sm-6 col-xs-12">';
            echo '<div'
                . ' class="lnms-echart"'
                . ' style="width: 100%; height: 200px;"'
                . ' data-graph-url="' . e($dataUrl) . '"'
                . ' data-link-url="' . e($linkUrl) . '"'
                . ' data-hide-datazoom="true"'
                . '></div>';
            echo '</div>';
        }
        echo '</div>';
    } else {
        $graph_type = 'port_upkts';
        include 'includes/html/print-interface-graphs.inc.php';
    }
    echo '</div></div>';

    echo '<div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title">Interface Non Unicast</h3>
            </div>';

    $graph_type = 'port_nupkts';
    echo '<div class="panel-body">';
    include 'includes/html/print-interface-graphs.inc.php';
    echo '</div></div>';

    echo '<div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title">Interface Errors</h3>
            </div>';
    echo '<div class="panel-body">';
    if ($renderer === 'echarts') {
        $periods = session('widescreen')
            ? LibrenmsConfig::get('graphs.mini.widescreen')
            : LibrenmsConfig::get('graphs.mini.normal');

        echo '<p class="text-muted small" style="margin-bottom:4px;">Errors</p>';
        echo '<div class="row">';
        foreach ($periods as $period => $period_text) {
            $from    = LibrenmsConfig::get("time.$period");
            $to      = time();
            $dataUrl = GraphDataUrl::port($portId, 'port_errors', ['from' => $from, 'to' => $to]);
            $linkUrl = Url::generate(['page' => 'graphs', 'type' => 'port_errors', 'id' => $portId, 'from' => $from, 'to' => $to]);

            echo '<div class="col-md-3 col-sm-6 col-xs-12">';
            echo '<div'
                . ' class="lnms-echart"'
                . ' style="width: 100%; height: 200px;"'
                . ' data-graph-url="' . e($dataUrl) . '"'
                . ' data-link-url="' . e($linkUrl) . '"'
                . ' data-hide-datazoom="true"'
                . '></div>';
            echo '</div>';
        }
        echo '</div>';

        echo '<p class="text-muted small" style="margin-top:12px; margin-bottom:4px;">Discards</p>';
        echo '<div class="row">';
        foreach ($periods as $period => $period_text) {
            $from    = LibrenmsConfig::get("time.$period");
            $to      = time();
            $dataUrl = GraphDataUrl::port($portId, 'port_discards', ['from' => $from, 'to' => $to]);
            $linkUrl = Url::generate(['page' => 'graphs', 'type' => 'port_discards', 'id' => $portId, 'from' => $from, 'to' => $to]);

            echo '<div class="col-md-3 col-sm-6 col-xs-12">';
            echo '<div'
                . ' class="lnms-echart"'
                . ' style="width: 100%; height: 200px;"'
                . ' data-graph-url="' . e($dataUrl) . '"'
                . ' data-link-url="' . e($linkUrl) . '"'
                . ' data-hide-datazoom="true"'
                . '></div>';
            echo '</div>';
        }
        echo '</div>';
    } else {
        $graph_type = 'port_errors';
        include 'includes/html/print-interface-graphs.inc.php';
    }
    echo '</div></div>';

    if (Rrd::checkRrdExists(get_port_rrdfile_path($device['hostname'], $port['port_id'], 'poe'))) {
        echo '<div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title">PoE</h3>
            </div>';
        $graph_type = 'port_poe';

        echo '<div class="panel-body">';
        include 'includes/html/print-interface-graphs.inc.php';
        echo '</div></div>';
    }

    if (LibrenmsConfig::get('enable_ports_etherlike') && Rrd::checkRrdExists(get_port_rrdfile_path($device['hostname'], $port['port_id'], 'dot3'))) {
        echo '<div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title">Ethernet Errors</h3>
            </div>';
        $graph_type = 'port_etherlike';

        echo '<div class="panel-body">';
        include 'includes/html/print-interface-graphs.inc.php';
        echo '</div></div>';
    }

    /*
     *  CISCO-IF-EXTENSION MIB statistics
     *  Additional information about input and output errors as seen in `show interface` output.
     */
    if (Rrd::checkRrdExists(get_port_rrdfile_path($device['hostname'], $port['port_id'], 'cie'))) {
        echo '<div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title">Detailed interface errors</h3>
            </div>';
        $graph_type = 'port_cie';

        echo '<div class="panel-body">';
        include 'includes/html/print-interface-graphs.inc.php';
        echo '</div></div>';
    }
}
