<?php
// admin-quotes.php
session_start();
require __DIR__ . '/config.php';

if (empty($_SESSION['is_admin'])) {
    header('Location: admin-login.php');
    exit;
}

// Handle update submission (amount / status / Bryco quote)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_quote'])) {
    $id          = (int)($_POST['id'] ?? 0);
    $amount      = (float)($_POST['amount'] ?? 0);
    $status      = $_POST['status'] ?? 'draft';
    $brycoQuote  = trim($_POST['bryco_quote'] ?? '');

    if ($id > 0) {
        // Basic sanitising of status
        $allowedStatuses = ['draft','sent','paid','cancelled','failed'];
        if (!in_array($status, $allowedStatuses, true)) {
            $status = 'draft';
        }

        $stmt = $pdo->prepare(
            "UPDATE quotes
             SET amount = :amount,
                 status = :status,
                 bryco_quote = :bryco_quote
             WHERE id = :id"
        );
        $stmt->execute([
            ':amount'      => $amount,
            ':status'      => $status,
            ':bryco_quote' => $brycoQuote,
            ':id'          => $id,
        ]);
    }

    // Redirect to avoid form resubmission
    header('Location: admin-quotes.php');
    exit;
}

// Handle delete submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_quote'])) {
    $id = (int)($_POST['id'] ?? 0);

    if ($id > 0) {
        $stmt = $pdo->prepare("DELETE FROM quotes WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }

    header('Location: admin-quotes.php');
    exit;
}

// Fetch quotes (newest first)
$stmt = $pdo->query("SELECT * FROM quotes ORDER BY created_at DESC");
$quotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Quotes Admin | Bend Cut Send</title>
  <style>
    body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; font-size:14px; }
    table { border-collapse: collapse; width: 100%; font-size: 0.85rem; }
    th, td { border: 1px solid #ddd; padding: 0.35rem 0.5rem; text-align: left; vertical-align: top; }
    th { background: #f3f4f6; }
    tr:nth-child(even) { background: #fafafa; }
    .status-draft { color: #92400e; font-weight: 600; }
    .status-sent { color: #1d4ed8; font-weight: 600; }
    .status-paid { color: #15803d; font-weight: 600; }
    .status-cancelled { color: #b91c1c; font-weight: 600; }
    .status-failed { color: #b91c1c; font-weight: 600; }
    .small { font-size: 0.75rem; color:#4b5563; white-space: pre-line; }
    .topbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; }
    input[type="number"], select { font-size:0.8rem; padding:0.15rem 0.25rem; width:5rem; }
    .status-select { width:6.5rem; }
    .btn-small { font-size:0.75rem; padding:0.15rem 0.4rem; cursor:pointer; }
    .payment-input { width:100%; font-size:0.7rem; }
    .bryco-input { width:7rem; font-size:0.75rem; }
    .actions-row { margin-top:0.25rem; display:flex; gap:0.25rem; align-items:center; flex-wrap:wrap; }
    .btn-danger { background:#b91c1c; color:#fff; border:0; border-radius:2px; }
  </style>
</head>
<body>
  <div class="topbar">
    <h1>Quotes Admin</h1>
    <div>
      <a href="index.html">View site</a> |
      <a href="admin-logout.php">Log out</a>
    </div>
  </div>

  <table>
    <thead>
      <tr>
        <th>ID</th>
        <th>Quote # / Bryco #</th>
        <th>Client</th>
        <th>Contact</th>
        <th>Amount / Status (editable)</th>
        <th>Created &amp; details</th>
        <th>Payment link</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$quotes): ?>
        <tr><td colspan="7">No quotes yet.</td></tr>
      <?php else: ?>
        <?php foreach ($quotes as $q): ?>
          <?php
            $statusClass = 'status-' . $q['status'];
            // Now point to quote-payment.php instead of payfast-link.php
            $paymentUrl  = 'https://' . $_SERVER['HTTP_HOST'] . '/quote-payment.php?id=' . $q['id'];
          ?>
          <tr>
            <td><?php echo h($q['id']); ?></td>
            <td>
              <?php echo h($q['quote_number']); ?><br>
              <?php if (!empty($q['bryco_quote'])): ?>
                <span class="small">Bryco #: <?php echo h($q['bryco_quote']); ?></span>
              <?php endif; ?>
            </td>
            <td><?php echo h($q['client_name']); ?></td>
            <td>
              <div><?php echo h($q['client_email']); ?></div>
              <div><?php echo h($q['client_phone']); ?></div>
            </td>
            <td>
              <form method="post" action="admin-quotes.php" style="margin:0;">
                <input type="hidden" name="id" value="<?php echo h($q['id']); ?>">
                <label>
                  R
                  <input
                    type="number"
                    name="amount"
                    step="0.01"
                    min="0"
                    value="<?php echo number_format((float)$q['amount'], 2, '.', ''); ?>">
                </label>
                <br>
                <label>
                  Status
                  <select name="status" class="status-select">
                    <option value="draft"     <?php if ($q['status']==='draft')     echo 'selected'; ?>>draft</option>
                    <option value="sent"      <?php if ($q['status']==='sent')      echo 'selected'; ?>>sent</option>
                    <option value="paid"      <?php if ($q['status']==='paid')      echo 'selected'; ?>>paid</option>
                    <option value="cancelled" <?php if ($q['status']==='cancelled') echo 'selected'; ?>>cancelled</option>
                    <option value="failed"    <?php if ($q['status']==='failed')    echo 'selected'; ?>>failed</option>
                  </select>
                </label>
                <br>
                <label>
                  Bryco #:
                  <input
                    type="text"
                    name="bryco_quote"
                    class="bryco-input"
                    value="<?php echo h($q['bryco_quote'] ?? ''); ?>">
                </label>
                <div class="actions-row">
                  <button type="submit" name="update_quote" class="btn-small">Save</button>
                  <span class="<?php echo h($statusClass); ?>">
                    current: <?php echo h($q['status']); ?>
                  </span>
                </div>
              </form>

              <form method="post" action="admin-quotes.php" style="margin-top:0.25rem;"
                    onsubmit="return confirm('Are you sure you want to delete this quote?');">
                <input type="hidden" name="id" value="<?php echo h($q['id']); ?>">
                <button type="submit" name="delete_quote" class="btn-small btn-danger">Delete</button>
              </form>
            </td>
            <td>
              <?php echo h($q['created_at']); ?><br>
              <span class="small"><?php echo h($q['details']); ?></span>
            </td>
            <td>
              <input type="text"
                     class="payment-input"
                     value="<?php echo h($paymentUrl); ?>"
                     readonly
                     onclick="this.select();">
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</body>
</html>