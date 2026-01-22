<?php
// Initialize variables with empty values or set them to previously submitted data
$first_name = $_POST['first_name'] ?? '';
$last_name = $_POST['last_name'] ?? '';
$email = $_POST['email'] ?? '';
$phone = $_POST['phone'] ?? '';
$street = $_POST['street'] ?? '';
$city = $_POST['city'] ?? '';
$postal_code = $_POST['postal_code'] ?? '';
$building_type = $_POST['building_type'] ?? '';
$material = $_POST['material'] ?? '';
$surface_finish = $_POST['surface_finish'] ?? '';
$thickness = $_POST['thickness'] ?? '';
$quantity = $_POST['quantity'] ?? 1;
$comments = $_POST['comments'] ?? '';

// Initialize error message as empty
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // SERVER-SIDE VALIDATION
    $errors = [];
    if (empty($first_name)) {
        $errors[] = "First Name is required.";
    }
    if (empty($last_name)) {
        $errors[] = "Last Name is required.";
    }
    if (empty($email)) {
        $errors[] = "Email Address is required.";
    }
    if (empty($phone) || !preg_match('/^[0-9]{9}$/', $phone)) {
        $errors[] = "A valid Mobile Number is required.";
    }
    if (empty($street)) {
        $errors[] = "Street Address is required.";
    }
    if (empty($city)) {
        $errors[] = "City is required.";
    }
    if (empty($postal_code) || !preg_match('/^\d{4}$/', $postal_code)) {
        $errors[] = "A valid Postal Code is required.";
    }
    if (empty($building_type)) {
        $errors[] = "Building Type is required.";
    }
    if (empty($material)) {
        $errors[] = "Material selection is required.";
    }
    if (empty($quantity) || $quantity < 1) {
        $errors[] = "Quantity must be at least 1.";
    }

    if (count($errors) > 0) {
        // Store error messages in $error_message
        $error_message = implode('<br>', $errors);
    } else {
        // If no errors, process form (redirect to thank-you page)
        header('Location: thank-you.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Request a Quote | Bend Cut Send</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="styles.css">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@500&family=Roboto:wght@400;500&display=swap" rel="stylesheet">
</head>
<body>

<!-- Navbar -->
<nav class="navbar">
  <div class="nav-inner">
    <a href="index.html" class="nav-logo">Bend Cut Send</a>
    <div class="nav-links">
      <a href="index.html">Home</a>
      <a href="how-it-works.html">How it works</a>
      <a href="metals.html">Metals Available</a>
      <a href="qa.html">Q&amp;A</a>
      <a href="request-quote.html" class="active">Request a Quote</a>
      <a href="ask-question.html">Contact Us/Ask a Question</a>
    </div>
  </div>
</nav>

<main class="container">
  <h1>Request a Quote</h1>

  <!-- Display Error Message -->
  <?php if (!empty($error_message)): ?>
    <div style="color: red; background: #ffe4e6; padding: 0.75rem; border-radius: 8px; margin-bottom: 1.5rem;">
      <strong>Error:</strong>
      <div><?php echo $error_message; ?></div>
    </div>
  <?php endif; ?>

  <form action="" method="post" enctype="multipart/form-data">
    <!-- First Name & Last Name -->
    <div class="form-row">
      <label>First Name
        <input type="text" name="first_name" value="<?php echo htmlspecialchars($first_name); ?>" placeholder="Enter your first name" required>
      </label>
      <label>Last Name
        <input type="text" name="last_name" value="<?php echo htmlspecialchars($last_name); ?>" placeholder="Enter your last name" required>
      </label>
    </div>

    <!-- Email & Mobile Number -->
    <div class="form-row">
      <label>Email Address
        <input type="email" name="email" value="<?php echo htmlspecialchars($email); ?>" placeholder="Enter your email address" required>
      </label>
      <label>Mobile Number
        <div class="phone-row">
          <div class="phone-code">+27</div>
          <input type="tel" class="phone-input" name="phone" value="<?php echo htmlspecialchars($phone); ?>" placeholder="Enter 9-digit mobile number" pattern="^[0-9]{9}$" required>
        </div>
      </label>
    </div>

    <!-- Address -->
    <div class="form-row">
      <label>Street Address
        <input type="text" name="street" value="<?php echo htmlspecialchars($street); ?>" placeholder="Street name and number" required>
      </label>
      <label>City
        <input type="text" name="city" value="<?php echo htmlspecialchars($city); ?>" placeholder="Your city" required>
      </label>
    </div>
    <div class="form-row">
      <label>Postal Code
        <input type="text" name="postal_code" value="<?php echo htmlspecialchars($postal_code); ?>" placeholder="e.g., 2191" pattern="\d{4}" inputmode="numeric" required>
      </label>
      <label>Building Type
        <select name="building_type" required>
          <option value="" disabled>-- Select building type --</option>
          <option value="House" <?php echo $building_type === 'House' ? 'selected' : ''; ?>>House</option>
          <option value="Apartment" <?php echo $building_type === 'Apartment' ? 'selected' : ''; ?>>Apartment</option>
          <option value="Office" <?php echo $building_type === 'Office' ? 'selected' : ''; ?>>Office</option>
        </select>
      </label>
    </div>

    <!-- Dropdown for Material, Surface Finish, Thickness -->
    <div class="dropdown-grid">
      <label>Material
        <select name="material" required>
          <option value="" disabled selected>-- Select material --</option>
          <option>Mild Steel</option>
          <option>3CR12 Stainless Steel</option>
          <option>430 Stainless Steel</option>
        </select>
      </label>
      <label>Surface Finish
        <select name="surface_finish" required>
          <option value="">-- Select surface finish --</option>
        </select>
      </label>
      <label>Thickness
        <select name="thickness" required>
          <option value="">-- Select thickness --</option>
        </select>
      </label>
    </div>

    <!-- Quantity -->
    <div class="form-row">
      <label>Quantity
        <input type="number" name="quantity" value="<?php echo htmlspecialchars($quantity); ?>" min="1" required>
      </label>
    </div>

    <!-- File Upload -->
    <label>Design Files (Max 5)
      <input type="file" name="design_file[]" multiple>
    </label>
    
    <!-- Comments -->
    <label>Comments
      <textarea name="comments" rows="4"><?php echo htmlspecialchars($comments); ?></textarea>
    </label>

    <!-- Submit Button -->
    <button type="submit">Submit Request</button>
  </form>
</main>
</body>
</html>