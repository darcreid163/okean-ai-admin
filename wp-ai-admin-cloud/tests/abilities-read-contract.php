<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/AbilitiesPolicy.php';
require dirname(__DIR__).'/src/Core.php';
use WPAICloudGateway\AbilitiesPolicy;
use WPAICloudGateway\Core;
foreach (['ability.list','ability.get','ability_list','ability_get'] as $name) {
    if (!AbilitiesPolicy::isMetadataRead($name) || AbilitiesPolicy::requiredScope($name)!=='mcp:read' || !AbilitiesPolicy::mayExecute($name))
        throw new RuntimeException('Metadata policy rejected: '.$name);
}
foreach (['ability.run','ability.execute','ability.delete','ability_run','ability_get_extra'] as $name) {
    if (AbilitiesPolicy::isMetadataRead($name) || AbilitiesPolicy::requiredScope($name)!=='mcp:write' || AbilitiesPolicy::mayExecute($name))
        throw new RuntimeException('Non-metadata tool permitted: '.$name);
}
foreach (['ability.list','ability.get','ability_list','ability_get'] as $name) {
    if (Core::writes($name, [])) throw new RuntimeException('Core incorrectly requires write scope: '.$name);
}
foreach (['ability.run','ability.execute','ability.delete'] as $name) {
    if (!Core::writes($name, [])) throw new RuntimeException('Core exposed unsafe Ability execution: '.$name);
}
echo "PASS integrated Cloud Core Abilities scope classification\n";
