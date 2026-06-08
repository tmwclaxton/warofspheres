<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class EnforceCanonicalHttpsTest extends TestCase
{
    /**
     * @param  array<string, string>  $server
     */
    private function productionRequest(string $uri = '/', array $server = [], bool $secure = true): TestResponse
    {
        $this->app['env'] = 'production';

        Config::set('app.url', 'https://clashofdots.com');

        $defaults = [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_HOST' => 'clashofdots.com',
        ];

        if ($secure) {
            $defaults['HTTPS'] = 'on';
            $defaults['HTTP_X_FORWARDED_PROTO'] = 'https';
        }

        return $this->call('GET', $uri, [], [], [], array_merge($defaults, $server));
    }

    public function test_redirects_insecure_requests_to_https_in_production(): void
    {
        $response = $this->productionRequest('/', [
            'HTTP_X_FORWARDED_PROTO' => 'http',
        ], secure: false);

        $response->assertRedirect('https://clashofdots.com/');
        $response->assertStatus(301);
    }

    public function test_redirects_www_host_to_canonical_https_in_production(): void
    {
        $response = $this->productionRequest('/', [
            'HTTP_HOST' => 'www.clashofdots.com',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        $response->assertRedirect('https://clashofdots.com/');
        $response->assertStatus(301);
    }

    public function test_secure_canonical_requests_include_hsts_header_in_production(): void
    {
        $response = $this->productionRequest('https://clashofdots.com/');

        $response->assertOk();
        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_generates_https_preload_links_behind_proxy_in_production(): void
    {
        $response = $this->productionRequest('https://clashofdots.com/');

        $response->assertOk();

        $link = $response->headers->get('Link');

        $this->assertNotNull($link);
        $this->assertStringNotContainsString('http://clashofdots.com', $link);
    }

    public function test_does_not_redirect_local_requests(): void
    {
        Config::set('app.env', 'local');
        Config::set('app.url', 'http://localhost');

        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertHeaderMissing('Strict-Transport-Security');
    }
}
