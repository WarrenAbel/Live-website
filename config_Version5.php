<?php
// ===== DATABASE SETTINGS =====

$dbHost = 'localhost';
$dbName = 'bendcutsend_quotes';
$dbUser = 'Quotes';
$dbPass = 'nhIep49H1@jad#qH';

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    // Do not show full DB error to visitors
    die('Database connection failed.');
}


// ===== PAYFAST SETTINGS =====

// From PayFast → Settings → Developer Settings
$payfastMerchantId  = '33318487';
$payfastMerchantKey = 'b2njgjz5lucww';

// Security Passphrase from the same page
$payfastPassphrase  = 'B3nDC/t,eN-1';

// Live PayFast URL (real payments)
$payfastBaseUrl = 'https://www.payfast.co.za/eng/process';

// For sandbox testing, comment the line above and use this instead:
// $payfastBaseUrl = 'https://sandbox.payfast.co.za/eng/process';