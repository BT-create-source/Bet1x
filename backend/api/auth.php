<?php
/**
 * User Auth Endpoint - Login, Signup, Session Status, Logout (Unified Backend)
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

// Helper to generate secure token in PHP
function php_generate_token($user) {
    $payload = [
        'id' => $user['id'] ?? 1,
        'username' => $user['username'],
        'email' => $user['email'] ?? '',
        'exp' => time() + (7 * 86400)
    ];
    return base64_encode(json_encode($payload));
}

switch ($action) {
    case 'signup':
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        if (empty($email) && !empty($username)) {
            $email = strtolower($username) . '@bet1x.com';
        }
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? $password;

        if (empty($username) || empty($password)) {
            echo json_encode(['error' => 'Username and password are required.']);
            exit;
        }

        if ($password !== $confirm) {
            echo json_encode(['error' => 'Passwords do not match.']);
            exit;
        }

        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['error' => 'Invalid email address format.']);
            exit;
        }

        if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
            echo json_encode(['error' => 'Username must be 3-20 alphanumeric characters or underscores.']);
            exit;
        }

        if (strlen($password) < 6) {
            echo json_encode(['error' => 'Password must be at least 6 characters.']);
            exit;
        }

        // Try Express API first
        $nodeRes = db_api_request('POST', '/api/auth/signup', [
            'username' => $username,
            'email' => $email,
            'password' => $password,
            'confirm_password' => $confirm,
            'starting_balance' => STARTING_BALANCE
        ]);

        if ($nodeRes && !isset($nodeRes['error'])) {
            $_SESSION['username'] = $username;
            $_SESSION['email'] = $email;
            echo json_encode($nodeRes);
            exit;
        }

        try {
            $createdUser = null;
            $success = db_transaction('users', function (&$users) use ($username, $email, $password, &$createdUser) {
                foreach ($users as $u) {
                    if (strtolower($u['username']) === strtolower($username)) {
                        return ['error' => 'Username is already taken.'];
                    }
                    if (strtolower($u['email']) === strtolower($email)) {
                        return ['error' => 'Email is already registered.'];
                    }
                }

                $createdUser = [
                    'id' => count($users) + 1,
                    'username' => $username,
                    'email' => $email,
                    'password' => password_hash($password, PASSWORD_BCRYPT),
                    'wallet_balance' => STARTING_BALANCE,
                    'created_at' => date('Y-m-d H:i:s')
                ];
                $users[] = $createdUser;
                return true;
            });

            if ($success === true && $createdUser) {
                db_log_transaction($username, 'Deposit', STARTING_BALANCE, 'Welcome Bonus Credits', 'Completed');

                $_SESSION['username'] = $username;
                $_SESSION['email'] = $email;

                echo json_encode([
                    'success' => true,
                    'token' => php_generate_token($createdUser),
                    'user' => [
                        'id' => $createdUser['id'],
                        'username' => $username,
                        'email' => $email,
                        'wallet_balance' => (float)$createdUser['wallet_balance']
                    ]
                ]);
            } else {
                echo json_encode($success);
            }
        } catch (Exception $e) {
            echo json_encode(['error' => 'Database error during registration: ' . $e->getMessage()]);
        }
        break;

    case 'login':
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            echo json_encode(['error' => 'Username/email and password are required.']);
            exit;
        }

        // Try Express API first
        $nodeRes = db_api_request('POST', '/api/auth/login', [
            'username' => $username,
            'password' => $password
        ]);

        if ($nodeRes && !isset($nodeRes['error'])) {
            $_SESSION['username'] = $nodeRes['user']['username'] ?? $username;
            $_SESSION['email'] = $nodeRes['user']['email'] ?? '';
            echo json_encode($nodeRes);
            exit;
        }

        $users = db_read('users');
        $found = null;
        foreach ($users as $u) {
            if (strtolower($u['username']) === strtolower($username) || (isset($u['email']) && strtolower($u['email']) === strtolower($username))) {
                $found = $u;
                break;
            }
        }

        if ($found && password_verify($password, $found['password'])) {
            $_SESSION['username'] = $found['username'];
            $_SESSION['email'] = $found['email'] ?? '';

            echo json_encode([
                'success' => true,
                'token' => php_generate_token($found),
                'user' => [
                    'id' => $found['id'] ?? 1,
                    'username' => $found['username'],
                    'email' => $found['email'] ?? '',
                    'wallet_balance' => (float)($found['wallet_balance'] ?? STARTING_BALANCE)
                ]
            ]);
        } else {
            echo json_encode(['error' => 'Incorrect username or password.']);
        }
        break;

    case 'status':
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $tokenUser = null;
        if (strpos($authHeader, 'Bearer ') === 0) {
            $rawToken = substr($authHeader, 7);
            $tokenData = json_decode(base64_decode($rawToken), true);
            if ($tokenData && isset($tokenData['username']) && (!isset($tokenData['exp']) || $tokenData['exp'] > time())) {
                $tokenUser = $tokenData['username'];
            }
        }

        $activeUser = $tokenUser ?? $_SESSION['username'] ?? $_GET['username'] ?? null;

        if ($activeUser) {
            $users = db_read('users');
            $found = null;
            foreach ($users as $u) {
                if (strtolower($u['username']) === strtolower($activeUser)) {
                    $found = $u;
                    break;
                }
            }
            if ($found) {
                echo json_encode([
                    'logged_in' => true,
                    'user' => [
                        'id' => $found['id'] ?? 1,
                        'username' => $found['username'],
                        'email' => $found['email'] ?? '',
                        'wallet_balance' => (float)($found['wallet_balance'] ?? STARTING_BALANCE)
                    ]
                ]);
                exit;
            }
        }
        echo json_encode(['logged_in' => false]);
        break;

    case 'logout':
        session_unset();
        session_destroy();
        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['error' => 'Invalid auth action.']);
        break;
}
