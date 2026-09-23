param(
  [string]$Site = "https://okean.market"
)

$ErrorActionPreference = "Stop"
$base = ($Site.TrimEnd("/") + "/wp-json/okean-ai/v1")
$mcp = ($Site.TrimEnd("/") + "/wp-json/okean-ai/mcp")
$port = 43123
$redirect = "http://127.0.0.1:$port/callback"

function Base64Url([byte[]]$Bytes) {
  return [Convert]::ToBase64String($Bytes).TrimEnd('=') -replace '\+','-' -replace '/','_'
}

function Read-HttpJson([string]$Uri,[string]$Method,[hashtable]$Body) {
  $json = $Body | ConvertTo-Json -Depth 20
  return Invoke-RestMethod -Uri $Uri -Method $Method -ContentType "application/json" -Body $json
}

Write-Host "Okean AI Admin OAuth runtime smoke"
Write-Host "Site: $Site"
Write-Host ""

Write-Host "Checking OAuth discovery..."
$prm = Invoke-RestMethod -Uri ($Site.TrimEnd("/") + "/.well-known/oauth-protected-resource") -Method GET
$asm = Invoke-RestMethod -Uri ($Site.TrimEnd("/") + "/.well-known/oauth-authorization-server") -Method GET
Write-Host "PASS  Protected Resource Metadata"
Write-Host "PASS  Authorization Server Metadata"

$verifier = Base64Url (1..32 | ForEach-Object { [byte](Get-Random -Minimum 0 -Maximum 256) })
$challengeBytes = [Security.Cryptography.SHA256]::Create().ComputeHash([Text.Encoding]::UTF8.GetBytes($verifier))
$challenge = Base64Url $challengeBytes
$state = Base64Url (1..24 | ForEach-Object { [byte](Get-Random -Minimum 0 -Maximum 256) })

$client = Read-HttpJson ($base + "/oauth/register") "POST" @{
  client_name = "Okean AI Admin local OAuth smoke"
  redirect_uris = @($redirect)
  grant_types = @("authorization_code","refresh_token")
  response_types = @("code")
  token_endpoint_auth_method = "none"
}
$clientId = [string]$client.client_id
if($clientId -eq "") { throw "OAuth client registration returned no client_id" }
Write-Host ("PASS  Client registration :: " + $clientId)

$authUri = $asm.authorization_endpoint + "?" + (
  @{
    client_id=$clientId
    redirect_uri=$redirect
    response_type="code"
    scope="mcp:read mcp:write"
    state=$state
    code_challenge=$challenge
    code_challenge_method="S256"
    resource=$mcp
  }.GetEnumerator() | ForEach-Object {
    [uri]::EscapeDataString([string]$_.Key) + "=" + [uri]::EscapeDataString([string]$_.Value)
  }
) -join "&"

$listener = New-Object System.Net.HttpListener
$listener.Prefixes.Add("http://127.0.0.1:$port/")
$listener.Start()

Write-Host ""
Write-Host "Открою окно WordPress авторизации."
Write-Host "На сайте нажми «Разрешить», затем вернись сюда."
Start-Process $authUri

$context = $listener.GetContext()
try {
  $query = $context.Request.Url.Query
  $q = [System.Web.HttpUtility]::ParseQueryString($query)
  if($q["state"] -ne $state) { throw "OAuth state mismatch" }
  if($q["error"]) { throw ("OAuth authorization failed: " + $q["error"] + " " + $q["error_description"]) }
  $code = [string]$q["code"]
  if($code -eq "") { throw "Authorization callback returned no code" }
  $html = "<html><body><h2>Okean AI Admin: авторизация получена.</h2><p>Можно вернуться в PowerShell.</p></body></html>"
  $bytes=[Text.Encoding]::UTF8.GetBytes($html)
  $context.Response.ContentType="text/html; charset=utf-8"
  $context.Response.ContentLength64=$bytes.Length
  $context.Response.OutputStream.Write($bytes,0,$bytes.Length)
} finally {
  $context.Response.Close()
  $listener.Stop()
}

$token = Read-HttpJson ($asm.token_endpoint) "POST" @{
  grant_type = "authorization_code"
  code = $code
  client_id = $clientId
  redirect_uri = $redirect
  code_verifier = $verifier
  resource = $mcp
}
if([string]$token.access_token -eq "") { throw "OAuth token endpoint returned no access_token" }
Write-Host "PASS  Authorization code exchange"

$headers=@{Authorization="Bearer "+$token.access_token}
$body=@{jsonrpc="2.0";id=1;method="initialize";params=@{}}|ConvertTo-Json -Depth 20
$mcpResult=Invoke-RestMethod -Uri $mcp -Method POST -Headers $headers -ContentType "application/json" -Body $body
if($mcpResult.result.protocolVersion -ne $null) {
  Write-Host ("PASS  MCP authorized initialize :: protocol=" + $mcpResult.result.protocolVersion)
} else {
  throw "Authorized MCP initialize returned no result"
}

Write-Host ""
Write-Host "RESULT  OAuth end-to-end authorization succeeded"
