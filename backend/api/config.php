<?php
/**
 * bet1x Global Configuration Settings (Unified Backend)
 */

// Disable error display in response body to prevent JSON corruption
error_reporting(0);
ini_set('display_errors', 0);

// Razorpay Credentials (Test keys by default)
define('RAZORPAY_KEY_ID', 'rzp_test_zG8h2Xb2Z9j2KL');
define('RAZORPAY_KEY_SECRET', 'kY8H3lK9h2jS9lZ8Xm2B9zN3');
define('RAZORPAY_WEBHOOK_SECRET', 'bet1x_secure_webhook_secret_2026');

// Starting balance for new signups
define('STARTING_BALANCE', 1000.00);

// Admin Credentials
define('ADMIN_USER', 'admin');
define('ADMIN_PASS_HASH', password_hash('admin', PASSWORD_BCRYPT)); // Securely hashed default password

// Database JSON directories
define('DATA_DIR', __DIR__ . '/../data');
