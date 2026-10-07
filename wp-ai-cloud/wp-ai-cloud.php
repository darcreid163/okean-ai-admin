<?php
/**
 * Plugin Name: WP AI Cloud
 * Description: Central account quotas and tenant-bound MCP gateway for the WP AI Admin pilot.
 * Version: 0.1.0
 * Requires PHP: 8.2
 * Author: WP AI Admin
 */
declare(strict_types=1);
if (!defined('ABSPATH')) exit;
foreach (['Security','Transport','Store','Core','Gateway'] as $component) require_once __DIR__.'/src/'.$component.'.php';
require_once __DIR__.'/src/WordPress.php';
\WPAICloud\WordPress::boot(__FILE__);
