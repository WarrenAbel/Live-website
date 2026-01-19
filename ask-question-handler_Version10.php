<?php
/**
 * ask-question-handler.php
 * Handler for the "Ask a Question" form (no file upload).
 * - Sends the question details to admin@bendcutsend.net
 * - BCCs a copy to sent@bendcutsend.net for tracking
 * - Sends a confirmation email to the client
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

// Only handle POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo 'Method Not Allowed';
    exit;
}

// -----------------------------------------------------------------------------
// Collect form data (must match HTML field names)
// -----------------------------------------------------------------------------
$name    = isset($_POST['name'])    ? trim($_POST['name'])    : '';
$email   = isset($_POST['email'])   ? trim($_POST['email'])   : '';
$phone   = isset($_POST['phone'])   ? trim($_POST['phone'])   : '';
$subject = isset($_POST['subject']) ? trim($_POST['subject']) : '';
$message = isset($_POST['message']) ? trim($_POST['message']) : '';

// -----------------------------------------------------------------------------
// Basic validation & normalization
// -----------------------------------------------------------------------------

// Required fields
if ($name === '' || $email === '' || $message === '') {
    http_response_code(400); // Bad Request
    echo 'Name, email and your question are required.';
    exit;
}

// Limit lengths to avoid abuse
$name    = mb_substr($name, 0, 200);
$email   = mb_substr($email, 0, 320);
$phone   = mb_substr($phone, 0, 50);
$subject = mb_substr($subject, 0, 200);
$message = mb_substr($message, 0, 5000);

// Validate email format
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo 'Please provide a valid email address.';
    exit;
}

// Sanitize for header usage: strip CR/LF to prevent header injection
$safeEmailHeader = str_replace(["\r", "\n"], '', $email);

// -----------------------------------------------------------------------------
// Build email to admin
// -----------------------------------------------------------------------------
$adminTo       = 'admin@bendcutsend.net';
$fromAddress   = 'no-reply@bendcutsend.net';  // must exist in Plesk Mail
$adminSubject  = 'New Question from bendcutsend.net';

$adminBodyLines = [
    "A new question was submitted on bendcutsend.net:",
    "",
    "Name:    {$name}",
    "Email:   {$email}",
    "Phone:   " . ($phone !== '' ? $phone : '(not provided)'),
    "Subject: " . ($subject !== '' ? $subject : '(not specified)'),
    "",
    "Question:",
    $message !== '' ? $message : '(none)',
    "",
    "----",
    "End of question.",
];

$adminBody = implode("\n", $adminBodyLines);

$adminHeaders = [];
$adminHeaders[] = "From: {$fromAddress}";
$adminHeaders[] = "Reply-To: {$safeEmailHeader}";
$adminHeaders[] = "Bcc: sent@bendcutsend.net";
$adminHeaders[] = "X-Mailer: PHP/" . phpversion();
$adminHeaders[] = "Content-Type: text/plain; charset=\"UTF-8\"";

// -----------------------------------------------------------------------------
// Send email to admin (with BCC to sent@bendcutsend.net)
// -----------------------------------------------------------------------------
$adminMailResult = mail($adminTo, $adminSubject, $adminBody, implode("\r\n", $adminHeaders));

if ($adminMailResult) {
    // -------------------------------------------------------------------------
    // Send professional auto-reply to client (best-effort, no hard failure)
    // -------------------------------------------------------------------------
    $clientTo      = $email;
    $clientSubject = 'We have received your question – Bend Cut Send';

    $clientLines = [
        "Hi {$name},",
        "",
        "Thank you for contacting Bend Cut Send.",
        "We have received your question and a member of our team will review it and respond as soon as possible.",
        "",
        "Summary of your enquiry:",
        "------------------------------------------------------------",
        "Name:    {$name}",
        "Email:   {$email}",
        "Phone:   " . ($phone !== '' ? $phone : '(not provided)'),
        "Subject: " . ($subject !== '' ? $subject : '(not specified)'),
        "",
        "Your question:",
        $message !== '' ? $message : '(none)',
        "------------------------------------------------------------",
        "",
        "If you need to provide additional information or files, you can reply directly to this email.",
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

    // Fire-and-forget: we don't change the response if this fails.
    @mail($clientTo, $clientSubject, $clientBody, implode("\r\n", $clientHeaders));

    header('Location: /thank-you.html');
    exit;
} else {
    http_response_code(500);
    echo 'There was a problem sending your question. Please try again later.';
    exit;
}