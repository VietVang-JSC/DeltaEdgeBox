<?php

namespace App\Http\Controllers;
use App\Services\MasterDataSyncService;

use Illuminate\Http\Request;
use App\Models\SyncMetadata;

class MasterSyncController extends Controller
{
    protected MasterDataSyncService $masterSyncService;

    public function __construct(MasterDataSyncService $masterSyncService)
    {
        $this->masterSyncService = $masterSyncService;
    }

    public function sync(Request $request)
    {
        $result = $this->masterSyncService->syncMasterData($request->boolean('force', false));

        return response()->json($result, ($result['success'] ?? false) ? 200 : 500);
    }

    public function lastMasterSync()
    {
        $metadata = SyncMetadata::where('store_id', config('edge_box.store_id'))->first();

        return response()->json([
            'success' => true,
            'timestamp' => $metadata?->last_sync_timestamp?->toIso8601String(),
            'sync_status' => $metadata?->sync_status,
            'last_error' => $metadata?->last_error,
        ]);
    }
}
