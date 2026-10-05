param(
    [string]$OutputPath = ""
)

$ErrorActionPreference = "Stop"
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
if (-not $OutputPath) {
    $OutputPath = Join-Path $projectRoot "dist\RMT-Deployment.zip"
}
$OutputPath = [System.IO.Path]::GetFullPath($OutputPath)
$outputDirectory = Split-Path -Parent $OutputPath
New-Item -ItemType Directory -Force -Path $outputDirectory | Out-Null

$stage = Join-Path ([System.IO.Path]::GetTempPath()) ("rmt-deploy-" + [guid]::NewGuid().ToString("N"))
$publicStage = Join-Path $stage "public_html"
$privateStage = Join-Path $stage "rmt-private"

function Copy-DirectoryContents([string]$Source, [string]$Destination) {
    New-Item -ItemType Directory -Force -Path $Destination | Out-Null
    Get-ChildItem -LiteralPath $Source -Force | ForEach-Object {
        Copy-Item -LiteralPath $_.FullName -Destination $Destination -Recurse -Force
    }
}

try {
    New-Item -ItemType Directory -Force -Path $publicStage, $privateStage | Out-Null
    Copy-Item -LiteralPath (Join-Path $projectRoot "public\index.php") -Destination $publicStage
    Copy-Item -LiteralPath (Join-Path $projectRoot "public\.htaccess") -Destination $publicStage
    Copy-Item -LiteralPath (Join-Path $projectRoot "public\assets") -Destination $publicStage -Recurse -Force
    $publicUploadsStage = Join-Path $publicStage "uploads"
    New-Item -ItemType Directory -Force -Path $publicUploadsStage | Out-Null
    Copy-Item -LiteralPath (Join-Path $projectRoot "public\uploads\.htaccess") -Destination $publicUploadsStage

    Copy-Item -LiteralPath (Join-Path $projectRoot "app") -Destination $privateStage -Recurse -Force
    $privateStorageStage = Join-Path $privateStage "storage"
    New-Item -ItemType Directory -Force -Path $privateStorageStage | Out-Null
    Set-Content -LiteralPath (Join-Path $privateStorageStage ".htaccess") -Encoding ASCII -Value "Require all denied"
    Copy-Item -LiteralPath (Join-Path $projectRoot "schema.sql") -Destination $privateStage
    Copy-Item -LiteralPath (Join-Path $projectRoot "create_admin.php") -Destination $privateStage
    Copy-Item -LiteralPath (Join-Path $projectRoot "deployment\DEPLOY.txt") -Destination $stage

    $sampleConfig = Join-Path $privateStage "app\config.example.php"
    $localConfig = Join-Path $privateStage "app\config.local.php"
    Copy-Item -LiteralPath $sampleConfig -Destination $localConfig
    Remove-Item -LiteralPath $sampleConfig

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
