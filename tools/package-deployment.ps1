param(
    [string]$OutputPath = "",
    [ValidateSet("cpanel", "byet", "byet-htdocs")]
    [string]$Target = "cpanel",
    [string]$DatabaseHost = "",
    [string]$DatabaseName = "",
    [string]$DatabaseUser = ""
)

$ErrorActionPreference = "Stop"
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
if (-not $OutputPath) {
    $archiveName = switch ($Target) {
        "byet" { "RMT-Byet-Deployment.zip" }
        "byet-htdocs" { "RMT-Byet-htdocs-Upload.zip" }
        default { "RMT-Deployment.zip" }
    }
    $OutputPath = Join-Path $projectRoot "dist\$archiveName"
}
$OutputPath = [System.IO.Path]::GetFullPath($OutputPath)
$outputDirectory = Split-Path -Parent $OutputPath
New-Item -ItemType Directory -Force -Path $outputDirectory | Out-Null

$stage = Join-Path ([System.IO.Path]::GetTempPath()) ("rmt-deploy-" + [guid]::NewGuid().ToString("N"))
$publicFolderName = if ($Target -eq "cpanel") { "public_html" } else { "htdocs" }
$publicStage = if ($Target -eq "byet-htdocs") { $stage } else { Join-Path $stage $publicFolderName }
$privateStage = Join-Path $stage "rmt-private"

function Copy-DirectoryContents([string]$Source, [string]$Destination) {
    New-Item -ItemType Directory -Force -Path $Destination | Out-Null
    Get-ChildItem -LiteralPath $Source -Force | ForEach-Object {
        Copy-Item -LiteralPath $_.FullName -Destination $Destination -Recurse -Force
    }
}

try {
    New-Item -ItemType Directory -Force -Path $stage | Out-Null
    if ($Target -ne "byet-htdocs") {
        New-Item -ItemType Directory -Force -Path $privateStage | Out-Null
    }
    Copy-Item -LiteralPath (Join-Path $projectRoot "public\index.php") -Destination $publicStage
    Copy-Item -LiteralPath (Join-Path $projectRoot "public\.htaccess") -Destination $publicStage
    Copy-Item -LiteralPath (Join-Path $projectRoot "public\assets") -Destination $publicStage -Recurse -Force
    $publicUploadsStage = Join-Path $publicStage "uploads"
    New-Item -ItemType Directory -Force -Path $publicUploadsStage | Out-Null
    Copy-Item -LiteralPath (Join-Path $projectRoot "public\uploads\.htaccess") -Destination $publicUploadsStage

    if ($Target -eq "byet-htdocs") {
        New-Item -ItemType Directory -Force -Path $privateStage | Out-Null
    }
    Copy-Item -LiteralPath (Join-Path $projectRoot "app") -Destination $privateStage -Recurse -Force
    $privateStorageStage = Join-Path $privateStage "storage"
    New-Item -ItemType Directory -Force -Path $privateStorageStage | Out-Null
    Set-Content -LiteralPath (Join-Path $privateStorageStage ".htaccess") -Encoding ASCII -Value "Require all denied"
    Copy-Item -LiteralPath (Join-Path $projectRoot "schema.sql") -Destination $privateStage
    Copy-Item -LiteralPath (Join-Path $projectRoot "create_admin.php") -Destination $privateStage
    if ($Target -ne "byet-htdocs") {
        $deploymentGuide = if ($Target -eq "byet") { "DEPLOY-BYET.txt" } else { "DEPLOY.txt" }
        Copy-Item -LiteralPath (Join-Path $projectRoot "deployment\$deploymentGuide") -Destination $stage
    }

    $sampleConfig = Join-Path $privateStage "app\config.example.php"
    $localConfig = Join-Path $privateStage "app\config.local.php"
    Copy-Item -LiteralPath $sampleConfig -Destination $localConfig
    Remove-Item -LiteralPath $sampleConfig
    if ($DatabaseHost -or $DatabaseName -or $DatabaseUser) {
        if (-not ($DatabaseHost -and $DatabaseName -and $DatabaseUser)) {
            throw "Provide DatabaseHost, DatabaseName, and DatabaseUser together."
        }
        foreach ($databaseValue in @($DatabaseHost, $DatabaseName, $DatabaseUser)) {
            if ($databaseValue -notmatch '\A[a-zA-Z0-9._-]+\z') {
                throw "Database host, name and user must contain only letters, numbers, dots, underscores or hyphens."
            }
        }
        $configContent = Get-Content -LiteralPath $localConfig -Raw
        $configContent = $configContent.Replace("'host' => 'localhost'", "'host' => '$DatabaseHost'")
        $configContent = $configContent.Replace("'name' => 'cpanelprefix_rmt_ministry'", "'name' => '$DatabaseName'")
        $configContent = $configContent.Replace("'user' => 'cpanelprefix_rmt_user'", "'user' => '$DatabaseUser'")
        $configContent = $configContent.Replace("'password' => 'REPLACE_WITH_A_LONG_RANDOM_PASSWORD'", "'password' => 'ENTER_VPANEL_PASSWORD_HERE'")
        Set-Content -LiteralPath $localConfig -Value $configContent -Encoding ASCII
    }

    $privateHtaccess = Join-Path $privateStage ".htaccess"
    Set-Content -LiteralPath $privateHtaccess -Encoding ASCII -Value "Require all denied"
    $verificationStage = Join-Path $privateStage "storage\verification"
    New-Item -ItemType Directory -Force -Path $verificationStage | Out-Null
    $verificationHtaccess = Join-Path $verificationStage ".htaccess"
    Set-Content -LiteralPath $verificationHtaccess -Encoding ASCII -Value "Require all denied"

    if (Test-Path -LiteralPath $OutputPath) {
        Remove-Item -LiteralPath $OutputPath -Force
    }
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    [System.IO.Compression.ZipFile]::CreateFromDirectory(
        $stage,
        $OutputPath,
        [System.IO.Compression.CompressionLevel]::Optimal,
        $false
    )

    Write-Output "Deployment archive created: $OutputPath"
    Write-Output "Archive entries:"
    $archive = [System.IO.Compression.ZipFile]::OpenRead($OutputPath)
    try {
        $archive.Entries | ForEach-Object { Write-Output $_.FullName }
    }
    finally {
        $archive.Dispose()
    }
}
finally {
    if (Test-Path -LiteralPath $stage) {
        Remove-Item -LiteralPath $stage -Recurse -Force
    }
}
