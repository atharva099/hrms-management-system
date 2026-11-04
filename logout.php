<?php

require_once __DIR__ . '/includes/auth.php';

$request_method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($request_method !== 'GET' && $request_method !== 'POST') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Method not allowed.');
}

auth_start_session();
if (auth_current_employee_id() === null) {
    header('Location: index.php');
    exit();
}

if ($request_method === 'POST') {
    if (!csrf_validate_token($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('Invalid request.');
    }

    auth_logout();
    header('Location: index.php');
    exit();
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Confirm logout - CoreAxis CRM</title>
    <link rel="stylesheet" href="css/style.css?v=3">
    <style>
        .logout-confirmation { max-width: 440px; }
        .logout-confirmation h1 { font-size: 28px; line-height: 1.25; }
        .logout-confirmation .logout-message { margin: 18px 0 8px; line-height: 1.6; }
        .logout-confirmation form { margin: 0; }
        .logout-cancel {
            display: flex;
            justify-content: center;
            margin-top: 14px;
            padding: 10px;
            color: #1d4ed8;
            font-weight: 600;
            text-align: center;
            text-decoration: none;
        }
        .logout-cancel:hover { text-decoration: underline; }
        .logout-cancel:focus-visible { outline: 3px solid #93c5fd; outline-offset: 2px; }
    </style>
</head>
<body>
    <div class="login-container">
        <main class="login-box logout-confirmation" aria-labelledby="logout-title">
            <h1 id="logout-title">Sign out of CoreAxis CRM?</h1>
            <p class="subtitle logout-message">Your current session will end only after you confirm below.</p>
            <form method="post" action="logout.php">
                <?php echo csrf_field(); ?>
                <button type="submit">Log out</button>
            </form>
            <a class="logout-cancel" href="dashboard.php">Cancel and return to Dashboard</a>
        </main>
    </div>
</body>
</html>
