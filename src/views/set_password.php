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
 * @var bool     $recovery
 * @var string   $username
 * @var string[] $errors
 * @var string   $csrf
 * @var callable $e
 * @var callable $url
 */
?>
<section class="card narrow">
  <h1><?= $recovery ? 'Set a new admin password' : 'Change admin password' ?></h1>
  <?php if ($recovery): ?>
  <p class="notice">The config file has no admin password. Choose a user name and password to finish recovery.</p>
  <?php endif; ?>
  <?php foreach ($errors as $error): ?>
  <p class="error" role="alert"><?= $e($error) ?></p>
  <?php endforeach; ?>
  <form method="post" action="<?= $url('/setPassword') ?>" class="stack">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <?php if (!$recovery): ?>
    <label>Current password
      <input type="password" name="current" autocomplete="current-password" required>
    </label>
    <?php endif; ?>
    <label>User name
      <input type="text" name="username" value="<?= $e($username) ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required>
    </label>
    <label>New password
      <input type="password" name="password" autocomplete="new-password" minlength="8" required>
    </label>
    <label>New password again
      <input type="password" name="password2" autocomplete="new-password" minlength="8" required>
    </label>
    <button type="submit">Save password</button>
  </form>
</section>
