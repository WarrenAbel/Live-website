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

// Build signature EXACTLY as PayFast expects
function payfast_signature(array $data, string $passphrase = ''): string {
    // 1) remove any existing signature
    unset($data['signature']);

    // 2) remove empty values
    foreach ($data as $k => $v) {
        if ((string)$v === '') unset($data[$k]);
    }

    // 3) sort keys
    ksort($data);

    // 4) build query in RFC3986 mode (PayFast-friendly)
    $query = http_build_query($data, '', '&', PHP_QUERY_RFC3986);

    // 5) append passphrase (must match PayFast dashboard exactly)
    if ($passphrase !== '') {
        $query .= '&passphrase=' . rawurlencode($passphrase);
    }

    return md5($query);
}

$data['signature'] = payfast_signature($data, (string)$payfastPassphrase);

// Redirect to PayFast
$redirectUrl = rtrim($payfastBaseUrl, '?') . '?' . http_build_query($data, '', '&', PHP_QUERY_RFC3986);
header('Location: ' . $redirectUrl);
exit;