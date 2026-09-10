<?php
/**
 * unraid-certbot 本地预览服务器（php -S 的路由脚本）。
 *
 *   ./dev.sh                      # 初始化沙箱并启动，默认 http://127.0.0.1:8080
 *   php -S 127.0.0.1:8080 -t dev/run/usr/local/emhttp dev/server.php
 *
 * 路由：
 *   /                        调试首页（沙箱状态 + 快捷入口）
 *   /Settings/UnraidCertbot  渲染 unraid-certbot.page
 *   /Utilities/CertStatus    渲染 CertStatus.page
 *   /update.php              仿真 Unraid 的设置保存流程
 *   /plugins/...             沙箱里的真实文件（exec.php、logging.htm 等）交给内置服务器
 *
 * 服务进程自己会补上 CB_DEV_ROOT / CB_DOCKER / PATH，所以插件代码在它下面
 * 看到的路径全部落在 dev/run/ 沙箱里，不会碰宿主机。
 */

$ROOT = dirname(__DIR__);

$DEV_ROOT = rtrim((string)(getenv('CB_DEV_ROOT') ?: ''), '/');
if ($DEV_ROOT === '') {
    $DEV_ROOT = "$ROOT/dev/run";
}
$DOCROOT = "$DEV_ROOT/usr/local/emhttp";

// 让插件里的 getenv() / exec() 也走沙箱
putenv("CB_DEV_ROOT=$DEV_ROOT");
putenv('CB_DOCKER=' . (getenv('CB_DOCKER') ?: "$DEV_ROOT/bin/docker"));
putenv('PATH=' . "$DEV_ROOT/bin:" . (string)getenv('PATH'));

// PHP 的 date.timezone 可能是 UTC，而 shell 的 date 用系统时区。
// 预览里两者必须一致，否则「上次续期 3 分钟前」会被算成「未来」。
// 优先用 CB_DEV_TZ，其次从 /etc/localtime 读出系统时区，最后退回 +HH:MM 偏移。
if (($devTz = trim((string)getenv('CB_DEV_TZ'))) === '') {
    $link = (string)@readlink('/etc/localtime');
    if (preg_match('#/zoneinfo/(.+)$#', $link, $m)) {
        $devTz = $m[1];
    } else {
        $offset = trim((string)@shell_exec('date +%z'));
        if (preg_match('/^[+-]\d{4}$/', $offset)) {
            $devTz = substr($offset, 0, 3) . ':' . substr($offset, 3, 2);
        }
    }
}
if ($devTz !== '') {
    @date_default_timezone_set($devTz);
}

$docroot = $DOCROOT;
$_SERVER['DOCUMENT_ROOT'] = $DOCROOT;

require_once __DIR__ . '/lib/emhttp.php';

$PLUGIN_DIR = "$DOCROOT/plugins/unraid-certbot";
$SEED_HINT  = '<p>沙箱还不完整，请先在仓库根目录执行 <code>./dev.sh</code>（或 <code>./dev/seed.sh</code>）初始化。</p>';

// ---------------------------------------------------------------------------
// 请求分发
// ---------------------------------------------------------------------------

$uri = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$uri = '/' . ltrim(rawurldecode($uri), '/');
if ($uri !== '/' && substr($uri, -1) === '/') {
    $uri = rtrim($uri, '/');
}

// 目录穿越直接拒绝；本地服务也不该有例外
if (strpos($uri, '..') !== false) {
    http_response_code(400);
    echo '非法路径';
    return true;
}

// 沙箱里真实存在的文件（exec.php / logging.htm 等）交给 PHP 内置服务器处理
if ($uri !== '/' && is_file("$DOCROOT$uri")) {
    return false;
}

switch (true) {
    case $uri === '/' || $uri === '/index.php':
        cb_dev_index($DEV_ROOT, $DOCROOT, $PLUGIN_DIR, $SEED_HINT);
        return true;

    case strcasecmp($uri, '/Settings/UnraidCertbot') === 0:
        cb_dev_render($PLUGIN_DIR, 'unraid-certbot.page', $DEV_ROOT, $SEED_HINT, $uri);
        return true;

    case strcasecmp($uri, '/Utilities/CertStatus') === 0:
        cb_dev_render($PLUGIN_DIR, 'CertStatus.page', $DEV_ROOT, $SEED_HINT, $uri);
        return true;

    case $uri === '/update.php':
        cb_dev_update($DOCROOT);
        return true;
}

http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><title>404</title></head><body>';
echo '<p>本地预览里没有这个地址：<code>' . htmlspecialchars($uri, ENT_QUOTES) . '</code></p>';
echo '<p><a href="/">回到调试首页</a></p></body></html>';
return true;

// ---------------------------------------------------------------------------
// 路由实现
// ---------------------------------------------------------------------------

/** 顶栏要显示的信息：服务器名、版本、运行时长 */
function cb_dev_header_ctx(string $devRoot): array
{
    $host = 'tower';
    $ini  = "$devRoot/var/local/emhttp/var.ini";
    if (is_file($ini) && preg_match('/^\s*NAME\s*=\s*"?([^"\r\n]+)"?/m', (string)file_get_contents($ini), $m)) {
        $host = trim($m[1]);
    } elseif (function_exists('gethostname') && gethostname()) {
        $host = (string)gethostname();
    }

    // 服务进程每次请求都是新进程，用文件记住首次启动时间，好让 Uptime 真实变化
    $startFile = "$devRoot/.dev-start";
    $start     = is_file($startFile) ? (int)file_get_contents($startFile) : 0;
    if ($start <= 0) {
        $start = time();
        @file_put_contents($startFile, (string)$start);
    }
    $secs   = max(0, time() - $start);
    $uptime = intdiv($secs, 86400) . ' 天 '
            . sprintf('%02d:%02d', intdiv($secs % 86400, 3600), intdiv($secs % 3600, 60));

    // 顶栏显示的 Unraid 版本号，只为观感一致，可用 CB_DEV_UNRAID_VERSION 改
    $version = trim((string)(getenv('CB_DEV_UNRAID_VERSION') ?: '')) ?: '7.3.2';

    return ['host' => $host, 'version' => $version, 'uptime' => $uptime];
}

/** 渲染一个 .page */
function cb_dev_render(string $pluginDir, string $page, string $devRoot, string $seedHint, string $current = '/'): void
{
    $file = "$pluginDir/$page";
    header('Content-Type: text/html; charset=utf-8');
    if (!is_file($file)) {
        http_response_code(500);
        echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"></head><body>' . $seedHint . '</body></html>';
        return;
    }
    $rel = "source/unraid-certbot/usr/local/emhttp/plugins/unraid-certbot/$page";
    echo cb_dev_render_page($file, cb_dev_header_ctx($devRoot) + [
        'dev_root' => $devRoot,
        'source'   => $rel,
        'current'  => $current,
    ]);
}

/** 调试首页：汇总沙箱状态和快捷入口 */
function cb_dev_index(string $devRoot, string $docrootPath, string $pluginDir, string $seedHint): void
{
    $statusFile = "$pluginDir/include/status.php";
    $loaded     = false;

    if (is_file($statusFile)) {
        define('CB_NO_CLI_OUTPUT', true);
        require_once $statusFile;
        $loaded = true;
    }

    $rows = [
        'PHP'        => PHP_VERSION . ' (' . PHP_SAPI . ')',
        '沙箱根目录' => $devRoot,
        '$docroot'   => $docrootPath,
        '插件目录'   => is_dir($pluginDir) ? $pluginDir . '（软链到源码）' : '缺失',
        '配置目录'   => $loaded ? CB_CFG_DIR : '',
    ];

    $body = '<p>这是 unraid-certbot 的本地调试首页。所有读写都发生在沙箱目录里，'
          . '可以放心点按钮、改配置、跑续期。</p>';

    $body .= '<h3>快捷入口</h3><ul>'
           . '<li><a href="/Settings/UnraidCertbot">设置页（Settings → Unraid Certbot）</a></li>'
           . '<li><a href="/Utilities/CertStatus">状态页（Utilities → Cert Status）</a></li>'
           . '<li><a href="/plugins/unraid-certbot/include/exec.php?action=docker" '
           . 'onclick="openBox(this.href, \'Docker 环境检查\', 820, 560); return false;">'
           . '打开 Docker 环境检查（假 docker）</a></li>'
           . '<li><a href="/plugins/unraid-certbot/include/exec.php?action=renew" '
           . 'onclick="openBox(this.href, \'检查并续期\', 820, 560); return false;">'
           . '跑一次续期（假 docker，不联网）</a></li>'
           . '</ul>';

    $body .= <<<'HTML'
<h3>命令行</h3>
<pre><code>./dev.sh status          # renew.sh --status
./dev.sh renew           # 在沙箱里跑一次续期
./dev.sh renew --force   # 强制续期
./dev.sh reset           # 重建沙箱
./lint.sh                # 静态检查</code></pre>
HTML;

    if ($loaded) {
        $cfg = cb_load_cfg();
        $st  = cb_status($cfg);

        $rows += [
            'Unraid 主机名' => $st['host'] !== '' ? cb_e($st['host']) : '<span class="grey-text">未配置</span>',
            '主域名'        => $st['primary'] !== '' ? cb_e($st['primary']) : '<span class="grey-text">未配置</span>',
            '证书状态'      => $st['bundle']
                ? cb_e((string)$st['bundle']['subject']) . '，' . cb_days_html($st['bundle']['days'])
                : '<span class="grey-text">尚未签发</span>',
            'Docker（假）'  => $st['docker_ok'] ? '<span class="green-text">可用</span>' : '<span class="red-text">不可用</span>',
            '上次续期'      => $st['last_run'] ? cb_e($st['last_run']['time'] . ' ' . $st['last_run']['result']) : '无记录',
        ];
    } else {
        $body .= $seedHint;
    }

    $table = '<table><tbody>';
    foreach ($rows as $k => $v) {
        $table .= '<tr><th style="width:22rem">' . cb_e($k) . '</th><td>' . $v . '</td></tr>';
    }
    $table .= '</tbody></table>';

    header('Content-Type: text/html; charset=utf-8');
    echo cb_dev_chrome(
        ['Title' => 'unraid-certbot 本地调试', 'Menu' => 'Dev'],
        $body . '<h3>沙箱状态</h3>' . $table,
        cb_dev_header_ctx($devRoot) + ['dev_root' => $devRoot, 'source' => 'dev/server.php', 'current' => '/']
    );
}

/**
 * 仿真 Unraid 的设置保存流程（/usr/local/emhttp/update.php 的最小实现）：
 *   1. 先跑表单里 #include 指向的校验脚本（插件的 include/update.php）
 *   2. 脚本把 $save 置为 false 就拒绝写入
 *   3. 否则把 $_POST 里除 # 开头的字段写成 .cfg
 */
function cb_dev_update(string $docroot): void
{
    header('Content-Type: text/html; charset=utf-8');

    // 先加载插件状态助手：路径常量（CB_CFG_DIR 等）由它按沙箱规则算出来
    $statusFile = "$docroot/plugins/unraid-certbot/include/status.php";
    if (is_file($statusFile)) {
        define('CB_NO_CLI_OUTPUT', true);
        require_once $statusFile;
    } else {
        http_response_code(500);
        echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"></head><body>'
           . '<p>找不到插件文件，请先运行 <code>./dev.sh</code> 初始化沙箱。</p></body></html>';
        return;
    }

    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
       . '<title>保存设置</title></head><body style="margin:0">';
    if (is_file("$docroot/logging.htm")) {
        readfile("$docroot/logging.htm");
    }
    echo '<script>function cbDevFinish(ok,msg){if(window.parent&&parent.cbUpdateDone)parent.cbUpdateDone(ok,msg);}</script>';

    $file    = (string)($_POST['#file'] ?? '');
    $include = (string)($_POST['#include'] ?? '');
    $pluginsDir = dirname(CB_CFG_DIR);
    $save    = true;

    cb_dev_addlog('=== 保存设置 ===');

    $rel = ltrim(str_replace('\\', '/', $file), '/');
    if ($rel === '' || strpos($rel, '..') !== false) {
        cb_dev_addlog('❌ 表单里缺少有效的 #file 字段');
        $save = false;
    }

    if ($save && $include !== '') {
        // 只允许 docroot 下的路径，挡掉 ../ 之类的构造
        $incFile = $docroot . $include;
        if (strpos($include, '..') !== false || !is_file($incFile)) {
            cb_dev_addlog('❌ 找不到校验脚本：' . $include);
            $save = false;
        } else {
            // 插件的 update.php 会把校验结果通过 addLog 打出来，并可能把 $save 置为 false
            include $incFile;
        }
    }

    if ($save) {
        $cfgPath = $pluginsDir . '/' . $rel;
        $lines   = [];
        foreach ($_POST as $k => $v) {
            if ($k === '' || $k[0] === '#' || is_array($v)) {
                continue;
            }
            // Token 由 update.php 单独写进 cloudflare.ini；正常情况下它已经把这两个
            // 字段从 $_POST 摘掉了，这里再兜一层，避免异常请求把 Token 写进 .cfg
            if ($k === 'CF_API_TOKEN_NEW' || $k === 'CF_API_TOKEN_CLEAR') {
                continue;
            }
            $lines[] = $k . '="' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string)$v) . '"';
        }
        if (!is_dir(dirname($cfgPath))) {
            @mkdir(dirname($cfgPath), 0700, true);
        }
        if (@file_put_contents($cfgPath, implode("\n", $lines) . "\n", LOCK_EX) === false) {
            cb_dev_addlog('❌ 写入配置失败：' . $cfgPath);
            echo '<script>cbDevFinish(false, "写入配置失败")</script></body></html>';
            return;
        }
        cb_dev_addlog('✅ 已写入 ' . $cfgPath);

        $cronFile = CB_CFG_DIR . '/renew.cron';
        if (is_file($cronFile)) {
            cb_dev_addlog('cron 条目：' . trim((string)file_get_contents($cronFile)));
        } else {
            cb_dev_addlog('cron 条目：已清空（自动续期关闭）');
        }
        echo '<script>cbDevFinish(true, "设置已保存")</script>';
    } else {
        echo '<script>cbDevFinish(false, "设置未保存，请看上面的原因")</script>';
    }

    echo '</body></html>';
}

/** 往设置保存页面的日志框里追加一行 */
function cb_dev_addlog(string $msg): void
{
    $msg = str_replace(["\n", '"'], ['<br>', '\\"'], $msg);
    echo '<script>addLog("' . $msg . '");</script>';
    @flush();
}
