<?php
require __DIR__ . '/config.php';

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

// SIGNATURE (PayFast canonical: sort + skip empty)
unset($data['signature']);
foreach ($data as $k => $v) if ((string)$v === '') unset($data[$k]);
ksort($data);

$paramString = '';
foreach ($data as $k => $v) {
    $paramString .= $k . '=' . urlencode((string)$v) . '&';
}
$paramString = rtrim($paramString, '&');

// Only add passphrase if you later re-enable it
if ($payfastPassphrase !== '') {
    $paramString .= '&passphrase=' . urlencode($payfastPassphrase);
}

$data['signature'] = md5($paramString);

$redirectUrl = rtrim($payfastBaseUrl, '?') . '?' . http_build_query($data);
header('Location: ' . $redirectUrl);
exit;