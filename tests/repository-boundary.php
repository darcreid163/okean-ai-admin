<?php
/**
 * Native publication gate.
 *
 * Runs on every PR and before any publication. Three groups:
 *   1. repository boundary  — no server-only code, no installable artifacts, no key material;
 *   2. publication contract — required files, GPL headers, attribution bookkeeping;
 *   3. disclosure hygiene   — no absolute local paths, no gateway secret names.
 */
$root = dirname(__DIR__);
$bad = [];
$warn = [];

/* ---------------------------------------------------------- 1. boundary --- */

$denyPrefixes = ['wp-ai-admin-cloud/', 'wp-ai-cloud/', 'legacy/wp-ai-cloud/', 'releases/wp-ai-cloud-'];
$denySuffixes = ['.zip', '.sql', '.sqlite', '.pem', '.p12', '.key', '.env', '.bak'];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $item) {
    if (!$item->isFile()) continue;
    $path = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
    if (str_starts_with($path, '.git/')) continue;
    foreach ($denyPrefixes as $prefix) if (str_starts_with($path, $prefix)) $bad[] = $path.': server-only path';
    foreach ($denySuffixes as $ext) if (str_ends_with(strtolower($path), $ext)) $bad[] = $path.': forbidden artifact';
    if ($item->getSize() > 2000000) continue;
    $s = file_get_contents($item->getPathname());
    if ($s === false) continue;
    if (preg_match('/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/', $s)) $bad[] = $path.': private key material';
    if (preg_match('/(?:ghp|gho|ghu|ghs|ghr)_[A-Za-z0-9]{35,}/', $s)) $bad[] = $path.': probable GitHub credential';
    if (preg_match('/(?:sk_live|rk_live)_[A-Za-z0-9]{12,}/', $s)) $bad[] = $path.': probable payment credential';
    if (preg_match('/(?:github_pat_[A-Za-z0-9_]{20,}|x-access-token:[A-Za-z0-9_-]{20,})/', $s)) $bad[] = $path.': probable access token';
    if (preg_match('/C:\\\\Users\\\\|\/home\/[a-z0-9._-]+\/|\/var\/www\//i', $s)) $bad[] = $path.': absolute local path';
    if (preg_match('/WP_AI_CLOUD_(?:VAULT|SIGNING)_KEY\s*[:=]\s*[\'"]?[A-Za-z0-9+\/=]{16,}/', $s)) $bad[] = $path.': gateway key value';
}

if (is_dir($root.'/releases')) $bad[] = 'releases/: installable artifacts must not be published from this repository';
foreach (['wp-ai-admin-cloud', 'wp-ai-cloud', 'legacy/wp-ai-cloud'] as $dir) {
    if (is_dir($root.'/'.$dir)) $bad[] = $dir.'/: server-only directory present';
}

/* ---------------------------------------------- 2. publication contract --- */

foreach (['LICENSE', 'THIRD-PARTY.md', 'README.md', 'AGENTS.md', 'MIGRATION-STATUS.md', '.gitignore'] as $required) {
    if (!is_file($root.'/'.$required)) $bad[] = $required.': required publication file is missing';
}

$license = is_file($root.'/LICENSE') ? (string) file_get_contents($root.'/LICENSE') : '';
if ($license !== '' && !str_contains($license, 'GNU GENERAL PUBLIC LICENSE')) $bad[] = 'LICENSE: does not contain the GNU GPL text';
if ($license !== '' && !str_contains($license, 'Version 2, June 1991')) $bad[] = 'LICENSE: is not GPL version 2';

$plugin = is_file($root.'/okean-ai-admin.php') ? (string) file_get_contents($root.'/okean-ai-admin.php') : '';
foreach (['License: GPL-2.0-or-later', 'Plugin Name', 'Version:'] as $header) {
    if (!str_contains($plugin, $header)) $bad[] = 'okean-ai-admin.php: missing plugin header "'.$header.'"';
}
if (!preg_match("/define\('OAI_VERSION','[0-9]+\.[0-9]+\.[0-9]+'\)/", $plugin)) $bad[] = 'okean-ai-admin.php: OAI_VERSION is not a semantic version';

$thirdParty = is_file($root.'/THIRD-PARTY.md') ? (string) file_get_contents($root.'/THIRD-PARTY.md') : '';
if ($thirdParty !== '') {
    foreach (['GPL-2.0-or-later', 'WPVibe', 'awesomemotive/wpvibe-ai-mcp'] as $needle) {
        if (!str_contains($thirdParty, $needle)) $bad[] = 'THIRD-PARTY.md: attribution is missing "'.$needle.'"';
    }
    if (!str_contains($thirdParty, 'Андриянов')) $bad[] = 'THIRD-PARTY.md: copyright holder is not recorded';
}

$agents = is_file($root.'/AGENTS.md') ? (string) file_get_contents($root.'/AGENTS.md') : '';
if ($agents !== '' && !str_contains($agents, 'Approval Engine')) $warn[] = 'AGENTS.md: does not mention the Approval Engine boundary';

$status = is_file($root.'/MIGRATION-STATUS.md') ? (string) file_get_contents($root.'/MIGRATION-STATUS.md') : '';
if ($status !== '' && !str_contains($status, 'PUBLICATION BLOCKED') && !str_contains($status, 'PUBLISHED')) {
    $bad[] = 'MIGRATION-STATUS.md: must state either PUBLICATION BLOCKED or PUBLISHED explicitly';
}

/* ---------------------------------------------------------------- output --- */

if ($warn) fwrite(STDERR, "WARNINGS\n".implode("\n", $warn)."\n");
if ($bad) { fwrite(STDERR, "FAILURES\n".implode("\n", $bad)."\n"); exit(1); }
echo 'PASS: boundary, publication contract and disclosure hygiene ('.count($warn).' warning(s))'.PHP_EOL;
