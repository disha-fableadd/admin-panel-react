<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class StatusController extends Controller
{
    /**
     * Update the status of a specific model entity.
     */
    public function updateStatus(Request $request)
    {
        $request->validate([
            'model' => 'required|string',
            'id' => 'required|integer',
            'status' => 'required|string',
        ]);

        $modelClass = $this->getModelClass($request->model);

        if (!$modelClass) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid model specified.',
            ], 400);
        }

        $entity = $modelClass::find($request->id);

        if (!$entity) {
            return response()->json([
                'success' => false,
                'message' => 'Record not found.',
            ], 404);
        }

        $entity->status = $request->status;
        $entity->save();

        return response()->json([
            'success' => true,
            'message' => 'Status updated successfully.',
            'data' => $entity
        ], 200);
    }

    private function getModelClass($modelName)
    {
        $map = [
            'staff' => \App\Models\User::class,
            'client' => \App\Models\Client::class,
            'membership' => \App\Models\Membership::class,
            'transaction' => \App\Models\Transaction::class,
            'role' => \App\Models\Role::class,
            'module' => \App\Models\Module::class,
            'project-module' => \App\Models\ProjectModule::class,
            'project_module' => \App\Models\ProjectModule::class,
            // Plural support
            'clients' => \App\Models\Client::class,
            'memberships' => \App\Models\Membership::class,
            'transactions' => \App\Models\Transaction::class,
            'roles' => \App\Models\Role::class,
            'modules' => \App\Models\Module::class,
            'project-modules' => \App\Models\ProjectModule::class,
        ];

        return $map[strtolower($modelName)] ?? null;
    }
}
