<?php
/**
 * @var array    $values
 * @var string[] $errors
 * @var array    $checks
 * @var bool     $hasSqlite
 * @var bool     $hasMysql
 * @var callable $e
 * @var callable $url
 */
$v = static function (string $key) use ($values, $e): string {
    return $e($values[$key] ?? '');
};
?>
<section class="card narrow wide-ish">
  <h1>Set up PikaRelay</h1>
  <p>This page is shown only until setup finishes. Anyone who can reach this address can complete it, so finish it now.</p>

  <h2>Server checks</h2>
  <ul class="checks">
  <?php foreach ($checks as $check): ?>
    <li class="<?= $check['ok'] ? 'ok' : ($check['fatal'] ? 'fail' : 'warn') ?>">
      <strong><?= $e($check['label']) ?>:</strong> <?= $e($check['ok'] ? 'OK' : ($check['fatal'] ? 'Problem' : 'Warning')) ?>
      <span class="muted">(<?= $e($check['detail']) ?>)</span>
    </li>
  <?php endforeach; ?>
  </ul>

  <?php foreach ($errors as $error): ?>
  <p class="error" role="alert"><?= $e($error) ?></p>
  <?php endforeach; ?>

  <form method="post" action="<?= $url('/setup') ?>" class="stack">
    <fieldset>
      <legend>Admin account</legend>
      <label>User name
        <input type="text" name="admin_user" value="<?= $v('admin_user') ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required>
      </label>
      <label>Password (at least 8 characters)
        <input type="password" name="password" autocomplete="new-password" minlength="8" required>
      </label>
      <label>Password again
        <input type="password" name="password2" autocomplete="new-password" minlength="8" required>
      </label>
    </fieldset>

    <fieldset>
      <legend>Database</legend>
      <label class="check">
        <input type="radio" name="driver" value="sqlite" <?= ($values['driver'] ?? '') === 'sqlite' ? 'checked' : '' ?> <?= $hasSqlite ? '' : 'disabled' ?>>
        SQLite (a single file, no database server needed)<?= $hasSqlite ? '' : ' (not available on this server)' ?>
      </label>
      <label>SQLite file (relative to the PikaRelay folder, or an absolute path; a folder outside the web root is safest)
        <input type="text" name="sqlite_path" value="<?= $v('sqlite_path') ?>" spellcheck="false">
      </label>
      <label class="check">
        <input type="radio" name="driver" value="mysql" <?= ($values['driver'] ?? '') === 'mysql' ? 'checked' : '' ?> <?= $hasMysql ? '' : 'disabled' ?>>
        MySQL / MariaDB<?= $hasMysql ? '' : ' (not available on this server)' ?>
      </label>
      <div class="grid-form">
        <label>Host <input type="text" name="mysql_host" value="<?= $v('mysql_host') ?>" spellcheck="false"></label>
        <label>Port <input type="number" name="mysql_port" value="<?= $v('mysql_port') ?>" min="1" max="65535"></label>
        <label>Database name <input type="text" name="mysql_name" value="<?= $v('mysql_name') ?>" spellcheck="false"></label>
        <label>User <input type="text" name="mysql_user" value="<?= $v('mysql_user') ?>" autocomplete="off" spellcheck="false"></label>
        <label>Password <input type="password" name="mysql_password" autocomplete="off"></label>
      </div>
    </fieldset>

    <fieldset>
      <legend>Security</legend>
      <label class="check">
        <input type="checkbox" name="secure_mode" value="1" <?= ($values['secure_mode'] ?? '') === '1' ? 'checked' : '' ?>>
        Secure mode: readers and PikaDownloader clients must send an API key and secret (recommended)
      </label>
    </fieldset>

    <button type="submit">Finish setup</button>
  </form>
</section>
