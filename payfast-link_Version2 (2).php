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

$amount        = (float)($quote['amount'] ?? 0);
$customerName  = (string)($quote['client_name'] ?? '');
$customerEmail = (string)($quote['client_email'] ?? '');
$quoteNumber   = (string)($quote['quote_number'] ?? '');

if ($amount <= 0) {
    die('Quote amount not set yet.');
}

// Build PayFast data
$data = [
    'merchant_id'   => $payfastMerchantId,
    'merchant_key'  => $payfastMerchantKey,

    'return_url'    => 'https://bendcutsend.net/payment-result.php?m_payment_id=' . urlencode($quoteNumber),
    'cancel_url'    => 'https://bendcutsend.net/payment-result.php?m_payment_id=' . urlencode($quoteNumber),
    'notify_url'    => 'https://bendcutsend.net/payfast-ipn.php',

    'name_first'    => $customerName,
    'email_address' => $customerEmail,

    'm_payment_id'  => $quoteNumber,
    'amount'        => number_format($amount, 2, '.', ''),
    'item_name'     => 'Quote ' . $quoteNumber,
];

// Canonical PayFast signature generator
function generatePayfastSignature(array $data, string $passphrase = ''): string {
    // 1) sort by key
    ksort($data);

    // 2) build param string (skip empty values, DO NOT trim)
    $pairs = [];
    foreach ($data as $key => $value) {
        $value = (string)$value;
        if ($value === '') continue;
        $pairs[] = $key . '=' . urlencode($value);
    }
    $string = implode('&', $pairs);

    // 3) add passphrase if set
    if ($passphrase !== '') {
        $string .= '&passphrase=' . urlencode($passphrase);
    }

    return md5($string);
}

$data['signature'] = generatePayfastSignature($data, (string)$payfastPassphrase);

// Redirect to PayFast
$redirectUrl = rtrim($payfastBaseUrl, '?') . '?' . http_build_query($data);
header('Location: ' . $redirectUrl);
exit;