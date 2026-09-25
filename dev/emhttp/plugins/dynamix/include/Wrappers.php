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
    /** 本地模拟 Unraid 的页面词条加载与键规范化。 */
    function _($text)
    {
        global $docroot;
        static $language = null;
        if ($language === null) {
            $language = [];
            $locale = (string)(getenv('CB_DEV_LOCALE') ?: 'zh_CN');
            if (preg_match('/^[a-z]{2}_[A-Z]{2}$/', $locale)) {
                $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
                $parts = array_filter(explode('/', strtolower((string)$path)));
                if (in_array('unraidcertbot', $parts, true)) {
                    $parts[] = 'unraid-certbot';
                }
                foreach ($parts as $part) {
                    $file = "$docroot/languages/$locale/$part.txt";
                    if (!is_file($file)) {
                        continue;
                    }
                    $source = file_get_contents($file);
                    $escaped = str_replace(["\"\n", '"'], ["\" \n", '\\"'], $source);
                    $parsed = parse_ini_string(preg_replace(
                        ['/^\s*?(null|yes|no|true|false|on|off|none)\s*?=/mi', '/^\s*?([^>].*?)\s*?=\s*?(.*)\s*?$/m'],
                        ['$1.=', '$1="$2"'],
                        $escaped
                    ));
                    if (is_array($parsed)) {
                        $language = array_replace($language, $parsed);
                    }
                }
            }
        }
        $text = trim((string)$text);
        if ($text === '') {
            return '';
        }
        $key = preg_replace(
            ['/\&amp;|[\?\{\}\|\&\~\!\[\]\(\)\/\\:\*^\.\"\']|<.+?\/?>/', '/^(null|yes|no|true|false|on|off|none)$/i', '/  +/'],
            ['', '$1.', ' '],
            $text
        );
        return preg_replace(
            ['/\*\*(.+?)\*\*/', '/\*(.+?)\*/', "/'/"],
            ['<b>$1</b>', '<i>$1</i>', '&apos;'],
            $language[$key] ?? $text
        );
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
            return null;
        }
        @mkdir(dirname($file), 0700, true);
        $text = rtrim((string)$text, "\n") . "\n";
        $skipOnce = (string)getenv('CB_DEV_CRON_SKIP_ONCE');
        if ($skipOnce !== '' && !is_file($skipOnce)) {
            @file_put_contents($skipOnce, '1');
            return null;
        }
        $ok = @file_put_contents($file, $text, LOCK_EX) !== false;
        $failOnce = (string)getenv('CB_DEV_CRON_FAIL_ONCE');
        if ($failOnce !== '' && !is_file($failOnce)) {
            @file_put_contents($failOnce, '1');
            return false;
        }
        return $ok ? null : false;
    }
}

if (!function_exists('addLog')) {
    /** 占位，避免被当 PHP 调用时报错 */
    function addLog($text = '')
    {
        return $text;
    }
}
