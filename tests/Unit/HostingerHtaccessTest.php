<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class HostingerHtaccessTest extends TestCase
{
    public function test_it_routes_the_deployment_root_before_existing_directory_checks(): void
    {
        $htaccess = file_get_contents(dirname(__DIR__, 2).'/.htaccess');

        $this->assertIsString($htaccess);

        $rootRulePosition = strpos($htaccess, 'RewriteRule ^$ index.php [L]');
        $directoryCheckPosition = strpos($htaccess, 'RewriteCond %{REQUEST_FILENAME} !-d');

        $this->assertNotFalse($rootRulePosition);
        $this->assertNotFalse($directoryCheckPosition);
        $this->assertLessThan($directoryCheckPosition, $rootRulePosition);
    }
}
