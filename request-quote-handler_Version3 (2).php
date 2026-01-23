<?php
// ... keep everything above as-is ...

// Generate sequential quote number: BCS-YYYY-0001, 0002, ...
$year = date('Y');

// Locking strategy: use a transaction + FOR UPDATE to reduce duplicates under concurrency.
// NOTE: This assumes your table is InnoDB. If it's MyISAM, FOR UPDATE won't help.
$conn->begin_transaction();

try {
    $prefix = "BCS-$year-";

    // Get the highest sequence for this year
    $sqlMax = "
      SELECT MAX(CAST(SUBSTRING(quote_number, LENGTH(?) + 1) AS UNSIGNED)) AS max_seq
      FROM quotes
      WHERE quote_number LIKE CONCAT(?, '%')
      FOR UPDATE
    ";
    $stmtMax = $conn->prepare($sqlMax);
    if (!$stmtMax) {
        throw new Exception("Error preparing max query: " . $conn->error);
    }

    $stmtMax->bind_param("ss", $prefix, $prefix);
    $stmtMax->execute();
    $result = $stmtMax->get_result();
    $row = $result ? $result->fetch_assoc() : null;

    $maxSeq = isset($row['max_seq']) && $row['max_seq'] !== null ? (int)$row['max_seq'] : 0;
    $nextSeq = $maxSeq + 1;

    $quote_number = $prefix . str_pad((string)$nextSeq, 4, "0", STR_PAD_LEFT);

    $stmtMax->close();

    // IMPORTANT: do NOT commit yet if your INSERT is below.
    // Commit after the INSERT succeeds, and rollback if it fails.

} catch (Exception $e) {
    $conn->rollback();
    die("Error generating quote number: " . $e->getMessage());
}

// ... continue building $details ...

// Insert data into database (your existing INSERT)
$sql = "INSERT INTO quotes
  (quote_number, client_name, client_email, client_phone, company_name, details, material, thickness, quantity, amount, status, created_at, bryco_quote)
  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0.00, 'draft', NOW(), NULL)";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    $conn->rollback();
    die("Error preparing SQL statement: " . $conn->error);
}

$stmt->bind_param(
  "ssssssssi",
  $quote_number,
  $client_name,
  $email,
  $phone,
  $company_name,
  $details,
  $material,
  $thickness,
  $quantity
);

if ($stmt->execute()) {
    $conn->commit();
    header("Location: thank-you.php?quote_ref=" . urlencode($quote_number));
    exit();
} else {
    $conn->rollback();
    die("Error executing query: " . $stmt->error);
}