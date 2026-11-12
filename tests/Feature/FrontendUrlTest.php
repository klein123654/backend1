<?php

namespace Tests\Feature;

use App\Support\FrontendUrl;
use Tests\TestCase;

class FrontendUrlTest extends TestCase
{
    public function test_new_frontend_origin_is_allowed_by_cors(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'https://butuanspaces.pages.dev',
            'Access-Control-Request-Method' => 'GET',
        ])->options('/api/properties');

        $response->assertHeader('Access-Control-Allow-Origin', 'https://butuanspaces.pages.dev');
    }

    public function test_links_use_the_first_origin_when_several_are_configured(): void
    {
        putenv('FRONTEND_URL=https://butuanspaces.pages.dev, https://frontend-1cf.pages.dev');
        try {
            $config = require base_path('config/app.php');
        } finally {
            putenv('FRONTEND_URL');
        }

        config(['app.frontend_url' => $config['frontend_url']]);

        $this->assertSame('https://butuanspaces.pages.dev/login', FrontendUrl::to('/login'));
    }
}
