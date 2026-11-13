<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\PropertyImage;
use App\Services\DeepSeekService;
use App\Services\PropertyImageStorage;
use App\Support\PublisherProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PropertyController extends Controller
{
    private const PUBLIC_COLUMNS = [
        'id', 'owner_id', 'broker_id', 'title', 'slug', 'property_type',
        'listing_type', 'description', 'price', 'currency', 'address_line',
        'barangay', 'city', 'province', 'postal_code', 'latitude', 'longitude',
        'bedrooms', 'bathrooms', 'floor_area', 'lot_area', 'parking_spaces',
        'status', 'published_at', 'created_at', 'updated_at', 'ai_tags',
    ];

    /**
     * Public search. Only published listings with a publication timestamp
     * are visible to the public.
     */
    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:120'],
            'city' => ['sometimes', 'string', 'max:120'],
            'province' => ['sometimes', 'string', 'max:120'],
            'property_type' => ['sometimes', 'string', 'max:80'],
            'listing_type' => ['sometimes', 'string', 'max:40'],
            'min_price' => ['sometimes', 'numeric', 'min:0'],
            'max_price' => ['sometimes', 'numeric', 'min:0'],
            'bedrooms' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'ai_tags' => ['sometimes', 'array', 'max:6'],
            'ai_tags.*' => ['string', 'max:40'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'sort' => ['sometimes', 'string', 'in:newest,oldest,price_asc,price_desc'],
            'barangay' => ['sometimes', 'string', 'max:120'],
            'publisher' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = Property::query()
            ->select(self::PUBLIC_COLUMNS)
            ->with(['images', 'broker:id,name', ...PublisherProfile::eagerLoads()])
            ->publiclyVisible();

        if (! empty($filters['search'])) {
            $term = '%'.strtolower($filters['search']).'%';

            $query->where(function ($builder) use ($term) {
                $builder->whereRaw('LOWER(title) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(description) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(address_line) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(city) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(province) LIKE ?', [$term]);
            });
        }

        foreach (['city', 'province', 'property_type', 'listing_type'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                if (in_array($field, ['city', 'province'], true)) {
                    $query->whereRaw(
                        'LOWER('.$field.') LIKE ?',
                        ['%'.strtolower($filters[$field]).'%']
                    );
                } else {
                    $query->where($field, $filters[$field]);
                }
            }
        }

        if (! empty($filters['barangay'])) {
            $query->whereRaw('LOWER(barangay) = ?', [strtolower(trim($filters['barangay']))]);
        }

        if (isset($filters['publisher'])) {
            $query->where('owner_id', $filters['publisher']);
        }

        if (isset($filters['min_price'])) {
            $query->where('price', '>=', $filters['min_price']);
        }

        if (isset($filters['max_price'])) {
            $query->where('price', '<=', $filters['max_price']);
        }

        if (isset($filters['bedrooms'])) {
            $query->where('bedrooms', '>=', $filters['bedrooms']);
        }

        if (! empty($filters['ai_tags'])) {
            $query->where(function ($tagQuery) use ($filters): void {
                foreach ($filters['ai_tags'] as $tag) {
                    $tagQuery->orWhereJsonContains('ai_tags', $tag);
                }
            });
        }

        $perPage = (int) ($filters['per_page'] ?? 12);

        match ($filters['sort'] ?? 'newest') {
            'oldest' => $query->orderBy('published_at')->orderBy('id'),
            'price_asc' => $query->orderBy('price')->orderByDesc('id'),
            'price_desc' => $query->orderByDesc('price')->orderByDesc('id'),
            default => $query->orderByDesc('published_at')->orderByDesc('id'),
        };

        $properties = $query->paginate($perPage);

        return response()->json([
            'data' => array_map($this->withPublisher(...), $properties->items()),
            'meta' => [
                'current_page' => $properties->currentPage(),
                'last_page' => $properties->lastPage(),
                'per_page' => $properties->perPage(),
                'total' => $properties->total(),
            ],
        ]);
    }

    /**
     * Public details by numeric ID or slug.
     */
    public function show(Request $request, string $property)
    {
        $query = Property::query()
            ->select(self::PUBLIC_COLUMNS)
            ->with(['images', 'broker:id,name', ...PublisherProfile::eagerLoads()])
            ->publiclyVisible();

        if (ctype_digit($property)) {
            $query->where('id', (int) $property);
        } else {
            $query->where('slug', $property);
        }

        $record = $query->firstOrFail();
        $this->recordView($request, $record);

        return response()->json([
            'data' => $this->withPublisher($record),
        ]);
    }

    /**
     * Count a listing view at most once per visitor per hour, ignoring
     * crawlers, link-preview bots and the publisher's own visits.
     */
    private function recordView(Request $request, Property $property): void
    {
        if (preg_match('/bot|crawl|spider|slurp|preview|facebookexternalhit|whatsapp|telegram|headless|lighthouse/i', (string) $request->userAgent())) {
            return;
        }

        $viewer = auth('sanctum')->user();
        if ($viewer && in_array($viewer->id, [$property->owner_id, $property->broker_id], true)) {
            return;
        }

        $visitor = $viewer ? 'user:'.$viewer->id : 'ip:'.hash('sha256', (string) $request->ip());
        if (Cache::add('listing-view:'.$property->id.':'.$visitor, true, 3600)) {
            Property::query()->whereKey($property->id)->increment('views_count');
        }
    }

    /**
     * Number of publicly visible listings per barangay, for the home page.
     */
    public function barangays(): JsonResponse
    {
        $counts = Property::query()
            ->publiclyVisible()
            ->whereNotNull('barangay')
            ->where('barangay', '!=', '')
            ->selectRaw('barangay, COUNT(*) AS listing_count')
            ->groupBy('barangay')
            ->orderByDesc('listing_count')
            ->orderBy('barangay')
            ->get()
            ->map(fn (Property $row): array => [
                'barangay' => $row->barangay,
                'listing_count' => (int) $row->getAttribute('listing_count'),
            ]);

        return response()->json(['data' => $counts]);
    }

    /**
     * Mark a listing as still available, rented or sold. Rented and sold
     * listings are hidden from the public; confirming resets the reminder clock.
     */
    public function updateAvailability(Request $request, Property $property): JsonResponse
    {
        Gate::authorize('update', $property);

        $data = $request->validate([
            'availability' => ['required', 'in:'.implode(',', Property::AVAILABILITY_OPTIONS)],
        ]);

        $property->markAvailabilityConfirmed($data['availability']);
        $property->save();

        return response()->json([
            'message' => $data['availability'] === 'available'
                ? 'Thanks! The listing is marked as still available.'
                : 'The listing is marked as '.$data['availability'].' and hidden from the public.',
            'data' => $property->fresh()->load('images'),
        ]);
    }

    /**
     * List the authenticated owner's own listings, including drafts.
     */
    public function mine(Request $request)
    {
        abort_unless($request->user()->account_status === 'active', 403);
        $filters = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $properties = $request->user()
            ->ownedProperties()
            ->with('images')
            ->withCount('inquiries')
            ->orderByDesc('created_at')
            ->paginate((int) ($filters['per_page'] ?? 12));

        return response()->json([
            'data' => $properties->items(),
            'meta' => [
                'current_page' => $properties->currentPage(),
                'last_page' => $properties->lastPage(),
                'per_page' => $properties->perPage(),
                'total' => $properties->total(),
            ],
        ]);
    }

    /**
     * Create a draft. Clients cannot choose an owner, broker, or status.
     */
    public function store(Request $request, DeepSeekService $deepSeek)
    {
        Gate::authorize('create', Property::class);

        $data = $request->validate($this->propertyRules());
        $images = $data['images'] ?? [];
        $submitForReview = (bool) ($data['submit_for_review'] ?? false);
        unset($data['images'], $data['submit_for_review']);

        $property = DB::transaction(function () use ($request, $data, $images, $submitForReview) {
            $property = Property::create(array_merge($data, [
                'owner_id' => $request->user()->id,
                'broker_id' => $request->user()->role === 'broker' ? $request->user()->id : null,
                'slug' => $this->uniqueSlug($data['title']),
                'status' => $submitForReview ? 'pending' : 'draft',
                'published_at' => null,
            ]));

            if (! empty($images)) {
                $this->saveImages($property, $images);
            }

            return $property;
        });

        try {
            $tagResult = $deepSeek->tagProperty($property->title, $property->description);
            if ($tagResult['success'] && ! empty($tagResult['tags'])) {
                $property->forceFill(['ai_tags' => $tagResult['tags']])->save();
            }
        } catch (\Throwable) {
            // Tagging is optional and must not prevent property creation.
        }

        return response()->json([
            'message' => $submitForReview ? 'Property submitted for review.' : 'Property saved as a draft.',
            'data' => $property->fresh()->load('images'),
        ], 201);
    }

    /**
     * Update a property belonging to the authenticated owner. Editing a pending
     * or published listing sends it back to administrator review.
     */
    public function update(Request $request, Property $property)
    {
        Gate::authorize('update', $property);

        $data = $request->validate($this->propertyRules(true) + [
            'remove_image_ids' => ['sometimes', 'array', 'max:20'],
            'remove_image_ids.*' => ['integer'],
            'image_order' => ['sometimes', 'array', 'max:30'],
            'image_order.*' => ['integer'],
            'primary_image_id' => ['sometimes', 'nullable', 'integer'],
        ]);
        $images = $data['images'] ?? [];
        $submitForReview = (bool) ($data['submit_for_review'] ?? false);
        $removeIds = array_map('intval', $data['remove_image_ids'] ?? []);
        $imageOrder = array_map('intval', $data['image_order'] ?? []);
        $primaryId = isset($data['primary_image_id']) ? (int) $data['primary_image_id'] : null;
        unset($data['images'], $data['submit_for_review'], $data['remove_image_ids'], $data['image_order'], $data['primary_image_id']);

        $ownImageIds = $property->images()->pluck('id')->all();
        foreach ([...$removeIds, ...$imageOrder, ...array_filter([$primaryId])] as $imageId) {
            abort_unless(in_array($imageId, $ownImageIds, true), 422, 'One of the selected photos does not belong to this listing.');
        }
        if ($removeIds !== [] && count($ownImageIds) - count(array_unique($removeIds)) + count($images) < 1) {
            return response()->json([
                'message' => 'Keep at least one photo on the listing.',
                'errors' => ['images' => ['Keep at least one photo on the listing.']],
            ], 422);
        }

        if (
            isset($data['title']) &&
            $data['title'] !== $property->title
        ) {
            $data['slug'] = $this->uniqueSlug(
                $data['title'],
                (int) $property->id
            );
        }

        $property = DB::transaction(function () use ($property, $data, $images, $submitForReview) {
            $property->fill($data);
            $isEditingSubmittedListing = in_array($property->status, ['pending', 'published'], true)
                && ($property->isDirty() || ! empty($images));
            $property->markAvailabilityConfirmed($property->availability ?? 'available');

            if ($isEditingSubmittedListing) {
                // Changes to a live or submitted listing must be re-approved, so
                // the listing is hidden from the public until an admin reviews it.
                $property->status = 'pending';
                $property->published_at = null;
                $property->reviewed_by = null;
                $property->reviewed_at = null;
                $property->review_notes = null;
            } elseif ($submitForReview && in_array($property->status, ['draft', 'rejected'], true)) {
                $property->status = 'pending';
                $property->reviewed_by = null;
                $property->reviewed_at = null;
                $property->review_notes = null;
            }
            $property->save();

            if (! empty($images)) {
                $this->saveImages($property, $images);
            }

            return $property;
        });

        if ($removeIds !== [] || $imageOrder !== [] || $primaryId !== null) {
            app(PropertyImageStorage::class)->arrange($property, $removeIds, $imageOrder, $primaryId);
        }

        return response()->json([
            'message' => $property->status === 'pending'
                ? 'Property updated and sent to an administrator for review.'
                : 'Property updated.',
            'data' => $property->fresh()->load('images'),
        ]);
    }

    /**
     * Owners may delete their own listings at any status. Inquiries, reports
     * and saved entries for the listing are removed by cascading foreign keys.
     */
    public function destroy(Property $property, PropertyImageStorage $imageStorage)
    {
        Gate::authorize('delete', $property);

        $imageStorage->deleteListing($property);

        return response()->json(['message' => 'Listing deleted.']);
    }

    /**
     * Attach the publisher's public profile and drop the raw owner relation.
     */
    private function withPublisher(Property $property): Property
    {
        if ($property->owner) {
            $property->setAttribute('publisher', PublisherProfile::make($property->owner));
            $property->owner->setRelations([])->setHidden(['role', 'created_at', 'contact_phone', 'contact_email', 'messenger_username', 'viber_number']);
        }

        return $property;
    }

    private function propertyRules(bool $partial = false): array
    {
        return [
            'title' => $partial
                ? ['sometimes', 'required', 'string', 'max:255']
                : ['required', 'string', 'max:255'],

            'property_type' => $partial
                ? ['sometimes', 'required', 'string', 'max:80']
                : ['required', 'string', 'max:80'],

            'listing_type' => $partial
                ? ['sometimes', 'required', 'string', 'max:40']
                : ['required', 'string', 'max:40'],

            'description' => $partial
                ? ['sometimes', 'required', 'string', 'max:20000']
                : ['required', 'string', 'max:20000'],

            'price' => $partial
                ? ['sometimes', 'required', 'numeric', 'min:0', 'max:999999999999.99']
                : ['required', 'numeric', 'min:0', 'max:999999999999.99'],

            'currency' => ['sometimes', 'string', 'size:3'],

            'address_line' => $partial
                ? ['sometimes', 'required', 'string', 'max:255']
                : ['required', 'string', 'max:255'],

            'barangay' => ['sometimes', 'nullable', 'string', 'max:255'],

            'city' => $partial
                ? ['sometimes', 'required', 'string', 'max:255']
                : ['required', 'string', 'max:255'],

            'province' => $partial
                ? ['sometimes', 'required', 'string', 'max:255']
                : ['required', 'string', 'max:255'],

            'postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],

            'latitude' => [
                'sometimes', 'nullable', 'numeric', 'between:-90,90',
            ],

            'longitude' => [
                'sometimes', 'nullable', 'numeric', 'between:-180,180',
            ],

            'bedrooms' => [
                'sometimes', 'nullable', 'integer', 'min:0', 'max:65535',
            ],

            'bathrooms' => [
                'sometimes', 'nullable', 'numeric', 'min:0', 'max:999.9',
            ],

            'floor_area' => [
                'sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999.99',
            ],

            'lot_area' => [
                'sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999.99',
            ],

            'parking_spaces' => [
                'sometimes', 'nullable', 'integer', 'min:0', 'max:65535',
            ],
            'submit_for_review' => ['sometimes', 'boolean'],
            'images' => ['sometimes', 'array', 'max:10'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    private function saveImages(Property $property, array $images): void
    {
        $disk = config('filesystems.default', 'public');
        $nextOrder = ((int) $property->images()->reorder()->max('sort_order')) + ($property->images()->exists() ? 1 : 0);
        $hasPrimary = $property->images()->where('is_primary', true)->exists();

        foreach ($images as $index => $image) {
            try {
                // Generate a unique file name to prevent overwriting
                $filename = Str::random(20).'.'.$image->getClientOriginalExtension();

                // Upload the file with the exact MIME type from the browser
                $path = Storage::disk($disk)->putFileAs('properties', $image, $filename, [
                    'mimetype' => $image->getClientMimeType(),
                    'visibility' => 'public',
                ]);

                if (! is_string($path)) {
                    throw new \RuntimeException('Image upload did not return a storage path.');
                }

                $storedUrl = Storage::disk($disk)->url($path);

                PropertyImage::create([
                    'property_id' => $property->id,
                    'path' => $storedUrl,
                    'alt_text' => $property->title,
                    'sort_order' => $nextOrder + $index,
                    'is_primary' => $index === 0 && ! $hasPrimary,
                ]);
            } catch (\Throwable $exception) {
                throw new \RuntimeException(
                    'Image upload failed. Please check the storage bucket, credentials, and permissions before trying again.',
                    0,
                    $exception
                );
            }
        }
    }

    private function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title);

        if ($base === '') {
            $base = 'property';
        }

        $slug = $base;
        $suffix = 2;

        while (true) {
            $query = Property::query()->where('slug', $slug);

            if ($ignoreId !== null) {
                $query->where('id', '!=', $ignoreId);
            }

            if (! $query->exists()) {
                return $slug;
            }

            $slug = $base.'-'.$suffix;
            $suffix++;
        }
    }
}
