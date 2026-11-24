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
 * @var int      $status
 * @var string   $message
 * @var callable $e
 * @var callable $url
 */
?>
<section class="card narrow">
  <h1>Error <?= $e($status) ?></h1>
  <p><?= $e($message) ?></p>
  <p><a href="<?= $url('/') ?>">Back to PikaRelay</a></p>
</section>
