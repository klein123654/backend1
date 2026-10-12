<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Property;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SavedPropertyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($this->canSaveProperties($request), 403);
        $properties = Property::query()
            ->with('images')
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->whereIn('id', DB::table('saved_properties')->where('user_id', $request->user()->id)->select('property_id'))
            ->latest('published_at')
            ->get();

        return response()->json(['data' => $properties]);
    }

    public function store(Request $request, Property $property): JsonResponse
    {
        abort_unless($this->canSaveProperties($request), 403);
        abort_unless($property->status === 'published' && $property->published_at, 404);
        DB::table('saved_properties')->insertOrIgnore([
            'user_id' => $request->user()->id,
            'property_id' => $property->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Property saved.']);
    }

    public function destroy(Request $request, Property $property): JsonResponse
    {
        abort_unless($this->canSaveProperties($request), 403);
        DB::table('saved_properties')
            ->where('user_id', $request->user()->id)
            ->where('property_id', $property->id)
            ->delete();

        return response()->json(['message' => 'Property removed from saved properties.']);
    }

    private function canSaveProperties(Request $request): bool
    {
        return $request->user()
            && $request->user()->account_status === 'active'
            && $request->user()->role !== 'admin';
    }
}
