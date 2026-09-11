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
        'ok'      => ['green-text',  '证书正常'],
        'soon'    => ['orange-text', '即将到期'],
        'expired' => ['red-text',    '已过期'],
        'unknown' => ['grey-text',   '尚未签发'],
    ][$st['health']];
?>
<?php if (empty($st['fs_ok'])): ?>
<blockquote class="inline_help" style="display:block">
<b><?=_('证书目录不可用，续期将失败')?></b><br>
<?=cb_e((string)$st['fs_reason'])?><br>
<span class="grey-text">
  <?=_('当前目录')?>：<code><?=cb_e((string)$st['cert_dir'])?></code>
  <?php if (!empty($st['fs_summary'])): ?>（<?=cb_e((string)$st['fs_summary'])?>）<?php endif; ?>
  <?=_('请到「设置」改用 /mnt/user/appdata/letsencrypt（需先启动阵列）。')?>
</span>
</blockquote>
<?php endif; ?>

<div class="cb-actions" style="margin-bottom:1rem">
  <input type="button" value="<?=_('立即检查并续期')?>" onclick="cbRun('renew')">
  <input type="button" value="<?=_('强制续期')?>" onclick="cbRun('force')">
  <input type="button" value="<?=_('检查 Docker 环境')?>" onclick="cbRun('docker')">
</div>

<p>
  整体状态：<span class="<?=$health[0]?>"><b><?=$health[1]?></b></span>
  &nbsp;·&nbsp; 剩余有效期：<?=cb_days_html($st['days'])?>
  <?php if ($st['staging']): ?>
    &nbsp;·&nbsp; <span class="orange-text"><b>当前配置为测试环境，证书不被信任</b></span>
  <?php endif; ?>
</p>

<?php if ($st['bundle']): ?>
<h3>WebGUI 正在使用的证书</h3>
<table class="cb-table">
  <tr><th>文件</th><td><code><?=cb_e($st['bundle_path'])?></code></td></tr>
  <tr><th>最后写入</th><td><?=$st['bundle_mtime'] ? date('Y-m-d H:i:s', $st['bundle_mtime']) : '未知'?>
      &nbsp;<span class="grey-text">(<?=cb_e(cb_ago($st['bundle_mtime']))?>)</span></td></tr>
  <tr><th>主体 (CN)</th><td><?=cb_e($st['bundle']['subject'])?></td></tr>
  <tr><th>颁发者</th><td><?=cb_e($st['bundle']['issuer'])?></td></tr>
  <tr><th>生效时间</th><td><?=date('Y-m-d H:i:s', $st['bundle']['from'])?></td></tr>
  <tr><th>到期时间</th><td><?=date('Y-m-d H:i:s', $st['bundle']['to'])?>
      &nbsp;(<?=cb_days_html($st['bundle']['days'])?>)</td></tr>
  <tr><th>覆盖域名</th><td><?=cb_e(implode(', ', $st['bundle']['sans']))?></td></tr>
</table>
<?php else: ?>
<blockquote class="inline_help" style="display:block">
<b>尚未签发证书。</b>
<?php if (!$st['bundle_path']): ?>
先在 <a href="/Settings/UnraidCertbot?tab=config">设置</a> 里填写 Unraid 主机名。
<?php else: ?>
执行「立即检查并续期」以签发证书。
<?php endif; ?>
</blockquote>
<?php endif; ?>

<h3>certbot 源证书</h3>
<?php if ($st['live']): ?>
<table class="cb-table">
  <tr><th>文件</th><td><code><?=cb_e($st['live_path'])?></code></td></tr>
  <tr><th>颁发者</th><td><?=cb_e($st['live']['issuer'])?></td></tr>
  <tr><th>到期时间</th><td><?=date('Y-m-d H:i:s', $st['live']['to'])?>
      &nbsp;(<?=cb_days_html($st['live']['days'])?>)</td></tr>
  <tr><th>覆盖域名</th><td><?=cb_e(implode(', ', $st['live']['sans']))?></td></tr>
</table>
<?php else: ?>
<blockquote class="inline_help">尚未签发。</blockquote>
<?php endif; ?>

<h3>运行环境</h3>
<table class="cb-table">
  <tr><th>Docker 服务</th><td>
      <?php if ($st['docker_ok']): ?><span class="green-text">可用</span>
      <?php else: ?><span class="red-text">不可用（通常为阵列未启动）</span><?php endif; ?>
  </td></tr>
  <tr><th>certbot 镜像</th><td>
      <?php if ($st['image_ok']): ?><span class="green-text">已存在</span>
      <?php else: ?><span class="grey-text">未拉取（首次运行时自动拉取）</span><?php endif; ?>
  </td></tr>
  <tr><th>证书目录</th><td>
      <code><?=cb_e($st['cert_dir'])?></code>
      <?php if (empty($st['fs_ok'])): ?>
        <br><span class="red-text"><?=_('该目录不可用')?>：<?=cb_e((string)$st['fs_reason'])?></span>
      <?php elseif (!empty($st['fs_summary'])): ?>
        <br><span class="grey-text"><?=cb_e((string)$st['fs_summary'])?></span>
      <?php endif; ?>
  </td></tr>
  <tr><th>DNS 传播等待</th><td><?=$st['propagation']?> 秒</td></tr>
  <tr><th>自动续期</th><td><?=cb_e(cb_schedule_label($st['schedule']))?></td></tr>
  <tr><th>重启 nginx</th><td><?=$st['restart_nginx'] ? '是' : '否'?></td></tr>
  <tr><th>上次运行</th><td>
      <?php if ($st['last_run']): ?>
        <?=cb_e($st['last_run']['time'])?>
        （<?=cb_e(cb_trigger_label($st['last_run']['trigger']))?>）
        <?php if ($st['last_run']['result'] === '成功'): ?>
          <span class="cb-badge cb-ok">成功</span>
        <?php else: ?>
          <span class="cb-badge cb-fail">失败</span>
        <?php endif; ?>
        <?=cb_e($st['last_run']['message'])?>
      <?php else: ?>
        <span class="grey-text">无记录</span>
      <?php endif; ?>
  </td></tr>
  <tr><th>上次成功续期</th><td>
      <?= $st['last_ok'] ? cb_e($st['last_ok']['time']) . ' (' . cb_e(cb_ago(strtotime($st['last_ok']['time']) ?: null)) . ')' : '<span class="grey-text">无记录</span>' ?>
  </td></tr>
</table>
<?php
}
