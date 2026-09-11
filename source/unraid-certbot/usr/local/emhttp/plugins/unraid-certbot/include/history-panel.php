<?php
/**
 * unraid-certbot - 续期历史面板（标签内容，不含页面外壳与标题）
 *
 * 只被 unraid-certbot.page 的「续期历史」标签调用。
 */

/**
 * @param array $st cb_status() 的结果
 * @param string $cb 插件名（unraid-certbot）
 */
function cb_history_panel(array $st, string $cb): void
{
    if (empty($st['history'])) {
        echo '<blockquote class="inline_help cb-help-open">暂无记录。</blockquote>';
        return;
    }
?>
<table class="cb-table">
  <tr>
    <th style="width:150px">时间</th>
    <th style="width:80px">触发</th>
    <th style="width:70px">结果</th>
    <th style="width:220px">域名</th>
    <th>说明</th>
  </tr>
  <?php foreach ($st['history'] as $h): ?>
  <tr>
    <td><?=cb_e($h['time'])?></td>
    <td><?=cb_e(cb_trigger_label($h['trigger']))?></td>
    <td>
      <?php if ($h['result'] === '成功'): ?>
        <span class="cb-badge cb-ok">成功</span>
      <?php else: ?>
        <span class="cb-badge cb-fail">失败</span>
      <?php endif; ?>
    </td>
    <td style="word-break:break-all"><?=cb_e($h['domains'])?></td>
    <td><?=cb_e($h['message'])?></td>
  </tr>
  <?php endforeach; ?>
</table>
<p class="grey-text" style="font-size:12px">
  记录保存在 <code>/boot/config/plugins/<?=$cb?>/history.tsv</code>，最多保留 <?=CB_HISTORY_MAX?> 条。
</p>
<?php
}
