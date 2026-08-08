<?php
/**
 * bet1x database emulation layer using secure flock JSON files.
 * Handles users, wallets, deposits, withdrawals, bets, transactions, and payment logs.
 */

require_once __DIR__ . '/config.php';

if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0755, true);
}

// Function to perform thread-safe operations on a table with exclusive locking
function db_transaction(string $table, callable $callback) {
    $filePath = DATA_DIR . '/' . $table . '.json';
    
    // Open file for read and write (creates if not exists)
    $fp = fopen($filePath, 'c+');
    if (!$fp) {
        throw new Exception("Unable to open database file: " . $table);
    }
    
    // Acquire exclusive lock (equivalent to DB transaction isolation)
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        throw new Exception("Unable to acquire exclusive database lock on: " . $table);
    }
    
    try {
        // Read file contents
        $size = filesize($filePath);
        $data = [];
        if ($size > 0) {
            rewind($fp);
            $content = fread($fp, $size);
            $data = json_decode($content, true);
            if (!is_array($data)) {
                $data = [];
            }
        }
        
        // Execute caller-supplied callback which modifies the array data
        $result = $callback($data);
        
        // Truncate and write modified data back
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
        fflush($fp);
        
        // Release lock
        flock($fp, LOCK_UN);
        fclose($fp);
        
        return $result;
    } catch (Exception $e) {
        // Release lock and clean up in case of failure
        flock($fp, LOCK_UN);
        fclose($fp);
        throw $e;
    }
}

// Query helper to fetch data from a table without locking (read-only operations)
function db_read(string $table): array {
    $filePath = DATA_DIR . '/' . $table . '.json';
    if (!file_exists($filePath)) {
        return [];
    }
    
    $fp = fopen($filePath, 'r');
    if (!$fp) {
        return [];
    }
    
    // Shared lock for reading
    if (flock($fp, LOCK_SH)) {
        $size = filesize($filePath);
        $data = [];
        if ($size > 0) {
            $content = fread($fp, $size);
            $data = json_decode($content, true);
        }
        flock($fp, LOCK_UN);
        fclose($fp);
        return is_array($data) ? $data : [];
    }
    
    fclose($fp);
    return [];
}

// Helper to log a user transaction record
function db_log_transaction(string $username, string $type, float $amount, string $details, string $status) {
    db_transaction('transactions', function (&$txns) use ($username, $type, $amount, $details, $status) {
        $txns[] = [
            'id' => strtoupper(substr($type, 0, 3)) . '_' . mt_rand(100000, 999999),
            'user' => $username,
            'type' => $type,
            'amount' => $amount,
            'details' => $details,
            'status' => $status,
            'timestamp' => date('Y-m-d H:i:s')
        ];
    });
}

// Safe wrapper to adjust user wallet balance with transactions support
function db_adjust_wallet(string $username, float $delta, string $details) {
    return db_transaction('users', function (&$users) use ($username, $delta, $details) {
        foreach ($users as &$u) {
            if (strtolower($u['username']) === strtolower($username)) {
                $currentBal = (float)$u['wallet_balance'];
                $newBal = $currentBal + $delta;
                if ($newBal < 0) {
                    return ['error' => 'Insufficient balance.'];
                }
                $u['wallet_balance'] = $newBal;
                
                // Determine transaction type
                $type = ($delta >= 0) ? 'Deposit' : 'Withdrawal';
                $absDelta = abs($delta);
                
                // Write transaction log
                db_log_transaction($username, $type, $absDelta, $details, 'Completed');
                
                return ['success' => true, 'new_balance' => $newBal];
            }
        }
        return ['error' => 'User not found.'];
    });
}
