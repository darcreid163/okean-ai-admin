
param(
  [string]$Endpoint = "https://okean.market/wp-json/okean-ai/mcp",
  [string]$McpToken = $env:OKEAN_MCP_TOKEN
)

$ErrorActionPreference = "Stop"
$headers = @{}
if($McpToken -ne "") { $headers["Authorization"] = "Bearer $McpToken" }

$passed = 0
$failed = 0

function Write-Result {
  param([string]$Name,[bool]$Ok,[string]$Detail="")
  if($Ok) {
    $script:passed++
    Write-Host ("PASS  " + $Name + $(if($Detail){" :: $Detail"}else{""}))
  } else {
    $script:failed++
    Write-Host ("FAIL  " + $Name + $(if($Detail){" :: $Detail"}else{""}))
  }
}

function Invoke-Mcp {
  param([int]$Id,[string]$Method,[hashtable]$Params=@{})
  $body=@{jsonrpc="2.0";id=$Id;method=$Method;params=$Params}|ConvertTo-Json -Depth 20
  Invoke-RestMethod -Uri $Endpoint -Method POST -Headers $headers -ContentType "application/json" -Body $body
}

Write-Host "Okean AI Admin MCP runtime smoke"
Write-Host "Endpoint: $Endpoint"
Write-Host ""

if($McpToken -eq "") {
  Write-Host "FAIL  MCP token is not set. Set OKEAN_MCP_TOKEN or pass -McpToken."
  exit 2
}

try {
  $r=Invoke-Mcp 1 "initialize"
  Write-Result "MCP initialize" ($r.jsonrpc -eq "2.0" -and $r.result.protocolVersion -ne $null -and $r.result.capabilities.tools -ne $null) ("protocol=" + $r.result.protocolVersion)
} catch { Write-Result "MCP initialize" $false $_.Exception.Message }

try {
  $r=Invoke-Mcp 2 "tools/list"
  $names=@($r.result.tools|ForEach-Object{$_.name})
  $unique=(@($names|Select-Object -Unique)).Count -eq $names.Count
  $required=@("wordpress.execute","builder.catalog","builder.detect","system.capabilities")
  $present=$true
  foreach($n in $required){if($names -notcontains $n){$present=$false}}
  Write-Result "MCP tools/list" ($r.jsonrpc -eq "2.0" -and $unique -and $present -and $names.Count -gt 20) ("tools=" + $names.Count)
} catch { Write-Result "MCP tools/list" $false $_.Exception.Message }

try {
  $r=Invoke-Mcp 3 "resources/list"
  $uris=@($r.result.resources|ForEach-Object{$_.uri})
  Write-Result "MCP resources/list" ($uris -contains "okean://capabilities" -and $uris -contains "okean://site/status") ("resources=" + $uris.Count)
} catch { Write-Result "MCP resources/list" $false $_.Exception.Message }

try {
  $r=Invoke-Mcp 4 "resources/read" @{uri="okean://capabilities"}
  $text=[string]$r.result.contents[0].text
  $cap=$text|ConvertFrom-Json
  Write-Result "MCP resources/read capabilities" ($cap.read -contains "elementor" -and $cap.security.direct_live_writes -eq $false) ("bytes=" + $text.Length)
} catch { Write-Result "MCP resources/read capabilities" $false $_.Exception.Message }

try {
  $r=Invoke-Mcp 5 "tools/call" @{name="builder.catalog";arguments=@{}}
  $toolText=[string]$r.result.content[0].text
  $data=$toolText|ConvertFrom-Json
  $ids=@($data.items|ForEach-Object{$_.id})
  Write-Result "MCP builder.catalog" ($ids -contains "elementor" -and $ids -contains "gutenberg" -and $ids -contains "divi") ("adapters=" + $ids.Count)
} catch { Write-Result "MCP builder.catalog" $false $_.Exception.Message }

try {
  $r=Invoke-Mcp 6 "tools/call" @{name="system.capabilities";arguments=@{}}
  $toolText=[string]$r.result.content[0].text
  $data=$toolText|ConvertFrom-Json
  Write-Result "MCP system.capabilities" ($data.capabilities.security.direct_live_writes -eq $false -and $data.execution.direct_live_writes -eq $false)
} catch { Write-Result "MCP system.capabilities" $false $_.Exception.Message }

try {
  $r=Invoke-Mcp 7 "tools/call" @{name="operation.approve";arguments=@{id=1}}
  $ok=($null -ne $r.error -and $r.error.code -eq -32003)
  Write-Result "MCP human approval guard" $ok
} catch { Write-Result "MCP human approval guard" $false $_.Exception.Message }

Write-Host ""
Write-Host ("RESULT  Passed: {0}  Failed: {1}" -f $passed,$failed)
if($failed -gt 0){exit 1}
