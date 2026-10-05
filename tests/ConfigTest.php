<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
    }

    private function writeConfig(string $php): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cahob-config-');
        file_put_contents($path, $php);
        $this->tempFiles[] = $path;
        return $path;
    }

    public function testConfigLivesOneFolderAboveTheWebRoot(): void
    {
        $this->assertSame(
            '/home/cahob/cahob-data/config.php',
            config_path(false, '/home/cahob/public_html'),
        );
    }

    public function testEnvironmentOverrideWins(): void
    {
        $this->assertSame('/tmp/dev/config.php', config_path('/tmp/dev/config.php', '/home/cahob/public_html'));
    }

    public function testEmptyOverrideIsIgnored(): void
    {
        $this->assertSame('/home/cahob/cahob-data/config.php', config_path('', '/home/cahob/public_html'));
    }

    public function testCommandLineWithoutOverrideHasNoPath(): void
    {
        $this->assertSame('', config_path(false, ''));
    }

    public function testMissingConfigMeansNotConfigured(): void
    {
        $this->expectException(NotConfiguredException::class);
        load_config(sys_get_temp_dir() . '/no-such-cahob-config.php');
    }

    public function testEmptyPathMeansNotConfigured(): void
    {
        $this->expectException(NotConfiguredException::class);
        load_config('');
    }

    public function testConfigWithoutDatabasePathIsRejected(): void
    {
        $path = $this->writeConfig("<?php return ['admin' => []];");
        $this->expectException(NotConfiguredException::class);
        load_config($path);
    }

    public function testValidConfigLoads(): void
    {
        $path = $this->writeConfig("<?php return ['db_path' => '/data/messages.sqlite'];");
        $this->assertSame(['db_path' => '/data/messages.sqlite'], load_config($path));
    }

    public function testExampleConfigHasEverySetting(): void
    {
        $config = load_config(__DIR__ . '/../lib/config.example.php');
        $this->assertStringEndsWith('messages.sqlite', $config['db_path']);
        $this->assertArrayHasKey('username', $config['admin']);
        $this->assertSame('', $config['admin']['password_hash'], 'The example must not ship a real password hash.');
        $this->assertFalse($config['debug'], 'The example must not turn on debug output.');
    }

    public function testFallbackPageEscapesDetailAndShowsBothLanguages(): void
    {
        $html = fallback_page('Messages are coming soon', '信息即將推出', '<script>x</script>');
        $this->assertStringContainsString('Messages are coming soon', $html);
        $this->assertStringContainsString('信息即將推出', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function testNotConfiguredPageRevealsNoServerPaths(): void
    {
        $html = fallback_page('Messages are coming soon', '信息即將推出');
        foreach (['cahob-data', 'config.php', 'public_html', '/home/'] as $secret) {
            $this->assertStringNotContainsString($secret, $html);
        }
    }
}
