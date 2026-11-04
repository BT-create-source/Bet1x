<?php
// CLI-only token for local browser verification. Never accepts a remote account or password.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/php-backend/config.php';
if (cfg('IS_PRODUCTION')) exit(1);
require dirname(__DIR__) . '/php-backend/lib/auth.php';
echo issue_token(['id' => 0, 'username' => 'admin', 'role' => 'admin']);
