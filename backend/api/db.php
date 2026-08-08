<?php
/**
 * bet1x database emulation layer using Node.js Express server APIs backed by PostgreSQL and Prisma.
 * Bridges all users, wallets, deposits, withdrawals, bets, and transaction logs.
 */

require_once __DIR__ . '/config.php';

if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0755, true);
}

// Global API Request Helper
function db_api_request(string $method, string $path, $data = null) {
    $url = 'http://localhost:5000' . $path;
    
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        
        if ($data !== null) {
            $jsonData = json_encode($data);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($jsonData)
            ]);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode >= 200 && $httpCode < 300) {
            return json_decode($response, true);
        }
        return null;
    } else {
        $options = [
            'http' => [
                'method' => $method,
                'header' => "Content-Type: application/json\r\n",
                'content' => $data !== null ? json_encode($data) : '',
                'ignore_errors' => true
            ]
        ];
        $context = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);
        return json_decode($response, true);
    }
}

// Shared lock to perform transaction on a table
function db_transaction(string $table, callable $callback) {
    $lockFile = DATA_DIR . '/' . $table . '.lock';
    $fp = fopen($lockFile, 'w');
    if (!$fp) {
        throw new Exception("Unable to open database lock file: " . $lockFile);
    }
    
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        throw new Exception("Unable to acquire database lock on: " . $table);
    }
    
    try {
        // Read table data from Postgres API
        $data = db_read($table);
        
        // Execute callback to modify array
        $result = $callback($data);
        
        // Synchronize full state back to Postgres database
        db_api_request('POST', '/api/db/' . $table . '/sync', $data);
        
        flock($fp, LOCK_UN);
        fclose($fp);
        
        return $result;
    } catch (Exception $e) {
        flock($fp, LOCK_UN);
        fclose($fp);
        throw $e;
    }
}

// Fetch all entries of a table
function db_read(string $table): array {
    $data = db_api_request('GET', '/api/db/' . $table);
    if (!is_array($data)) {
        return [];
    }
    
    // Backwards compatibility mappings for PHP code
    if ($table === 'withdrawals') {
        foreach ($data as &$item) {
            if (isset($item['id'])) {
                $item['withdrawal_id'] = $item['id'];
            }
            if (isset($item['user'])) {
                $item['username'] = $item['user'];
            }
        }
    }
    return $data;
}

// Log transaction to PostgreSQL
function db_log_transaction(string $username, string $type, float $amount, string $details, string $status) {
    db_api_request('POST', '/api/db/transactions', [
        'user' => $username,
        'type' => $type,
        'amount' => $amount,
        'details' => $details,
        'status' => $status
    ]);
}

// Atomic balance adjustment inside PostgreSQL
function db_adjust_wallet(string $username, float $delta, string $details) {
    $res = db_api_request('POST', '/api/db/users/adjust-balance', [
        'username' => $username,
        'delta' => $delta,
        'details' => $details
    ]);
    if (!$res || isset($res['error'])) {
        return ['error' => $res['error'] ?? 'Connection to database failed.'];
    }
    return ['success' => true, 'new_balance' => $res['new_balance']];
}
