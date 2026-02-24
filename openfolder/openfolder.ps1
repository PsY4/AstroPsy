# openfolder.ps1
param([string]$arg)

function Normalize-OpenFolderArg {
    if (-not $arg) { throw "No argument" }

    # Remove custom scheme if present
    $raw = ($arg -replace '^\s*openfolder:(//{0,2})?', '')

    # URL-decode
    $decoded = [System.Uri]::UnescapeDataString($raw)

    # If looks like server/share/... without leading \\ add them
    if ($decoded -match '^[^:\\/]+[\\/][^\\/]+') {
        # UNC path missing leading backslashes
        $decoded = '\\' + $decoded.TrimStart('\','/')
    }

    # Trim leading slashes before drive path forms like /C:/...
    $decoded = $decoded -replace '^[\\/]+(?=[A-Za-z]:[\\/])', ''

    # Normalize slashes
    $winPath = $decoded -replace '/', '\'

    # If it's a bare drive like "C:" add trailing backslash
    if ($winPath -match '^[A-Za-z]:$') { $winPath = "$winPath\" }

    return $winPath
}

try {
    $path = Normalize-OpenFolderArg

    # First try as directory
    if (Test-Path -LiteralPath $path -PathType Container) {
        # Prefer COM to surface an Explorer window, then force new window
        try {
            $shell = New-Object -ComObject Shell.Application
            $shell.Open($path)
            Start-Sleep -Milliseconds 120
        } catch {}
        Start-Process explorer.exe -ArgumentList @('/n,', "`"$path`"") -WindowStyle Hidden
        exit 0
    }

    # Then as file
    if (Test-Path -LiteralPath $path -PathType Leaf) {
        try {
            # Open with default associated application
            Start-Process -FilePath $path -Verb Open
            exit 0
        } catch {
            # Fallback: show the file in Explorer
            Start-Process explorer.exe -ArgumentList @("/select,`"$path`"") -WindowStyle Hidden
            exit 0
        }
    }

    # Not found: try opening parent folder if it exists
    $parent = Split-Path -Parent $path
    if ($parent -and (Test-Path -LiteralPath $parent -PathType Container)) {
        Start-Process explorer.exe -ArgumentList @('/n,', "`"$parent`"") -WindowStyle Hidden
        exit 2
    }

    # Nothing worked
    throw "Path not found: $path"
}
catch {
    # Optional: write a tiny log for troubleshooting
    try {
        $msg = "$(Get-Date -Format o) ERROR: $($_.Exception.Message) | arg='$arg'"
        $msg | Out-File -FilePath "$env:TEMP\openfolder.log" -Append -Encoding UTF8
    } catch {}
    exit 1
}
