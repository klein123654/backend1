<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SupabaseStorageConfigurationTest extends TestCase
{
    public function test_supabase_disk_is_available_for_property_uploads(): void
    {
        config([
            'filesystems.default' => 'supabase',
            'filesystems.disks.supabase' => [
                'driver' => 's3',
                'key' => 'demo-access-key-id',
                'secret' => 'demo-secret-access-key',
                'region' => 'us-east-1',
                'bucket' => 'property-images',
                'url' => 'https://ihagysnbteppvuqpgmib.supabase.co/storage/v1/object/public/property-images',
                'endpoint' => 'https://ihagysnbteppvuqpgmib.supabase.co/storage/v1/s3',
                'use_path_style_endpoint' => true,
            ],
        ]);

        $this->assertNotNull(config('filesystems.disks.supabase'));
        $this->assertSame('s3', config('filesystems.disks.supabase.driver'));
        $this->assertNotSame(
            config('filesystems.disks.supabase.key'),
            config('filesystems.disks.supabase.secret')
        );
        $this->assertSame('supabase', config('filesystems.default'));
        $this->assertStringContainsString('/storage/v1/object/public/', config('filesystems.disks.supabase.url'));
        $this->assertSame('property-images', config('filesystems.disks.supabase.bucket'));
    }

    public function test_supabase_public_url_appends_only_the_bucket_relative_object_path(): void
    {
        config([
            'filesystems.disks.supabase' => [
                'driver' => 's3',
                'key' => 'demo-access-key-id',
                'secret' => 'demo-secret-access-key',
                'region' => 'us-east-1',
                'bucket' => 'property-images',
                'url' => 'https://storage.example.test/storage/v1/object/public/property-images',
                'endpoint' => 'https://storage.example.test/storage/v1/s3',
                'use_path_style_endpoint' => true,
            ],
        ]);
        Storage::forgetDisk('supabase');

        $publicUrl = Storage::disk('supabase')->url('properties/warehouse.png');

        $this->assertSame(
            'https://storage.example.test/storage/v1/object/public/property-images/properties/warehouse.png',
            $publicUrl,
        );
        $this->assertStringNotContainsString('/property-images/property-images/', $publicUrl);
    }
}
