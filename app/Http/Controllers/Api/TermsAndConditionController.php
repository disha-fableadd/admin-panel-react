<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TermsAndCondition;
use Illuminate\Http\Request;

class TermsAndConditionController extends Controller
{
    /**
     * Display a listing of terms and conditions.
     */
    public function index(Request $request)
    {
        $query = TermsAndCondition::with(['product', 'project']);

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $terms = $query->latest()->get();

        return response()->json([
            'success' => true,
            'data'    => $terms,
        ], 200);
    }

    /**
     * Helper to normalize input title into an array of strings.
     */
    protected function normalizeTitles($rawTitle): array
    {
        if (is_string($rawTitle)) {
            $decoded = json_decode($rawTitle, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $titles = $decoded;
            } else {
                $titles = [$rawTitle];
            }
        } elseif (is_array($rawTitle)) {
            $titles = $rawTitle;
        } else {
            $titles = [];
        }

        return array_values(array_filter(array_map(function ($item) {
            return is_string($item) ? trim($item) : $item;
        }, $titles), function ($item) {
            return $item !== '' && $item !== null;
        }));
    }

    /**
     * Store or merge terms and conditions.
     * When product_id and project_id are the same, titles are stored in an array.
     */
    public function store(Request $request)
    {
        $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'project_id' => 'nullable|integer|exists:projects,id',
            'title'      => 'required',
            'status'     => 'nullable|string|in:Active,Inactive',
            'replace'    => 'nullable|boolean',
        ]);

        $productId = (int) $request->product_id;
        $projectId = $request->filled('project_id') ? (int) $request->project_id : null;
        $status    = $request->input('status', 'Active');
        $newTitles = $this->normalizeTitles($request->input('title'));

        if (empty($newTitles)) {
            return response()->json([
                'success' => false,
                'message' => 'The title field is required and must contain at least one valid term.',
            ], 422);
        }

        // Check if a record already exists with the same product_id and project_id
        $query = TermsAndCondition::where('product_id', $productId);
        if ($projectId !== null) {
            $query->where('project_id', $projectId);
        } else {
            $query->whereNull('project_id');
        }

        $existing = $query->first();

        if ($existing) {
            // When product_id and project_id is the same: store/merge titles into the array
            if ($request->boolean('replace')) {
                $existing->title = $newTitles;
            } else {
                $currentTitles = is_array($existing->title) ? $existing->title : (!empty($existing->title) ? [$existing->title] : []);
                $existing->title = array_values(array_unique(array_merge($currentTitles, $newTitles)));
            }

            if ($request->has('status')) {
                $existing->status = $status;
            }

            $existing->save();
            $existing->load(['product', 'project']);

            return response()->json([
                'success' => true,
                'message' => 'Terms and conditions for this product and project updated (title stored in array).',
                'data'    => $existing,
            ], 200);
        }

        // If not exists, create new record with titles stored as array
        $term = TermsAndCondition::create([
            'product_id' => $productId,
            'project_id' => $projectId,
            'title'      => $newTitles,
            'status'     => $status,
        ]);

        $term->load(['product', 'project']);

        return response()->json([
            'success' => true,
            'message' => 'Terms and conditions created successfully.',
            'data'    => $term,
        ], 201);
    }

    /**
     * Display the specified terms and conditions record.
     */
    public function show($id)
    {
        $term = ($id instanceof TermsAndCondition) ? $id : TermsAndCondition::find($id);

        if (!$term) {
            return response()->json([
                'success' => false,
                'message' => 'Terms and conditions not found.',
            ], 404);
        }

        $term->load(['product', 'project']);

        return response()->json([
            'success' => true,
            'data'    => $term,
        ], 200);
    }

    /**
     * Update the specified terms and conditions record.
     */
    public function update(Request $request, $id)
    {
        $term = ($id instanceof TermsAndCondition) ? $id : TermsAndCondition::find($id);

        if (!$term) {
            return response()->json([
                'success' => false,
                'message' => 'Terms and conditions not found.',
            ], 404);
        }

        $request->validate([
            'product_id' => 'nullable|integer|exists:products,id',
            'project_id' => 'nullable|integer|exists:projects,id',
            'title'      => 'nullable',
            'status'     => 'nullable|string|in:Active,Inactive',
            'merge'      => 'nullable|boolean',
        ]);

        if ($request->has('product_id')) {
            $term->product_id = $request->product_id;
        }

        if ($request->has('project_id')) {
            $term->project_id = $request->filled('project_id') ? (int) $request->project_id : null;
        }

        if ($request->has('status')) {
            $term->status = $request->status;
        }

        if ($request->has('title')) {
            $newTitles = $this->normalizeTitles($request->input('title'));
            if ($request->boolean('merge')) {
                $currentTitles = is_array($term->title) ? $term->title : (!empty($term->title) ? [$term->title] : []);
                $term->title = array_values(array_unique(array_merge($currentTitles, $newTitles)));
            } else {
                $term->title = $newTitles;
            }
        }

        $term->save();
        $term->load(['product', 'project']);

        return response()->json([
            'success' => true,
            'message' => 'Terms and conditions updated successfully.',
            'data'    => $term,
        ], 200);
    }

    /**
     * Remove a single item from the title array of a terms record.
     */
    public function removeItem(Request $request, $id)
    {
        $term = ($id instanceof TermsAndCondition) ? $id : TermsAndCondition::find($id);

        if (!$term) {
            return response()->json([
                'success' => false,
                'message' => 'Terms and conditions not found.',
            ], 404);
        }

        $request->validate([
            'index' => 'nullable|integer',
            'title' => 'nullable|string',
        ]);

        $currentTitles = is_array($term->title) ? $term->title : [];

        if ($request->has('index') && isset($currentTitles[$request->index])) {
            unset($currentTitles[$request->index]);
            $currentTitles = array_values($currentTitles);
        } elseif ($request->filled('title')) {
            $currentTitles = array_values(array_filter($currentTitles, function ($item) use ($request) {
                return trim($item) !== trim($request->title);
            }));
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Please provide an index or title string to remove.',
            ], 422);
        }

        $term->title = $currentTitles;
        $term->save();
        $term->load(['product', 'project']);

        return response()->json([
            'success' => true,
            'message' => 'Title item removed successfully.',
            'data'    => $term,
        ], 200);
    }

    /**
     * Remove the specified terms and conditions record.
     */
    public function destroy($id)
    {
        $term = ($id instanceof TermsAndCondition) ? $id : TermsAndCondition::find($id);

        if (!$term) {
            return response()->json([
                'success' => false,
                'message' => 'Terms and conditions not found.',
            ], 404);
        }

        $term->delete();

        return response()->json([
            'success' => true,
            'message' => 'Terms and conditions deleted successfully.',
        ], 200);
    }

    /**
     * Public API to get terms and conditions by product_id and project_id.
     */
    public function getByProductAndProject(Request $request)
    {
        $productId = $request->query('product_id', $request->input('product_id'));
        $projectId = $request->query('project_id', $request->input('project_id'));

        $query = TermsAndCondition::where('status', 'Active')->with(['product', 'project']);

        if (!empty($productId)) {
            $query->where('product_id', $productId);
        }

        if (!empty($projectId)) {
            $query->where('project_id', $projectId);
        }

        $terms = $query->get();

        // Extract flattened array of all titles
        $flatTitles = [];
        foreach ($terms as $t) {
            if (is_array($t->title)) {
                $flatTitles = array_merge($flatTitles, $t->title);
            } elseif (!empty($t->title)) {
                $flatTitles[] = $t->title;
            }
        }
        $flatTitles = array_values(array_unique($flatTitles));

        return response()->json([
            'success' => true,
            'data'    => [
                'records' => $terms,
                'titles'  => $flatTitles,
            ],
        ], 200);
    }
}
