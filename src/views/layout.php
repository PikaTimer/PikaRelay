<?php
/**
 * @var string   $title
 * @var string   $content  already-rendered HTML
 * @var bool     $loggedIn
 * @var string   $csrf
 * @var array    $flash
 * @var callable $e
 * @var callable $url
 * @var callable $asset
 */
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $e($title) ?> · PikaRelay</title>
<link rel="stylesheet" href="<?= $asset('assets/app.css') ?>">
<script src="<?= $asset('assets/app.js') ?>" defer></script>
</head>
<body>
<header class="topbar">
  <a class="brand" href="<?= $url('/') ?>">PikaRelay</a>
  <?php if ($loggedIn): ?>
  <nav>
    <a href="<?= $url('/') ?>">Readers</a>
    <a href="<?= $url('/admin') ?>">Admin</a>
    <a href="<?= $url('/setPassword') ?>">Password</a>
    <form method="post" action="<?= $url('/logout') ?>" class="inline">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <button type="submit" class="link">Log out</button>
    </form>
  </nav>
  <?php endif; ?>
</header>
<main>
<?php foreach ($flash as $msg): ?>
  <div class="flash flash-<?= $e($msg['type']) ?>" role="status">
    <p><?= $e($msg['message']) ?></p>
    <?php if ($msg['type'] === 'secret'): ?>
    <dl class="secret">
      <dt>Role</dt><dd><?= $e($msg['role']) ?></dd>
      <dt>X-Pika-Key</dt><dd><code><?= $e($msg['key']) ?></code></dd>
      <dt>X-Pika-Secret</dt><dd><code><?= $e($msg['secret']) ?></code></dd>
    </dl>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
<?= $content ?>
</main>
</body>
</html>
