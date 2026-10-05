<?php
// Admin home. For now it only confirms the login works; the message dashboard replaces it
// in the next step.

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/render.php';

$admin = require_admin();

admin_header(at('dashboard_title'), $admin);
?>
      <section class="admin-card">
        <h1><?= e(at('welcome', $admin)) ?></h1>
        <p class="admin-intro"><?= at('placeholder') ?></p>
        <a href="<?= admin_lang() === 'zh' ? '/messages-zh.php' : '/messages.php' ?>" class="explore-link"><?= at('view_messages') ?> <span aria-hidden="true">&rarr;</span></a>
      </section>
<?php
admin_footer();
