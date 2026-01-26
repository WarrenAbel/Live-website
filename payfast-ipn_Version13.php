<?php
require __DIR__ . '/config.php';

$logFile = __DIR__ . '/payfast-itn.log';

function itn_log(string $msg): void {
    global $logFile;
    @file_put_contents($logFile, '[' . date('c') . '] ' . $msg . PHP_EOL, FILE_APPEND);
}

// Always log the hit as the very first thing (even if later code dies)
itn_log('HIT method=' . ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN') . ' ip=' . ($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'));

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    itn_log('NON-POST');
    exit('Method Not Allowed');
}

$data = $_POST;
itn_log('RAW ' . json_encode($data));

$sigRecv = $data['signature'] ?? '';
$mPaymentId = $data['m_payment_id'] ?? '';

/**
 * Build PayFast signature string:
 * - exclude signature
 * - exclude merchant_key (do NOT sign it)
 * - sort keys
 * - skip empty values
 * - urlencode values
 * - append passphrase if set
 */
function pf_signature_base(array $data, string $passphrase = ''): string {
    unset($data['signature']);
    unset($data['merchant_key']); // IMPORTANT: do not include in ITN signature base

    ksort($data);

    $pairs = [];
    foreach ($data as $k => $v) {
        $v = (string)$v;
        if ($v === '') continue;           // IMPORTANT: skip empty values
        $pairs[] = $k . '=' . urlencode($v);
    }

    $str = implode('&', $pairs);

    if ($passphrase !== '') {
        $str .= '&passphrase=' . urlencode($passphrase);
    }

    return $str;
}

try {
    // 1) Signature check
    $base = pf_signature_base($data, (string)$payfastPassphrase);
    $sigCalc = md5($base);

    if ($sigRecv === '' || strcasecmp($sigRecv, $sigCalc) !== 0) {
        itn_log("SIGNATURE_FAIL m_payment_id={$mPaymentId} recv={$sigRecv} calc={$sigCalc} base={$base}");
        http_response_code(400);
        exit('Invalid signature');
    }
    itn_log("SIGNATURE_OK m_payment_id={$mPaymentId}");

    // 2) PayFast validate call (recommended)
    $validateUrl = 'https://www.payfast.co.za/eng/query/validate';
    $body = http_build_query($data);

    $ch = curl_init($validateUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        itn_log("VALIDATE_ERROR curl={$err} http={$code}");
        http_response_code(500);
        exit('Validate error');
    }

    $respNorm = strtoupper(trim((string)$resp));
    itn_log("VALIDATE http={$code} body={$respNorm}");

    if ($code !== 200 || $respNorm !== 'VALID') {
        http_response_code(400);
        exit('Validate failed');
    }

    // 3) Update quote (adjust column names if different)
    $paymentStatus = $data['payment_status'] ?? '';
    $pfPaymentId = $data['pf_payment_id'] ?? null;
    $amountGross = $data['amount_gross'] ?? null;

    $stmt = $pdo->prepare("SELECT id, quote_number, status FROM quotes WHERE quote_number = :q LIMIT 1");
    $stmt->execute([':q' => $mPaymentId]);
    $quote = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$quote) {
        itn_log("QUOTE_NOT_FOUND m_payment_id={$mPaymentId}");
        http_response_code(404);
        exit('Quote not found');
    }

    $internal = $quote['status'];
    if ($paymentStatus === 'COMPLETE') $internal = 'paid';
    elseif ($paymentStatus === 'CANCELLED') $internal = 'cancelled';
    elseif ($paymentStatus === 'FAILED') $internal = 'failed';

    // If you don't have these columns, remove them (or tell me your schema)
    $stmt = $pdo->prepare("
        UPDATE quotes
        SET status = :status,
            payfast_payment_status = :pf_status,
            payfast_pf_payment_id = :pf_id,
            payfast_amount_gross = :gross,
            payfast_updated_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([
        ':status' => $internal,
        ':pf_status' => $paymentStatus ?: null,
        ':pf_id' => $pfPaymentId,
        ':gross' => $amountGross,
        ':id' => (int)$quote['id'],
    ]);

    itn_log("DB_UPDATED quote={$mPaymentId} status={$internal} pf_status={$paymentStatus}");

    // Respond 200 so PayFast stops retrying
    http_response_code(200);
    echo 'OK';
} catch (Throwable $e) {
    itn_log('ERROR ' . $e->getMessage());
    http_response_code(500);
    echo 'Server error';
}