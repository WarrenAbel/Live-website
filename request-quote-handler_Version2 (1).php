<?php
// request-quote-handler.php
require __DIR__ . '/config.php';

// Helper to get a POST field safely
function post($key, $default = '') {
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

// Enable detailed error reporting for debugging purposes
ini_set('log_errors', 1); // Enable error logging
ini_set('error_log', __DIR__ . '/logs/request-quote-errors.log'); // Specify log file
error_reporting(E_ALL); // Log all errors

error_log("Form submission started.");

// 1. Read form fields (names must match your HTML)
$firstName      = post('first_name');
$lastName       = post('last_name');
$email          = post('email');
$phone          = post('phone');
$street         = post('street');
$city           = post('city');
$buildingType   = post('building_type');
$postalCode     = post('postal_code');
$material       = post('material');
$surfaceFinish  = post('surface_finish');
$thickness      = post('thickness');
$quantity       = (int) post('quantity');
$comments       = post('comments');

// Basic validation (server-side)
$errors = [];

if ($firstName === '')    $errors[] = 'First name is required.';
if ($lastName === '')     $errors[] = 'Last name is required.';
if ($email === '')        $errors[] = 'Email is required.';
if ($phone === '')        $errors[] = 'Phone number is required.';
if ($street === '')       $errors[] = 'Street address is required.';
if ($city === '')         $errors[] = 'City is required.';
if ($buildingType === '') $errors[] = 'Building type is required.';
if ($postalCode === '')   $errors[] = 'Postal code is required.';
if ($material === '')     $errors[] = 'Material is required.';
if ($surfaceFinish === '')$errors[] = 'Surface finish is required.';
if ($thickness === '')    $errors[] = 'Thickness is required.';
if ($quantity <= 0)       $errors[] = 'Quantity must be at least 1.';

// 2. Handle file upload
$uploadedFiles = []; // To store the names of successfully uploaded files
$maxBytes = 25 * 1024 * 1024; // 25MB Limit

if (isset($_FILES['design_file']['name']) && is_array($_FILES['design_file']['name'])) {
    // Loop through uploaded files
    for ($i = 0; $i < count($_FILES['design_file']['name']); $i++) {
        if ($_FILES['design_file']['error'][$i] !== UPLOAD_ERR_OK) {
            $uploadErrors = [
                UPLOAD_ERR_INI_SIZE   => "The uploaded file exceeds the upload_max_filesize directive in php.ini.",
                UPLOAD_ERR_FORM_SIZE  => "The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form.",
                UPLOAD_ERR_PARTIAL    => "The uploaded file was only partially uploaded.",
                UPLOAD_ERR_NO_FILE    => "No file was uploaded.",
                UPLOAD_ERR_NO_TMP_DIR => "Missing a temporary folder.",
                UPLOAD_ERR_CANT_WRITE => "Failed to write file to disk.",
                UPLOAD_ERR_EXTENSION  => "A PHP extension stopped the file upload.",
            ];
            $errors[] = $uploadErrors[$_FILES['design_file']['error'][$i]] ?? 'Unknown upload error.';
            error_log('File upload error: ' . htmlspecialchars($uploadErrors[$_FILES['design_file']['error'][$i]] ?? 'Unknown upload error', ENT_QUOTES, 'UTF-8'));
            continue;
        }

        $fileName    = strtolower($_FILES['design_file']['name'][$i]);
        $fileSize    = $_FILES['design_file']['size'][$i];
        $fileTemp    = $_FILES['design_file']['tmp_name'][$i];
        $fileExt     = pathinfo($fileName, PATHINFO_EXTENSION);

        if (!in_array($fileExt, ['dwg', 'dwt', 'dxf', 'dws', 'dwf', 'dwfx', 'dxb', 'pdf', 'stl', 'jpeg', 'jpg', 'png', 'tiff', 'bmp'], true)) {
            $errors[] = "File type '{$fileExt}' is not allowed for file '{$fileName}'.";
            error_log("Invalid file type for file {$fileName}");
            continue;
        }

        if ($fileSize > $maxBytes) {
            $errors[] = "File '{$fileName}' exceeds the maximum size of 25MB.";
            error_log("File size too large for file {$fileName}");
            continue;
        }

        $uploadDir = __DIR__ . '/uploads';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $newFileName = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $fileExt;
        if (!move_uploaded_file($fileTemp, $uploadDir . '/' . $newFileName)) {
            $errors[] = "Failed to save file '{$fileName}' to the server.";
            error_log("Failed to move uploaded file '{$fileName}' to {$uploadDir}/{$newFileName}");
        } else {
            $uploadedFiles[] = $newFileName;
        }
    }
} else {
    $errors[] = "No file was uploaded or an invalid form configuration.";
    error_log("File upload issue: No files detected for 'design_file[]'.");
}

// If there were any validation or upload errors, show them and stop
if ($errors) {
    http_response_code(400);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Request a Quote – Error</title>
    </head>
    <body>
        <h1>There was a problem with your request</h1>
        <ul>
            <?php foreach ($errors as $e): ?>
                <li><?php echo htmlspecialchars($e, ENT_QUOTES, 'UTF-8'); ?></li>
            <?php endforeach; ?>
        </ul>
        <p><a href="request-quote.html">Go back to the quote form</a></p>
    </body>
    </html>
    <?php
    exit;
}

// Success section below:
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Quote Request Received</title>
</head>
<body>
  <h1>Thank you!</h1>
  <p>Your quote request has been received. We will email you a quote shortly.</p>
  <p>You can close this window or go back to the <a href="index.html">home page</a>.</p>
</body>
</html>