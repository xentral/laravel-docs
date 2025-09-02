<?php declare(strict_types=1);

namespace Workbench\App\Http\Controller;

use Illuminate\Http\JsonResponse;
use Workbench\App\Models\TestModel;

class TestController
{
    public function index(): JsonResponse
    {
        $testModels = TestModel::all();

        return response()->json([
            'data' => $testModels->map(function (TestModel $model) {
                return [
                    'id' => $model->id,
                    'name' => $model->name,
                    'slug' => $model->slug,
                    'created_at' => $model->created_at?->toISOString(),
                    'updated_at' => $model->updated_at?->toISOString(),
                ];
            })->values(),
        ]);
    }
}
