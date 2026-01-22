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
$uploadedFileName = null;

if (!empty($_FILES['design_file']['name'])) {
    $file = $_FILES['design_file'];
    $maxBytes = 25 * 1024 * 1024;

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_OK         => "There is no error, the file uploaded successfully.",
            UPLOAD_ERR_INI_SIZE   => "The uploaded file exceeds the upload_max_filesize directive in php.ini.",
            UPLOAD_ERR_FORM_SIZE  => "The uploaded file exceeds the MAX_FILE_SIZE directive specified in the HTML form.",
            UPLOAD_ERR_PARTIAL    => "The uploaded file was only partially uploaded.",
            UPLOAD_ERR_NO_FILE    => "No file was uploaded.",
            UPLOAD_ERR_NO_TMP_DIR => "Missing a temporary folder.",
            UPLOAD_ERR_CANT_WRITE => "Failed to write file to disk.",
            UPLOAD_ERR_EXTENSION  => "A PHP extension stopped the file upload.",
        ];
        $uploadError = $uploadErrors[$file['error']] ?? 'Unknown upload error.';
        error_log('File Error: ' . $uploadError);
        $errors[] = 'File upload failed: ' . htmlspecialchars($uploadError, ENT_QUOTES, 'UTF-8');
    } elseif ($file['size'] > $maxBytes) {
        $errors[] = 'Design file is too large (max 25MB).';
    } else {
        $allowedExts = ['dwg','dwt','dxf','dws','dwf','dwfx','dxb','pdf','stl','jpeg','jpg','png','tiff','bmp'];
        $name        = strtolower($file['name']);
        $ext         = pathinfo($name, PATHINFO_EXTENSION);

        if (!in_array($ext, $allowedExts, true)) {
            $errors[] = 'File type not allowed.';
        } else {
            $uploadDir = __DIR__ . '/uploads';
            if (!is_dir($uploadDir)) {
                if (mkdir($uploadDir, 0755, true)) {
                    error_log('Uploads directory created: ' . $uploadDir);
                } else {
                    $errors[] = 'Failed to create upload directory.';
                    error_log('Failed to create upload directory: ' . $uploadDir);
                }
            }

            // Create unique name for file
            $uploadedFileName = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $targetPath       = $uploadDir . '/' . $uploadedFileName;

            if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                $errors[] = 'Failed to save uploaded file.';
                error_log("Failed to move uploaded file to target path: {$targetPath}");
            }
        }
    }
}

// If there were any validation or upload errors, stop and show them
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

// Remaining part of script
// Database insertion logic here (as provided earlier).
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