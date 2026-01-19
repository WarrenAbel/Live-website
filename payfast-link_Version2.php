<?php
require __DIR__ . '/config.php';

// Get quote id from URL
$quoteId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($quoteId <= 0) {
    http_response_code(400);
    echo 'Missing or invalid quote ID.';
    exit;
}

// Load quote from DB
$stmt = $pdo->prepare("SELECT * FROM quotes WHERE id = :id");
$stmt->execute([':id' => $quoteId]);
$quote = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$quote) {
    http_response_code(404);
    echo 'Quote not found.';
    exit;
}

$amount       = (float)$quote['amount'];
$customerName = $quote['client_name'];
$customerEmail= $quote['client_email'];
$quoteNumber  = $quote['quote_number'];

if ($amount <= 0) {
    die('Quote amount not set yet.');
}

// Build PayFast data
$data = [
    'merchant_id'   => $payfastMerchantId,
    'merchant_key'  => $payfastMerchantKey,
    'return_url'    => 'https://bendcutsend.net/thank-you.html',
    'cancel_url'    => 'https://bendcutsend.net/payment-cancelled.html',
    'notify_url'    => 'https://bendcutsend.net/payfast-ipn.php',

    'name_first'    => $customerName,
    'email_address' => $customerEmail,

    'm_payment_id'  => $quoteNumber,                     // your quote ref
    'amount'        => number_format($amount, 2, '.', ''),
    'item_name'     => 'Quote ' . $quoteNumber,
];

// Signature function
function generateSignature($data, $passphrase) {
    $tmp = [];
    foreach ($data as $key => $value) {
        if ($value !== '') {
            $tmp[] = $key . '=' . urlencode(trim($value));
        }
    }
    $string = implode('&', $tmp);

    if ($passphrase !== '') {
        $string .= '&passphrase=' . urlencode($passphrase);
    }

    return md5($string);
}

$data['signature'] = generateSignature($data, $payfastPassphrase);

// Build redirect URL
$query = http_build_query($data);
$redirectUrl = $payfastBaseUrl . '?' . $query;

// Redirect to PayFast
header('Location: ' . $redirectUrl);
exit;