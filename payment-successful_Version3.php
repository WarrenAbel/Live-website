<?php
require __DIR__ . '/config.php';

/**
 * B2: Verify server-to-server on return_url before marking PAID.
 *
 * Requirements/assumptions from you:
 * - Passphrase: NO
 * - Environment: LIVE (www.payfast.co.za)
 * - Success status expected: COMPLETE
 *
 * This file does NOT replace ITN/IPN. It is a fallback to update immediately on the client return,
 * but only after PayFast validation confirms the transaction.
 */

$quoteNumber = isset($_GET['m_payment_id']) ? trim($_GET['m_payment_id']) : '';
if ($quoteNumber === '') {
    http_response_code(400);
    exit('Missing quote reference.');
}

// Load quote to get expected amount (prevents marking paid for wrong amount)
$stmt = $pdo->prepare("SELECT * FROM quotes WHERE quote_number = :q LIMIT 1");
$stmt->execute([':q' => $quoteNumber]);
$quote = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$quote) {
    http_response_code(404);
    exit('Quote not found.');
}

$expectedAmount = number_format((float)($quote['amount'] ?? 0), 2, '.', '');
if ((float)$expectedAmount <= 0) {
    http_response_code(400);
    exit('Invalid quote amount.');
}

/**
 * PayFast "server-side verification" approach:
 * - Take whatever PayFast returns to return_url (usually GET params)
 * - Post it to /eng/query/validate
 * - Expect response body to start with "VALID"
 *
 * NOTE: Some return_url flows may not include all fields. If we have too little data,
 * we cannot validate and will just redirect to payment-result.php (pending).
 */
$returnData = $_GET;
unset($returnData['wait']); // ignore our own param if present

// Build validation string (URL encoded, sorted) similarly to ITN validation flow.
function pf_build_query_string(array $data): string
{
    // signature may or may not be present; keep it for validation POST if PayFast returned it
    ksort($data);

    $pairs = [];
    foreach ($data as $k => $v) {
        if (is_array($v)) continue;
        $v = (string)$v;
        if ($v === '') continue;
        $pairs[] = $k . '=' . urlencode($v);
    }
    return implode('&', $pairs);
}

// If PayFast didn't return enough fields, we cannot validate reliably.
$hasPaymentStatus = isset($returnData['payment_status']) && $returnData['payment_status'] !== '';
$hasPfPaymentId   = isset($returnData['pf_payment_id']) && $returnData['pf_payment_id'] !== '';
$hasGross         = isset($returnData['amount_gross']) && $returnData['amount_gross'] !== '';

if (!$hasPaymentStatus && !$hasPfPaymentId && !$hasGross) {
    // Not enough info to validate on return. Fall back to normal polling page.
    header('Location: /payment-result.php?m_payment_id=' . urlencode($quoteNumber) . '&wait=0');
    exit;
}

$pfHost = 'www.payfast.co.za';
$validateUrl = 'https://' . $pfHost . '/eng/query/validate';
$postBody = pf_build_query_string($returnData);

$ch = curl_init($validateUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $postBody,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);
$resp = curl_exec($ch);
$err  = curl_error($ch);
$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Log for debugging
@file_put_contents(
    __DIR__ . '/payfast-return-verify.log',
    "[" . date('c') . "] m_payment_id={$quoteNumber}\nHTTP={$http}\nERR={$err}\nPOST={$postBody}\nRESP={$resp}\n\n",
    FILE_APPEND
);

$isValid = (!$err && $http === 200 && is_string($resp) && strncmp($resp, 'VALID', 5) === 0);

if ($isValid) {
    $paymentStatus = strtoupper(trim((string)($returnData['payment_status'] ?? '')));

    // Only mark paid when PayFast says COMPLETE
    if ($paymentStatus === 'COMPLETE') {
        // Extra safety: if amount_gross is present, ensure it matches expected amount
        $amountGross = isset($returnData['amount_gross']) ? number_format((float)$returnData['amount_gross'], 2, '.', '') : null;
        if ($amountGross !== null && $amountGross !== $expectedAmount) {
            // Mismatch: do not mark paid. Treat as pending and let ITN resolve.
            header('Location: /payment-result.php?m_payment_id=' . urlencode($quoteNumber) . '&wait=0');
            exit;
        }

        $pfPaymentId = (string)($returnData['pf_payment_id'] ?? '');
        $stmt = $pdo->prepare("
            UPDATE quotes
            SET status = 'paid',
                payfast_payment_status = 'COMPLETE',
                payfast_pf_payment_id = NULLIF(:pf_payment_id, ''),
                payfast_amount_gross = COALESCE(NULLIF(:amount_gross, ''), payfast_amount_gross),
                payfast_updated_at = NOW()
            WHERE quote_number = :quote_number
            LIMIT 1
        ");
        $stmt->execute([
            ':quote_number'  => $quoteNumber,
            ':pf_payment_id' => $pfPaymentId,
            ':amount_gross'  => (string)($returnData['amount_gross'] ?? ''),
        ]);
    } elseif ($paymentStatus === 'CANCELLED') {
        // Optional: you already have payment-cancelled.php, but handle it here too.
        $stmt = $pdo->prepare("
            UPDATE quotes
            SET status = 'cancelled',
                payfast_payment_status = 'CANCELLED',
                payfast_updated_at = NOW()
            WHERE quote_number = :quote_number
            LIMIT 1
        ");
        $stmt->execute([':quote_number' => $quoteNumber]);
    } else {
        // Any other VALID status: mark as pending
        $stmt = $pdo->prepare("
            UPDATE quotes
            SET status = CASE
                WHEN status IN ('paid','cancelled','failed') THEN status
                ELSE 'sent'
            END,
            payfast_payment_status = COALESCE(NULLIF(:pf_status, ''), payfast_payment_status),
            payfast_updated_at = NOW()
            WHERE quote_number = :quote_number
            LIMIT 1
        ");
        $stmt->execute([
            ':quote_number' => $quoteNumber,
            ':pf_status' => (string)($returnData['payment_status'] ?? ''),
        ]);
    }
}

// Always redirect to your existing page (single source of UI)
header('Location: /payment-result.php?m_payment_id=' . urlencode($quoteNumber) . '&wait=0');
exit;