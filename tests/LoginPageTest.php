<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Renders the real login page against a throwaway config and database. A successful login
 * redirects and exits, so that path is checked against the running server instead.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class LoginPageTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cahob-login-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $hash = password_hash('correct horse battery', PASSWORD_BCRYPT);
        file_put_contents(
            $this->dir . '/config.php',
            "<?php return ['db_path' => __DIR__ . '/messages.sqlite', 'admin' => ['username' => 'pastor', 'password_hash' => '$hash'], 'debug' => false];"
        );
        putenv('CAHOB_CONFIG=' . $this->dir . '/config.php');
        $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
        require_once __DIR__ . '/../lib/auth.php';
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        foreach (array_merge(glob($this->dir . '/sessions/*') ?: [], glob($this->dir . '/logs/*') ?: [], glob($this->dir . '/*')) as $path) {
            is_dir($path) ? @rmdir($path) : @unlink($path);
        }
        @rmdir($this->dir);
    }

    private function post(array $fields, bool $validToken = true): string
    {
        start_admin_session();
        $_SESSION['csrf'] = 'test-token';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $fields + ['csrf' => $validToken ? 'test-token' : 'stale'];
        return $this->render();
    }

    private function render(): string
    {
        $_SERVER['REQUEST_METHOD'] ??= 'GET';
        ob_start();
        require __DIR__ . '/../admin/login.php';
        return ob_get_clean();
    }

    private function securityLog(): string
    {
        return (string) @file_get_contents($this->dir . '/logs/security.log');
    }

    public function testLoginFormHasATokenAndIsNotIndexed(): void
    {
        $html = $this->render();
        $this->assertMatchesRegularExpression('#<input type="hidden" name="csrf" value="[0-9a-f]{64}" />#', $html);
        $this->assertStringContainsString('autocomplete="current-password"', $html);
        $this->assertStringContainsString('noindex', $html);
        $this->assertStringNotContainsString('Log out', $html);
    }

    public function testAdminPagesAreChineseByDefaultWithAnEnglishButton(): void
    {
        $_SERVER['REQUEST_URI'] = '/admin/login.php?expired=1';
        $html = $this->render();
        $this->assertStringContainsString('<html lang="zh-Hant">', $html);
        $this->assertStringContainsString('<label for="username">用戶名</label>', $html);
        $this->assertStringContainsString('href="/admin/language.php?to=en&amp;back=%2Fadmin%2Flogin.php%3Fexpired%3D1" class="admin-lang" lang="en"', $html);
        $this->assertStringContainsString('>English</a>', $html);
    }

    public function testEnglishButtonTurnsEverythingEnglish(): void
    {
        $_COOKIE['cahob_admin_lang'] = 'en';
        $html = $this->post(['username' => 'pastor', 'password' => 'wrong']);
        $this->assertStringContainsString('<html lang="en">', $html);
        $this->assertStringContainsString('Error: Wrong password or username', $html);
        $this->assertStringContainsString('<label for="password">Password</label>', $html);
        $this->assertStringContainsString('data-hide-label="Hide password"', $html);
        $this->assertStringContainsString('>中文</a>', $html);
        $this->assertStringNotContainsString('錯誤', $html);
    }

    public function testPasswordHasAShowButtonThatStartsHiddenUntilScriptRuns(): void
    {
        $html = $this->render();
        $this->assertMatchesRegularExpression('#<button type="button" class="password-toggle" aria-controls="password" aria-pressed="false"[^>]* hidden>#', $html);
        $this->assertStringContainsString('<script src="/admin/admin.js', $html);
    }

    public function testWrongPasswordIsRejectedCountedAndLogged(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = "TestBrowser/1.0\nFAKE_LINE";
        $html = $this->post(['username' => 'pastor', 'password' => 'wrong']);

        $this->assertStringContainsString('錯誤：密碼或用戶名不正確', $html);
        $this->assertStringContainsString('value="pastor"', $html, 'The username is kept so only the password needs retyping.');
        $this->assertSame(1, (int) db()->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn());
        $this->assertArrayNotHasKey('admin', $_SESSION);

        $log = $this->securityLog();
        $this->assertMatchesRegularExpression('#^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d P[DS]T  LOGIN_FAILED  203\.0\.113\.5 +wrong password  "TestBrowser/1\.0 FAKE_LINE"\n$#', $log);
        $this->assertStringNotContainsString('wrong"', $log, 'The password typed is never logged.');
    }

    public function testUnknownUsernameIsLoggedWithoutTheNameTyped(): void
    {
        $this->post(['username' => 'admin', 'password' => 'guess']);
        $log = $this->securityLog();
        $this->assertStringContainsString('LOGIN_FAILED  203.0.113.5      unknown username', $log);
        $this->assertStringNotContainsString('admin', $log);
    }

    public function testFifthFailureLocksOutAndIsLogged(): void
    {
        for ($i = 0; $i < 4; $i++) {
            record_failed_login(db(), '203.0.113.5', time() - 60);
        }
        $html = $this->post(['username' => 'pastor', 'password' => 'wrong']);
        $this->assertStringContainsString('嘗試次數過多，請在 14 分鐘後再試。', $html);
        $this->assertStringContainsString('LOCKED_OUT    203.0.113.5      5 failed logins in 15 minutes; locked for 14 minutes', $this->securityLog());
    }

    public function testLockedOutAddressCannotLogInEvenWithTheRightPassword(): void
    {
        for ($i = 0; $i < 5; $i++) {
            record_failed_login(db(), '203.0.113.5', time() - 60);
        }
        $html = $this->post(['username' => 'pastor', 'password' => 'correct horse battery']);
        $this->assertStringContainsString('嘗試次數過多', $html);
        $this->assertArrayNotHasKey('admin', $_SESSION);
        $this->assertSame(5, (int) db()->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn(), 'Attempts while locked out are not counted.');
        $this->assertStringContainsString('BLOCKED       203.0.113.5      tried to log in while locked out', $this->securityLog());
    }

    public function testSqlInUsernameIsJustAWrongUsername(): void
    {
        $html = $this->post(['username' => "pastor' OR '1'='1' --", 'password' => "' OR 1=1 --"]);
        $this->assertStringContainsString('錯誤：密碼或用戶名不正確', $html);
        $this->assertArrayNotHasKey('admin', $_SESSION);
        $this->assertSame(['login_attempts', 'messages'], db()->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testStaleFormTokenIsRejected(): void
    {
        $html = $this->post(['username' => 'pastor', 'password' => 'correct horse battery'], validToken: false);
        $this->assertStringContainsString('頁面已過期', $html);
        $this->assertArrayNotHasKey('admin', $_SESSION);
    }

    public function testExpiredNoticeIsShown(): void
    {
        $_GET = ['expired' => '1'];
        $this->assertStringContainsString('閒置超過兩小時', $this->render());
    }
}
