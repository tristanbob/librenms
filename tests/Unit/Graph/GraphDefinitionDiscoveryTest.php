<?php

/**
 * GraphDefinitionDiscoveryTest.php
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
 * @copyright  2026 Tristan Rhodes
 * @author     Tristan Rhodes <tristan.rhodes@gmail.com>
 */

namespace LibreNMS\Tests\Unit\Graph;

use LibreNMS\Graph\GraphDefinitionDiscovery;
use LibreNMS\Graph\GraphDefinitionRegistry;
use LibreNMS\Tests\TestCase;

final class GraphDefinitionDiscoveryTest extends TestCase
{
    private function discoveredRegistry(): GraphDefinitionRegistry
    {
        $registry = new GraphDefinitionRegistry();
        GraphDefinitionDiscovery::register($registry);

        return $registry;
    }

    public function testDiscoversStandaloneDefinitions(): void
    {
        $registry = $this->discoveredRegistry();

        foreach ([
            'device_bits',
            'device_icmp_perf',
            'device_processor',
            'device_mempool',
            'device_storage',
            'device_diskio',
            'device_poller_perf',
            'device_poller_modules_perf',
            'processor_usage',
            'mempool_usage',
            'storage_usage',
            'toner_usage',
        ] as $type) {
            $this->assertTrue($registry->supports($type), "$type should be auto-discovered");
        }
    }

    public function testDiscoversDeclarativeCatalogDefinitions(): void
    {
        $registry = $this->discoveredRegistry();

        foreach ([
            'device_availability',
            'device_hr_processes',
            'device_uptime',
            'device_netstat_icmp',
            'device_netstat_ip',
            'device_ucd_io',
            'device_ucd_load',
            'device_ipsystemstats_ipv4',
            'port_bits',
            'port_packets',
        ] as $type) {
            $this->assertTrue($registry->supports($type), "$type should be auto-discovered from a catalog");
        }
    }

    public function testDiscoversResolverBackedFamilies(): void
    {
        $registry = $this->discoveredRegistry();

        $this->assertTrue($registry->supports('sensor_temperature'));
        $this->assertTrue($registry->supports('device_wireless_clients'));
        $this->assertTrue($registry->supports('wireless_rssi'));
    }

    public function testDiscoveryDoesNotRegisterParameterisedTemplates(): void
    {
        $registry = $this->discoveredRegistry();

        // Template/resolver-produced classes require constructor args and must be skipped.
        $this->assertFalse($registry->supports('sensor_'));
        $this->assertFalse($registry->supports(''));
    }

    /**
     * "Dead simple" acceptance criterion: a declarative row names a catalog metric key
     * and presentation only. The backend binding kind (gauge vs counter-rate) is derived
     * from the MetricCatalog, so no row may carry a vm_kind / vmKind backend token.
     */
    public function testNoDefinitionRowCarriesABackendKindToken(): void
    {
        $baseDir = base_path('LibreNMS/Graph/Definitions');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($baseDir, \FilesystemIterator::SKIP_DOTS)
        );

        $offenders = [];
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());
            if (preg_match('/[\'"]vm_kind[\'"]|\bvmKind\b/', $contents)) {
                $offenders[] = $file->getPathname();
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These definition files still specify a backend kind token; derive it from the MetricCatalog instead:\n" .
            implode("\n", $offenders)
        );
    }
}
