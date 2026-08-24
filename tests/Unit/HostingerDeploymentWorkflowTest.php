<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class HostingerDeploymentWorkflowTest extends TestCase
{
    public function test_it_pins_all_actions_and_keeps_deployment_guards(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/deploy.yml');

        $this->assertIsString($workflow);
        preg_match_all('/^\s*uses:\s+(\S+?)(?:\s+#.*)?$/m', $workflow, $matches);

        $this->assertNotEmpty($matches[1]);

        foreach ($matches[1] as $action) {
            $this->assertMatchesRegularExpression('/^[^@\s]+@[0-9a-f]{40}$/', $action);
        }

        $this->assertStringContainsString("github.event.workflow_run.event == 'push'", $workflow);
        $this->assertStringContainsString("github.event.workflow_run.head_branch == 'main'", $workflow);
        $this->assertStringContainsString('DEPLOY_COMMIT: ${{ github.event.workflow_run.head_sha }}', $workflow);
        $this->assertStringContainsString('git ls-remote origin refs/heads/main', $workflow);
        $this->assertStringContainsString('StrictHostKeyChecking=yes', $workflow);
        $this->assertStringContainsString('SSH_KNOWN_HOSTS', $workflow);
    }

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
