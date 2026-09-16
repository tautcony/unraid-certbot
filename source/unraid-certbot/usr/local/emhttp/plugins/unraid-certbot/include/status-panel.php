<?php
/**
 * unraid-certbot - 证书状态面板
 */

/**
 * @param array $st cb_status() 的结果
 * @param string $cb 插件名（unraid-certbot）
 */
function cb_status_panel(array $st, string $cb): void
{
    $health = [
        'ok'      => ['green-text',  _('Certificate valid')],
        'soon'    => ['orange-text', _('Expiring soon')],
        'expired' => ['red-text',    _('Expired')],
        'unknown' => ['grey-text',   _('Not issued')],
    ][$st['health']];
?>
<?php if (empty($st['fs_ok'])): ?>
<blockquote class="inline_help" style="display:block">
<b><?=_('Certificate directory is unavailable; renewal will fail')?></b><br>
<?=cb_e((string)$st['fs_reason'])?><br>
<span class="grey-text">
  <?=_('Current directory')?>: <code><?=cb_e((string)$st['cert_dir'])?></code>
  <?php if (!empty($st['fs_summary'])): ?>（<?=cb_e((string)$st['fs_summary'])?>）<?php endif; ?>
  <?=_('Change it to /mnt/user/appdata/letsencrypt in Settings.')?>
</span>
</blockquote>
<?php endif; ?>

<div class="cb-actions" style="margin-bottom:1rem">
  <input type="button" value="<?=_('Check and renew now')?>" onclick="cbRun('renew')">
  <input type="button" value="<?=_('Force renewal')?>" onclick="cbRun('force')">
  <input type="button" value="<?=_('Check Docker environment')?>" onclick="cbRun('docker')">
</div>

<p>
  <?=_('Overall status')?>: <span class="<?=$health[0]?>"><b><?=$health[1]?></b></span>
  &nbsp;·&nbsp; <?=_('Days remaining')?>: <?=cb_days_html($st['days'])?>
  <?php if ($st['staging']): ?>
    &nbsp;·&nbsp; <span class="orange-text"><b><?=_('Staging is enabled; the certificate is not trusted')?></b></span>
  <?php endif; ?>
</p>

<?php if ($st['bundle']): ?>
<h3><?=_('Certificate used by the webGUI')?></h3>
<table class="cb-table cb-detail-table">
  <tr><th><?=_('File')?></th><td><code><?=cb_e($st['bundle_path'])?></code></td></tr>
  <tr><th><?=_('Last written')?></th><td><?=$st['bundle_mtime'] ? date('Y-m-d H:i:s', $st['bundle_mtime']) : _('Unknown')?>
      &nbsp;<span class="grey-text">(<?=cb_e(cb_ago($st['bundle_mtime']))?>)</span></td></tr>
  <tr><th><?=_('Subject (CN)')?></th><td><?=cb_e($st['bundle']['subject'])?></td></tr>
  <tr><th><?=_('Issuer')?></th><td><?=cb_e($st['bundle']['issuer'])?></td></tr>
  <tr><th><?=_('Valid from')?></th><td><?=date('Y-m-d H:i:s', $st['bundle']['from'])?></td></tr>
  <tr><th><?=_('Expires')?></th><td><?=date('Y-m-d H:i:s', $st['bundle']['to'])?>
      &nbsp;(<?=cb_days_html($st['bundle']['days'])?>)</td></tr>
  <tr><th><?=_('Covered domains')?></th><td><?=cb_e(implode(', ', $st['bundle']['sans']))?></td></tr>
</table>
<?php else: ?>
<blockquote class="inline_help" style="display:block">
<b><?=_('No certificate has been issued.')?></b>
<?php if (!$st['bundle_path']): ?>
<?=_('Enter the Unraid hostname in')?> <a href="/Settings/UnraidCertbot?tab=config"><?=_('Settings')?></a>.
<?php else: ?>
<?=_('Run Check and renew now to issue a certificate.')?>
<?php endif; ?>
</blockquote>
<?php endif; ?>

<h3><?=_('Source certificate from certbot')?></h3>
<?php if ($st['live']): ?>
<table class="cb-table cb-detail-table">
  <tr><th><?=_('File')?></th><td><code><?=cb_e($st['live_path'])?></code></td></tr>
  <tr><th><?=_('Issuer')?></th><td><?=cb_e($st['live']['issuer'])?></td></tr>
  <tr><th><?=_('Expires')?></th><td><?=date('Y-m-d H:i:s', $st['live']['to'])?>
      &nbsp;(<?=cb_days_html($st['live']['days'])?>)</td></tr>
  <tr><th><?=_('Covered domains')?></th><td><?=cb_e(implode(', ', $st['live']['sans']))?></td></tr>
</table>
<?php else: ?>
<blockquote class="inline_help"><?=_('Not issued.')?></blockquote>
<?php endif; ?>

<h3><?=_('Runtime environment')?></h3>
<table class="cb-table cb-detail-table">
  <tr><th><?=_('Docker service')?></th><td>
      <?php if ($st['docker_ok']): ?><span class="green-text"><?=_('Available')?></span>
      <?php else: ?><span class="red-text"><?=_('Unavailable (usually because the array is stopped)')?></span><?php endif; ?>
  </td></tr>
  <tr><th><?=_('certbot image')?></th><td>
      <?php if ($st['image_ok']): ?><span class="green-text"><?=_('Present')?></span>
      <?php else: ?><span class="grey-text"><?=_('Not pulled (automatically pulled on first run)')?></span><?php endif; ?>
  </td></tr>
  <tr><th><?=_('Certificate directory')?></th><td>
      <code><?=cb_e($st['cert_dir'])?></code>
      <?php if (empty($st['fs_ok'])): ?>
        <br><span class="red-text"><?=_('This directory is unavailable')?>: <?=cb_e((string)$st['fs_reason'])?></span>
      <?php elseif (!empty($st['fs_summary'])): ?>
        <br><span class="grey-text"><?=cb_e((string)$st['fs_summary'])?></span>
      <?php endif; ?>
  </td></tr>
  <tr><th><?=_('DNS propagation wait')?></th><td><?=$st['propagation']?> <?=_('seconds')?></td></tr>
  <tr><th><?=_('Automatic renewal')?></th><td><?=cb_e(cb_schedule_label($st['schedule']))?></td></tr>
  <tr><th><?=_('Restart nginx')?></th><td><?=$st['restart_nginx'] ? _('Yes') : _('No')?></td></tr>
  <tr><th><?=_('Last run')?></th><td>
      <?php if ($st['last_run']): ?>
        <?=cb_e($st['last_run']['time'])?>
        （<?=cb_e(cb_trigger_label($st['last_run']['trigger']))?>）
        <?php if (in_array($st['last_run']['result'], ['success', '成功'], true)): ?>
          <span class="cb-badge cb-ok"><?=_('Success')?></span>
        <?php else: ?>
          <span class="cb-badge cb-fail"><?=_('Failed')?></span>
        <?php endif; ?>
        <?=cb_e($st['last_run']['message'])?>
      <?php else: ?>
        <span class="grey-text"><?=_('No records')?></span>
      <?php endif; ?>
  </td></tr>
  <tr><th><?=_('Last successful renewal')?></th><td>
      <?= $st['last_ok'] ? cb_e($st['last_ok']['time']) . ' (' . cb_e(cb_ago(strtotime($st['last_ok']['time']) ?: null)) . ')' : '<span class="grey-text">' . _('No records') . '</span>' ?>
  </td></tr>
</table>
<?php
}
