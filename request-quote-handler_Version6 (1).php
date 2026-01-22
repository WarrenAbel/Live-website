<?php
// Enable error reporting for debugging (Remove this in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Database connection with correct details
$servername = "localhost"; // Update if needed; default is "localhost"
$username = "Quotes";      // Database username (provided)
$password = "nhIep49H1@jad#qH"; // Database password (provided)
$dbname = "bendcutsend_quotes"; // Database name (assuming this is correct)

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
$company_name  = $_POST['company_name'];
$street        = $_POST['street'];
$city          = $_POST['city'];
$postal_code   = $_POST['postal_code'];
$building_type = $_POST['building_type'];
$material      = $_POST['material'];
$surface_finish = $_POST['surface_finish'];
$thickness     = $_POST['thickness'];
$quantity      = (int)$_POST['quantity'];
$comments      = $_POST['comments'] ?: "N/A";

// Combine names into `client_name`
$client_name = $first_name . " " . $last_name;

// Validate required fields
if (empty($first_name) || empty($last_name) || empty($email) || empty($material) || empty($quantity)) {
    die("Error: All required fields must be filled in.");
}

// Handle file upload
$uploaded_file_path = '';
if (isset($_FILES['design_file']) && $_FILES['design_file']['error'][0] === UPLOAD_ERR_OK) {
    $upload_dir = "uploads/";
    $file_name = basename($_FILES["design_file"]["name"][0]);
    $target_file = $upload_dir . date('Ymd_His_') . uniqid() . "_" . $file_name;

    if (move_uploaded_file($_FILES["design_file"]["tmp_name"][0], $target_file)) {
        $uploaded_file_path = $target_file;
    } else {
        die("Error uploading the design file. Please try again.");
    }
}

// Generate unique quote reference number
$quote_number = "BCS-" . date('Y') . "-" . str_pad(rand(1, 10000), 4, "0", STR_PAD_LEFT);

// Construct `details` field content
$details = "Building Type: $building_type\nStreet: $street\nCity: $city\nPostal Code: $postal_code\nMaterial: $material\nSurface Finish: $surface_finish\nThickness: $thickness mm\nQuantity: $quantity\nComments: $comments";

// Insert data into database
$stmt = $conn->prepare("INSERT INTO quotes (quote_number, client_name, client_email, client_phone, company_name, details, material, thickness, quantity, amount, status, created_at, bryco_quote) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0.00, 'draft', NOW(), NULL)");

if (!$stmt) {
    die("Error preparing SQL statement: " . $conn->error);
}

$stmt->bind_param("ssssssssi", $quote_number, $client_name, $email, $phone, $company_name, $details, $material, $thickness, $quantity);

if ($stmt->execute()) {
    // Redirect to the thank-you page and display quote number
    header("Location: thank-you.php?quote_ref=" . urlencode($quote_number));
    exit();
} else {
    die("Error executing query: " . $stmt->error);
}

// Close statement and connection
$stmt->close();
$conn->close();
?>