<?php

/**
 * GraphDefinitionRegistry.php
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

class GraphDefinitionRegistry
{
    /** @var array<string, class-string<GraphDefinition>|GraphDefinition> */
    private array $map = [];

    /** @var GraphDefinitionResolver[] */
    private array $resolvers = [];

    /**
     * @param iterable<class-string<GraphDefinition>|GraphDefinition> $definitions
     */
    public function __construct(iterable $definitions = [])
    {
        foreach ($definitions as $definition) {
            $this->register($definition);
        }
    }

    /**
     * @param class-string<GraphDefinition>|GraphDefinition $definition
     */
    public function register(string|GraphDefinition $definition): void
    {
        $instance = is_string($definition) ? new $definition() : $definition;
        $this->map[$instance->graphType()] = $definition;
    }

    public function registerResolver(GraphDefinitionResolver $resolver): void
    {
        $this->resolvers[] = $resolver;
    }

    /** @throws \RuntimeException if the graph type has no registered definition */
    public function definitionFor(string $graphType): GraphDefinition
    {
        $definition = $this->map[$graphType] ?? null;
        if ($definition !== null) {
            return is_string($definition) ? new $definition() : $definition;
        }

        foreach ($this->resolvers as $resolver) {
            if ($resolver->supports($graphType)) {
                return $resolver->definitionFor($graphType);
            }
        }

        throw new \RuntimeException(
            "Graph type '{$graphType}' is not yet supported by the JSON graph data API."
        );
    }

    public function supports(string $graphType): bool
    {
        if (isset($this->map[$graphType])) {
            return true;
        }
        foreach ($this->resolvers as $resolver) {
            if ($resolver->supports($graphType)) {
                return true;
            }
        }

        return false;
    }
}
