<?php
require __DIR__ . '/config.php';

$quoteNumber = isset($_GET['m_payment_id']) ? trim($_GET['m_payment_id']) : '';
if ($quoteNumber === '') {
    http_response_code(400);
    exit('Missing quote reference.');
}

// Set cancelled immediately (fallback). If ITN later arrives as COMPLETE, it can overwrite to paid.
$stmt = $pdo->prepare("
    UPDATE quotes
    SET status = 'cancelled',
        payfast_payment_status = 'CANCELLED',
        payfast_updated_at = NOW()
    WHERE quote_number = :quote_number
    LIMIT 1
");
$stmt->execute([':quote_number' => $quoteNumber]);

header('Location: /payment-result.php?m_payment_id=' . urlencode($quoteNumber));
exit;