param(
    [Parameter(Mandatory = $true)][string]$SdkRoot,
    [Parameter(Mandatory = $true)][string]$NdkRoot,
    [string]$OutputPath
)

$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path -Parent $PSScriptRoot
$sdkRootResolved = (Resolve-Path -LiteralPath $SdkRoot).Path
$ndkRootResolved = (Resolve-Path -LiteralPath $NdkRoot).Path
$clang = Join-Path $ndkRootResolved 'toolchains\llvm\prebuilt\windows-x86_64\bin\clang.exe'
$linker = Join-Path $ndkRootResolved 'toolchains\llvm\prebuilt\windows-x86_64\bin\ld.lld.exe'
$sdkInclude = Join-Path $sdkRootResolved 'target\include'
$sdkLib = Join-Path $sdkRootResolved 'target\lib'
$linkerScript = Join-Path $sdkRootResolved 'ldscripts\elf_x86_64.x'
$source = Join-Path $repoRoot 'payloads\src\app_control_daemon.c'
$output = if ([string]::IsNullOrWhiteSpace($OutputPath)) {
    Join-Path $repoRoot 'payloads\app_control.elf'
} else {
    [System.IO.Path]::GetFullPath($OutputPath)
}
$object = Join-Path ([System.IO.Path]::GetTempPath()) ('ghm-app-control-' + [guid]::NewGuid().ToString('N') + '.o')

foreach ($required in @($clang, $linker, $sdkInclude, $sdkLib, $linkerScript, $source, (Join-Path $sdkLib 'crt1.o'))) {
    if (-not (Test-Path -LiteralPath $required)) { throw "Falta el requisito de compilación: $required" }
}

try {
    & $clang -target x86_64-unknown-freebsd -isystem $sdkInclude -fPIC -ffreestanding -fno-builtin -fvisibility=hidden -O2 -Wall -Wextra -Werror -c $source -o $object
    if ($LASTEXITCODE -ne 0) { throw "clang terminó con código $LASTEXITCODE" }

    $outputDirectory = Split-Path -Parent $output
    if (-not (Test-Path -LiteralPath $outputDirectory)) { New-Item -ItemType Directory -Path $outputDirectory -Force | Out-Null }
    & $linker -m elf_x86_64 -pie -T $linkerScript -z max-page-size=0x4000 --eh-frame-hdr -o $output (Join-Path $sdkLib 'crt1.o') $object -L $sdkLib -lc -lSceLibcInternal -lSceNet -lSceNetCtl -lkernel_web -lSceSystemService -lSceUserService
    if ($LASTEXITCODE -ne 0) { throw "ld.lld terminó con código $LASTEXITCODE" }

    $stream = [System.IO.File]::OpenRead($output)
    try {
        $magic = New-Object byte[] 4
        if ($stream.Read($magic, 0, 4) -ne 4 -or $magic[0] -ne 0x7f -or $magic[1] -ne 0x45 -or $magic[2] -ne 0x4c -or $magic[3] -ne 0x46) {
            throw 'El resultado no tiene cabecera ELF válida.'
        }
    } finally { $stream.Dispose() }

    $sha = [System.Security.Cryptography.SHA256]::Create()
    $file = [System.IO.File]::OpenRead($output)
    try { $hash = ([System.BitConverter]::ToString($sha.ComputeHash($file))).Replace('-', '').ToLowerInvariant() }
    finally { $file.Dispose(); $sha.Dispose() }
    Write-Output "ELF=$output"
    Write-Output "SIZE=$((Get-Item -LiteralPath $output).Length)"
    Write-Output "SHA256=$hash"
} finally {
    Remove-Item -LiteralPath $object -Force -ErrorAction SilentlyContinue
}
