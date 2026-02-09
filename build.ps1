# View Builder Plugin Build Script
$version = "1.2.1"
$pluginName = "plg_system_viewbuilder"
$zipName = "$($pluginName)_v$($version).zip"

# Clean up any existing zip
if (Test-Path $zipName) {
    Remove-Item $zipName
}

Write-Host "Creating package $zipName..." -ForegroundColor Cyan

# Define items to include
$includeItems = @(
    "src",
    "services",
    "media",
    "language",
    "viewbuilder.xml",
    "README.md",
    "CHANGELOG.md",
    "cache"
)

# Create the ZIP
Compress-Archive -Path $includeItems -DestinationPath $zipName

# Create a generic ZIP for the stable release link
$stableZipName = "$($pluginName).zip"
if (Test-Path $stableZipName) { Remove-Item $stableZipName }
Compress-Archive -Path $includeItems -DestinationPath $stableZipName

Write-Host "Build complete: $zipName and $stableZipName" -ForegroundColor Green
