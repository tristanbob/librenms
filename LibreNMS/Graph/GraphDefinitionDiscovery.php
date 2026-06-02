<?php

/**
 * GraphDefinitionDiscovery.php
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

namespace LibreNMS\Graph;

use ReflectionClass;

/**
 * Auto-discovers graph definitions under LibreNMS\Graph\Definitions and registers them,
 * so GraphServiceProvider no longer hand-lists every class. Three shapes are recognised:
 *
 *   - classes implementing {@see ProvidesGraphDefinitions} (declarative catalogs)
 *   - instantiable {@see GraphDefinition} classes with a zero-argument constructor
 *     (standalone graphs; parameterised template/resolver-produced graphs are skipped)
 *   - instantiable {@see GraphDefinitionResolver} classes with a zero-argument constructor
 */
final class GraphDefinitionDiscovery
{
    private const NAMESPACE_PREFIX = 'LibreNMS\\Graph\\Definitions\\';

    public static function register(GraphDefinitionRegistry $registry, ?string $baseDir = null): void
    {
        foreach (self::classes($baseDir) as $class) {
            $reflection = new ReflectionClass($class);
            if (! $reflection->isInstantiable()) {
                continue;
            }

            if ($reflection->implementsInterface(ProvidesGraphDefinitions::class)) {
                foreach ($class::definitions() as $definition) {
                    $registry->register($definition);
                }

                continue;
            }

            $constructor = $reflection->getConstructor();
            if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
                continue; // parameterised template / resolver-produced graph
            }

            if ($reflection->implementsInterface(GraphDefinition::class)) {
                $registry->register($class);
            } elseif ($reflection->implementsInterface(GraphDefinitionResolver::class)) {
                $registry->registerResolver($reflection->newInstance());
            }
        }
    }

    /**
     * @return list<class-string>
     */
    private static function classes(?string $baseDir): array
    {
        $baseDir = rtrim($baseDir ?? base_path('LibreNMS/Graph/Definitions'), '/');
        if (! is_dir($baseDir)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($baseDir, \FilesystemIterator::SKIP_DOTS)
        );

        $classes = [];
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($baseDir) + 1, -4);
            $class = self::NAMESPACE_PREFIX . str_replace('/', '\\', $relative);
            if (class_exists($class)) {
                $classes[] = $class;
            }
        }

        sort($classes); // deterministic order regardless of filesystem traversal

        return $classes;
    }
}
