<?php
// Database connection
$servername = "localhost";
$username = "root"; // Update with your database username
$password = "";     // Update with your database password
$dbname = "bend_cut_send"; // Update with your database name

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Capture form data
$first_name = $_POST['first_name'];
$last_name = $_POST['last_name'];
$email = $_POST['email'];
$phone = $_POST['phone'];
$street = $_POST['street'];
$city = $_POST['city'];
$postal_code = $_POST['postal_code'];
$building_type = $_POST['building_type'];
$material = $_POST['material'];
$surface_finish = $_POST['surface_finish'];
$thickness = $_POST['thickness'];
$quantity = $_POST['quantity'];
$comments = $_POST['comments'];

// File upload processing
$upload_dir = "uploads/";
$file_name = basename($_FILES["design_file"]["name"][0]);
$target_file = $upload_dir . date('Ymd_His_') . uniqid() . "_" . $file_name;
if (!move_uploaded_file($_FILES["design_file"]["tmp_name"][0], $target_file)) {
    die("Error uploading file.");
}

// Generate unique quote reference number
$quote_ref = "BCS-" . date('Y') . "-" . str_pad(rand(1, 10000), 4, "0", STR_PAD_LEFT);

// Insert into the database
$stmt = $conn->prepare("INSERT INTO quotes (quote_ref, first_name, last_name, email, phone, street, city, postal_code, building_type, material, surface_finish, thickness, quantity, comments, design_file, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
$stmt->bind_param("ssssssssssssiss", $quote_ref, $first_name, $last_name, $email, $phone, $street, $city, $postal_code, $building_type, $material, $surface_finish, $thickness, $quantity, $comments, $target_file);

if ($stmt->execute()) {
    header("Location: thank-you.php?quote_ref=" . urlencode($quote_ref));
} else {
    echo "Error: " . $stmt->error;
}

$stmt->close();
$conn->close();
?>