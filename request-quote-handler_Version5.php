<?php
// Enable debugging (for development only)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Database connection (replace with actual credentials)
$servername = "localhost";           // Update with your database server
$username = "your_username";         // Update with your username
$password = "your_password";         // Update with your password
$dbname = "bendcutsend_quotes";      // Update with your DB name

$conn = new mysqli($servername, $username, $password, $dbname);

// Check database connection
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// Capture form data
$first_name    = $_POST['first_name'];
$last_name     = $_POST['last_name'];
$email         = $_POST['email'];
$phone         = $_POST['phone'];
$company_name  = $_POST['company_name']; // Optional
$street        = $_POST['street'];
$city          = $_POST['city'];
$postal_code   = $_POST['postal_code'];
$building_type = $_POST['building_type'];
$material      = $_POST['material'];
$surface_finish = $_POST['surface_finish'];
$thickness     = $_POST['thickness'];
$quantity      = (int)$_POST['quantity'];
$comments      = $_POST['comments'];

// Combine client name
$client_name = $first_name . ' ' . $last_name;

// Validate required fields
if (empty($first_name) || empty($last_name) || empty($email) || empty($material) || empty($quantity)) {
    die("Error: Missing required fields.");
}

// File upload handling
$uploaded_file_path = '';
if (isset($_FILES['design_file']) && $_FILES['design_file']['error'][0] === UPLOAD_ERR_OK) {
    $upload_dir = "uploads/";
    $file_name = basename($_FILES["design_file"]["name"][0]);
    $target_file = $upload_dir . date('Ymd_His_') . uniqid() . "_" . $file_name;

    if (move_uploaded_file($_FILES["design_file"]["tmp_name"][0], $target_file)) {
        $uploaded_file_path = $target_file;
    } else {
        die("Error uploading file.");
    }
}

// Generate unique quote reference number
$quote_number = "BCS-" . date('Y') . "-" . str_pad(rand(1, 10000), 4, "0", STR_PAD_LEFT);

// Generate details field (concatenate all details)
$details = "Building Type: $building_type\nStreet: $street\nCity: $city\nPostal Code: $postal_code\nMaterial: $material\nSurface Finish: $surface_finish\nThickness: $thickness mm\nQuantity: $quantity\nComments: $comments";

// Insert data into database
$stmt = $conn->prepare("INSERT INTO quotes (quote_number, client_name, client_email, client_phone, company_name, details, material, thickness, quantity, status, created_at, bryco_quote) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', NOW(), ?)");

if (!$stmt) {
    die("Error preparing SQL statement: " . $conn->error);
}

$stmt->bind_param("ssssssssis", $quote_number, $client_name, $email, $phone, $company_name, $details, $material, $thickness, $quantity, $uploaded_file_path);

if ($stmt->execute()) {
    // Redirect to thank-you page with quote reference displayed
    header("Location: thank-you.php?quote_ref=" . urlencode($quote_number));
    exit();
} else {
    die("Error executing SQL statement: " . $stmt->error);
}

$stmt->close();
$conn->close();
?>