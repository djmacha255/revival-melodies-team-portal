param(
    [switch]$ResetDatabase
)

$ErrorActionPreference = "Stop"
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$previewRoot = Join-Path $projectRoot ".local-preview"
$runtimeRoot = Join-Path $previewRoot "runtime"
$phpExe = Join-Path $runtimeRoot "php\php.exe"
$phpArchive = Join-Path $previewRoot "downloads\php-8.4.26-nts-Win32-vs17-x64.zip"
$mariaArchive = Join-Path $previewRoot "downloads\mariadb-11.4.8-winx64.zip"
$mariaRoot = Join-Path $runtimeRoot "mariadb"
$dataDir = Join-Path $previewRoot "data"
$logDir = Join-Path $previewRoot "logs"
$pidDir = Join-Path $previewRoot "pids"
$dbPort = 3307
$webPort = 8000
$dbName = "rmt_preview"
$dbUser = "rmt_preview"
$dbPassword = "rmt-preview-local-only"

if (-not (Test-Path -LiteralPath $phpExe)) {
    if (-not (Test-Path -LiteralPath $phpArchive)) {
        throw "Portable PHP archive missing: $phpArchive. Re-run the approved portable-runtime download step."
    }
    New-Item -ItemType Directory -Force -Path (Split-Path -Parent $phpExe) | Out-Null
    Expand-Archive -LiteralPath $phpArchive -DestinationPath (Split-Path -Parent $phpExe) -Force
    $phpIni = Join-Path (Split-Path -Parent $phpExe) "php.ini"
    Copy-Item -LiteralPath (Join-Path (Split-Path -Parent $phpExe) "php.ini-development") -Destination $phpIni
    $ini = Get-Content -LiteralPath $phpIni -Raw
    $ini = $ini.Replace(';extension_dir = "ext"', 'extension_dir = "ext"')
    foreach ($extension in @("fileinfo", "mbstring", "pdo_mysql")) {
        $ini = $ini.Replace(";extension=$extension", "extension=$extension")
    }
    $ini = $ini.Replace(";date.timezone =", "date.timezone = Africa/Dar_es_Salaam")
    Set-Content -LiteralPath $phpIni -Value $ini -Encoding ASCII
}

if (-not (Test-Path -LiteralPath $mariaRoot) -or -not (Get-ChildItem -LiteralPath $mariaRoot -Filter "mariadbd.exe" -File -Recurse -ErrorAction SilentlyContinue | Select-Object -First 1)) {
    if (-not (Test-Path -LiteralPath $mariaArchive)) {
        throw "Portable MariaDB archive missing: $mariaArchive. Run tools\prepare-preview.ps1 first."
    }
    New-Item -ItemType Directory -Force -Path $mariaRoot | Out-Null
    Expand-Archive -LiteralPath $mariaArchive -DestinationPath $mariaRoot -Force
}

$mariaExe = Get-ChildItem -LiteralPath $mariaRoot -Filter "mariadbd.exe" -File -Recurse | Select-Object -First 1
$mariaInit = Get-ChildItem -LiteralPath $mariaRoot -Filter "mariadb-install-db.exe" -File -Recurse | Select-Object -First 1
$mariaClient = Get-ChildItem -LiteralPath $mariaRoot -Filter "mariadb.exe" -File -Recurse | Select-Object -First 1
if (-not $mariaExe -or -not $mariaClient) {
    throw "Portable MariaDB binaries were not found under $mariaRoot. Extract the MariaDB archive there before starting the preview."
}

New-Item -ItemType Directory -Force -Path $logDir, $pidDir | Out-Null
$dbPidPath = Join-Path $pidDir "mariadb.pid"
$webPidPath = Join-Path $pidDir "php.pid"

function Assert-PreviewProcess([string]$PidPath, [string]$ExpectedExecutable) {
    if (-not (Test-Path -LiteralPath $PidPath)) {
        return
    }
    $storedId = [int](Get-Content -LiteralPath $PidPath -Raw)
    $storedProcess = Get-Process -Id $storedId -ErrorAction SilentlyContinue
    if (-not $storedProcess) {
        Remove-Item -LiteralPath $PidPath -Force
        return
    }
    if (-not $storedProcess.Path -or [System.IO.Path]::GetFullPath($storedProcess.Path) -ne [System.IO.Path]::GetFullPath($ExpectedExecutable)) {
        throw "Preview PID file $PidPath points at a different running program. Refusing to reuse or stop it."
    }
}

Assert-PreviewProcess $dbPidPath $mariaExe.FullName
Assert-PreviewProcess $webPidPath $phpExe

if ($ResetDatabase) {
    foreach ($pidPath in @($webPidPath, $dbPidPath)) {
        if (Test-Path -LiteralPath $pidPath) {
            $runningPid = [int](Get-Content -LiteralPath $pidPath -Raw)
            $running = Get-Process -Id $runningPid -ErrorAction SilentlyContinue
            if ($running) {
                Stop-Process -Id $runningPid
            }
            Remove-Item -LiteralPath $pidPath -Force
        }
    }
    if (Test-Path -LiteralPath $dataDir) {
        Remove-Item -LiteralPath $dataDir -Recurse -Force
    }
}

$dbListener = Get-NetTCPConnection -State Listen -LocalPort $dbPort -ErrorAction SilentlyContinue | Select-Object -First 1
if ($dbListener -and (-not (Test-Path -LiteralPath $dbPidPath) -or [int](Get-Content -LiteralPath $dbPidPath -Raw) -ne $dbListener.OwningProcess)) {
    throw "Port $dbPort is already used by another process. Stop it manually or choose a different preview port."
}
if (-not $dbListener) {
    if (-not (Test-Path -LiteralPath (Join-Path $dataDir "mysql"))) {
        if (-not $mariaInit) {
            throw "MariaDB initialization tool mariadb-install-db.exe is missing from $mariaRoot."
        }
        New-Item -ItemType Directory -Force -Path $dataDir | Out-Null
        & $mariaInit.FullName "--datadir=$dataDir" "--password=$dbPassword" "--port=$dbPort"
        if ($LASTEXITCODE -ne 0) {
            throw "MariaDB data directory initialization failed (exit code $LASTEXITCODE)."
        }
    }
    $mariaBase = Split-Path -Parent (Split-Path -Parent $mariaExe.FullName)
    $mariaArguments = @(
        "--no-defaults",
        "--basedir=`"$mariaBase`"",
        "--datadir=`"$dataDir`"",
        "--port=$dbPort",
        "--bind-address=127.0.0.1",
        "--console"
    ) -join " "
    $mariaProcess = Start-Process -FilePath $mariaExe.FullName -ArgumentList $mariaArguments -WorkingDirectory $projectRoot -RedirectStandardOutput (Join-Path $logDir "mariadb.out.log") -RedirectStandardError (Join-Path $logDir "mariadb.error.log") -PassThru -WindowStyle Hidden
    Set-Content -LiteralPath $dbPidPath -Value $mariaProcess.Id
}

$dbReady = $false
for ($attempt = 0; $attempt -lt 40; $attempt++) {
    Start-Sleep -Milliseconds 750
    $dbListener = Get-NetTCPConnection -State Listen -LocalPort $dbPort -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($dbListener) {
        $dbReady = $true
        break
    }
    if (Test-Path -LiteralPath (Join-Path $logDir "mariadb.error.log")) {
        $errorText = Get-Content -LiteralPath (Join-Path $logDir "mariadb.error.log") -Raw
        if ($errorText -match "ERROR|FATAL") {
            throw "MariaDB did not start. See $logDir\mariadb.error.log"
        }
    }
}
if (-not $dbReady) {
    throw "MariaDB did not listen on 127.0.0.1:$dbPort. See $logDir\mariadb.error.log"
}

$env:DB_HOST = "127.0.0.1"
$env:DB_PORT = "$dbPort"
$env:DB_NAME = $dbName
$env:DB_USER = "root"
$env:DB_PASSWORD = $dbPassword
$clientArguments = @("--protocol=tcp", "--host=127.0.0.1", "--port=$dbPort", "--user=root", "--password=$dbPassword")
$createDatabaseSql = "CREATE DATABASE IF NOT EXISTS ``$dbName`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER IF NOT EXISTS '$dbUser'@'127.0.0.1' IDENTIFIED BY '$dbPassword'; GRANT ALL PRIVILEGES ON ``$dbName``.* TO '$dbUser'@'127.0.0.1'; FLUSH PRIVILEGES;"
& $mariaClient.FullName @clientArguments --execute=$createDatabaseSql
if ($LASTEXITCODE -ne 0) {
    throw "Could not configure the local preview database. See $logDir\mariadb.error.log"
}

$env:DB_USER = $dbUser
$schema = Join-Path $projectRoot "schema.sql"
$schemaSql = Get-Content -LiteralPath $schema -Raw
if ($ResetDatabase -or -not (Test-Path -LiteralPath (Join-Path $dataDir "rmt-schema-ready"))) {
    & $mariaClient.FullName @clientArguments $dbName "--execute=$schemaSql"
    if ($LASTEXITCODE -ne 0) {
        throw "The RMT schema could not be imported into the local preview database."
    }
    New-Item -ItemType File -Force -Path (Join-Path $dataDir "rmt-schema-ready") | Out-Null
}

$adminEmail = "preview.admin@example.test"
$existingAdmin = & $mariaClient.FullName @clientArguments --batch --skip-column-names $dbName "--execute=SELECT id FROM users WHERE email='$adminEmail' LIMIT 1"
if ($LASTEXITCODE -ne 0) {
    throw "Could not check the local preview administrator account."
}
if (-not $existingAdmin) {
    & $phpExe (Join-Path $projectRoot "create_admin.php") "RMT Preview Admin" $adminEmail "RmtPreview!2026"
    if ($LASTEXITCODE -ne 0) {
        throw "Could not create the local preview administrator account."
    }
}

$webListener = Get-NetTCPConnection -State Listen -LocalPort $webPort -ErrorAction SilentlyContinue | Select-Object -First 1
if ($webListener -and (-not (Test-Path -LiteralPath $webPidPath) -or [int](Get-Content -LiteralPath $webPidPath -Raw) -ne $webListener.OwningProcess)) {
    throw "Port $webPort is already used by another process. Stop it manually or choose a different preview port."
}
if (-not $webListener) {
    $phpArguments = "-S 127.0.0.1:$webPort -t `"$projectRoot\public`""
    $phpProcess = Start-Process -FilePath $phpExe -ArgumentList $phpArguments -WorkingDirectory $projectRoot -RedirectStandardOutput (Join-Path $logDir "php.out.log") -RedirectStandardError (Join-Path $logDir "php.error.log") -PassThru -WindowStyle Hidden
    Set-Content -LiteralPath $webPidPath -Value $phpProcess.Id
}

$webReady = $false
for ($attempt = 0; $attempt -lt 30; $attempt++) {
    Start-Sleep -Milliseconds 500
    try {
        $response = Invoke-WebRequest -Uri "http://127.0.0.1:$webPort/" -UseBasicParsing -TimeoutSec 3
        if ($response.StatusCode -eq 200 -and $response.Content -match "Revival Melodies") {
            $webReady = $true
            break
        }
    }
    catch {
        if ($attempt -eq 29) {
            throw "The PHP preview server did not become ready. See $logDir\php.error.log"
        }
    }
}
if (-not $webReady) {
    throw "The local homepage check did not succeed. See $logDir\php.error.log"
}

Write-Output "RMT preview is running: http://127.0.0.1:$webPort/"
Write-Output "Local MySQL-compatible database: 127.0.0.1:$dbPort/$dbName"
Write-Output "Preview admin: preview.admin@example.test / RmtPreview!2026"
Write-Output "Logs: $logDir"
