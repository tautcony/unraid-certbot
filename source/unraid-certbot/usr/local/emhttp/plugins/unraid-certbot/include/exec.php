<?php
/**
 * unraid-certbot - 动作执行端点
 *
 * 界面上的按钮通过 openBox() 打开这个文件，命令输出用 addLog() 实时回传。
 *
 * 安全：这里只接受白名单里的动作名，命令本身完全由常量拼成，
 * 用户输入不会出现在 shell 里。这个端点以 root 运行，改动请务必保守。
 */

$docroot = $docroot ?? ($_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp');
require_once "$docroot/plugins/dynamix/include/Wrappers.php";

// exec.php 的输出全部是给界面 addLog 的 <script> 片段，禁止任何额外输出混进来
define('CB_NO_CLI_OUTPUT', true);

$plugin    = 'unraid-certbot';
// 用 $docroot 推导而不是写死 /usr/local/emhttp，便于在测试环境里跑，也兼容非标准安装
$pluginDir = "{$docroot}/plugins/{$plugin}";
$script    = "{$pluginDir}/scripts/renew.sh";
$logFile   = "/boot/config/plugins/{$plugin}/certbot.log";

header('Content-Type: text/html; charset=utf-8');

/**
 * 动作白名单：动作名 => [命令行参数数组, 界面标题]
 * 命令固定是 renew.sh，参数也全部是写死的常量。
 */
$actions = [
    'renew'  => [['--trigger=webgui'],                 '立即检查并续期'],
    'force'  => [['--force', '--trigger=webgui'],      '强制续期'],
    'status' => [['--status'],                         '查看当前状态'],
    'docker' => [[],                                   '检查 Docker 环境'],
];

$action = (string)($_GET['action'] ?? $_POST['action'] ?? 'renew');
if (!isset($actions[$action])) {
    http_response_code(400);
    echo '<p style="color:#c33;font-family:sans-serif">不支持的操作：' . htmlspecialchars($action) . '</p>';
    exit;
}

[$args, $title] = $actions[$action];

// 输出流：优先用 Unraid 自带的 logging.htm（提供 addLog 与进度条样式）
echo '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0">';
$loggingHtm = "$docroot/logging.htm";
if (is_file($loggingHtm)) {
    readfile($loggingHtm);
} else {
    // 兜底：logging.htm 不在时自带一份等价的实现
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
    // 单纯的排障动作：把 docker 环境情况打出来
    foreach ([
        'docker 路径'   => 'command -v docker',
        'docker 版本'   => 'docker --version',
        '守护进程'      => 'docker info --format "{{.ServerVersion}} / {{.Driver}}"',
        'certbot 镜像'  => 'docker image inspect certbot/dns-cloudflare --format "{{.Id}} {{.Created}}"',
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
    write_log('❌ 找不到脚本 ' . $script . '，插件文件可能不完整，请重新安装插件');
    write_log('=== 结束 ===');
    exit;
}
if (!is_executable($script)) {
    write_log('⚠️ 脚本没有可执行权限，尝试修复...');
    @chmod($script, 0755);
    if (!is_executable($script)) {
        write_log('❌ 无法修复可执行权限，请执行：chmod 755 ' . $script);
        write_log('=== 结束 ===');
        exit;
    }
}

$cmd = escapeshellarg($script);
foreach ($args as $a) {
    $cmd .= ' ' . escapeshellarg($a);
}

if (!is_writable(dirname($logFile)) && !is_dir(dirname($logFile))) {
    write_log('⚠️ 配置目录 ' . dirname($logFile) . ' 不存在，正在创建');
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
        1 => '配置有误，请回到设置页检查邮箱 / 主机名 / 域名 / Token。',
        2 => 'certbot 执行失败，常见原因：Token 权限不足（需要 Zone→DNS→Edit）、域名不在该 Cloudflare 账号下、或 API 限流。',
        3 => '已有一次续期正在进行，请稍后再试。',
        4 => 'Docker 服务不可用，通常是阵列未启动。',
    ];
    if (isset($hints[$rc])) {
        write_log('提示：' . $hints[$rc]);
    }
}
echo '</body></html>';
