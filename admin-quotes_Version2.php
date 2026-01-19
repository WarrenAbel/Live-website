<?php
// admin-quotes.php
session_start();
require __DIR__ . '/config.php';

if (empty($_SESSION['is_admin'])) {
    header('Location: admin-login.php');
    exit;
}

// Handle update submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_quote'])) {
    $id     = (int)($_POST['id'] ?? 0);
    $amount = (float)($_POST['amount'] ?? 0);
    $status = $_POST['status'] ?? 'draft';

    if ($id > 0) {
        // Basic sanitising of status
        $allowedStatuses = ['draft','sent','paid','cancelled'];
        if (!in_array($status, $allowedStatuses, true)) {
            $status = 'draft';
        }

        $stmt = $pdo->prepare(
            "UPDATE quotes SET amount = :amount, status = :status WHERE id = :id"
        );
        $stmt->execute([
            ':amount' => $amount,
            ':status' => $status,
            ':id'     => $id,
        ]);
    }

    // Redirect to avoid form resubmission
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
    .small { font-size: 0.75rem; color:#4b5563; white-space: pre-line; }
    .topbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; }
    input[type="number"], select { font-size:0.8rem; padding:0.15rem 0.25rem; width:5rem; }
    .status-select { width:6.5rem; }
    .btn-small { font-size:0.75rem; padding:0.15rem 0.4rem; cursor:pointer; }
    .payment-input { width:100%; font-size:0.7rem; }
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
        <th>Quote #</th>
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
            $paymentUrl  = 'https://' . $_SERVER['HTTP_HOST'] . '/payfast-link.php?id=' . $q['id'];
          ?>
          <tr>
            <td><?php echo h($q['id']); ?></td>
            <td><?php echo h($q['quote_number']); ?></td>
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
                  </select>
                </label>
                <br>
                <button type="submit" name="update_quote" class="btn-small">Save</button>
                <div class="<?php echo h($statusClass); ?>" style="margin-top:0.15rem;">
                  current: <?php echo h($q['status']); ?>
                </div>
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