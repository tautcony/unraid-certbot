<?php
/**
 * 本地调试用的 Wrappers.php 仿真实现。
 *
 * 这不是 Unraid 的原文件，只是把插件真正用到的那几个函数按相同语义实现一遍，
 * 好让 .page / include/*.php 能在开发机上直接跑起来。seed.sh 会把它软链到
 * 沙箱的 $docroot/plugins/dynamix/include/Wrappers.php。
 *
 * 覆盖的函数：
 *   _()                文案翻译，本地不做翻译，原样返回
 *   parse_plugin_cfg() default.cfg + 用户 .cfg 合并，用户优先
 *   mk_option()        生成 <option>，用于设置页的下拉框
 *   parse_cron_cfg()   把 cron 条目写进沙箱，不碰宿主机 crontab
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
    /** Unraid 的翻译函数。本地不加载语言包，恒等返回。 */
    function _($text)
    {
        return $text;
    }
}

if (!function_exists('parse_plugin_cfg')) {
    /**
     * 等价于 Unraid 的 parse_plugin_cfg()：先读插件自带的 default.cfg，
     * 再用 /boot/config/plugins/<插件>/<插件>.cfg 覆盖。
     */
    function parse_plugin_cfg($plugin, $sections = false, $translate = false)
    {
        global $docroot;

        $cfg = [];
        // 顺序即优先级：default.cfg 先读，用户 cfg 后读并覆盖之
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
    /**
     * 生成一个 <option>。$select 是当前值，$value 是本项的值，相等则选中。
     */
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
    /**
     * 等价于 Unraid 的 parse_cron_cfg()：把一段 cron 文本写到插件的 .cron 文件。
     * 传空字符串表示删除该条目（关闭定时任务）。
     * 本地调试只写沙箱文件，不去动 crontab。
     */
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
    /** 真机上这是 JS 函数；这里只作为占位，防止被当 PHP 调用时报错 */
    function addLog($text = '')
    {
        return $text;
    }
}
