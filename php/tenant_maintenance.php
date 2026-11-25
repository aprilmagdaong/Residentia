
<?php
session_start();

require_once __DIR__ . '/index_config.php';

// TENANT ACCESS ONLY
if (
    !isset($_SESSION['role']) ||
    strtolower(trim($_SESSION['role'])) !== 'tenant'
) {
    header('Location: ../index.php');
    exit;
}

$tenant_id = (int)($_SESSION['tenant_id'] ?? 0);

if ($tenant_id <= 0) {
    die('Tenant account not found. Please log in again.');
}

$pdo = db();

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function fdate($date): string {
    return $date ? date('M j, Y', strtotime($date)) : '—';
}

// CSRF TOKEN
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$success = '';
$error = '';

/* =========================================
   GET TENANT'S CURRENT ROOM AND OWNER
========================================= */

$stmt = $pdo->prepare("
    SELECT
        ra.room_id,
        r.room_number,
        bh.owner_id,
        bh.bh_name
    FROM room_assignment ra
    INNER JOIN room r ON r.room_id = ra.room_id
    INNER JOIN boarding_house bh ON bh.bh_id = r.bh_id
    WHERE ra.tenant_id = ?
      AND ra.moveout_date IS NULL
    LIMIT 1
");

$stmt->execute([$tenant_id]);
$currentRoom = $stmt->fetch(PDO::FETCH_ASSOC);

/* =========================================
   SUBMIT MAINTENANCE REQUEST
========================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

        if (
            !isset($_POST['csrf_token']) ||
            !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
        ) {
            throw new Exception('Invalid request. Please refresh the page.');
        }

        $title = trim($_POST['request_title'] ?? '');
        $description = trim($_POST['request_description'] ?? '');

        if ($title === '' || $description === '') {
            throw new Exception('Please complete the request title and description.');
        }

        if (!$currentRoom) {
            throw new Exception('You must have an active room assignment before submitting a maintenance request.');
        }

        $stmt = $pdo->prepare("
            INSERT INTO maintenance_request (
                tenant_id,
                room_id,
                owner_id,
                request_title,
                request_description,
                request_date,
                status,
                completion_date
            )
            VALUES (?, ?, ?, ?, ?, CURDATE(), 'Pending', NULL)
        ");

        $stmt->execute([
            $tenant_id,
            $currentRoom['room_id'],
            $currentRoom['owner_id'],
            $title,
            $description
        ]);

        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        header('Location: tenant_maintenance.php?submitted=1');
        exit;

    } catch (PDOException $ex) {

        error_log($ex->getMessage());
        $error = 'Unable to submit your request. Please try again.';

    } catch (Exception $ex) {

        $error = $ex->getMessage();
    }
}

if (isset($_GET['submitted'])) {
    $success = 'Your maintenance request has been submitted successfully.';
}

/* =========================================
   GET ONLY THIS TENANT'S REQUESTS
========================================= */

$stmt = $pdo->prepare("
    SELECT
        mr.request_id,
        mr.request_title,
        mr.request_description,
        mr.request_date,
        mr.status,
        mr.completion_date,
        r.room_number
    FROM maintenance_request mr
    LEFT JOIN room r ON r.room_id = mr.room_id
    WHERE mr.tenant_id = ?
    ORDER BY mr.request_id DESC
");

$stmt->execute([$tenant_id]);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================================
   REQUEST COUNTS
========================================= */

$counts = [
    'Pending' => 0,
    'In Progress' => 0,
    'Resolved' => 0,
    'Cancelled' => 0
];

foreach ($requests as $request) {

    if (isset($counts[$request['status']])) {
        $counts[$request['status']]++;
    }
}

$username = $_SESSION['username'] ?? 'Tenant';
$currentDate = date('l, F j, Y');

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Maintenance | Residentia</title>

    <!-- SHARED CSS -->
    <link rel="stylesheet" href="../css/owner_db.css?v=4">
    <link rel="stylesheet" href="../css/tenant_db.css?v=3">
    <link rel="stylesheet" href="../css/tenant_portal.css?v=2">
    <link rel="stylesheet" href="../css/owner_ui.css?v=1">

    <!-- MAINTENANCE PAGE CSS -->
    <link rel="stylesheet" href="../css/tenant_maintenance.css?v=2">
</head>

<body>

<div class="layout">

    <!-- SIDEBAR -->
    <aside class="sidebar">

        <picture>
            <img
                class="logo"
                src="../image/db_logo.png"
                alt="Residentia Logo"
            >
        </picture>

        <nav class="nav">
            <a href="tenant_db.php">Dashboard</a>
            <a href="tenant_billing.php">Billing &amp; Payments</a>
            <a href="tenant_maintenance.php" class="active">Maintenance</a>
            <a href="tenant_announcement.php">Announcements</a>
        </nav>

        <a class="logout" href="../logout.php">Log out</a>

    </aside>

    <!-- MAIN CONTENT -->
    <main class="main">

        <div class="tenant-main tenant-maintenance-page">

            <!-- HEADER -->
            <header class="tenant-header">
                <div>
                    <h1>Maintenance</h1>
                    <p><?= e($currentDate) ?> · Welcome, <?= e($username) ?></p>
                </div>
            </header>

            <!-- SUCCESS MESSAGE -->
            <?php if ($success): ?>
                <div class="tenant-alert success">
                    <?= e($success) ?>
                </div>
            <?php endif; ?>

            <!-- ERROR MESSAGE -->
            <?php if ($error): ?>
                <div class="tenant-alert error">
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <!-- CURRENT ROOM -->
            <?php if ($currentRoom): ?>

                <section class="tenant-note">
                    <strong>Current Room:</strong>
                    <?= e($currentRoom['room_number']) ?>

                    <span>
                        <?= e($currentRoom['bh_name']) ?>
                    </span>
                </section>

            <?php else: ?>

                <section class="tenant-alert error">
                    You currently have no active room assignment.
                    Please contact your owner before submitting a maintenance request.
                </section>

            <?php endif; ?>

            <!-- REQUEST SUMMARY -->
            <section class="tenant-stats">

                <div class="tenant-panel maintenance-stat pending-stat">
                    <span>Pending</span>
                    <h2><?= $counts['Pending'] ?></h2>
                </div>

                <div class="tenant-panel maintenance-stat in-progress-stat">
                    <span>In Progress</span>
                    <h2><?= $counts['In Progress'] ?></h2>
                </div>

                <div class="tenant-panel maintenance-stat resolved-stat">
                    <span>Resolved</span>
                    <h2><?= $counts['Resolved'] ?></h2>
                </div>

            </section>

            <!-- TWO-COLUMN LAYOUT -->
            <div class="tenant-maintenance-grid">

                <!-- LEFT: SUBMIT REQUEST -->
                <section class="tenant-panel maintenance-submit-panel">

                    <div class="tenant-panel-header">
                        <div>
                            <h2>Submit a Maintenance Request</h2>
                            <p>Describe the issue in your room or boarding house.</p>
                        </div>
                    </div>

                    <form method="POST" class="maintenance-form">

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= e($_SESSION['csrf_token']) ?>"
                        >

                        <div class="tenant-field">
                            <label for="request_title">Request Title</label>

                            <input
                                type="text"
                                id="request_title"
                                name="request_title"
                                placeholder="Example: Leaking bathroom faucet"
                                required
                            >
                        </div>

                        <div class="tenant-field">
                            <label for="request_description">Description</label>

                            <textarea
                                id="request_description"
                                name="request_description"
                                rows="7"
                                placeholder="Describe the maintenance issue..."
                                required
                            ></textarea>
                        </div>

                        <button
                            type="submit"
                            class="btn"
                            <?= !$currentRoom ? 'disabled' : '' ?>
                        >
                            Submit Request
                        </button>

                    </form>

                </section>

                <!-- RIGHT: REQUEST HISTORY -->
                <section class="tenant-panel maintenance-history-panel">

                    <div class="tenant-panel-header">
                        <div>
                            <h2>My Maintenance Requests</h2>
                            <p>Monitor your submitted requests and their status.</p>
                        </div>

                        <span class="request-count">
                            <?= count($requests) ?> Requests
                        </span>



                    </div>

                    <?php if (empty($requests)): ?>

                        <div class="tenant-note maintenance-empty">
                            <h3>No maintenance requests yet</h3>
                            <p>Your submitted maintenance requests will appear here.</p>
                        </div>

                    <?php else: ?>

                        <div class="tenant-request-list">

                            <?php foreach ($requests as $request): ?>

                                <?php
                                $statusClass = strtolower(
                                    str_replace(' ', '-', $request['status'])
                                );
                                ?>

                                <article class="tenant-request-item">

                                    <div class="tenant-request-header">

                                        <div>
                                            <h3>
                                                <?= e($request['request_title']) ?>
                                            </h3>

                                            <small>
                                                Request #<?= (int)$request['request_id'] ?>
                                            </small>
                                        </div>

                                        <span class="maintenance-status <?= e($statusClass) ?>">
                                            <?= e($request['status']) ?>
                                        </span>

                                    </div>

                                    <p class="tenant-request-description">
                                        <?= nl2br(e($request['request_description'])) ?>
                                    </p>

                                    <div class="tenant-request-details">

                                        <span>
                                            <strong>Room:</strong>
                                            <?= e($request['room_number'] ?? '—') ?>
                                        </span>

                                        <span>
                                            <strong>Submitted:</strong>
                                            <?= e(fdate($request['request_date'])) ?>
                                        </span>

                                        <?php if (!empty($request['completion_date'])): ?>
                                            <span>
                                                <strong>Completed:</strong>
                                                <?= e(fdate($request['completion_date'])) ?>
                                            </span>
                                        <?php endif; ?>

                                    </div>

                                </article>

                            <?php endforeach; ?>

                        </div>

                    <?php endif; ?>

                </section>

            </div>

        </div>

    </main>

</div>

</body>
</html>
