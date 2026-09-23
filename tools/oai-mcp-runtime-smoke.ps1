
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
  $r=Invoke-Mcp 8 "tools/call" @{name="wp.get_site";arguments=@{}}
  $toolText=[string]$r.result.content[0].text
  $data=$toolText|ConvertFrom-Json
  Write-Result "MCP wp.get_site" ($data.name -ne $null -and $data.url -ne $null)
} catch { Write-Result "MCP wp.get_site" $false $_.Exception.Message }

try {
  $r=Invoke-Mcp 9 "tools/call" @{name="builder.detect";arguments=@{post_id=35279}}
  $toolText=[string]$r.result.content[0].text
  $data=$toolText|ConvertFrom-Json
  $ids=@($data.matches|ForEach-Object{$_.id})
  Write-Result "MCP builder.detect Gutenberg" ($ids -contains "gutenberg" -and $data.primary.id -eq "gutenberg")
} catch { Write-Result "MCP builder.detect Gutenberg" $false $_.Exception.Message }

try {
  $r=Invoke-Mcp 10 "tools/call" @{name="builder.detect";arguments=@{post_id=31499}}
  $toolText=[string]$r.result.content[0].text
  $data=$toolText|ConvertFrom-Json
  $ids=@($data.matches|ForEach-Object{$_.id})
  Write-Result "MCP builder.detect Elementor" ($ids -contains "elementor" -and $data.primary.id -eq "elementor")
} catch { Write-Result "MCP builder.detect Elementor" $false $_.Exception.Message }

try {
  $r=Invoke-Mcp 11 "tools/call" @{name="gutenberg.get_blocks";arguments=@{post_id=35279}}
  $toolText=[string]$r.result.content[0].text
  $data=$toolText|ConvertFrom-Json
  Write-Result "MCP Gutenberg get_blocks" ($data.post_id -eq 35279 -and $null -ne $data.blocks)
} catch { Write-Result "MCP Gutenberg get_blocks" $false $_.Exception.Message }

try {
  $r=Invoke-Mcp 12 "tools/call" @{name="gutenberg.locate_block";arguments=@{post_id=35279;text="доставка"}}
  $toolText=[string]$r.result.content[0].text
  $data=$toolText|ConvertFrom-Json
  Write-Result "MCP Gutenberg locate_block" ($data.post_id -eq 35279 -and $null -ne $data.count)
} catch { Write-Result "MCP Gutenberg locate_block" $false $_.Exception.Message }

try {
  $r=Invoke-Mcp 13 "tools/call" @{name="elementor.get_widget";arguments=@{post_id=31499;widget_id="14a1e75"}}
  $toolText=[string]$r.result.content[0].text
  $data=$toolText|ConvertFrom-Json
  Write-Result "MCP Elementor get_widget" ($data.widget_id -eq "14a1e75" -and $data.widget_type -eq "html")
} catch { Write-Result "MCP Elementor get_widget" $false $_.Exception.Message }

try {
  $r=Invoke-Mcp 14 "tools/call" @{name="woocommerce.get_product";arguments=@{product_id=35127}}
  $toolText=[string]$r.result.content[0].text
  $data=$toolText|ConvertFrom-Json
  Write-Result "MCP WooCommerce get_product" ($data.id -eq 35127 -and $data.sku -ne $null)
} catch { Write-Result "MCP WooCommerce get_product" $false $_.Exception.Message }

try {
  $r=Invoke-Mcp 15 "tools/call" @{name="wordpress.execute";arguments=@{command="core version";mode="read"}}
  $toolText=[string]$r.result.content[0].text
  $data=$toolText|ConvertFrom-Json
  Write-Result "MCP wordpress.execute read" ($data.version -match '^\\d+\\.\\d+')
} catch { Write-Result "MCP wordpress.execute read" $false $_.Exception.Message }

try {
  $r=Invoke-Mcp 16 "tools/call" @{name="wordpress.execute";arguments=@{command="option update oai_mcp_runtime_plan_probe 1";mode="plan"}}
  $toolText=[string]$r.result.content[0].text
  $data=$toolText|ConvertFrom-Json
  Write-Result "MCP wordpress.execute write remains planned" ($data.execution_model -eq "sandbox_then_approval" -and $data.executable_now -eq $false)
} catch { Write-Result "MCP wordpress.execute write remains planned" $false $_.Exception.Message }

try {
  $r=Invoke-Mcp 17 "tools/call" @{name="operation.approve";arguments=@{id=1}}
  $ok=($null -ne $r.error -and $r.error.code -eq -32003)
  Write-Result "MCP human approval guard" $ok
} catch { Write-Result "MCP human approval guard" $false $_.Exception.Message }

Write-Host ""
Write-Host ("RESULT  Passed: {0}  Failed: {1}" -f $passed,$failed)
if($failed -gt 0){exit 1}
