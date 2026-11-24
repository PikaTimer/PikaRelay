<?php
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
