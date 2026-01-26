<?php
require __DIR__ . '/config.php';

$quoteId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($quoteId <= 0) {
    http_response_code(400);
    echo 'Missing or invalid quote ID.';
    exit;
}

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

// PayFast data
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

// Canonical signature (PayFast-style)
function pf_signature(array $data, string $passphrase = ''): array {
    unset($data['signature']);
    ksort($data);

    $pairs = [];
    foreach ($data as $k => $v) {
        $v = (string)$v;
        if ($v === '') continue;          // skip empty
        $pairs[] = $k . '=' . urlencode($v); // PayFast commonly uses urlencode
    }

    $paramString = implode('&', $pairs);

    if ($passphrase !== '') {
        $paramString .= '&passphrase=' . urlencode($passphrase);
    }

    return [$paramString, md5($paramString)];
}

[$sigBase, $sig] = pf_signature($data, (string)$payfastPassphrase);
$data['signature'] = $sig;

// DEBUG LOG (temporary): writes the exact string used to sign
@file_put_contents(__DIR__ . '/payfast-link-debug.log',
    '[' . date('c') . "] quote={$quoteNumber}\nSIG_BASE={$sigBase}\nSIG={$sig}\n\n",
    FILE_APPEND
);

// Redirect
$query = http_build_query($data); // this is OK for redirect; signature was already computed above
$redirectUrl = rtrim($payfastBaseUrl, '?') . '?' . $query;

header('Location: ' . $redirectUrl);
exit;