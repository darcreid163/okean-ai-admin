<?php
/**
 * WPVibe-inspired Elementor schema/save integration boundary.
 *
 * This is an original implementation using the existing OAI_Elementor API.
 * No WPVibe source has been copied into this file.
 * Stage 1 is deliberately NOT registered as an MCP or REST write route.
 */
if (!defined('ABSPATH')) exit;

final class OAI_Elementor_Schema_Save {
    public static function schema($widget_type) {
        if (!class_exists('OAI_Elementor')) {
            return new WP_Error('oai_elementor_missing', 'Elementor adapter is unavailable', array('status'=>503));
        }
        return OAI_Elementor::widget_schema($widget_type);
    }

    /** Read-only snapshot for a future approved replace; avoids lossy serialization. */
    public static function snapshot($post_id) {
        $id = absint($post_id);
        if (!$id) return new WP_Error('oai_invalid_post', 'A post ID is required', array('status'=>400));
        $tree = OAI_Elementor::get_data($id);
        if (is_wp_error($tree)) return $tree;
        return array('post_id'=>$id, 'tree'=>$tree,
            'sha256'=>hash('sha256', wp_json_encode($tree)));
    }

    public static function validate_replace($before, $after, $expected_sha256) {
        if (!is_array($before) || !is_array($after) || !array_is_list($after)) {
            return new WP_Error('oai_invalid_tree', 'A list of Elementor nodes is required', array('status'=>422));
        }
        $hash = hash('sha256', wp_json_encode($before));
        if (!is_string($expected_sha256) ||
            !preg_match('/^[a-f0-9]{64}$/D', $expected_sha256) ||
            !hash_equals($hash, $expected_sha256)) {
            return new WP_Error('oai_snapshot_mismatch', 'Snapshot hash mismatch', array('status'=>409));
        }
        $decoded = OAI_Elementor::decode_create_nodes($after);
        if (is_wp_error($decoded)) return $decoded;
        if ($decoded === $before) {
            return new WP_Error('oai_noop', 'No content change', array('status'=>409));
        }
        return array('before'=>$before, 'after'=>$decoded, 'before_sha256'=>$hash,
            'after_sha256'=>hash('sha256', wp_json_encode($decoded)));
    }

    /**
     * Apply only after a separate authority has validated a human-approved operation.
     * The caller MUST supply approved=true from the existing Approval Engine.
     * This method does not grant approvals or expose any write endpoints.
     */
    public static function apply_approved($post_id, $change, $approved=false) {
        if ($approved !== true) {
            return new WP_Error('oai_approval_required', 'Human approval is required', array('status'=>403));
        }
        if (!is_array($change) || !isset($change['before'], $change['after'], $change['before_sha256'])) {
            return new WP_Error('oai_invalid_change', 'Missing prepared change', array('status'=>400));
        }
        $snapshot = self::snapshot($post_id);
        if (is_wp_error($snapshot)) return $snapshot;
        if (!hash_equals($snapshot['sha256'], (string)$change['before_sha256']) ||
            $snapshot['tree'] !== $change['before']) {
            return new WP_Error('oai_stale_target', 'Elementor document changed before apply', array('status'=>409));
        }
        return OAI_Elementor::save_document(absint($post_id), $change['after']);
    }

    /** Full comparison, not only the existence of valid Elementor JSON. */
    public static function verify($post_id, $expected_tree) {
        $snapshot = self::snapshot($post_id);
        if (is_wp_error($snapshot)) return $snapshot;
        $match = is_array($expected_tree) && $snapshot['tree'] === $expected_tree;
        return array('ok'=>$match, 'post_id'=>$snapshot['post_id'],
            'actual_sha256'=>$snapshot['sha256'],
            'expected_sha256'=>is_array($expected_tree) ? hash('sha256', wp_json_encode($expected_tree)) : null);
    }

    /** Rollback requires a separately approved rollback and no intervening edits. */
    public static function rollback_approved($post_id, $change, $approved=false) {
        if ($approved !== true) {
            return new WP_Error('oai_approval_required', 'Rollback approval required', array('status'=>403));
        }
        if (!is_array($change) || !isset($change['before'], $change['after'])) {
            return new WP_Error('oai_invalid_change', 'Missing rollback snapshot', array('status'=>400));
        }
        $snapshot = self::snapshot($post_id);
        if (is_wp_error($snapshot)) return $snapshot;
        if ($snapshot['tree'] !== $change['after']) {
            return new WP_Error('oai_rollback_conflict', 'Target changed since apply', array('status'=>409));
        }
        return OAI_Elementor::save_document(absint($post_id), $change['before']);
    }
}
