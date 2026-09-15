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

/** 将一行消息输出至界面日志框 */
function cb_say(string $msg, string $prefix = ''): void
{
    $msg = str_replace(["\n", '"'], ['<br>', '\\"'], $prefix . $msg);
    echo "<script>addLog(\"{$msg}\");</script>";
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

$certDir = rtrim(trim((string)($_POST['CERT_DIR'] ?? '')), '/');
if ($certDir === '') {
    $certDir = '/mnt/user/appdata/letsencrypt';
}
if ($certDir[0] !== '/') {
    cb_error("证书目录必须是绝对路径，当前为：{$certDir}");
} elseif (strpos($certDir, ' ') !== false) {
    cb_error('证书目录不能包含空格');
} else {
    // .cfg 中存储 Unraid 上的路径，落盘时映射至沙箱
    $_POST['CERT_DIR'] = $certDir;
    $realCertDir = cb_syspath($certDir);

    // 文件系统能力检查：certbot 需在 live/ 与 archive/ 之间创建符号链接，
    // FAT/exFAT 上必然失败。此处提前拦截，避免续期时才抛出 EPERM。
    [$fsOk, $fsReason, $fsInfo] = cb_fs_check($realCertDir, true);
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

// ---------------------------------------------------------------------------
// Cloudflare Token
//
// 表单字段名为 CF_API_TOKEN_NEW，刻意区别于任何 .cfg 键，
// 避免被 update.php 写入配置文件（处理完后即从 $_POST 中移除）。
// Token 仅存储于 600 权限的 cloudflare.ini。
// ---------------------------------------------------------------------------

$newToken  = trim((string)($_POST['CF_API_TOKEN_NEW'] ?? ''));
$clearTok  = cb_bool($_POST['CF_API_TOKEN_CLEAR'] ?? 'no');
unset($_POST['CF_API_TOKEN_NEW'], $_POST['CF_API_TOKEN_CLEAR']);

if ($clearTok) {
    if (is_file($cbCredFile)) {
        @unlink($cbCredFile);
        cb_notice('已清除 Cloudflare API Token');
    }
} elseif ($newToken !== '') {
    if (!preg_match('/^[A-Za-z0-9_\-]{20,100}$/', $newToken)) {
        cb_error('Cloudflare API Token 格式不正确');
    } else {
        if (!is_dir($cbCfgDir) && !@mkdir($cbCfgDir, 0700, true)) {
            cb_error("无法创建配置目录 {$cbCfgDir}");
        } else {
            $content = "# 由 unraid-certbot 插件生成，请勿手工编辑\n"
                     . "# 对应权限：Zone → DNS → Edit\n"
                     . "dns_cloudflare_api_token = {$newToken}\n";
            if (@file_put_contents($cbCredFile, $content, LOCK_EX) === false) {
                cb_error("写入凭据文件失败：{$cbCredFile}");
            } else {
                @chmod($cbCredFile, 0600);
                @chown($cbCredFile, 'root');
                @chgrp($cbCredFile, 'root');
                cb_notice('Cloudflare API Token 已保存到 ' . $cbCredFile . '（权限 600）');
            }
        }
    }
} elseif (!cb_has_token()) {
    // 未提交新 Token 且无已保存 Token
    cb_error('请填写 Cloudflare API Token');
}

// ---------------------------------------------------------------------------
// 只有前面都通过了，才更新 cron
// ---------------------------------------------------------------------------

if (cb_errors() === []) {
    $script    = "$cbPluginDir/scripts/renew.sh";
    $schedule  = (string)($_POST['SCHEDULE'] ?? 'daily');
    $scheduleMap = [
        'daily'   => '14 1 * * *',
        'weekly'  => '14 1 * * 0',
        'monthly' => '14 1 1 * *',
    ];
    $scheduleTime = trim((string)($_POST['SCHEDULE_TIME'] ?? '01:14'));
    if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $scheduleTime)) {
        cb_error('自动检查时间格式不正确，应为 HH:MM');
        $scheduleTime = '01:14';
    }
    $_POST['SCHEDULE_TIME'] = $scheduleTime;
    [$scheduleHour, $scheduleMinute] = array_map('intval', explode(':', $scheduleTime));

    if (cb_errors() === [] && isset($scheduleMap[$schedule])) {
        $cronTime = sprintf('%d %d', $scheduleMinute, $scheduleHour);
        // Unraid's update_cron places plugin entries in the root user's
        // crontab, which uses the five-field user crontab format. Do not
        // include a second user column here.
        $text = preg_replace('/^\S+ \S+/', $cronTime, $scheduleMap[$schedule])
            . " {$script} --quiet --trigger=cron >/dev/null 2>&1\n";
        parse_cron_cfg('unraid-certbot', 'renew', $text);
        cb_notice("自动续期已设置为每" . ['daily' => '天', 'weekly' => '周', 'monthly' => '月'][$schedule] . "检查一次");
    } else {
        parse_cron_cfg('unraid-certbot', 'renew', '');
        cb_notice('自动续期已关闭');
    }

    if (cb_bool($_POST['STAGING'] ?? 'no')) {
        cb_say('⚠️ 测试环境签发的证书不受浏览器信任');
    }

    cb_notice('设置已保存');
} else {
    // 拒绝写入，界面保留用户输入以便修改。
    // 注意：Token 单独存储于 cloudflare.ini，上面已写入 —— 此处需说明，
    // 否则用户会误以为 Token 未保存，修改其他字段后重复粘贴。
    $save = false;
    cb_say('设置未保存，请修正上述问题', '⚠️ ');
    cb_say('（Token 已单独保存，无需重新输入）');
}

// 设置页「设置」标签内有 #cb-update-result 区域。此脚本在 progressFrame
// iframe 内执行，需通过 parent 调用；进度框的 addLog 输出保持不变。
$cbResultMsg = $save ? '设置已保存' : '设置未保存';
echo '<script>if(window.parent&&typeof parent.cbSaveResult==="function"){parent.cbSaveResult('
   . ($save ? 'true' : 'false') . ',"' . $cbResultMsg . '");}</script>';
