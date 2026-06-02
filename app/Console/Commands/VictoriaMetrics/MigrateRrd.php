<?php

namespace App\Console\Commands\VictoriaMetrics;

use App\Console\LnmsCommand;
use App\Facades\LibrenmsConfig;
use App\Facades\Rrd;
use App\Models\Device;
use App\Models\Port;
use Illuminate\Http\Client\ConnectionException;
use LibreNMS\Data\Store\VictoriaMetrics as VmStore;
use LibreNMS\Data\Store\VictoriaMetrics\LabelExtractor;
use LibreNMS\Data\Store\VictoriaMetrics\PrometheusTextFormatter;
use LibreNMS\Data\Store\VictoriaMetrics\RrdMigrationMapper;
use LibreNMS\Graph\RrdGraphDataProvider;
use LibreNMS\Util\Http;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Process\Process;

class MigrateRrd extends LnmsCommand
{
    protected $name = 'victoriametrics:migrate-rrd';

    private array  $batch = [];
    private int    $batchSize = 10000;
    private int    $totalSamples = 0;
    private int    $batchesSent = 0;
    private int    $failedBatches = 0;
    private string $writeUrl = '';
    private float  $timeout = 30.0;
    private int    $resolution = 300;
    private ?string $start = null;
    private ?string $end = null;

    public function __construct()
    {
        parent::__construct();

        $this->addOption('device', null, InputOption::VALUE_OPTIONAL, '', 'all');
        $this->addOption('start', null, InputOption::VALUE_OPTIONAL);
        $this->addOption('end', null, InputOption::VALUE_OPTIONAL);
        $this->addOption('resolution', null, InputOption::VALUE_OPTIONAL, '', '300');
        $this->addOption('counters', null, InputOption::VALUE_NONE);
        $this->addOption('url', null, InputOption::VALUE_OPTIONAL);
        $this->addOption('batch-size', null, InputOption::VALUE_OPTIONAL, '', '10000');
        $this->addOption('timeout', null, InputOption::VALUE_OPTIONAL, '', '30');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE);
    }

    public function handle(): int
    {
        $this->batchSize = max(1, (int) $this->option('batch-size'));
        $this->timeout = max(1.0, (float) $this->option('timeout'));
        $this->resolution = max(1, (int) $this->option('resolution'));
        $this->start = $this->option('start') ?: null;
        $this->end = $this->option('end') ?: null;

        $this->writeUrl = $this->option('url') ?: VmStore::resolveWriteUrl(
            'direct',
            LibrenmsConfig::get('victoriametrics.write_host', '127.0.0.1'),
            LibrenmsConfig::get('victoriametrics.write_port', 8428),
            LibrenmsConfig::get('victoriametrics.write_path', ''),
            ''
        );

        $this->info(__('commands.victoriametrics:migrate-rrd.starting', ['url' => $this->writeUrl]));

        if ($this->option('dry-run')) {
            $this->warn(__('commands.victoriametrics:migrate-rrd.dry_run'));
        }

        if ($this->option('counters')) {
            $this->warn(__('commands.victoriametrics:migrate-rrd.counters_note'));
        }

        $devices = Device::whereDeviceSpec($this->option('device'))->get();

        if ($devices->isEmpty()) {
            $this->warn(__('commands.victoriametrics:migrate-rrd.no_devices'));

            return 0;
        }

        foreach ($devices as $device) {
            $this->line(__('commands.victoriametrics:migrate-rrd.device', ['hostname' => $device->hostname]));
            $this->migratePollerPerf($device);
            $this->migratePorts($device);
        }

        $this->flush(force: true);

        $this->info(__('commands.victoriametrics:migrate-rrd.complete', [
            'samples' => $this->totalSamples,
            'batches' => $this->batchesSent,
            'failed'  => $this->failedBatches,
        ]));

        return $this->failedBatches > 0 ? 1 : 0;
    }

    private function migratePollerPerf(Device $device): void
    {
        $rrdFile = Rrd::name($device->hostname, 'poller-perf');

        if (! $this->rrdFileExists($rrdFile)) {
            return;
        }

        $allData = $this->fetchRrd($rrdFile);
        $ds = RrdMigrationMapper::pollerPerfDs();
        $samples = $allData[$ds] ?? [];

        if (empty($samples)) {
            return;
        }

        $metric = RrdMigrationMapper::pollerPerfMetric();
        $labels = LabelExtractor::extract($device, 'poller-perf', []);

        foreach ($samples as [$tsMs, $value]) {
            if ($value === null || ! is_finite($value)) {
                continue;
            }

            $this->queueLine(PrometheusTextFormatter::format($metric, $labels, $value, $tsMs));
        }
    }

    private function migratePorts(Device $device): void
    {
        $ports = $device->ports()->where('deleted', 0)->get(['port_id', 'ifIndex', 'ifName']);

        foreach ($ports as $port) {
            $this->migratePort($device, $port);
        }
    }

    private function migratePort(Device $device, Port $port): void
    {
        $rrdFile = Rrd::name($device->hostname, Rrd::portName($port->port_id));

        if (! $this->rrdFileExists($rrdFile)) {
            return;
        }

        $allData = $this->fetchRrd($rrdFile);
        $labels = LabelExtractor::extract($device, 'ports', [
            'ifIndex' => $port->ifIndex,
            'ifName'  => $port->ifName,
        ]);

        foreach (RrdMigrationMapper::gaugeMappings() as $ds => [$metric, $transform]) {
            foreach ($allData[$ds] ?? [] as [$tsMs, $value]) {
                if ($value === null || ! is_finite($value)) {
                    continue;
                }

                $this->queueLine(PrometheusTextFormatter::format($metric, $labels, $transform($value), $tsMs));
            }
        }

        if ($this->option('counters')) {
            foreach (RrdMigrationMapper::counterMappings() as $ds => [$metric]) {
                $synthetic = RrdMigrationMapper::synthesizeCounter($allData[$ds] ?? [], $this->resolution);

                foreach ($synthetic as [$tsMs, $cumulative]) {
                    $this->queueLine(PrometheusTextFormatter::format($metric, $labels, $cumulative, $tsMs));
                }
            }
        }

        unset($allData);
    }

    /**
     * Returns true when the RRD file exists on disk.
     * Protected so tests can override without touching the filesystem.
     */
    protected function rrdFileExists(string $path): bool
    {
        return file_exists($path);
    }

    /**
     * Fetch all DS data from one RRD file via rrdtool fetch AVERAGE.
     *
     * Uses Rrd::buildCommand() so that rrdcached --daemon injection is handled
     * automatically when rrdcached is configured. Protected so tests can override
     * with controlled data without requiring a real rrdtool binary.
     *
     * @return array<string, list<array{int, float|null}>>
     */
    protected function fetchRrd(string $rrdFile): array
    {
        $fetchOptions = ['AVERAGE'];

        if ($this->start !== null) {
            $fetchOptions[] = '--start';
            $fetchOptions[] = $this->start;
        }

        if ($this->end !== null) {
            $fetchOptions[] = '--end';
            $fetchOptions[] = $this->end;
        }

        $fetchOptions[] = '--resolution';
        $fetchOptions[] = (string) $this->resolution;

        $command = Rrd::buildCommand('fetch', $rrdFile, $fetchOptions);
        $rrdtool = LibrenmsConfig::get('rrdtool', 'rrdtool');
        $rrdDir = LibrenmsConfig::get('rrd_dir', LibrenmsConfig::get('install_dir') . '/rrd');

        $proc = new Process(array_merge([$rrdtool], $command), $rrdDir);
        $proc->setTimeout(300);
        $proc->run();

        if (! $proc->isSuccessful()) {
            $this->warn("rrdtool fetch failed for {$rrdFile}: " . trim($proc->getErrorOutput()));

            return [];
        }

        return RrdGraphDataProvider::parseRrdFetchOutput($proc->getOutput());
    }

    private function queueLine(string $line): void
    {
        $this->batch[] = $line;
        $this->totalSamples++;

        if (count($this->batch) >= $this->batchSize) {
            $this->flush();
        }
    }

    private function flush(bool $force = false): void
    {
        if (! $force && count($this->batch) < $this->batchSize) {
            return;
        }

        if (empty($this->batch)) {
            return;
        }

        if ($this->option('dry-run')) {
            $this->batch = [];

            return;
        }

        $body = implode("\n", $this->batch) . "\n";
        $this->batch = [];

        try {
            $response = Http::client()
                ->timeout($this->timeout)
                ->withBody($body, 'text/plain')
                ->post($this->writeUrl);

            if ($response->failed()) {
                $this->warn("VictoriaMetrics POST failed: HTTP {$response->status()} — " . substr($response->body(), 0, 200));
                $this->failedBatches++;
            } else {
                $this->batchesSent++;
            }
        } catch (ConnectionException $e) {
            $this->error('VictoriaMetrics connection failed: ' . $e->getMessage());
            $this->failedBatches++;
        }
    }
}
