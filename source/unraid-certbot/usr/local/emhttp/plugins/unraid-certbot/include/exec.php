<?php
/**
 * unraid-certbot - 动作执行端点
 *
 * 安全：仅接受白名单内的动作名，命令均由常量拼接构成，
 * 用户输入不会进入 shell。本端点以 root 运行，修改须谨慎。
 */

$docroot = $docroot ?? ($_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp');
require_once "$docroot/plugins/dynamix/include/Wrappers.php";

// exec.php 的输出全部为界面 addLog 的 <script> 片段，禁止混入其他输出
define('CB_NO_CLI_OUTPUT', true);

require_once "$docroot/plugins/unraid-certbot/include/status.php";

$plugin    = 'unraid-certbot';
// 通过 $docroot 推导而非硬编码 /usr/local/emhttp，便于测试环境运行，并兼容非标准安装
$pluginDir = "{$docroot}/plugins/{$plugin}";
$script    = "{$pluginDir}/scripts/renew.sh";
// 日志路径遵循 status.php 的沙箱规则
$logFile   = CB_LOG;

header('Content-Type: text/html; charset=utf-8');

/**
 * 动作白名单：动作名 => [命令行参数数组, 界面标题]
 * 命令固定为 renew.sh，参数均为预设常量。
 */
$actions = [
    'renew'  => [['--trigger=webgui'],                 cb_t('Check and renew now')],
    'force'  => [['--force', '--trigger=webgui'],      cb_t('Force renewal')],
    'status' => [['--status'],                         cb_t('View current status')],
    'docker' => [[],                                   cb_t('Check Docker environment')],
];

$method = (string)($_SERVER['REQUEST_METHOD'] ?? '');
if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    exit;
}
$action = (string)($method === 'POST' ? ($_POST['action'] ?? '') : ($_GET['action'] ?? ''));
if (!isset($actions[$action])) {
    http_response_code(400);
    echo '<p style="color:#c33;font-family:sans-serif">' . cb_t('Unsupported action') . ': ' . htmlspecialchars($action) . '</p>';
    exit;
}

if ($method === 'GET') {
    header('Cache-Control: no-store');
    $postUrl = "/plugins/{$plugin}/include/exec.php";
    ?>
    <!DOCTYPE html><html><head><meta charset="utf-8"></head><body>
    <form id="cb-action" method="post" action="<?=htmlspecialchars($postUrl, ENT_QUOTES, 'UTF-8')?>">
      <input type="hidden" name="action" value="<?=htmlspecialchars($action, ENT_QUOTES, 'UTF-8')?>">
      <input type="hidden" name="csrf_token">
    </form>
    <script>
    try {
      if (window.parent !== window && window.parent.location.origin === location.origin) {
        var token = window.parent.document.querySelector('input[name="csrf_token"]');
        if (token && token.value) {
          var form = document.getElementById('cb-action');
          form.elements.csrf_token.value = token.value;
          form.submit();
        }
      }
    } catch (e) {}
    </script></body></html>
    <?php
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

[$args, $title] = $actions[$action];

// 输出流：优先使用 Unraid 自带的 logging.htm（提供 addLog 与进度条样式）
echo '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0">';
$loggingHtm = "$docroot/logging.htm";
if (is_file($loggingHtm)) {
    readfile($loggingHtm);
} else {
    // 回退：logging.htm 缺失时使用等价的内置实现
    ?>
    <textarea id="log" rows="20" style="width:100%;font-family:monospace" readonly></textarea>
    <script>
    function addLog(s) {
        var t = document.getElementById('log');
        if (!t) return;
        t.value += s + '\n';
        t.scrollTop = t.scrollHeight;
    }
    </script>
    <?php
}

function write_log(string $string): void
{
    if ($string === '') {
        return;
    }
    $encoded = json_encode(htmlspecialchars(trim($string), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
    echo "<script>addLog({$encoded});</script>";
    @flush();
}

write_log("=== {$title} ===");
write_log('');

if ($action === 'docker') {
    // 排障动作：输出 docker 环境信息
    $dk = escapeshellarg(cb_docker_cmd());
    foreach ([
        cb_t('Docker path')   => "command -v " . $dk,
        cb_t('Docker version') => $dk . ' --version',
        cb_t('Docker daemon')  => $dk . ' info --format "{{.ServerVersion}} / {{.Driver}}"',
        cb_t('certbot image')  => $dk . ' image inspect certbot/dns-cloudflare --format "{{.Id}} {{.Created}}"',
    ] as $label => $cmd) {
        write_log("--- {$label} ---");
        $p = popen($cmd . ' 2>&1', 'r');
        if ($p) {
            while (!feof($p)) {
                write_log((string)fgets($p));
            }
            pclose($p);
        }
        write_log('');
    }
    write_log('=== ' . cb_t('Finished') . ' ===');
    exit;
}

if (!is_file($script)) {
    write_log(cb_t('Plugin files are incomplete. Reinstall the plugin.'));
    write_log('=== ' . cb_t('Finished') . ' ===');
    exit;
}
if (!is_executable($script)) {
    write_log(cb_t('Repairing script permissions...'));
    @chmod($script, 0755);
    if (!is_executable($script)) {
        write_log(cb_t('Cannot repair script permissions'));
        write_log('=== ' . cb_t('Finished') . ' ===');
        exit;
    }
}

$cmd = escapeshellarg($script);
foreach ($args as $a) {
    $cmd .= ' ' . escapeshellarg($a);
}

if (!is_writable(dirname($logFile)) && !is_dir(dirname($logFile))) {
    write_log(cb_t('Creating missing configuration directory'));
    @mkdir(dirname($logFile), 0700, true);
}

$proc = popen($cmd . ' 2>&1', 'r');
if (!$proc) {
    write_log(cb_t('Cannot start renewal script'));
    write_log('=== ' . cb_t('Finished') . ' ===');
    exit;
}

while (!feof($proc)) {
    write_log((string)fgets($proc));
}
$rc = pclose($proc);

write_log('');
if ($rc === 0) {
    write_log('=== ' . sprintf(cb_t('Completed (exit code %d)'), 0) . ' ===');
} else {
    write_log('=== ' . sprintf(cb_t('Finished (exit code %d)'), $rc) . ' ===');
    $hints = [
        1 => 'Invalid configuration. Check Settings.',
        2 => 'certbot failed. Common causes: insufficient token permissions (Zone > DNS > Edit) or a domain outside the authorized Cloudflare account.',
        3 => 'A renewal is already running. Try again later.',
        4 => 'Docker is unavailable, usually because the array is stopped.',
    ];
    if (isset($hints[$rc])) {
        write_log(cb_t('Hint') . ': ' . cb_t($hints[$rc]));
    }
}
echo '</body></html>';
