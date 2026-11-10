<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../lib/sms.php';
$cases = [
    [['status_code'=>996,'message'=>'Before using OTP SMS API, complete KYC.'], 'kyc_required'],
    [['status_code'=>996,'message'=>'Spamming detected'], 'repeat_blocked'],
    [['status_code'=>997,'message'=>['Complete KYC first']], 'kyc_required'],
    [['status_code'=>409,'message'=>'Invalid route authentication'], 'route_not_enabled'],
    [['status_code'=>412,'message'=>'Invalid Authentication, Check Authorization Key'], 'credentials_rejected'],
    [['status_code'=>414,'message'=>'IP is blacklisted from Dev API'], 'ip_restricted'],
    [['status_code'=>416,'message'=>'Insufficient wallet balance'], 'balance_or_activation'],
    [['status_code'=>998,'message'=>'Use DLT or Quick SMS route'], 'route_not_enabled'],
    [['status_code'=>'secret-key-123456','message'=>'secret-key-123456 OTP 654321'], 'unknown'],
    [null, 'unknown']
];
foreach ($cases as [$input,$expected]) {
    $out = sms_provider_error($input);
    if ($out['reason'] !== $expected) throw new RuntimeException('Wrong SMS rejection classification');
    if (strpos(json_encode($out), 'secret-key-123456') !== false || strpos(json_encode($out), '654321') !== false) throw new RuntimeException('Provider secrets escaped');
}
echo 'PASS ' . count($cases) . " SMS rejection classifications and secret redaction checks\n";
