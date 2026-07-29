<?php

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class FrontendSubdirectoryRoutingTest extends TestCase
{
    public function test_frontend_application_routes_are_not_hardcoded_from_the_domain_root(): void
    {
        $resources = dirname(__DIR__, 2).'/resources/js';
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($resources, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (! in_array($file->getExtension(), ['ts', 'tsx'], true)) {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            $this->assertIsString($source);
            $this->assertDoesNotMatchRegularExpression(
                '~[\'"`]/(?:confirm-password|dashboard|login|logout|notion-syncs|operations|passkeys|results|settings|setup|storage|user/passkeys|wallpapers)(?:[/?\'"`$])~',
                $source,
                $file->getPathname().' contains a domain-root application URL.',
            );
        }
    }
}
