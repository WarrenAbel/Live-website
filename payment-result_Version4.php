<?php
// payment-result.php
require __DIR__ . '/config.php';

$quoteNumber = isset($_GET['m_payment_id']) ? trim($_GET['m_payment_id']) : '';

if ($quoteNumber === '') {
    http_response_code(400);
    echo 'Missing quote reference.';
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM quotes WHERE quote_number = :quote_number LIMIT 1");
$stmt->execute([':quote_number' => $quoteNumber]);
$quote = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$quote) {
    http_response_code(404);
    echo 'Quote not found.';
    exit;
}

$status     = $quote['status'];
$clientName = $quote['client_name'];

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$title       = 'Payment result';
$heading     = 'Payment status';
$description = 'We could not determine the status of your payment. Please contact us.';

if ($status === 'paid') {
    $title       = 'Payment successful';
    $heading     = 'Payment successful';
    $description = 'Thank you, your payment has been received successfully.';
} elseif ($status === 'cancelled') {
    $title       = 'Payment cancelled';
    $heading     = 'Payment cancelled';
    $description = 'Your payment was cancelled. No funds have been taken.';
} elseif ($status === 'failed') {
    $title       = 'Payment failed';
    $heading     = 'Payment failed';
    $description = 'Unfortunately your payment failed. No funds have been taken. You can try again or contact us for help.';
} elseif ($status === 'sent' || $status === 'draft') {
    $title       = 'Payment pending';
    $heading     = 'Payment pending';
    $description = 'Your payment is being processed. If you do not receive a confirmation email, please contact us.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title><?php echo h($title); ?> | Bend Cut Send</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="styles.css">
</head>
<body>

<nav class="navbar">
  <div class="nav-inner">
    <a href="index.html" class="nav-logo">Bend Cut Send</a>
    <button class="nav-toggle" aria-label="Toggle navigation" onclick="document.body.classList.toggle('nav-open');">
      ☰
    </button>
    <div class="nav-links">
      <a href="index.html">Home</a>
      <a href="how-it-works.html">How it works</a>
      <a href="metals.html">Metals Available</a>
      <a href="qa.html">Q&amp;A</a>
      <a href="request-quote.html">Request a Quote</a>
      <a href="ask-question.html">Contact Us/Ask a Question</a>
    </div>
  </div>
</nav>

<main class="container text-page">
  <h1><?php echo h($heading); ?></h1>
  <?php if ($clientName): ?>
    <p>Hi <?php echo h($clientName); ?>,</p>
  <?php endif; ?>
  <p><?php echo h($description); ?></p>
  <p>Quote reference: <strong><?php echo h($quoteNumber); ?></strong></p>

  <p><a href="index.html" class="cta-btn accent">Back to homepage</a></p>
</main>

<footer class="site-footer">
  <p>&copy; <span id="year"></span> Bend Cut Send.</p>
</footer>
<script>
  document.getElementById('year').textContent = new Date().getFullYear();
</script>
</body>
</html>