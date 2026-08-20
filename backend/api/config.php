<?php
/**
 * bet1x Global Configuration Settings (Unified Backend)
 */

// Disable error display in response body to prevent JSON corruption
error_reporting(0);
ini_set('display_errors', 0);

// Payment gateway credentials come from the environment. They used to be hardcoded here, in a
// file that the static server happily handed to anyone who requested /api/config.php.
// Those literal values are in this repository's git history and must be treated as compromised.
define('RAZORPAY_KEY_ID', getenv('RAZORPAY_KEY_ID') ?: '');
define('RAZORPAY_KEY_SECRET', getenv('RAZORPAY_KEY_SECRET') ?: '');
define('RAZORPAY_WEBHOOK_SECRET', getenv('RAZORPAY_WEBHOOK_SECRET') ?: '');

// Starting balance for new signups
define('STARTING_BALANCE', 2000.00);

// Admin credentials. The previous line hashed the literal password "admin" at runtime, so the
// hash looked secure while the password was public.
define('ADMIN_USER', getenv('ADMIN_USERNAME') ?: 'admin');
define('ADMIN_PASS_HASH', getenv('ADMIN_PASSWORD_HASH') ?: '');

// Database JSON directories
define('DATA_DIR', __DIR__ . '/../data');
