<?php

use App\Models\Device;
use App\Models\Port;
use App\Facades\Rrd;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

$hostCount = max(1, min(25, (int) env('DEV_HOSTS', 1)));
$interfacesPerHost = max(1, min(1000, (int) env('DEV_INTERFACES', 48)));
$historyHours = max(1, min(8760, (int) env('DEV_HISTORY_HOURS', 6)));
$step = max(30, min(300, (int) env('DEV_HISTORY_STEP', 60)));
$now = time() - (time() % $step);
$start = $now - ($historyHours * 3600);

$primary = Device::where('hostname', 'snmp-device')->firstOrFail();

// Device role templates — cycles through as hosts are created.
// ifaceStyle drives interface name generation; profile/asymmetry drive traffic shape.
$deviceTemplates = [
    [
        'os'           => 'cisco-ios',
        'hardware'     => 'Cisco Catalyst 3850-48T',
        'type'         => 'network',
        'sysNamePrefix' => 'sw',
        'ifaceStyle'   => 'catalyst',
    ],
    [
        'os'           => 'cisco-ios',
        'hardware'     => 'Cisco ISR 4451-X',
        'type'         => 'network',
        'sysNamePrefix' => 'rtr',
        'ifaceStyle'   => 'isr',
    ],
    [
        'os'           => 'ubuntu',
        'hardware'     => 'Dell PowerEdge R750',
        'type'         => 'server',
        'sysNamePrefix' => 'srv',
        'ifaceStyle'   => 'linux',
    ],
    [
        'os'           => 'junos',
        'hardware'     => 'Juniper EX4300-48T',
        'type'         => 'network',
        'sysNamePrefix' => 'sw',
        'ifaceStyle'   => 'junos',
    ],
];

$devices = [];

for ($host = 1; $host <= $hostCount; $host++) {
    $tmpl    = $deviceTemplates[($host - 1) % count($deviceTemplates)];
    $hostname = sprintf('synthetic-%s-%02d', $tmpl['sysNamePrefix'], $host);
    $device  = Device::updateOrCreate(
        ['hostname' => $hostname],
        [
            'sysName'          => $hostname,
            'community'        => 'public',
            'snmpver'          => 'v2c',
            'snmp_disable'     => true,
            'os'               => $tmpl['os'],
            'status'           => true,
            'status_reason'    => '',
            'hardware'         => $tmpl['hardware'],
            'type'             => $tmpl['type'],
            'last_polled'      => now(),
            'last_discovered'  => now(),
        ]
    );
    $devices[] = ['device' => $device, 'template' => $tmpl];
}

$rrdDefinition = [
    'INOCTETS', 'OUTOCTETS', 'INERRORS', 'OUTERRORS',
    'INUCASTPKTS', 'OUTUCASTPKTS', 'INNUCASTPKTS', 'OUTNUCASTPKTS',
    'INDISCARDS', 'OUTDISCARDS', 'INUNKNOWNPROTOS',
    'INBROADCASTPKTS', 'OUTBROADCASTPKTS',
    'INMULTICASTPKTS', 'OUTMULTICASTPKTS',
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
$vmLines     = [];

foreach ($devices as $deviceIndex => $entry) {
    $device = $entry['device'];
    $tmpl   = $entry['template'];

    DB::table('device_graphs')->updateOrInsert(
        ['device_id' => $device->device_id, 'graph' => 'poller_perf'],
        ['device_id' => $device->device_id, 'graph' => 'poller_perf']
    );

    for ($interface = 1; $interface <= $interfacesPerHost; $interface++) {
        $ifIndex = 10000 + $interface;
        $ifMeta  = interfaceMetadata($tmpl['ifaceStyle'], $interface, $interfacesPerHost);

        $port = Port::updateOrCreate(
            ['device_id' => $device->device_id, 'ifIndex' => $ifIndex],
            [
                'ifName'             => $ifMeta['ifName'],
                'ifDescr'            => $ifMeta['ifDescr'],
                'ifAlias'            => $ifMeta['ifAlias'],
                'ifType'             => 'ethernetCsmacd',
                'ifSpeed'            => $ifMeta['speed'],
                'ifOperStatus'       => 'up',
                'ifAdminStatus'      => 'up',
                'ifDuplex'           => 'fullDuplex',
                'ifMtu'              => 1500,
                'ifConnectorPresent' => 'true',
                'ignore'             => false,
                'disabled'           => false,
                'deleted'            => false,
            ]
        );

        [$lastCounters, $rates] = seedPortRrd($device, $port, $rrdDefinition, $rra, $start, $now, $step, $deviceIndex, $interface, $ifMeta);
        collectVmSamples($vmLines, $device, $port, $start, $now, $step, $deviceIndex, $interface, $ifMeta);
        flushVmSamples($vmLines, false);

        $port->forceFill([
            'ifInOctets'         => $lastCounters['inOctets'],
            'ifOutOctets'        => $lastCounters['outOctets'],
            'ifInUcastPkts'      => $lastCounters['inPackets'],
            'ifOutUcastPkts'     => $lastCounters['outPackets'],
            'ifInErrors'         => $lastCounters['inErrors'],
            'ifOutErrors'        => $lastCounters['outErrors'],
            'ifInOctets_rate'    => (int) ($rates['inBits'] / 8),
            'ifOutOctets_rate'   => (int) ($rates['outBits'] / 8),
            'ifInUcastPkts_rate' => $rates['inPackets'],
            'ifOutUcastPkts_rate' => $rates['outPackets'],
            'ifInErrors_rate'    => $rates['inErrors'],
            'ifOutErrors_rate'   => $rates['outErrors'],
            'poll_time'          => $now,
            'poll_prev'          => $now - $step,
            'poll_period'        => $step,
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

// ── Interface metadata ────────────────────────────────────────────────────────

/**
 * Returns interface name, description, alias, link speed, and traffic profile
 * for a given device style and interface index.
 *
 * Profile values:
 *   uplink  — high-util, balanced in/out (aggregate distribution uplink)
 *   access  — moderate util, out-heavy (switch pushes content to end-users)
 *   server  — moderate util, in-heavy (switch receives data the server sends)
 *   wan     — moderate util, in-heavy (ISP sends downloads to us)
 *   lan     — moderate util, slight in bias
 *   mgmt    — very low util, near-zero traffic
 *   idle    — port is up but carries no meaningful traffic
 */
function interfaceMetadata(string $style, int $ifaceNum, int $ifaceCount): array
{
    switch ($style) {
        case 'catalyst':
            // Last 4 ports are 10G uplinks; everything else is 1G access
            $accessCount = max(1, $ifaceCount - 4);
            if ($ifaceNum <= $accessCount) {
                static $accessAliases = [
                    'office workstation', 'IP phone', 'wireless AP', 'network printer',
                    'IP camera', 'building automation', 'badge reader', 'AV system', '', '',
                ];
                $alias = $accessAliases[($ifaceNum - 1) % count($accessAliases)];
                return [
                    'ifName'  => sprintf('GigabitEthernet1/0/%d', $ifaceNum),
                    'ifDescr' => sprintf('GigabitEthernet1/0/%d', $ifaceNum),
                    'ifAlias' => $alias,
                    'speed'   => 1_000_000_000,
                    'profile' => $alias === '' ? 'idle' : 'access',
                ];
            }
            $uplinkNum = $ifaceNum - $accessCount;
            return [
                'ifName'  => sprintf('TenGigabitEthernet1/1/%d', $uplinkNum),
                'ifDescr' => sprintf('TenGigabitEthernet1/1/%d', $uplinkNum),
                'ifAlias' => $uplinkNum === 1 ? 'Uplink to distribution' : sprintf('Redundant uplink %d', $uplinkNum),
                'speed'   => 10_000_000_000,
                'profile' => 'uplink',
            ];

        case 'isr':
            // First ~20% of ports are GE WAN, remainder are GE LAN
            $wanCount = max(1, (int) ceil($ifaceCount * 0.2));
            if ($ifaceNum <= $wanCount) {
                return [
                    'ifName'  => sprintf('GigabitEthernet0/0/%d', $ifaceNum - 1),
                    'ifDescr' => sprintf('GigabitEthernet0/0/%d', $ifaceNum - 1),
                    'ifAlias' => $ifaceNum === 1 ? 'ISP primary' : 'ISP failover',
                    'speed'   => 1_000_000_000,
                    'profile' => 'wan',
                ];
            }
            $lanNum = $ifaceNum - $wanCount;
            return [
                'ifName'  => sprintf('GigabitEthernet0/1/%d', $lanNum - 1),
                'ifDescr' => sprintf('GigabitEthernet0/1/%d', $lanNum - 1),
                'ifAlias' => sprintf('LAN segment %d', $lanNum),
                'speed'   => 1_000_000_000,
                'profile' => 'lan',
            ];

        case 'linux':
            // Realistic Linux interface names; first is management, rest are data/bond
            static $linuxNames    = ['eth0', 'eth1', 'bond0', 'ens3', 'ens4', 'ens5', 'ens6', 'ens7'];
            static $linuxAliases  = ['management', 'storage', 'data bond', 'vm-traffic', 'vm-traffic', 'backup', '', ''];
            $idx = ($ifaceNum - 1) % count($linuxNames);
            return [
                'ifName'  => $linuxNames[$idx],
                'ifDescr' => $linuxNames[$idx],
                'ifAlias' => $linuxAliases[$idx],
                'speed'   => 10_000_000_000,
                'profile' => $idx === 0 ? 'mgmt' : 'server',
            ];

        case 'junos':
        default:
            // ge-0/0/N access ports, xe-0/1/N uplinks
            $accessCount = max(1, $ifaceCount - 4);
            if ($ifaceNum <= $accessCount) {
                return [
                    'ifName'  => sprintf('ge-0/0/%d', $ifaceNum - 1),
                    'ifDescr' => sprintf('ge-0/0/%d', $ifaceNum - 1),
                    'ifAlias' => '',
                    'speed'   => 1_000_000_000,
                    'profile' => $ifaceNum % 9 === 0 ? 'idle' : 'access',
                ];
            }
            $uplinkNum = $ifaceNum - $accessCount;
            return [
                'ifName'  => sprintf('xe-0/1/%d', $uplinkNum - 1),
                'ifDescr' => sprintf('xe-0/1/%d', $uplinkNum - 1),
                'ifAlias' => 'uplink',
                'speed'   => 10_000_000_000,
                'profile' => 'uplink',
            ];
    }
}

// ── RRD seeding ───────────────────────────────────────────────────────────────

function seedPortRrd(Device $device, Port $port, array $datasets, array $rra, int $start, int $now, int $step, int $deviceIndex, int $interface, array $ifMeta): array
{
    $file = Rrd::name($device->hostname, Rrd::portName($port->port_id));
    $dir  = dirname($file);
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

    $updates    = [];
    $inOctets   = 1_000_000_000 + ($deviceIndex * 100_000_000) + ($interface * 1_000_000);
    $outOctets  = 600_000_000  + ($deviceIndex * 100_000_000) + ($interface * 700_000);
    $inPackets  = 10_000_000  + ($interface * 10_000);
    $outPackets = 9_000_000   + ($interface * 8_000);
    $inErrors   = 0;
    $outErrors  = 0;
    $rates      = [];

    for ($timestamp = $start; $timestamp <= $now; $timestamp += $step) {
        $inBits  = trafficRate($timestamp, $deviceIndex, $interface, 'in',  $ifMeta);
        $outBits = trafficRate($timestamp, $deviceIndex, $interface, 'out', $ifMeta);

        // Packet size varies by interface type (access ports have smaller frames on average)
        $avgPktBytes  = 800 + deterministicInt($interface, $deviceIndex, 77) % 600;
        $inPktRate    = $inBits  > 0 ? max(1, (int) ($inBits  / ($avgPktBytes * 8))) : 0;
        $outPktRate   = $outBits > 0 ? max(1, (int) ($outBits / ($avgPktBytes * 8))) : 0;

        // Real networks rarely have errors — sparse single-sample increments
        $inErrorRate  = deterministicRand($timestamp, $deviceIndex, $interface, 1) < 0.0005 ? 1 : 0;
        $outErrorRate = deterministicRand($timestamp, $deviceIndex, $interface, 2) < 0.0002 ? 1 : 0;

        $inOctets   += (int) (($inBits  / 8) * $step);
        $outOctets  += (int) (($outBits / 8) * $step);
        $inPackets  += $inPktRate  * $step;
        $outPackets += $outPktRate * $step;
        $inErrors   += $inErrorRate;
        $outErrors  += $outErrorRate;

        // Broadcast/multicast are a tiny fraction of unicast traffic
        $bcastIn  = (int) ($inPktRate  * 0.004);
        $mcastIn  = (int) ($inPktRate  * 0.018);
        $bcastOut = (int) ($outPktRate * 0.004);
        $mcastOut = (int) ($outPktRate * 0.018);

        $updates[] = implode(':', [
            $timestamp,
            $inOctets, $outOctets,
            $inErrors, $outErrors,
            $inPackets, $outPackets,
            0, 0,           // nucast (deprecated, always 0)
            0, 0,           // discards
            0,              // unknown protos
            $bcastIn,  $bcastOut,
            $mcastIn,  $mcastOut,
        ]);
        $rates = [
            'inBits'    => $inBits,
            'outBits'   => $outBits,
            'inPackets' => $inPktRate,
            'outPackets' => $outPktRate,
            'inErrors'  => $inErrorRate,
            'outErrors' => $outErrorRate,
        ];

        // Flush in batches to stay well under ARG_MAX (~2MB).
        // Each update arg is ~175 chars; 5000 × 175 ≈ 850KB.
        if (count($updates) >= 5000) {
            runProcess(['rrdtool', 'update', $file, ...$updates]);
            $updates = [];
        }
    }

    if ($updates !== []) {
        runProcess(['rrdtool', 'update', $file, ...$updates]);
    }

    return [[
        'inOctets'   => $inOctets,
        'outOctets'  => $outOctets,
        'inPackets'  => $inPackets,
        'outPackets' => $outPackets,
        'inErrors'   => $inErrors,
        'outErrors'  => $outErrors,
    ], $rates];
}

// ── VictoriaMetrics sample collection ─────────────────────────────────────────

function collectVmSamples(array &$lines, Device $device, Port $port, int $start, int $now, int $step, int $deviceIndex, int $interface, array $ifMeta): void
{
    $labels = [
        'device_id'   => (string) $device->device_id,
        'entity_type' => 'port',
        'hostname'    => (string) $device->hostname,
        'ifName'      => (string) $port->ifName,
        'port_id'     => (string) $port->port_id,
        'source'      => 'librenms',
    ];

    for ($timestamp = $start; $timestamp <= $now; $timestamp += $step) {
        $lines[] = vmLine('librenms_port_if_in_bits_per_second',  $labels, trafficRate($timestamp, $deviceIndex, $interface, 'in',  $ifMeta), $timestamp);
        $lines[] = vmLine('librenms_port_if_out_bits_per_second', $labels, trafficRate($timestamp, $deviceIndex, $interface, 'out', $ifMeta), $timestamp);
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

    $body     = implode("\n", $lines) . "\n";
    $lines    = [];
    $response = Http::timeout(10)->withBody($body, 'text/plain')->post('http://vmagent:8429/api/v1/import/prometheus');
    if ($response->failed()) {
        fwrite(STDERR, "VictoriaMetrics import returned HTTP {$response->status()}\n");
    }
}

// ── Traffic rate model ────────────────────────────────────────────────────────

/**
 * Returns a realistic bit-rate for one direction on one port at a given Unix timestamp.
 *
 * Direction convention (from the device port's perspective):
 *   'in'  = bytes the device receives on this port
 *   'out' = bytes the device transmits on this port
 *
 * Direction ratios by profile:
 *   uplink  — balanced (aggregate link; both directions carry significant traffic)
 *   access  — out-heavy: switch pushes content downloads to end-users
 *   server  — in-heavy: switch receives the large payloads the server sends
 *   wan     — in-heavy: ISP sends more to us than we upload
 *   lan     — slight in bias (internal LAN, mostly download-flavoured)
 *   mgmt    — near-zero, balanced (management pings, SSH, SNMP)
 *   idle    — zero traffic
 */
function trafficRate(int $timestamp, int $deviceIndex, int $interface, string $direction, array $ifMeta): int
{
    $profile   = $ifMeta['profile'];
    $linkSpeed = $ifMeta['speed'];

    if ($profile === 'idle') {
        return 0;
    }

    if ($profile === 'mgmt') {
        $base = (int) ($linkSpeed * 0.0008);
        return (int) ($base * (0.6 + 0.4 * sin($timestamp / 300.0 + $interface)));
    }

    // ── Diurnal envelope (UTC) ────────────────────────────────────────────────
    // Business hours 07:00–20:00: traffic peaks ~10:00 and again ~14:00.
    // Off-hours:                  8–20% of daytime peak.
    $hourOfDay = ($timestamp % 86400) / 3600.0;
    if ($hourOfDay >= 7.0 && $hourOfDay < 20.0) {
        $t       = ($hourOfDay - 7.0) / 13.0;            // 0..1 across business window
        $diurnal = 0.25 + 0.75 * sin(M_PI * $t)
                        * (0.85 + 0.15 * cos(2 * M_PI * ($t - 0.35)));
    } else {
        $nightT  = $hourOfDay >= 20.0
            ? ($hourOfDay - 20.0) / 11.0
            : ($hourOfDay + 4.0)  / 11.0;
        $diurnal = 0.08 + 0.12 * sin(M_PI * $nightT);
    }

    // ── Base utilisation by profile ───────────────────────────────────────────
    $baseUtil = match ($profile) {
        'uplink' => 0.40,
        'wan'    => 0.28,
        'lan'    => 0.42,
        'access' => 0.14,
        'server' => 0.32,
        default  => 0.18,
    };

    // Per-interface variance: each port has its own "busy-ness" multiplier
    // so a 48-port switch doesn't have every port at identical utilisation.
    $ifVariance    = 0.4 + 1.2 * deterministicRand($interface, $deviceIndex, 0xA5);
    $effectiveUtil = min(0.90, $baseUtil * $ifVariance);

    // ── Multi-scale time variation ────────────────────────────────────────────
    // ~18-min wave: gradual traffic swells (meeting starts, batch job runs)
    $wave18  = 0.80 + 0.12 * sin($timestamp / 1100.0 + $interface * 1.73 + $deviceIndex * 2.31)
                    + 0.08 * cos($timestamp / 1800.0 + $interface * 0.97 + $deviceIndex * 1.57);
    // ~5-min noise: short bursts of activity
    $wave5   = 0.92 + 0.08 * sin($timestamp / 300.0 + $interface * 3.14 + $deviceIndex * 0.91);

    // ── Sustained traffic events (file transfers, backups, video calls) ───────
    // Each 10-minute window gets a deterministic "is there an event?" decision.
    // Events raise traffic 50–130% for the whole window, simulating a sustained
    // transfer rather than an instantaneous spike.
    $window     = (int) floor($timestamp / 600);
    $eventRand  = deterministicRand($window, $interface, $deviceIndex, 0xBEEF);
    $eventMag   = deterministicRand($window, $interface, $deviceIndex, 0xCAFE);
    $eventMult  = $eventRand < 0.07 ? (1.5 + $eventMag * 1.3) : 1.0;

    $baseRate = $linkSpeed * $effectiveUtil * $diurnal * $wave18 * $wave5 * $eventMult;

    // ── Direction asymmetry ───────────────────────────────────────────────────
    // [in-factor, out-factor] relative to $baseRate
    [$inFactor, $outFactor] = match ($profile) {
        'uplink' => [1.00, 0.75],   // aggregate link; more download arrives than upload departs
        'access' => [0.28, 1.00],   // switch pushes large downloads out to end-user
        'server' => [1.00, 0.28],   // switch receives large payloads the server sends
        'wan'    => [1.00, 0.35],   // ISP pushes downloads to us
        'lan'    => [1.00, 0.80],   // slight download bias on internal LAN
        default  => [0.90, 0.90],
    };

    $rate = $direction === 'in'
        ? $baseRate * $inFactor
        : $baseRate * $outFactor;

    return (int) min($rate, $linkSpeed * 0.95);
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/** Deterministic pseudo-random float in [0, 1) from integer seeds. */
function deterministicRand(int ...$seeds): float
{
    $h = abs(crc32(implode('|', $seeds)));
    return ($h % 1_000_000) / 1_000_000.0;
}

/** Deterministic pseudo-random non-negative integer from integer seeds. */
function deterministicInt(int ...$seeds): int
{
    return abs(crc32(implode('|', $seeds)));
}

function vmLine(string $metric, array $labels, int $value, int $timestamp): string
{
    ksort($labels);
    $pairs = [];
    foreach ($labels as $key => $label) {
        $pairs[] = $key . '="' . str_replace(['\\', "\n", '"'], ['\\\\', '\\n', '\\"'], $label) . '"';
    }

    return sprintf('%s{%s} %d %d', $metric, implode(',', $pairs), $value, $timestamp * 1000);
}

function runProcess(array $command): void
{
    $process = new Process($command);
    $process->setTimeout(120);
    $process->mustRun();
}
