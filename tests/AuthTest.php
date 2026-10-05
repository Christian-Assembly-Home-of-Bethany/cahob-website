<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/auth.php';

final class AuthTest extends TestCase
{
    private PDO $pdo;
    private array $admin;
    private int $now;

    protected function setUp(): void
    {
        $this->pdo = db_open(':memory:');
        db_migrate($this->pdo);
        $this->admin = ['username' => 'pastor', 'password_hash' => password_hash('correct horse battery', PASSWORD_BCRYPT)];
        $this->now = strtotime('2026-10-04 12:00:00 UTC');
    }

    private function failAt(int $secondsAgo, string $ip = '203.0.113.5'): void
    {
        record_failed_login($this->pdo, $ip, $this->now - $secondsAgo);
    }

    public function testRightUsernameAndPasswordLogIn(): void
    {
        $this->assertTrue(credentials_valid($this->admin, 'pastor', 'correct horse battery'));
    }

    public function testWrongPasswordOrUsernameIsRejected(): void
    {
        $this->assertFalse(credentials_valid($this->admin, 'pastor', 'wrong'));
        $this->assertFalse(credentials_valid($this->admin, 'Pastor', 'correct horse battery'));
        $this->assertFalse(credentials_valid($this->admin, '', ''));
    }

    public function testNobodyCanLogInBeforeAPasswordIsSetUp(): void
    {
        $this->assertFalse(credentials_valid(['username' => 'pastor', 'password_hash' => ''], 'pastor', ''));
        $this->assertFalse(credentials_valid([], '', ''));
    }

    public function testTheDummyHashIsARealBcryptHash(): void
    {
        $this->assertSame('bcrypt', password_get_info(DUMMY_HASH)['algoName']);
    }

    public function testSessionsEndAfterTwoIdleHours(): void
    {
        $this->assertFalse(session_expired($this->now - 7200, $this->now));
        $this->assertTrue(session_expired($this->now - 7201, $this->now));
    }

    public function testFormTokenMustMatchExactly(): void
    {
        $this->assertTrue(csrf_valid('abc123', 'abc123'));
        $this->assertFalse(csrf_valid('abc123', 'abc124'));
        $this->assertFalse(csrf_valid('abc123', null));
        $this->assertFalse(csrf_valid('abc123', ['abc123']));
        $this->assertFalse(csrf_valid('', ''), 'A missing session token never matches.');
    }

    public function testFourFailuresDoNotLockOut(): void
    {
        foreach ([600, 400, 200, 10] as $ago) {
            $this->failAt($ago);
        }
        $this->assertSame(0, lockout_remaining($this->pdo, '203.0.113.5', $this->now));
    }

    public function testFiveFailuresInFifteenMinutesLockOut(): void
    {
        foreach ([60, 50, 40, 30, 20] as $ago) {
            $this->failAt($ago);
        }
        // Unlocks 15 minutes after the oldest of those five.
        $this->assertSame(15 * 60 - 60, lockout_remaining($this->pdo, '203.0.113.5', $this->now));
    }

    public function testOldFailuresDoNotCount(): void
    {
        foreach ([3600, 1000, 950, 60, 10] as $ago) {
            $this->failAt($ago);
        }
        $this->assertSame(0, lockout_remaining($this->pdo, '203.0.113.5', $this->now));
    }

    public function testLockoutIsPerAddress(): void
    {
        foreach ([60, 50, 40, 30, 20] as $ago) {
            $this->failAt($ago, '198.51.100.7');
        }
        $this->assertGreaterThan(0, lockout_remaining($this->pdo, '198.51.100.7', $this->now));
        $this->assertSame(0, lockout_remaining($this->pdo, '203.0.113.5', $this->now), "Someone else's failures can't lock the pastor out.");
    }

    public function testSuccessfulLoginClearsFailures(): void
    {
        foreach ([60, 50, 40, 30, 20] as $ago) {
            $this->failAt($ago);
        }
        clear_failed_logins($this->pdo, '203.0.113.5');
        $this->assertSame(0, lockout_remaining($this->pdo, '203.0.113.5', $this->now));
    }

    public function testSqlInAnAddressIsStoredAsPlainText(): void
    {
        $attack = "1.2.3.4'); DROP TABLE login_attempts; --";
        record_failed_login($this->pdo, $attack, $this->now);
        $this->assertSame($attack, $this->pdo->query('SELECT ip FROM login_attempts')->fetchColumn());
        $this->assertSame(0, lockout_remaining($this->pdo, $attack, $this->now));
    }

    public function testFailureReasonSaysWhichPartWasWrong(): void
    {
        $this->assertSame('wrong password', failure_reason($this->admin, 'pastor'));
        $this->assertSame('unknown username', failure_reason($this->admin, 'root'));
    }

    public function testSecurityLogLineIsOneReadableLine(): void
    {
        $line = security_log_line('LOCKED_OUT', '203.0.113.5', '5 failed logins in 15 minutes', $this->now, "Bot\r\n2026-01-01 FAKE \"quoted\"");
        $this->assertSame(
            "2026-10-04 05:00:00 PDT  LOCKED_OUT    203.0.113.5      5 failed logins in 15 minutes  \"Bot 2026-01-01 FAKE quoted\"\n",
            $line,
        );
    }

    public function testSecurityLogKeepsOneOlderFileWhenItGetsBig(): void
    {
        $dir = sys_get_temp_dir() . '/cahob-logs-' . bin2hex(random_bytes(4));
        try {
            write_security_log($dir, "first\n", 10);
            write_security_log($dir, "second line\n", 10);   // file is now over 10 bytes
            write_security_log($dir, "third\n", 10);         // so this starts a new one
            $this->assertSame("third\n", file_get_contents("$dir/security.log"));
            $this->assertSame("first\nsecond line\n", file_get_contents("$dir/security.log.1"));
        } finally {
            array_map('unlink', glob("$dir/*"));
            rmdir($dir);
        }
    }

    public function testLanguageSwitchOnlyReturnsToAdminPages(): void
    {
        $this->assertSame('/admin/login.php?expired=1', safe_admin_path('/admin/login.php?expired=1'));
        $this->assertSame('/admin/', safe_admin_path('/admin/'));
        foreach (['https://evil.example/', '//evil.example/admin/', '/messages.php', '/admin/../index.html', "/admin/\r\nSet-Cookie: x", '/admin\\..\\x', null, ['x']] as $bad) {
            $this->assertSame('/admin/', safe_admin_path($bad), var_export($bad, true));
        }
    }

    public function testFailuresOlderThanADayAreDeleted(): void
    {
        $this->failAt(2 * 86400);
        $this->failAt(10);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn());
    }
}
