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
