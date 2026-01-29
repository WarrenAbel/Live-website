<?php
// Only handle real form submissions
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  header('Content-Type: text/plain; charset=utf-8');
  echo "Method Not Allowed. Please submit the form from the Request a Quote page.";
  exit;
}

// Enable error reporting for debugging (disable in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Database connection
$servername = "localhost";
$username = "Quotes";
$password = "nhIep49H1@jad#qH";
$dbname = "bendcutsend_quotes";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
  die("Database connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

// Helper
function post($key, $default = '') {
  return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

// Read fields (company_name optional)
$first_name     = post('first_name');
$last_name      = post('last_name');
$email          = post('email');
$phone          = post('phone');
$company_name   = post('company_name', '');
$street         = post('street');
$city           = post('city');
$postal_code    = post('postal_code');
$building_type  = post('building_type');
$material       = post('material');
$surface_finish = post('surface_finish');
$thickness      = post('thickness');
$quantity       = (int)($_POST['quantity'] ?? 0);
$comments       = post('comments', 'N/A');

$client_name = trim($first_name . " " . $last_name);

// Validate required fields (matches your form)
if ($first_name === '' || $last_name === '' || $email === '' || $material === '' || $quantity < 1) {
  die("Error: All required fields must be filled in.");
}

/**
 * Generate sequential quote number: BCS-YYYY-0001, 0002, ...
 * This version ignores accidental big numbers (like 5355) by only considering < 1000.
 * Adjust $limit if you want a larger range.
 */
$year = date('Y');
$prefix = "BCS-$year-";
$limit = 1000;

$conn->begin_transaction();

try {
  $sqlMax = "
    SELECT MAX(CAST(RIGHT(quote_number, 4) AS UNSIGNED)) AS max_seq
    FROM quotes
    WHERE quote_number LIKE CONCAT(?, '%')
      AND quote_number REGEXP CONCAT('^', ?, '[0-9]{4}$')
      AND CAST(RIGHT(quote_number, 4) AS UNSIGNED) < ?
    FOR UPDATE
  ";

  $stmtMax = $conn->prepare($sqlMax);
  if (!$stmtMax) {
    throw new Exception("Prepare failed (max): " . $conn->error);
  }

  $stmtMax->bind_param("ssi", $prefix, $prefix, $limit);
  if (!$stmtMax->execute()) {
    throw new Exception("Execute failed (max): " . $stmtMax->error);
  }

  $stmtMax->bind_result($max_seq);
  $stmtMax->fetch();
  $stmtMax->close();

  $max_seq = $max_seq !== null ? (int)$max_seq : 0;
  $next_seq = $max_seq + 1;
  $quote_number = $prefix . str_pad((string)$next_seq, 4, "0", STR_PAD_LEFT);

  // Handle MULTIPLE uploads (design_file[])
  $upload_dir_abs = __DIR__ . "/uploads/";
  $uploaded_paths = [];

  if (!is_dir($upload_dir_abs) && !mkdir($upload_dir_abs, 0755, true)) {
    throw new Exception("Could not create uploads directory.");
  }

  if (isset($_FILES['design_file']) && is_array($_FILES['design_file']['name'])) {
    $file_count = count($_FILES['design_file']['name']);

    for ($i = 0; $i < $file_count; $i++) {
      if ($_FILES['design_file']['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
      if ($_FILES['design_file']['error'][$i] !== UPLOAD_ERR_OK) {
        throw new Exception("File upload error code: " . $_FILES['design_file']['error'][$i]);
      }

      $original_name = basename($_FILES['design_file']['name'][$i]);
      $safe_name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $original_name);

      $target_rel = "uploads/" . date('Ymd_His_') . uniqid() . "_" . $safe_name;
      $target_abs = __DIR__ . "/" . $target_rel;

      if (!move_uploaded_file($_FILES['design_file']['tmp_name'][$i], $target_abs)) {
        throw new Exception("Failed to move uploaded file: " . $original_name);
      }

      $uploaded_paths[] = $target_rel;
    }
  }

  // UPDATED: include download.php links (uploads folder is not publicly accessible; direct /uploads/... 404s)
  $baseSite = 'https://bendcutsend.net';
  $files_line = "Design Files: " . (count($uploaded_paths) ? implode(", ", array_map(function ($p) use ($baseSite) {
    return $p . " (" . $baseSite . "/download.php?f=" . rawurlencode($p) . ")";
  }, $uploaded_paths)) : "None");

  // Construct details (store file paths here; no extra DB column required)
  $details =
    "Quote Number: $quote_number\n" .
    "Client Name: $client_name\n" .
    "Client Email: $email\n" .
    "Client Phone: $phone\n" .
    "Company Name: " . ($company_name !== '' ? $company_name : "N/A") . "\n" .
    "Building Type: $building_type\n" .
    "Street: $street\n" .
    "City: $city\n" .
    "Postal Code: $postal_code\n" .
    "Material: $material\n" .
    "Surface Finish: $surface_finish\n" .
    "Thickness: $thickness mm\n" .
    "Quantity: $quantity\n" .
    "Comments: $comments\n" .
    $files_line;

  // Insert
  $sql = "INSERT INTO quotes
    (quote_number, client_name, client_email, client_phone, company_name, details, material, thickness, quantity, amount, status, created_at, bryco_quote)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0.00, 'draft', NOW(), NULL)";

  $stmt = $conn->prepare($sql);
  if (!$stmt) {
    throw new Exception("Prepare failed (insert): " . $conn->error);
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

  if (!$stmt->execute()) {
    throw new Exception("Execute failed (insert): " . $stmt->error);
  }

  $stmt->close();

  // --- EMAIL NOTIFICATION (silent BCC) ---
  // Primary recipient (To) can be a no-reply mailbox on your domain to avoid spam filters.
  // BCC will silently notify sent@bendcutsend.net.
  $to = "no-reply@bendcutsend.net";
  $subject = "New Quote Request Submitted: $quote_number";

  $headers = [];
  // Use a domain email as From to reduce SPF/DMARC issues
  $headers[] = "From: Bend Cut Send <no-reply@bendcutsend.net>";
  // Optional: reply should go to the customer
  $headers[] = "Reply-To: " . $email;
  $headers[] = "MIME-Version: 1.0";
  $headers[] = "Content-Type: text/plain; charset=utf-8";
  $headers[] = "Bcc: sent@bendcutsend.net";

  // Send after DB insert but before redirect. If mail fails, we still proceed.
  @mail($to, $subject, $details, implode("\r\n", $headers));

  // NEW: customer confirmation email (does not change the admin email above)
  $cust_to = $email;
  $cust_subject = "We received your quote request: $quote_number";

  $cust_message =
    "Hi $client_name,\n\n" .
    "Thank you — we received your quote request.\n\n" .
    "Your quote reference is: $quote_number\n\n" .
    "We will review your request and email you your quote as soon as possible.\n\n" .
    "Regards,\n" .
    "Bend Cut Send\n" .
    "https://bendcutsend.net\n";

  $cust_headers = [];
  $cust_headers[] = "From: Bend Cut Send <no-reply@bendcutsend.net>";
  $cust_headers[] = "Reply-To: sent@bendcutsend.net";
  $cust_headers[] = "MIME-Version: 1.0";
  $cust_headers[] = "Content-Type: text/plain; charset=utf-8";

  @mail($cust_to, $cust_subject, $cust_message, implode("\r\n", $cust_headers));

  // Commit after DB + email attempt
  $conn->commit();

  header("Location: thank-you.php?quote_ref=" . urlencode($quote_number));
  exit;

} catch (Exception $e) {
  $conn->rollback();
  http_response_code(500);
  die("Server error: " . $e->getMessage());
}