# Okean AI Admin runtime smoke audit
# Revision: 2.0.21-r1
# Updated: 2026-09-24

param(
  [string]$Site = "https://okean.market"
)

$ErrorActionPreference = "Stop"
$base = ($Site.TrimEnd("/") + "/wp-json/okean-ai/v1")
$mcp = ($Site.TrimEnd("/") + "/wp-json/okean-ai/mcp")
$port = 43123
$redirect = "http://127.0.0.1:$port/callback"

$passed = 0
$failed = 0
$skipped = 0

function Base64Url([byte[]]$Bytes) {
  return [Convert]::ToBase64String($Bytes).TrimEnd('=') -replace '\+','-' -replace '/','_'
}

function Read-HttpJson([string]$Uri,[string]$Method,[hashtable]$Body) {
  $json = $Body | ConvertTo-Json -Depth 30
  return Invoke-RestMethod -Uri $Uri -Method $Method -ContentType "application/json" -Body $json
}

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

function Write-Skip {
  param([string]$Name,[string]$Detail="")
  $script:skipped++
  Write-Host ("SKIP  " + $Name + $(if($Detail){" :: $Detail"}else{""}))
}

function Invoke-Mcp-Raw {
  param([int]$Id,[string]$Method,[hashtable]$Params=@{},[hashtable]$HeadersOverride=$null)
  $h = if($null -ne $HeadersOverride){$HeadersOverride}else{$script:headers}
  $body=@{jsonrpc="2.0";id=$Id;method=$Method;params=$Params}|ConvertTo-Json -Depth 30
  return Invoke-RestMethod -Uri $script:mcp -Method POST -Headers $h -ContentType "application/json" -Body $body
}

function Invoke-Mcp {
  param([int]$Id,[string]$Method,[hashtable]$Params=@{},[hashtable]$HeadersOverride=$null)
  $r=Invoke-Mcp-Raw $Id $Method $Params $HeadersOverride
  if($null -ne $r.error) {
    throw ("JSON-RPC {0}: {1}" -f $r.error.code,$r.error.message)
  }
  return $r
}

function Tool-Json {
  param([int]$Id,[string]$Name,[hashtable]$Arguments=@{})
  $r=Invoke-Mcp $Id "tools/call" @{name=$Name;arguments=$Arguments}
  return ([string]$r.result.content[0].text | ConvertFrom-Json)
}

function Get-Scalar-Int {
  param($Value)
  if($null -eq $Value){ return 0 }
  if($Value -is [System.Array]){
    foreach($item in $Value){
      $n=Get-Scalar-Int $item
      if($n -gt 0){ return $n }
    }
    return 0
  }
  if($Value -isnot [string] -and $Value.PSObject.Properties.Name -contains "id"){
    return Get-Scalar-Int $Value.id
  }
  $parsed=0
  if([int]::TryParse([string]$Value,[ref]$parsed)){ return $parsed }
  return 0
}

function Invoke-Mcp-ExpectedError {
  param([int]$Id,[string]$Method,[hashtable]$Params,[int[]]$ExpectedCodes)
  try {
    $r=Invoke-Mcp-Raw $Id $Method $Params
    if($null -ne $r.error) {
      $code=[int]$r.error.code
      foreach($expected in $ExpectedCodes){
        if($code -eq $expected){
          return @{ ok=$true; detail=("error_code="+$code) }
        }
      }
      return @{ ok=$false; detail=("Unexpected JSON-RPC error code="+$code+" message="+[string]$r.error.message) }
    }
    return @{ ok=$false; detail="Expected JSON-RPC error but request returned a result" }
  } catch {
    $msg=[string]$_.Exception.Message
    if($msg -match "JSON-RPC\s+(-?\d+)"){
      $actual=[int]$Matches[1]
      foreach($code in $ExpectedCodes){
        if($actual -eq [int]$code){
          return @{ ok=$true; detail=("error_code="+$actual) }
        }
      }
    }
    foreach($code in $ExpectedCodes){
      if($msg -match [regex]::Escape([string]$code)){
        return @{ ok=$true; detail=("error_code="+$code) }
      }
    }
    return @{ ok=$false; detail=$msg }
  }
}

Write-Host "Okean AI Admin OAuth + MCP runtime audit"
Write-Host "Site: $Site"
Write-Host ""

Write-Host "Checking OAuth discovery..."
try {
  $prm = Invoke-RestMethod -Uri ($Site.TrimEnd("/") + "/.well-known/oauth-protected-resource") -Method GET
  $asm = Invoke-RestMethod -Uri ($Site.TrimEnd("/") + "/.well-known/oauth-authorization-server") -Method GET
  Write-Result "OAuth protected-resource metadata" ($prm.resource -eq $mcp -and $prm.authorization_servers.Count -ge 1 -and ($prm.scopes_supported -contains "mcp:read") -and ($prm.scopes_supported -contains "mcp:write"))
  Write-Result "OAuth authorization-server metadata" (
    $asm.authorization_endpoint -ne $null -and
    $asm.token_endpoint -ne $null -and
    $asm.registration_endpoint -ne $null -and
    ($asm.code_challenge_methods_supported -contains "S256") -and ($asm.scopes_supported -contains "offline_access") -and ($asm.client_id_metadata_document_supported -eq $true) -and ($asm.token_endpoint_auth_methods_supported -contains "none")
  )
} catch {
  Write-Result "OAuth discovery" $false $_.Exception.Message
  exit 1
}

# CIMD discovery sanity check. This does not exchange or persist any token;
# it only verifies that ChatGPT's published public-client metadata is reachable.
try {
  $cimdUri = "https://chatgpt.com/oauth/client.json"
  $cimd = Invoke-RestMethod -Uri $cimdUri -Method GET -Headers @{Accept="application/json"}
  $cimdOk = ([string]$cimd.client_id -eq $cimdUri) -and
            (@($cimd.redirect_uris).Count -gt 0) -and
            ((@($cimd.token_endpoint_auth_methods_supported) -contains "none") -or ([string]$cimd.token_endpoint_auth_method -eq "none"))
  Write-Result "ChatGPT CIMD metadata reachable" $cimdOk ("client_id=" + [string]$cimd.client_id)
} catch {
  Write-Result "ChatGPT CIMD metadata reachable" $false $_.Exception.Message
}

$verifier = Base64Url (1..32 | ForEach-Object { [byte](Get-Random -Minimum 0 -Maximum 256) })
$challengeBytes = [Security.Cryptography.SHA256]::Create().ComputeHash([Text.Encoding]::UTF8.GetBytes($verifier))
$challenge = Base64Url $challengeBytes
$state = Base64Url (1..24 | ForEach-Object { [byte](Get-Random -Minimum 0 -Maximum 256) })

try {
  $client = Read-HttpJson ($base + "/oauth/register") "POST" @{
    client_name = "Okean AI Admin comprehensive OAuth runtime audit"
    redirect_uris = @($redirect)
    grant_types = @("authorization_code","refresh_token")
    response_types = @("code")
    token_endpoint_auth_method = "none"
  }
  $clientId = [string]$client.client_id
  Write-Result "OAuth dynamic client registration" ($clientId -ne "" -and $client.redirect_uris -contains $redirect -and ($client.scopes_supported -contains "mcp:read") -and ($client.scopes_supported -contains "mcp:write")) ("client=" + $clientId)
} catch {
  Write-Result "OAuth dynamic client registration" $false $_.Exception.Message
  exit 1
}

$query = [System.Web.HttpUtility]::ParseQueryString("")
$query["client_id"] = $clientId
$query["redirect_uri"] = $redirect
$query["response_type"] = "code"
# Explicit unsupported scopes must be rejected and must never fall back to full access.
try {
  $badScope = [System.Web.HttpUtility]::ParseQueryString("")
  $badScope["client_id"] = $clientId
  $badScope["redirect_uri"] = $redirect
  $badScope["response_type"] = "code"
  $badScope["scope"] = "unsupported:scope"
  $badScope["state"] = "oai-bad-scope-test"
  $badScope["code_challenge"] = $challenge
  $badScope["code_challenge_method"] = "S256"
  $badScope["resource"] = $mcp
  $badUri = $asm.authorization_endpoint + "?" + $badScope.ToString()
  try {
    Invoke-WebRequest -Uri $badUri -Method GET -MaximumRedirection 0 -ErrorAction Stop | Out-Null
    Write-Result "OAuth explicit invalid scope rejection" $false "unsupported scope was accepted"
  } catch {
    $status = [int]$_.Exception.Response.StatusCode
    $body = ""
    if($_.ErrorDetails -and $_.ErrorDetails.Message) { $body = [string]$_.ErrorDetails.Message }
    Write-Result "OAuth explicit invalid scope rejection" ($status -eq 400 -and $body -match "invalid_scope") ("HTTP " + $status)
  }
} catch {
  Write-Result "OAuth explicit invalid scope rejection" $false $_.Exception.Message
}

$query["scope"] = "mcp:read mcp:write offline_access"
$query["state"] = $state
$query["code_challenge"] = $challenge
$query["code_challenge_method"] = "S256"
$query["resource"] = $mcp

$authUri = $asm.authorization_endpoint + "?" + $query.ToString()
Write-Host ""
Write-Host "FULL OAuth authorization URL (same browser/profile as wp-admin):"
Write-Host $authUri
Write-Host ""

$listener = New-Object System.Net.HttpListener
$listener.Prefixes.Add("http://127.0.0.1:$port/")
$listener.Start()

Write-Host "Opening OAuth URL in the default browser."
Start-Process $authUri

$context = $listener.GetContext()
try {
  $callbackQuery = $context.Request.Url.Query
  $q = [System.Web.HttpUtility]::ParseQueryString($callbackQuery)
  if($q["state"] -ne $state) { throw "OAuth state mismatch" }
  if($q["iss"] -ne $Site.TrimEnd("/") ) { throw "OAuth issuer mismatch: " + [string]$q["iss"] }
  if($q["error"]) { throw ("OAuth authorization failed: " + $q["error"] + " " + $q["error_description"]) }
  $code = [string]$q["code"]
  if($code -eq "") { throw "Authorization callback returned no code" }
  $html = "<html><body><h2>Okean AI Admin: authorization received.</h2><p>You can return to PowerShell.</p></body></html>"
  $bytes=[Text.Encoding]::UTF8.GetBytes($html)
  $context.Response.ContentType="text/html; charset=utf-8"
  $context.Response.ContentLength64=$bytes.Length
  $context.Response.OutputStream.Write($bytes,0,$bytes.Length)
  Write-Result "OAuth authorization code callback" $true
} catch {
  Write-Result "OAuth authorization code callback" $false $_.Exception.Message
  exit 1
} finally {
  $context.Response.Close()
  $listener.Stop()
}

try {
  $token = Read-HttpJson ($asm.token_endpoint) "POST" @{
    grant_type = "authorization_code"
    code = $code
    client_id = $clientId
    redirect_uri = $redirect
    code_verifier = $verifier
    resource = $mcp
  }
  $accessToken = [string]$token.access_token
  $refreshToken = [string]$token.refresh_token
  Write-Result "OAuth authorization code exchange" ($accessToken -ne "" -and $refreshToken -ne "" -and $token.token_type -eq "Bearer" -and ([string]$token.scope -match "mcp:read") -and ([string]$token.scope -match "mcp:write") -and ([string]$token.scope -match "offline_access") -and ([string]$token.resource -eq $mcp)) ("expires_in=" + $token.expires_in + "; scope=" + $token.scope)
} catch {
  Write-Result "OAuth authorization code exchange" $false $_.Exception.Message
  exit 1
}

$headers=@{Authorization="Bearer "+$accessToken}

try {
  $r=Invoke-Mcp 100 "initialize"
  Write-Result "MCP initialize" ($r.jsonrpc -eq "2.0" -and $r.result.protocolVersion -eq "2025-06-18")
  $r2=Invoke-Mcp 99 "notifications/initialized"
  Write-Result "MCP notifications/initialized" ($r2.jsonrpc -eq "2.0")
  $r3=Invoke-Mcp 98 "ping"
  Write-Result "MCP ping" ($r3.jsonrpc -eq "2.0")
} catch { Write-Result "MCP session handshake" $false $_.Exception.Message }

try {
  $r=Invoke-Mcp 101 "tools/list"
  $toolMap=@{}
  foreach($tool in @($r.result.tools)) { $toolMap[[string]$tool.name]=$tool }
  $readSec=($toolMap["wp.get_site"].securitySchemes | Where-Object { $_.type -eq "oauth2" -and $_.scopes -contains "mcp:read" }).Count -gt 0
  $writeSec=($toolMap["gutenberg.patch"].securitySchemes | Where-Object { $_.type -eq "oauth2" -and $_.scopes -contains "mcp:write" }).Count -gt 0
  Write-Result "MCP tool OAuth securitySchemes" ($readSec -and $writeSec) "read=wp.get_site; write=gutenberg.patch"
  $names=@($r.result.tools|ForEach-Object{$_.name})
  $unique=(@($names|Select-Object -Unique)).Count -eq $names.Count
  $required=@("wordpress.execute","builder.catalog","builder.detect","system.capabilities","operation.get","operation.verify","operation.proof","operation.preview_link","gutenberg.get_blocks","gutenberg.locate_block","elementor.get_widget","woocommerce.get_product")
  $missing=@($required|Where-Object{$names -notcontains $_})
  Write-Result "MCP tools/list" ($unique -and $missing.Count -eq 0 -and $names.Count -ge 35) ("tools=" + $names.Count)
  if($missing.Count -gt 0){Write-Host ("      Missing: " + ($missing -join ", "))}
} catch { Write-Result "MCP tools/list" $false $_.Exception.Message }

try {
  $r=Invoke-Mcp 102 "resources/list"
  $uris=@($r.result.resources|ForEach-Object{$_.uri})
  Write-Result "MCP resources/list" ($uris -contains "okean://capabilities" -and $uris -contains "okean://site/status" -and $uris -contains "okean://operations") ("resources=" + $uris.Count)
} catch { Write-Result "MCP resources/list" $false $_.Exception.Message }

foreach($uri in @("okean://site/status","okean://capabilities","okean://operations")){
  try {
    $r=Invoke-Mcp 103 "resources/read" @{uri=$uri}
    $ok=($r.result.contents.Count -ge 1 -and [string]$r.result.contents[0].text -ne "")
    Write-Result ("MCP resources/read " + $uri) $ok ("bytes=" + ([string]$r.result.contents[0].text).Length)
  } catch { Write-Result ("MCP resources/read " + $uri) $false $_.Exception.Message }
}

try {
  $d=Tool-Json 110 "wp.get_site"
  $expectedHost=([Uri]::new($Site)).Host.ToLowerInvariant()
  $actualHost=([Uri]::new([string]$d.url)).Host.ToLowerInvariant()
  $hostOk=($actualHost -eq $expectedHost -or $actualHost -eq ("www."+$expectedHost) -or $expectedHost -eq ("www."+$actualHost))
  Write-Result "MCP wp.get_site" ($d.name -ne $null -and $d.wp_version -match "^\d+\.\d+" -and $hostOk) ("wp=" + $d.wp_version + "; host=" + $actualHost)
} catch { Write-Result "MCP wp.get_site" $false $_.Exception.Message }

foreach($test in @(
  @{id=111;name="wp.list_plugins";args=@{}},
  @{id=112;name="wp.list_themes";args=@{}},
  @{id=113;name="wp.health";args=@{}},
  @{id=114;name="wp.diagnostics";args=@{}},
  @{id=115;name="wp.execution_readiness";args=@{}},
  @{id=116;name="system.capabilities";args=@{}}
)){
  try {
    $d=Tool-Json $test.id $test.name $test.args
    Write-Result ("MCP " + $test.name) ($null -ne $d)
  } catch { Write-Result ("MCP " + $test.name) $false $_.Exception.Message }
}

try {
  $d=Tool-Json 120 "builder.catalog"
  $ids=@($d.items|ForEach-Object{$_.id})
  Write-Result "MCP builder.catalog" ($ids -contains "elementor" -and $ids -contains "gutenberg" -and $ids -contains "woocommerce") ("adapters=" + $ids.Count)
} catch { Write-Result "MCP builder.catalog" $false $_.Exception.Message }

$pages=@()
try {
  $r=Invoke-Mcp 121 "tools/call" @{name="wordpress.execute";arguments=@{command="post list --post_type=page --post_status=any --number=50";mode="read"}}
  $pages=@($r.result.content[0].text | ConvertFrom-Json)
  Write-Result "MCP WP-CLI post list pages" ($pages.Count -gt 0) ("pages=" + $pages.Count)
} catch { Write-Result "MCP WP-CLI post list pages" $false $_.Exception.Message }

$elementorId=31499
$elementorWidgetId="14a1e75"
try {
  foreach($p in $pages){
    try {
      $d=Tool-Json 122 "builder.detect" @{post_id=[int]$p.id}
      if($d.primary -eq "elementor"){ $elementorId=[int]$p.id; break }
    } catch {}
  }
  $d=Tool-Json 123 "builder.detect" @{post_id=$elementorId}
  Write-Result "MCP builder.detect Elementor" ($d.primary -eq "elementor" -and @($d.matches|ForEach-Object{$_.id}) -contains "elementor") ("post_id=" + $elementorId)
} catch { Write-Result "MCP builder.detect Elementor" $false $_.Exception.Message }

try {
  $d=Tool-Json 124 "elementor.get_structure" @{post_id=$elementorId;limit=50;include_html=$false}
  Write-Result "MCP Elementor get_structure" ($d.id -eq $elementorId -and $d.count -ge 1) ("widgets=" + $d.count)
  if($d.count -gt 0 -and $d.nodes[0].id){
    $elementorWidgetId=[string]$d.nodes[0].id
  }
} catch { Write-Result "MCP Elementor get_structure" $false $_.Exception.Message }

try {
  $d=Tool-Json 125 "elementor.get_widget" @{post_id=$elementorId;widget_id=$elementorWidgetId}
  Write-Result "MCP Elementor get_widget" ($d.widget_id -eq $elementorWidgetId -and $d.widgetType -ne $null)
} catch { Write-Result "MCP Elementor get_widget" $false $_.Exception.Message }

$schemaWidgetType="html"
try {
  $structureProbe=Tool-Json 128 "elementor.get_structure" @{post_id=$elementorId;limit=50;include_html=$false}
  foreach($node in @($structureProbe.nodes)){if([string]$node.widgetType -ne ""){$schemaWidgetType=[string]$node.widgetType;break}}
} catch {}

foreach($test in @(
  @{id=126;name="elementor.get_schema";args=@{widget_type=$schemaWidgetType}},
  @{id=127;name="elementor.get_globals";args=@{}}
)){
  try { $d=Tool-Json $test.id $test.name $test.args; Write-Result ("MCP " + $test.name) ($null -ne $d) }
  catch { Write-Result ("MCP " + $test.name) $false $_.Exception.Message }
}

$gutenbergId=0
foreach($p in $pages){
  try {
    $d=Tool-Json 130 "builder.detect" @{post_id=[int]$p.id}
    if($d.primary -eq "gutenberg"){ $gutenbergId=[int]$p.id; break }
  } catch {}
}
if($gutenbergId -eq 0){
  try {
    $r=Invoke-Mcp 131 "tools/call" @{name="wordpress.execute";arguments=@{command="post list --post_type=post --post_status=any --number=50";mode="read"}}
    $posts=@($r.result.content[0].text | ConvertFrom-Json)
    foreach($p in $posts){
      try {
        $d=Tool-Json 132 "builder.detect" @{post_id=[int]$p.id}
        if($d.primary -eq "gutenberg"){ $gutenbergId=[int]$p.id; break }
      } catch {}
    }
  } catch {}
}
if($gutenbergId -gt 0){
  try {
    $d=Tool-Json 133 "builder.detect" @{post_id=$gutenbergId}
    Write-Result "MCP builder.detect Gutenberg" ($d.primary -eq "gutenberg") ("post_id=" + $gutenbergId)
  } catch { Write-Result "MCP builder.detect Gutenberg" $false $_.Exception.Message }

  try {
    $d=Tool-Json 134 "gutenberg.get_blocks" @{post_id=$gutenbergId}
    Write-Result "MCP Gutenberg get_blocks" ($d.post_id -eq $gutenbergId -and $null -ne $d.blocks) ("mode=" + $d.content_mode)
    $firstBlock=$null
    foreach($b in @($d.blocks)){if([string]$b.blockName -ne ""){$firstBlock=$b;break}}
    if($null -ne $firstBlock){
      try {
        $loc=Tool-Json 135 "gutenberg.locate_block" @{post_id=$gutenbergId;block_name=[string]$firstBlock.blockName}
        Write-Result "MCP Gutenberg locate_block" ($loc.post_id -eq $gutenbergId -and $loc.count -ge 1) ("matches=" + $loc.count)
      } catch { Write-Result "MCP Gutenberg locate_block" $false $_.Exception.Message }
    } else {
      Write-Skip "MCP Gutenberg locate_block" "No native block_name available in selected document"
    }
  } catch { Write-Result "MCP Gutenberg get_blocks" $false $_.Exception.Message }
} else {
  Write-Skip "MCP Gutenberg read suite" "No Gutenberg-owned page/post found"
}

try {
  $r=Invoke-Mcp 140 "tools/call" @{name="wordpress.execute";arguments=@{command="post list --post_type=product --post_status=any --number=1 --format=ids";mode="read"}}
  $productProbe=$r.result.content[0].text | ConvertFrom-Json
  $productId=Get-Scalar-Int $productProbe
  if($productId -gt 0){
    $d=Tool-Json 141 "woocommerce.get_product" @{product_id=$productId}
    Write-Result "MCP WooCommerce get_product" ($d.id -eq $productId) ("product_id=" + $productId)
  } else {
    Write-Skip "MCP WooCommerce get_product" "No product found"
  }
} catch { Write-Result "MCP WooCommerce read suite" $false $_.Exception.Message }

try {
  $d=Tool-Json 150 "wp.list_files" @{scope="child_theme";path=""}
  Write-Result "MCP files list child_theme" ($d.scope -eq "child_theme" -and $null -ne $d.items) ("items=" + @($d.items).Count)
} catch { Write-Result "MCP files list child_theme" $false $_.Exception.Message }

try {
  $d=Tool-Json 151 "wp.search_files" @{scope="child_theme";q="OAI_";limit=5}
  Write-Result "MCP files search child_theme" ($d.scope -eq "child_theme" -and $null -ne $d.items) ("matches=" + @($d.items).Count)
} catch { Write-Result "MCP files search child_theme" $false $_.Exception.Message }

try {
  $d=Tool-Json 160 "wordpress.execute" @{command="core version";mode="read"}
  Write-Result "MCP WP-CLI core version" ($d.version -match '^\d+\.\d+')
} catch { Write-Result "MCP WP-CLI core version" $false $_.Exception.Message }

try {
  $d=Tool-Json 161 "wordpress.execute" @{command="option get oai_mcp_nonexistent_probe";mode="read"}
  Write-Result "MCP WP-CLI option get" ($d.name -eq "oai_mcp_nonexistent_probe")
} catch { Write-Result "MCP WP-CLI option get" $false $_.Exception.Message }

try {
  $d=Tool-Json 162 "wordpress.execute" @{command="option update oai_mcp_runtime_plan_probe 1";mode="plan"}
  Write-Result "MCP planned write remains non-executable" ($d.execution_model -eq "sandbox_then_approval" -and $d.executable_now -eq $false)
} catch { Write-Result "MCP planned write remains non-executable" $false $_.Exception.Message }

try {
  $d=Tool-Json 163 "wordpress.execute" @{command="core version";mode="plan"}
  $propertyNames=@($d.PSObject.Properties|ForEach-Object{$_.Name})
  Write-Result "MCP plan mode routes through plan layer" ($d.execution_model -eq "read_only" -and $d.executable_now -eq $true -and ($propertyNames -notcontains "version"))
} catch { Write-Result "MCP plan mode does not execute read command" $false $_.Exception.Message }

try {
  $d=Tool-Json 165 "wp.list_operations"
  $ops=@($d.items)
  if($ops.Count -gt 0){
    $opId=0
    $og=$null
    foreach($candidate in $ops){
      $parsedId=Get-Scalar-Int $candidate
      if($parsedId -le 0){ continue }
      try {
        $probe=Tool-Json 166 "operation.get" @{id=$parsedId}
        if($probe.id -eq $parsedId){ $opId=$parsedId; $og=$probe; break }
      } catch {}
    }
    if($opId -le 0){throw "Operation ledger returned rows, but none could be resolved by operation.get"}
    Write-Result "MCP operation.get" ($og.id -eq $opId) ("operation_id=" + $opId)
    $ov=Tool-Json 167 "operation.verify" @{id=$opId}
    Write-Result "MCP operation.verify" ($null -ne $ov) ("operation_id=" + $opId)
    $op=Tool-Json 168 "operation.proof" @{id=$opId}
    Write-Result "MCP operation.proof" ($null -ne $op) ("operation_id=" + $opId)
    $or=Tool-Json 169 "operation.get_receipt" @{id=$opId}
    Write-Result "MCP operation.get_receipt" ($null -ne $or) ("operation_id=" + $opId)
  } else {
    Write-Skip "MCP operation ledger" "No operations recorded on site"
  }
} catch { Write-Result "MCP operation ledger" $false $_.Exception.Message }

$guard=Invoke-Mcp-ExpectedError 170 "tools/call" @{name="operation.approve";arguments=@{id=1}} @(-32003)
Write-Result "MCP human approval guard" $guard.ok $guard.detail

$guard=Invoke-Mcp-ExpectedError 171 "tools/call" @{name="operation.apply";arguments=@{id=1}} @(-32003,-32004)
Write-Result "MCP apply approval guard" $guard.ok $guard.detail

$guard=Invoke-Mcp-ExpectedError 172 "tools/call" @{name="operation.rollback";arguments=@{id=1}} @(-32003,-32004)
Write-Result "MCP rollback approval guard" $guard.ok $guard.detail

try {
  $r=Invoke-Mcp-Raw 173 "tools/call" @{name="content.patch";arguments=@{post_id=31499;field="post_content";old_content="__oai_scope_probe__";new_content="__oai_scope_probe__x"}}
  if($null -ne $r.error){
    $code=[int]$r.error.code
    $scopeDenied=([string]$r.error.message -match "OAuth scope required")
    Write-Result "MCP content.patch scope permission" (-not $scopeDenied) ("content.patch reached tool layer; error_code=" + $code)
  } else {
    Write-Result "MCP content.patch scope permission" $false "content.patch unexpectedly returned a result"
  }
} catch {
  $msg=[string]$_.Exception.Message
  $scopeDenied=$msg -match "OAuth scope required"
  $rpcCode=0
  if($msg -match "JSON-RPC\s+(-?\d+)"){$rpcCode=[int]$Matches[1]}
  Write-Result "MCP content.patch scope permission" (-not $scopeDenied) $(if($scopeDenied){"OAuth scope was denied"}else{"content.patch reached tool layer; error_code="+$rpcCode})
}

try {
  $badHeaders=@{Authorization="Bearer invalid_oai_runtime_token"}
  try {
    $r=Invoke-Mcp 174 "initialize" @{} $badHeaders
    Write-Result "MCP invalid token rejection" $false "Request unexpectedly authorized"
  } catch {
    $msg=$_.Exception.Message
    Write-Result "MCP invalid token rejection" ($msg -match "401|Unauthorized")
  }
} catch { Write-Result "MCP invalid token rejection" $false $_.Exception.Message }


# REST lifecycle bypass barrier: the OAuth access token is scoped to the MCP
# resource, not to the legacy /v1 REST permission callback. Therefore a direct
# OAuth call must be rejected before the sandbox action handler is reached.
# This is a safe non-mutating probe against a nonexistent operation id.
# HTTP 401 is the expected authentication barrier; HTTP 403 with the explicit
# human-approval error is also accepted if a future REST auth bridge reaches
# the lifecycle guard.
function Test-RestLifecycleBarrier {
  param([string]$Action,[int]$ProbeId=999999)
  $uri = $base + "/sandbox/$ProbeId/$Action"
  try {
    $r = Invoke-WebRequest -Uri $uri -Method POST -Headers @{Authorization="Bearer "+$accessToken} -ContentType "application/json" -Body "{}" -ErrorAction Stop
    return @{ok=$false;detail=("HTTP "+[int]$r.StatusCode+" unexpectedly returned success")}
  } catch {
    $resp=$_.Exception.Response
    if($null -eq $resp){ return @{ok=$false;detail=$_.Exception.Message} }
    $status=[int]$resp.StatusCode
    $body=""
    try {
      $stream=$resp.GetResponseStream()
      if($null -ne $stream){
        $reader=New-Object System.IO.StreamReader($stream)
        $body=$reader.ReadToEnd()
        $reader.Dispose(); $stream.Dispose()
      }
    } catch {}
    $approvalCode=($body -match 'oai_human_approval_required')
    $authBarrier=($status -eq 401)
    $humanApprovalBarrier=($status -eq 403 -and $approvalCode)
    return @{ok=($authBarrier -or $humanApprovalBarrier);detail=("HTTP "+$status+$(if($body){"; "+$body}else{""}))}
  }
}

foreach($action in @("approve","apply","rollback")){
  $guard=Test-RestLifecycleBarrier $action
  Write-Result ("REST OAuth lifecycle barrier /" + $action) $guard.ok $guard.detail
}

try {
  $oldAccess=$accessToken
  $refresh=Read-HttpJson ($asm.token_endpoint) "POST" @{
    grant_type = "refresh_token"
    refresh_token = $refreshToken
    client_id = $clientId
    resource = $mcp
  }
  $newAccess=[string]$refresh.access_token
  Write-Result "OAuth refresh token rotation" ($newAccess -ne "" -and $newAccess -ne $oldAccess -and [string]$refresh.refresh_token -ne "")
  $headers=@{Authorization="Bearer "+$newAccess}
  $r=Invoke-Mcp 175 "initialize"
  Write-Result "MCP initialize after token refresh" ($r.result.protocolVersion -eq "2025-06-18")
  try {
    $oldHeaders=@{Authorization="Bearer "+$oldAccess}
    $r=Invoke-Mcp 176 "initialize" @{} $oldHeaders
    Write-Result "OAuth old access token revoked after refresh" $false "Old access token still authorized"
  } catch {
    Write-Result "OAuth old access token revoked after refresh" ($_.Exception.Message -match "401|Unauthorized")
  }
} catch { Write-Result "OAuth refresh token rotation" $false $_.Exception.Message }

Write-Host ""
Write-Host ("RESULT  Passed: {0}  Failed: {1}  Skipped: {2}" -f $passed,$failed,$skipped)
if($failed -gt 0){ exit 1 }
