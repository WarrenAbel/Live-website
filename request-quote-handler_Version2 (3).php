<?php
// Enable error reporting for debugging (remove or disable in production)
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

// Helper: safely read POST with default
function post($key, $default = '') {
  return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

// Capture form data (company_name is OPTIONAL because the form doesn't send it)
$first_name     = post('first_name');
$last_name      = post('last_name');
$email          = post('email');
$phone          = post('phone');
$company_name   = post('company_name', ''); // optional
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

// Validate required fields (matches the form)
if ($first_name === '' || $last_name === '' || $email === '' || $material === '' || $quantity < 1) {
  die("Error: All required fields must be filled in.");
}

// Handle MULTIPLE file uploads (form field name design_file[])
$upload_dir = __DIR__ . "/uploads/";
$uploaded_paths = [];

if (!is_dir($upload_dir)) {
  // Create uploads folder if missing
  if (!mkdir($upload_dir, 0755, true)) {
    die("Error: Could not create uploads directory.");
  }
}

if (isset($_FILES['design_file']) && is_array($_FILES['design_file']['name'])) {
  $file_count = count($_FILES['design_file']['name']);

  for ($i = 0; $i < $file_count; $i++) {
    if ($_FILES['design_file']['error'][$i] === UPLOAD_ERR_NO_FILE) {
      continue;
    }

    if ($_FILES['design_file']['error'][$i] !== UPLOAD_ERR_OK) {
      die("Error uploading file (error code " . $_FILES['design_file']['error'][$i] . ").");
    }

    $original_name = basename($_FILES['design_file']['name'][$i]);
    $safe_name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $original_name);

    $target_rel = "uploads/" . date('Ymd_His_') . uniqid() . "_" . $safe_name;
    $target_abs = __DIR__ . "/" . $target_rel;

    if (!move_uploaded_file($_FILES['design_file']['tmp_name'][$i], $target_abs)) {
      die("Error uploading the design file. Please try again.");
    }

    $uploaded_paths[] = $target_rel;
  }
}

// Put file list into details (no DB column needed)
$files_line = "Design Files: " . (count($uploaded_paths) ? implode(", ", $uploaded_paths) : "None");

// Generate quote number
$quote_number = "BCS-" . date('Y') . "-" . str_pad((string)rand(1, 10000), 4, "0", STR_PAD_LEFT);

// Construct details field
$details =
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

// Insert into database (NO uploaded_files column used)
$sql = "INSERT INTO quotes
  (quote_number, client_name, client_email, client_phone, company_name, details, material, thickness, quantity, amount, status, created_at, bryco_quote)
  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0.00, 'draft', NOW(), NULL)";

$stmt = $conn->prepare($sql);
if (!$stmt) {
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
  header("Location: thank-you.php?quote_ref=" . urlencode($quote_number));
  exit();
}

die("Error executing query: " . $stmt->error);