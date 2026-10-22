<?php
declare(strict_types=1);
/** Required Cloud versioned Abilities policy; fails until new release implementation. */
require dirname(__DIR__).'/src/Security.php';
require dirname(__DIR__).'/src/BatchAccounting.php';
require dirname(__DIR__).'/src/QuotaPolicy.php';
require dirname(__DIR__).'/src/Core.php';
use WPAICloudGateway\Core;
foreach (['ability.list','ability.get'] as $tool) {
    if (Core::writes($tool, [])) {
        throw new RuntimeException('Cloud must treat approved Ability metadata as mcp:read: '.$tool);
    }
}
foreach (['ability.run','ability.execute','ability.delete'] as $tool) {
    if (!Core::writes($tool, [])) {
        throw new RuntimeException('Unknown Ability execution must fail closed: '.$tool);
    }
}
echo "PASS Cloud Abilities metadata read/write classification\n";
