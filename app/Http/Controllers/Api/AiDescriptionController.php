<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DeepSeekService;
use App\Support\DraftFactCheck;
use App\Support\ListingFacts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiDescriptionController extends Controller
{
    /**
     * Draft (or polish) a listing description. The response lists any numbers
     * in the draft that were not in the publisher's details, for checking.
     */
    public function __invoke(Request $request, DeepSeekService $deepSeek): JsonResponse
    {
        $data = $request->validate([
            ...$this->factRules(),
            'current_description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'style' => ['sometimes', 'nullable', 'in:'.implode(',', array_keys(DeepSeekService::DESCRIPTION_STYLES))],
            'language' => ['sometimes', 'nullable', 'in:'.implode(',', array_keys(DeepSeekService::DESCRIPTION_LANGUAGES))],
        ]);

        $facts = ListingFacts::from($data);
        $result = $deepSeek->describe($facts, [
            'style' => $data['style'] ?? 'standard',
            'language' => $data['language'] ?? 'en',
            'current_description' => $data['current_description'] ?? null,
        ]);

        if (($result['success'] ?? false) && isset($result['description'])) {
            $result['unsupported_numbers'] = DraftFactCheck::unsupportedNumbers(
                $result['description'],
                [...array_values($facts), (string) ($data['current_description'] ?? '')],
            );
        }

        return response()->json($result, 200);
    }

    /**
     * Suggest up to three listing titles from the same details.
     */
    public function titles(Request $request, DeepSeekService $deepSeek): JsonResponse
    {
        $data = $request->validate($this->factRules());

        return response()->json($deepSeek->suggestTitles(ListingFacts::from($data)), 200);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function factRules(): array
    {
        return [
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'size' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'location' => ['sometimes', 'nullable', 'string', 'max:500'],
            'price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'type' => ['sometimes', 'nullable', 'string', 'max:80'],
            'listing_type' => ['sometimes', 'nullable', 'string', 'max:40'],
            'features' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
