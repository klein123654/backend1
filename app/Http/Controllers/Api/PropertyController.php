<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\PropertyImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PropertyController extends Controller
{
    private const PUBLIC_COLUMNS = [
        'id', 'owner_id', 'broker_id', 'title', 'slug', 'property_type',
        'listing_type', 'description', 'price', 'currency', 'address_line',
        'barangay', 'city', 'province', 'postal_code', 'latitude', 'longitude',
        'bedrooms', 'bathrooms', 'floor_area', 'lot_area', 'parking_spaces',
        'status', 'published_at', 'created_at', 'updated_at',
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
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $query = Property::query()
            ->select(self::PUBLIC_COLUMNS)
            ->with(['images', 'owner:id,name', 'broker:id,name'])
            ->where('status', 'published')
            ->whereNotNull('published_at');

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

        if (isset($filters['min_price'])) {
            $query->where('price', '>=', $filters['min_price']);
        }

        if (isset($filters['max_price'])) {
            $query->where('price', '<=', $filters['max_price']);
        }

        if (isset($filters['bedrooms'])) {
            $query->where('bedrooms', '>=', $filters['bedrooms']);
        }

        $perPage = (int) ($filters['per_page'] ?? 12);

        $properties = $query
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate($perPage);

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
     * Public details by numeric ID or slug.
     */
    public function show(string $property)
    {
        $query = Property::query()
            ->select(self::PUBLIC_COLUMNS)
            ->with(['images', 'owner:id,name', 'broker:id,name'])
            ->where('status', 'published')
            ->whereNotNull('published_at');

        if (ctype_digit($property)) {
            $query->where('id', (int) $property);
        } else {
            $query->where('slug', $property);
        }

        return response()->json([
            'data' => $query->firstOrFail(),
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
    public function store(Request $request)
    {
        $this->ensureActiveOwner($request);

        $data = $request->validate($this->propertyRules());
        $images = $data['images'] ?? [];
        $submitForReview = (bool) ($data['submit_for_review'] ?? false);
        unset($data['images'], $data['submit_for_review']);

        $property = Property::create(array_merge($data, [
            'owner_id' => $request->user()->id,
            'broker_id' => $request->user()->role === 'broker' ? $request->user()->id : null,
            'slug' => $this->uniqueSlug($data['title']),
            'status' => $submitForReview ? 'pending' : 'draft',
            'published_at' => null,
        ]));
        $this->saveImages($property, $images);

        return response()->json([
            'message' => 'Property saved as a draft.',
            'data' => $property->load('images'),
        ], 201);
    }

    /**
     * Update only a property belonging to the authenticated owner.
     * Publication status cannot be changed through this endpoint.
     */
    public function update(Request $request, Property $property)
    {
        $this->ensureActiveOwner($request);

        abort_unless(
            (int) $property->owner_id === (int) $request->user()->id,
            403,
            'You cannot edit another owner’s property.'
        );

        $data = $request->validate($this->propertyRules(true));
        $images = $data['images'] ?? [];
        $submitForReview = (bool) ($data['submit_for_review'] ?? false);
        unset($data['images'], $data['submit_for_review']);

        if (
            isset($data['title']) &&
            $data['title'] !== $property->title
        ) {
            $data['slug'] = $this->uniqueSlug(
                $data['title'],
                (int) $property->id
            );
        }

        $property->fill($data);
        if ($submitForReview && in_array($property->status, ['draft', 'rejected'], true)) {
            $property->status = 'pending';
            $property->reviewed_by = null;
            $property->reviewed_at = null;
            $property->review_notes = null;
        }
        $property->save();
        $this->saveImages($property, $images);

        return response()->json([
            'message' => 'Property updated.',
            'data' => $property->fresh()->load('images'),
        ]);
    }

    public function destroy(Request $request, Property $property)
    {
        $this->ensureActiveOwner($request);
        abort_unless((int) $property->owner_id === (int) $request->user()->id, 403);
        abort_unless(in_array($property->status, ['draft', 'rejected'], true), 409, 'Only drafts and rejected listings can be deleted.');

        foreach ($property->images as $image) {
            Storage::disk('public')->delete(str_replace('/storage/', '', $image->path));
        }
        $property->delete();

        return response()->json(['message' => 'Listing deleted.']);
    }

    private function ensureActiveOwner(Request $request): void
    {
        abort_unless(
            $request->user() &&
            $request->user()->account_status === 'active',
            403,
            'Your account is not active.'
        );

        abort_unless(
            in_array($request->user()->role, ['owner', 'broker', 'developer'], true),
            403,
            'A publisher account is required to manage property listings.'
        );

        if ($request->user()->role === 'broker') {
            abort_unless(
                $request->user()->brokerVerifications()->where('status', 'verified')->exists(),
                403,
                'Broker verification is required before managing listings.'
            );
        }
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
        foreach ($images as $index => $image) {
            $path = $image->store('properties', 'public');
            PropertyImage::create([
                'property_id' => $property->id,
                'path' => '/storage/'.$path,
                'alt_text' => $property->title,
                'sort_order' => $index,
                'is_primary' => $index === 0 && ! $property->images()->exists(),
            ]);
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
