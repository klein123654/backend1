<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\PropertyReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReportController extends Controller
{
    public function global(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'category' => ['required', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:10000'],
        ]);

        $report = PropertyReport::create([
            'reporter_id' => Auth::guard('sanctum')->user()?->id,
            'reporter_name' => $data['name'] ?? null,
            'reporter_email' => $data['email'] ?? null,
            'reason' => $data['category'].': '.$data['title'],
            'details' => $data['description'],
            'status' => 'open',
        ]);

        return response()->json(['message' => 'Report submitted.', 'data' => $report], 201);
    }

    public function store(Request $request, Property $property): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'reason' => ['required', 'string', 'max:255'],
            'details' => ['nullable', 'string', 'max:10000'],
        ]);

        $report = PropertyReport::create([
            'property_id' => $property->id,
            'reporter_id' => Auth::guard('sanctum')->user()?->id,
            'reporter_name' => $data['name'] ?? null,
            'reporter_email' => $data['email'] ?? null,
            'reason' => $data['reason'],
            'details' => $data['details'] ?? null,
            'status' => 'open',
        ]);

        return response()->json(['message' => 'Report submitted.', 'data' => $report], 201);
    }
}
