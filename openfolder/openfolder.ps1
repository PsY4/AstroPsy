# openfolder.ps1 -- AstroPsy OpenFolder Protocol Handler
# Reads local base path from openfolder.conf, prefixes the relative path received
param([string]$arg)

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Definition
$confFile  = Join-Path $scriptDir 'openfolder.conf'

function Get-LocalBase {
    if (-not (Test-Path $confFile)) {
        throw "openfolder.conf not found in $scriptDir -- please run install.ps1 again."
    }
    $base = (Get-Content $confFile -Raw).Trim()
    if ([string]::IsNullOrWhiteSpace($base)) {
        throw "openfolder.conf is empty -- please run install.ps1 again."
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
