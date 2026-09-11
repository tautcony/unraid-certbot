<?php
/**
 * 本地调试用的 Wrappers.php 仿真：只实现插件用到的几个函数，不是 Unraid 原文件。
 */

if (!function_exists('cb_dev_path')) {
    /** 把 Unraid 绝对路径映射到 CB_DEV_ROOT 沙箱下 */
    function cb_dev_path(string $path): string
    {
        $root = rtrim((string)getenv('CB_DEV_ROOT'), '/');
        if ($root === '' || $path === '') {
            return $path;
        }
        if ($path === $root || strpos($path, $root . '/') === 0) {
            return $path;
        }
        return $path[0] === '/' ? $root . $path : $root . '/' . $path;
    }
}

if (!function_exists('_')) {
    /** 本地不加载语言包，恒等返回 */
    function _($text)
    {
        return $text;
    }
}

if (!function_exists('parse_plugin_cfg')) {
    /** default.cfg + 用户 cfg 合并，用户优先 */
    function parse_plugin_cfg($plugin, $sections = false, $translate = false)
    {
        global $docroot;

        $cfg = [];
        foreach ([
            "{$docroot}/plugins/{$plugin}/default.cfg",
            cb_dev_path("/boot/config/plugins/{$plugin}/{$plugin}.cfg"),
        ] as $file) {
            if (!is_file($file)) {
                continue;
            }
            $parsed = @parse_ini_file($file, (bool)$sections, INI_SCANNER_RAW);
            if (is_array($parsed)) {
                $cfg = array_replace($cfg, $parsed);
            }
        }
        return $cfg;
    }
}

if (!function_exists('mk_option')) {
    function mk_option($select, $value, $text = '', $extra = '')
    {
        if (is_array($value)) {
            $value = (string)reset($value);
        }
        $selected = ((string)$select === (string)$value) ? ' selected' : '';
        $extra    = ($extra !== '') ? ' ' . $extra : '';
        return sprintf(
            '<option value="%s"%s%s>%s</option>',
            htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'),
            $selected,
            $extra,
            $text
        );
    }
}

if (!function_exists('parse_cron_cfg')) {
    /** 写沙箱里的 .cron 文件，空文本表示删除；不动宿主机 crontab */
    function parse_cron_cfg($plugin, $name, $text = '')
    {
        $file = cb_dev_path("/boot/config/plugins/{$plugin}/{$name}.cron");
        if (trim((string)$text) === '') {
            @unlink($file);
            return true;
        }
        @mkdir(dirname($file), 0700, true);
        $text = rtrim((string)$text, "\n") . "\n";
        return @file_put_contents($file, $text, LOCK_EX) !== false;
    }
}

if (!function_exists('addLog')) {
    /** 占位，避免被当 PHP 调用时报错 */
    function addLog($text = '')
    {
        return $text;
    }
}
