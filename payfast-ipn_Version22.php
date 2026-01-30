<?php
require __DIR__ . '/config.php';

$logFile = __DIR__ . '/payfast-itn.log';

function pf_log(string $msg): void {
    global $logFile;
    @file_put_contents($logFile, "[" . date('c') . "] " . $msg . "\n", FILE_APPEND);
}

pf_log("--- ITN HIT --- IP=" . ($_SERVER['REMOTE_ADDR'] ?? '') . " METHOD=" . ($_SERVER['REQUEST_METHOD'] ?? ''));

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

// Capture raw post exactly as PayFast sent it
$raw = file_get_contents('php://input') ?: '';
pf_log("RAW=" . $raw);
pf_log("POST=" . json_encode($_POST));

$mPaymentId    = trim((string)($_POST['m_payment_id'] ?? ''));
$paymentStatus = strtoupper(trim((string)($_POST['payment_status'] ?? '')));
$pfPaymentId   = trim((string)($_POST['pf_payment_id'] ?? ''));
$amountGross   = isset($_POST['amount_gross']) ? number_format((float)$_POST['amount_gross'], 2, '.', '') : '';

if ($mPaymentId === '') {
    pf_log("ERROR: Missing m_payment_id");
    http_response_code(200);
    echo 'OK';
    exit;
}

/**
 * Validate with PayFast (server-to-server).
 * PayFast expects the original POST body sent back to /eng/query/validate.
 */
$pfHost = 'www.payfast.co.za';
$validateUrl = 'https://' . $pfHost . '/eng/query/validate';

$ch = curl_init($validateUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $raw,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);
$resp = curl_exec($ch);
$err  = curl_error($ch);
$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

pf_log("VALIDATE HTTP={$http} ERR={$err} RESP={$resp}");

$isValid = (!$err && $http === 200 && is_string($resp) && strncmp($resp, 'VALID', 5) === 0);

if (!$isValid) {
    pf_log("ITN INVALID for {$mPaymentId} (no DB update)");
    http_response_code(200);
    echo 'OK';
    exit;
}

// Load quote to confirm it exists
$stmt = $pdo->prepare("SELECT amount, status FROM quotes WHERE quote_number = :q LIMIT 1");
$stmt->execute([':q' => $mPaymentId]);
$quote = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$quote) {
    pf_log("ERROR: Quote not found for m_payment_id={$mPaymentId}");
    http_response_code(200);
    echo 'OK';
    exit;
}

$expectedAmount = number_format((float)($quote['amount'] ?? 0), 2, '.', '');

// Optional safety: if amount is set, ensure it matches before marking paid
$canMarkPaid = true;
if ((float)$expectedAmount > 0 && $amountGross !== '' && $amountGross !== $expectedAmount) {
    pf_log("AMOUNT MISMATCH: expected={$expectedAmount} got={$amountGross} for {$mPaymentId}");
    $canMarkPaid = false;
}

// Update DB
if ($paymentStatus === 'COMPLETE' && $canMarkPaid) {
    $stmt = $pdo->prepare("
        UPDATE quotes
        SET status = 'paid',
            payfast_payment_status = 'COMPLETE',
            payfast_pf_payment_id = NULLIF(:pf_payment_id, ''),
            payfast_amount_gross = COALESCE(NULLIF(:amount_gross, ''), payfast_amount_gross),
            payfast_updated_at = NOW()
        WHERE quote_number = :quote_number
        LIMIT 1
    ");
    $stmt->execute([
        ':quote_number'  => $mPaymentId,
        ':pf_payment_id' => $pfPaymentId,
        ':amount_gross'  => $amountGross,
    ]);
    pf_log("UPDATED: {$mPaymentId} PAID pf_payment_id={$pfPaymentId} amount_gross={$amountGross}");
} elseif ($paymentStatus === 'CANCELLED') {
    $stmt = $pdo->prepare("
        UPDATE quotes
        SET status = 'cancelled',
            payfast_payment_status = 'CANCELLED',
            payfast_pf_payment_id = NULLIF(:pf_payment_id, ''),
            payfast_amount_gross = COALESCE(NULLIF(:amount_gross, ''), payfast_amount_gross),
            payfast_updated_at = NOW()
        WHERE quote_number = :quote_number
        LIMIT 1
    ");
    $stmt->execute([
        ':quote_number'  => $mPaymentId,
        ':pf_payment_id' => $pfPaymentId,
        ':amount_gross'  => $amountGross,
    ]);
    pf_log("UPDATED: {$mPaymentId} CANCELLED");
} else {
    // Record status info even if not paid
    $stmt = $pdo->prepare("
        UPDATE quotes
        SET payfast_payment_status = COALESCE(NULLIF(:pf_status, ''), payfast_payment_status),
            payfast_pf_payment_id = COALESCE(NULLIF(:pf_payment_id, ''), payfast_pf_payment_id),
            payfast_amount_gross = COALESCE(NULLIF(:amount_gross, ''), payfast_amount_gross),
            payfast_updated_at = NOW()
        WHERE quote_number = :quote_number
        LIMIT 1
    ");
    $stmt->execute([
        ':quote_number'  => $mPaymentId,
        ':pf_status'     => $paymentStatus,
        ':pf_payment_id' => $pfPaymentId,
        ':amount_gross'  => $amountGross,
    ]);
    pf_log("UPDATED: {$mPaymentId} stored status={$paymentStatus} (canMarkPaid=" . ($canMarkPaid ? 'yes' : 'no') . ")");
}

http_response_code(200);
echo 'OK';