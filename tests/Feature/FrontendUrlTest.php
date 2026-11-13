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
        $value = 'https://butuanspaces.pages.dev, https://frontend-1cf.pages.dev';
        $saved = [$_ENV['FRONTEND_URL'] ?? null, $_SERVER['FRONTEND_URL'] ?? null, getenv('FRONTEND_URL')];
        $_ENV['FRONTEND_URL'] = $_SERVER['FRONTEND_URL'] = $value;
        putenv('FRONTEND_URL='.$value);

        try {
            $config = require base_path('config/app.php');
        } finally {
            [$env, $server, $process] = $saved;
            if ($env === null) {
                unset($_ENV['FRONTEND_URL']);
            } else {
                $_ENV['FRONTEND_URL'] = $env;
            }
            if ($server === null) {
                unset($_SERVER['FRONTEND_URL']);
            } else {
                $_SERVER['FRONTEND_URL'] = $server;
            }
            putenv($process === false ? 'FRONTEND_URL' : 'FRONTEND_URL='.$process);
        }

        config(['app.frontend_url' => $config['frontend_url']]);

        $this->assertSame('https://butuanspaces.pages.dev/login', FrontendUrl::to('/login'));
    }
}
