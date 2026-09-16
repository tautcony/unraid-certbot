<?php
/**
 * unraid-certbot - 续期历史面板
 */

/**
 * @param array $st cb_status() 的结果
 * @param string $cb 插件名（unraid-certbot）
 */
function cb_history_panel(array $st, string $cb): void
{
    if (empty($st['history'])) {
        echo '<blockquote class="inline_help" style="display:block">' . _('No records.') . '</blockquote>';
        return;
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
  <?php foreach ($st['history'] as $h): ?>
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
<p class="grey-text" style="font-size:12px">
  <?=_('Records are stored in')?> <code>/boot/config/plugins/<?=$cb?>/history.tsv</code><?=_(', with a maximum of')?> <?=CB_HISTORY_MAX?> <?=_('entries.')?>
</p>
<?php
}
