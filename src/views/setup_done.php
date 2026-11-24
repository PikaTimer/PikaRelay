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
 * @var bool     $secure
 * @var callable $url
 */
?>
<section class="card narrow">
  <h1>Setup complete</h1>
  <p>The configuration file has been written and the setup page is now disabled.</p>
  <?php if ($secure): ?>
  <p>Secure mode is on. Log in, open <strong>Admin</strong> and create API keys for your readers and PikaDownloader clients.</p>
  <?php else: ?>
  <p>Insecure mode is on: anyone who knows this address can post and read data. You can switch to secure mode on the Admin page.</p>
  <?php endif; ?>
  <p><a class="button" href="<?= $url('/') ?>">Go to the admin login</a></p>
</section>
