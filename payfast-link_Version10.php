<?php
require __DIR__ . '/config.php';

$base = 'https://bendcutsend.net';

$quoteId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($quoteId <= 0) { http_response_code(400); exit('Missing or invalid quote ID.'); }

$stmt = $pdo->prepare("SELECT * FROM quotes WHERE id = :id");
$stmt->execute([':id' => $quoteId]);
$quote = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$quote) { http_response_code(404); exit('Quote not found.'); }

$amount        = (float)($quote['amount'] ?? 0);
$customerName  = (string)($quote['client_name'] ?? '');
$customerEmail = (string)($quote['client_email'] ?? '');
$quoteNumber   = (string)($quote['quote_number'] ?? '');

if ($amount <= 0) exit('Quote amount not set yet.');

// Parameters sent to PayFast
$data = [
    'merchant_id'   => $payfastMerchantId,
    'merchant_key'  => $payfastMerchantKey, // sent, but NOT signed

    'return_url'    => $base . '/payment-result.php?m_payment_id=' . urlencode($quoteNumber),
    'cancel_url'    => $base . '/payment-result.php?m_payment_id=' . urlencode($quoteNumber),
    'notify_url'    => $base . '/payfast-ipn.php',

    'name_first'    => $customerName,
    'email_address' => $customerEmail,

    'm_payment_id'  => $quoteNumber,
    'amount'        => number_format($amount, 2, '.', ''),
    'item_name'     => 'Quote ' . $quoteNumber,
];

// Remove empty + sort for sending
foreach ($data as $k => $v) if ((string)$v === '') unset($data[$k]);
ksort($data);

// Build the parameter string for the redirect (urlencode)
$pairs = [];
foreach ($data as $k => $v) {
    $pairs[] = $k . '=' . urlencode((string)$v);
}
$paramString = implode('&', $pairs);

// Build signature base: same as above but EXCLUDING merchant_key
$toSignData = $data;
unset($toSignData['merchant_key']); // IMPORTANT
ksort($toSignData);

$signPairs = [];
foreach ($toSignData as $k => $v) {
    $v = (string)$v;
    if ($v === '') continue;
    $signPairs[] = $k . '=' . urlencode($v);
}
$toSign = implode('&', $signPairs);

// Passphrase only if enabled in BOTH PayFast dashboard and config.php
if (!empty($payfastPassphrase)) {
    $toSign .= '&passphrase=' . urlencode($payfastPassphrase);
}

$signature = md5($toSign);

$redirectUrl = rtrim($payfastBaseUrl, '?') . '?' . $paramString . '&signature=' . $signature;

@file_put_contents(__DIR__ . '/payfast-link-debug.log',
    '[' . date('c') . "] quote={$quoteNumber}\nURL={$redirectUrl}\nTO_SIGN={$toSign}\nSIG={$signature}\n\n",
    FILE_APPEND
);

header('Location: ' . $redirectUrl);
exit;