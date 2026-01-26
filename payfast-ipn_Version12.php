<?php
require __DIR__ . '/config.php';

$logFile = __DIR__ . '/payfast-itn.log';

function itn_log(string $msg): void {
    global $logFile;
    @file_put_contents($logFile, '[' . date('c') . '] ' . $msg . PHP_EOL, FILE_APPEND);
}

function pf_build_signature_string(array $data, string $passphrase = ''): string {
    // Remove signature
    if (isset($data['signature'])) unset($data['signature']);

    // Sort keys
    ksort($data);

    // Build param string: SKIP empty values (this is the key fix)
    $pairs = [];
    foreach ($data as $key => $val) {
        $val = (string)$val;
        if ($val === '') continue; // <<< DO NOT include empty fields
        $pairs[] = $key . '=' . urlencode($val);
    }

    $paramString = implode('&', $pairs);

    // Append passphrase if used
    if ($passphrase !== '') {
        $paramString .= '&passphrase=' . urlencode($passphrase);
    }

    return $paramString;
}

function pf_remote_post(string $url, string $body, int $timeoutSeconds = 15): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Connection: Close',
        ],
    ]);

    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$code, $resp, $err];
}

// Require POST
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    itn_log('NON-POST: ' . ($_SERVER['REQUEST_METHOD'] ?? ''));
    http_response_code(405);
    exit('Method Not Allowed');
}

$data = $_POST;
itn_log('ITN HIT raw=' . json_encode($data));

$mPaymentId    = $data['m_payment_id'] ?? '';
$pfPaymentId   = $data['pf_payment_id'] ?? '';
$paymentStatus = $data['payment_status'] ?? '';
$amountGross   = $data['amount_gross'] ?? '';
$sigRecv       = $data['signature'] ?? '';

try {
    // 1) Signature validation (FIXED)
    $paramString = pf_build_signature_string($data, (string)$payfastPassphrase);
    $sigCalc = md5($paramString);

    if (strcasecmp($sigRecv, $sigCalc) !== 0) {
        itn_log("SIGNATURE FAIL m_payment_id={$mPaymentId} recv={$sigRecv} calc={$sigCalc} param_string={$paramString}");
        http_response_code(400);
        exit('Invalid signature');
    }

    itn_log("SIGNATURE OK m_payment_id={$mPaymentId} status={$paymentStatus}");

    // 2) PayFast validation (recommended)
    $validateUrl = 'https://www.payfast.co.za/eng/query/validate';
    $validationBody = http_build_query($data);

    [$code, $resp, $err] = pf_remote_post($validateUrl, $validationBody);

    if ($err) {
        itn_log("VALIDATE ERROR curl={$err} http={$code}");
        http_response_code(500);
        exit('Validation error');
    }

    $respNorm = strtoupper(trim((string)$resp));
    itn_log("VALIDATE RESPONSE http={$code} body=" . str_replace(["\r","\n"], ['\\r','\\n'], $respNorm));

    if ($code !== 200 || $respNorm !== 'VALID') {
        itn_log("VALIDATE FAIL m_payment_id={$mPaymentId} http={$code} body=" . str_replace(["\r","\n"], ['\\r','\\n'], (string)$resp));
        http_response_code(400);
        exit('Validation failed');
    }

    // 3) Update DB
    $stmt = $pdo->prepare("SELECT * FROM quotes WHERE quote_number = :q LIMIT 1");
    $stmt->execute([':q' => $mPaymentId]);
    $quote = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$quote) {
        itn_log("QUOTE NOT FOUND m_payment_id={$mPaymentId}");
        http_response_code(404);
        exit('Quote not found');
    }

    $internalStatus = $quote['status'];

    if ($paymentStatus === 'COMPLETE') $internalStatus = 'paid';
    elseif ($paymentStatus === 'CANCELLED') $internalStatus = 'cancelled';
    elseif ($paymentStatus === 'FAILED') $internalStatus = 'failed';

    $stmt = $pdo->prepare("
        UPDATE quotes
        SET
          status = :status,
          payfast_payment_status = :pf_status,
          payfast_pf_payment_id = :pf_id,
          payfast_amount_gross = :gross,
          payfast_updated_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([
        ':status' => $internalStatus,
        ':pf_status' => $paymentStatus ?: null,
        ':pf_id' => $pfPaymentId ?: null,
        ':gross' => $amountGross ?: null,
        ':id' => (int)$quote['id'],
    ]);

    itn_log("DB UPDATED quote={$quote['quote_number']} internal={$internalStatus} pf_status={$paymentStatus} pf_payment_id={$pfPaymentId}");

    echo 'OK';
} catch (Throwable $e) {
    itn_log('ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo 'ITN error';
}