<?php

/**
 * VictoriaMetrics.php
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
 * @copyright  2026 LibreNMS Contributors
 */

namespace LibreNMS\Data\Store;

use App\Polling\Measure\Measurement;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use LibreNMS\Config as LibrenmsConfig;
use LibreNMS\Interfaces\Data\Datastore;
use LibreNMS\Util\Http;
use LibreNMS\Data\Store\VictoriaMetrics\LabelExtractor;
use LibreNMS\Data\Store\VictoriaMetrics\MetricMapper;
use LibreNMS\Data\Store\VictoriaMetrics\PrometheusTextFormatter;

/**
 * Optional dual-write datastore that sends metrics to VictoriaMetrics or
 * vmagent via Prometheus text import. Write-only; graph reads are Stage 5.
 *
 * Metric naming: explicit Prometheus/MetricsQL-compatible metric names.
 * Label scheme: source, device_id, hostname, entity_type, plus entity-specific IDs.
 *
 * Metric mapping contract (updated as graph types are promoted to VM reads):
 *
 * | Graph type           | Measurement  | Field          | VM metric name                              | Type    | Labels             |
 * |----------------------|--------------|----------------|---------------------------------------------|---------|--------------------|
 * | device_poller_perf   | poller-perf  | poller         | librenms_device_poller_duration_seconds     | gauge   | device_id          |
 * | port_bits (in)       | ports        | INOCTETS       | librenms_port_if_in_octets_total            | counter | device_id, port_id |
 * | port_bits (out)      | ports        | OUTOCTETS      | librenms_port_if_out_octets_total           | counter | device_id, port_id |
 * | port_bits (in rate)  | ports        | ifInBits_rate  | librenms_port_if_in_bits_per_second         | gauge   | device_id, port_id |
 * | port_bits (out rate) | ports        | ifOutBits_rate | librenms_port_if_out_bits_per_second        | gauge   | device_id, port_id |
 */
class VictoriaMetrics extends BaseDatastore implements Datastore
{
    private const DEFAULT_WRITE_MODE = 'vmagent';
    private const DEFAULT_SCHEME = 'http';
    private const DEFAULT_VMAGENT_HOST = '127.0.0.1';
    private const DEFAULT_VMAGENT_PORT = 8429;
    private const DEFAULT_DIRECT_HOST = '127.0.0.1';
    private const DEFAULT_DIRECT_PORT = 8428;
    private const DEFAULT_IMPORT_PATH = '/api/v1/import/prometheus';
    private const FAILURE_BACKOFF_SECONDS = 60;

    private array $batch = [];
    private int $batchSize;
    private string $writeUrl;
    private float $timeout;
    private bool $verifySsl;
    private bool $debug;
    private int $disabledUntil = 0;

    public function __construct()
    {
        parent::__construct();
        $this->writeUrl = self::resolveWriteUrl(
            LibrenmsConfig::get('victoriametrics.write_mode', self::DEFAULT_WRITE_MODE),
            LibrenmsConfig::get('victoriametrics.write_host', ''),
            LibrenmsConfig::get('victoriametrics.write_port', ''),
            LibrenmsConfig::get('victoriametrics.write_path', ''),
            LibrenmsConfig::get('victoriametrics.write_url', '')
        );
        $this->batchSize = (int) LibrenmsConfig::get('victoriametrics.batch_size', 500);
        $this->timeout = (float) LibrenmsConfig::get('victoriametrics.timeout', 10.0);
        $this->verifySsl = (bool) LibrenmsConfig::get('victoriametrics.verify_ssl', true);
        $this->debug = (bool) LibrenmsConfig::get('victoriametrics.debug', false);
    }

    public static function isEnabled(): bool
    {
        return (bool) LibrenmsConfig::get('victoriametrics.enable', false);
    }

    public function getName(): string
    {
        return 'VictoriaMetrics';
    }

    public function write(string $measurement, array $fields, array $tags = [], array $meta = []): void
    {
        if ($this->isTemporarilyDisabled()) {
            return;
        }

        $stat = Measurement::start('write');
        $device = $this->getDevice($meta);
        $labels = LabelExtractor::extract($device, $measurement, $tags);
        $timestamp = (int) floor(microtime(true) * 1000);
        $wroteSamples = false;

        foreach ($fields as $field => $value) {
            if (! is_numeric($value) || ! is_finite((float) $value)) {
                continue;
            }

            $metric = MetricMapper::map($measurement, (string) $field);
            if ($metric === null) {
                if ($this->debug) {
                    Log::debug("VictoriaMetrics skipped unmapped metric {$measurement}.{$field}");
                }

                continue;
            }

            $this->batch[] = PrometheusTextFormatter::format($metric, $labels, (float) $value, $timestamp);
            $wroteSamples = true;
        }

        if (count($this->batch) >= $this->batchSize) {
            $this->flush();
        }

        if ($wroteSamples) {
            $this->recordStatistic($stat->end());
        }
    }

    public function terminate(): void
    {
        if (empty($this->batch)) {
            return;
        }

        $body = implode("\n", $this->batch) . "\n";
        $this->batch = [];

        try {
            $response = Http::client()
                ->timeout($this->timeout)
                ->withOptions(['verify' => $this->verifySsl])
                ->withBody($body, 'text/plain')
                ->post($this->writeUrl);

            if ($response->failed()) {
                Log::warning("VictoriaMetrics write failed: HTTP {$response->status()} — {$response->body()}");
            }
        } catch (ConnectionException $e) {
            $this->disabledUntil = time() + self::FAILURE_BACKOFF_SECONDS;
            Log::error('VictoriaMetrics write connection failed, temporarily disabling: ' . $e->getMessage());
        } catch (\Exception $e) {
            Log::error('VictoriaMetrics write exception: ' . $e->getMessage());
        }
    }

    private function flush(): void
    {
        $this->terminate();
    }

    private function isTemporarilyDisabled(): bool
    {
        return $this->disabledUntil > time();
    }

    public static function resolveWriteUrl(?string $mode, ?string $host, mixed $port = null, ?string $path = null, ?string $legacyUrl = null): string
    {
        $mode = in_array($mode, ['vmagent', 'direct', 'custom'], true) ? $mode : self::DEFAULT_WRITE_MODE;
        $host = trim((string) $host);
        $path = trim((string) $path);
        $legacyUrl = trim((string) $legacyUrl);

        if ($host === '' && $path === '' && $legacyUrl !== '') {
            return self::resolveLegacyWriteUrl($mode, $legacyUrl);
        }

        $host = $host === '' ? self::defaultHost($mode) : $host;
        $path = $path === '' ? self::DEFAULT_IMPORT_PATH : self::normalizePath($path);
        $configuredPort = self::normalizePort($port);
        $url = self::ensureScheme($host);

        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? self::DEFAULT_SCHEME;
        $hostname = $parts['host'] ?? $host;
        $configuredPort ??= $parts['port'] ?? self::defaultPort($mode);

        return "{$scheme}://{$hostname}:{$configuredPort}{$path}";
    }

    private static function resolveLegacyWriteUrl(string $mode, string $configuredUrl): string
    {
        $url = self::ensureScheme($configuredUrl);
        $path = parse_url($url, PHP_URL_PATH);
        if ($path === null || $path === '' || $path === '/') {
            return rtrim($url, '/') . self::DEFAULT_IMPORT_PATH;
        }

        return rtrim($url, '/');
    }

    private static function ensureScheme(string $url): string
    {
        return preg_match('#^https?://#i', $url) ? $url : 'http://' . $url;
    }

    private static function normalizePath(string $path): string
    {
        return '/' . ltrim($path, '/');
    }

    private static function normalizePort(mixed $port): ?int
    {
        if ($port === null || $port === '') {
            return null;
        }

        $port = (int) $port;

        return $port > 0 ? $port : null;
    }

    private static function defaultHost(string $mode): string
    {
        return $mode === 'direct' ? self::DEFAULT_DIRECT_HOST : self::DEFAULT_VMAGENT_HOST;
    }

    private static function defaultPort(string $mode): int
    {
        return $mode === 'direct' ? self::DEFAULT_DIRECT_PORT : self::DEFAULT_VMAGENT_PORT;
    }
}
