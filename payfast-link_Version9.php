<?php
require __DIR__ . '/config.php';

$base = 'https://bendcutsend.net'; // IMPORTANT: keep consistent everywhere

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

// Mark as pending before redirect (optional but helpful)
try {
    $pdo->prepare("UPDATE quotes SET status = 'pending' WHERE id = :id")->execute([':id' => $quoteId]);
} catch (Throwable $e) {
    // ignore if your schema doesn't allow it; not fatal
}

// Build PayFast request
$data = [
    'merchant_id'   => $payfastMerchantId,
    'merchant_key'  => $payfastMerchantKey,

    'return_url'    => $base . '/payment-result.php?m_payment_id=' . urlencode($quoteNumber),
    'cancel_url'    => $base . '/payment-result.php?m_payment_id=' . urlencode($quoteNumber),
    'notify_url'    => $base . '/payfast-ipn.php',

    'name_first'    => $customerName,
    'email_address' => $customerEmail,

    'm_payment_id'  => $quoteNumber,
    'amount'        => number_format($amount, 2, '.', ''),
    'item_name'     => 'Quote ' . $quoteNumber,
];

// Remove empty + sort
unset($data['signature']);
foreach ($data as $k => $v) if ((string)$v === '') unset($data[$k]);
ksort($data);

// Build param string with urlencode (PayFast compatible)
$pairs = [];
foreach ($data as $k => $v) {
    $pairs[] = $k . '=' . urlencode((string)$v);
}
$paramString = implode('&', $pairs);

// Sign (include passphrase only if it is set in BOTH PayFast dashboard and config.php)
$toSign = $paramString;
if (!empty($payfastPassphrase)) {
    $toSign .= '&passphrase=' . urlencode($payfastPassphrase);
}
$signature = md5($toSign);

$redirectUrl = rtrim($payfastBaseUrl, '?') . '?' . $paramString . '&signature=' . $signature;

// Debug log: proves what URL is being sent (so we can confirm notify_url)
@file_put_contents(__DIR__ . '/payfast-link-debug.log',
    '[' . date('c') . "] quote={$quoteNumber}\nURL={$redirectUrl}\nTO_SIGN={$toSign}\nSIG={$signature}\n\n",
    FILE_APPEND
);

header('Location: ' . $redirectUrl);
exit;