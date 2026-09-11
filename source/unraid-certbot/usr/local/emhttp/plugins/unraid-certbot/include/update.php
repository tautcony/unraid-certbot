<?php
/**
 * unraid-certbot - 设置校验
 *
 * 由 /usr/local/emhttp/update.php 通过表单里的 #include 隐藏字段在「写入配置文件之前」执行。
 * 校验不通过时把 $save 置为 false 拒绝保存，并用 addLog() 把原因显示在界面上。
 *
 * 除了校验，这里还负责两件配置写入之外的事：
 *   1. 把 Cloudflare Token 写进独立的 600 权限凭据文件（不落在 .cfg 里）
 *   2. 按所选频率重建 cron 条目
 */

$docroot = $docroot ?? ($_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp');
require_once "$docroot/plugins/dynamix/include/Wrappers.php";

// 阻止 status.php 在 CLI 下打印摘要 —— 这里的 stdout 是给界面的 addLog 用的，
// 混进别的输出会破坏设置保存流程。
define('CB_NO_CLI_OUTPUT', true);
require_once "$docroot/plugins/unraid-certbot/include/status.php";

// 路径常量来自 status.php
$cbPluginDir = CB_PLUGIN_DIR;
$cbCfgDir    = CB_CFG_DIR;
$cbCredFile  = CB_CRED_FILE;

/** 把一行消息推给界面上的日志框 */
function cb_say(string $msg, string $prefix = ''): void
{
    $msg = str_replace(["\n", '"'], ['<br>', '\\"'], $prefix . $msg);
    echo "<script>addLog(\"{$msg}\");</script>";
    @flush();
}

/**
 * 校验错误清单。用函数内 static 而非 `global $cbErrors`：
 * 本地调试是在函数里 include 本文件的，那时 global 取不到外层变量。
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
// 域名：规范化后写回 $_POST，让存进 .cfg 的是干净的值
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
            cb_notice('域名解析为 ' . count($domains) . ' 个，主域名（证书目录名）为 ' . $domains[0]);
        }
    }
}

// ---------------------------------------------------------------------------
// 邮箱
// ---------------------------------------------------------------------------

$email = trim((string)($_POST['ACME_EMAIL'] ?? ''));
if ($email === '') {
    cb_error('邮箱不能为空，Let\'s Encrypt 用它发送证书到期提醒');
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    cb_error("邮箱格式不正确：{$email}");
} else {
    $_POST['ACME_EMAIL'] = $email;
}

// ---------------------------------------------------------------------------
// Unraid 主机名（决定 bundle 文件名，必须和 Unraid 自己认的名字一致）
// ---------------------------------------------------------------------------

$host = trim((string)($_POST['UNRAID_HOSTNAME'] ?? ''));
if ($host === '') {
    cb_error('Unraid 主机名不能为空');
} elseif (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,62}$/', $host)) {
    cb_error("主机名含有非法字符：{$host}");
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
    $certDir = '/boot/config/letsencrypt';
}
if ($certDir[0] !== '/') {
    cb_error("证书目录必须是绝对路径，当前为：{$certDir}");
} elseif (strpos($certDir, ' ') !== false) {
    cb_error('证书目录不能包含空格');
} else {
    // .cfg 里存 Unraid 上的路径，落盘时再映射到沙箱
    $_POST['CERT_DIR'] = $certDir;
    $realCertDir = cb_syspath($certDir);
    if (!is_dir($realCertDir) && !@mkdir($realCertDir, 0700, true)) {
        cb_error("无法创建证书目录 {$certDir}，请检查路径与权限");
    }
}

// ---------------------------------------------------------------------------
// 复选框：未勾选时浏览器不提交该字段，所以界面上放了同名的 hidden "no"
// ---------------------------------------------------------------------------

foreach (['RESTART_NGINX', 'STAGING', 'RUN_AT_BOOT'] as $flag) {
    $_POST[$flag] = cb_bool($_POST[$flag] ?? 'no') ? 'yes' : 'no';
}

// ---------------------------------------------------------------------------
// Cloudflare Token
//
// 表单字段叫 CF_API_TOKEN_NEW，刻意不同于任何 .cfg 键，
// 这样它不会被 update.php 写进配置文件（写完这里就把它从 $_POST 里摘掉）。
// Token 只存在于 600 权限的 cloudflare.ini 里。
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
        cb_error('Cloudflare API Token 格式看起来不对（应为 20–100 位的字母数字、下划线或连字符）');
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
    // 既没填新的，也没有旧的
    cb_error('还没有配置 Cloudflare API Token');
}

// ---------------------------------------------------------------------------
// 只有前面都通过了，才去动 cron
// ---------------------------------------------------------------------------

if (cb_errors() === []) {
    $script    = "$cbPluginDir/scripts/renew.sh";
    $schedule  = (string)($_POST['SCHEDULE'] ?? 'daily');
    $scheduleMap = [
        'daily'   => '17 3 * * *',
        'weekly'  => '17 3 * * 0',
        'monthly' => '17 3 1 * *',
    ];

    if (isset($scheduleMap[$schedule])) {
        $text = $scheduleMap[$schedule] . " root {$script} --quiet --trigger=cron >/dev/null 2>&1\n";
        parse_cron_cfg('unraid-certbot', 'renew', $text);
        cb_notice("自动续期已设置为每" . ['daily' => '天', 'weekly' => '周', 'monthly' => '月'][$schedule] . "检查一次");
    } else {
        parse_cron_cfg('unraid-certbot', 'renew', '');
        cb_notice('自动续期已关闭');
    }

    if (cb_bool($_POST['STAGING'] ?? 'no')) {
        cb_say('⚠️ 当前使用 Let\'s Encrypt 测试环境，签出的证书不被浏览器信任，验证完请记得关掉');
    }

    cb_notice('设置已保存');
} else {
    // 拒绝写入，界面上保留用户输入让他改。
    // 注意：Token 是单独存进 cloudflare.ini 的，上面已经写过了 —— 这里要说清楚，
    // 否则用户会以为 Token 也没存上，修完别的字段后又重新粘贴一遍。
    $save = false;
    cb_say('除 Token 外的设置未保存，请修正上面标出的问题', '⚠️ ');
    cb_say('（Token 已单独保存，修改其它字段时留空即可，不用重新输入）');
}
