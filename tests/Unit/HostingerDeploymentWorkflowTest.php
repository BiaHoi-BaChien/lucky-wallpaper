<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class HostingerDeploymentWorkflowTest extends TestCase
{
    public function test_it_keeps_route_caching_disabled_for_the_subdirectory_deployment(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/deploy.yml');

        $this->assertIsString($workflow);
        $this->assertStringContainsString('php artisan optimize:clear', $workflow);
        $this->assertStringContainsString('php artisan config:cache', $workflow);
        $this->assertDoesNotMatchRegularExpression('/^\s*php artisan route:cache\s*$/m', $workflow);
        $this->assertStringContainsString('php artisan view:cache', $workflow);
    }

    public function test_it_builds_assets_for_the_production_subdirectory(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/deploy.yml');

        $this->assertIsString($workflow);
        $this->assertMatchesRegularExpression(
            '/- name: Build production artifact.*?env:\s+ASSET_URL: \/lucky_wallpaper.*?npm run build/s',
            $workflow,
        );
    }
}
