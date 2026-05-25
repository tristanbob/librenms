<?php

/**
 * includes/html/pages/device/health.inc.php
 *
 * piece of code responssible for display health information on device page
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
 * @copyright  2022 Peca Nesovanovic
 * @author     Peca Nesovanovic <peca.nesovanovic@sattrakt.com>
 */

use App\Facades\LibrenmsConfig;
use App\Models\DiskIo;
use App\Models\Mempool;
use App\Models\Processor;
use App\Models\Sensor;
use App\Models\Storage;
use LibreNMS\Graph\GraphDataUrl;
use LibreNMS\Util\Url;

/*
# QFP count for cisco devices
*/

$qfp = 0;
if ($device['os_group'] == 'cisco') {
    $component = new LibreNMS\Component();
    $components = $component->getComponents($device['device_id'], ['type' => 'cisco-qfp']);
    $components = $components[$device['device_id']];
    $qfp = isset($components) ? count($components) : 0;
}

unset($datas);
$datas[] = 'overview';

if (Processor::where('device_id', $device['device_id'])->exists()) {
    $datas[] = 'processor';
}

if ($qfp) {
    $datas[] = 'qfp';
}

if (Mempool::where('device_id', $device['device_id'])->exists()) {
    $datas[] = 'mempool';
}

if (Storage::where('device_id', $device['device_id'])->exists()) {
    $datas[] = 'storage';
}

if (DiskIo::where('device_id', $device['device_id'])->exists()) {
    $datas[] = 'diskio';
}

foreach (Sensor::where('device_id', $device['device_id'])->distinct()->pluck('sensor_class') as $sensor_class) {
    $datas[] = $sensor_class;
    $type_text[$sensor_class] = trans('sensors.' . $sensor_class . '.short');
}

$type_text['overview'] = 'Overview';
$type_text['qfp'] = 'QFP';
$type_text['processor'] = 'Processor';
$type_text['mempool'] = 'Memory';
$type_text['storage'] = 'Disk Usage';
$type_text['diskio'] = 'Disk I/O';

$link_array = [
    'page' => 'device',
    'device' => $device['device_id'],
    'tab' => 'health',
];

print_optionbar_start();

echo "<span style='font-weight: bold;'>Health</span> &#187; ";

if (empty($vars['metric'])) {
    $vars['metric'] = 'overview';
}

$sep = '';
foreach ($datas as $type) {
    echo $sep;
    if ($vars['metric'] == $type) {
        echo '<span class="pagemenu-selected">';
    }

    echo generate_link($type_text[$type], $link_array, ['metric' => $type]);
    if ($vars['metric'] == $type) {
        echo '</span>';
    }

    $sep = ' | ';
}

print_optionbar_end();

$metric = basename((string) $vars['metric']);
if (is_file("includes/html/pages/device/health/$metric.inc.php")) {
    include "includes/html/pages/device/health/$metric.inc.php";
} else {
    $renderer = LibrenmsConfig::get('graphs.renderer', 'rrd');
    $registry  = $renderer === 'echarts' ? app(\LibreNMS\Graph\GraphDefinitionRegistry::class) : null;
    $periods   = LibrenmsConfig::get('graphs.mini.normal');

    foreach ($datas as $type) {
        if ($type === 'overview') {
            continue;
        }

        $graph_title = $type_text[$type];
        $graphType   = 'device_' . $type;

        echo '<div class="panel panel-default">';
        echo '<div class="panel-heading"><h3 class="panel-title">' . e($graph_title) . '</h3></div>';
        echo '<div class="panel-body"><div class="row">';

        if ($registry !== null && $registry->supports($graphType)) {
            $to = time();
            foreach ($periods as $period => $period_text) {
                $from    = (int) LibrenmsConfig::get("time.$period");
                $dataUrl = GraphDataUrl::device((int) $device['device_id'], $graphType, ['from' => $from, 'to' => $to]);
                $linkUrl = Url::generate(['page' => 'device', 'device' => $device['device_id'], 'tab' => 'health', 'metric' => $type]);
                echo '<div class="col-md-3 col-sm-6 col-xs-12">';
                echo '<div class="lnms-echart" style="width:100%;height:150px"'
                    . ' data-graph-url="' . e($dataUrl) . '"'
                    . ' data-link-url="' . e($linkUrl) . '"></div>';
                echo '</div>';
            }
        } else {
            $graph_array          = [];
            $graph_array['type']  = $graphType;
            $graph_array['device'] = $device['device_id'];
            include 'includes/html/print-graphrow.inc.php';
        }

        echo '</div></div></div>';
    }
}

$pagetitle[] = 'Health';
