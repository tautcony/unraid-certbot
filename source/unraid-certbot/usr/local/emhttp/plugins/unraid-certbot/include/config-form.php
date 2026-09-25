<?php
/**
 * unraid-certbot - 设置表单
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
<blockquote class="inline_help" style="display:block">
<b><?=cb_t('Required settings')?>: <?=cb_e(implode(', ', $fail))?></b><br>
<?=cb_t('Complete them, then click Apply.')?>
</blockquote>
<?php endif; ?>

<form id="cb-config-form" method="POST" action="/plugins/<?=$cb?>/include/update.php">

<dl>
  <dt><?=cb_t('Interface language')?></dt>
  <dd>
    <select name="UI_LANGUAGE">
      <?=mk_option($cfg['UI_LANGUAGE'] ?? 'auto', 'auto', cb_t('Follow Unraid'))?>
      <?=mk_option($cfg['UI_LANGUAGE'] ?? 'auto', 'zh_CN', '简体中文')?>
      <?=mk_option($cfg['UI_LANGUAGE'] ?? 'auto', 'en_US', 'English')?>
    </select>
  </dd>

  <dt><?=cb_t('Cloudflare API Token')?></dt>
  <dd>
    <input type="password" name="CF_API_TOKEN_NEW" id="cb-token" autocomplete="new-password"
           spellcheck="false" placeholder="<?=$token ? cb_t('Configured. Leave blank to keep the current token.') : cb_t('Cloudflare API Token')?>">
    <input type="button" class="cb-inline-btn" value="<?=cb_t('Show')?>" onclick="cbToggle('cb-token',this)">
    <br>
    <label><input type="checkbox" name="CF_API_TOKEN_CLEAR" value="yes"> <?=cb_t('Clear saved token')?></label>
    <blockquote class="inline_help">
      <?=cb_t('Create it in Cloudflare under My Profile > API Tokens > Create Token. Grant Zone > DNS > Edit and select the zones for the requested domains.')?><br>
      <?=cb_t('Stored in')?> <code>/boot/config/plugins/<?=$cb?>/cloudflare.ini</code><?=cb_t(' with mode 600.')?>
    </blockquote>
  </dd>

  <dt><?=cb_t('Email')?></dt>
  <dd>
    <input type="email" name="ACME_EMAIL" value="<?=cb_e($cfg['ACME_EMAIL'])?>">
    <blockquote class="inline_help">
      <?=cb_t("Receives Let's Encrypt certificate expiry notices.")?>
    </blockquote>
  </dd>

  <dt><?=cb_t('Unraid Hostname')?></dt>
  <dd>
    <input type="text" name="UNRAID_HOSTNAME" value="<?=cb_e($cfg['UNRAID_HOSTNAME'])?>">
    <blockquote class="inline_help">
      <?=cb_t('Must match the Unraid server name, otherwise the webGUI will not load the new certificate. Current name:')?>
      <code><?=cb_e(cb_unraid_name())?></code>。
    </blockquote>
  </dd>

  <dt><?=cb_t('Domains')?></dt>
  <dd>
    <textarea name="DOMAINS" rows="4" spellcheck="false"><?=cb_e($cfg['DOMAINS'])?></textarea>
    <blockquote class="inline_help">
      <?=cb_t('Separate with commas or new lines, for example example.com, www.example.com.')?>
      <b><?=cb_t('The first domain is the primary domain')?></b><br><?=cb_t('Wildcards such as *.example.com are supported.')?>
    </blockquote>
  </dd>

  <dt><?=cb_t('DNS propagation wait (seconds)')?></dt>
  <dd>
    <input type="number" name="PROPAGATION" class="narrow" min="10" max="900" value="<?=cb_e($cfg['PROPAGATION'])?>">
    <blockquote class="inline_help">
      <?=cb_t("Time to wait after creating DNS records. Default: 60 seconds. Increase to 60-120 seconds if validation often fails.")?>
    </blockquote>
  </dd>

  <dt><?=cb_t('Certificate storage directory')?></dt>
  <dd>
    <input type="text" name="CERT_DIR" value="<?=cb_e($cfg['CERT_DIR'])?>">
    <blockquote class="inline_help">
      <?=cb_t('Storage for certbot accounts, certificates, and renewal configuration. Default: /mnt/user/appdata/letsencrypt.')?><br>
      <b><?=cb_t('Must be on a filesystem that supports symbolic links')?></b><br><?=cb_t('FAT and exFAT are not supported and renewal will fail.')?>
    </blockquote>
  </dd>

  <dt><?=cb_t('Automatic renewal frequency')?></dt>
  <dd>
    <select name="SCHEDULE">
      <?=mk_option($cfg['SCHEDULE'], 'daily',   cb_t('Check daily (recommended)'))?>
      <?=mk_option($cfg['SCHEDULE'], 'weekly',  cb_t('Check weekly'))?>
      <?=mk_option($cfg['SCHEDULE'], 'monthly', cb_t('Check monthly'))?>
      <?=mk_option($cfg['SCHEDULE'], 'off',     cb_t('Disable automatic renewal'))?>
    </select>
    <blockquote class="inline_help">
      <?=cb_t('How often to check. Certificates are renewed only within 30 days of expiry.')?>
    </blockquote>
  </dd>

  <dt><?=cb_t('Automatic check time')?></dt>
  <dd>
    <input type="time" name="SCHEDULE_TIME" value="<?=cb_e($cfg['SCHEDULE_TIME'] ?? '01:14')?>" step="60">
    <blockquote class="inline_help">
      <?=cb_t('Uses the Unraid system local time. Default: 01:14. The selected weekly or monthly schedule uses this time too.')?>
    </blockquote>
  </dd>

  <dt><?=cb_t('Restart nginx after update')?></dt>
  <dd>
    <input type="hidden" name="RESTART_NGINX" value="no">
    <label><input type="checkbox" name="RESTART_NGINX" value="yes"<?=cb_bool($cfg['RESTART_NGINX']) ? ' checked' : ''?>> <?=cb_t('Automatically restart the Unraid web management service when the certificate changes')?></label>
    <blockquote class="inline_help">
      <?=cb_t('Does not restart when the certificate is unchanged. Restarting interrupts the current webGUI session for a few seconds.')?>
    </blockquote>
  </dd>

  <dt><?=cb_t('Staging environment')?></dt>
  <dd>
    <input type="hidden" name="STAGING" value="no">
    <label><input type="checkbox" name="STAGING" value="yes"<?=cb_bool($cfg['STAGING']) ? ' checked' : ''?>> <?=cb_t("Use the Let's Encrypt staging environment")?></label>
    <blockquote class="inline_help">
      <?=cb_t("The Let's Encrypt staging environment has more lenient rate limits and is useful for validating configuration. Issued certificates are not trusted by browsers.")?>
      <?=cb_t('After validation succeeds, clear this checkbox and run Force renewal to obtain a production certificate.')?>
    </blockquote>
  </dd>
</dl>

<p class="cb-apply-action"><input type="submit" value="<?=cb_t('Apply')?>" disabled></p>
</form>
<?php
}
