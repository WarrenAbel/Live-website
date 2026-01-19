<?php
// payfast-ipn.php
require __DIR__ . '/config.php';

// 1. Read POST data from PayFast
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$postData = $_POST;

// 2. Basic security: verify that this call is from PayFast's servers (optional but recommended)
$validHosts = [
    'www.payfast.co.za',
    'sandbox.payfast.co.za',
    'w1w.payfast.co.za',
    'w2w.payfast.co.za'
];
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
// For strict security you’d do a DNS lookup against these hosts; keeping it simple here.

// 3. Verify signature (PayFast docs)
function generateSignature(array $data, string $passphrase): string
{
    // Remove the signature parameter before hashing
    if (isset($data['signature'])) {
        unset($data['signature']);
    }

    // Build the parameter string
    $pairs = [];
    foreach ($data as $key => $value) {
        if ($value !== '') {
            $pairs[] = $key . '=' . urlencode(trim($value));
        }
    }
    $string = implode('&', $pairs);

    if ($passphrase !== '') {
        $string .= '&passphrase=' . urlencode($passphrase);
    }

    return md5($string);
}

$theirSignature = $postData['signature'] ?? '';
$ourSignature   = generateSignature($postData, $payfastPassphrase);

if ($theirSignature !== $ourSignature) {
    // Invalid signature – possible tampering
    http_response_code(400);
    exit('Invalid signature');
}

// 4. Check payment status
$status = $postData['payment_status'] ?? '';
if (strcasecmp($status, 'COMPLETE') !== 0) {
    // Not a successful payment, ignore
    http_response_code(200);
    exit('Not complete');
}

// 5. Get our quote reference (we sent it as m_payment_id)
$quoteNumber = $postData['m_payment_id'] ?? '';
$pfAmount    = (float) ($postData['amount_gross'] ?? 0);

// 6. Look up the quote in DB by quote_number
$stmt = $pdo->prepare("SELECT * FROM quotes WHERE quote_number = :qn LIMIT 1");
$stmt->execute([':qn' => $quoteNumber]);
$quote = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$quote) {
    http_response_code(404);
    exit('Quote not found');
}

// 7. Amount check: make sure PayFast amount matches our quote amount
$dbAmount = (float) $quote['amount'];

if (abs($dbAmount - $pfAmount) > 0.01) {
    http_response_code(400);
    exit('Amount mismatch');
}

// 8. Everything looks good – mark quote as PAID
$update = $pdo->prepare("UPDATE quotes SET status = 'paid' WHERE id = :id");
$update->execute([':id' => $quote['id']]);

// 9. Respond OK so PayFast knows we handled it
http_response_code(200);
echo 'OK';