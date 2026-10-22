<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/AbilitiesPolicy.php';
use WPAICloudGateway\AbilitiesPolicy;
foreach (['ability.list','ability.get','ability_list','ability_get'] as $name) {
    if (!AbilitiesPolicy::isMetadataRead($name) || AbilitiesPolicy::requiredScope($name)!=='mcp:read' || !AbilitiesPolicy::mayExecute($name))
        throw new RuntimeException('Metadata policy rejected: '.$name);
}
foreach (['ability.run','ability.execute','ability.delete','ability_run','ability_get_extra'] as $name) {
    if (AbilitiesPolicy::isMetadataRead($name) || AbilitiesPolicy::requiredScope($name)!=='mcp:write' || AbilitiesPolicy::mayExecute($name))
        throw new RuntimeException('Non-metadata tool permitted: '.$name);
}
echo "PASS isolated Cloud Abilities policy (not integrated into Core)\n";
