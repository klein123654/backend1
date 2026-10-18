<?php

namespace Tests\Feature;

use Tests\TestCase;

class SupabaseStorageConfigurationTest extends TestCase
{
    public function test_supabase_disk_is_available_for_property_uploads(): void
    {
        config([
            'filesystems.default' => 'supabase',
            'filesystems.disks.supabase' => [
                'driver' => 's3',
                'key' => 'demo-key',
                'secret' => 'demo-secret',
                'region' => 'us-east-1',
                'bucket' => 'property-images',
                'url' => 'https://ihagysnbteppvuqpgmib.supabase.co/storage/v1/object/public/property-images',
                'endpoint' => 'https://ihagysnbteppvuqpgmib.supabase.co/storage/v1/s3',
                'use_path_style_endpoint' => true,
            ],
        ]);

        $this->assertNotNull(config('filesystems.disks.supabase'));
        $this->assertSame('supabase', config('filesystems.default'));
        $this->assertStringContainsString('/storage/v1/object/public/', config('filesystems.disks.supabase.url'));
        $this->assertSame('property-images', config('filesystems.disks.supabase.bucket'));
    }
}
