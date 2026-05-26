<?php

namespace LibreNMS\Graph\Definitions\Device;

use LibreNMS\Graph\GraphDefinition;

class DeviceGraphCatalog
{
    /**
     * @return GraphDefinition[]
     */
    public static function definitions(): array
    {
        return [
            ...DeviceStatsGraphCatalog::definitions(),
            ...DeviceNetstatGraphCatalog::definitions(),
            ...DeviceIpSystemStatsGraphCatalog::definitions(),
            ...DeviceUcdGraphCatalog::definitions(),
        ];
    }
}
