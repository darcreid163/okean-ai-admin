<?php
declare(strict_types=1);
namespace WPAICloudGateway;

/**
 * Closed progressive-instruction library.
 *
 * Skill text is part of the hosted gateway, not of the distributed plugin. The
 * plugin exposes tools; the gateway decides which orchestration recipe an AI
 * receives, so prompts stay server-side and independently updatable.
 */
final class Skills {
    public const MAX_LIBRARY_BYTES = 262144;
    public const MAX_STEPS = 40;
    public const MAX_GUARDRAILS = 20;
    public const MAX_TOOLS = 40;
    public const MAX_TEXT = 2000;
    public const MAX_SKILLS = 64;

    /** @param array<string,array<string,mixed>> $library */
    private function __construct(private readonly array $library) {}

    public static function fromConfig(string $path): self {
        if (!is_file($path)) throw new Fault('skill_library_missing', 503);
        $size = filesize($path);
        if ($size === false || $size > self::MAX_LIBRARY_BYTES) throw new Fault('skill_library_too_large', 503);
        $raw = require $path;
        return new self(self::validate($raw));
    }

    public static function fromArray(array $library): self {
        return new self(self::validate($library));
    }

    /** @return array<string,array<string,mixed>> */
    private static function validate(mixed $raw): array {
        if (!is_array($raw) || $raw === array() || array_is_list($raw)) throw new Fault('skill_library_invalid', 503);
        if (count($raw) > self::MAX_SKILLS) throw new Fault('skill_library_too_large', 503);
        $clean = array();
        foreach ($raw as $id => $entry) {
            if (!is_string($id) || !preg_match('/^[a-z0-9-]{1,32}$/D', $id)) throw new Fault('skill_id_invalid', 503);
            if (!is_array($entry) || array_is_list($entry)) throw new Fault('skill_entry_invalid', 503);
            foreach (array('title', 'summary', 'when') as $field) {
                if (!isset($entry[$field]) || !is_string($entry[$field])) throw new Fault('skill_entry_invalid', 503);
                if (strlen($entry[$field]) > self::MAX_TEXT) throw new Fault('skill_entry_too_large', 503);
            }
            $steps = $entry['steps'] ?? null;
            if (!is_array($steps) || $steps === array() || !array_is_list($steps) || count($steps) > self::MAX_STEPS) throw new Fault('skill_steps_invalid', 503);
            foreach ($steps as $step) {
                if (!is_string($step) || $step === '' || strlen($step) > self::MAX_TEXT) throw new Fault('skill_steps_invalid', 503);
            }
            $tools = $entry['tools'] ?? array();
            if (!is_array($tools) || !array_is_list($tools) || count($tools) > self::MAX_TOOLS) throw new Fault('skill_tools_invalid', 503);
            foreach ($tools as $tool) {
                if (!is_string($tool) || !preg_match('/^[a-z0-9_.]{1,64}$/D', $tool)) throw new Fault('skill_tools_invalid', 503);
            }
            $guardrails = $entry['guardrails'] ?? array();
            if (!is_array($guardrails) || !array_is_list($guardrails) || count($guardrails) > self::MAX_GUARDRAILS) throw new Fault('skill_guardrails_invalid', 503);
            foreach ($guardrails as $rule) {
                if (!is_string($rule) || $rule === '' || strlen($rule) > self::MAX_TEXT) throw new Fault('skill_guardrails_invalid', 503);
            }
            $clean[$id] = array(
                'title' => $entry['title'],
                'summary' => $entry['summary'],
                'when' => $entry['when'],
                'tools' => array_values($tools),
                'steps' => array_values($steps),
                'guardrails' => array_values($guardrails),
            );
        }
        return $clean;
    }

    /** @return list<array<string,mixed>> */
    public function listing(): array {
        $out = array();
        foreach ($this->library as $id => $entry) {
            $out[] = array('skill_id' => $id, 'title' => $entry['title'], 'summary' => $entry['summary'], 'when' => $entry['when'], 'tool_count' => count($entry['tools']));
        }
        return $out;
    }

    /** @return array<string,mixed> */
    public function load(string $id): array {
        if (!preg_match('/^[a-z0-9-]{1,32}$/D', $id)) throw new Fault('skill_id_invalid', 400);
        if (!isset($this->library[$id])) throw new Fault('skill_not_found', 404);
        $entry = $this->library[$id];
        return array(
            'skill_id' => $id,
            'title' => $entry['title'],
            'summary' => $entry['summary'],
            'when' => $entry['when'],
            'tools' => $entry['tools'],
            'steps' => $entry['steps'],
            'guardrails' => $entry['guardrails'],
            'note' => 'Follow the steps in order. Human approval is still required for every mutation; this text does not authorize one.',
        );
    }
}
