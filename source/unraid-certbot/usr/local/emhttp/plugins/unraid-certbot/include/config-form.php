<?php
/**
 * unraid-certbot - 设置表单
 *
 * 只被 unraid-certbot.page 的「设置」标签调用，不是独立页面。
 *
 * 这里全部用原生 HTML 写死 <dl>/<dt>/<dd> 与 input，不依赖 Unraid 的 Markdown
 * 渲染：早期版本写成 `_(标签)_:` 的定义列表，在真机上没被渲染成表单，整页样式塌掉。
 * 文案走 Unraid 自己的 _() 翻译，和核心设置页一致。
 *
 * 提交后由 Unraid 的 /update.php 调用 include/update.php 校验并写入配置。
 */

/**
 * @param array $cfg  parse_plugin_cfg() 的结果
 * @param array $fail 需要提示的缺失项
 */
function cb_form(array $cfg, array $fail = []): void
{
    $cb    = 'unraid-certbot';
    $token = cb_has_token();
?>
<?php if ($fail): ?>
<blockquote class="inline_help cb-help-open">
<b><?=_('待配置项')?>：<?=cb_e(implode('、', $fail))?></b><br>
<?=_('填写后点「应用」。首次使用建议先启用「测试环境」验证流程。')?>
</blockquote>
<?php endif; ?>

<form method="POST" action="/update.php" target="progressFrame">
<input type="hidden" name="#file" value="<?=$cb?>/<?=$cb?>.cfg">
<input type="hidden" name="#include" value="/plugins/<?=$cb?>/include/update.php">

<dl>
  <dt><?=_('Cloudflare API Token')?></dt>
  <dd>
    <input type="password" name="CF_API_TOKEN_NEW" id="cb-token" autocomplete="new-password"
           spellcheck="false" placeholder="<?=$token ? _('已配置，留空则保持不变') : _('Cloudflare API Token')?>">
    <input type="button" class="cb-inline-btn" value="<?=_('显示')?>" onclick="cbToggle('cb-token',this)">
    <br>
    <label><input type="checkbox" name="CF_API_TOKEN_CLEAR" value="yes"> <?=_('清除已保存的 Token')?></label>
    <blockquote class="inline_help">
      <?=_('在 Cloudflare 后台 My Profile → API Tokens → Create Token 创建，权限选择 Zone → DNS → Edit，Zone Resources 选中要签发的域名。')?><br>
      <?=_('保存于')?> <code>/boot/config/plugins/<?=$cb?>/cloudflare.ini</code><?=_('，权限 600。')?>
    </blockquote>
  </dd>

  <dt><?=_('邮箱')?></dt>
  <dd>
    <input type="email" name="ACME_EMAIL" value="<?=cb_e($cfg['ACME_EMAIL'])?>">
    <blockquote class="inline_help">
      <?=_("接收 Let's Encrypt 证书到期提醒。")?>
    </blockquote>
  </dd>

  <dt><?=_('Unraid 主机名')?></dt>
  <dd>
    <input type="text" name="UNRAID_HOSTNAME" value="<?=cb_e($cfg['UNRAID_HOSTNAME'])?>">
    <blockquote class="inline_help">
      <?=_('必须与 Unraid 服务器名称一致，否则 webGUI 不会加载新证书。当前名称：')?>
      <code><?=cb_e(cb_unraid_name())?></code>。
    </blockquote>
  </dd>

  <dt><?=_('域名列表')?></dt>
  <dd>
    <textarea name="DOMAINS" rows="4" spellcheck="false"><?=cb_e($cfg['DOMAINS'])?></textarea>
    <blockquote class="inline_help">
      <?=_('以逗号或换行分隔，例如 example.com, www.example.com。')?>
      <b><?=_('第一个域名为主域名')?></b><?=_('。支持通配符，如 *.example.com。')?>
    </blockquote>
  </dd>

  <dt><?=_('DNS 传播等待秒数')?></dt>
  <dd>
    <input type="number" name="PROPAGATION" class="narrow" min="10" max="900" value="<?=cb_e($cfg['PROPAGATION'])?>">
    <blockquote class="inline_help">
      <?=_("DNS 记录创建后的等待时间，默认 60 秒。验证经常失败时可提高至 60–120。")?>
    </blockquote>
  </dd>

  <dt><?=_('证书存储目录')?></dt>
  <dd>
    <input type="text" name="CERT_DIR" value="<?=cb_e($cfg['CERT_DIR'])?>">
    <blockquote class="inline_help">
      <?=_('certbot 账号、证书与续期配置的存储位置，推荐 /mnt/user/appdata/letsencrypt（需先启动阵列）。')?><br>
      <b><?=_('必须位于支持符号链接的文件系统')?></b><?=_('，FAT/exFAT 不支持，会导致续期失败。')?>
    </blockquote>
  </dd>

  <dt><?=_('自动续期频率')?></dt>
  <dd>
    <select name="SCHEDULE">
      <?=mk_option($cfg['SCHEDULE'], 'daily',   _('每天检查一次（推荐）'))?>
      <?=mk_option($cfg['SCHEDULE'], 'weekly',  _('每周检查一次'))?>
      <?=mk_option($cfg['SCHEDULE'], 'monthly', _('每月检查一次'))?>
      <?=mk_option($cfg['SCHEDULE'], 'off',     _('关闭自动续期'))?>
    </select>
    <blockquote class="inline_help">
      <?=_('检查频率。证书到期前 30 天内才会实际续期。')?>
    </blockquote>
  </dd>

  <dt><?=_('阵列启动后自动检查')?></dt>
  <dd>
    <input type="hidden" name="RUN_AT_BOOT" value="no">
    <label><input type="checkbox" name="RUN_AT_BOOT" value="yes"<?=cb_bool($cfg['RUN_AT_BOOT']) ? ' checked' : ''?>> <?=_('阵列启动完成后延迟 90 秒执行一次检查')?></label>
    <blockquote class="inline_help">
      <?=_('默认关闭。定时任务通常已足够。')?>
    </blockquote>
  </dd>

  <dt><?=_('更新后重启 nginx')?></dt>
  <dd>
    <input type="hidden" name="RESTART_NGINX" value="no">
    <label><input type="checkbox" name="RESTART_NGINX" value="yes"<?=cb_bool($cfg['RESTART_NGINX']) ? ' checked' : ''?>> <?=_('证书变化后自动重启 Unraid Web 管理服务')?></label>
    <blockquote class="inline_help">
      <?=_('证书未变化时不重启。重启会中断当前 webGUI 会话数秒。')?>
    </blockquote>
  </dd>

  <dt><?=_('测试环境')?></dt>
  <dd>
    <input type="hidden" name="STAGING" value="no">
    <label><input type="checkbox" name="STAGING" value="yes"<?=cb_bool($cfg['STAGING']) ? ' checked' : ''?>> <?=_("使用 Let's Encrypt 测试环境")?></label>
    <blockquote class="inline_help">
      <?=_("使用 Let's Encrypt 测试环境，速率限制宽松，适用于验证配置；签发的证书不受浏览器信任。")?>
      <?=_('验证通过后取消勾选，执行「强制续期」获取正式证书。')?>
    </blockquote>
  </dd>
</dl>

<p><input type="submit" name="#apply" value="<?=_('应用')?>" disabled></p>
</form>
<?php
}
