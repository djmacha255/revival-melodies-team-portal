$ErrorActionPreference = "Stop"
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$pidDir = Join-Path $projectRoot ".local-preview\pids"

foreach ($name in @("php", "mariadb")) {
    $pidPath = Join-Path $pidDir "$name.pid"
    if (-not (Test-Path -LiteralPath $pidPath)) {
        continue
    }
    $processId = [int](Get-Content -LiteralPath $pidPath -Raw)
    $process = Get-Process -Id $processId -ErrorAction SilentlyContinue
    if ($process) {
        $expectedExe = if ($name -eq "php") {
            Join-Path $projectRoot ".local-preview\runtime\php\php.exe"
        } else {
            $candidate = Get-ChildItem -LiteralPath (Join-Path $projectRoot ".local-preview\runtime\mariadb") -Filter "mariadbd.exe" -File -Recurse | Select-Object -First 1
            if ($candidate) { $candidate.FullName } else { "" }
        }
        if (-not $expectedExe -or -not $process.Path -or [System.IO.Path]::GetFullPath($process.Path) -ne [System.IO.Path]::GetFullPath($expectedExe)) {
            throw "PID file $pidPath no longer identifies the expected $name preview program. Refusing to stop an unrelated process."
        }
        Stop-Process -Id $processId
        Write-Output "Stopped $name preview process (PID $processId)."
    }
    Remove-Item -LiteralPath $pidPath -Force
}
