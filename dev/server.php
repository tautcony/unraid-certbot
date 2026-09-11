<?php
/**
 * 本地预览服务器（php -S 的路由脚本）。
 *
 *   ./dev.sh                                              # 初始化沙箱并启动
 *   php -S 127.0.0.1:8080 -t dev/run/usr/local/emhttp dev/server.php
 *
 * 路由：/（调试首页）、/Settings/UnraidCertbot（设置页）、插件端点
 * （/plugins/unraid-certbot/**.php）、/update.php（仿真保存流程）；
 * 沙箱里真实存在的其它文件交给内置服务器。
 */

$ROOT = dirname(__DIR__);

$DEV_ROOT = rtrim((string)(getenv('CB_DEV_ROOT') ?: ''), '/');
if ($DEV_ROOT === '') {
    $DEV_ROOT = "$ROOT/dev/run";
}
$DOCROOT = "$DEV_ROOT/usr/local/emhttp";

// 插件里的 getenv() / exec() 也走沙箱
putenv("CB_DEV_ROOT=$DEV_ROOT");
putenv('CB_DOCKER=' . (getenv('CB_DOCKER') ?: "$DEV_ROOT/bin/docker"));
putenv('PATH=' . "$DEV_ROOT/bin:" . (string)getenv('PATH'));

// PHP 时区可能是 UTC 而 shell 用系统时区，差 8 小时会让「上次续期」算成「未来」
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

$uri = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$uri = '/' . ltrim(rawurldecode($uri), '/');
if ($uri !== '/' && substr($uri, -1) === '/') {
    $uri = rtrim($uri, '/');
}

if (strpos($uri, '..') !== false) {
    http_response_code(400);
    echo '非法路径';
    return true;
}

// 插件目录里的 .php 端点要由路由接（真机上是 PHP 执行，不是当静态文件发出去），
// 所以静态文件短路必须放在路由之后，见文件末尾的 cb_dev_static()。

switch (true) {
    case $uri === '/' || $uri === '/index.php':
        cb_dev_index($DEV_ROOT, $DOCROOT, $PLUGIN_DIR, $SEED_HINT);
        return true;

    case strcasecmp($uri, '/Settings/UnraidCertbot') === 0:
        cb_dev_render($PLUGIN_DIR, 'unraid-certbot.page', $DEV_ROOT, $SEED_HINT, $uri);
        return true;

    // 插件端点（exec.php 等）：真机上由 PHP 执行，不能当静态文件发出去
    case preg_match('#^/plugins/unraid-certbot/[A-Za-z0-9_./-]+\.php$#', $uri) === 1:
        cb_dev_endpoint($PLUGIN_DIR, ltrim($uri, '/'), $DEV_ROOT);
        return true;

    case $uri === '/update.php':
        cb_dev_update($DOCROOT);
        return true;
}

// 没命中路由：沙箱里真实存在的文件（页面样式表、图片、其它 .php）交给内置服务器
if ($uri !== '/' && is_file("$DOCROOT$uri")) {
    return false;
}

http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><title>404</title></head><body>';
echo '<p>本地预览里没有这个地址：<code>' . htmlspecialchars($uri, ENT_QUOTES) . '</code></p>';
echo '<p><a href="/">回到调试首页</a></p></body></html>';
return true;

/** 顶栏展示用的服务器名、版本、运行时间 */
function cb_dev_header_ctx(string $devRoot): array
{
    $host = 'tower';
    $ini  = "$devRoot/var/local/emhttp/var.ini";
    if (is_file($ini) && preg_match('/^\s*NAME\s*=\s*"?([^"\r\n]+)"?/m', (string)file_get_contents($ini), $m)) {
        $host = trim($m[1]);
    } elseif (function_exists('gethostname') && gethostname()) {
        $host = (string)gethostname();
    }

    // 每个请求都是新进程，用文件记住首次启动时间才能让 Uptime 走起来
    $startFile = "$devRoot/.dev-start";
    $start     = is_file($startFile) ? (int)file_get_contents($startFile) : 0;
    if ($start <= 0) {
        $start = time();
        @file_put_contents($startFile, (string)$start);
    }
    $secs   = max(0, time() - $start);
    $uptime = intdiv($secs, 86400) . ' 天 '
            . sprintf('%02d:%02d', intdiv($secs % 86400, 3600), intdiv($secs % 3600, 60));

    $version = trim((string)(getenv('CB_DEV_UNRAID_VERSION') ?: '')) ?: '7.3.2';

    return ['host' => $host, 'version' => $version, 'uptime' => $uptime];
}

/** 渲染一个 .page；$box 保留给以后可能出现的对话框形态页面 */
function cb_dev_render(string $pluginDir, string $page, string $devRoot, string $seedHint, string $current = '/', bool $box = false): void
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
        'box'      => $box,
        'title'    => 'Unraid Certbot 设置',
    ]);
}

/**
 * 执行一个插件端点（include/exec.php 等）。
 * 真机上这些 .php 由 PHP 直接执行，预览里也按原样跑：它们自带输出，
 * exec.php 还会自己 readfile(logging.htm) 拿到 addLog 与按钮样式。
 */
function cb_dev_endpoint(string $pluginDir, string $rel, string $devRoot): void
{
    $file = $pluginDir . '/' . preg_replace('#^plugins/unraid-certbot/#', '', $rel);
    header('Content-Type: text/html; charset=utf-8');
    if (!is_file($file)) {
        http_response_code(404);
        echo '<p>找不到端点 ' . htmlspecialchars($rel, ENT_QUOTES, 'UTF-8') . '</p>';
        return;
    }

    global $docroot;
    $docroot = "$devRoot/usr/local/emhttp";

    include $file;
}

/** 调试首页 */
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

    $body = '<p>unraid-certbot 本地调试首页，所有读写均在沙箱内。</p>';

    $body .= '<h3>快捷入口</h3><ul>'
           . '<li><a href="/Settings/UnraidCertbot">设置页（设置 → Unraid Certbot，配置 / 状态 / 历史 / 日志）</a></li>'
           . '<li><a href="/Settings/UnraidCertbot?tab=status">证书状态标签</a>'
           . '（<a href="/Settings/UnraidCertbot?tab=history">续期历史</a>'
           . ' / <a href="/Settings/UnraidCertbot?tab=log">运行日志</a>）</li>'
           . '<li><a href="/plugins/unraid-certbot/include/exec.php?action=docker" '
           . 'onclick="openBox(this.href, \'Docker 环境检查\', 820, 560); return false;">'
           . 'Docker 环境检查</a></li>'
           . '<li><a href="/plugins/unraid-certbot/include/exec.php?action=renew" '
           . 'onclick="openBox(this.href, \'检查并续期\', 820, 560); return false;">'
           . '执行续期（不联网）</a></li>'
           . '</ul>';

    $body .= <<<'HTML'
<h3>命令行</h3>
<pre><code>./dev.sh status          # renew.sh --status
./dev.sh renew           # 在沙箱中执行一次续期
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
 * 仿真 Unraid 的 update.php：先跑 #include 指向的校验脚本，脚本把 $save 置为 false
 * 就拒绝写入，否则把 $_POST 里非 # 开头的字段写成 .cfg。
 */
function cb_dev_update(string $docroot): void
{
    header('Content-Type: text/html; charset=utf-8');

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

    $file       = (string)($_POST['#file'] ?? '');
    $include    = (string)($_POST['#include'] ?? '');
    $pluginsDir = dirname(CB_CFG_DIR);
    $save       = true;

    cb_dev_addlog('=== 保存设置 ===');

    $rel = ltrim(str_replace('\\', '/', $file), '/');
    if ($rel === '' || strpos($rel, '..') !== false) {
        cb_dev_addlog('❌ 表单里缺少有效的 #file 字段');
        $save = false;
    }

    if ($save && $include !== '') {
        $incFile = $docroot . $include;
        if (strpos($include, '..') !== false || !is_file($incFile)) {
            cb_dev_addlog('找不到校验脚本：' . $include);
            $save = false;
        } else {
            include $incFile; // 校验脚本自己 addLog，并可能把 $save 置为 false
        }
    }

    if ($save) {
        $cfgPath = $pluginsDir . '/' . $rel;
        $lines   = [];
        foreach ($_POST as $k => $v) {
            // Token 由 update.php 单独写进 cloudflare.ini，这里再兜一层
            if ($k === '' || $k[0] === '#' || is_array($v)
                || $k === 'CF_API_TOKEN_NEW' || $k === 'CF_API_TOKEN_CLEAR') {
                continue;
            }
            $lines[] = $k . '="' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string)$v) . '"';
        }
        if (!is_dir(dirname($cfgPath))) {
            @mkdir(dirname($cfgPath), 0700, true);
        }
        if (@file_put_contents($cfgPath, implode("\n", $lines) . "\n", LOCK_EX) === false) {
            cb_dev_addlog('写入配置失败：' . $cfgPath);
            echo '<script>cbDevFinish(false, "写入配置失败")</script></body></html>';
            return;
        }
        cb_dev_addlog('已写入 ' . $cfgPath);

        $cronFile = CB_CFG_DIR . '/renew.cron';
        if (is_file($cronFile)) {
            cb_dev_addlog('cron 条目：' . trim((string)file_get_contents($cronFile)));
        } else {
            cb_dev_addlog('cron 条目：已清空（自动续期关闭）');
        }
        echo '<script>cbDevFinish(true, "设置已保存")</script>';
    } else {
        echo '<script>cbDevFinish(false, "设置未保存")</script>';
    }

    echo '</body></html>';
}

/** 往保存页面的日志框追加一行 */
function cb_dev_addlog(string $msg): void
{
    $msg = str_replace(["\n", '"'], ['<br>', '\\"'], $msg);
    echo '<script>addLog("' . $msg . '");</script>';
    @flush();
}
