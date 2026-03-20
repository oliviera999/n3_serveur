[CmdletBinding()]
param(
    [string]$ChangelogPath = (Join-Path $PSScriptRoot "..\CHANGELOG.md"),
    [string]$ArchiveDir = (Join-Path $PSScriptRoot "..\docs\changelog\archive"),
    [int]$MaxSizeKB = 300,
    [int]$KeepLatest = 40,
    [switch]$CheckOnly,
    [switch]$Rotate
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

if (-not $CheckOnly -and -not $Rotate) {
    $CheckOnly = $true
}

function Read-Utf8Strict {
    param([string]$Path)
    $bytes = [System.IO.File]::ReadAllBytes($Path)
    $utf8Strict = [System.Text.UTF8Encoding]::new($false, $true)
    return $utf8Strict.GetString($bytes)
}

function Write-Utf8NoBom {
    param(
        [string]$Path,
        [string]$Content
    )
    $utf8 = [System.Text.UTF8Encoding]::new($false)
    [System.IO.File]::WriteAllText($Path, $Content, $utf8)
}

function Get-VersionHeadingMatches {
    param([string]$Content)
    $regex = [regex]'(?m)^## \[(?<version>[^\]]+)\] - (?<date>\d{4}-\d{2}-\d{2})\s*$'
    return $regex.Matches($Content)
}

function Split-Changelog {
    param([string]$Content)

    $matches = Get-VersionHeadingMatches -Content $Content
    if ($matches.Count -eq 0) {
        throw "Aucune entree de version detectee. Format attendu: ## [x.y.z] - YYYY-MM-DD"
    }

    $header = $Content.Substring(0, $matches[0].Index).TrimEnd() + "`n`n"
    $blocks = New-Object System.Collections.Generic.List[string]

    for ($i = 0; $i -lt $matches.Count; $i++) {
        $start = $matches[$i].Index
        $end = if ($i -lt $matches.Count - 1) { $matches[$i + 1].Index } else { $Content.Length }
        $blocks.Add($Content.Substring($start, $end - $start).Trim())
    }

    return @{
        Header = $header
        Blocks = $blocks
    }
}

if (-not (Test-Path -LiteralPath $ChangelogPath)) {
    throw "CHANGELOG introuvable: $ChangelogPath"
}

$content = Read-Utf8Strict -Path $ChangelogPath
$matches = Get-VersionHeadingMatches -Content $content

$versions = @()
foreach ($m in $matches) {
    $versions += $m.Groups["version"].Value
}

$dups = $versions | Group-Object | Where-Object { $_.Count -gt 1 } | Select-Object -ExpandProperty Name
if ($dups.Count -gt 0) {
    throw "Doublons de versions detectes: $($dups -join ', ')"
}

$sizeKB = [math]::Round((Get-Item -LiteralPath $ChangelogPath).Length / 1KB, 2)
if ($sizeKB -gt $MaxSizeKB -and -not $Rotate) {
    throw "CHANGELOG.md trop volumineux (${sizeKB}KB > ${MaxSizeKB}KB). Lancez avec -Rotate."
}

if ($CheckOnly -and -not $Rotate) {
    Write-Host "OK changelog: UTF-8 valide, versions uniques, taille ${sizeKB}KB."
    exit 0
}

$split = Split-Changelog -Content $content
$blocks = $split.Blocks

if ($blocks.Count -le $KeepLatest -and $sizeKB -le $MaxSizeKB) {
    Write-Host "Aucune rotation necessaire: $($blocks.Count) entrees, ${sizeKB}KB."
    exit 0
}

$keep = @()
$archive = @()

for ($i = 0; $i -lt $blocks.Count; $i++) {
    if ($i -lt $KeepLatest) {
        $keep += $blocks[$i]
    }
    else {
        $archive += $blocks[$i]
    }
}

if (-not (Test-Path -LiteralPath $ArchiveDir)) {
    New-Item -ItemType Directory -Path $ArchiveDir -Force | Out-Null
}

$archivePath = Join-Path $ArchiveDir ("CHANGELOG-archive-{0}.md" -f (Get-Date -Format "yyyy"))
if (-not (Test-Path -LiteralPath $archivePath)) {
    Write-Utf8NoBom -Path $archivePath -Content "# Archive changelog serveur`n`nArchive generee automatiquement depuis CHANGELOG.md.`n"
}

$archiveContent = Read-Utf8Strict -Path $archivePath
$toAppend = ($archive -join "`n`n---`n`n").Trim()
if (-not [string]::IsNullOrWhiteSpace($toAppend)) {
    if (-not $archiveContent.EndsWith("`n")) {
        $archiveContent += "`n"
    }
    $archiveContent += "`n" + $toAppend + "`n"
    Write-Utf8NoBom -Path $archivePath -Content $archiveContent
}

$newChangelog = $split.Header + ($keep -join "`n`n---`n`n") + "`n"
Write-Utf8NoBom -Path $ChangelogPath -Content $newChangelog

$newSize = [math]::Round((Get-Item -LiteralPath $ChangelogPath).Length / 1KB, 2)
Write-Host "Rotation terminee: $($archive.Count) entree(s) archivee(s), $($keep.Count) conservee(s), taille finale ${newSize}KB."
