
param(
  [string]$Site = "https://okean.market",
  [string]$BearerToken = $env:OKEAN_AI_BEARER,
  [switch]$RunSandboxCreateReject
)

$ErrorActionPreference = "Stop"
$base = ($Site.TrimEnd("/") + "/wp-json/okean-ai/v1")
$headers = @{}
if($BearerToken -ne "") { $headers["Authorization"] = "Bearer $BearerToken" }

$passed = 0
$failed = 0

function Write-Result {
  param([string]$Name, [bool]$Ok, [string]$Detail = "")
  if($Ok) {
    $script:passed++
    Write-Host ("PASS  " + $Name + $(if($Detail){" :: $Detail"}else{""}))
  } else {
    $script:failed++
    Write-Host ("FAIL  " + $Name + $(if($Detail){" :: $Detail"}else{""}))
  }
}

function Invoke-OaiGet {
  param([string]$Route)
  Invoke-RestMethod -Uri ($base + $Route) -Method GET -Headers $headers
}

function Invoke-OaiPost {
  param([string]$Route, [hashtable]$Body)
  $json = $Body | ConvertTo-Json -Depth 20
  Invoke-RestMethod -Uri ($base + $Route) -Method POST -Headers $headers -ContentType "application/json" -Body $json
}

function Invoke-OaiPostRaw {
  param([string]$Route, [string]$Json)
  try {
    $r = Invoke-WebRequest -Uri ($base + $Route) -Method POST -Headers $headers -ContentType "application/json" -Body $Json
    return [pscustomobject]@{ Status = [int]$r.StatusCode; Body = $r.Content }
  } catch {
    if($_.Exception.Response) {
      $resp = $_.Exception.Response
      $reader = New-Object System.IO.StreamReader($resp.GetResponseStream())
      try { $body = $reader.ReadToEnd() } finally { $reader.Dispose() }
      return [pscustomobject]@{ Status = [int]$resp.StatusCode; Body = $body }
    }
    throw
  }
}

Write-Host "Okean AI Admin runtime smoke"
Write-Host "Site: $Site"
Write-Host ""

try {
  $fp = Invoke-OaiGet "/runtime/fingerprint"
  Write-Result "Runtime fingerprint reachable" ($fp.ok -eq $true) ("version=" + $fp.version + ", build=" + $fp.build_id)
  Write-Result "Execution route resolves to OAI_REST" ($fp.routes.callback -contains "OAI_REST execution_wordpress" -or (($fp.routes | ForEach-Object { $_.callback -join " " }) -contains "OAI_REST execution_wordpress"))
} catch {
  Write-Result "Runtime fingerprint reachable" $false $_.Exception.Message
}

try {
  $ready = Invoke-OaiGet "/execution/readiness"
  Write-Result "Execution readiness reachable" ($ready.direct_live_writes -eq $false -and $ready.approval_required -eq $true)
  Write-Result "No direct live WP-CLI writes" ($ready.execution.direct_write_execution -eq $false)
  Write-Result "DB query is read-only" ($ready.execution.db_query_read_only -eq $true)
} catch {
  Write-Result "Execution readiness reachable" $false $_.Exception.Message
}

try {
  $caps = Invoke-OaiGet "/read/capabilities"
  Write-Result "Capabilities reachable" ($caps.read -contains "gutenberg" -or $caps.read -contains "files")
  Write-Result "Woo sandbox capability advertised" ($caps.sandbox -contains "woo_product_price" -and $caps.sandbox -contains "woo_product_stock")
} catch {
  Write-Result "Capabilities reachable" $false $_.Exception.Message
}

try {
  $diag = Invoke-OaiGet "/diagnostics"
  Write-Result "Diagnostics healthy" ($diag.ok -eq $true -and ($diag.issues | Where-Object { $_.status -ne "ok" }).Count -eq 0)
} catch {
  Write-Result "Diagnostics healthy" $false $_.Exception.Message
}

try {
  $cli = Invoke-OaiPost "/execution/wordpress" @{ command = "core version"; mode = "read" }
  Write-Result "WP-CLI core version read" ($cli.version -match '^\d+\.\d+')
} catch {
  Write-Result "WP-CLI core version read" $false $_.Exception.Message
}

try {
  $r = Invoke-OaiPostRaw "/execution/wordpress" '{"command":"core version --bogus=1","mode":"read"}'
  $body = $r.Body | ConvertFrom-Json
  Write-Result "Unknown WP-CLI flag rejected" ($r.Status -eq 400 -and $body.code -eq "oai_cli_unknown_flag") ("HTTP=" + $r.Status)
} catch {
  Write-Result "Unknown WP-CLI flag rejected" $false $_.Exception.Message
}

try {
  $cli = Invoke-OaiPost "/execution/wordpress" @{ command = "post list --number 1"; mode = "read" }
  $count = @($cli).Count
  Write-Result "WP-CLI --number=1 honored" ($count -eq 1) ("count=" + $count)
} catch {
  Write-Result "WP-CLI --number=1 honored" $false $_.Exception.Message
}

try {
  $r = Invoke-OaiPostRaw "/execution/wordpress" '{"command":"option update oai_runtime_smoke_probe 1","mode":"apply"}'
  $body = $r.Body | ConvertFrom-Json
  Write-Result "Invalid WP-CLI apply mode rejected" ($r.Status -eq 400 -and $body.code -eq "oai_execution_mode") ("HTTP=" + $r.Status)
} catch {
  Write-Result "Invalid WP-CLI apply mode rejected" $false $_.Exception.Message
}

try {
  $plan = Invoke-OaiPost "/execution/wordpress" @{ command = "option update oai_runtime_smoke_probe 1"; mode = "read" }
  Write-Result "WP-CLI write stays lifecycle-only" ($plan.execution_model -eq "sandbox_then_approval" -and $plan.executable_now -eq $false)
} catch {
  Write-Result "WP-CLI write stays lifecycle-only" $false $_.Exception.Message
}

try {
  $g = Invoke-OaiGet "/read/gutenberg/35279"
  Write-Result "Gutenberg resolver reachable" ($g.post_id -eq 35279 -and $null -ne $g.blocks)
} catch {
  Write-Result "Gutenberg resolver reachable" $false $_.Exception.Message
}

try {
  $g = Invoke-OaiGet "/read/gutenberg/35279/locate?text=доставка"
  Write-Result "Gutenberg text locate returns structured response" ($g.post_id -eq 35279 -and $null -ne $g.count)
} catch {
  Write-Result "Gutenberg text locate returns structured response" $false $_.Exception.Message
}

try {
  $g = Invoke-OaiGet "/read/gutenberg/35279/locate?html_contains=доставка"
  Write-Result "Gutenberg html_contains locate returns structured response" ($g.post_id -eq 35279 -and $null -ne $g.count)
} catch {
  Write-Result "Gutenberg html_contains locate returns structured response" $false $_.Exception.Message
}

try {
  $e = Invoke-OaiGet "/read/content/31499/elementor/14a1e75"
  Write-Result "Elementor widget resolver reachable" ($e.widget_id -eq "14a1e75" -and $e.widget_type -eq "html")
} catch {
  Write-Result "Elementor widget resolver reachable" $false $_.Exception.Message
}

try {
  $r = Invoke-WebRequest -Uri ($base + "/read/content/31499/elementor/___NO___") -Method GET -Headers $headers
  Write-Result "Invalid Elementor widget rejected" $false ("unexpected HTTP=" + $r.StatusCode)
} catch {
  if($_.Exception.Response) {
    $resp = $_.Exception.Response
    $reader = New-Object System.IO.StreamReader($resp.GetResponseStream())
    try { $body = $reader.ReadToEnd() } finally { $reader.Dispose() }
    $ok = ([int]$resp.StatusCode -eq 404 -and (($body | ConvertFrom-Json).code -eq "oai_elementor_node"))
    Write-Result "Invalid Elementor widget rejected" $ok ("HTTP=" + [int]$resp.StatusCode)
  } else {
    Write-Result "Invalid Elementor widget rejected" $false $_.Exception.Message
  }
}

try {
  $wc = Invoke-OaiGet "/read/woocommerce/35127"
  Write-Result "WooCommerce product resolver reachable" ($wc.id -eq 35127 -and $wc.sku -ne $null)
} catch {
  Write-Result "WooCommerce product resolver reachable" $false $_.Exception.Message
}

foreach($probe in @(
  @{ Name = "File root traversal '.' blocked"; Route = "/files/list?scope=child_theme&path=." ; Code = "oai_path_denied" },
  @{ Name = "File traversal '..' blocked"; Route = "/files/list?scope=child_theme&path=../wp-config.php" ; Code = "oai_path_denied" },
  @{ Name = "Sensitive file blocked"; Route = "/files/list?scope=child_theme&path=/wp-config.php" ; Code = "oai_path_denied" }
)) {
  try {
    $x = Invoke-WebRequest -Uri ($base + $probe.Route) -Method GET -Headers $headers
    Write-Result $probe.Name $false ("unexpected HTTP=" + $x.StatusCode)
  } catch {
    if($_.Exception.Response) {
      $resp = $_.Exception.Response
      $reader = New-Object System.IO.StreamReader($resp.GetResponseStream())
      try { $body = $reader.ReadToEnd() } finally { $reader.Dispose() }
      $json = $body | ConvertFrom-Json
      Write-Result $probe.Name ([int]$resp.StatusCode -eq 403 -and $json.code -eq $probe.Code) ("HTTP=" + [int]$resp.StatusCode)
    } else {
      Write-Result $probe.Name $false $_.Exception.Message
    }
  }
}

try {
  $q = Invoke-OaiPost "/execution/wordpress" @{ command = 'db query "SELECT 1 LIMIT 1"'; mode = "read" }
  Write-Result "DB SELECT read works" ($q.count -le 1 -and $q.limited_to -eq 100)
} catch {
  Write-Result "DB SELECT read works" $false $_.Exception.Message
}

try {
  $r = Invoke-OaiPostRaw "/execution/wordpress" '{"command":"db query \"UPDATE sushi_options SET option_value=1\"","mode":"read"}'
  $body = $r.Body | ConvertFrom-Json
  Write-Result "DB mutating SQL rejected" ($r.Status -eq 403 -and $body.code -eq "oai_db_read_only")
} catch {
  Write-Result "DB mutating SQL rejected" $false $_.Exception.Message
}

if($RunSandboxCreateReject) {
  try {
    $op = Invoke-OaiPost "/woocommerce/patch" @{
      product_id = 35127
      changes = @{ stock_quantity = 1; stock_status = "instock" }
    }
    $id = [int]$op.id
    Write-Result "Woo sandbox operation created" ($id -gt 0 -and $op.status -eq "pending") ("id=" + $id)
    $reject = Invoke-OaiPost ("/sandbox/{0}/reject" -f $id) @{}
    Write-Result "Woo sandbox operation rejected cleanly" ($reject.status -eq "rejected") ("id=" + $id)
  } catch {
    Write-Result "Woo sandbox create/reject" $false $_.Exception.Message
  }
} else {
  Write-Host "SKIP  Woo sandbox create/reject (use -RunSandboxCreateReject for the write-lifecycle probe)"
}

Write-Host ""
Write-Host ("RESULT  Passed: {0}  Failed: {1}" -f $passed, $failed)
if($failed -gt 0) { exit 1 }
