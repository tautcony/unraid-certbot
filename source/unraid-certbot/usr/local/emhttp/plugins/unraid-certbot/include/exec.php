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
    'renew'  => [['--trigger=webgui'],                 _('Check and renew now')],
    'force'  => [['--force', '--trigger=webgui'],      _('Force renewal')],
    'status' => [['--status'],                         _('View current status')],
    'docker' => [[],                                   _('Check Docker environment')],
];

$action = (string)($_GET['action'] ?? $_POST['action'] ?? 'renew');
if (!isset($actions[$action])) {
    http_response_code(400);
    echo '<p style="color:#c33;font-family:sans-serif">' . _('Unsupported action') . ': ' . htmlspecialchars($action) . '</p>';
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
    $string = str_replace("\n", "<br>", $string);
    $string = str_replace('"', "\\\"", trim($string));
    echo "<script>addLog(\"{$string}\");</script>";
    @flush();
}

write_log("=== {$title} ===");
write_log('');

if ($action === 'docker') {
    // 排障动作：输出 docker 环境信息
    $dk = escapeshellarg(cb_docker_cmd());
    foreach ([
        'docker 路径'   => "command -v " . $dk,
        'docker 版本'   => $dk . ' --version',
        '守护进程'      => $dk . ' info --format "{{.ServerVersion}} / {{.Driver}}"',
        'certbot 镜像'  => $dk . ' image inspect certbot/dns-cloudflare --format "{{.Id}} {{.Created}}"',
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
    write_log('=== 结束 ===');
    exit;
}

if (!is_file($script)) {
    write_log('❌ 插件文件不完整，请重新安装');
    write_log('=== 结束 ===');
    exit;
}
if (!is_executable($script)) {
    write_log('⚠️ 正在修复脚本权限...');
    @chmod($script, 0755);
    if (!is_executable($script)) {
        write_log('❌ 无法修复脚本权限');
        write_log('=== 结束 ===');
        exit;
    }
}

$cmd = escapeshellarg($script);
foreach ($args as $a) {
    $cmd .= ' ' . escapeshellarg($a);
}

if (!is_writable(dirname($logFile)) && !is_dir(dirname($logFile))) {
    write_log('⚠️ 配置目录不存在，正在创建');
    @mkdir(dirname($logFile), 0700, true);
}

$proc = popen($cmd . ' 2>&1', 'r');
if (!$proc) {
    write_log('❌ 无法启动脚本');
    write_log('=== 结束 ===');
    exit;
}

while (!feof($proc)) {
    write_log((string)fgets($proc));
}
$rc = pclose($proc);

write_log('');
if ($rc === 0) {
    write_log('=== 完成（退出码 0）===');
} else {
    write_log("=== 结束，退出码 {$rc} ===");
    $hints = [
        1 => '配置有误，请检查设置页。',
        2 => 'certbot failed. Common causes: insufficient token permissions (Zone > DNS > Edit) or a domain outside the authorized Cloudflare account.',
        3 => 'A renewal is already running. Try again later.',
        4 => 'Docker is unavailable, usually because the array is stopped.',
    ];
    if (isset($hints[$rc])) {
        write_log('提示：' . $hints[$rc]);
    }
}
echo '</body></html>';
