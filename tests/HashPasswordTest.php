<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/tools/hash_password.php';

final class HashPasswordTest extends TestCase
{
    public function testReplacesOnlyThePasswordHash(): void
    {
        $example = file_get_contents(__DIR__ . '/../lib/config.example.php');
        $hash = password_hash('a long enough password', PASSWORD_BCRYPT);

        $updated = with_password_hash($example, $hash);

        $this->assertStringContainsString("'password_hash' => '$hash'", $updated);
        $this->assertSame($example, str_replace($hash, '', $updated), 'Nothing else in the file changes.');
    }

    public function testUpdatedConfigStillLoads(): void
    {
        $hash = password_hash('a long enough password', PASSWORD_BCRYPT);
        $path = tempnam(sys_get_temp_dir(), 'cahob-config-');
        file_put_contents($path, with_password_hash(file_get_contents(__DIR__ . '/../lib/config.example.php'), $hash));
        try {
            $this->assertTrue(password_verify('a long enough password', load_config($path)['admin']['password_hash']));
        } finally {
            unlink($path);
        }
    }

    public function testRefusesAConfigWithoutAPasswordLine(): void
    {
        $this->expectException(RuntimeException::class);
        with_password_hash("<?php return ['db_path' => 'x'];", 'hash');
    }
}
