<?php
// payfast-ipn.php
// Handles PayFast ITN (Instant Transaction Notification)
// - Verifies security
// - Updates quote status to paid / cancelled / failed
// - Sends emails to admin and client

require __DIR__ . '/config.php';

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

// Store POST in a local variable
$data = $_POST;

// Basic required fields
$pfPaymentId   = $data['pf_payment_id']   ?? '';
$mPaymentId    = $data['m_payment_id']    ?? ''; // your quote_number
$paymentStatus = $data['payment_status']  ?? '';
$amountGross   = $data['amount_gross']    ?? '';
$emailAddress  = $data['email_address']   ?? '';

// ----------------------------------------------------------------------
// 1. Validate signature
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

// Add passphrase if set
if ($payfastPassphrase !== '') {
    $pfParamString .= '&passphrase=' . urlencode($payfastPassphrase);
}

$calculatedSignature = md5($pfParamString);

if (strcasecmp($signature, $calculatedSignature) !== 0) {
    http_response_code(400);
    exit('Invalid signature');
}

// ----------------------------------------------------------------------
// 2. Lookup quote by quote_number (m_payment_id)
// ----------------------------------------------------------------------
$stmt = $pdo->prepare("SELECT * FROM quotes WHERE quote_number = :quote_number LIMIT 1");
$stmt->execute([':quote_number' => $mPaymentId]);
$quote = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$quote) {
    http_response_code(404);
    exit('Quote not found for m_payment_id');
}

$quoteId    = (int)$quote['id'];
$adminEmail = 'admin@bendcutsend.net';
$clientEmail = $quote['client_email'];
$clientName  = $quote['client_name'];

// ----------------------------------------------------------------------
// 3. Update status based on payment_status
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

// Update status if changed
if ($statusToSet !== $quote['status']) {
    $stmt = $pdo->prepare("UPDATE quotes SET status = :status WHERE id = :id");
    $stmt->execute([
        ':status' => $statusToSet,
        ':id'     => $quoteId,
    ]);
}

// ----------------------------------------------------------------------
// 4. Send emails (admin + client) for all handled statuses
// ----------------------------------------------------------------------
if ($emailSubject !== '') {
    // Admin gets a copy, plus BCC to sent@ for record
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
}

// Always respond something so PayFast knows ITN was processed
echo 'OK';