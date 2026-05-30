<?php

/**
 * ProvidesGraphDefinitions.php
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

/**
 * Marker for catalog classes that contribute a group of declarative graph rows.
 *
 * Implementing this interface opts the class into auto-discovery: the
 * {@see GraphDefinitionDiscovery} scanner registers everything returned by
 * definitions() so new graph families no longer have to be hand-listed in
 * GraphServiceProvider.
 */
interface ProvidesGraphDefinitions
{
    /**
     * @return iterable<GraphDefinition>
     */
    public static function definitions(): iterable;
}
