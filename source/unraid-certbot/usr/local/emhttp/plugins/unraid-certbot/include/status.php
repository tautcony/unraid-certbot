<?php
/**
 * unraid-certbot - 状态读取
 *
 * 被两个 .page 共用，也可以直接在终端里跑：
 *   php -f /usr/local/emhttp/plugins/unraid-certbot/include/status.php
 *
 * 这里只做只读操作，不会发起任何续期请求。
 */

const CB_PLUGIN      = 'unraid-certbot';
const CB_CFG_DIR     = '/boot/config/plugins/unraid-certbot';
const CB_PLUGIN_DIR  = '/usr/local/emhttp/plugins/unraid-certbot';
const CB_CRED_FILE   = CB_CFG_DIR . '/cloudflare.ini';
const CB_HISTORY     = CB_CFG_DIR . '/history.tsv';
const CB_LOG         = CB_CFG_DIR . '/certbot.log';
const CB_HISTORY_MAX = 200;

/** 把配置里的 yes/on/true/1 统一判断成布尔值 */
function cb_bool($v): bool
{
    return in_array(strtolower(trim((string)$v)), ['yes', 'on', 'true', '1'], true);
}

/**
 * 宽容地读一个 ini 配置文件。
 *
 * 不能直接用 parse_ini_file()：配置文件在 flash 上、用户可以手改，
 * 一旦出现注释里的括号或写错的字符，parse_ini_file 会整份返回 false，
 * 状态页就整个白掉了。Unraid 自己的 my_parse_ini_file 也是先剥注释再解析的。
 */
function cb_parse_cfg(string $file): array
{
    if (!is_file($file) || !is_readable($file)) {
        return [];
    }
    // 先用原生解析（最快的路径，且能处理引号里的 # 号）
    $r = @parse_ini_file($file, false, INI_SCANNER_RAW);
    if (is_array($r)) {
        return $r;
    }
    // 退化路径：逐行手工解析，容忍各种脏数据
    $out = [];
    foreach ((array)@file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || $line[0] === ';' || strpos($line, '=') === false) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[strlen($v) - 1] === $v[0]) {
            $v = substr($v, 1, -1);
        }
        if ($k !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $k)) {
            $out[$k] = $v;
        }
    }
    return $out;
}

/** 读插件配置：默认值 + 用户覆盖，用户优先（等价于 Unraid 的 parse_plugin_cfg） */
function cb_load_cfg(): array
{
    $cfg = cb_parse_cfg(CB_PLUGIN_DIR . '/default.cfg');
    $user = CB_CFG_DIR . '/' . CB_PLUGIN . '.cfg';
    if (is_file($user)) {
        $cfg = array_replace($cfg, cb_parse_cfg($user));
    }
    return $cfg;
}

/**
 * 规范化域名列表：逗号 / 分号 / 空白分隔，去重保序、转小写、去尾部点。
 * 返回有序数组，第一个即主域名。
 */
function cb_parse_domains(string $raw): array
{
    $parts = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $seen  = [];
    $out   = [];
    foreach ($parts as $d) {
        $d = strtolower(rtrim(trim($d), '.'));
        if ($d === '' || isset($seen[$d])) {
            continue;
        }
        $seen[$d] = true;
        $out[]    = $d;
    }
    return $out;
}

/** 单个域名是否合法（允许 *.example.com 通配符） */
function cb_valid_domain(string $d): bool
{
    $d = preg_replace('/^\*\./', '', $d);
    if ($d === null || strlen($d) > 253) {
        return false;
    }
    return (bool)preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $d);
}

/** 是否已经配置过 Cloudflare Token */
function cb_has_token(): bool
{
    return is_file(CB_CRED_FILE) && filesize(CB_CRED_FILE) > 0
        && (bool)preg_match('/^\s*dns_cloudflare_api_token\s*=\s*\S+/m', (string)@file_get_contents(CB_CRED_FILE));
}

/** 读取 PEM 里的第一张证书，返回摘要信息；读不到返回 null */
function cb_cert_info(?string $file): ?array
{
    if (!$file || !is_readable($file) || filesize($file) === 0) {
        return null;
    }
    $pem = @file_get_contents($file);
    if ($pem === false || $pem === '') {
        return null;
    }
    $x = @openssl_x509_parse($pem);
    if (!is_array($x)) {
        return null;
    }
    $to   = (int)($x['validTo_time_t'] ?? 0);
    $from = (int)($x['validFrom_time_t'] ?? 0);

    // subjectAltName 形如 "DNS:example.com, DNS:www.example.com"
    $sans = [];
    if (!empty($x['extensions']['subjectAltName'])) {
        foreach (explode(',', $x['extensions']['subjectAltName']) as $part) {
            $part = trim($part);
            if (stripos($part, 'DNS:') === 0) {
                $sans[] = substr($part, 4);
            }
        }
    }

    return [
        'file'    => $file,
        'subject' => (string)($x['subject']['CN'] ?? ''),
        'issuer'  => (string)($x['issuer']['CN'] ?? ($x['issuer']['O'] ?? '')),
        'from'    => $from,
        'to'      => $to,
        'days'    => (int)floor(($to - time()) / 86400),
        'sans'    => $sans,
        'serial'  => (string)($x['serialNumber'] ?? ''),
        'staging' => stripos((string)($x['issuer']['CN'] ?? ''), 'staging') !== false,
    ];
}

/** 续期历史，最新的在前 */
function cb_history(int $limit = 50): array
{
    if (!is_file(CB_HISTORY)) {
        return [];
    }
    $lines = @file(CB_HISTORY, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) {
        return [];
    }
    $out = [];
    foreach (array_reverse($lines) as $line) {
        if (count($out) >= $limit) {
            break;
        }
        $p     = explode("\t", $line);
        $out[] = [
            'time'    => $p[0] ?? '',
            'trigger' => $p[1] ?? '',
            'result'  => $p[2] ?? '',
            'domains' => $p[3] ?? '',
            'message' => $p[4] ?? '',
        ];
    }
    return $out;
}

/** 日志尾部若干行 */
function cb_log_tail(int $lines = 400): string
{
    if (!is_file(CB_LOG)) {
        return '';
    }
    $all = @file(CB_LOG, FILE_IGNORE_NEW_LINES);
    if (!$all) {
        return '';
    }
    return implode("\n", array_slice($all, -$lines));
}

/** Docker 是否可用（阵列没起时为 false） */
function cb_docker_ok(): bool
{
    static $ok = null;
    if ($ok === null) {
        exec('docker info >/dev/null 2>&1', $_, $rc);
        $ok = ($rc === 0);
    }
    return $ok;
}

/** certbot 镜像是否已在本地 */
function cb_image_ok(): bool
{
    static $ok = null;
    if ($ok === null) {
        exec('docker image inspect certbot/dns-cloudflare >/dev/null 2>&1', $_, $rc);
        $ok = ($rc === 0);
    }
    return $ok;
}

/**
 * 汇总状态。$cfg 来自 parse_plugin_cfg()。
 */
function cb_status(array $cfg): array
{
    $domains = cb_parse_domains((string)($cfg['DOMAINS'] ?? ''));
    $primary = $domains[0] ?? '';
    $host    = trim((string)($cfg['UNRAID_HOSTNAME'] ?? ''));
    $certDir = rtrim(trim((string)($cfg['CERT_DIR'] ?? '')), '/') ?: '/boot/config/letsencrypt';

    $bundlePath = $host !== '' ? "/boot/config/ssl/certs/{$host}_unraid_bundle.pem" : null;
    $bundle     = cb_cert_info($bundlePath);
    $livePath   = ($primary !== '' && is_file("{$certDir}/live/{$primary}/fullchain.pem"))
        ? "{$certDir}/live/{$primary}/fullchain.pem"
        : null;
    $live       = cb_cert_info($livePath);

    $history  = cb_history(50);
    $lastRun  = $history[0] ?? null;
    $lastOk   = null;
    foreach ($history as $h) {
        if (($h['result'] ?? '') === '成功') {
            $lastOk = $h;
            break;
        }
    }

    // 整体健康度：以 webGUI 实际使用的那张证书（bundle）为准
    $days = $bundle['days'] ?? ($live['days'] ?? null);
    if ($days === null) {
        $health = 'unknown';
    } elseif ($days < 0) {
        $health = 'expired';
    } elseif ($days <= 14) {
        $health = 'soon';
    } else {
        $health = 'ok';
    }

    return [
        'configured' => [
            'token'   => cb_has_token(),
            'email'   => trim((string)($cfg['ACME_EMAIL'] ?? '')) !== '',
            'host'    => $host !== '',
            'domains' => count($domains) > 0,
        ],
        'domains'    => $domains,
        'primary'    => $primary,
        'host'       => $host,
        'cert_dir'   => $certDir,
        'bundle'     => $bundle,
        'bundle_path'=> $bundlePath,
        'bundle_mtime' => ($bundlePath && is_file($bundlePath)) ? filemtime($bundlePath) : null,
        'live'       => $live,
        'live_path'  => $livePath,
        'health'     => $health,
        'days'       => $days,
        'history'    => $history,
        'last_run'   => $lastRun,
        'last_ok'    => $lastOk,
        'docker_ok'  => cb_docker_ok(),
        'image_ok'   => cb_image_ok(),
        'schedule'   => (string)($cfg['SCHEDULE'] ?? 'daily'),
        'staging'    => cb_bool($cfg['STAGING'] ?? 'no'),
        'restart_nginx' => cb_bool($cfg['RESTART_NGINX'] ?? 'yes'),
        'propagation'=> (int)($cfg['PROPAGATION'] ?? 60),
    ];
}

/** 把天数渲染成带颜色的文案 */
function cb_days_html(?int $days): string
{
    if ($days === null) {
        return '<span class="grey-text">未知</span>';
    }
    if ($days < 0) {
        return '<span class="red-text"><b>已过期 ' . abs($days) . ' 天</b></span>';
    }
    if ($days <= 14) {
        return '<span class="orange-text"><b>' . $days . ' 天</b></span>';
    }
    return '<span class="green-text">' . $days . ' 天</span>';
}

/** 转义输出 */
function cb_e($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** 触发来源显示名 */
function cb_trigger_label(string $t): string
{
    return [
        'manual' => '手动',
        'webgui' => '界面按钮',
        'cron'   => '定时任务',
        'boot'   => '开机',
    ][$t] ?? $t;
}

/** 续期频率显示名 */
function cb_schedule_label(string $s): string
{
    return [
        'daily'   => '每天检查一次',
        'weekly'  => '每周检查一次',
        'monthly' => '每月检查一次',
        'off'     => '已关闭',
    ][$s] ?? $s;
}

/**
 * Unraid 自己的服务器名 —— 证书 bundle 的文件名必须与它一致。
 * 优先读 /var/local/emhttp/var.ini 的 NAME，读不到就退回系统 hostname。
 */
function cb_unraid_name(): string
{
    $ini = '/var/local/emhttp/var.ini';
    if (is_file($ini)) {
        foreach ((array)@file($ini, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (preg_match('/^\s*NAME\s*=\s*"?(.*?)"?\s*$/', $line, $m) && $m[1] !== '') {
                return $m[1];
            }
        }
    }
    return (string)gethostname();
}

/** 把时间戳渲染成「3 天前」这类相对时间 */
function cb_ago(?int $ts): string
{
    if ($ts === null || $ts <= 0) {
        return '未知';
    }
    $diff = time() - $ts;
    if ($diff < 0) {
        return '未来';
    }
    foreach ([[31536000, '年'], [2592000, '个月'], [86400, '天'], [3600, '小时'], [60, '分钟']] as [$unit, $label]) {
        if ($diff >= $unit) {
            return floor($diff / $unit) . " {$label}前";
        }
    }
    return '刚刚';
}

// 直接用 CLI 运行时打印一份摘要，方便排障。
// 被其它脚本 require 时应先 define('CB_NO_CLI_OUTPUT', true)，避免污染它们的输出。
if (PHP_SAPI === 'cli' && !defined('CB_NO_CLI_OUTPUT') && !defined('CB_STATUS_INCLUDED')) {
    $st = cb_status(cb_load_cfg());
    printf("健康状态   : %s\n", $st['health']);
    printf("主域名     : %s\n", $st['primary'] ?: '(未配置)');
    printf("bundle     : %s\n", $st['bundle_path'] ?: '(未配置主机名)');
    if ($st['bundle']) {
        printf("  颁发者   : %s\n", $st['bundle']['issuer']);
        printf("  到期     : %s (剩 %d 天)\n", date('Y-m-d H:i:s', $st['bundle']['to']), $st['bundle']['days']);
    } else {
        printf("  (未找到证书)\n");
    }
    printf("Docker     : %s\n", $st['docker_ok'] ? '可用' : '不可用');
    printf("镜像       : %s\n", $st['image_ok'] ? '已存在' : '未拉取');
    printf("上次运行   : %s\n", $st['last_run'] ? "{$st['last_run']['time']} {$st['last_run']['result']}" : '无记录');
}
