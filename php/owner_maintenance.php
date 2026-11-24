<?php
session_start();

require_once __DIR__ . '/../php/index_config.php';

if (
    !isset($_SESSION['role']) ||
    strtolower(trim($_SESSION['role'])) !== 'owner'
) {
    header('Location: ../index.php');
    exit;
}

$owner_id = (int)($_SESSION['owner_id'] ?? 0);

if ($owner_id <= 0) {
    die('Owner ID is missing. Please log in again.');
}

$pdo = db();

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (
            !isset($_POST['csrf_token']) ||
            !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
        ) {
            throw new Exception('Invalid request. Please refresh the page.');
        }

        $requestId = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
        $status = trim($_POST['status'] ?? '');
        $allowedStatuses = ['Pending', 'In Progress', 'Resolved', 'Cancelled'];

        if (!$requestId) {
            throw new Exception('Invalid maintenance request.');
        }

        if (!in_array($status, $allowedStatuses, true)) {
            throw new Exception('Please select a valid status.');
        }

        $stmt = $pdo->prepare("
            SELECT mr.request_id
            FROM maintenance_request mr
            INNER JOIN tenant t ON t.tenant_id = mr.tenant_id
            INNER JOIN room_assignment ra ON ra.tenant_id = t.tenant_id
            INNER JOIN room r ON r.room_id = ra.room_id
            INNER JOIN boarding_house bh ON bh.bh_id = r.bh_id
            WHERE mr.request_id = ?
              AND bh.owner_id = ?
              AND ra.moveout_date IS NULL
            LIMIT 1
        ");
        $stmt->execute([$requestId, $owner_id]);

        if (!$stmt->fetch()) {
            throw new Exception('Maintenance request not found.');
        }

        $stmt = $pdo->prepare("
            UPDATE maintenance_request
            SET status = ?
            WHERE request_id = ?
        ");
        $stmt->execute([$status, $requestId]);

        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $success = 'Maintenance request status updated.';
    } catch (PDOException $ex) {
        $error = 'Database error: ' . $ex->getMessage();
    } catch (Exception $ex) {
        $error = $ex->getMessage();
    }
}

$stmt = $pdo->prepare("
    SELECT
        mr.request_id,
        mr.request_description,
        mr.status,
        t.first_name,
        t.middle_name,
        t.last_name,
        r.room_number,
        bh.bh_name
    FROM maintenance_request mr
    INNER JOIN tenant t ON t.tenant_id = mr.tenant_id
    INNER JOIN room_assignment ra ON ra.tenant_id = t.tenant_id
    INNER JOIN room r ON r.room_id = ra.room_id
    INNER JOIN boarding_house bh ON bh.bh_id = r.bh_id
    WHERE bh.owner_id = ?
      AND ra.moveout_date IS NULL
    ORDER BY mr.request_id DESC
");
$stmt->execute([$owner_id]);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

$counts = [
    'Pending' => 0,
    'In Progress' => 0,
    'Resolved' => 0
];

foreach ($requests as $request) {
    if (isset($counts[$request['status']])) {
        $counts[$request['status']]++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maintenance | Residentia</title>
    <link rel="stylesheet" href="../css/owner_db.css?v=4">
    <link rel="stylesheet" href="../css/owner_maintenance.css?v=1">
    <link rel="stylesheet" href="../css/owner_ui.css?v=1">
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <picture>
            <img class="logo" src="../image/db_logo.png" alt="Residentia Logo">
        </picture>

        <nav class="nav">
            <a href="owner_db.php">Dashboard</a>
            <a href="owner_rooms.php">Rooms</a>
            <a href="owner_tenants.php">Tenants</a>
            <a href="owner_billing.php">Billing &amp; Payments</a>
            <a href="owner_maintenance.php" class="active">Maintenance</a>
            <a href="owner_announcement.php">Announcements</a>
            <a href="owner_reports.php">Reports</a>
        </nav>

        <a class="logout" href="../logout.php">Log out</a>
    </aside>

    <main class="main">
        <div class="maintenance-page">
            <header class="maintenance-header">
                <div>
                    <h1>Maintenance Requests</h1>
                    <p>Review tenant repair requests and update their progress.</p>
                </div>
            </header>

            <?php if ($success): ?>
                <div class="maintenance-alert success"><?= e($success) ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="maintenance-alert error"><?= e($error) ?></div>
            <?php endif; ?>

            <section class="maintenance-summary">
                <div class="summary-card">
                    <span>Pending</span>
                    <h2><?= $counts['Pending'] ?></h2>
                </div>
                <div class="summary-card">
                    <span>In Progress</span>
                    <h2><?= $counts['In Progress'] ?></h2>
                </div>
                <div class="summary-card">
                    <span>Resolved</span>
                    <h2><?= $counts['Resolved'] ?></h2>
                </div>
            </section>

            <section class="maintenance-list">
                <div class="maintenance-section-heading">
                    <div>
                        <h2>Request List</h2>
                        <p>All maintenance requests from your tenants.</p>
                    </div>
                    <span class="maintenance-count"><?= count($requests) ?> request(s)</span>
                </div>

                <?php if (empty($requests)): ?>
                    <div class="maintenance-empty">
                        <h3>No maintenance requests</h3>
                        <p>Tenant maintenance requests will appear here.</p>
                    </div>
                <?php else: ?>
                    <div class="maintenance-table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Tenant</th>
                                    <th>Boarding House</th>
                                    <th>Room</th>
                                    <th>Request</th>
                                    <th>Status</th>
                                    <th>Update</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($requests as $request): ?>
                                <?php
                                $tenantName = trim(
                                    $request['first_name'] . ' ' .
                                    ($request['middle_name'] ? $request['middle_name'] . ' ' : '') .
                                    $request['last_name']
                                );
                                $statusClass = strtolower(str_replace(' ', '-', $request['status']));
                                ?>
                                <tr>
                                    <td><strong><?= e($tenantName) ?></strong></td>
                                    <td><?= e($request['bh_name']) ?></td>
                                    <td><?= e($request['room_number']) ?></td>
                                    <td class="maintenance-description">
                                        <?= nl2br(e($request['request_description'])) ?>
                                    </td>
                                    <td>
                                        <span class="maintenance-status <?= e($statusClass) ?>">
                                            <?= e($request['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <form method="POST" class="maintenance-status-form">
                                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="request_id" value="<?= (int)$request['request_id'] ?>">
                                            <select name="status" aria-label="Update request status">
                                                <option value="Pending" <?= $request['status'] === 'Pending' ? 'selected' : '' ?>>Pending</option>
                                                <option value="In Progress" <?= $request['status'] === 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                                                <option value="Resolved" <?= $request['status'] === 'Resolved' ? 'selected' : '' ?>>Resolved</option>
                                                <option value="Cancelled" <?= $request['status'] === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                            </select>
                                            <button type="submit">Save</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>
</div>
</body>
</html>
