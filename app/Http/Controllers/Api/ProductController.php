<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /**
     * Display a listing of products.
     */
    public function __construct()
    {
        $this->middleware('permission:Modules (Products),VIEW')->only(['index', 'show']);
        $this->middleware('permission:Modules (Products),ADD')->only(['store']);
        $this->middleware('permission:Modules (Products),EDIT')->only(['update']);
        $this->middleware('permission:Modules (Products),DELETE')->only(['destroy']);
    }

    public function index(Request $request)
    {
        $query = Product::query();

        if ($request->has('status') && !empty($request->status)) {
            $query->where('status', $request->status);
        }

        if ($request->has('search') && !empty($request->search)) {
            $query->where('title', 'like', '%' . $request->search . '%');
        }

        if ($request->boolean('with_modules')) {
            $query->with('projectModules');
        }

        $products = $query->latest()->get();

        return response()->json([
            'success' => true,
            'data' => $products
        ], 200);
    }

    /**
     * Store a newly created product in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255|unique:products,title',
            'description' => 'nullable|string',
            'status' => 'nullable|in:Active,Inactive',
        ], [
            'title.unique' => 'A product with this name already exists.'
        ]);

        if (!isset($validated['status'])) {
            $validated['status'] = 'Active';
        }

        $product = Product::create($validated);

        \App\Models\Project::create([
            'product_id' => $product->id,
            'name' => $product->title,
            'description' => $product->description,
            'status' => $product->status,
        ]);

        $this->notifyAllUsers('New Product Created', 'A new product was added.');

        return response()->json([
            'success' => true,
            'message' => 'Product created successfully.',
            'data' => $product
        ], 201);
    }

    /**
     * Display the specified product.
     */
    public function show($id)
    {
        $product = Product::with('projectModules')->find($id);

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $product
        ], 200);
    }

    /**
     * Update the specified product in storage.
     */
    public function update(Request $request, $id)
    {
        $product = Product::find($id);

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found.'
            ], 404);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255|unique:products,title,' . $id,
            'description' => 'nullable|string',
            'status' => 'nullable|in:Active,Inactive',
        ], [
            'title.unique' => 'A product with this name already exists.'
        ]);

        $product->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Product updated successfully.',
            'data' => $product
        ], 200);
    }

    /**
     * Remove the specified product from storage.
     */
    public function destroy($id)
    {
        $product = Product::find($id);

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found.'
            ], 404);
        }

        $hasProjects = \App\Models\Project::where('product_id', $id)->exists();
        $hasModules = \App\Models\ProjectModule::where('product_id', $id)->exists();
        $hasMemberships = \App\Models\Membership::where('product_id', $id)->exists();
        $hasClients = \App\Models\Client::where('product_id', $id)->exists();

        if ($hasProjects || $hasModules || $hasMemberships || $hasClients) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete this product because it is assigned to existing projects, modules, memberships, or clients. Please delete them first.'
            ], 400);
        }

        $product->delete();

        return response()->json([
            'success' => true,
            'message' => 'Product deleted successfully.'
        ], 200);
    }
}
