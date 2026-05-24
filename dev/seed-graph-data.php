<?php

use App\Models\Device;
use App\Models\Port;
use App\Facades\Rrd;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

$hostCount = max(1, min(25, (int) env('DEV_HOSTS', 1)));
$interfacesPerHost = max(1, min(1000, (int) env('DEV_INTERFACES', 48)));
$historyHours = max(1, min(72, (int) env('DEV_HISTORY_HOURS', 6)));
$step = max(30, min(300, (int) env('DEV_HISTORY_STEP', 60)));
$now = time() - (time() % $step);
$start = $now - ($historyHours * 3600);

$primary = Device::where('hostname', 'snmp-device')->firstOrFail();
$devices = [];

for ($host = 1; $host <= $hostCount; $host++) {
    $hostname = sprintf('synthetic-device-%02d', $host);
    $device = Device::updateOrCreate(
        ['hostname' => $hostname],
        [
            'sysName' => $hostname,
            'community' => 'public',
            'snmpver' => 'v2c',
            'snmp_disable' => true,
            'os' => 'linux',
            'status' => true,
            'status_reason' => '',
            'hardware' => 'Synthetic LibreNMS graph host',
            'type' => 'server',
            'last_polled' => now(),
            'last_discovered' => now(),
        ]
    );
    $devices[] = $device;
}

$rrdDefinition = [
    'INOCTETS',
    'OUTOCTETS',
    'INERRORS',
    'OUTERRORS',
    'INUCASTPKTS',
    'OUTUCASTPKTS',
    'INNUCASTPKTS',
    'OUTNUCASTPKTS',
    'INDISCARDS',
    'OUTDISCARDS',
    'INUNKNOWNPROTOS',
    'INBROADCASTPKTS',
    'OUTBROADCASTPKTS',
    'INMULTICASTPKTS',
    'OUTMULTICASTPKTS',
];
$rra = [
    'RRA:AVERAGE:0.5:1:2016',
    'RRA:AVERAGE:0.5:6:1440',
    'RRA:AVERAGE:0.5:24:1440',
    'RRA:AVERAGE:0.5:288:1440',
    'RRA:MIN:0.5:1:2016',
    'RRA:MIN:0.5:6:1440',
    'RRA:MIN:0.5:24:1440',
    'RRA:MIN:0.5:288:1440',
    'RRA:MAX:0.5:1:2016',
    'RRA:MAX:0.5:6:1440',
    'RRA:MAX:0.5:24:1440',
    'RRA:MAX:0.5:288:1440',
    'RRA:LAST:0.5:1:2016',
];

$seededPorts = 0;
$vmLines = [];

foreach ($devices as $deviceIndex => $device) {
    DB::table('device_graphs')->updateOrInsert(
        ['device_id' => $device->device_id, 'graph' => 'poller_perf'],
        ['device_id' => $device->device_id, 'graph' => 'poller_perf']
    );

    for ($interface = 1; $interface <= $interfacesPerHost; $interface++) {
        $ifIndex = 10000 + $interface;
        $ifName = sprintf('dev%d', $interface);
        $speed = [100000000, 1000000000, 10000000000][$interface % 3];
        $port = Port::updateOrCreate(
            ['device_id' => $device->device_id, 'ifIndex' => $ifIndex],
            [
                'ifName' => $ifName,
                'ifDescr' => sprintf('Dev Interface %d', $interface),
                'ifAlias' => sprintf('Synthetic traffic sample %s/%s', $device->hostname, $ifName),
                'ifType' => 'ethernetCsmacd',
                'ifSpeed' => $speed,
                'ifOperStatus' => 'up',
                'ifAdminStatus' => 'up',
                'ifDuplex' => 'fullDuplex',
                'ifMtu' => 1500,
                'ifConnectorPresent' => 'true',
                'ignore' => false,
                'disabled' => false,
                'deleted' => false,
            ]
        );

        [$lastCounters, $rates] = seedPortRrd($device, $port, $rrdDefinition, $rra, $start, $now, $step, $deviceIndex, $interface);
        collectVmSamples($vmLines, $device, $port, $start, $now, $step, $deviceIndex, $interface);
        flushVmSamples($vmLines, false);

        $port->forceFill([
            'ifInOctets' => $lastCounters['inOctets'],
            'ifOutOctets' => $lastCounters['outOctets'],
            'ifInUcastPkts' => $lastCounters['inPackets'],
            'ifOutUcastPkts' => $lastCounters['outPackets'],
            'ifInErrors' => $lastCounters['inErrors'],
            'ifOutErrors' => $lastCounters['outErrors'],
            'ifInOctets_rate' => (int) ($rates['inBits'] / 8),
            'ifOutOctets_rate' => (int) ($rates['outBits'] / 8),
            'ifInUcastPkts_rate' => $rates['inPackets'],
            'ifOutUcastPkts_rate' => $rates['outPackets'],
            'ifInErrors_rate' => $rates['inErrors'],
            'ifOutErrors_rate' => $rates['outErrors'],
            'poll_time' => $now,
            'poll_prev' => $now - $step,
            'poll_period' => $step,
        ])->save();

        $seededPorts++;
    }
}

flushVmSamples($vmLines, true);

echo sprintf(
    "seeded %d synthetic devices, %d synthetic interfaces per device, %d hours at %d second step; live device is %s (%d)\n",
    count($devices),
    $interfacesPerHost,
    $historyHours,
    $step,
    $primary->hostname,
    $primary->device_id
);

function seedPortRrd(Device $device, Port $port, array $datasets, array $rra, int $start, int $now, int $step, int $deviceIndex, int $interface): array
{
    $file = Rrd::name($device->hostname, Rrd::portName($port->port_id));
    $dir = dirname($file);
    if (! is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    if (is_file($file)) {
        unlink($file);
    }

    $create = ['rrdtool', 'create', $file, '--start', (string) ($start - $step), '--step', (string) $step];
    foreach ($datasets as $dataset) {
        $create[] = sprintf('DS:%s:DERIVE:600:0:12500000000', $dataset);
    }
    array_push($create, ...$rra);
    runProcess($create);

    $updates = [];
    $inOctets = 1000000000 + ($deviceIndex * 100000000) + ($interface * 1000000);
    $outOctets = 600000000 + ($deviceIndex * 100000000) + ($interface * 700000);
    $inPackets = 10000000 + ($interface * 10000);
    $outPackets = 9000000 + ($interface * 8000);
    $inErrors = 10 + $interface;
    $outErrors = 5 + $interface;
    $rates = [];

    for ($timestamp = $start; $timestamp <= $now; $timestamp += $step) {
        $phase = (($timestamp / $step) + $interface + ($deviceIndex * 7));
        $inBits = syntheticRate($phase, $interface, 0.32);
        $outBits = syntheticRate($phase, $interface, 0.24);
        $inPacketRate = max(1, (int) ($inBits / 8000));
        $outPacketRate = max(1, (int) ($outBits / 9000));
        $inErrorRate = $phase % 97 === 0 ? 1 : 0;
        $outErrorRate = $phase % 131 === 0 ? 1 : 0;

        $inOctets += (int) (($inBits / 8) * $step);
        $outOctets += (int) (($outBits / 8) * $step);
        $inPackets += $inPacketRate * $step;
        $outPackets += $outPacketRate * $step;
        $inErrors += $inErrorRate;
        $outErrors += $outErrorRate;

        $updates[] = implode(':', [
            $timestamp,
            $inOctets,
            $outOctets,
            $inErrors,
            $outErrors,
            $inPackets,
            $outPackets,
            0,
            0,
            0,
            0,
            0,
            max(1, (int) ($inPacketRate / 20)),
            max(1, (int) ($outPacketRate / 20)),
            max(1, (int) ($inPacketRate / 15)),
            max(1, (int) ($outPacketRate / 15)),
        ]);
        $rates = [
            'inBits' => $inBits,
            'outBits' => $outBits,
            'inPackets' => $inPacketRate,
            'outPackets' => $outPacketRate,
            'inErrors' => $inErrorRate,
            'outErrors' => $outErrorRate,
        ];
    }

    runProcess(['rrdtool', 'update', $file, ...$updates]);

    return [[
        'inOctets' => $inOctets,
        'outOctets' => $outOctets,
        'inPackets' => $inPackets,
        'outPackets' => $outPackets,
        'inErrors' => $inErrors,
        'outErrors' => $outErrors,
    ], $rates];
}

function collectVmSamples(array &$lines, Device $device, Port $port, int $start, int $now, int $step, int $deviceIndex, int $interface): void
{
    $labels = [
        'device_id' => (string) $device->device_id,
        'entity_type' => 'port',
        'hostname' => (string) $device->hostname,
        'ifName' => (string) $port->ifName,
        'port_id' => (string) $port->port_id,
        'source' => 'librenms',
    ];

    for ($timestamp = $start; $timestamp <= $now; $timestamp += $step) {
        $phase = (($timestamp / $step) + $interface + ($deviceIndex * 7));
        $lines[] = vmLine('librenms_port_if_in_bits_per_second', $labels, syntheticRate($phase, $interface, 0.32), $timestamp);
        $lines[] = vmLine('librenms_port_if_out_bits_per_second', $labels, syntheticRate($phase, $interface, 0.24), $timestamp);
    }
}

function flushVmSamples(array &$lines, bool $force): void
{
    if (count($lines) < 5000 && ! $force) {
        return;
    }

    if ($lines === []) {
        return;
    }

    $body = implode("\n", $lines) . "\n";
    $lines = [];
    $response = Http::timeout(10)->withBody($body, 'text/plain')->post('http://vmagent:8429/api/v1/import/prometheus');
    if ($response->failed()) {
        fwrite(STDERR, "VictoriaMetrics import returned HTTP {$response->status()}\n");
    }
}

function syntheticRate(float $phase, int $interface, float $scale): int
{
    $base = 2000000 + ($interface * 110000);
    $wave = (sin($phase / 9) + 1) * 900000;
    $burst = ((int) $phase % (17 + ($interface % 11)) === 0) ? 6000000 : 0;

    return (int) (($base + $wave + $burst) * $scale);
}

function vmLine(string $metric, array $labels, int $value, int $timestamp): string
{
    ksort($labels);
    $pairs = [];
    foreach ($labels as $key => $label) {
        $pairs[] = $key . '="' . str_replace(["\\", "\n", '"'], ["\\\\", "\\n", '\\"'], $label) . '"';
    }

    return sprintf('%s{%s} %d %d', $metric, implode(',', $pairs), $value, $timestamp * 1000);
}

function runProcess(array $command): void
{
    $process = new Process($command);
    $process->setTimeout(120);
    $process->mustRun();
}
