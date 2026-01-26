<?php
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/payfast-link-error.log');

require __DIR__ . '/config.php';

$baseSite = 'https://bendcutsend.net';

function pf_to_sign(array $data, string $passphrase = ''): string
{
    unset($data['signature']);
    ksort($data);

    $pairs = [];
    foreach ($data as $k => $v) {
        $v = (string)$v;
        if ($v === '') continue;
        $pairs[] = $k . '=' . urlencode($v);
    }

    $s = implode('&', $pairs);

    if ($passphrase !== '') {
        $s .= '&passphrase=' . urlencode($passphrase);
    }

    return $s;
}

$quoteId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($quoteId <= 0) { http_response_code(400); exit('Missing id'); }

$stmt = $pdo->prepare("SELECT * FROM quotes WHERE id = :id");
$stmt->execute([':id' => $quoteId]);
$quote = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$quote) { http_response_code(404); exit('Quote not found'); }

$amount        = (float)($quote['amount'] ?? 0);
$quoteNumber   = (string)($quote['quote_number'] ?? '');
$customerName  = (string)($quote['client_name'] ?? '');
$customerEmail = (string)($quote['client_email'] ?? '');

if ($amount <= 0) { http_response_code(400); exit('Amount not set'); }
if ($quoteNumber === '') { http_response_code(500); exit('Missing quote_number'); }

$data = [
    'merchant_id'   => (string)$payfastMerchantId,
    'merchant_key'  => (string)$payfastMerchantKey,
    'return_url'    => $baseSite . '/payment-result.php?m_payment_id=' . $quoteNumber,

    // FIX: send cancels to the fallback endpoint that updates DB immediately
    'cancel_url'    => $baseSite . '/payment-cancelled.php?m_payment_id=' . $quoteNumber,

    'notify_url'    => $baseSite . '/payfast-ipn.php',
    'name_first'    => $customerName,
    'email_address' => $customerEmail,
    'm_payment_id'  => $quoteNumber,
    'amount'        => number_format($amount, 2, '.', ''),
    'item_name'     => 'Quote ' . $quoteNumber,
];

// strip empties
foreach ($data as $k => $v) {
    if ((string)$v === '') unset($data[$k]);
}

$passphrase = (string)($payfastPassphrase ?? '');
$toSign = pf_to_sign($data, $passphrase);
$sig = md5($toSign);

// add signature into data
$data['signature'] = $sig;

// build URL from data INCLUDING signature
$redirectUrl = rtrim((string)$payfastBaseUrl, '?') . '?' . http_build_query($data, '', '&');

@file_put_contents(
    __DIR__ . '/payfast-link-debug.log',
    '[' . date('c') . "] quote={$quoteNumber}\nURL={$redirectUrl}\nTO_SIGN={$toSign}\nSIG={$sig}\n\n",
    FILE_APPEND
);

header('Location: ' . $redirectUrl);
exit;