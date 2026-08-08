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
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

switch ($action) {
    case 'signup':
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (empty($username) || empty($email) || empty($password)) {
            echo json_encode(['error' => 'All fields are required.']);
            exit;
        }

        if ($password !== $confirm) {
            echo json_encode(['error' => 'Passwords do not match.']);
            exit;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['error' => 'Invalid email address.']);
            exit;
        }

        if (!preg_match('/^[a-zA-Z0-9_]{3,15}$/', $username)) {
            echo json_encode(['error' => 'Username must be 3-15 alphanumeric characters or underscores.']);
            exit;
        }

        try {
            $success = db_transaction('users', function (&$users) use ($username, $email, $password) {
                foreach ($users as $u) {
                    if (strtolower($u['username']) === strtolower($username)) {
                        return ['error' => 'Username is already taken.'];
                    }
                    if (strtolower($u['email']) === strtolower($email)) {
                        return ['error' => 'Email is already registered.'];
                    }
                }

                $users[] = [
                    'username' => $username,
                    'email' => $email,
                    'password' => password_hash($password, PASSWORD_BCRYPT),
                    'wallet_balance' => STARTING_BALANCE,
                    'created_at' => date('Y-m-d H:i:s')
                ];
                return true;
            });

            if ($success === true) {
                db_log_transaction($username, 'Deposit', STARTING_BALANCE, 'Welcome Bonus Credits', 'Completed');

                $_SESSION['username'] = $username;
                $_SESSION['email'] = $email;

                echo json_encode([
                    'success' => true,
                    'user' => ['username' => $username, 'email' => $email]
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
            echo json_encode(['error' => 'Username and password are required.']);
            exit;
        }

        $users = db_read('users');
        $found = null;
        foreach ($users as $u) {
            if (strtolower($u['username']) === strtolower($username)) {
                $found = $u;
                break;
            }
        }

        if ($found && password_verify($password, $found['password'])) {
            $_SESSION['username'] = $found['username'];
            $_SESSION['email'] = $found['email'];

            echo json_encode([
                'success' => true,
                'user' => ['username' => $found['username'], 'email' => $found['email']]
            ]);
        } else {
            echo json_encode(['error' => 'Incorrect username or password.']);
        }
        break;

    case 'status':
        if (isset($_SESSION['username'])) {
            echo json_encode([
                'logged_in' => true,
                'user' => [
                    'username' => $_SESSION['username'],
                    'email' => $_SESSION['email'] ?? ''
                ]
            ]);
        } else {
            echo json_encode(['logged_in' => false]);
        }
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
