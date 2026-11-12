<?php
/**
 * Disposable WordPress: plugin header and readme metadata completeness (issue #144).
 *
 * Validates that okean-ai-admin.php declares the minimum headers required for
 * WP.org publication and that README.txt has a valid WP.org-style structure.
 * No WordPress bootstrap required — runs against the source files directly.
 */
if (getenv('OAI_DISPOSABLE_WP_TEST') !== '1') throw new RuntimeException('Disposable WP test guard missing');

final class PluginMetaChecks { public static int $count = 0; }
function pmAssert(bool $ok, string $msg): void {
    ++PluginMetaChecks::$count;
    if (!$ok) throw new RuntimeException($msg);
}

$pluginFile = ABSPATH . 'wp-content/plugins/okean-ai-admin/okean-ai-admin.php';
pmAssert(file_exists($pluginFile), 'Plugin file not found at ' . $pluginFile);
$header = file_get_contents($pluginFile, false, null, 0, 2048);

// Required WP.org plugin header fields.
pmAssert((bool)preg_match('/Plugin Name\s*:/i',       $header), 'Plugin header: missing Plugin Name');
pmAssert((bool)preg_match('/Version\s*:\s*\d/i',      $header), 'Plugin header: missing Version');
pmAssert((bool)preg_match('/Requires at least\s*:/i', $header), 'Plugin header: missing Requires at least (issue #144)');
pmAssert((bool)preg_match('/Requires PHP\s*:/i',      $header), 'Plugin header: missing Requires PHP (issue #144)');
pmAssert((bool)preg_match('/Tested up to\s*:/i',      $header), 'Plugin header: missing Tested up to (issue #144)');
pmAssert((bool)preg_match('/WC requires at least\s*:/i', $header), 'Plugin header: missing WC requires at least (issue #144)');
pmAssert((bool)preg_match('/License\s*:/i',           $header), 'Plugin header: missing License');

// Minimum version sanity: Requires PHP must be >= 8.2.
preg_match('/Requires PHP\s*:\s*([\d.]+)/i', $header, $m);
pmAssert(isset($m[1]) && version_compare($m[1], '8.2', '>='), 'Plugin header: Requires PHP must be >= 8.2, got ' . ($m[1] ?? 'none'));

// README.txt must exist and have WP.org structure.
$readmeFile = ABSPATH . 'wp-content/plugins/okean-ai-admin/README.txt';
// Fallback: source tree location when the runner copies tests/ but not the plugin root.
if (!file_exists($readmeFile)) {
    $readmeFile = dirname($pluginFile) . '/README.txt';
}
pmAssert(file_exists($readmeFile), 'README.txt not found alongside plugin file');
$readme = file_get_contents($readmeFile);

pmAssert((bool)preg_match('/^===\s+\S/m',              $readme), 'README.txt: missing === Plugin Name === header');
pmAssert((bool)preg_match('/Requires at least\s*:/i',  $readme), 'README.txt: missing Requires at least (issue #144)');
pmAssert((bool)preg_match('/Tested up to\s*:/i',       $readme), 'README.txt: missing Tested up to (issue #144)');
pmAssert((bool)preg_match('/Requires PHP\s*:/i',       $readme), 'README.txt: missing Requires PHP (issue #144)');
pmAssert((bool)preg_match('/Stable tag\s*:/i',         $readme), 'README.txt: missing Stable tag');
pmAssert((bool)preg_match('/== Description ==/i',      $readme), 'README.txt: missing == Description == section');
pmAssert((bool)preg_match('/== Installation ==/i',     $readme), 'README.txt: missing == Installation == section');
pmAssert((bool)preg_match('/== Changelog ==/i',        $readme), 'README.txt: missing == Changelog == section');
pmAssert((bool)preg_match('/== Frequently Asked Questions ==/i', $readme), 'README.txt: missing == FAQ == section');

// Stable tag must match the plugin Version.
preg_match('/^\s*\*?\s*Version\s*:\s*([\d.]+)/im', $header, $verMatch);
preg_match('/^\s*Stable tag\s*:\s*([\d.]+)/im', $readme, $stableMatch);
pmAssert(
    isset($verMatch[1], $stableMatch[1]) && $verMatch[1] === $stableMatch[1],
    sprintf('Version mismatch: plugin Version=%s, README.txt Stable tag=%s', $verMatch[1] ?? '?', $stableMatch[1] ?? '?')
);

// Changelog must mention the current version.
pmAssert(
    str_contains($readme, '= ' . ($verMatch[1] ?? 'MISSING') . ' ='),
    'README.txt: Changelog does not contain an entry for version ' . ($verMatch[1] ?? 'MISSING')
);

echo 'PASS Plugin metadata and README.txt completeness: ' . PluginMetaChecks::$count . " checks; plugin headers complete, README.txt WP.org-valid\n";
