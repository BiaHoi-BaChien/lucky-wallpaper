<?php

namespace App\Http\Controllers;

use App\Models\ApiRun;
use App\Models\SyncRun;
use Illuminate\Http\JsonResponse;

class OperationController extends Controller
{
    public function show(string $id): JsonResponse
    {
        $operation = SyncRun::query()->find($id) ?? ApiRun::query()->find($id);
        abort_if($operation === null, 404);

        return response()->json($operation);
    }
}
