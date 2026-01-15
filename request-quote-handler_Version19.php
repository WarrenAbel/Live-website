<?php
/**
 * request-quote-handler.php
 * Handler for Bend Cut Send "Request a Quote" form.
 * - Sends all form details to admin@bendcutsend.net
 * - Optionally attaches the uploaded design file (max 25 MB)
 * - Sends a confirmation email to the client
 * - Converts material / finish keys to human-readable labels in the email
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

// Only handle POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

// -----------------------------------------------------------------------------
// Collect form data (must match HTML field names)
// -----------------------------------------------------------------------------
$firstName    = isset($_POST['first_name']) ? trim($_POST['first_name']) : '';
$lastName     = isset($_POST['last_name'])  ? trim($_POST['last_name'])  : '';
$email        = isset($_POST['email'])      ? trim($_POST['email'])      : '';
$phone        = isset($_POST['phone'])      ? trim($_POST['phone'])      : '';
$street       = isset($_POST['street'])     ? trim($_POST['street'])     : '';
$city         = isset($_POST['city'])       ? trim($_POST['city'])       : '';
$buildingType = isset($_POST['building_type']) ? trim($_POST['building_type']) : '';
$postalCode   = isset($_POST['postal_code'])   ? trim($_POST['postal_code'])   : '';

$materialKey  = isset($_POST['material'])        ? trim($_POST['material'])        : '';
$finishKey    = isset($_POST['surface_finish'])  ? trim($_POST['surface_finish'])  : '';
$thickness    = isset($_POST['thickness'])       ? trim($_POST['thickness'])       : '';

$comments     = isset($_POST['comments'])        ? trim($_POST['comments'])        : '';
$quantityRaw  = isset($_POST['quantity'])        ? trim($_POST['quantity'])        : '';

// -----------------------------------------------------------------------------
// Basic validation & normalization
// -----------------------------------------------------------------------------

// Required
if ($firstName === '' || $lastName === '' || $email === '' || $phone === '') {
    http_response_code(400);
    echo 'First name, last name, email and phone number are required.';
    exit;
}

// Limit field lengths
$firstName    = mb_substr($firstName, 0, 100);
$lastName     = mb_substr($lastName, 0, 100);
$email        = mb_substr($email, 0, 320);
$phone        = mb_substr($phone, 0, 50);
$street       = mb_substr($street, 0, 255);
$city         = mb_substr($city, 0, 255);
$buildingType = mb_substr($buildingType, 0, 100);
$postalCode   = mb_substr($postalCode, 0, 20);
$materialKey  = mb_substr($materialKey, 0, 50);
$finishKey    = mb_substr($finishKey, 0, 50);
$thickness    = mb_substr($thickness, 0, 20);
$comments     = mb_substr($comments, 0, 5000);

// Email format
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo 'Please provide a valid email address.';
    exit;
}

// Sanitize email for header use
$safeEmailHeader = str_replace(["\r", "\n"], '', $email);

// Quantity: must be a positive integer, default 1 if missing or invalid
$quantity = (int)$quantityRaw;
if ($quantity < 1) {
    $quantity = 1;
}
if ($quantity > 1000000) {
    $quantity = 1000000;
}

// -----------------------------------------------------------------------------
// Map keys to human-readable labels (must match request-quote.html JS data)
// -----------------------------------------------------------------------------
$materialMap = [
    'mild_steel'      => 'Mild Steel',
    '3cr12'           => '3CR12 Stainless Steel',
    '430_ss'          => '430 Stainless Steel',
    '304_ss'          => '304 Stainless Steel',
    '316_ss'          => '316 Stainless Steel',
    'aluminium_1050'  => 'Aluminium 1050',
];

$finishMap = [
    // Mild Steel
    'cold_rolled' => 'Cold rolled',
    'hot_rolled'  => 'Hot rolled',
    'galvanised'  => 'Galvanised',

    // 3CR12
    '2d'          => '2D',
    'no1'         => 'No.1',

    // 430 / 304 / 316 etc.
    'ba'          => 'BA',
    '2b'          => '2B',
    'pvc_ba'      => 'PVC BA',
    '2b_pvc'      => '2B PVC',
    'no4_pvc'     => 'No.4 PVC',

    // Aluminium 1050
    'mill'        => 'Mill',
    'mill_pvc'    => 'Mill PVC',
];

// Human-readable labels (fallback to key if unknown)
$materialLabel = $materialKey !== '' && isset($materialMap[$materialKey])
    ? $materialMap[$materialKey]
    : ($materialKey !== '' ? $materialKey : '(none)');

$finishLabel = $finishKey !== '' && isset($finishMap[$finishKey])
    ? $finishMap[$finishKey]
    : ($finishKey !== '' ? $finishKey : '(none)');

$thicknessLabel = $thickness !== '' ? $thickness . ' mm' : '(none)';

// -----------------------------------------------------------------------------
// Handle optional file upload
// -----------------------------------------------------------------------------
$hasAttachment  = false;
$attachmentPath = '';
$attachmentName = '';
$attachmentType = '';
$maxBytes       = 25 * 1024 * 1024; // 25 MB

if (isset($_FILES['design_file']) && $_FILES['design_file']['error'] !== UPLOAD_ERR_NO_FILE) {
    $file = $_FILES['design_file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo 'There was a problem uploading your design file.';
        exit;
    }

    if ($file['size'] > $maxBytes) {
        http_response_code(400);
        echo 'File is too large. Maximum size is 25MB.';
        exit;
    }

    $allowedExt   = ['dwg','dwt','dxf','dws','dwf','dwfx','dxb','pdf','stl','jpeg','jpg','png','tiff','bmp'];
    $originalName = $file['name'] ?? 'attachment';
    $ext          = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if (!in_array($ext, $allowedExt, true)) {
        http_response_code(400);
        echo 'File type not allowed.';
        exit;
    }

    // Optional: basic MIME check if fileinfo is available
    $attachmentType = 'application/octet-stream';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detectedType = finfo_file($finfo, $file['tmp_name']);
            if ($detectedType) {
                $attachmentType = $detectedType;
            }
            finfo_close($finfo);
        }
    }

    $tmpDir   = sys_get_temp_dir();
    $safeName = preg_replace('/[^A-Za-z0-9_\.\-]/', '_', $originalName);
    $targetPath = $tmpDir . DIRECTORY_SEPARATOR . uniqid('quote_', true) . '_' . $safeName;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        http_response_code(500);
        echo 'Could not save uploaded file.';
        exit;
    }

    $hasAttachment  = true;
    $attachmentPath = $targetPath;
    $attachmentName = $originalName;
}

// -----------------------------------------------------------------------------
// Build email body to admin
// -----------------------------------------------------------------------------
$adminTo       = 'admin@bendcutsend.net';
$fromAddress   = 'no-reply@bendcutsend.net';

$adminSubject = 'New Quote Request from bendcutsend.net';

$bodyLines = [
    "A new quote request was submitted on bendcutsend.net:",
    "",
    "First Name:    {$firstName}",
    "Last Name:     {$lastName}",
    "Email:         {$email}",
    "Phone:         {$phone}",
    "Quantity:      {$quantity}",
    "",
    "Street:        {$street}",
    "City:          {$city}",
    "Building Type: {$buildingType}",
    "Postal Code:   {$postalCode}",
    "",
    "Material selection:",
    "  Material:       {$materialLabel}",
    "  Surface finish: {$finishLabel}",
    "  Thickness:      {$thicknessLabel}",
    "",
    "Comments:",
    $comments !== '' ? $comments : '(none)',
    "",
];

if ($hasAttachment) {
    $bodyLines[] = "A design file is attached: {$attachmentName}";
    $bodyLines[] = "";
}

$bodyLines[] = "----";
$bodyLines[] = "End of quote request.";

$plainBody = implode("\n", $bodyLines);

// -----------------------------------------------------------------------------
// Send email to admin (with or without attachment)
// -----------------------------------------------------------------------------
$adminHeaders = [];
$adminHeaders[] = "From: {$fromAddress}";
$adminHeaders[] = "Reply-To: {$safeEmailHeader}";
$adminHeaders[] = "X-Mailer: PHP/" . phpversion();

if ($hasAttachment && is_readable($attachmentPath)) {
    $boundary = "==Multipart_Boundary_x" . md5((string)time()) . "x";

    $adminHeaders[] = "MIME-Version: 1.0";
    $adminHeaders[] = "Content-Type: multipart/mixed; boundary=\"{$boundary}\"";

    $message  = "This is a multi-part message in MIME format.\r\n\r\n";
    $message .= "--{$boundary}\r\n";
    $message .= "Content-Type: text/plain; charset=\"UTF-8\"\r\n";
    $message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
    $message .= $plainBody . "\r\n\r\n";

    $fileContent        = file_get_contents($attachmentPath);
    $fileContentEncoded = chunk_split(base64_encode($fileContent));

    $message .= "--{$boundary}\r\n";
    $message .= "Content-Type: {$attachmentType}; name=\"{$attachmentName}\"\r\n";
    $message .= "Content-Transfer-Encoding: base64\r\n";
    $message .= "Content-Disposition: attachment; filename=\"{$attachmentName}\"\r\n\r\n";
    $message .= $fileContentEncoded . "\r\n\r\n";
    $message .= "--{$boundary}--\r\n";

    $adminMailResult = mail($adminTo, $adminSubject, $message, implode("\r\n", $adminHeaders));
} else {
    $adminHeaders[] = "Content-Type: text/plain; charset=\"UTF-8\"";
    $adminMailResult = mail($adminTo, $adminSubject, $plainBody, implode("\r\n", $adminHeaders));
}

// Clean up temp file
if ($hasAttachment && is_file($attachmentPath)) {
    @unlink($attachmentPath);
}

// -----------------------------------------------------------------------------
// If admin mail succeeded, send confirmation to client
// -----------------------------------------------------------------------------
if ($adminMailResult) {
    $clientTo      = $email;
    $clientSubject = 'We have received your quote request – Bend Cut Send';

    $clientLines = [
        "Hi {$firstName} {$lastName},",
        "",
        "Thank you for requesting a quote from Bend Cut Send.",
        "We have received your details and design file. Our team will review your request and email you a quotation as soon as possible.",
        "",
        "Summary of your request:",
        "------------------------------------------------------------",
        "Name:           {$firstName} {$lastName}",
        "Email:          {$email}",
        "Phone:          {$phone}",
        "Quantity:       {$quantity}",
        "Street:         {$street}",
        "City:           {$city}",
        "Building Type:  {$buildingType}",
        "Postal Code:    {$postalCode}",
        "",
        "Material:       {$materialLabel}",
        "Surface finish: {$finishLabel}",
        "Thickness:      {$thicknessLabel}",
        "",
        "Comments:",
        $comments !== '' ? $comments : '(none)',
        "------------------------------------------------------------",
        "",
        "If you need to make any changes or provide additional information, you can reply directly to this email.",
        "",
        "Kind regards,",
        "Bend Cut Send",
        "https://bendcutsend.net",
    ];

    $clientBody = implode("\n", $clientLines);

    $clientHeaders = [];
    $clientHeaders[] = "From: {$fromAddress}";
    $clientHeaders[] = "Reply-To: {$fromAddress}";
    $clientHeaders[] = "X-Mailer: PHP/" . phpversion();
    $clientHeaders[] = "Content-Type: text/plain; charset=\"UTF-8\"";

    // Fire-and-forget: we don't fail the whole request if this fails
    @mail($clientTo, $clientSubject, $clientBody, implode("\r\n", $clientHeaders));

    header('Location: /thank-you.html');
    exit;
} else {
    http_response_code(500);
    echo 'There was a problem sending your request. Please try again later.';
    exit;
}