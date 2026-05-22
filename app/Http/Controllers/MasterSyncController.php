<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class MasterSyncController extends Controller
{
      protected $masterSyncService;

    public function __construct(MasterDataSyncService $masterSyncService)
    {
        $this->masterSyncService = $masterSyncService;
    }

    public function sync()
    {
        $result = $this->masterSyncService->syncMasterData();

        return response()->json($result);
    }
}
