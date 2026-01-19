<?php
// request-quote-handler.php
require __DIR__ . '/config.php';

// Helper to get a POST field safely
function post($key, $default = '') {
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

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

// 2. Handle file upload (optional but recommended)
$uploadedFileName = null;

if (!empty($_FILES['design_file']['name'])) {
    $file     = $_FILES['design_file'];
    $maxBytes = 25 * 1024 * 1024;

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'There was an error uploading the design file.';
    } elseif ($file['size'] > $maxBytes) {
        $errors[] = 'Design file is too large (max 25MB).';
    } else {
        $allowedExts = [
            'dwg','dwt','dxf','dws','dwf','dwfx','dxb',
            'pdf','stl','jpeg','jpg','png','tiff','bmp'
        ];
        $name      = strtolower($file['name']);
        $ext       = pathinfo($name, PATHINFO_EXTENSION);

        if (!in_array($ext, $allowedExts, true)) {
            $errors[] = 'File type not allowed.';
        } else {
            // Store uploads in httpdocs/uploads (create folder and secure it if needed)
            $uploadDir = __DIR__ . '/uploads';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            // Unique name to avoid clashes
            $uploadedFileName = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $targetPath       = $uploadDir . '/' . $uploadedFileName;

            if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                $errors[] = 'Failed to save uploaded file.';
            }
        }
    }
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

// 3. Build combined address / details text
$fullName = trim($firstName . ' ' . $lastName);

$detailsText  = "Building type: {$buildingType}\n";
$detailsText .= "Street: {$street}\n";
$detailsText .= "City: {$city}\n";
$detailsText .= "Postal code: {$postalCode}\n\n";

$detailsText .= "Material: {$material}\n";
$detailsText .= "Surface finish: {$surfaceFinish}\n";
$detailsText .= "Thickness: {$thickness} mm\n";
$detailsText .= "Quantity: {$quantity}\n\n";

$detailsText .= "Comments:\n{$comments}\n";

if ($uploadedFileName) {
    $detailsText .= "\nDesign file: uploads/{$uploadedFileName}\n";
}

// 4. Generate quote number like BCS-2026-0001
$year = date('Y');
$stmt = $pdo->query("SELECT COUNT(*) FROM quotes WHERE YEAR(created_at) = $year");
$countThisYear = (int) $stmt->fetchColumn();
$next          = $countThisYear + 1;
$quoteNumber   = sprintf('BCS-%d-%04d', $year, $next);

// Client must NOT enter amount – start at 0, status 'draft'
$amount = 0.00;

// 5. Insert into quotes table
$sql = "INSERT INTO quotes
        (quote_number, client_name, client_email, client_phone, company_name,
         details, material, thickness, quantity, amount, status)
        VALUES
        (:quote_number, :client_name, :client_email, :client_phone, :company_name,
         :details, :material, :thickness, :quantity, :amount, 'draft')";

$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':quote_number' => $quoteNumber,
    ':client_name'  => $fullName,
    ':client_email' => $email,
    ':client_phone' => $phone,
    ':company_name' => null,   // you don't collect this; keep as NULL
    ':details'      => $detailsText,
    ':material'     => $material,
    ':thickness'    => $thickness,
    ':quantity'     => $quantity,
    ':amount'       => $amount,
]);

$quoteId = $pdo->lastInsertId();

// 6. Send notification email (with BCC to sent@bendcutsend.net)
$to      = 'quotes@bendcutsend.net'; // main recipient for new quote requests
$subject = "New quote request: {$quoteNumber}";

$body  = "A new quote request has been submitted.\n\n";
$body .= "Quote number: {$quoteNumber}\n";
$body .= "Name: {$fullName}\n";
$body .= "Email: {$email}\n";
$body .= "Phone: +27{$phone}\n\n";
$body .= $detailsText . "\n";

$headers  = "From: noreply@bendcutsend.net\r\n";
$headers .= "Reply-To: {$email}\r\n";
$headers .= "Bcc: sent@bendcutsend.net\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

@mail($to, $subject, $body, $headers);

// 7. Show thank‑you page to client
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
  <p>Your reference number is: <strong><?php echo htmlspecialchars($quoteNumber, ENT_QUOTES, 'UTF-8'); ?></strong></p>
  <p>You can close this window or go back to the <a href="index.html">home page</a>.</p>
</body>
</html>