<?php

namespace App\Http\Controllers;

use App\Models\ApiRun;
use App\Models\SyncRun;
use Illuminate\Http\JsonResponse;

class OperationController extends Controller
{
    public function show(string $id): JsonResponse
    {
        $operation = SyncRun::query()->find($id);
        if ($operation === null) {
            $generation = ApiRun::query()->where('type', ApiRun::WALLPAPER_GENERATION)->find($id);
            abort_if($generation === null, 404);

            return response()->json($generation->operation());
        }

        return response()->json($operation);
    }
}
