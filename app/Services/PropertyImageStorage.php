<?php

namespace App\Services;

use App\Models\Property;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PropertyImageStorage
{
    /**
     * Delete a listing together with its uploaded photo files.
     *
     * A storage failure is reported but does not stop the listing from being
     * deleted; at worst an orphaned file is left in the bucket.
     */
    public function deleteListing(Property $property): void
    {
        $disk = config('filesystems.default', 'public');

        foreach ($property->images as $image) {
            $relativePath = $this->relativeStoragePath($image->path);

            if ($relativePath === '') {
                continue;
            }

            try {
                Storage::disk($disk)->delete($relativePath);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $property->delete();
    }

    /**
     * Remove, reorder and choose the cover photo of a listing. Removing or
     * rearranging existing photos does not send a listing back to review,
     * because no new content is added.
     *
     * @param  array<int, int>  $removeIds
     * @param  array<int, int>  $order  photo ids, first is shown first
     */
    public function arrange(Property $property, array $removeIds, array $order, ?int $primaryId): void
    {
        $disk = config('filesystems.default', 'public');

        foreach ($property->images()->whereIn('id', $removeIds)->get() as $image) {
            $relativePath = $this->relativeStoragePath($image->path);
            if ($relativePath !== '') {
                try {
                    Storage::disk($disk)->delete($relativePath);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
            $image->delete();
        }

        $images = $property->images()->orderBy('sort_order')->orderBy('id')->get();
        $position = array_flip(array_values(array_diff($order, $removeIds)));
        $sorted = $images->sortBy(fn ($image) => [$position[$image->id] ?? PHP_INT_MAX, $image->sort_order, $image->id])->values();

        if ($primaryId === null || in_array($primaryId, $removeIds, true)) {
            $primaryId = $sorted->firstWhere('is_primary', true)?->id ?? $sorted->first()?->id;
        }

        foreach ($sorted as $index => $image) {
            $image->forceFill(['sort_order' => $index, 'is_primary' => $image->id === $primaryId])->save();
        }
    }

    /**
     * Convert a stored public image URL back into a path on the storage disk.
     */
    public function relativeStoragePath(string $path): string
    {
        $parsed = parse_url($path, PHP_URL_PATH);

        if ($parsed === null || $parsed === false || $parsed === '') {
            return '';
        }

        $bucketName = config('filesystems.disks.supabase.bucket', 'property-images');
        $basePath = '/storage/v1/object/public/'.$bucketName.'/';

        if (str_contains($parsed, '/storage/v1/object/public/')) {
            return ltrim(str_replace($basePath, '', $parsed), '/');
        }

        return ltrim(str_replace('/storage/', '', $parsed), '/');
    }
}
