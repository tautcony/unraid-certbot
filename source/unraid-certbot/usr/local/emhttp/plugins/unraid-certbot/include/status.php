<?php
/**
 * unraid-certbot - 状态读取
 */

const CB_PLUGIN      = 'unraid-certbot';
const CB_HISTORY_MAX = 200;

/** 本地调试：CB_DEV_ROOT 是沙箱根，规则与 renew.sh 的 syspath() 一致 */
function cb_dev_root(): string
{
    static $root = null;
    if ($root === null) {
        $root = rtrim((string)getenv('CB_DEV_ROOT'), '/');
    }
    return $root;
}

/** 已在沙箱内的路径原样返回，避免重复加前缀 */
function cb_syspath(string $path): string
{
    $root = cb_dev_root();
    if ($root === '' || $path === '') {
        return $path;
    }
    if ($path === $root || strpos($path, $root . '/') === 0) {
        return $path;
    }
    return $path[0] === '/' ? $root . $path : $root . '/' . $path;
}

define('CB_PLUGIN_DIR', cb_syspath('/usr/local/emhttp/plugins/unraid-certbot'));
define('CB_CFG_DIR',    cb_syspath('/boot/config/plugins/unraid-certbot'));
define('CB_SSL_DIR',    cb_syspath('/boot/config/ssl/certs'));
define('CB_VAR_INI',    cb_syspath('/var/local/emhttp/var.ini'));
define('CB_CRED_FILE',  CB_CFG_DIR . '/cloudflare.ini');
define('CB_HISTORY',    CB_CFG_DIR . '/history.tsv');
define('CB_LOG',        CB_CFG_DIR . '/certbot.log');

function cb_config_keys(): array
{
    return (array)@file(CB_PLUGIN_DIR . '/config-keys.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
}

/** Only appdata descendants are valid certbot state directories. */
function cb_valid_cert_dir(string $path): bool
{
    if (!preg_match('#^/mnt/user/appdata/[A-Za-z0-9._/-]+$#D', $path)
        || strpos($path, '//') !== false) {
        return false;
    }
    $parts = explode('/', substr($path, strlen('/mnt/user/appdata/')));
    if (in_array('', $parts, true) || in_array('.', $parts, true) || in_array('..', $parts, true)) {
        return false;
    }
    $real = cb_syspath($path);
    $root = cb_syspath('/mnt/user/appdata');
    for ($p = $real; $p !== $root && $p !== dirname($p); $p = dirname($p)) {
        if (is_link($p)) {
            return false;
        }
    }
    if (is_link($root)) {
        return false;
    }
    if (is_dir($real) && realpath($real) !== $real) {
        return false;
    }
    if (is_dir($real)) {
        foreach ((array)@file('/proc/mounts', FILE_IGNORE_NEW_LINES) as $mount) {
            $fields = preg_split('/\s+/', $mount);
            if (isset($fields[1]) && str_replace('\\040', ' ', $fields[1]) === $real) {
                return false;
            }
        }
    }
    // An existing mount point has its own device and must not be used as certbot state.
    if (is_dir($real) && is_dir(dirname($real))
        && @stat($real)['dev'] !== @stat(dirname($real))['dev']) {
        return false;
    }
    return true;
}

/** 把配置里的 yes/on/true/1 统一判断成布尔值 */
function cb_bool($v): bool
{
    return in_array(strtolower(trim((string)$v)), ['yes', 'on', 'true', '1'], true);
}

/**
 * 容错读取 ini 配置文件。
 *
 * 不直接使用 parse_ini_file()：配置文件位于 flash 上，用户可能手工编辑，
 * 注释中出现括号或非法字符时，parse_ini_file 会整体返回 false，
 * 导致状态页空白。Unraid 自带的 my_parse_ini_file 同样先剥离注释再解析。
 */
function cb_parse_cfg(string $file): array
{
    if (!is_file($file) || !is_readable($file)) {
        return [];
    }
    // 优先使用原生解析（性能最佳，且能处理引号中的 # 号）
    $r = @parse_ini_file($file, false, INI_SCANNER_RAW);
    if (is_array($r)) {
        return $r;
    }
    // 回退路径：逐行手工解析，容忍非法字符
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

/** 仅覆盖插件文案；auto 继续使用 Unraid 当前语言。 */
function cb_t(string $text): string
{
    static $configuredMode = null;
    static $zh = [];
    if ($configuredMode === null) {
        $configuredMode = (string)(cb_load_cfg()['UI_LANGUAGE'] ?? 'auto');
    }
    $mode = $GLOBALS['cb_ui_language_override'] ?? $configuredMode;
    if ($mode === 'auto') {
        if (function_exists('_')) {
            return _($text);
        }
        $dynamix = cb_parse_cfg(cb_syspath('/boot/config/plugins/dynamix/dynamix.cfg'));
        $mode = (string)($dynamix['locale'] ?? 'en_US');
    }
    if ($mode === 'zh_CN' && $zh === []) {
        $file = cb_syspath('/usr/local/emhttp/languages/zh_CN/unraid-certbot.txt');
        $zh = is_file($file) ? ((array)@parse_ini_file($file, false, INI_SCANNER_RAW)) : [];
    }
    if ($mode !== 'zh_CN') {
        return trim($text);
    }
    $key = preg_replace(
        ['/\&amp;|[\?\{\}\|\&\~\!\[\]\(\)\/\\:\*^\.\"\']|<.+?\/?>/', '/^(null|yes|no|true|false|on|off|none)$/i', '/  +/'],
        ['', '$1.', ' '],
        trim($text)
    );
    return $zh[$key] ?? $text;
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

/** 读取 PEM 中的第一张证书并返回摘要信息；无法读取时返回 null */
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

/** docker 可执行文件；本地调试时 dev.sh 通过 CB_DOCKER 指向模拟实现 */
function cb_docker_cmd(): string
{
    static $cmd = null;
    if ($cmd === null) {
        $cmd = trim((string)getenv('CB_DOCKER'));
        if ($cmd === '') {
            $cmd = 'docker';
        }
    }
    return $cmd;
}

/** Docker 是否可用（阵列未启动时为 false） */
function cb_docker_ok(): bool
{
    static $ok = null;
    if ($ok === null) {
        exec(escapeshellarg(cb_docker_cmd()) . ' info >/dev/null 2>&1', $_, $rc);
        $ok = ($rc === 0);
    }
    return $ok;
}

/** certbot 镜像是否已在本地 */
function cb_image_ok(): bool
{
    static $ok = null;
    if ($ok === null) {
        exec(escapeshellarg(cb_docker_cmd()) . ' image inspect certbot/dns-cloudflare >/dev/null 2>&1', $_, $rc);
        $ok = ($rc === 0);
    }
    return $ok;
}

/**
 * 证书目录的文件系统能力检查。
 *
 * certbot 在 /etc/letsencrypt 下将 archive/<域名>/xxx.pem 链接为
 * live/<域名>/xxx.pem，因此底层文件系统必须支持符号链接。FAT / exFAT 等
 * 不支持符号链接的文件系统上，certbot 仅抛出 PermissionError (EPERM)，
 * 表现为权限错误，实际原因为文件系统不支持。此处提前判定并返回结论。
 *
 * 返回 [ok, reason, 详情数组]；ok=false 时 reason 为可直接显示的结论。
 */
function cb_fs_check(string $dir, bool $create = false): array
{
    $info = [
        'dir'      => $dir,
        'real'     => $dir,
        'fstype'   => '',
        'ro'       => false,
        'symlink'  => null,   // null=未检测
        'writable' => null,
    ];

    $real = $dir;
    if (!is_dir($real)) {
        if ($create) {
            @mkdir($real, 0700, true);
        }
        if (!is_dir($real)) {
            // 目录不存在：就近查找已存在的父目录以判定文件系统能力
            $probe = $real;
            while ($probe !== '' && $probe !== '/' && !is_dir($probe)) {
                $probe = dirname($probe);
            }
            return [true, 'Directory does not exist and will be created at the first renewal', $info + ['probe' => $probe]];
        }
    }
    $info['real'] = $real;

    // 文件系统类型与挂载选项：取挂载点最长匹配项（兼容 bind mount / 子卷）
    $mounts = @file_get_contents('/proc/mounts');
    if ($mounts !== false) {
        $best = '';
        foreach (explode("\n", $mounts) as $line) {
            $f = preg_split('/\s+/', trim($line));
            if (count($f) < 4) {
                continue;
            }
            $mp = str_replace('\\040', ' ', $f[1]);
            if (($real === $mp || strpos($real, rtrim($mp, '/') . '/') === 0) && strlen($mp) > strlen($best)) {
                $best    = $mp;
                $info['fstype'] = $f[2];
                $opts           = explode(',', $f[3]);
                $info['ro']     = in_array('ro', $opts, true);
            }
        }
        $info['mount'] = $best;
    }
    if ($info['fstype'] === '') {
        // 非 Linux（本地调试）无法读取 /proc/mounts，按平台回退
        $arg = escapeshellarg($real);
        if (PHP_OS_FAMILY === 'Darwin') {
            $out = @shell_exec("diskutil info {$arg} 2>/dev/null | awk -F: '/File System Personality/ {gsub(/^ +| +$/, \"\", $2); print $2}'");
        } else {
            $out = @shell_exec("stat -c '%T' {$arg} 2>/dev/null || stat -f '%T' {$arg} 2>/dev/null");
        }
        $info['fstype'] = trim((string)$out) ?: 'unknown';
    }

    $info['writable'] = is_writable($real);

    // 实际创建符号链接再删除：唯一可靠的判定方式
    $probeFile = $real . '/.cb-fsprobe-' . getmypid();
    $probeLink = $probeFile . '.lnk';
    if (@file_put_contents($probeFile, 'x') !== false) {
        $info['symlink'] = @symlink($probeFile, $probeLink);
        if ($info['symlink']) {
            @unlink($probeLink);
        }
        @unlink($probeFile);
    }

    // 本地调试用：CB_FAKE_FSTYPE=vfat 模拟 FAT 上的目录，
    // 用于验证「不支持符号链接则拒绝保存」分支（生产环境无需设置）
    if (($fake = trim((string)getenv('CB_FAKE_FSTYPE'))) !== '') {
        $info['fstype'] = $fake;
    }

    $fstype = strtolower($info['fstype']);
    if ($info['ro']) {
        return [false, "Certificate directory {$dir} is on a read-only filesystem", $info];
    }
    if (in_array($fstype, ['vfat', 'msdos', 'exfat', 'fat', 'fat32'], true)) {
        return [false, "Certificate directory {$dir} is on {$info['fstype']}, which does not support symbolic links; choose another path", $info];
    }
    if ($info['symlink'] === false) {
        return [false, "The filesystem containing {$dir} does not support symbolic links; choose another path", $info];
    }
    if ($info['writable'] === false) {
        return [false, "Certificate directory {$dir} is not writable; check permissions (root:root 700)", $info];
    }

    return [true, 'Available', $info];
}

/**
 * 将 cb_fs_check 的详情压缩为一行，用于设置页/状态页显示。
 */
function cb_fs_summary(array $info): string
{
    $parts = [];
    if (!empty($info['fstype'])) {
        $parts[] = cb_t('Filesystem') . ' ' . $info['fstype'];
    }
    if (array_key_exists('symlink', $info) && $info['symlink'] !== null) {
        $parts[] = $info['symlink'] ? cb_t('Symbolic links supported') : cb_t('Symbolic links unsupported');
    }
    if (array_key_exists('writable', $info) && $info['writable'] !== null) {
        $parts[] = $info['writable'] ? cb_t('Writable') : cb_t('Not writable');
    }
    return implode(' · ', $parts);
}

/**
 * 汇总状态。$cfg 来自 parse_plugin_cfg()。
 */
function cb_status(array $cfg): array
{
    $domains = cb_parse_domains((string)($cfg['DOMAINS'] ?? ''));
    $primary = $domains[0] ?? '';
    $host    = trim((string)($cfg['UNRAID_HOSTNAME'] ?? ''));
    $certDir = rtrim(trim((string)($cfg['CERT_DIR'] ?? '')), '/') ?: '/mnt/user/appdata/letsencrypt';
    // 配置中存储 Unraid 上的路径，本地调试时需映射至沙箱
    $certDir = cb_syspath($certDir);
    [$fsOk, $fsReason, $fsInfo] = cb_fs_check($certDir);

    $bundlePath = $host !== '' ? CB_SSL_DIR . "/{$host}_unraid_bundle.pem" : null;
    $bundle     = cb_cert_info($bundlePath);
    $livePath   = ($primary !== '' && is_file("{$certDir}/live/{$primary}/fullchain.pem"))
        ? "{$certDir}/live/{$primary}/fullchain.pem"
        : null;
    $live       = cb_cert_info($livePath);

    $history  = cb_history(50);
    $lastRun  = $history[0] ?? null;
    $lastOk   = null;
    foreach ($history as $h) {
        if (in_array(($h['result'] ?? ''), ['success', '成功'], true)) {
            $lastOk = $h;
            break;
        }
    }

    // 整体健康度：以 webGUI 实际使用的证书（bundle）为准
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
        'fs_ok'      => $fsOk,
        'fs_reason'  => $fsReason,
        'fs_info'    => $fsInfo,
        'fs_summary' => cb_fs_summary($fsInfo),
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
        return '<span class="grey-text">' . cb_t('Unknown') . '</span>';
    }
    if ($days < 0) {
        return '<span class="red-text"><b>' . cb_t('Expired') . ' ' . abs($days) . ' ' . cb_t('days ago') . '</b></span>';
    }
    if ($days <= 14) {
        return '<span class="orange-text"><b>' . $days . ' ' . cb_t('days') . '</b></span>';
    }
    return '<span class="green-text">' . $days . ' ' . cb_t('days') . '</span>';
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
        'manual' => cb_t('Manual'),
        'webgui' => cb_t('webGUI button'),
        'cron'   => cb_t('Scheduled task'),
        'boot'   => cb_t('System startup'),
    ][$t] ?? $t;
}

/** 续期频率显示名 */
function cb_schedule_label(string $s): string
{
    return [
        'daily'   => cb_t('Check daily'),
        'weekly'  => cb_t('Check weekly'),
        'monthly' => cb_t('Check monthly'),
        'off'     => cb_t('Disabled'),
    ][$s] ?? $s;
}

/**
 * Unraid 服务器名
 * 优先读取 /var/local/emhttp/var.ini 的 NAME，失败时回退至系统 hostname。
 */
function cb_unraid_name(): string
{
    $ini = CB_VAR_INI;
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
        return cb_t('Unknown');
    }
    $diff = time() - $ts;
    if ($diff < 0) {
        return cb_t('In the future');
    }
    foreach ([[31536000, cb_t('year')], [2592000, cb_t('month')], [86400, cb_t('day')], [3600, cb_t('hour')], [60, cb_t('minute')]] as [$unit, $label]) {
        if ($diff >= $unit) {
            return floor($diff / $unit) . " {$label} " . cb_t('ago');
        }
    }
    return cb_t('Just now');
}

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
