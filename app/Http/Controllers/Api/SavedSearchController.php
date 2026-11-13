<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SavedSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SavedSearchController extends Controller
{
    public const MAX_PER_USER = 10;

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $request->user()->savedSearches()->latest('id')->get(['id', 'name', 'filters', 'last_alerted_at', 'created_at']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->account_status === 'active' && $user->role !== 'admin', 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'filters' => ['required', 'array'],
            'filters.search' => ['nullable', 'string', 'max:120'],
            'filters.property_type' => ['nullable', 'string', 'max:80'],
            'filters.listing_type' => ['nullable', 'string', 'max:40'],
            'filters.barangay' => ['nullable', 'string', 'max:120'],
            'filters.min_price' => ['nullable', 'numeric', 'min:0'],
            'filters.max_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $filters = array_filter(
            array_intersect_key($data['filters'], array_flip(SavedSearch::FILTER_KEYS)),
            fn ($value) => $value !== null && $value !== ''
        );

        if ($filters === []) {
            return response()->json([
                'message' => 'Choose at least one filter before saving a search.',
                'errors' => ['filters' => ['Choose at least one filter before saving a search.']],
            ], 422);
        }

        if ($user->savedSearches()->count() >= self::MAX_PER_USER) {
            return response()->json([
                'message' => 'You can keep up to '.self::MAX_PER_USER.' saved searches. Remove one to add another.',
            ], 422);
        }

        $search = $user->savedSearches()->create(['name' => $data['name'], 'filters' => $filters]);

        return response()->json(['data' => $search->only(['id', 'name', 'filters', 'last_alerted_at', 'created_at'])], 201);
    }

    public function destroy(Request $request, SavedSearch $savedSearch): JsonResponse
    {
        abort_unless($savedSearch->user_id === $request->user()->id, 404);
        $savedSearch->delete();

        return response()->json(['message' => 'Saved search removed.']);
    }
}
