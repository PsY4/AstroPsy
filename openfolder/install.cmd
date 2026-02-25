<# : batch trampoline -- double-click this file to install
@echo off
copy "%~f0" "%TEMP%\astropsy-install.ps1" >nul
powershell -ExecutionPolicy Bypass -NoProfile -File "%TEMP%\astropsy-install.ps1"
del "%TEMP%\astropsy-install.ps1" >nul 2>&1
exit /b
#>
# install.cmd -- AstroPsy OpenFolder Protocol Handler Installer
# Single-file installer: embeds openfolder.ps1, registers protocol via PowerShell.
# No admin rights required -- installs to %APPDATA%\OpenFolder, registers in HKCU.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$installDir = Join-Path $env:APPDATA 'OpenFolder'

# --- Embedded openfolder.ps1 content ---
$handlerScript = @'
# openfolder.ps1 -- AstroPsy OpenFolder Protocol Handler
# Reads local base path from openfolder.conf, prefixes the relative path received
param([string]$arg)

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Definition
$confFile  = Join-Path $scriptDir 'openfolder.conf'

function Get-LocalBase {
    if (-not (Test-Path $confFile)) {
        throw "openfolder.conf not found in $scriptDir -- please run install.cmd again."
    }
    $base = (Get-Content $confFile -Raw).Trim()
    if ([string]::IsNullOrWhiteSpace($base)) {
        throw "openfolder.conf is empty -- please run install.cmd again."
    }
    return $base
}

function Normalize-OpenFolderArg {
    if (-not $arg) { throw "No argument" }

    # Remove custom scheme if present
    $raw = ($arg -replace '^\s*openfolder:(//{0,2})?', '')

    # URL-decode
    $decoded = [System.Uri]::UnescapeDataString($raw)

    # Trim leading/trailing slashes and whitespace
    $decoded = $decoded.Trim().Trim('/', '\')

    # Prefix with local base path
    $base = Get-LocalBase
    $full = Join-Path $base $decoded

    # Normalize slashes for Windows
    $winPath = $full -replace '/', '\'

    return $winPath
}

try {
    $path = Normalize-OpenFolderArg

    # First try as directory
    if (Test-Path -LiteralPath $path -PathType Container) {
        try {
            $shell = New-Object -ComObject Shell.Application
            $shell.Open($path)
            Start-Sleep -Milliseconds 120
        } catch {}
        Start-Process explorer.exe -ArgumentList @('/n,', "`"$path`"") -WindowStyle Hidden
        exit 0
    }

    # Then as file -- open with default app
    if (Test-Path -LiteralPath $path -PathType Leaf) {
        try {
            Start-Process -FilePath $path -Verb Open
            exit 0
        } catch {
            Start-Process explorer.exe -ArgumentList @("/select,`"$path`"") -WindowStyle Hidden
            exit 0
        }
    }

    # Not found: try opening parent folder
    $parent = Split-Path -Parent $path
    if ($parent -and (Test-Path -LiteralPath $parent -PathType Container)) {
        Start-Process explorer.exe -ArgumentList @('/n,', "`"$parent`"") -WindowStyle Hidden
        exit 2
    }

    throw "Path not found: $path"
}
catch {
    try {
        $msg = "$(Get-Date -Format o) ERROR: $($_.Exception.Message) | arg='$arg'"
        $msg | Out-File -FilePath "$env:TEMP\openfolder.log" -Append -Encoding UTF8
    } catch {}
    exit 1
}
'@

# =====================================================================

Write-Host ''
Write-Host '=== AstroPsy -- OpenFolder Protocol Handler ===' -ForegroundColor Cyan
Write-Host ''
Write-Host "Install directory: $installDir" -ForegroundColor DarkGray
Write-Host ''

# --- 1. Ask for the local sessions root path via Explorer dialog ---
Write-Host 'Select the local folder that contains your astrophotography sessions.' -ForegroundColor Yellow
Write-Host 'This is the root folder (e.g. Z:\Astro or \\NAS\Astro) that maps to' -ForegroundColor Yellow
Write-Host 'the sessions directory used by AstroPsy.' -ForegroundColor Yellow
Write-Host ''

Add-Type -AssemblyName System.Windows.Forms
$dialog = New-Object System.Windows.Forms.FolderBrowserDialog
$dialog.Description = 'Select your local sessions root folder'
$dialog.ShowNewFolderButton = $false

if ($dialog.ShowDialog() -ne [System.Windows.Forms.DialogResult]::OK) {
    Write-Host 'Installation cancelled.' -ForegroundColor Red
    Read-Host 'Press Enter to exit'
    exit 1
}

$localBase = $dialog.SelectedPath
Write-Host "  Local path: $localBase" -ForegroundColor Green
Write-Host ''

# --- 2. Create install directory ---
if (-not (Test-Path $installDir)) {
    New-Item -ItemType Directory -Path $installDir -Force | Out-Null
}

# --- 3. Write openfolder.ps1 ---
$ps1Path = Join-Path $installDir 'openfolder.ps1'
$handlerScript | Out-File -FilePath $ps1Path -Encoding UTF8 -Force
Write-Host "  Written: $ps1Path" -ForegroundColor Green

# --- 4. Write openfolder.conf ---
$confPath = Join-Path $installDir 'openfolder.conf'
$localBase | Out-File -FilePath $confPath -Encoding UTF8 -NoNewline -Force
Write-Host "  Written: $confPath" -ForegroundColor Green

# --- 5. Register openfolder: protocol in HKCU ---
Write-Host ''
Write-Host 'Registering openfolder: protocol...' -ForegroundColor Yellow

$regBase = 'HKCU:\Software\Classes\openfolder'
if (Test-Path $regBase) { Remove-Item -Path $regBase -Recurse -Force }

New-Item -Path $regBase -Force | Out-Null
Set-ItemProperty -Path $regBase -Name '(Default)' -Value 'Open Folder Protocol'
Set-ItemProperty -Path $regBase -Name 'URL Protocol' -Value ''

$cmdKey = "$regBase\shell\open\command"
New-Item -Path $cmdKey -Force | Out-Null
$cmdValue = "powershell.exe -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$ps1Path`" `"%1`""
Set-ItemProperty -Path $cmdKey -Name '(Default)' -Value $cmdValue

Write-Host "  Protocol registered (HKCU)" -ForegroundColor Green

# --- 6. Done ---
Write-Host ''
Write-Host '=====================================' -ForegroundColor Cyan
Write-Host '  Installation complete!' -ForegroundColor Cyan
Write-Host '=====================================' -ForegroundColor Cyan
Write-Host ''
Write-Host "  Handler:  $ps1Path"
Write-Host "  Config:   $confPath"
Write-Host "  Base:     $localBase"
Write-Host ''
Write-Host 'You can now use "Open locally" buttons in AstroPsy.' -ForegroundColor Green
Write-Host ''
Read-Host 'Press Enter to exit'
