<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DeepSeekService;
use Illuminate\Http\Request;

class AiDescriptionController extends Controller
{
    public function __invoke(Request $request, DeepSeekService $deepSeek): mixed
    {
        $details = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'size' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'location' => ['sometimes', 'nullable', 'string', 'max:500'],
            'price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'type' => ['sometimes', 'nullable', 'string', 'max:80'],
            'listing_type' => ['sometimes', 'nullable', 'string', 'max:40'],
        ]);

        $result = $deepSeek->describe($details);

        return response()->json($result, 200);
    }
}
