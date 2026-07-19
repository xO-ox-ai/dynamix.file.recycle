<?php

declare(strict_types=1);

$root = dirname(__DIR__) . '/source/dynamix.file.recycle/languages';
$english = parse_ini_file($root . '/en_US.txt', false, INI_SCANNER_RAW);
$chinese = parse_ini_file($root . '/zh_CN.txt', false, INI_SCANNER_RAW);
if (!is_array($english) || !is_array($chinese)) {
    throw new RuntimeException('Unable to parse language files.');
}
$enKeys = array_keys($english);
$zhKeys = array_keys($chinese);
sort($enKeys);
sort($zhKeys);
if ($enKeys !== $zhKeys) {
    throw new RuntimeException('English and Chinese localization keys differ.');
}
foreach ($english as $key => $value) {
    if (trim((string) $value) === '' || trim((string) $chinese[$key]) === '') {
        throw new RuntimeException("Empty localization value: $key");
    }
}
echo "Localization contract tests passed.\n";
