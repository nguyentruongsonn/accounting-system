[CmdletBinding()]
param(
    [string]$ConfigurationPath = (Join-Path (Split-Path -Parent $PSScriptRoot) 'backend/phpunit.xml'),
    [string]$Filter,
    [string]$TestPath,
    [switch]$ListTests,
    [switch]$DryRun
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$workspace = Split-Path -Parent $PSScriptRoot
$backendRoot = Join-Path $workspace 'backend'
$requiredContract = [ordered]@{
    APP_ENV = @{ value = 'testing'; force = 'true' }
    DB_CONNECTION = @{ value = 'sqlite'; force = 'true' }
    DB_DATABASE = @{ value = ':memory:'; force = 'true' }
    DB_URL = @{ value = ''; force = 'true' }
}

function Fail-SafeContract([string]$Message) {
    throw "SAFE BACKEND TEST BLOCKED: $Message"
}

function Read-RequiredEnvNode([System.Xml.XmlDocument]$Document, [string]$Name) {
    $node = $Document.SelectSingleNode("/phpunit/php/env[@name='$Name']")
    if ($null -eq $node) {
        Fail-SafeContract "backend/phpunit.xml is missing forced env '$Name'."
    }
    return $node
}

function Assert-SafePhpUnitContract([string]$PathValue) {
    if (-not (Test-Path -LiteralPath $PathValue -PathType Leaf)) {
        Fail-SafeContract "PHPUnit configuration does not exist: $PathValue"
    }

    try {
        [xml]$document = Get-Content -Raw -LiteralPath $PathValue
    } catch {
        Fail-SafeContract "PHPUnit configuration is not valid XML: $($_.Exception.Message)"
    }

    foreach ($name in $requiredContract.Keys) {
        $node = Read-RequiredEnvNode $document $name
        $expected = $requiredContract[$name]
        $actualValue = [string]$node.GetAttribute('value')
        $actualForce = [string]$node.GetAttribute('force')
        if ($actualValue -cne [string]$expected.value -or $actualForce -cne [string]$expected.force) {
            Fail-SafeContract "forced env '$name' must be value='$($expected.value)' force='$($expected.force)'; found value='$actualValue' force='$actualForce'."
        }
    }
}

$resolvedConfig = (Resolve-Path -LiteralPath $ConfigurationPath -ErrorAction SilentlyContinue).Path
if ([string]::IsNullOrWhiteSpace($resolvedConfig)) {
    Fail-SafeContract "PHPUnit configuration cannot be resolved: $ConfigurationPath"
}
Assert-SafePhpUnitContract $resolvedConfig

$phpunit = Join-Path $backendRoot 'vendor/bin/phpunit.bat'
if (-not (Test-Path -LiteralPath $phpunit -PathType Leaf)) {
    Fail-SafeContract "PHPUnit binary is missing: $phpunit. Run composer install in backend first."
}

$configurationArgument = if ($resolvedConfig -eq (Join-Path $backendRoot 'phpunit.xml')) {
    'phpunit.xml'
} else {
    $resolvedConfig
}
$arguments = @('--configuration', $configurationArgument, '--colors=never')
if ($ListTests) { $arguments += '--list-tests' }
if (-not [string]::IsNullOrWhiteSpace($Filter)) { $arguments += @('--filter', $Filter) }
if (-not [string]::IsNullOrWhiteSpace($TestPath)) { $arguments += $TestPath }

Write-Output 'SAFE BACKEND TEST CONTRACT: APP_ENV=testing; DB_CONNECTION=sqlite; DB_DATABASE=:memory:; DB_URL=(empty)'
Write-Output ("COMMAND: vendor/bin/phpunit.bat " + ($arguments -join ' '))
Write-Output 'BOUNDARY: this runner never targets the application MySQL database; it uses PHPUnit forced SQLite memory.'

if ($DryRun) {
    Write-Output 'DRY-RUN: PHPUnit was not started.'
    exit 0
}

$environmentNames = @('APP_ENV', 'DB_CONNECTION', 'DB_DATABASE', 'DB_URL')
$previousEnvironment = @{}
$hadEnvironmentValue = @{}
foreach ($name in $environmentNames) {
    $hadEnvironmentValue[$name] = Test-Path -LiteralPath "Env:$name"
    $previousEnvironment[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
}

$exitCode = 1
Push-Location $backendRoot
try {
    # Set the child process boundary as well as the XML boundary. This makes
    # the safe contract explicit even when the caller inherited app settings.
    $env:APP_ENV = 'testing'
    $env:DB_CONNECTION = 'sqlite'
    $env:DB_DATABASE = ':memory:'
    $env:DB_URL = ''
    & $phpunit @arguments
    $exitCode = $LASTEXITCODE
} finally {
    Pop-Location
    foreach ($name in $environmentNames) {
        if ($hadEnvironmentValue[$name]) {
            [Environment]::SetEnvironmentVariable($name, $previousEnvironment[$name], 'Process')
        } else {
            Remove-Item -LiteralPath "Env:$name" -ErrorAction SilentlyContinue
        }
    }
}

exit $exitCode
