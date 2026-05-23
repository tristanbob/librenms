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
    private const DEFAULT_WRITE_URL = 'http://127.0.0.1:8429/api/v1/import/prometheus';
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
        $this->writeUrl = LibrenmsConfig::get('victoriametrics.write_url', self::DEFAULT_WRITE_URL);
        $this->batchSize = (int) LibrenmsConfig::get('victoriametrics.batch_size', 500);
        $this->timeout = (float) LibrenmsConfig::get('victoriametrics.timeout', 2.0);
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
}
