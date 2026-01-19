<?php
// quote-payment.php
require __DIR__ . '/config.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo 'Missing or invalid quote ID.';
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM quotes WHERE id = :id");
$stmt->execute([':id' => $id]);
$quote = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$quote) {
    http_response_code(404);
    echo 'Quote not found.';
    exit;
}

$amount      = (float)$quote['amount'];
$quoteNumber = $quote['quote_number'];
$clientName  = $quote['client_name'];

if ($amount <= 0) {
    echo 'This quote does not have an amount set yet.';
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Pay Quote <?php echo htmlspecialchars($quoteNumber, ENT_QUOTES, 'UTF-8'); ?> | Bend Cut Send</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="styles.css">
</head>
<body>
<nav class="navbar">
  <a href="index.html">Home</a>
  <a href="how-it-works.html">How it works</a>
  <a href="metals.html">Metals Available</a>
  <a href="qa.html">Q&amp;A</a>
  <a href="request-quote.html">Request a Quote</a>
  <a href="ask-question.html">Contact Us/Ask a Question</a>
</nav>

<main class="container text-page">
  <h1>Pay your quote</h1>
  <p>Hi <?php echo htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8'); ?>,</p>
  <p>You are paying quote <strong><?php echo htmlspecialchars($quoteNumber, ENT_QUOTES, 'UTF-8'); ?></strong> for the amount of <strong>R <?php echo number_format($amount, 2); ?></strong>.</p>
  <p>When you click the button below you will be taken to our secure PayFast payment page.</p>

  <form action="payfast-link.php" method="get">
    <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
    <button type="submit" class="cta-btn accent">Pay now</button>
  </form>
</main>

<footer class="site-footer">
  <p>&copy; <span id="year"></span> Bend Cut Send.</p>
</footer>
<script>
  document.getElementById('year').textContent = new Date().getFullYear();
</script>
</body>
</html>