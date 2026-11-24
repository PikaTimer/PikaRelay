<?php
/**
 * @var array    $readers
 * @var int      $now
 * @var callable $e
 * @var callable $time
 * @var callable $ago
 */
?>
<section data-autorefresh="15">
  <div class="heading-row">
    <h1>Readers</h1>
    <p class="muted">Refreshes every 15 seconds. Status dots: green under 20s, yellow under 2 minutes.</p>
  </div>
  <?php if (!$readers): ?>
  <p class="card">No reader has contacted this relay yet. Readers appear here after their first status update or read upload.</p>
  <?php else: ?>
  <div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th>Reader</th>
        <th>Last status</th>
        <th>Reading</th>
        <th>Profile</th>
        <th class="num">Battery</th>
        <th class="num">Total reads</th>
        <th>Newest read</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($readers as $r): ?>
      <tr>
        <td>
          <strong><?= $e($r['id']) ?></strong>
          <?php if ($r['name'] !== $r['id']): ?><br><span class="muted"><?= $e($r['name']) ?></span><?php endif; ?>
        </td>
        <td>
          <span class="dot dot-<?= $e($r['health']) ?>" aria-hidden="true"></span>
          <span class="sr-only"><?= $e($r['health']) ?>:</span>
          <?= $e($ago($r['statusMs'])) ?>
          <?php if ($r['statusMs'] !== null): ?><br><span class="muted small"><?= $time($r['statusMs']) ?></span><?php endif; ?>
        </td>
        <td><?= $r['reading'] === null ? '<span class="muted">?</span>' : ($r['reading'] ? 'Yes' : 'No') ?></td>
        <td><?= $r['profile'] === null ? '<span class="muted">-</span>' : $e($r['profile']) ?></td>
        <td class="num"><?= $r['battery'] === null ? '<span class="muted">-</span>' : $e(round((float) $r['battery'])) . '%' ?></td>
        <td class="num"><?= $r['totalReads'] === null ? '<span class="muted">-</span>' : $e(number_format($r['totalReads'])) ?></td>
        <td><?= $time($r['lastReadMs']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</section>
