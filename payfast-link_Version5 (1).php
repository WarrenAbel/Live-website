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
    'return_url'    => 'https://bendcutsend.net/payment-result.php?m_payment_id=' . $quoteNumber,
    'cancel_url'    => 'https://bendcutsend.net/payment-result.php?m_payment_id=' . $quoteNumber,
    'notify_url'    => 'https://bendcutsend.net/payfast-ipn.php',
    'name_first'    => $customerName,
    'email_address' => $customerEmail,
    'm_payment_id'  => $quoteNumber,
    'amount'        => number_format($amount, 2, '.', ''),
    'item_name'     => 'Quote ' . $quoteNumber,
];

// Remove empty values (PayFast signing rule)
foreach ($data as $k => $v) {
    if ((string)$v === '') unset($data[$k]);
}

// Sort keys (PayFast signing rule)
ksort($data);

// Build query string in ONE place with ONE encoding (RFC1738 == spaces become +)
$query = http_build_query($data, '', '&', PHP_QUERY_RFC1738);

// Append passphrase (must match PayFast dashboard exactly)
if (!empty($payfastPassphrase)) {
    $queryToSign = $query . '&passphrase=' . urlencode($payfastPassphrase);
} else {
    $queryToSign = $query;
}

// Sign EXACTLY what we send
$signature = md5($queryToSign);

// Build final redirect URL using the same query string
$redirectUrl = rtrim($payfastBaseUrl, '?') . '?' . $query . '&signature=' . $signature;

// (Temporary debug file to prove exactly what was signed)
@file_put_contents(__DIR__ . '/payfast-link-debug.log',
    '[' . date('c') . "] quote={$quoteNumber}\nTO_SIGN={$queryToSign}\nSIG={$signature}\nURL={$redirectUrl}\n\n",
    FILE_APPEND
);

header('Location: ' . $redirectUrl);
exit;