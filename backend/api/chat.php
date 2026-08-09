<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    // Read raw body if JSON, otherwise $_POST
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (!$input) {
        $input = $_POST;
    }
    $response = db_api_request('POST', '/api/chat', $input);
    echo json_encode($response);
} else {
    $response = db_api_request('GET', '/api/chat');
    echo json_encode($response);
}
