<?php
// payfast-ipn.php
// PayFast ITN handler (LIVE) + debug logging + PayFast server validation
// Place in: httpdocs/payfast-ipn.php (Plesk)
//
// Creates log: httpdocs/payfast-itn.log
//
// IMPORTANT:
// - Ensure config.php has LIVE merchant_id/merchant_key and passphrase that matches PayFast.
// - This script expects m_payment_id to equal your quotes.quote_number.

require __DIR__ . '/config.php';

// =====================
// Logging
// =====================
$logFile = __DIR__ . '/payfast-itn.log';

function itn_log(string $msg): void {
    global $logFile;
    @file_put_contents($logFile, '[' . date('c') . '] ' . $msg . PHP_EOL, FILE_APPEND);
}

// =====================
// Mail helper
// =====================
function sendMailSimple($to, $subject, $body, $from = 'noreply@bendcutsend.net', $bcc = ''): void {
    $headers  = "From: {$from}\r\n";
    $headers .= "Reply-To: {$from}\r\n";
    if ($bcc !== '') {
        $headers .= "Bcc: {$bcc}\r\n";
    }
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
    @mail($to, $subject, $body, $headers);
}

// =====================
// HTTP helpers
// =====================
function pf_remote_post(string $url, string $body, int $timeoutSeconds = 10): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Connection: Close',
        ],
    ]);

    $responseBody = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$httpCode, $responseBody, $curlErr];
}

// =====================
// Require POST
// =====================
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    itn_log('NON-POST: method=' . ($_SERVER['REQUEST_METHOD'] ?? ''));
    http_response_code(405);
    exit('Method Not Allowed');
}

$data = $_POST;
itn_log('ITN HIT raw=' . json_encode($data));

try {
    // Required fields (may be empty depending on status)
    $pfPaymentId   = $data['pf_payment_id']   ?? '';
    $mPaymentId    = $data['m_payment_id']    ?? ''; // quote_number
    $paymentStatus = $data['payment_status']  ?? ''; // COMPLETE / CANCELLED / FAILED / PENDING
    $amountGross   = $data['amount_gross']    ?? '';

    // ============================================================
    // 1) SIGNATURE VALIDATION (FIXED: include ALL fields, no trim)
    // ============================================================
    $signature = $data['signature'] ?? '';
    $sigData = $data;
    unset($sigData['signature']);

    ksort($sigData);

    $pfParamString = '';
    foreach ($sigData as $key => $val) {
        // IMPORTANT: include ALL keys even if empty, and do NOT trim
        $pfParamString .= $key . '=' . urlencode((string)$val) . '&';
    }
    $pfParamString = rtrim($pfParamString, '&');

    if (!empty($payfastPassphrase)) {
        $pfParamString .= '&passphrase=' . urlencode($payfastPassphrase);
    }

    $calculatedSignature = md5($pfParamString);

    if (strcasecmp($signature, $calculatedSignature) !== 0) {
        itn_log("SIGNATURE FAIL m_payment_id={$mPaymentId} recv={$signature} calc={$calculatedSignature} param_string={$pfParamString}");
        http_response_code(400);
        exit('Invalid signature');
    }

    itn_log("SIGNATURE OK m_payment_id={$mPaymentId} payment_status={$paymentStatus}");

    // ============================================================
    // 2) PAYFAST SERVER VALIDATION (recommended improvement)
    //    Post back to PayFast validation endpoint.
    // ============================================================
    // PayFast validation endpoint (LIVE). If you ever use sandbox, change this to sandbox validate.
    $validateUrl = 'https://www.payfast.co.za/eng/query/validate';

    // Validation body must be the original POST data (including signature)
    // Build from $data to ensure we send exactly what we received.
    $validationBody = '';
    foreach ($data as $key => $val) {
        $validationBody .= $key . '=' . urlencode((string)$val) . '&';
    }
    $validationBody = rtrim($validationBody, '&');

    [$httpCode, $validateResponse, $curlErr] = pf_remote_post($validateUrl, $validationBody, 15);

    if ($curlErr) {
        itn_log("VALIDATE ERROR curl={$curlErr} http={$httpCode}");
        http_response_code(500);
        exit('Validation error');
    }

    $validateResponseNorm = strtoupper(trim((string)$validateResponse));
    itn_log("VALIDATE RESPONSE http={$httpCode} body=" . str_replace(["\r", "\n"], ['\\r', '\\n'], $validateResponseNorm));

    // PayFast typically returns "VALID" if OK
    if ($httpCode !== 200 || $validateResponseNorm !== 'VALID') {
        itn_log("VALIDATE FAIL m_payment_id={$mPaymentId} http={$httpCode} body=" . str_replace(["\r", "\n"], ['\\r', '\\n'], (string)$validateResponse));
        http_response_code(400);
        exit('Validation failed');
    }

    // ============================================================
    // 3) LOOKUP QUOTE
    // ============================================================
    $stmt = $pdo->prepare("SELECT * FROM quotes WHERE quote_number = :quote_number LIMIT 1");
    $stmt->execute([':quote_number' => $mPaymentId]);
    $quote = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$quote) {
        itn_log("QUOTE NOT FOUND m_payment_id={$mPaymentId}");
        http_response_code(404);
        exit('Quote not found for m_payment_id');
    }

    $quoteId     = (int)$quote['id'];
    $adminEmail  = 'admin@bendcutsend.net';
    $clientEmail = $quote['client_email'] ?? '';
    $clientName  = $quote['client_name'] ?? '';

    // ============================================================
    // 4) MAP STATUS
    // ============================================================
    $statusToSet = $quote['status']; // keep existing by default
    $emailSubject = '';
    $emailBodyAdmin = '';
    $emailBodyClient = '';

    if ($paymentStatus === 'COMPLETE') {
        $statusToSet = 'paid';

        $emailSubject = "Payment successful for quote {$quote['quote_number']}";
        $emailBodyAdmin =
            "A payment has been completed via PayFast.\n\n" .
            "Quote: {$quote['quote_number']}\n" .
            "Client: {$clientName}\n" .
            "Client email: {$clientEmail}\n" .
            "Amount paid: R {$amountGross}\n" .
            "PayFast reference: {$pfPaymentId}\n" .
            "Status: COMPLETE\n";

        $emailBodyClient =
            "Hi {$clientName},\n\n" .
            "Thank you for your payment.\n\n" .
            "Quote: {$quote['quote_number']}\n" .
            "Amount paid: R {$amountGross}\n" .
            "PayFast reference: {$pfPaymentId}\n\n" .
            "Your payment has been received successfully.\n\n" .
            "Kind regards,\nBend Cut Send\nhttps://bendcutsend.net\n";

    } elseif ($paymentStatus === 'CANCELLED') {
        $statusToSet = 'cancelled';

        $emailSubject = "Payment cancelled for quote {$quote['quote_number']}";
        $emailBodyAdmin =
            "A PayFast payment was cancelled.\n\n" .
            "Quote: {$quote['quote_number']}\n" .
            "Client: {$clientName}\n" .
            "Client email: {$clientEmail}\n" .
            "Amount: R {$amountGross}\n" .
            "PayFast reference: {$pfPaymentId}\n" .
            "Status: CANCELLED\n";

        $emailBodyClient =
            "Hi {$clientName},\n\n" .
            "Your PayFast payment for quote {$quote['quote_number']} was cancelled.\n\n" .
            "No funds have been taken. If you cancelled by mistake, you can use the payment link we sent you to try again.\n\n" .
            "Kind regards,\nBend Cut Send\nhttps://bendcutsend.net\n";

    } elseif ($paymentStatus === 'FAILED') {
        $statusToSet = 'failed';

        $emailSubject = "Payment failed for quote {$quote['quote_number']}";
        $emailBodyAdmin =
            "A PayFast payment failed.\n\n" .
            "Quote: {$quote['quote_number']}\n" .
            "Client: {$clientName}\n" .
            "Client email: {$clientEmail}\n" .
            "Amount: R {$amountGross}\n" .
            "PayFast reference: {$pfPaymentId}\n" .
            "Status: FAILED\n";

        $emailBodyClient =
            "Hi {$clientName},\n\n" .
            "Unfortunately, your PayFast payment for quote {$quote['quote_number']} failed.\n\n" .
            "No funds have been taken. Please try again later or contact us if the problem continues.\n\n" .
            "Kind regards,\nBend Cut Send\nhttps://bendcutsend.net\n";
    } else {
        // PENDING or other: do not force internal status change
        itn_log("UNHANDLED payment_status={$paymentStatus} (will only store PayFast fields)");
    }

    // ============================================================
    // 5) UPDATE DB (always store PayFast fields)
    // ============================================================
    $stmt = $pdo->prepare("
      UPDATE quotes
      SET
        status = :status,
        payfast_payment_status = :payfast_payment_status,
        payfast_pf_payment_id = :payfast_pf_payment_id,
        payfast_amount_gross = :payfast_amount_gross,
        payfast_updated_at = NOW()
      WHERE id = :id
    ");
    $stmt->execute([
        ':status' => $statusToSet,
        ':payfast_payment_status' => ($paymentStatus !== '' ? $paymentStatus : null),
        ':payfast_pf_payment_id' => ($pfPaymentId !== '' ? $pfPaymentId : null),
        ':payfast_amount_gross' => ($amountGross !== '' ? $amountGross : null),
        ':id' => $quoteId,
    ]);

    itn_log("DB UPDATED quote_number={$quote['quote_number']} id={$quoteId} internal_status={$statusToSet} pf_status={$paymentStatus} pf_payment_id={$pfPaymentId} amount_gross={$amountGross}");

    // ============================================================
    // 6) EMAILS (only for COMPLETE/CANCELLED/FAILED)
    // ============================================================
    if ($emailSubject !== '') {
        sendMailSimple(
            $adminEmail,
            $emailSubject,
            $emailBodyAdmin,
            'noreply@bendcutsend.net',
            'sent@bendcutsend.net'
        );

        if (!empty($clientEmail)) {
            sendMailSimple(
                $clientEmail,
                $emailSubject,
                $emailBodyClient,
                'noreply@bendcutsend.net'
            );
        }

        itn_log("EMAIL SENT subject=" . $emailSubject);
    }

    echo 'OK';
} catch (Throwable $e) {
    itn_log('ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo 'ITN error';
}