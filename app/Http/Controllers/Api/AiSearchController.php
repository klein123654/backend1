<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AiListingSearch;
use App\Services\DeepSeekService;
use Illuminate\Http\Request;

class AiSearchController extends Controller
{
    /**
     * Interpret a natural-language query and return matching public listings.
     */
    public function __invoke(Request $request, DeepSeekService $deepSeek, AiListingSearch $listings): mixed
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'min:2', 'max:500'],
        ]);

        $result = $deepSeek->search($validated['query'], fn (array $filters): array => $listings->find($filters));

        if (! $result['success']) {
            return response()->json($result, 200);
        }

        return response()->json($result);
    }
}
