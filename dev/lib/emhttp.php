<?php
/**
 * 本地预览用的 .page 渲染仿真。
 * 外壳与控件样式取自 Unraid 7 webGUI 源码，差异见 dev/README.md。
 */

/** Unraid 官方 wordmark（来自 webGui Header.php） */
const CB_DEV_LOGO_PATH = 'M146.7,29.47H135l-3,9h-6.49L138.93,0h8l13.41,38.49h-7.09L142.62,6.93l-5.83,16.88h8Z'
    . 'M29.69,0V25.4c0,8.91-5.77,13.64-14.9,13.64S0,34.31,0,25.4V0H6.54V25.4c0,5.17,3.19,7.92,8.25,7.92s8.36-2.75,8.36-7.92V0Z'
    . 'M50.86,12v26.5H44.31V0h6.11l17,26.5V0H74V38.49H67.9Z'
    . 'M171.29,0h6.54V38.49h-6.54Z'
    . 'm51.07,24.69c0,9-5.88,13.8-15.17,13.8H192.67V0H207.3c9.18,0,15.06,4.78,15.06,13.8Z'
    . 'M215.82,13.8c0-5.28-3.3-8.14-8.52-8.14h-8.08V32.77h8c5.33,0,8.63-2.8,8.63-8.08Z'
    . 'M108.31,23.92c4.34-1.6,6.93-5.28,6.93-11.55C115.24,3.68,110.18,0,102.48,0H88.84V38.49h6.55V5.66h6.87c3.8,0,6.21,1.82,6.21,6.71s-2.41,6.76-6.21,6.76H98.88l9.21,19.36h7.53Z';

/** 拆出 .page 头部指令与正文 */
function cb_dev_page_parts(string $file): array
{
    $raw   = (string)file_get_contents($file);
    $lines = preg_split("/\r\n|\n|\r/", $raw) ?: [];
    $head  = [];
    $start = count($lines);

    foreach ($lines as $i => $line) {
        if (trim($line) === '---') {
            $start = $i + 1;
            break;
        }
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*"(.*)"\s*$/', $line, $m)) {
            $head[$m[1]] = $m[2];
        }
    }

    return [$head, implode("\n", array_slice($lines, $start))];
}

/** 执行正文；出错时把异常画在页面上 */
function cb_dev_eval_body(string $body): string
{
    global $docroot; // 正文直接用 $docroot，Unraid 也是这样放进作用域的

    ob_start();
    $error = '';
    try {
        eval('?>' . $body);
    } catch (\Throwable $e) {
        $error = '<pre class="cb-dev-error">' . htmlspecialchars(
            get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine(),
            ENT_QUOTES,
            'UTF-8'
        ) . '</pre>';
    }
    $out = ob_get_clean();

    return ($out === false ? '' : $out) . $error;
}

/** 处理 `_(文案)_` 翻译标记 */
function cb_dev_translate(string $html): string
{
    // 同理：<style>/<script> 不是页面文案，不要在里面做 _(...)_ 替换
    $held = [];
    $html = (string)preg_replace_callback(
        '#<(style|script)\b[^>]*>.*?</\1>#is',
        static function (array $m) use (&$held): string {
            $key = "\x00CBTR" . count($held) . "\x00";
            $held[$key] = $m[0];
            return $key;
        },
        $html
    );

    $html = (string)preg_replace_callback(
        '/_\((.*?)\)_/s',
        static function (array $m): string {
            return function_exists('_') ? (string)_($m[1]) : $m[1];
        },
        $html
    );

    return $held ? strtr($html, $held) : $html;
}

/**
 * 把 `_(标签)_:` / `: 控件` 转成 php-markdown Extra 那样的 <dl><dt>…<dd>…。
 * 说明文字写在 <blockquote> 里且不缩进，所以续行按未闭合块级标签数判断，而不是缩进。
 */
function cb_dev_definition_lists(string $html): string
{
    // <style>/<script> 里的 CSS/JS 不是 markdown，先摘出来，否则 ":root{...}" 会被
    // 当成定义列表项吃掉（预览面板就踩过这个坑），处理完再原样放回。
    // 注意 cb_dev_render_page 传进来的是 .page 正文，正文里没有 <style>，不受影响。
    $held = [];
    $html = (string)preg_replace_callback(
        '#<(style|script)\b[^>]*>.*?</\1>#is',
        static function (array $m) use (&$held): string {
            $key = "\x00CBHOLD" . count($held) . "\x00";
            $held[$key] = $m[0];
            return $key;
        },
        $html
    );

    $lines    = explode("\n", $html);
    $out      = [];
    $inList   = false;
    $openItem = false;
    $depth    = 0;

    $countDepth = static function (string $line): int {
        $l     = strtolower($line);
        $open  = 0;
        $close = 0;
        foreach (['blockquote', 'select', 'div', 'table', 'pre', 'ul', 'ol'] as $tag) {
            $open  += substr_count($l, '<' . $tag);
            $close += substr_count($l, '</' . $tag);
        }
        return $open - $close;
    };

    $closeItem = static function () use (&$out, &$openItem, &$depth): void {
        if ($openItem) {
            $out[]    = '</dd>';
            $openItem = false;
            $depth    = 0;
        }
    };
    $closeList = static function () use (&$out, &$inList, $closeItem): void {
        $closeItem();
        if ($inList) {
            $out[]  = '</dl>';
            $inList = false;
        }
    };

    foreach ($lines as $line) {
        if (preg_match('/^\s*_\((.*)\)_:\s*$/', $line, $m)) {
            $closeItem();
            if (!$inList) {
                $out[]  = '<dl>';
                $inList = true;
            }
            $out[] = '<dt>' . $m[1] . '</dt>';
            continue;
        }

        if (preg_match('/^\s*:\s?(.*)$/', $line, $m)) {
            $closeItem();
            $out[]    = '<dd>' . $m[1];
            $openItem = true;
            $depth    = $countDepth($m[1]);
            continue;
        }

        if ($openItem && ($depth > 0 || trim($line) === '' || preg_match('/^\s{2,}\S/', $line))) {
            $depth += $countDepth($line);
            if ($depth < 0) {
                $depth = 0;
            }
            $out[] = $line;
            continue;
        }

        $closeList();
        $out[] = $line;
    }

    $closeList();

    if ($held) {
        return strtr(implode("\n", $out), $held);
    }

    return implode("\n", $out);
}

/** 预览外壳与控件样式：取自 Unraid 7 webGUI，/page 与对话框形态共用 */
const CB_DEV_STYLES = <<<'CSS'
/* 调色板与主题变量：Unraid 7 的 default-color-palette.css / themes/*.css */
:root{
  --black:#1d1b1b; --black-opacity-05:rgba(0,0,0,.05); --black-opacity-30:rgba(0,0,0,.3);
  --white:#ffffff; --white-opacity-05:rgba(255,255,255,.05); --white-opacity-10:rgba(255,255,255,.1);
  --white-opacity-25:rgba(255,255,255,.25); --white-opacity-30:rgba(255,255,255,.3);
  --gray-000:#ffffff; --gray-100:#f2f2f2; --gray-120:rgba(242,242,242,.2); --gray-150:#e8e8e8;
  --gray-200:#d3d3d3; --gray-300:#cccccc; --gray-400:#909090; --gray-500:#808080;
  --gray-600:#404040; --gray-700:#303030; --gray-800:#212121; --gray-895:rgba(25,25,25,.95); --gray-900:#1d1b1b;
  --orange-100:#ffdfb9; --orange-200:#ff9900; --orange-300:#e68a00; --orange-400:#ce7c10;
  --orange-500:#ff8c2f; --orange-800:#f15a2c; --orange-900:#d63301;
  --red-100:#ffddd1; --red-300:#ff9e9e; --red-500:#ff3300; --red-600:#f0000c; --red-700:#de1100; --red-800:#e22828; --red-900:#941c00;
  --green-100:#dff2bf; --green-200:#33cc33; --green-500:#17bf0b; --green-800:#4f8a10; --green-900:#127a05;
  --blue-100:#d9edf7; --blue-200:#bce8f1; --blue-300:#bde5f8; --blue-700:#0099ff; --blue-800:#486dba; --blue-900:#3b5998;
  --yellow-100:#fff6bf; --yellow-200:#feefb3; --yellow-500:#ffd324;
  --font-sans: clear-sans,-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;
  --font-bitstream: bitstream,Menlo,Consolas,"Courier New",monospace;
}
html[data-theme="black"]{
  --text-color:var(--gray-100); --blockquote-text-color:var(--gray-800); --alt-text-color:var(--gray-500);
  --disabled-text-color:var(--gray-500); --inverse-text-color:var(--gray-900); --link-text-color:var(--blue-800);
  --background-color:var(--gray-900); --opac-background-color:var(--gray-895); --mild-background-color:#262626;
  --border-color:var(--gray-600); --disabled-border-color:var(--gray-500); --input-border-color:var(--gray-200);
  --disabled-input-border-color:var(--gray-500); --textarea-border-color:var(--gray-400);
  --table-border-color:var(--gray-700); --table-background-color:var(--gray-800); --table-header-background-color:var(--gray-800);
  --hover-table-row-background-color:var(--white-opacity-05);
  --footer-text:var(--text-color); --footer-background-color:var(--mild-background-color);
  --small-shadow:0 0 3px var(--gray-700); --hr-color:var(--gray-800);
  --brand-orange:var(--orange-500); --brand-red:var(--red-800);
  --header-text-color:var(--inverse-text-color); --header-background-color:var(--gray-100);
  --title-header-background-color:var(--mild-background-color);
  --button-text-color:var(--brand-orange); --button-border:none;
  --button-background:linear-gradient(90deg,var(--brand-red) 0,var(--brand-orange)) 0 0 no-repeat,linear-gradient(90deg,var(--brand-red) 0,var(--brand-orange)) 0 100% no-repeat,linear-gradient(0deg,var(--brand-red) 0,var(--brand-red)) 0 100% no-repeat,linear-gradient(0deg,var(--brand-orange) 0,var(--brand-orange)) 100% 100% no-repeat;
  --button-background-size:100% 2px,100% 2px,2px 100%,2px 100%;
  --hover-button-border:var(--blue-700); --hover-button-text-color:var(--white);
  --hover-button-background:linear-gradient(90deg,var(--brand-red) 0,var(--brand-orange));
  --focus-input-bg-color:var(--mild-background-color); --shade-bg-color:var(--gray-800);
  --bg-opacity-10:var(--black-opacity-10);
}
html[data-theme="white"]{
  --text-color:var(--gray-900); --blockquote-text-color:var(--gray-800); --alt-text-color:var(--gray-400);
  --disabled-text-color:var(--gray-500); --inverse-text-color:var(--gray-100); --link-text-color:var(--blue-800);
  --background-color:var(--gray-100); --opac-background-color:var(--gray-895); --mild-background-color:#f7f9f9;
  --border-color:var(--gray-200); --disabled-border-color:var(--gray-500); --input-border-color:var(--black);
  --disabled-input-border-color:var(--gray-400); --textarea-border-color:var(--gray-400);
  --table-border-color:var(--gray-200); --table-background-color:var(--gray-150); --table-header-background-color:var(--gray-150);
  --hover-table-row-background-color:var(--black-opacity-05);
  --footer-text:var(--text-color); --footer-background-color:var(--gray-200);
  --small-shadow:0 0 3px var(--gray-700); --hr-color:var(--gray-300);
  --brand-orange:var(--orange-500); --brand-red:var(--red-800);
  --header-text-color:var(--inverse-text-color); --header-background-color:var(--gray-900);
  --title-header-background-color:var(--gray-150);
  --button-text-color:var(--brand-orange); --button-border:none;
  --button-background:linear-gradient(90deg,var(--brand-red) 0,var(--brand-orange)) 0 0 no-repeat,linear-gradient(90deg,var(--brand-red) 0,var(--brand-orange)) 0 100% no-repeat,linear-gradient(0deg,var(--brand-red) 0,var(--brand-red)) 0 100% no-repeat,linear-gradient(0deg,var(--brand-orange) 0,var(--brand-orange)) 100% 100% no-repeat;
  --button-background-size:100% 2px,100% 2px,2px 100%,2px 100%;
  --hover-button-border:var(--blue-700); --hover-button-text-color:var(--white);
  --hover-button-background:linear-gradient(90deg,var(--brand-red) 0,var(--brand-orange));
  --focus-input-bg-color:var(--gray-150); --shade-bg-color:var(--gray-150);
  --bg-opacity-10:var(--white-opacity-10);
}

/* 以下规则取自 Unraid 7 default-base.css */
html{font-family:var(--font-sans);font-size:62.5%;height:100%;}
body{font-size:1.3rem;color:var(--text-color);background-color:var(--background-color);padding:0;margin:0;min-height:100vh;display:flex;flex-direction:column;-webkit-font-smoothing:antialiased;}
img{border:none;vertical-align:middle;}
a{color:var(--link-text-color);text-decoration:none;}
a:hover{text-decoration:underline;}
code,kbd{font-family:var(--font-bitstream);font-size:1.2rem;background:var(--bg-opacity-10);border:1px solid var(--border-color);border-radius:3px;padding:0 .4rem;}
pre{background:var(--bg-opacity-10);border:1px solid var(--border-color);border-radius:3px;font-family:var(--font-bitstream);font-size:1.2rem;padding:.6rem 1.2rem;overflow:auto;margin:1rem 0;}
hr{border:none;border-top:1px solid var(--hr-color);margin:1.2rem 0;}
#header{position:relative;display:flex;justify-content:space-between;align-items:center;gap:1rem;width:100%;min-height:64px;margin:0;padding-left:.8rem;color:var(--inverse-text-color);background-color:var(--header-background-color);border-bottom:1px solid var(--gray-600);box-sizing:border-box;}
#header .logo{display:flex;flex-direction:column;align-items:flex-start;margin-left:10px;color:var(--brand-red);}
#header .logo a svg{width:160px;height:28px;display:block;margin:6px 0 2px 0;}
#header .logo .version{display:inline-flex;align-items:center;gap:4px;font-size:1.1rem;font-weight:600;color:#999;}
#header .block{margin:0;float:right;text-align:right;background-color:var(--gray-120);padding:8px 12px;}
#header .text-left{float:left;text-align:right;padding-right:5px;border-right:solid medium var(--orange-800);}
#header .text-right{float:right;text-align:left;padding-left:8px;}
#menu{width:100%;display:grid;grid-template-columns:1fr;grid-template-rows:auto auto;z-index:101;}
@media (min-width:768px){#menu{position:sticky;top:0;grid-template-columns:auto max-content;grid-template-rows:auto;}}
.nav-tile{height:auto;min-height:4rem;line-height:4rem;display:flex;padding:0;margin:0;font-size:1.2rem;letter-spacing:1.8px;background-color:var(--header-background-color);white-space:nowrap;overflow-x:auto;overflow-y:hidden;}
@media (min-width:768px){.nav-tile{flex-wrap:nowrap;height:4rem;}}
.nav-tile.right{justify-content:flex-end;}
.nav-item{position:relative;display:flex;align-items:center;text-align:center;margin:0;}
.nav-item a{color:var(--header-text-color);background-color:transparent;text-transform:uppercase;font-weight:bold;display:block;padding:0 10px;text-decoration:none;transition:all .25s ease-out;cursor:pointer;}
.nav-item a:hover{text-decoration:none;}
.nav-item.disabled a{opacity:.35;cursor:default;}
.nav-item:after{content:"";display:block;border-radius:4px;background-color:transparent;width:32px;height:2px;position:absolute;left:50%;transform:translateX(-50%) translateY(8px);transition:all .25s ease-in-out;pointer-events:none;}
.nav-item:not(.disabled):hover:after{background-color:var(--orange-800);}
.nav-item.active:after{background-color:var(--header-text-color);}
#displaybox{width:100%;margin:0 auto;padding:1rem 1rem 4rem;box-sizing:border-box;}
div.title{display:flex;flex-direction:row;align-items:center;justify-content:space-between;margin:2rem 0;padding:8px 10px;clear:both;border-bottom:1px solid var(--table-border-color);background-color:var(--title-header-background-color);letter-spacing:1.8px;font-size:1.4rem;color:var(--text-color);}
div.title:first-of-type{margin-top:0;}
div.title .cb-title-left{display:inline-flex;align-items:center;gap:6px;}
div.title .cb-title-icon{display:inline-flex;color:var(--brand-orange);}
div.title .cb-title-icon svg{width:18px;height:18px;}
div.title .right{font-size:1.4rem;}
h1,h2,h3,h4{color:var(--text-color);font-weight:600;letter-spacing:1.2px;margin:1.8rem 0 .8rem;}
h3{font-size:1.5rem;padding-bottom:.4rem;border-bottom:1px solid var(--border-color);}
p{margin:.6rem 0 1rem;}
ul{margin:.6rem 0 1rem;padding-left:2rem;}
li{margin:.3rem 0;}
input[type=text],input[type=password],input[type=number],input[type=url],input[type=email],input[type=date],input[type=search]{
  color:var(--text-color);font-family:inherit;font-size:1.3rem;background-color:transparent;
  border:0;border-bottom:1px solid var(--input-border-color);padding:.5rem;min-height:2rem;line-height:2rem;
  outline:none;width:100%;max-width:400px;margin:0;box-shadow:none;border-radius:0;box-sizing:border-box;}
input[type=text]:focus,input[type=password]:focus,input[type=number]:focus,input[type=email]:focus{background-color:var(--focus-input-bg-color);}
textarea{color:var(--text-color);font-family:var(--font-bitstream);font-size:1.3rem;background-color:transparent;border:1px solid var(--textarea-border-color);border-bottom:1px solid var(--input-border-color);padding:6px;outline:none;width:100%;max-width:400px;box-sizing:border-box;resize:vertical;}
textarea:focus{background-color:var(--focus-input-bg-color);}
input[type=checkbox]{vertical-align:middle;margin-right:6px;accent-color:var(--brand-orange);}
select{appearance:none;-webkit-appearance:none;font-family:inherit;font-size:1.3rem;min-width:188px;max-width:314px;width:100%;padding:6px 14px 6px 6px;margin:0;border:1px solid var(--border-color);color:var(--text-color);background-color:transparent;background-image:linear-gradient(66.6deg,transparent 60%,var(--border-color) 40%),linear-gradient(113.4deg,var(--border-color) 40%,transparent 60%);background-position:calc(100% - 8px),calc(100% - 4px);background-size:4px 6px,4px 6px;background-repeat:no-repeat;outline:none;cursor:pointer;box-sizing:border-box;}
select option{color:var(--text-color);background-color:var(--opac-background-color);}
select:focus{background-color:var(--focus-input-bg-color);}
input[type=submit],input[type=reset],input[type=button],button{
  font-family:inherit;font-size:1.2rem;font-weight:normal;letter-spacing:normal;text-transform:none;
  min-width:86px;margin:10px 12px 10px 0;padding:8px;text-align:center;text-decoration:none;white-space:nowrap;
  cursor:pointer;outline:none;border-radius:4px;border:var(--button-border);color:var(--button-text-color);
  background:var(--button-background);background-size:var(--button-background-size);box-sizing:border-box;}
input[type=submit]:hover,input[type=reset]:hover,input[type=button]:hover,button:hover{
  color:var(--hover-button-text-color);background:var(--hover-button-background);border-color:var(--hover-button-border);}
input[disabled],input:hover[disabled],button[disabled],button:hover[disabled]{
  background:none;border:2px solid var(--disabled-border-color);color:var(--disabled-text-color);opacity:.5;cursor:default;}
/* Unraid 用 grid 排 dl，多 dd 时会错行；这里改成浮动两列，效果等同只写一个 dd 的页面 */
form{margin:0;}
dl{margin:0;padding:1rem 0;box-sizing:border-box;}
dl::after{content:"";display:block;clear:both;}
dt{font-weight:600;text-align:right;float:left;clear:left;width:35%;box-sizing:border-box;padding:.6rem 2rem 0 0;}
dd{margin:0 0 0 35%;padding:0 0 .6rem 2rem;box-sizing:border-box;white-space:normal;}
dd p{margin:0 0 4px 0;}
dd input[type=text],dd input[type=password],dd input[type=number],dd input[type=email],dd textarea,dd select{max-width:400px;}
@media (max-width:768px){
  dt{float:none;width:auto;text-align:left;padding:1rem 0 .4rem;}
  dd{margin-left:0;padding-left:0;}
}
@media (min-width:769px){ select{min-width:188px;} }
/* Unraid 7 默认隐藏 inline_help */
blockquote{width:100%;max-width:100ch;margin:1rem auto;text-align:left;padding:.5rem 2rem;border-top:2px solid var(--blue-200);border-bottom:2px solid var(--blue-200);color:var(--blockquote-text-color);background-color:var(--blue-100);box-sizing:border-box;}
blockquote a{color:var(--brand-orange);font-weight:600;}
dd blockquote{padding-left:0;}
blockquote.inline_help{display:none;margin:.6rem 0 0 0;font-size:1.2rem;}
#cb-pane-config dt{cursor:help;}
table{border-collapse:collapse;border-spacing:0;margin:0;width:100%;background-color:transparent;color:var(--text-color);}
table th,table td{padding:6px 8px;text-align:left;vertical-align:top;border-bottom:1px solid var(--table-border-color);}
table tbody tr:nth-child(even){background-color:var(--table-background-color);}
table tbody tr:hover{background-color:var(--hover-table-row-background-color);}
th{color:var(--gray-500);font-weight:normal;}
.tabs{display:flex;align-items:center;gap:.5rem;}
.tabs button{appearance:none;background-color:transparent;border:1px solid var(--disabled-input-border-color);border-radius:0;border-top-left-radius:6px;border-top-right-radius:6px;border-bottom:1px solid transparent;color:var(--text-color);font-weight:normal;font-size:1.4rem;letter-spacing:1.8px;margin:0;padding:.75rem 1rem;min-width:0;box-shadow:none;opacity:.5;cursor:pointer;}
.tabs button:hover,.tabs button.active{border-color:var(--brand-orange);border-bottom-color:transparent;opacity:1;color:var(--text-color);}
#footer{box-sizing:border-box;position:relative;color:var(--footer-text);background-color:var(--footer-background-color);padding:.5rem 1rem;width:100%;margin-top:auto;font-size:1.1rem;}
@media (min-width:768px){
  #footer{display:grid;grid-template-columns:auto 1fr auto;align-items:center;gap:1rem;position:fixed;bottom:0;left:0;z-index:10000;}
}
@media (min-height:500px) and (orientation:landscape){
  #footer{position:fixed;bottom:0;left:0;z-index:10000;max-height:30px;}
}
#footer .footer-left{display:flex;align-items:center;gap:1rem;}
#footer .footer-right{display:flex;align-items:center;gap:1rem;justify-content:flex-end;font-family:var(--font-bitstream);overflow-x:auto;}
#footer .green{color:var(--green-500);}
#footer .strong{font-weight:bold;}
#footer .cb-theme-switch{display:inline-flex;align-items:center;gap:.4rem;}
#footer .cb-theme-switch button{min-width:auto;margin:0;padding:.2rem .8rem;font-size:1rem;letter-spacing:1px;border-radius:3px;}

/* 预览专用：提示条、openBox 弹窗、保存进度框 */
.devbar{background:var(--shade-bg-color);color:var(--alt-text-color);border-bottom:1px solid var(--border-color);border-left:3px solid var(--brand-orange);padding:.6rem 1rem;font-size:1.2rem;}
.devbar a{color:var(--brand-orange);}
.cb-dev-error{background:var(--red-100);color:var(--red-900);border:1px solid var(--red-300);padding:1.2rem;border-radius:4px;white-space:pre-wrap;font-family:var(--font-bitstream);font-size:1.2rem;}
.cb-dev-overlay{position:fixed;inset:0;background:rgba(0,0,0,.6);display:flex;align-items:center;justify-content:center;z-index:9999;}
.cb-dev-box{background:var(--background-color);border:1px solid var(--border-color);border-radius:6px;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 12px 40px rgba(0,0,0,.5);max-width:95vw;max-height:92vh;}
.cb-dev-box-head{background:var(--mild-background-color);color:var(--text-color);padding:.8rem 1.2rem;display:flex;align-items:center;gap:1.2rem;font-size:1.3rem;border-bottom:1px solid var(--border-color);}
.cb-dev-box-head button{margin:0 0 0 auto;padding:.4rem 1.2rem;font-size:1.1rem;}
.cb-dev-box iframe{border:0;flex:1;width:100%;background:var(--background-color);}
#cb-dev-progress{position:fixed;right:2rem;bottom:4rem;width:760px;max-width:92vw;height:440px;background:var(--background-color);border:1px solid var(--border-color);border-radius:6px;box-shadow:0 12px 40px rgba(0,0,0,.45);display:flex;flex-direction:column;overflow:hidden;z-index:9998;}
#cb-dev-progress[hidden]{display:none;}
#cb-dev-progress .head{background:var(--mild-background-color);color:var(--text-color);padding:.8rem 1.2rem;display:flex;align-items:center;gap:1.2rem;font-size:1.3rem;border-bottom:1px solid var(--border-color);}
#cb-dev-progress .head .msg{font-weight:bold;}
#cb-dev-progress .head button{margin:0 0 0 auto;padding:.4rem 1.2rem;font-size:1.1rem;}
#cb-dev-progress .head button+button{margin-left:.4rem;}
#cb-dev-progress iframe{border:0;flex:1;width:100%;background:var(--background-color);}

/* 覆盖插件 .page 自带的内联样式。放在正文之后，靠顺序生效，不需要 !important */
.cb-table{width:100%;border-collapse:collapse;background-color:transparent;}
.cb-table th,.cb-table td{padding:6px 8px;text-align:left;vertical-align:top;color:var(--text-color);border-bottom:1px solid var(--table-border-color);}
.cb-table tr:nth-child(even)>td{background-color:var(--table-background-color);}
.cb-table tr:hover>td{background-color:var(--hover-table-row-background-color);}
.cb-table th{color:var(--gray-500);font-weight:normal;font-size:1.1rem;text-transform:uppercase;letter-spacing:1px;}
#cb-tabs{margin:0 0 1.2rem 0;border-bottom:1px solid var(--border-color);display:flex;gap:.5rem;}
#cb-tabs button{background:transparent;border:1px solid transparent;border-bottom:2px solid transparent;border-radius:6px 6px 0 0;color:var(--text-color);opacity:.5;padding:.75rem 1rem;font-size:1.4rem;letter-spacing:1.8px;cursor:pointer;}
#cb-tabs button:hover{border-color:var(--brand-orange);border-bottom-color:transparent;color:var(--text-color);opacity:1;}
#cb-tabs button.active{background:transparent;border-color:var(--brand-orange);border-bottom-color:transparent;color:var(--text-color);font-weight:normal;opacity:1;}
.cb-badge{display:inline-block;border-radius:1rem;padding:.2rem .8rem;font-size:1.1rem;}
.cb-ok{background:var(--green-100);color:var(--green-900);}
.cb-fail{background:var(--red-100);color:var(--red-900);}
.cb-log{background:#111;color:#d4d4d4;border:1px solid var(--border-color);border-radius:4px;padding:1.2rem;font-family:var(--font-bitstream);font-size:1.2rem;line-height:1.6;max-height:52rem;overflow:auto;white-space:pre-wrap;word-break:break-all;}
CSS;

/** 渲染 .page 为完整 HTML */
function cb_dev_render_page(string $file, array $ctx = []): string
{
    global $docroot;

    [$head, $body] = cb_dev_page_parts($file);
    $html = cb_dev_eval_body($body);

    // 正文发了 Location：按真机的响应处理，
    // 不再套预览外壳，否则会把跳转页渲染成一张带菜单的空白页。
    $headers = headers_list();
    foreach ($headers as $h) {
        if (stripos($h, 'Location:') !== 0) {
            continue;
        }
        if (function_exists('http_response_code')) {
            http_response_code(302);
        }
        return "<!DOCTYPE html>\n<html lang=\"zh-CN\"><head><meta charset=\"utf-8\">"
             . "<meta http-equiv=\"refresh\" content=\"0; url=" . htmlspecialchars(trim(substr($h, 9)), ENT_QUOTES, 'UTF-8') . "\">"
             . "</head><body style=\"font-family:sans-serif;padding:1rem\">" . $html . "</body></html>";
    }

    // 先切定义列表（此时 _(...)_ 还在，用来识别标签行），再统一翻译
    $html = cb_dev_definition_lists($html);
    $html = cb_dev_translate($html);

    $ctx['source'] = $ctx['source'] ?? $file;

    return cb_dev_chrome($head, $html, $ctx);
}

/** 顶部菜单；预览里不存在的页面保持灰显 */
function cb_dev_nav(string $current): string
{
    $items = [
        ['仪表板', '',                       false],
        ['主界面', '',                       false],
        ['共享',   '',                       false],
        ['用户',   '',                       false],
        ['设置',   '/Settings/UnraidCertbot', true],
        ['插件',   '',                       false],
        ['DOCKER', '',                       false],
        ['虚拟机', '',                       false],
        ['应用',   '',                       false],
        ['工具',   '',                       false],
    ];

    // 设置类的页面（/Settings/...）在真机上都在「设置」下，预览里也统一高亮它
    $current = preg_match('#^/Settings/#i', $current) ? '/Settings/UnraidCertbot' : $current;

    $out = '';
    foreach ($items as [$label, $href, $real]) {
        $active = ($real && $href !== '' && strcasecmp($current, $href) === 0) ? ' active' : '';

        if (!$real || $href === '') {
            $out .= '<div class="nav-item disabled"><a title="本地预览里没有这个页面">' . $label . '</a></div>';
        } else {
            $out .= '<div class="nav-item' . $active . '"><a href="'
                  . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . $label . '</a></div>';
        }
    }

    return $out;
}

/** 把正文套进 Unraid 外壳 */
function cb_dev_chrome(array $head, string $body, array $ctx = []): string
{
    $title   = $head['Title'] ?? 'Unraid';
    $devRoot = (string)($ctx['dev_root'] ?? '');
    $source  = (string)($ctx['source'] ?? '');
    $current = (string)($ctx['current'] ?? '/');
    $host    = (string)($ctx['host'] ?? 'tower');
    $version = (string)($ctx['version'] ?? '7.3.2');
    $uptime  = (string)($ctx['uptime'] ?? '0 天 00:00');

    $themeParam  = isset($_GET['theme']) ? ((string)$_GET['theme'] === 'black' ? 'black' : 'white') : null;
    $theme       = $themeParam ?? 'white';
    $themeForced = $themeParam !== null ? 'true' : 'false';
    $themeClass  = 'Theme--' . $theme;
    $themeUpper  = strtoupper($theme);
    $helpOpen    = isset($_GET['help']) ? 'true' : 'false';

    $titleHtml  = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $rootHtml   = htmlspecialchars($devRoot, ENT_QUOTES, 'UTF-8');
    $sourceHtml = htmlspecialchars($source, ENT_QUOTES, 'UTF-8');
    $hostHtml   = htmlspecialchars($host, ENT_QUOTES, 'UTF-8');
    $verHtml    = htmlspecialchars($version, ENT_QUOTES, 'UTF-8');
    $uptimeHtml = htmlspecialchars($uptime, ENT_QUOTES, 'UTF-8');
    $nav        = cb_dev_nav($current);
    $logoPath   = CB_DEV_LOGO_PATH;
    $cbDevStyles = CB_DEV_STYLES; // heredoc 只展开变量，常量要先落地到变量

    // 真机由 includePageStylesheets() 自动引入 <插件>/sheets/<页面名>.css，预览同样处理
    $cbSheetLink = '';
    $srcName = basename((string)$ctx['source'] ?? '');
    if (substr($srcName, -5) === '.page') {
        // __FILE__ = <repo>/dev/lib/emhttp.php → 仓库根目录要退三层
        $sheet = dirname(__DIR__, 2) . '/source/unraid-certbot/usr/local/emhttp/plugins/unraid-certbot/sheets/'
               . basename($srcName, '.page') . '.css';
        if (is_file($sheet)) {
            $cbSheetLink = '<link rel="stylesheet" href="/plugins/unraid-certbot/sheets/'
                         . basename($srcName, '.page') . '.css">' . "\n";
        }
    }

    return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN" class="{$themeClass}" data-theme="{$theme}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$titleHtml} · Unraid</title>
<style>{$cbDevStyles}</style>
{$cbSheetLink}
</head>
<body>

<div id="header">
  <div class="logo">
    <a href="https://unraid.net" target="_blank" rel="noopener" aria-label="Unraid">
      <svg xmlns="http://www.w3.org/2000/svg" width="160" height="28" viewBox="0 0 222.36 39.04" aria-hidden="true">
        <defs>
          <linearGradient id="cb-unraid-logo" x1="47.53" y1="79.1" x2="170.71" y2="-44.08" gradientUnits="userSpaceOnUse">
            <stop offset="0" stop-color="#e32929"/>
            <stop offset="1" stop-color="#ff8d30"/>
          </linearGradient>
        </defs>
        <path fill="url(#cb-unraid-logo)" d="{$logoPath}"/>
      </svg>
    </a>
    <span class="version"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M2.25 12c0-5.385 4.365-9.75 9.75-9.75s9.75 4.365 9.75 9.75-4.365 9.75-9.75 9.75S2.25 17.385 2.25 12Zm8.706-1.442c1.146-.573 2.437.463 2.126 1.706l-.709 2.836.042-.02a.75.75 0 0 1 .67 1.34l-.04.022c-1.147.573-2.438-.463-2.127-1.706l.71-2.836-.042.02a.75.75 0 1 1-.671-1.34l.041-.022ZM12 9a.75.75 0 1 0 0-1.5.75.75 0 0 0 0 1.5Z" clip-rule="evenodd"/></svg>{$verHtml}</span>
  </div>
  <div class="block">
    <span class="text-left">服务器<br>描述<br>版本<br>运行时间</span>
    <span class="text-right">{$hostHtml}<br>unraid-certbot 本地调试<br>{$verHtml}<br>{$uptimeHtml}</span>
  </div>
</div>

<div id="menu">
  <div class="nav-tile">{$nav}</div>
  <div class="nav-tile right">
    <div class="nav-item"><a href="/" title="沙箱信息">信息</a></div>
    <div class="nav-item"><a href="/Settings/UnraidCertbot?tab=log" title="运行日志">日志</a></div>
    <div class="nav-item"><a href="#" onclick="cbToggleHelp();return false;" title="展开/收起所有说明文字">帮助</a></div>
  </div>
</div>

<div id="displaybox">
  <div class="content">
    <div class="title">
      <span class="cb-title-left"><span class="cb-title-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/></svg></span>{$titleHtml}</span>
      <span class="right"></span>
    </div>
{$body}
  </div>
</div>

<footer id="footer">
  <div class="footer-left">
    <span class="green strong">Array Started</span>
    <span class="grey-text">（沙箱）</span>
  </div>
  <div class="footer-spacer"></div>
  <div class="footer-right">
    <span class="cb-theme-switch">主题
      <button type="button" onclick="cbToggleTheme()" id="cb-theme-label">{$themeUpper}</button>
    </span>
    <span>Unraid&reg; webGui 本地预览</span>
  </div>
</footer>

<div id="cb-dev-progress" hidden>
  <div class="head">
    <span class="msg">保存进度</span>
    <button type="button" onclick="location.reload()">刷新页面</button>
    <button type="button" onclick="document.getElementById('cb-dev-progress').hidden = true">关闭</button>
  </div>
  <iframe name="progressFrame" id="progressFrame" title="保存进度"></iframe>
</div>

<script>
(function () {
  var forced = {$themeForced};
  try {
    var saved = forced ? null : localStorage.getItem('cb-dev-theme');
    if (saved === 'black' || saved === 'white') {
      document.documentElement.setAttribute('data-theme', saved);
      document.documentElement.className = 'Theme--' + saved;
    }
  } catch (e) {}
  var lbl = document.getElementById('cb-theme-label');
  if (lbl) lbl.textContent = document.documentElement.getAttribute('data-theme').toUpperCase();
})();
function cbToggleTheme() {
  var root = document.documentElement;
  var next = root.getAttribute('data-theme') === 'white' ? 'black' : 'white';
  root.setAttribute('data-theme', next);
  root.className = 'Theme--' + next;
  try { localStorage.setItem('cb-dev-theme', next); } catch (e) {}
  var lbl = document.getElementById('cb-theme-label');
  if (lbl) lbl.textContent = next.toUpperCase();
}

function cbHelpBlocks() {
  return Array.prototype.slice.call(document.querySelectorAll('blockquote.inline_help'));
}
function cbToggleHelp() {
  var blocks = cbHelpBlocks();
  var anyOpen = blocks.some(function (b) { return b.style.display === 'block'; });
  blocks.forEach(function (b) { b.style.display = anyOpen ? '' : 'block'; });
}

function openBox(url, title, w, h) {
  cbDevCloseBox();
  var ov = document.createElement('div');
  ov.className = 'cb-dev-overlay';
  ov.id = 'cb-dev-overlay';
  ov.innerHTML = '<div class="cb-dev-box"><div class="cb-dev-box-head">' +
    '<span class="t"></span><button type="button" onclick="cbDevCloseBox()">关闭</button></div>' +
    '<iframe></iframe></div>';
  ov.querySelector('.t').textContent = title || '';
  var box = ov.querySelector('.cb-dev-box');
  box.style.width  = (w || 800) + 'px';
  box.style.height = (h || 560) + 'px';
  ov.querySelector('iframe').src = url;
  document.body.appendChild(ov);
}
function cbDevCloseBox() {
  var ov = document.getElementById('cb-dev-overlay');
  if (ov) ov.remove();
}
function closeBox() { cbDevCloseBox(); }

function cbUpdateDone(ok, msg) {
  var panel = document.getElementById('cb-dev-progress');
  if (!panel) return;
  panel.hidden = false;
  var t = panel.querySelector('.msg');
  if (t) t.textContent = msg || (ok ? '设置已保存' : '设置未保存');
}

document.addEventListener('DOMContentLoaded', function () {
  if ({$helpOpen}) cbToggleHelp();

  // 真机上「应用」按钮有改动才启用
  function enableApply(form) {
    if (!form) return;
    form.querySelectorAll('input[type=submit][disabled]').forEach(function (b) { b.disabled = false; });
  }
  document.addEventListener('input',  function (e) { enableApply(e.target.form); });
  document.addEventListener('change', function (e) { enableApply(e.target.form); });
  document.addEventListener('submit', function () {
    var panel = document.getElementById('cb-dev-progress');
    if (panel) {
      panel.hidden = false;
      var t = panel.querySelector('.msg');
      if (t) t.textContent = '正在保存…';
    }
  });
});
</script>
</body>
</html>
HTML;
}
