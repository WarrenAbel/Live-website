<?php
// payfast-ipn.php (Plesk: place in httpdocs/)
// Handles PayFast ITN + writes a debug log to httpdocs/payfast-itn.log

require __DIR__ . '/config.php';

$logFile = __DIR__ . '/payfast-itn.log';
function itn_log(string $msg): void {
    global $logFile;
    @file_put_contents($logFile, '[' . date('c') . '] ' . $msg . PHP_EOL, FILE_APPEND);
}

function sendMailSimple($to, $subject, $body, $from = 'noreply@bendcutsend.net', $bcc = '') {
    $headers  = "From: {$from}\r\n";
    $headers .= "Reply-To: {$from}\r\n";
    if ($bcc !== '') $headers .= "Bcc: {$bcc}\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
    @mail($to, $subject, $body, $headers);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    itn_log('NON-POST: ' . ($_SERVER['REQUEST_METHOD'] ?? ''));
    http_response_code(405);
    exit('Method Not Allowed');
}

$data = $_POST;
itn_log('ITN HIT raw=' . json_encode($data));

try {
    $pfPaymentId   = $data['pf_payment_id']   ?? '';
    $mPaymentId    = $data['m_payment_id']    ?? '';
    $paymentStatus = $data['payment_status']  ?? '';
    $amountGross   = $data['amount_gross']    ?? '';

    // Signature validation
    $signature = $data['signature'] ?? '';
    unset($data['signature']);

    ksort($data);
    $pfParamString = '';
    foreach ($data as $key => $val) {
        if ($val !== '') $pfParamString .= $key . '=' . urlencode(trim($val)) . '&';
    }
    $pfParamString = rtrim($pfParamString, '&');

    if ($payfastPassphrase !== '') {
        $pfParamString .= '&passphrase=' . urlencode($payfastPassphrase);
    }

    $calculatedSignature = md5($pfParamString);

    if (strcasecmp($signature, $calculatedSignature) !== 0) {
        itn_log("SIGNATURE FAIL m_payment_id={$mPaymentId} recv={$signature} calc={$calculatedSignature}");
        http_response_code(400);
        exit('Invalid signature');
    }
    itn_log("SIGNATURE OK m_payment_id={$mPaymentId} payment_status={$paymentStatus}");

    // Lookup quote
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
    $clientEmail = $quote['client_email'];
    $clientName  = $quote['client_name'];

    // Map PayFast status -> internal status
    $statusToSet = $quote['status'];
    $emailSubject = '';
    $emailBodyAdmin = '';
    $emailBodyClient = '';

    if ($paymentStatus === 'COMPLETE') {
        $statusToSet = 'paid';
        $emailSubject = "Payment successful for quote {$quote['quote_number']}";
        $emailBodyAdmin =
            "A payment has been completed via PayFast.\n\n" .
            "Quote: {$quote['quote_number']}\nClient: {$clientName}\nClient email: {$clientEmail}\n" .
            "Amount paid: R {$amountGross}\nPayFast reference: {$pfPaymentId}\nStatus: COMPLETE\n";
        $emailBodyClient =
            "Hi {$clientName},\n\nThank you for your payment.\n\n" .
            "Quote: {$quote['quote_number']}\nAmount paid: R {$amountGross}\nPayFast reference: {$pfPaymentId}\n\n" .
            "Kind regards,\nBend Cut Send\nhttps://bendcutsend.net\n";
    } elseif ($paymentStatus === 'CANCELLED') {
        $statusToSet = 'cancelled';
        $emailSubject = "Payment cancelled for quote {$quote['quote_number']}";
        $emailBodyAdmin =
            "A PayFast payment was cancelled.\n\nQuote: {$quote['quote_number']}\nClient: {$clientName}\n" .
            "Client email: {$clientEmail}\nAmount: R {$amountGross}\nPayFast reference: {$pfPaymentId}\nStatus: CANCELLED\n";
        $emailBodyClient =
            "Hi {$clientName},\n\nYour PayFast payment for quote {$quote['quote_number']} was cancelled.\n\n" .
            "Kind regards,\nBend Cut Send\nhttps://bendcutsend.net\n";
    } elseif ($paymentStatus === 'FAILED') {
        $statusToSet = 'failed';
        $emailSubject = "Payment failed for quote {$quote['quote_number']}";
        $emailBodyAdmin =
            "A PayFast payment failed.\n\nQuote: {$quote['quote_number']}\nClient: {$clientName}\n" .
            "Client email: {$clientEmail}\nAmount: R {$amountGross}\nPayFast reference: {$pfPaymentId}\nStatus: FAILED\n";
        $emailBodyClient =
            "Hi {$clientName},\n\nUnfortunately, your PayFast payment for quote {$quote['quote_number']} failed.\n\n" .
            "Kind regards,\nBend Cut Send\nhttps://bendcutsend.net\n";
    }

    // Update DB (always store raw PayFast fields)
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

    itn_log("DB UPDATED quote={$quote['quote_number']} id={$quoteId} internal_status={$statusToSet}");

    if ($emailSubject !== '') {
        sendMailSimple($adminEmail, $emailSubject, $emailBodyAdmin, 'noreply@bendcutsend.net', 'sent@bendcutsend.net');
        if (!empty($clientEmail)) {
            sendMailSimple($clientEmail, $emailSubject, $emailBodyClient, 'noreply@bendcutsend.net');
        }
        itn_log("EMAIL SENT subject={$emailSubject}");
    }

    echo 'OK';
} catch (Throwable $e) {
    itn_log('ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo 'ITN error';
}