$ErrorActionPreference = "Stop"
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$downloadDir = Join-Path $projectRoot ".local-preview\downloads"
New-Item -ItemType Directory -Force -Path $downloadDir | Out-Null

$downloads = @(
    @{
        Name = "PHP 8.4.26 x64 NTS"
        File = "php-8.4.26-nts-Win32-vs17-x64.zip"
        Url = "https://windows.php.net/downloads/releases/php-8.4.26-nts-Win32-vs17-x64.zip"
        Sha256 = "da68394f9193b7f6b89d0c76861a4034ae10efee7fd55a7255d8118c2acf70d7"
    },
    @{
        Name = "MariaDB 11.4.8 x64"
        File = "mariadb-11.4.8-winx64.zip"
        Url = "https://archive.mariadb.org/mariadb-11.4.8/winx64-packages/mariadb-11.4.8-winx64.zip"
        Sha256 = "ed86e93157af46317bb49161451c2ec258498a6fa8e68ca821ef1d780d855e6b"
    }
)

foreach ($download in $downloads) {
    $path = Join-Path $downloadDir $download.File
    if (-not (Test-Path -LiteralPath $path)) {
        Write-Output "Downloading $($download.Name) from its official release archive..."
        & curl.exe --ssl-no-revoke -fL --retry 2 --max-time 900 -o $path $download.Url
        if ($LASTEXITCODE -ne 0) {
            throw "Download failed for $($download.Name) (curl exit code $LASTEXITCODE)."
        }
    }
    $actual = (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($actual -ne $download.Sha256) {
        throw "$($download.Name) SHA-256 verification failed. Expected $($download.Sha256), received $actual."
    }
    Write-Output "Verified $($download.Name) SHA-256."
}

Write-Output "Portable runtimes are ready. Start the app with tools\start-preview.ps1."
