<?php
declare(strict_types=1);
namespace WPAICloudGateway;

/** Metadata-only Abilities policy integrated in the 0.3.13-dev candidate.
 * Discovery does not grant execution of an arbitrary Ability.
 */
final class AbilitiesPolicy {
    public const READ_TOOLS = ['ability_list','ability_get'];
    public static function normalize(string $name): string {
        return str_replace('.', '_', $name);
    }
    public static function isMetadataRead(string $name): bool {
        return in_array(self::normalize($name),self::READ_TOOLS,true);
    }
    public static function requiredScope(string $name): string {
        return self::isMetadataRead($name)?'mcp:read':'mcp:write';
    }
    public static function mayExecute(string $name): bool {
        // Discovery doesn't imply permission to execute an arbitrary Ability.
        return self::isMetadataRead($name);
    }
}
