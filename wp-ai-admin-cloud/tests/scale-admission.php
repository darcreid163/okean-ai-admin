<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/scale/AdmissionPolicy.php';

use WPAICloudGateway\Scale\AdmissionPolicy;

function expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$p = new AdmissionPolicy(3, 2, 2);
expect($p->tryAcquire('owner-a','site-a'), 'first admission');
expect($p->tryAcquire('owner-a','site-a'), 'second admission');
expect(!$p->tryAcquire('owner-a','site-a'), 'owner or site ceiling');
expect($p->tryAcquire('owner-b','site-b'), 'other owner');
expect(!$p->tryAcquire('owner-c','site-c'), 'global ceiling');
expect($p->active('owner-a','site-a') === ['global'=>3,'owner'=>2,'site'=>2], 'snapshot');
$p->release('owner-a','site-a');
expect($p->tryAcquire('owner-c','site-c'), 'slot reuse');
expect(!$p->tryAcquire('owner-a','site-a'), 'global still full');
$p->release('owner-c','site-c');
$p->release('owner-b','site-b');
$p->release('owner-a','site-a');
expect($p->active('owner-a','site-a')['global'] === 0, 'all released');

$unmatched = false;
try { $p->release('owner-a','site-a'); } catch (LogicException $e) { $unmatched = true; }
expect($unmatched, 'unmatched release rejected');
$invalid = false;
try { $p->tryAcquire('', 'site-a'); } catch (InvalidArgumentException $e) { $invalid = true; }
expect($invalid, 'invalid identity rejected');

$site = new AdmissionPolicy(5, 4, 1);
expect($site->tryAcquire('a','shared-site'), 'site first');
expect(!$site->tryAcquire('b','shared-site'), 'site ceiling across owners');
expect($site->tryAcquire('b','other-site'), 'distinct site available');

echo "PASS isolated admission policy prototype (non-distributed)\n";
