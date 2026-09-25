<?php
// Check the plugin dictionary against Unraid's punctuation-insensitive lookup.
$root = dirname(__DIR__);
$file = "$root/source/unraid-certbot/usr/local/emhttp/languages/zh_CN/unraid-certbot.txt";
if (!is_file($file)) {
    fwrite(STDERR, "Missing URI-matched Chinese dictionary\n");
    exit(1);
}

$normalize = static function (string $text): string {
    $text = trim($text);
    return preg_replace(
        ['/\&amp;|[\?\{\}\|\&\~\!\[\]\(\)\/\\:\*^\.\"\']|<.+?\/?>/', '/^(null|yes|no|true|false|on|off|none)$/i', '/  +/'],
        ['', '$1.', ' '],
        $text
    );
};

$keys = [];
$errors = [];
$contents = file_get_contents($file);
$escaped = str_replace(["\"\n", '"'], ["\" \n", '\\"'], $contents);
$parsed = parse_ini_string(preg_replace(
    ['/^\s*?(null|yes|no|true|false|on|off|none)\s*?=/mi', '/^\s*?([^>].*?)\s*?=\s*?(.*)\s*?$/m'],
    ['$1.=', '$1="$2"'],
    $escaped
));
if (!is_array($parsed)) {
    $errors[] = 'Unraid could not parse the dictionary';
}
foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
    if ($line === '' || $line[0] === '#') {
        continue;
    }
    [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
    if ($key !== $normalize($key)) {
        $errors[] = "Unnormalized key: $key";
    }
    if (isset($keys[$key])) {
        $errors[] = "Duplicate key: $key";
    }
    if ($value === '') {
        $errors[] = "Empty translation: $key";
    }
    if (!isset($parsed[$key]) || $parsed[$key] !== trim($value)) {
        $errors[] = "Unraid parser changed translation: $key";
    }
    $keys[$key] = true;
}

$plugin = "$root/source/unraid-certbot/usr/local/emhttp/plugins/unraid-certbot";
$sources = array_merge(glob("$plugin/*.page"), glob("$plugin/include/*.php"));
foreach ($sources as $source) {
    $content = file_get_contents($source);
    preg_match_all('/\b(?:_|cb_t)\(\s*(?:\'((?:\\\\.|[^\'])*)\'|"((?:\\\\.|[^"])*)")\s*\)/', $content, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        $text = $match[1] !== '' ? $match[1] : $match[2];
        $key = $normalize(stripcslashes($text));
        if (!isset($keys[$key])) {
            $errors[] = basename($source) . ": Missing translation: $text";
        }
    }
}

if ($errors) {
    fwrite(STDERR, implode("\n", array_unique($errors)) . "\n");
    exit(1);
}
echo "Chinese translations cover plugin UI strings\n";
