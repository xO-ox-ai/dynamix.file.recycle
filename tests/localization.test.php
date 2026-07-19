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

require_once dirname(__DIR__) . '/source/dynamix.file.recycle/include/I18n.php';

$_SESSION['locale'] = 'zh_CN';
$systemChinese = new \DynamixFileRecycle\I18n($root, 'auto');
if ($systemChinese->lang() !== 'zh_CN' || $systemChinese->t('btn.title') !== '移入回收站') {
    throw new RuntimeException('Chinese Unraid session locale was not followed.');
}

$_SESSION['locale'] = 'en_US';
$systemEnglish = new \DynamixFileRecycle\I18n($root, 'auto');
if ($systemEnglish->lang() !== 'en_US' || $systemEnglish->t('btn.title') !== 'Move to Recycle Bin') {
    throw new RuntimeException('English Unraid session locale was not followed.');
}

$menuFile = dirname(__DIR__) . '/source/dynamix.file.recycle/unraid-language/zh_CN/dynamix.file.recycle.txt';
$menuTranslations = parse_ini_file($menuFile, false, INI_SCANNER_RAW);
if (!is_array($menuTranslations)
    || ($menuTranslations['Dynamix File Recycle Bin'] ?? '') !== '文件回收站') {
    throw new RuntimeException('Unraid menu translation file is invalid.');
}
echo "Localization contract tests passed.\n";
