<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProjectModule;
use Illuminate\Http\Request;

class ProjectModuleController extends Controller
{
    public function index(Request $request)
    {
        // in get api only active modules show
        $query = ProjectModule::query();
        $query->where('status', 'Active');
        
        return response()->json([
            'success' => true,
            'data' => $query->get()
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'product_id' => 'required|integer',
            'status' => 'required|in:Active,Inactive',
            'description' => 'nullable|string',
        ]);

        $projectModule = ProjectModule::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Project Module created successfully.',
            'data' => $projectModule
        ], 201);
    }

    public function show(ProjectModule $projectModule)
    {
        return response()->json([
            'success' => true,
            'data' => $projectModule
        ]);
    }

    public function update(Request $request, ProjectModule $projectModule)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'product_id' => 'required|integer',
            'status' => 'required|in:Active,Inactive',
            'description' => 'nullable|string',
        ]);

        $projectModule->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Project Module updated successfully.',
            'data' => $projectModule
        ]);
    }

    public function destroy(ProjectModule $projectModule)
    {
        $projectModule->delete();

        return response()->json([
            'success' => true,
            'message' => 'Project Module deleted successfully.'
        ]);
    }
}
