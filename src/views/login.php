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
