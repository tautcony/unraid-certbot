<?php
/**
 * unraid-certbot - 设置校验
 *
 * 除校验外，还负责两项配置写入之外的工作：
 *   1. 将 Cloudflare Token 写入独立的 600 权限凭据文件
 *   2. 按所选频率重建 cron 条目
 */

$docroot = $docroot ?? ($_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp');
require_once "$docroot/plugins/dynamix/include/Wrappers.php";

// 阻止 status.php 在 CLI 下打印摘要 —— 此处 stdout 用于界面 addLog，
// 混入其他输出会破坏设置保存流程。
define('CB_NO_CLI_OUTPUT', true);
require_once "$docroot/plugins/unraid-certbot/include/status.php";

// 路径常量来自 status.php
$cbPluginDir = CB_PLUGIN_DIR;
$cbCfgDir    = CB_CFG_DIR;
$cbCredFile  = CB_CRED_FILE;
header('Content-Type: text/html; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}
$source = (string)($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '');
$sourceHost = parse_url($source, PHP_URL_HOST);
$sourcePort = parse_url($source, PHP_URL_PORT);
$requestHost = parse_url('http://' . (string)($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
$requestPort = parse_url('http://' . (string)($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_PORT);
if ($sourceHost === null || $requestHost === null || strcasecmp($sourceHost, $requestHost) !== 0
    || $sourcePort !== $requestPort) {
    http_response_code(403);
    exit;
}

echo '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>';
if (is_file("$docroot/logging.htm")) {
    readfile("$docroot/logging.htm");
} else {
    echo '<pre id="log"></pre><script>function addLog(s){document.getElementById("log").textContent+=s+"\n"}</script>';
}

/** 将一行消息输出至界面日志框 */
function cb_say(string $msg, string $prefix = ''): void
{
    $encoded = json_encode(htmlspecialchars($prefix . $msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
    echo "<script>addLog({$encoded});</script>";
    @flush();
}

/**
 * 校验错误清单。使用函数内 static 而非 `global $cbErrors`：
 * 本地调试时在函数内 include 本文件，此时 global 无法访问外层变量。
 */
function cb_errors(?string $add = null): array
{
    static $errors = [];
    if ($add !== null) {
        $errors[] = $add;
    }
    return $errors;
}

function cb_error(string $msg): void
{
    cb_errors($msg);
    cb_say($msg, '❌ ');
}

function cb_notice(string $msg): void
{
    cb_say($msg, '✅ ');
}

function cb_cron_text(string $schedule, string $time, string $script): string
{
    $days = ['daily' => '* * *', 'weekly' => '* * 0', 'monthly' => '1 * *'];
    if (!isset($days[$schedule]) || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $time)) {
        return '';
    }
    [$hour, $minute] = array_map('intval', explode(':', $time));
    return "$minute $hour {$days[$schedule]} $script --quiet --trigger=cron >/dev/null 2>&1\n";
}

function cb_restore_file(string $path, $previous): bool
{
    if ($previous === null) {
        return !is_file($path) || @unlink($path);
    }
    $tmp = @tempnam(dirname($path), '.restore-');
    if ($tmp === false) {
        return false;
    }
    $ok = @file_put_contents($tmp, $previous) === strlen($previous)
        && @chmod($tmp, 0600) && @rename($tmp, $path);
    if (!$ok) {
        @unlink($tmp);
    }
    return $ok;
}

$allowed = array_fill_keys(cb_config_keys(), true);
$special = ['CF_API_TOKEN_NEW' => true, 'CF_API_TOKEN_CLEAR' => true];
foreach ($_POST as $key => $value) {
    if (!isset($allowed[$key]) && !isset($special[$key])) {
        cb_error('不支持的配置字段：' . $key);
    } elseif (is_array($value)) {
        cb_error('配置字段格式不正确：' . $key);
        $_POST[$key] = '';
    }
}
if (cb_errors() !== []) {
    cb_say('设置未保存，请修正上述问题');
    echo '<script>if(window.parent&&typeof parent.cbSaveResult==="function"){parent.cbSaveResult(false,"设置未保存");}</script></body></html>';
    exit;
}

// ---------------------------------------------------------------------------
// 域名：规范化后写回 $_POST，确保写入 .cfg 的值符合规范
// ---------------------------------------------------------------------------

$rawDomains = (string)($_POST['DOMAINS'] ?? '');
$domains    = cb_parse_domains($rawDomains);

if (empty($domains)) {
    cb_error('域名列表不能为空');
} else {
    $bad = array_filter($domains, fn($d) => !cb_valid_domain($d));
    if (!empty($bad)) {
        cb_error('域名格式不正确：' . implode(', ', $bad));
    } else {
        $_POST['DOMAINS'] = implode(',', $domains);
        if (count($domains) > 1) {
            cb_notice('已识别 ' . count($domains) . ' 个域名，主域名：' . $domains[0]);
        }
    }
}

// ---------------------------------------------------------------------------
// 邮箱
// ---------------------------------------------------------------------------

$email = trim((string)($_POST['ACME_EMAIL'] ?? ''));
if ($email === '') {
    cb_error('请填写邮箱');
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    cb_error("邮箱格式不正确：{$email}");
} else {
    $_POST['ACME_EMAIL'] = $email;
}

// ---------------------------------------------------------------------------
// Unraid 主机名
// ---------------------------------------------------------------------------

$host = trim((string)($_POST['UNRAID_HOSTNAME'] ?? ''));
if ($host === '') {
    cb_error('请填写 Unraid 主机名');
} elseif (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,62}$/', $host)) {
    cb_error("主机名格式不正确：{$host}");
} else {
    $_POST['UNRAID_HOSTNAME'] = $host;
}

// ---------------------------------------------------------------------------
// DNS 传播等待时间
// ---------------------------------------------------------------------------

$prop = (int)($_POST['PROPAGATION'] ?? 60);
if ($prop < 10 || $prop > 900) {
    cb_error("DNS 传播等待时间必须在 10–900 秒之间，当前为 {$prop}");
} else {
    $_POST['PROPAGATION'] = (string)$prop;
}

// ---------------------------------------------------------------------------
// 证书目录
// ---------------------------------------------------------------------------

$certDir = trim((string)($_POST['CERT_DIR'] ?? ''));
if ($certDir === '') {
    $certDir = '/mnt/user/appdata/letsencrypt';
} else {
    $certDir = rtrim($certDir, '/') ?: '/';
}
if (!cb_valid_cert_dir($certDir)) {
    cb_error("证书目录必须位于 /mnt/user/appdata 的普通子目录且不能经过符号链接：{$certDir}");
} else {
    // .cfg 中存储 Unraid 上的路径，落盘时映射至沙箱
    $_POST['CERT_DIR'] = $certDir;
    $realCertDir = cb_syspath($certDir);

    // 文件系统能力检查：certbot 需在 live/ 与 archive/ 之间创建符号链接，
    // FAT/exFAT 上必然失败。此处提前拦截，避免续期时才抛出 EPERM。
    [$fsOk, $fsReason, $fsInfo] = cb_fs_check($realCertDir, false);
    if (!$fsOk) {
        // 沙箱下 $fsReason 为映射后的路径，显示前还原为配置中的路径
        cb_error(str_replace($realCertDir, $certDir, $fsReason));
    } else {
        $summary = cb_fs_summary($fsInfo);
        cb_notice("证书目录可用：{$certDir}" . ($summary !== '' ? "（{$summary}）" : ''));
        if ($fsInfo['symlink'] === null) {
            // 目录刚创建但无法检测（如父目录只读），续期前将重新检查
            cb_say('⚠️ 未能完成目录自检，续期前将重新检查');
        }
    }
}

// ---------------------------------------------------------------------------
// 复选框：未勾选时浏览器不提交该字段，因此界面放置同名的 hidden "no"
// ---------------------------------------------------------------------------

foreach (['RESTART_NGINX', 'STAGING'] as $flag) {
    $_POST[$flag] = cb_bool($_POST[$flag] ?? 'no') ? 'yes' : 'no';
}

// Validate every field before creating or replacing any persistent file.
$newToken = trim((string)($_POST['CF_API_TOKEN_NEW'] ?? ''));
$clearTok = cb_bool($_POST['CF_API_TOKEN_CLEAR'] ?? 'no');
if ($clearTok && $newToken !== '') {
    cb_error('不能同时填写和清除 Token');
} elseif ($newToken !== '' && !preg_match('/^[A-Za-z0-9_\-]{20,100}$/', $newToken)) {
    cb_error('Cloudflare API Token 格式不正确');
} elseif (!$clearTok && $newToken === '' && !cb_has_token()) {
    cb_error('请填写 Cloudflare API Token');
}

$schedule = (string)($_POST['SCHEDULE'] ?? 'daily');
$schedules = ['daily', 'weekly', 'monthly', 'off'];
if (!in_array($schedule, $schedules, true)) {
    cb_error('自动检查频率不正确');
}
$scheduleTime = trim((string)($_POST['SCHEDULE_TIME'] ?? '01:14'));
if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $scheduleTime)) {
    cb_error('自动检查时间格式不正确，应为 HH:MM');
}
$_POST['SCHEDULE'] = $schedule;
$_POST['SCHEDULE_TIME'] = $scheduleTime;

$save = cb_errors() === [];
if ($save) {
    if (!is_dir($cbCfgDir) && !@mkdir($cbCfgDir, 0700, true)) {
        cb_error("无法创建配置目录 {$cbCfgDir}");
        $save = false;
    } else {
        $cfgFile = "$cbCfgDir/unraid-certbot.cfg";
        $cfgTmp = @tempnam($cbCfgDir, '.cfg-');
        $credTmp = $newToken !== '' ? @tempnam($cbCfgDir, '.token-') : null;
        $cfgLines = [];
        foreach (cb_config_keys() as $key) {
            $value = (string)($_POST[$key] ?? '');
            $cfgLines[] = $key . '="' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
        }
        $cfgData = implode("\n", $cfgLines) . "\n";
        $credData = "# Generated by unraid-certbot\ndns_cloudflare_api_token = {$newToken}\n";
        if ($cfgTmp === false || @file_put_contents($cfgTmp, $cfgData) !== strlen($cfgData)
            || !@chmod($cfgTmp, 0600)
            || ($newToken !== '' && ($credTmp === false
                || @file_put_contents($credTmp, $credData) !== strlen($credData)
                || !@chmod($credTmp, 0600)
                || (function_exists('posix_geteuid') && posix_geteuid() === 0
                    && (!@chown($credTmp, 'root') || !@chgrp($credTmp, 'root')))))) {
            cb_error('准备配置文件失败');
            $save = false;
        }
        if ($save) {
            $oldCfg = is_file($cfgFile) ? @file_get_contents($cfgFile) : null;
            $oldCred = is_file($cbCredFile) ? @file_get_contents($cbCredFile) : null;
            if ($oldCfg === false || $oldCred === false) {
                cb_error('无法读取原配置，未提交设置');
                $save = false;
            }
            $oldValues = is_string($oldCfg) ? cb_parse_cfg($cfgFile) : [];
            $oldCron = cb_cron_text((string)($oldValues['SCHEDULE'] ?? 'off'),
                (string)($oldValues['SCHEDULE_TIME'] ?? ''), "$cbPluginDir/scripts/renew.sh");
            $cron = cb_cron_text($schedule, $scheduleTime, "$cbPluginDir/scripts/renew.sh");
            if ($save && (!@rename($cfgTmp, $cfgFile)
                || ($newToken !== '' && !@rename($credTmp, $cbCredFile))
                || ($clearTok && is_file($cbCredFile) && !@unlink($cbCredFile))
                || parse_cron_cfg('unraid-certbot', 'renew', $cron) === false)) {
                $cfgRestored = cb_restore_file($cfgFile, $oldCfg);
                $credRestored = cb_restore_file($cbCredFile, $oldCred);
                $cronRestored = parse_cron_cfg('unraid-certbot', 'renew', $oldCron) !== false;
                $restored = $cfgRestored && $credRestored && $cronRestored;
                cb_error($restored ? '提交设置失败，已恢复原配置' : '提交设置失败，恢复原配置也失败，请检查配置目录');
                $save = false;
            }
        }
        if (is_string($cfgTmp) && is_file($cfgTmp)) { @unlink($cfgTmp); }
        if (is_string($credTmp) && is_file($credTmp)) { @unlink($credTmp); }
    }
}

cb_say($save ? '设置已保存' : '设置未保存，请修正上述问题');
echo '<script>if(window.parent&&typeof parent.cbSaveResult==="function"){parent.cbSaveResult('
   . ($save ? 'true' : 'false') . ',"' . ($save ? '设置已保存' : '设置未保存') . '");}</script></body></html>';
