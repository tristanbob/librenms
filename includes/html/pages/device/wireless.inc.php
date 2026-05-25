<?php

use App\Models\WirelessSensor;
use App\Facades\LibrenmsConfig;
use LibreNMS\Enum\WirelessSensorType;
use LibreNMS\Graph\GraphDataUrl;
use LibreNMS\Util\Number;
use LibreNMS\Util\Url;

// this determines the order of the tabs
$db_classes = WirelessSensor::where('device_id', $device['device_id'])
    ->distinct()
    ->pluck('sensor_class')
    ->map(fn (WirelessSensorType $class) => $class->value)
    ->all();
$sensor_classes = array_intersect(WirelessSensorType::values(), $db_classes);

$wireless_link_array = [
    'page' => 'device',
    'device' => $device['device_id'],
    'tab' => 'wireless',
];

print_optionbar_start();

echo "<span style='font-weight: bold;'>Wireless</span> &#187; ";

if (empty($vars['metric'])) {
    $vars['metric'] = 'overview';
}

$sep = '';
echo '<span' . ($vars['metric'] == 'overview' ? ' class="pagemenu-selected"' : '') . '>';
echo generate_link('Overview', $wireless_link_array, ['metric' => 'overview']);
echo '</span>';

foreach ($sensor_classes as $type) {
    echo ' | <span';
    if ($vars['metric'] == $type) {
        echo ' class="pagemenu-selected"';
    }
    echo '>';

    echo generate_link(__("wireless.$type.short"), $wireless_link_array, ['metric' => $type]);

    echo '</span>';
}

print_optionbar_end();

$renderer = LibrenmsConfig::get('graphs.renderer', 'rrd');
$periods = LibrenmsConfig::get('graphs.mini.normal');

$printEchartsRow = function (callable $dataUrl, callable $linkUrl, bool $hideLegend = false) use ($periods): void {
    echo '<div class="row">';
    foreach ($periods as $period => $period_text) {
        $from = LibrenmsConfig::get("time.$period");
        $to = time();

        echo '<div class="col-md-3 col-sm-6 col-xs-12">';
        echo '<a href="' . e($linkUrl($from, $to)) . '">';
        echo '<div'
            . ' class="lnms-echart"'
            . ' style="width:100%;height:150px"'
            . ' data-graph-url="' . e($dataUrl($from, $to)) . '"'
            . ' data-link-url="' . e($linkUrl($from, $to)) . '"'
            . ($hideLegend ? ' data-hide-legend="true"' : '')
            . '></div>';
        echo '</a></div>';
    }
    echo '</div>';
};

if ($vars['metric'] == 'overview') {
    foreach ($sensor_classes as $type) {
        $text = __("wireless.$type.long");
        $unit = $type === WirelessSensorType::Frequency->value ? 'Hz' : __("wireless.$type.unit");
        if (! empty($unit)) {
            $text .= " ($unit)";
        }

        $graph_title = generate_link($text, $wireless_link_array, ['metric' => $type]);
        $graph_array['type'] = 'device_wireless_' . $type;

        echo '<div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title">' . $graph_title . '</h3>
            </div>
            <div class="panel-body">';

        if ($renderer === 'echarts') {
            $graphType = 'device_wireless_' . $type;
            $printEchartsRow(
                fn (int $from, int $to): string => GraphDataUrl::device((int) $device['device_id'], $graphType, ['from' => $from, 'to' => $to]),
                fn (int $from, int $to): string => Url::generate(['page' => 'graphs', 'device' => $device['device_id'], 'type' => $graphType, 'from' => $from, 'to' => $to]),
                true,
            );
        } else {
            echo '<div class="row">';
            include LibrenmsConfig::get('install_dir') . '/includes/html/print-graphrow.inc.php';
            echo '</div>';
        }

        echo '</div></div>';
    }
} elseif (WirelessSensorType::tryFrom($vars['metric'])) {
    $unit = __('wireless.' . $vars['metric'] . '.unit');
    $factor = 1;
    if ($unit == 'MHz') {
        $unit = 'Hz';
        $factor = 1000000;
    }
    $row = 0;

    $sensors = dbFetchRows(
        'SELECT * FROM `wireless_sensors` WHERE `sensor_class` = ? AND `device_id` = ? ORDER BY `sensor_descr`',
        [$vars['metric'], $device['device_id']]
    );
    foreach ($sensors as $sensor) {
        if (! is_int($row++ / 2)) {
            $row_colour = LibrenmsConfig::get('list_colour.even');
        } else {
            $row_colour = LibrenmsConfig::get('list_colour.odd');
        }

        $sensor_descr = $sensor['sensor_descr'];

        if (empty($unit)) {
            $sensor_current = ((int) $sensor['sensor_current']) . $unit;
            $sensor_limit = ((int) $sensor['sensor_limit']) . $unit;
            $sensor_limit_low = ((int) $sensor['sensor_limit_low']) . $unit;
        } else {
            $sensor_current = Number::formatSi($sensor['sensor_current'] * $factor, 3, 0, $unit);
            $sensor_limit = Number::formatSi($sensor['sensor_limit'] * $factor, 3, 0, $unit);
            $sensor_limit_low = Number::formatSi($sensor['sensor_limit_low'] * $factor, 3, 0, $unit);
        }

        echo "<div class='panel panel-default'>
            <div class='panel-heading'>
                <h3 class='panel-title'>
                    $sensor_descr
                    <div class='pull-right'>$sensor_current | $sensor_limit_low <> $sensor_limit</div>
                </h3>
            </div>";
        echo "<div class='panel-body'>";

        $graph_array['id'] = $sensor['sensor_id'];
        $graph_array['type'] = 'wireless_' . $vars['metric'];

        if ($renderer === 'echarts') {
            $graphType = 'wireless_' . $vars['metric'];
            $sensorId = (int) $sensor['sensor_id'];
            $printEchartsRow(
                fn (int $from, int $to): string => GraphDataUrl::wireless((int) $device['device_id'], $sensorId, $graphType, ['from' => $from, 'to' => $to]),
                fn (int $from, int $to): string => Url::generate(['page' => 'graphs', 'device' => $device['device_id'], 'type' => $graphType, 'id' => $sensorId, 'from' => $from, 'to' => $to]),
            );
        } else {
            include LibrenmsConfig::get('install_dir') . '/includes/html/print-graphrow.inc.php';
        }

        echo '</div></div>';
    }
}

$pagetitle[] = 'Wireless';
