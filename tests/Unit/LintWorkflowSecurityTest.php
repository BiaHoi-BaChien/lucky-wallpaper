<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class LintWorkflowSecurityTest extends TestCase
{
    private string $workflow;

    protected function setUp(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/lint.yml');

        $this->assertIsString($workflow);
        $this->workflow = $workflow;
    }

    public function test_it_only_grants_read_access_to_repository_contents(): void
    {
        $this->assertMatchesRegularExpression('/^permissions:\R  contents: read$/m', $this->workflow);
        $this->assertStringNotContainsString('contents: write', $this->workflow);
    }

    public function test_checkout_does_not_persist_the_github_token(): void
    {
        $this->assertMatchesRegularExpression(
            '/- uses: actions\/checkout@v4\R        with:\R          persist-credentials: false/',
            $this->workflow,
        );
    }

    public function test_it_installs_the_locked_npm_dependencies(): void
    {
        $this->assertStringContainsString('npm ci', $this->workflow);
        $this->assertStringNotContainsString('npm install', $this->workflow);
    }
}
