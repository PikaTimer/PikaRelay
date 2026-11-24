<?php
/*
 * Copyright (C) 2026 John Garner
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

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
