<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Graph\RrdGraphDataProvider;

class DeviceGraphDataController extends Controller
{
    public function __invoke(Request $request, string $hostname, string $graphType): JsonResponse
    {
        $device = Device::findByHostname($hostname);

        if (! $device) {
            return response()->json(['status' => 'error', 'message' => 'Device not found'], 404);
        }

        $this->authorize('view', $device);

        $from = (int) $request->input('from', time() - 86400);
        $to = (int) $request->input('to', time());
        $width = (int) $request->input('width', 1200);
        $height = (int) $request->input('height', 300);

        try {
            $query = GraphQuery::fromRequest('device', $graphType, [
                'device_id' => $device->device_id,
                'hostname' => $device->hostname,
            ], $from, $to, $width, $height);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }

        try {
            $result = app(RrdGraphDataProvider::class)->query($query);
        } catch (\RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 404);
        }

        $response = response()->json($result->toArray());

        if ($query->to < time() - 60) {
            $response->header('Cache-Control', 'private, max-age=300');
        } else {
            $response->header('Cache-Control', 'no-store');
        }

        return $response;
    }
}
