<?php
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
