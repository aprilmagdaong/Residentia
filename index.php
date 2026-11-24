

<?php
session_start();
require_once __DIR__ . '/php/index_config.php';

// Redirect if already logged in
if (!empty($_SESSION['role'])) {
    if ($_SESSION['role'] === 'tenant') {
        header('Location: php/tenant_db.php');
        exit;
    }

    if ($_SESSION['role'] === 'owner') {
        header('Location: php/owner_db.php');
        exit;
    }
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter your username and password.';
    } else {

        try {
            // Connect to Residentia database
            $pdo = db();

            // Find account
            $stmt = $pdo->prepare(
                "SELECT account_id, tenant_id, owner_id,
                        username, password, user_role
                 FROM accounts
                 WHERE username = ?
                 LIMIT 1"
            );

            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // Verify password
            $storedPassword = $user['password'] ?? '';

            $passwordValid = $user && (
                password_verify($password, $storedPassword) ||
                hash_equals($storedPassword, $password)
            );

            if (!$passwordValid) {
                $error = 'Incorrect username or password.';
            } else {

                // Check account role
                $role = strtolower(trim($user['user_role']));

                if (!in_array($role, ['owner', 'tenant'], true)) {
                    $error = 'This account does not have login access.';
                } else {

                    // Hash plain-text password after successful login
                    if (password_get_info($storedPassword)['algo'] === null) {

                        $hashedPassword = password_hash(
                            $password,
                            PASSWORD_DEFAULT
                        );

                        $update = $pdo->prepare(
                            "UPDATE accounts
                             SET password = ?
                             WHERE account_id = ?"
                        );

                        $update->execute([
                            $hashedPassword,
                            $user['account_id']
                        ]);
                    }

                    // Create session
                    session_regenerate_id(true);

                    $_SESSION['account_id'] = $user['account_id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = $role;
                    $_SESSION['tenant_id'] = $user['tenant_id'];
                    $_SESSION['owner_id'] = $user['owner_id'];

                    // Redirect to correct dashboard
                    if ($role === 'owner') {
                        header('Location: php/owner_db.php');
                    } else {
                        header('Location: php/tenant_db.php');
                    }

                    exit;
                }
            }

        } catch (PDOException $e) {
            error_log($e->getMessage());
            $error = 'Database connection error. Please try again.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Residentia – Login</title>

    <link rel="stylesheet" href="css/style.css">
</head>

<body>

    <!-- Full Screen Background -->
    <div class="bg" aria-hidden="true"></div>

    <!-- Centered Login Container -->
    <main class="page">

        <section class="login-box">

            <!-- Residentia Logo -->
            <img
                class="logo"
                src="image/slogo.png"
                alt="Residentia Boarding House"
                draggable="false"
            >

            <!-- Login Form -->
            <form
                class="card"
                method="post"
                action="index.php"
                id="loginForm"
                novalidate
            >

                <h1>Welcome back</h1>

                <p class="sub">
                    Log in to your owner or tenant account.
                </p>

                <!-- Error Message -->
                <div id="msg" class="msg" role="alert">
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </div>

                <!-- Username -->
                <div class="field">

                    <label for="user">Username</label>

                    <input
                        id="user"
                        name="username"
                        type="text"
                        autocomplete="username"
                        placeholder="Enter your username"
                        value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>"
                    >

                </div>

                <!-- Password -->
                <div class="field">

                    <label for="pass">Password</label>

                    <div class="pw">

                        <input
                            id="pass"
                            name="password"
                            type="password"
                            autocomplete="current-password"
                            placeholder="Enter your password"
                        >

                        <button
                            type="button"
                            id="toggle"
                            aria-label="Show password"
                        >
                            Show
                        </button>

                    </div>

                </div>

                <!-- Login Button -->
                <button
                    class="login"
                    id="loginBtn"
                    type="submit"
                >
                    Log in
                </button>

                <!-- Login Note -->
                <p class="note">
                    Tenant accounts are created by the owner.
                    Ask your owner if you can't log in.
                </p>

            </form>

        </section>

    </main>

    <script src="js/script.js"></script>

</body>
</html>
