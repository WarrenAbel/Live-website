<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Thank You | Bend Cut Send</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="styles.css">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600&family=Roboto:wght@400;500&display=swap" rel="stylesheet">
  <style>
    body {
      font-family: 'Roboto', sans-serif;
      background-color: #f3f4f6;
      margin: 0;
      padding: 0;
    }

    .container {
      max-width: 700px;
      margin: 3rem auto;
      padding: 2rem;
      background: white;
      border: 1px solid #e5e7eb;
      border-radius: 10px;
      box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
    }

    h1 {
      color: #1a202c;
    }

    .quote-ref {
      font-size: 1.25rem;
      font-weight: 600;
      color: #10b981; /* Green for success */
    }

    .home-link {
      display: inline-block;
      margin-top: 1rem;
      color: #2563eb;
      text-decoration: none;
    }

    .home-link:hover {
      text-decoration: underline;
    }
  </style>
</head>
<body>
<div class="container">
  <h1>Thank you!</h1>
  <p>Your quote request has been successfully submitted. We will email you with the details shortly.</p>
  <p>Your reference number is: <span class="quote-ref">
    <?php echo htmlspecialchars($_GET['quote_ref']); ?></span>
  </p>
  <a href="index.html" class="home-link">Return to Home Page</a>
</div>
</body>
</html>