<?php

use LibreNMS\Interfaces\Plugins\Hooks\DeviceOverviewHook;

$overview = 1;

if (! function_exists('device_overview_echart_tag')) {
    function device_overview_echart_tag(array $graph_array, array $device, array $options = []): ?string
    {
        $from = (int) ($graph_array['from'] ?? \App\Facades\LibrenmsConfig::get('time.day'));
        $to = (int) ($graph_array['to'] ?? \App\Facades\LibrenmsConfig::get('time.now'));
        $width = (int) ($graph_array['width'] ?? 80);
        $height = (int) ($graph_array['height'] ?? 20);
        $dataUrl = device_overview_echart_data_url($graph_array, $device, ['from' => $from, 'to' => $to, 'width' => $width, 'height' => $height]);

        if ($dataUrl === null) {
            return null;
        }

        $link_array = $graph_array;
        $link_array['page'] = 'graphs';
        unset($link_array['height'], $link_array['width'], $link_array['legend'], $link_array['bg']);
        $link = \LibreNMS\Util\Url::generate($link_array);

        $isSparkline = ($options['sparkline'] ?? true);

        return '<div'
            . ' class="lnms-echart"'
            . ' style="width:' . $width . 'px;height:' . $height . 'px"'
            . ' data-graph-url="' . e($dataUrl) . '"'
            . (($options['link'] ?? true) ? ' data-link-url="' . e($link) . '"' : '')
            . ' data-hide-legend="' . (($options['hideLegend'] ?? true) ? 'true' : 'false') . '"'
            . ' data-hide-tooltip="' . (($options['hideTooltip'] ?? $isSparkline) ? 'true' : 'false') . '"'
            . ' data-sparkline="' . ($isSparkline ? 'true' : 'false') . '"'
            . '></div>';
    }

    function device_overview_echart_data_url(array $graph_array, array $device, array $query): ?string
    {
        if (\App\Facades\LibrenmsConfig::get('graphs.renderer', 'rrd') !== 'echarts') {
            return null;
        }

        $type = $graph_array['type'] ?? '';
        $registry = app(\LibreNMS\Graph\GraphDefinitionRegistry::class);
        if ($type === '' || ! $registry->supports($type)) {
            return null;
        }

        $definition = $registry->definitionFor($type);

        return match ($definition->entityType()) {
            'device' => \LibreNMS\Graph\GraphDataUrl::device((int) ($graph_array['device'] ?? $device['device_id']), $type, $query),
            'port' => isset($graph_array['id'])
                ? \LibreNMS\Graph\GraphDataUrl::port((int) $graph_array['id'], $type, $query)
                : null,
            'sensor' => isset($graph_array['id'])
                ? \LibreNMS\Graph\GraphDataUrl::sensor((int) ($graph_array['device'] ?? $device['device_id']), (int) $graph_array['id'], $type, $query)
                : null,
            'wireless_sensor' => isset($graph_array['id'])
                ? \LibreNMS\Graph\GraphDataUrl::wireless((int) ($graph_array['device'] ?? $device['device_id']), (int) $graph_array['id'], $type, $query)
                : null,
            'processor' => isset($graph_array['id'])
                ? \LibreNMS\Graph\GraphDataUrl::processor((int) ($graph_array['device'] ?? $device['device_id']), (int) $graph_array['id'], $type, $query)
                : null,
            'mempool' => isset($graph_array['id'])
                ? \LibreNMS\Graph\GraphDataUrl::mempool((int) ($graph_array['device'] ?? $device['device_id']), (int) $graph_array['id'], $type, $query)
                : null,
            'storage' => isset($graph_array['id'])
                ? \LibreNMS\Graph\GraphDataUrl::storage((int) ($graph_array['device'] ?? $device['device_id']), (int) $graph_array['id'], $type, $query)
                : null,
            'printer_supply' => isset($graph_array['id'])
                ? \LibreNMS\Graph\GraphDataUrl::printerSupply((int) ($graph_array['device'] ?? $device['device_id']), (int) $graph_array['id'], $type, $query)
                : null,
            default => null,
        };
    }

    function device_overview_echart_overlib_content(array $graph_array, array $device, string $title): ?string
    {
        return device_overview_echart_overlib_grid_content($graph_array, $device, $title, true);
    }

    function device_overview_echart_overlib_grid_content(array $graph_array, array $device, string $title, bool $include_history = true): ?string
    {
        $from = (int) ($graph_array['from'] ?? \App\Facades\LibrenmsConfig::get('time.day'));
        $to = (int) ($graph_array['to'] ?? \App\Facades\LibrenmsConfig::get('time.now'));
        $ranges = [$from];
        if ($include_history) {
            $ranges = [
                \App\Facades\LibrenmsConfig::get('time.day'),
                \App\Facades\LibrenmsConfig::get('time.week'),
                \App\Facades\LibrenmsConfig::get('time.month'),
                \App\Facades\LibrenmsConfig::get('time.year'),
            ];
        }

        $width = $include_history ? 320 : 450;
        $height = $include_history ? 95 : 150;
        $columns = $include_history ? 2 : 1;
        $gridWidth = ($width * $columns) + (6 * ($columns - 1));
        $graphs = '';

        foreach ($ranges as $rangeFrom) {
            $dataUrl = device_overview_echart_data_url($graph_array, $device, [
                'from' => (int) $rangeFrom,
                'to' => $to,
                'width' => $width,
                'height' => $height,
            ]);

            if ($dataUrl === null) {
                return null;
            }

            $graphs .= '<div class="lnms-echart"'
                . ' style="width:' . $width . 'px;height:' . $height . 'px"'
                . ' data-graph-url="' . e($dataUrl) . '"'
                . ' data-hide-legend="true"'
                . ' data-hide-tooltip="false"'
                . ' data-sparkline="false"'
                . '></div>';
        }

        $overlibWidth = $gridWidth + 8; // account for small overlib padding/border

        return '<div class=overlib style="width:' . $overlibWidth . 'px;max-width:calc(100vw - 32px);">'
            . '<span class=overlib-text>' . e(str_replace("'", '&#039;', $title)) . '</span><br />'
            . '<div style="display:grid;grid-template-columns:repeat(' . $columns . ', ' . $width . 'px);gap:6px;width:' . $gridWidth . 'px;max-width:calc(100vw - 48px);">'
            . $graphs
            . '</div>'
            . '</div>';
    }
}

echo '
<div class="tw:grid tw:grid-cols-1 tw:md:grid-cols-2 tw:gap-4">
    <div class="tw:min-w-0">
';
require 'includes/html/dev-overview-data.inc.php';
require 'overview/maps.inc.php';
require 'includes/html/dev-groups-overview-data.inc.php';
require 'overview/puppet_agent.inc.php';

echo LibreNMS\Plugins::call('device_overview_container', [$device]);
foreach (PluginManager::call(DeviceOverviewHook::class, ['device' => DeviceCache::getPrimary()]) as $view) {
    echo $view;
}

require 'overview/ports.inc.php';
require 'overview/availability_bar.inc.php';
require 'overview/transceivers.inc.php';

if ($device['os'] == 'ping') {
    require 'overview/ping.inc.php';
}

echo '
    </div>
    <div class="tw:min-w-0">
';
// Right Pane
require 'overview/processors.inc.php';
require 'overview/mempools.inc.php';
require 'overview/storage.inc.php';
require 'overview/toner.inc.php';
require 'overview/sensors/charge.inc.php';
require 'overview/sensors/temperature.inc.php';
require 'overview/sensors/humidity.inc.php';
require 'overview/sensors/fanspeed.inc.php';
require 'overview/sensors/dbm.inc.php';
require 'overview/sensors/voltage.inc.php';
require 'overview/sensors/current.inc.php';
require 'overview/sensors/runtime.inc.php';
require 'overview/sensors/power.inc.php';
require 'overview/sensors/power_consumed.inc.php';
require 'overview/sensors/power_factor.inc.php';
require 'overview/sensors/frequency.inc.php';
require 'overview/sensors/load.inc.php';
require 'overview/sensors/state.inc.php';
require 'overview/sensors/count.inc.php';
require 'overview/sensors/percent.inc.php';
require 'overview/sensors/signal.inc.php';
require 'overview/sensors/tv_signal.inc.php';
require 'overview/sensors/bitrate.inc.php';
require 'overview/sensors/airflow.inc.php';
require 'overview/sensors/snr.inc.php';
require 'overview/sensors/pressure.inc.php';
require 'overview/sensors/cooling.inc.php';
require 'overview/sensors/delay.inc.php';
require 'overview/sensors/quality_factor.inc.php';
require 'overview/sensors/chromatic_dispersion.inc.php';
require 'overview/sensors/ber.inc.php';
require 'overview/sensors/eer.inc.php';
require 'overview/sensors/waterflow.inc.php';
require 'overview/sensors/loss.inc.php';
require 'overview/sensors/signal_loss.inc.php';
require 'overview/eventlog.inc.php';
require 'overview/services.inc.php';
require 'overview/syslog.inc.php';
require 'overview/graylog.inc.php';
echo '</div></div>';

//require 'overview/current.inc.php");
