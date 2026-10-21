<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$store=fixtureStore($argv[1]); $core=fixtureCore($store,new FakeTransport(),3);
try { $actor=$core->authenticate(getenv('CLOUD_TEST_TOKEN'),(int)$argv[3]); $core->call($actor,$argv[2],'wp_get_site',[],$argv[4],(int)$argv[3]); echo 'success'; }
catch(\WPAICloudGateway\Fault $e) { if (!in_array($e->reason,['monthly_quota_exhausted','request_outcome_pending_reconciliation'],true)) throw $e; echo $e->reason; }
