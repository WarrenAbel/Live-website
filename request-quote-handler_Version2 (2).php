<?php
// Debugging for development
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Database configuration
$servername = "localhost";
$username = "Quotes";
$password = "nhIep49H1@jad#qH";
$dbname = "bendcutsend_quotes";

// Database connection
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// Get form data
$first_name    = trim($_POST['first_name']);
$last_name     = trim($_POST['last_name']);
$email         = trim($_POST['email']);
$phone         = trim($_POST['phone']);
$company_name  = trim($_POST['company_name']);
$street        = trim($_POST['street']);
$city          = trim($_POST['city']);
$postal_code   = trim($_POST['postal_code']);
$building_type = trim($_POST['building_type']);
$material      = trim($_POST['material']);
$surface_finish = trim($_POST['surface_finish']);
$thickness     = trim($_POST['thickness']);
$quantity      = (int) $_POST['quantity'];
$comments      = !empty($_POST['comments']) ? trim($_POST['comments']) : 'N/A';

// Validate required fields
if (empty($first_name) || empty($last_name) || empty($email) || empty($material) || empty($quantity)) {
    die("Error: Please fill in all required fields.");
}

// File upload logic
$uploaded_file_paths = [];
if (isset($_FILES['design_file']) && count($_FILES['design_file']['name']) > 0) {
    $upload_dir = "uploads/";
    for ($i = 0; $i < count($_FILES['design_file']['name']); $i++) {
        if ($_FILES['design_file']['error'][$i] === UPLOAD_ERR_OK) {
            $filename = basename($_FILES['design_file']['name'][$i]);
            $unique_filename = $upload_dir . date('Ymd_His_') . uniqid() . "_" . $filename;
            if (move_uploaded_file($_FILES['design_file']['tmp_name'][$i], $unique_filename)) {
                $uploaded_file_paths[] = $unique_filename;
            } else {
                die("Error uploading file $filename. Please try again.");
            }
        }
    }
}

// Concatenate uploaded files into a single string for storage
$uploaded_files_string = implode(",", $uploaded_file_paths);

// Generate a unique quote number
$quote_number = 'BCS-' . date('Y') . '-' . str_pad(rand(1, 10000), 4, '0', STR_PAD_LEFT);

// Details
$details = "Building Type: $building_type\nStreet: $street\nCity: $city\nPostal Code: $postal_code\nMaterial: $material\nSurface Finish: $surface_finish\nThickness: $thickness mm\nQuantity: $quantity\nComments: $comments";

// Insert into database
$query = "INSERT INTO quotes (quote_number, client_name, client_email, client_phone, company_name, details, material, thickness, quantity, amount, status, created_at, uploaded_files) 
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0.00, 'draft', NOW(), ?)";
$stmt = $conn->prepare($query);
if (!$stmt) {
    die("Error preparing query: " . $conn->error);
}

$client_name = "$first_name $last_name";
$stmt->bind_param("sssssssisi", $quote_number, $client_name, $email, $phone, $company_name, $details, $material, $thickness, $quantity, $uploaded_files_string);

if ($stmt->execute()) {
    header("Location: thank-you.php?quote_ref=" . urlencode($quote_number));
    exit();
} else {
    die("Error executing query: " . $stmt->error);
}

$stmt->close();
$conn->close();
?>