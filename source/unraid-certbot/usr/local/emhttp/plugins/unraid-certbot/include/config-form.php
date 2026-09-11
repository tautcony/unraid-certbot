<?php
/**
 * Unraid Certbot 的具体设置表单。
 *
 * 只被 UnraidCertbotConfig.page 通过 cb_form($cfg) 调用；不是独立页面。
 * 表单提交后由 Unraid 的 /update.php 调用 include/update.php 校验并写入配置。
 */

/**
 * 输出设置表单。$cfg 为 parse_plugin_cfg() 的结果。
 */
function cb_form(array $cfg): void
{
    $cb = 'unraid-certbot';
?>
<form markdown="1" method="POST" action="/update.php" target="progressFrame">
<input type="hidden" name="#file" value="<?=$cb?>/<?=$cb?>.cfg">
<input type="hidden" name="#include" value="/plugins/<?=$cb?>/include/update.php">

_(Cloudflare API Token)_:
: <input type="password" name="CF_API_TOKEN_NEW" id="cb-token" autocomplete="new-password"
   placeholder="<?=cb_has_token() ? '已配置，留空则保持不变' : '粘贴你的 Cloudflare API Token'?>"
   spellcheck="false" style="width:60%">
: <input type="button" value="_(显示)_" onclick="cbToggle('cb-token',this)">
: <input type="checkbox" name="CF_API_TOKEN_CLEAR" value="yes" id="cb-clear">
  <label for="cb-clear">_(清除已保存的 Token)_</label>
: <blockquote class="inline_help">
在 Cloudflare 后台 <b>My Profile → API Tokens → Create Token</b> 创建，权限选择
<b>Zone → DNS → Edit</b>，Zone Resources 选中要签发的域名。<br>
Token 以 <code>dns_cloudflare_api_token</code> 形式保存于
<code>/boot/config/plugins/<?=$cb?>/cloudflare.ini</code>，文件权限 600，不写入插件配置，也不会出现在日志中。
</blockquote>

_(邮箱)_:
: <input type="email" name="ACME_EMAIL" value="<?=cb_e($cfg['ACME_EMAIL'])?>" style="width:40%">
: <blockquote class="inline_help">Let's Encrypt 用于发送证书到期提醒，建议填写真实可收信的地址。</blockquote>

_(Unraid 主机名)_:
: <input type="text" name="UNRAID_HOSTNAME" value="<?=cb_e($cfg['UNRAID_HOSTNAME'])?>" style="width:40%">
: <blockquote class="inline_help">
必须与 Unraid 自身的服务器名一致。证书将写入
<code>/boot/config/ssl/certs/<b>主机名</b>_unraid_bundle.pem</code>，文件名不一致时 webGUI 不会加载新证书。<br>
Unraid 当前识别的名称是 <code><?=cb_e(cb_unraid_name())?></code>。
</blockquote>

_(域名列表)_:
: <textarea name="DOMAINS" rows="4" style="width:60%" spellcheck="false"><?=cb_e($cfg['DOMAINS'])?></textarea>
: <blockquote class="inline_help">
以逗号或换行分隔，例如 <code>example.com, www.example.com</code>。
<b>第一个域名为主域名</b>，证书目录名与合并生成的 bundle 均以它为准；所有域名必须位于同一个 Cloudflare 账号下。<br>
需要通配符证书时填写 <code>*.example.com</code>（DNS-01 验证支持通配符，这也是选择 Cloudflare 而非 HTTP 验证的主要原因）。
</blockquote>

_(DNS 传播等待秒数)_:
: <input type="number" name="PROPAGATION" value="<?=cb_e($cfg['PROPAGATION'])?>" min="10" max="900" style="width:100px">
: <blockquote class="inline_help">
certbot 创建 DNS 记录后等待多久再向 Let's Encrypt 确认，默认 60 秒。若经常验证失败，可调整至 60–120。
</blockquote>

_(证书存储目录)_:
: <input type="text" name="CERT_DIR" value="<?=cb_e($cfg['CERT_DIR'])?>" style="width:60%">
: <blockquote class="inline_help">
certbot 的账号、证书与续期配置均存放于此，默认 <code>/boot/config/letsencrypt</code>。
存放于 flash 的好处是<b>阵列未启动时也能续期</b>，而 webGUI 故障时往往正是这种情况。<br>
改为 <code>/mnt/user/appdata/letsencrypt</code> 可减少 U 盘写入，但必须先启动阵列。
</blockquote>

_(自动续期频率)_:
: <select name="SCHEDULE">
  <?=mk_option($cfg['SCHEDULE'], 'daily',   _('每天检查一次（推荐）'))?>
  <?=mk_option($cfg['SCHEDULE'], 'weekly',  _('每周检查一次'))?>
  <?=mk_option($cfg['SCHEDULE'], 'monthly', _('每月检查一次'))?>
  <?=mk_option($cfg['SCHEDULE'], 'off',     _('关闭自动续期'))?>
  </select>
: <blockquote class="inline_help">
该项仅控制检查频率，不代表续期频率。certbot 会自行判断剩余有效期是否小于 30 天，未到期则直接跳过。
</blockquote>

_(阵列启动后自动检查)_:
: <input type="hidden" name="RUN_AT_BOOT" value="no">
  <input type="checkbox" name="RUN_AT_BOOT" value="yes" id="cb-boot" <?=cb_bool($cfg['RUN_AT_BOOT']) ? 'checked' : ''?>>
  <label for="cb-boot">_(阵列启动完成后延迟 90 秒执行一次检查)_</label>
: <blockquote class="inline_help">
默认关闭。适用于证书过期导致 webGUI 报警、重启后需自动恢复的场景；通常情况下定时任务已足够。
</blockquote>

_(更新后重启 nginx)_:
: <input type="hidden" name="RESTART_NGINX" value="no">
  <input type="checkbox" name="RESTART_NGINX" value="yes" id="cb-restart" <?=cb_bool($cfg['RESTART_NGINX']) ? 'checked' : ''?>>
  <label for="cb-restart">_(证书变化后自动重启 Unraid Web 管理服务)_</label>
: <blockquote class="inline_help">
证书内容未变化时不重启（脚本会比较合并结果）。重启会导致当前 webGUI 会话中断数秒。
</blockquote>

_(测试环境)_:
: <input type="hidden" name="STAGING" value="no">
  <input type="checkbox" name="STAGING" value="yes" id="cb-staging" <?=cb_bool($cfg['STAGING']) ? 'checked' : ''?>>
  <label for="cb-staging">_(使用 Let's Encrypt 测试环境)_</label>
: <blockquote class="inline_help">
测试环境的速率限制宽松得多，适用于首次验证配置，但<b>签发的证书不受浏览器信任</b>。
流程验证通过后取消勾选，再执行一次「强制续期」获取正式证书。
</blockquote>

: <input type="submit" name="#apply" value="_(应用)_" disabled>

</form>
<?php
}
