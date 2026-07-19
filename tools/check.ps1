$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

$php = Get-Command php -ErrorAction SilentlyContinue
if ($php) {
    $phpPath = $php.Source
} else {
    $phpPath = 'E:\test-dev\unraid-usb-guardian\.tools\php\php.exe'
}
if (!(Test-Path -LiteralPath $phpPath -PathType Leaf)) {
    throw 'PHP CLI is required for source checks.'
}

$bash = 'C:\Program Files\Git\bin\bash.exe'
if (!(Test-Path -LiteralPath $bash -PathType Leaf)) {
    throw 'Git Bash is required for source checks and release builds.'
}

$phpFiles = Get-ChildItem 'source\dynamix.file.recycle' -Recurse -File |
    Where-Object { $_.Extension -in @('.php', '.page') }
foreach ($file in $phpFiles) {
    & $phpPath -l $file.FullName | Out-Host
    if ($LASTEXITCODE -ne 0) { throw "PHP syntax check failed: $($file.FullName)" }
}

& $phpPath 'tests\contract.test.php'
if ($LASTEXITCODE -ne 0) { throw 'Core contract tests failed.' }
& $phpPath 'tests\localization.test.php'
if ($LASTEXITCODE -ne 0) { throw 'Localization contract tests failed.' }
& $phpPath 'tests\safety.test.php'
if ($LASTEXITCODE -ne 0) { throw 'Safety unit tests failed.' }

$node = Get-Command node -ErrorAction SilentlyContinue
if ($node) {
    $nodePath = $node.Source
} else {
    $nodePath = 'C:\Users\liwei01\.cache\codex-runtimes\codex-primary-runtime\dependencies\node\bin\node.exe'
}
if (!(Test-Path -LiteralPath $nodePath -PathType Leaf)) {
    throw 'Node.js is required for JavaScript syntax checks.'
}
& $nodePath --check 'source\dynamix.file.recycle\javascript\recycle.js'
if ($LASTEXITCODE -ne 0) { throw 'JavaScript syntax check failed.' }
& $nodePath --check 'source\dynamix.file.recycle\javascript\settings.js'
if ($LASTEXITCODE -ne 0) { throw 'Settings JavaScript syntax check failed.' }
& $nodePath --check 'source\dynamix.file.recycle\javascript\recycle-bin.js'
if ($LASTEXITCODE -ne 0) { throw 'Recycle Bin JavaScript syntax check failed.' }
& $nodePath 'tests\recycle-ui.test.js'
if ($LASTEXITCODE -ne 0) { throw 'DFM responsive UI contract failed.' }

& $bash -n 'tools/build.sh' `
    'source/dynamix.file.recycle/scripts/install.sh' `
    'source/dynamix.file.recycle/scripts/remove.sh' `
    'source/dynamix.file.recycle/scripts/recycle-maintain'
if ($LASTEXITCODE -ne 0) { throw 'Shell syntax checks failed.' }

$pluginsJson = [System.IO.File]::ReadAllText(
    (Resolve-Path 'plugins.json'),
    [System.Text.Encoding]::UTF8
)
$pluginsJson | ConvertFrom-Json | Out-Null
foreach ($xmlPath in @('ca_profile.xml', 'plugins\dynamix-file-recycle.xml')) {
    [xml](Get-Content $xmlPath -Raw) | Out-Null
}

$version = (Get-Content 'VERSION' -Raw).Trim()
& $bash -lc "cd '$($root -replace '\\','/')' && PLUGIN_VERSION='$version' tools/build.sh"
if ($LASTEXITCODE -ne 0) { throw 'Release build failed.' }

$package = Join-Path $root "build\dynamix.file.recycle-$version-x86_64-1.txz"
$releasePlg = Join-Path $root 'build\dynamix.file.recycle.plg'
if (!(Test-Path $package) -or !(Test-Path $releasePlg)) {
    throw 'Release artifacts are missing.'
}
$entries = & tar -tf $package
if ($LASTEXITCODE -ne 0) { throw 'Unable to inspect release archive.' }
if ($entries | Where-Object { $_ -eq 'dynamix.file.recycle/' -or $_ -like 'dynamix.file.recycle/*' }) {
    throw 'Package would extract into /dynamix.file.recycle instead of /usr/local/emhttp/plugins.'
}
if (!($entries | Where-Object { $_ -eq 'usr/local/emhttp/plugins/dynamix.file.recycle/api.php' })) {
    throw 'Package is missing the canonical plugin path.'
}
if (!($entries | Where-Object { $_ -eq 'usr/local/emhttp/plugins/dynamix.file.recycle/VERSION' })) {
    throw 'Package is missing its runtime VERSION file.'
}
if (!($entries | Where-Object { $_ -eq 'usr/local/emhttp/plugins/dynamix.file.recycle/DynamixFileRecycle.page' })) {
    throw 'Package is missing its User Programs settings page.'
}
if (!($entries | Where-Object { $_ -eq 'usr/local/emhttp/plugins/dynamix.file.recycle/unraid-language/zh_CN/dynamix.file.recycle.txt' })) {
    throw 'Package is missing its Chinese Unraid menu translation.'
}
foreach ($asset in @(
    'javascript/settings.js',
    'javascript/settings.css',
    'javascript/recycle-bin.js',
    'javascript/recycle-bin.css'
)) {
    if (!($entries | Where-Object { $_ -eq "usr/local/emhttp/plugins/dynamix.file.recycle/$asset" })) {
        throw "Package is missing its static settings asset: $asset"
    }
}
if ($entries | Where-Object { $_ -in @(
    'usr/local/emhttp/plugins/dynamix.file.recycle/settings.page',
    'usr/local/emhttp/plugins/dynamix.file.recycle/README.page'
) }) {
    throw 'Package still contains a legacy duplicate menu page.'
}
if ($entries | Where-Object { $_ -like 'usr/local/emhttp/plugins/dynamix.file.recycle/cron/*' }) {
    throw 'Package still contains an unconditional maintenance cron payload.'
}

$sha = (Get-FileHash $package -Algorithm SHA256).Hash.ToLowerInvariant()
$plgText = Get-Content $releasePlg -Raw
if (!$plgText.Contains("<SHA256>$sha</SHA256>") -or !$plgText.Contains("EXPECTED_SHA256=`"$sha`"")) {
    throw 'PLG and package SHA256 values are inconsistent.'
}

git diff --check
if ($LASTEXITCODE -ne 0) { throw 'Whitespace checks failed.' }
Write-Host "All checks passed for $version ($sha)."
