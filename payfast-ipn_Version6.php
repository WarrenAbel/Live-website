<?php
// payfast-ipn.php
// Handles PayFast ITN (Instant Transaction Notification)

require __DIR__ . '/config.php';

// ===== ITN DEBUG LOGGING (remove when confirmed working) =====
$logFile = __DIR__ . '/payfast-itn.log';
function itn_log($message) {
    global $logFile;
    @file_put_contents($logFile, '[' . date('c') . '] ' . $message . PHP_EOL, FILE_APPEND);
}

// Helper to send an email (simple wrapper)
function sendMailSimple($to, $subject, $body, $from = 'noreply@bendcutsend.net', $bcc = '') {
    $headers  = "From: {$from}\r\n";
    $headers .= "Reply-To: {$from}\r\n";
    if ($bcc !== '') {
        $headers .= "Bcc: {$bcc}\r\n";
    }
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
    @mail($to, $subject, $body, $headers);
}

// Read raw POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$data = $_POST;

// Log every hit (do NOT log full card/customer data; PayFast ITN doesn't include card numbers)
itn_log('ITN HIT: ' . json_encode($data));

try {
    // Required fields
    $pfPaymentId   = $data['pf_payment_id']   ?? '';
    $mPaymentId    = $data['m_payment_id']    ?? ''; // your quote_number
    $paymentStatus = $data['payment_status']  ?? ''; // COMPLETE / CANCELLED / FAILED / PENDING
    $amountGross   = $data['amount_gross']    ?? '';

    // ----------------------------------------------------------------------
    // 1) Validate signature
    // ----------------------------------------------------------------------
    $signature = $data['signature'] ?? '';
    unset($data['signature']);

    ksort($data);
    $pfParamString = '';
    foreach ($data as $key => $val) {
        if ($val !== '') {
            $pfParamString .= $key . '=' . urlencode(trim($val)) . '&';
        }
    }
    $pfParamString = rtrim($pfParamString, '&');

    if ($payfastPassphrase !== '') {
        $pfParamString .= '&passphrase=' . urlencode($payfastPassphrase);
    }

    $calculatedSignature = md5($pfParamString);

    if (strcasecmp($signature, $calculatedSignature) !== 0) {
        itn_log("SIGNATURE FAIL for m_payment_id={$mPaymentId} sig_recv={$signature} sig_calc={$calculatedSignature}");
        http_response_code(400);
        exit('Invalid signature');
    }

    itn_log("SIGNATURE OK for m_payment_id={$mPaymentId}");

    // ----------------------------------------------------------------------
    // 2) Lookup quote by quote_number (m_payment_id)
    // ----------------------------------------------------------------------
    $stmt = $pdo->prepare("SELECT * FROM quotes WHERE quote_number = :quote_number LIMIT 1");
    $stmt->execute([':quote_number' => $mPaymentId]);
    $quote = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$quote) {
        itn_log("QUOTE NOT FOUND for m_payment_id={$mPaymentId}");
        http_response_code(404);
        exit('Quote not found for m_payment_id');
    }

    $quoteId     = (int)$quote['id'];
    $adminEmail  = 'admin@bendcutsend.net';
    $clientEmail = $quote['client_email'];
    $clientName  = $quote['client_name'];

    // ----------------------------------------------------------------------
    // 3) Map PayFast payment_status -> internal status
    // ----------------------------------------------------------------------
    $statusToSet = $quote['status']; // default: keep existing
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
    }

    // ----------------------------------------------------------------------
    // 4) Update quote with BOTH internal and raw PayFast status fields
    // ----------------------------------------------------------------------
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

    itn_log("DB UPDATED quote_number={$quote['quote_number']} id={$quoteId} status={$statusToSet} pf_status={$paymentStatus} pf_payment_id={$pfPaymentId} amount_gross={$amountGross}");

    // ----------------------------------------------------------------------
    // 5) Send emails (admin + client) for handled statuses
    // ----------------------------------------------------------------------
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

        itn_log("EMAIL SENT quote_number={$quote['quote_number']} subject=" . $emailSubject);
    } else {
        itn_log("NO EMAIL (payment_status={$paymentStatus})");
    }

    echo 'OK';

} catch (Throwable $e) {
    itn_log("ERROR: " . $e->getMessage());
    http_response_code(500);
    echo 'ITN error';
}