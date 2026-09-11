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
<b><?=_('还差几项配置')?>：<?=cb_e(implode('、', $fail))?></b><br>
<?=_('填好后点「应用」。首次使用建议先勾选「测试环境」验证流程，确认无误后取消勾选并正式签发。')?>
</blockquote>
<?php endif; ?>

<form method="POST" action="/update.php" target="progressFrame">
<input type="hidden" name="#file" value="<?=$cb?>/<?=$cb?>.cfg">
<input type="hidden" name="#include" value="/plugins/<?=$cb?>/include/update.php">

<dl>
  <dt><?=_('Cloudflare API Token')?></dt>
  <dd>
    <input type="password" name="CF_API_TOKEN_NEW" id="cb-token" autocomplete="new-password"
           spellcheck="false" placeholder="<?=$token ? _('已配置，留空则保持不变') : _('粘贴你的 Cloudflare API Token')?>">
    <input type="button" class="cb-inline-btn" value="<?=_('显示')?>" onclick="cbToggle('cb-token',this)">
    <br>
    <label><input type="checkbox" name="CF_API_TOKEN_CLEAR" value="yes"> <?=_('清除已保存的 Token')?></label>
    <blockquote class="inline_help">
      <?=_('在 Cloudflare 后台 My Profile → API Tokens → Create Token 创建，权限选择 Zone → DNS → Edit，Zone Resources 选中要签发的域名。')?><br>
      <?=_('Token 单独保存于')?> <code>/boot/config/plugins/<?=$cb?>/cloudflare.ini</code><?=_('，文件权限 600，不写入插件配置，也不会出现在日志中。')?>
    </blockquote>
  </dd>

  <dt><?=_('邮箱')?></dt>
  <dd>
    <input type="email" name="ACME_EMAIL" value="<?=cb_e($cfg['ACME_EMAIL'])?>">
    <blockquote class="inline_help">
      <?=_("Let's Encrypt 用于发送证书到期提醒，建议填写真实可收信的地址。")?>
    </blockquote>
  </dd>

  <dt><?=_('Unraid 主机名')?></dt>
  <dd>
    <input type="text" name="UNRAID_HOSTNAME" value="<?=cb_e($cfg['UNRAID_HOSTNAME'])?>">
    <blockquote class="inline_help">
      <?=_('必须与 Unraid 自身的服务器名一致。证书将写入')?>
      <code>/boot/config/ssl/certs/&lt;主机名&gt;_unraid_bundle.pem</code><?=_('，文件名不一致时 webGUI 不会加载新证书。')?><br>
      <?=_('Unraid 当前识别的名称是')?> <code><?=cb_e(cb_unraid_name())?></code>。
    </blockquote>
  </dd>

  <dt><?=_('域名列表')?></dt>
  <dd>
    <textarea name="DOMAINS" rows="4" spellcheck="false"><?=cb_e($cfg['DOMAINS'])?></textarea>
    <blockquote class="inline_help">
      <?=_('以逗号或换行分隔，例如 example.com, www.example.com。')?>
      <b><?=_('第一个域名为主域名')?></b><?=_('，证书目录名与合并生成的 bundle 均以它为准；所有域名必须位于同一个 Cloudflare 账号下。')?><br>
      <?=_('需要通配符证书时填写 *.example.com（DNS-01 验证支持通配符，这也是选择 Cloudflare 而非 HTTP 验证的主要原因）。')?>
    </blockquote>
  </dd>

  <dt><?=_('DNS 传播等待秒数')?></dt>
  <dd>
    <input type="number" name="PROPAGATION" class="narrow" min="10" max="900" value="<?=cb_e($cfg['PROPAGATION'])?>">
    <blockquote class="inline_help">
      <?=_("certbot 创建 DNS 记录后等待多久再向 Let's Encrypt 确认，默认 60 秒。若经常验证失败，可调整至 60–120。")?>
    </blockquote>
  </dd>

  <dt><?=_('证书存储目录')?></dt>
  <dd>
    <input type="text" name="CERT_DIR" value="<?=cb_e($cfg['CERT_DIR'])?>">
    <blockquote class="inline_help">
      <?=_('certbot 的账号、证书与续期配置均存放于此，默认 /boot/config/letsencrypt。')?>
      <?=_('存放于 flash 的好处是阵列未启动时也能续期，而 webGUI 故障时往往正是这种情况。')?><br>
      <?=_('改为 /mnt/user/appdata/letsencrypt 可减少 U 盘写入，但必须先启动阵列。')?>
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
      <?=_('该项仅控制检查频率，不代表续期频率。certbot 会自行判断剩余有效期是否小于 30 天，未到期则直接跳过。')?>
    </blockquote>
  </dd>

  <dt><?=_('阵列启动后自动检查')?></dt>
  <dd>
    <input type="hidden" name="RUN_AT_BOOT" value="no">
    <label><input type="checkbox" name="RUN_AT_BOOT" value="yes"<?=cb_bool($cfg['RUN_AT_BOOT']) ? ' checked' : ''?>> <?=_('阵列启动完成后延迟 90 秒执行一次检查')?></label>
    <blockquote class="inline_help">
      <?=_('默认关闭。适用于证书过期导致 webGUI 报警、重启后需自动恢复的场景；通常情况下定时任务已足够。')?>
    </blockquote>
  </dd>

  <dt><?=_('更新后重启 nginx')?></dt>
  <dd>
    <input type="hidden" name="RESTART_NGINX" value="no">
    <label><input type="checkbox" name="RESTART_NGINX" value="yes"<?=cb_bool($cfg['RESTART_NGINX']) ? ' checked' : ''?>> <?=_('证书变化后自动重启 Unraid Web 管理服务')?></label>
    <blockquote class="inline_help">
      <?=_('证书内容未变化时不重启（脚本会比较合并结果）。重启会导致当前 webGUI 会话中断数秒。')?>
    </blockquote>
  </dd>

  <dt><?=_('测试环境')?></dt>
  <dd>
    <input type="hidden" name="STAGING" value="no">
    <label><input type="checkbox" name="STAGING" value="yes"<?=cb_bool($cfg['STAGING']) ? ' checked' : ''?>> <?=_("使用 Let's Encrypt 测试环境")?></label>
    <blockquote class="inline_help">
      <?=_('测试环境的速率限制宽松得多，适用于首次验证配置，但签发的证书不受浏览器信任。')?>
      <?=_('流程验证通过后取消勾选，再执行一次「强制续期」获取正式证书。')?>
    </blockquote>
  </dd>
</dl>

<p><input type="submit" name="#apply" value="<?=_('应用')?>" disabled></p>
</form>
<?php
}
