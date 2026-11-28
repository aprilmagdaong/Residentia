<?php
date_default_timezone_set('Asia/Manila');
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

if (!function_exists('e')) {
    function e($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('fdate')) {
    function fdate($date): string {
        return $date ? date('M j, Y', strtotime($date)) : '—';
    }
}

// CSRF TOKEN
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$success = '';
$error = '';
$title = '';
$description = '';

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
    <link rel="stylesheet" href="../css/owner_db.css?v=5">
    <link rel="stylesheet" href="../css/tenant_db.css?v=5">
    <link rel="stylesheet" href="../css/tenant_portal.css?v=3">
    <link rel="stylesheet" href="../css/owner_ui.css?v=11">

    <!-- MAINTENANCE PAGE CSS -->
    <link rel="stylesheet" href="../css/tenant_maintenance.css?v=8">
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
            <a href="tenant_db.php" class="<?= $page === 'dashboard' ? 'active' : '' ?>">
                <svg class="nav-icon" width="16" height="16" style="width:16px;height:16px;min-width:16px;max-width:16px;flex:0 0 16px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <rect x="3" y="3" width="7" height="9" rx="1"></rect>
                    <rect x="14" y="3" width="7" height="5" rx="1"></rect>
                    <rect x="14" y="12" width="7" height="9" rx="1"></rect>
                    <rect x="3" y="16" width="7" height="5" rx="1"></rect>
                </svg>
                <span>Dashboard</span>
            </a>

            <a href="tenant_billing.php" class="<?= $page === 'billing' ? 'active' : '' ?>">
                <svg class="nav-icon" width="16" height="16" style="width:16px;height:16px;min-width:16px;max-width:16px;flex:0 0 16px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <rect x="2" y="5" width="20" height="14" rx="2"></rect>
                    <line x1="2" y1="10" x2="22" y2="10"></line>
                </svg>
                <span>Billing &amp; Payments</span>
            </a>

            <a href="tenant_maintenance.php" class="<?= $page === 'maintenance' ? 'active' : '' ?>">
                <svg class="nav-icon" width="16" height="16" style="width:16px;height:16px;min-width:16px;max-width:16px;flex:0 0 16px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"></path>
                </svg>
                <span>Maintenance</span>
            </a>

            <a href="tenant_announcement.php" class="<?= $page === 'announcements' ? 'active' : '' ?>">
                <svg class="nav-icon" width="16" height="16" style="width:16px;height:16px;min-width:16px;max-width:16px;flex:0 0 16px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M3 11l19-9v20L3 13v-2z"></path>
                    <path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"></path>
                </svg>
                <span>Announcements</span>
            </a>

            <a href="tenant_policies.php" class="<?= $page === 'policies' ? 'active' : '' ?>">
                <svg class="nav-icon" width="16" height="16" style="width:16px;height:16px;min-width:16px;max-width:16px;flex:0 0 16px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                    <polyline points="14 2 14 8 20 8"></polyline>
                    <line x1="16" y1="13" x2="8" y2="13"></line>
                    <line x1="16" y1="17" x2="8" y2="17"></line>
                </svg>
                <span>Policies &amp; Rules</span>
            </a>

            <a href="tenant_profile.php" class="<?= ($page ?? '') === 'profile' ? 'active' : '' ?>">
                <svg class="nav-icon" width="16" height="16" style="width:16px;height:16px;min-width:16px;max-width:16px;flex:0 0 16px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="8" r="4"></circle>
                    <path d="M5 21a7 7 0 0 1 14 0"></path>
                </svg>
                <span>Profile</span>
            </a>
        </nav>

        <a class="logout" href="../logout.php" style="display: flex; align-items: center; gap: 10px;">
            <svg class="nav-icon" width="16" height="16" style="width:16px;height:16px;min-width:16px;max-width:16px;flex:0 0 16px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                <polyline points="16 17 21 12 16 7"></polyline>
                <line x1="21" y1="12" x2="9" y2="12"></line>
            </svg>
            <span>Log out</span>
        </a>
    </aside>

    <!-- MAIN CONTENT -->
    <main class="main">

        <div class="tenant-main tenant-maintenance-page">

            <!-- HEADER -->
            <header class="tenant-header page-header-banner">
                <div>
                    <h1>Maintenance</h1>
                    <p>Submit repair requests and track the status of your maintenance request.</p>
                </div>
                <button
                    type="button"
                    class="maintenance-add-trigger"
                    id="openMaintenanceRequest"
                    <?= !$currentRoom ? 'disabled' : '' ?>
                >
                    + Add Request
                </button>
            </header>

            <?php if ($success): ?>
                <dialog class="tenant-success-dialog" id="tenantSuccessDialog" aria-labelledby="tenantSuccessTitle" aria-describedby="tenantSuccessMessage">
                    <h2 id="tenantSuccessTitle">Request submitted</h2>
                    <p id="tenantSuccessMessage"><?= e($success) ?></p>
                    <form method="dialog">
                        <button type="submit">OK</button>
                    </form>
                </dialog>
            <?php endif; ?>

            <?php if (!$currentRoom): ?>
                <section class="tenant-alert error" role="alert">
                    You currently have no active room assignment.
                    Please contact your owner before submitting a maintenance request.
                </section>
            <?php endif; ?>

            <div class="tenant-maintenance-grid">

                <section class="tenant-panel maintenance-history-panel">

                    <div class="tenant-panel-header">
                        <div>
                            <h2>My Maintenance Requests</h2>
                            <p>Monitor your submitted requests and their status.</p>
                        </div>

                        <span class="request-count">
                            <?= count($requests) ?> <?= count($requests) === 1 ? 'Request' : 'Requests' ?>
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

            <dialog class="maintenance-request-dialog" id="maintenanceRequestDialog" aria-labelledby="maintenanceRequestTitle">
                <div class="maintenance-dialog-header">
                    <div>
                        <h2 id="maintenanceRequestTitle">Submit a Maintenance Request</h2>
                        <p>Describe the issue in your room or boarding house.</p>
                    </div>
                    <button type="button" class="maintenance-dialog-close" data-close-maintenance aria-label="Close request form">&times;</button>
                </div>

                <?php if ($error): ?>
                    <div class="tenant-alert error maintenance-dialog-error" role="alert">
                        <?= e($error) ?>
                    </div>
                <?php endif; ?>

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
                            value="<?= e($title) ?>"
                            placeholder="Example: Leaking bathroom faucet"
                            required
                        >
                    </div>

                    <div class="tenant-field">
                        <label for="request_description">Description</label>
                        <textarea
                            id="request_description"
                            name="request_description"
                            rows="5"
                            placeholder="Describe the maintenance issue..."
                            required
                        ><?= e($description) ?></textarea>
                    </div>

                    <div class="maintenance-dialog-actions">
                        <button type="button" class="maintenance-cancel" data-close-maintenance>Cancel</button>
                        <button
                            type="submit"
                            class="btn"
                            <?= !$currentRoom ? 'disabled' : '' ?>
                        >
                            Submit Request
                        </button>
                    </div>
                </form>
            </dialog>

        </div>

    </main>

</div>

<script>
(function () {
    var dialog = document.getElementById('maintenanceRequestDialog');
    var successDialog = document.getElementById('tenantSuccessDialog');
    var openButton = document.getElementById('openMaintenanceRequest');

    if (successDialog) {
        successDialog.showModal();
    }

    if (openButton) {
        openButton.addEventListener('click', function () {
            dialog.showModal();
        });
    }

    document.querySelectorAll('[data-close-maintenance]').forEach(function (button) {
        button.addEventListener('click', function () {
            dialog.close();
        });
    });

    dialog.addEventListener('click', function (event) {
        if (event.target === dialog) {
            dialog.close();
        }
    });

    <?php if ($error): ?>
    dialog.showModal();
    <?php endif; ?>
})();
</script>

</body>
</html>