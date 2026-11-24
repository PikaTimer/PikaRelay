<?php
/**
 * @var string   $error
 * @var bool     $recovery
 * @var string   $csrf
 * @var callable $e
 * @var callable $url
 */
?>
<section class="card narrow">
  <h1>Admin log in</h1>
  <?php if ($recovery): ?>
  <p class="notice">Password recovery is active: the config file has no admin password. Log in with a blank password, then set a new one.</p>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
  <p class="error" role="alert"><?= $e($error) ?></p>
  <?php endif; ?>
  <form method="post" action="<?= $url('/login') ?>" class="stack">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label>User name
      <input type="text" name="username" autocomplete="username" autocapitalize="none" spellcheck="false" <?= $recovery ? '' : 'required' ?> autofocus>
    </label>
    <label>Password
      <input type="password" name="password" autocomplete="current-password" <?= $recovery ? '' : 'required' ?>>
    </label>
    <button type="submit">Log in</button>
  </form>
</section>
