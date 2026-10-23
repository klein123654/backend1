<?php

namespace App\Services;

use Closure;
use OpenAI\Laravel\Facades\OpenAI;
use Throwable;

class DeepSeekService
{
    /**
     * @param  array{average_price_by_type: array<int, array{property_type: string, average_price: float|int|string, listing_count: int|string}>, count_by_location: array<int, array{city: string, province: string, listing_count: int|string}>}  $aggregates
     * @return array{success: bool, message?: string, summary: ?string}
     */
    public function marketInsight(array $aggregates): array
    {
        if (empty(config('services.deepseek.api_key'))) {
            return [
                'success' => false,
                'message' => 'AI service is not configured yet. Please add DEEPSEEK_API_KEY to .env.',
                'summary' => null,
            ];
        }

        try {
            $response = OpenAI::chat()->create([
                'model' => 'deepseek-flash',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Write a concise market insight in 3 to 4 sentences for B2B commercial real estate in Butuan City. Discuss only trends directly supported by the supplied aggregate listing counts and average prices. Do not infer demand, transactions, buyer behavior, or causes. Do not request or invent individual listing details.',
                    ],
                    [
                        'role' => 'user',
                        'content' => json_encode($aggregates, JSON_THROW_ON_ERROR),
                    ],
                ],
            ]);

            $summary = trim((string) ($response->choices[0]->message->content ?? ''));

            if ($summary === '') {
                return [
                    'success' => false,
                    'message' => 'The AI could not summarize current market data.',
                    'summary' => null,
                ];
            }

            return ['success' => true, 'summary' => $summary];
        } catch (Throwable) {
            return [
                'success' => false,
                'message' => 'AI market insights are temporarily unavailable.',
                'summary' => null,
            ];
        }
    }

    /**
     * @return array{success: bool, message?: string, summary: ?string, priority: 'high'|'medium'|'low'|'unscored'}
     */
    public function scoreInquiry(string $message): array
    {
        if (empty(config('services.deepseek.api_key'))) {
            return [
                'success' => false,
                'message' => 'AI service is not configured yet. Please add DEEPSEEK_API_KEY to .env.',
                'summary' => null,
                'priority' => 'unscored',
            ];
        }

        try {
            $response = OpenAI::chat()->create([
                'model' => 'deepseek-flash',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Summarize this commercial property inquiry in exactly one concise sentence. Assign priority high, medium, or low based on how clearly the buyer states budget, timeline, and specific requirements: high when concrete budget/timing/requirements indicate strong intent, medium when some intent or details are present, and low when the request is vague. Do not infer facts absent from the message.',
                    ],
                    ['role' => 'user', 'content' => $message],
                ],
                'tools' => [[
                    'type' => 'function',
                    'function' => [
                        'name' => 'summarize_inquiry',
                        'description' => 'Return a one-sentence inquiry summary and lead priority.',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'summary' => ['type' => 'string'],
                                'priority' => [
                                    'type' => 'string',
                                    'enum' => ['high', 'medium', 'low'],
                                ],
                            ],
                            'required' => ['summary', 'priority'],
                        ],
                    ],
                ]],
                'tool_choice' => [
                    'type' => 'function',
                    'function' => ['name' => 'summarize_inquiry'],
                ],
            ]);

            $arguments = $response->choices[0]->message->toolCalls[0]->function->arguments ?? null;
            $result = is_string($arguments) ? json_decode($arguments, true, 512, JSON_THROW_ON_ERROR) : [];
            $summary = trim((string) ($result['summary'] ?? ''));
            $priority = $result['priority'] ?? 'unscored';

            if ($summary === '' || ! in_array($priority, ['high', 'medium', 'low'], true)) {
                return [
                    'success' => false,
                    'message' => 'The AI could not score this inquiry reliably.',
                    'summary' => null,
                    'priority' => 'unscored',
                ];
            }

            return [
                'success' => true,
                'summary' => $summary,
                'priority' => $priority,
            ];
        } catch (Throwable) {
            return [
                'success' => false,
                'message' => 'AI inquiry scoring is temporarily unavailable.',
                'summary' => null,
                'priority' => 'unscored',
            ];
        }
    }

    /**
     * @return array{success: bool, message?: string, tags: array<int, string>}
     */
    public function tagProperty(string $title, string $description): array
    {
        if (empty(config('services.deepseek.api_key'))) {
            return [
                'success' => false,
                'message' => 'AI service is not configured yet. Please add DEEPSEEK_API_KEY to .env.',
                'tags' => [],
            ];
        }

        try {
            $response = OpenAI::chat()->create([
                'model' => 'deepseek-flash',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Create 3 to 6 short, useful search tags for a B2B commercial property listing in Butuan City. Tags should describe concrete property traits or location facts. Use only facts present in the title and description. Return concise lowercase phrases, such as "near port", "high-ceiling", or "flood-free".',
                    ],
                    [
                        'role' => 'user',
                        'content' => json_encode([
                            'title' => $title,
                            'description' => $description,
                        ], JSON_THROW_ON_ERROR),
                    ],
                ],
                'tools' => [[
                    'type' => 'function',
                    'function' => [
                        'name' => 'return_property_tags',
                        'description' => 'Return three to six concise tags grounded in the supplied listing details.',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'tags' => [
                                    'type' => 'array',
                                    'items' => ['type' => 'string'],
                                    'minItems' => 3,
                                    'maxItems' => 6,
                                ],
                            ],
                            'required' => ['tags'],
                        ],
                    ],
                ]],
                'tool_choice' => [
                    'type' => 'function',
                    'function' => ['name' => 'return_property_tags'],
                ],
            ]);

            $arguments = $response->choices[0]->message->toolCalls[0]->function->arguments ?? null;
            $decoded = is_string($arguments) ? json_decode($arguments, true, 512, JSON_THROW_ON_ERROR) : [];
            $tags = array_values(array_unique(array_filter(
                array_map(
                    static fn (mixed $tag): string => is_string($tag)
                        ? mb_strtolower(trim($tag))
                        : '',
                    is_array($decoded['tags'] ?? null) ? $decoded['tags'] : [],
                ),
                static fn (string $tag): bool => $tag !== '' && mb_strlen($tag) <= 40,
            )));

            if (count($tags) < 3) {
                return ['success' => false, 'message' => 'The AI could not generate enough reliable property tags.', 'tags' => []];
            }

            return ['success' => true, 'tags' => array_slice($tags, 0, 6)];
        } catch (Throwable) {
            return ['success' => false, 'message' => 'AI property tagging is temporarily unavailable.', 'tags' => []];
        }
    }

    /**
     * @param  array{title?: string, size?: int|float|null, location?: string, price?: int|float|null, type?: string, listing_type?: string}  $details
     * @return array{success: bool, message?: string, description?: string}
     */
    public function describe(array $details): array
    {
        if (empty(config('services.deepseek.api_key'))) {
            return ['success' => false, 'message' => 'AI service is not configured yet. Please add DEEPSEEK_API_KEY to .env.'];
        }

        try {
            $response = OpenAI::chat()->create([
                'model' => 'deepseek-flash',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Write a professional, SEO-friendly description of 80 to 120 words for a B2B commercial real estate listing in Butuan City. Use only the supplied facts. Do not invent property features, amenities, nearby landmarks, access, or measurements. Mention Butuan City naturally. Return only the description text.',
                    ],
                    [
                        'role' => 'user',
                        'content' => 'Create a listing description using these details: '.json_encode($details, JSON_THROW_ON_ERROR),
                    ],
                ],
            ]);

            $description = trim((string) ($response->choices[0]->message->content ?? ''));

            if ($description === '') {
                return ['success' => false, 'message' => 'The AI could not generate a description. Please try again.'];
            }

            return ['success' => true, 'description' => $description];
        } catch (Throwable) {
            return ['success' => false, 'message' => 'AI description generation is temporarily unavailable. Please try again shortly.'];
        }
    }

    /**
     * @param  Closure(array<string, mixed>): array<int, array<string, mixed>>  $findProperties
     * @return array{success: bool, message?: string, summary?: string, listings?: array<int, array<string, mixed>>}
     */
    public function search(string $query, Closure $findProperties): array
    {
        if (empty(config('services.deepseek.api_key'))) {
            return ['success' => false, 'message' => 'AI service is not configured yet. Please add DEEPSEEK_API_KEY to .env.'];
        }

        try {
            $toolResponse = OpenAI::chat()->create([
                'model' => 'deepseek-flash',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Extract property search filters from the user query. Use only the searchProperties tool.',
                    ],
                    ['role' => 'user', 'content' => $query],
                ],
                'tools' => [[
                    'type' => 'function',
                    'function' => [
                        'name' => 'searchProperties',
                        'description' => 'Find published commercial properties matching the user\'s request.',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'location' => [
                                    'type' => 'string',
                                    'description' => 'City, province, barangay, or address to search; empty when unspecified.',
                                ],
                                'property_type' => [
                                    'type' => 'string',
                                    'description' => 'Commercial property type to search; empty when unspecified.',
                                ],
                                'max_price' => [
                                    'type' => 'number',
                                    'description' => 'Maximum price in PHP; use 0 when unspecified.',
                                ],
                                'min_size' => [
                                    'type' => 'number',
                                    'description' => 'Minimum area in square meters; use 0 when unspecified.',
                                ],
                                'keywords' => [
                                    'type' => 'array',
                                    'items' => ['type' => 'string'],
                                    'description' => 'Distinctive property terms from the query.',
                                ],
                            ],
                            'required' => ['location', 'property_type', 'max_price', 'min_size', 'keywords'],
                        ],
                    ],
                ]],
                'tool_choice' => 'auto',
            ]);

            $assistantMessage = $toolResponse->choices[0]->message;
            $toolCall = $assistantMessage->toolCalls[0] ?? null;

            if ($toolCall === null || $toolCall->function->name !== 'searchProperties') {
                return ['success' => false, 'message' => 'The AI could not understand that property search. Please try rephrasing it.'];
            }

            $filters = json_decode($toolCall->function->arguments, true, 512, JSON_THROW_ON_ERROR);
            $listings = $findProperties($filters);

            $summaryResponse = OpenAI::chat()->create([
                'model' => 'deepseek-flash',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Summarize these matching commercial property listings for the user. Do not invent details; clearly say when no listings match.',
                    ],
                    ['role' => 'user', 'content' => $query],
                    [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => $toolCall->id,
                            'type' => 'function',
                            'function' => [
                                'name' => 'searchProperties',
                                'arguments' => $toolCall->function->arguments,
                            ],
                        ]],
                    ],
                    [
                        'role' => 'tool',
                        'tool_call_id' => $toolCall->id,
                        'content' => json_encode($listings, JSON_THROW_ON_ERROR),
                    ],
                ],
            ]);

            return [
                'success' => true,
                'summary' => $summaryResponse->choices[0]->message->content ?? 'Here are the matching commercial listings.',
                'listings' => $listings,
            ];
        } catch (Throwable) {
            return ['success' => false, 'message' => 'AI property search is temporarily unavailable. Please try again shortly.'];
        }
    }
}
