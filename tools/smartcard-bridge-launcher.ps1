$ErrorActionPreference = 'Stop'

$bridgePath = Join-Path $PSScriptRoot 'smartcard-bridge.ps1'
$bridgeSource = [IO.File]::ReadAllText($bridgePath, [Text.Encoding]::UTF8)
$bridgeScript = [ScriptBlock]::Create($bridgeSource)
& $bridgeScript
