<?php
/**
 * unraid-certbot - 续期历史面板
 */

/**
 * @param string $cb 插件名（unraid-certbot）
 */
function cb_history_panel(string $cb, int $requestedPage = 1): void
{
    $history = cb_history(CB_HISTORY_MAX);
    if ($history === []) {
        echo '<blockquote class="inline_help" style="display:block">' . _('No records.') . '</blockquote>';
        return;
    }
    $pageSize = 10;
    $pageCount = (int)ceil(count($history) / $pageSize);
    $page = max(1, min($requestedPage, $pageCount));
    $records = array_slice($history, ($page - 1) * $pageSize, $pageSize);
    $visiblePages = [];
    for ($n = 1; $n <= $pageCount; $n++) {
        if ($pageCount <= 7 || $n === 1 || $n === $pageCount
            || ($page <= 4 && $n <= 5)
            || ($page >= $pageCount - 3 && $n >= $pageCount - 4)
            || abs($n - $page) <= 1) {
            $visiblePages[] = $n;
        }
    }
?>
<table class="cb-table">
  <tr>
    <th style="width:150px"><?=_('Time')?></th>
    <th style="width:80px"><?=_('Trigger')?></th>
    <th style="width:70px"><?=_('Result')?></th>
    <th style="width:220px"><?=_('Domains')?></th>
    <th><?=_('Details')?></th>
  </tr>
  <?php foreach ($records as $h): ?>
  <tr>
    <td><?=cb_e($h['time'])?></td>
    <td><?=cb_e(cb_trigger_label($h['trigger']))?></td>
    <td>
      <?php if (in_array($h['result'], ['success', '成功'], true)): ?>
        <span class="cb-badge cb-ok"><?=_('Success')?></span>
      <?php else: ?>
        <span class="cb-badge cb-fail"><?=_('Failed')?></span>
      <?php endif; ?>
    </td>
    <td style="word-break:break-all"><?=cb_e($h['domains'])?></td>
    <td><?=cb_e($h['message'])?></td>
  </tr>
  <?php endforeach; ?>
</table>
<nav class="cb-history-pages" aria-label="<?=_('Renewal History')?>">
  <button type="button" aria-label="<?=_('Previous page')?>" title="<?=_('Previous page')?>"
          onclick="cbHistoryPage(<?=$page - 1?>)"<?=$page === 1 ? ' disabled' : ''?>>&lsaquo;</button>
  <?php $previousPage = 0; foreach ($visiblePages as $n): ?>
    <?php if ($n > $previousPage + 1): ?><span class="cb-history-ellipsis" aria-hidden="true">&hellip;</span><?php endif; ?>
    <button type="button" aria-label="<?=_('Page')?> <?=$n?>"<?=$n === $page ? ' class="active" aria-current="page"' : ''?>
            onclick="cbHistoryPage(<?=$n?>)"<?=$n === $page ? ' disabled' : ''?>><?=$n?></button>
  <?php $previousPage = $n; endforeach; ?>
  <button type="button" aria-label="<?=_('Next page')?>" title="<?=_('Next page')?>"
          onclick="cbHistoryPage(<?=$page + 1?>)"<?=$page === $pageCount ? ' disabled' : ''?>>&rsaquo;</button>
</nav>
<p class="grey-text" style="font-size:12px">
  <?=_('Records are stored in')?> <code>/boot/config/plugins/<?=$cb?>/history.tsv</code><?=_(', with a maximum of')?> <?=CB_HISTORY_MAX?> <?=_('entries.')?>
</p>
<?php
}
