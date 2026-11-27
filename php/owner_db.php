<?php
session_start();

require_once __DIR__ . '/../php/index_config.php';

/* =========================================
1. OWNER ACCESS PROTECTION
   ========================================= */

$role = strtolower(trim($_SESSION['role'] ?? ''));

if ($role !== 'owner') {
    header('Location: ../index.php');
    exit;
}

/* =========================================
2. HELPER FUNCTIONS
   ========================================= */

// Secure HTML output
function e($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Generate safe CSS class names
function statusClass($status): string {
    return strtolower(
        preg_replace('/[^a-zA-Z0-9]+/', '', trim((string) $status))
    );
}

// Execute a query that returns one value
function getValue(string $sql, array $params = [], $default = 0) {
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);

        $result = $stmt->fetchColumn();

        return ($result === false || $result === null)
            ? $default
            : $result;

    } catch (PDOException $ex) {
        error_log('Dashboard query error: ' . $ex->getMessage());
        return $default;
    }
}

// Execute a query that returns multiple rows
function getRows(string $sql, array $params = []): array {
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $ex) {
        error_log('Dashboard query error: ' . $ex->getMessage());
        return [];
    }
}

/* =========================================
3. DASHBOARD STATISTICS
   ========================================= */

// Total Rooms
$totalRooms = (int) getValue(
    "SELECT COUNT(*) FROM room"
);

// Occupied Rooms
$occupiedRooms = (int) getValue(
    "SELECT COUNT(DISTINCT room_id)
     FROM room_assignment
     WHERE moveout_date IS NULL"
);

// Available Rooms
$availableRooms = max($totalRooms - $occupiedRooms, 0);

// Active Tenants
$activeTenants = (int) getValue(
    "SELECT COUNT(*)
     FROM tenant
     WHERE status = 'Active'"
);

// Unpaid and Partial Bills
$unpaidPayments = (int) getValue(
    "SELECT COUNT(*)
     FROM billing
     WHERE payment_status IN ('Unpaid', 'Partial')"
);

// Monthly Income
$monthlyIncome = (float) getValue(
    "SELECT COALESCE(SUM(amount_paid), 0)
     FROM payment
     WHERE MONTH(payment_date) = MONTH(CURDATE())
     AND YEAR(payment_date) = YEAR(CURDATE())"
);

// Open Maintenance Requests
$openRepairs = (int) getValue(
    "SELECT COUNT(*)
     FROM maintenance_request
     WHERE status IN ('Pending', 'In Progress')"
);

// Occupancy Percentage
$occupancyRate = $totalRooms > 0
    ? round(($occupiedRooms / $totalRooms) * 100)
    : 0;

/* =========================================
4. UPCOMING PAYMENTS
   ========================================= */

$upcomingPayments = getRows(
    "SELECT
        CONCAT_WS(' ',
            t.first_name,
            t.middle_name,
            t.last_name
        ) AS full_name,
        GREATEST(
            COALESCE((SELECT SUM(bd.amount)
                      FROM billing_details bd
                      WHERE bd.billing_id = b.billing_id), 0)
- COALESCE((SELECT SUM(p.amount_paid)
                        FROM payment p
                        WHERE p.billing_id = b.billing_id), 0),
            0
        ) AS amount,
        b.due_date,
        b.payment_status AS status

     FROM billing b

     INNER JOIN tenant t
        ON b.tenant_id = t.tenant_id

     WHERE b.owner_id = ?
       AND b.due_date >= CURDATE()
       AND b.payment_status IN ('Unpaid', 'Partial')

     ORDER BY b.due_date ASC
     LIMIT 5",
    [$_SESSION['owner_id'] ?? 0]
);

/* =========================================
5. MAINTENANCE REQUESTS
   ========================================= */

$maintenanceRequests = getRows(
    "SELECT
        CONCAT_WS(' ',
            t.first_name,
            t.middle_name,
            t.last_name
        ) AS full_name,
        m.request_description AS description,
        m.status

     FROM maintenance_request m

     INNER JOIN tenant t
        ON m.tenant_id = t.tenant_id

     WHERE m.status IN ('Pending', 'In Progress')

     ORDER BY m.request_id DESC
     LIMIT 5"
);

/* =========================================
6. PAGE SETTINGS
   ========================================= */

$page = 'dashboard';
$username = $_SESSION['username'] ?? 'Owner';
$ownerId = (int) ($_SESSION['owner_id'] ?? 0);
$ownerDisplayName = $username;
$ownerFirstLast = $username;

if ($ownerId > 0) {
    try {
        $stmt = db()->prepare(
            "SELECT first_name, middle_name, last_name
             FROM owner
             WHERE owner_id = ?
             LIMIT 1"
        );
        $stmt->execute([$ownerId]);
        $ownerRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($ownerRow) {
            $first = trim((string) ($ownerRow['first_name'] ?? ''));
            $middle = trim((string) ($ownerRow['middle_name'] ?? ''));
            $last = trim((string) ($ownerRow['last_name'] ?? ''));

            $builtFull = trim($first . ' ' . ($middle !== '' ? $middle . ' ' : '') . $last);
            $builtShort = trim($first . ' ' . $last);

            if ($builtFull !== '') {
                $ownerDisplayName = $builtFull;
                $ownerFirstLast = $builtShort !== '' ? $builtShort : $builtFull;
            }
        }
    } catch (PDOException $ex) {
        error_log('Owner profile query error: ' . $ex->getMessage());
    }
}

$currentDate = date('l, F j, Y');
$hour = (int) date('G');
$greetingText = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

$initials = '';
foreach (array_slice(preg_split('/[\s._-]+/', trim((string) $ownerFirstLast)), 0, 2) as $part) {
    if ($part !== '') {
        $initials .= strtoupper(substr($part, 0, 1));
    }
}
if ($initials === '') {
    $initials = 'OW';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Owner Dashboard | Residentia</title>

    <!-- Google Font -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link
        href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap"
        rel="stylesheet"
    >

    <!-- Dashboard CSS -->
<<<<<<< HEAD
    <link rel="stylesheet" href="../css/owner_db.css?v=3">
    <link rel="stylesheet" href="../css/owner_ui.css?v=1">
=======
    <link rel="stylesheet" href="../css/owner_db.css?v=10">
    <link rel="stylesheet" href="../css/owner_ui.css?v=10">
>>>>>>> d8abc4f76ee52e2361b359cf6b62550bbbf3a6ac
</head>

<body class="owner-dashboard">

<div class="layout">

    <!-- =====================================
         SIDEBAR
         ===================================== -->

    <aside class="sidebar">

        <picture>
            <source
                srcset="../image/db_logo.png"
                media="(prefers-color-scheme: dark)"
            >

            <img
                class="logo"
                src="../image/db_logo.png"
                alt="Residentia Boarding House"
                draggable="false"
            >
        </picture>

        <nav class="nav">

            <a
                href="owner_db.php"
                class="<?= $page === 'dashboard' ? 'active' : '' ?>"
            >
                <svg class="nav-icon" width="16" height="16" style="width:16px;height:16px;min-width:16px;max-width:16px;flex:0 0 16px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <rect x="3" y="3" width="7" height="9" rx="1"></rect>
                    <rect x="14" y="3" width="7" height="5" rx="1"></rect>
                    <rect x="14" y="12" width="7" height="9" rx="1"></rect>
                    <rect x="3" y="16" width="7" height="5" rx="1"></rect>
                </svg>
                <span>Dashboard</span>
            </a>

            <a
                href="owner_rooms.php"
                class="<?= $page === 'rooms' ? 'active' : '' ?>"
            >
                <svg class="nav-icon" width="16" height="16" style="width:16px;height:16px;min-width:16px;max-width:16px;flex:0 0 16px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M3 10.5L12 3l9 7.5"></path>
                    <path d="M5 9.5V21h14V9.5"></path>
                    <path d="M9 21v-6h6v6"></path>
                </svg>
                <span>Rooms</span>
            </a>

            <a
                href="owner_tenants.php"
                class="<?= $page === 'tenants' ? 'active' : '' ?>"
            >
                <svg class="nav-icon" width="16" height="16" style="width:16px;height:16px;min-width:16px;max-width:16px;flex:0 0 16px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
                    <circle cx="9" cy="7" r="4"></circle>
                    <path d="M22 21v-2a4 4 0 0 0-3-3.87"></path>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                </svg>
                <span>Tenants</span>
            </a>

            <a
                href="owner_billing.php"
                class="<?= $page === 'billing' ? 'active' : '' ?>"
            >
                <svg class="nav-icon" width="16" height="16" style="width:16px;height:16px;min-width:16px;max-width:16px;flex:0 0 16px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <rect x="2" y="5" width="20" height="14" rx="2"></rect>
                    <line x1="2" y1="10" x2="22" y2="10"></line>
                </svg>
                <span>Billing &amp; Payments</span>
            </a>

            <a
                href="owner_maintenance.php"
                class="<?= $page === 'maintenance' ? 'active' : '' ?>"
            >
                <svg class="nav-icon" width="16" height="16" style="width:16px;height:16px;min-width:16px;max-width:16px;flex:0 0 16px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"></path>
                </svg>
                <span>Maintenance</span>
            </a>

            <a
                href="owner_announcement.php"
                class="<?= $page === 'announcements' ? 'active' : '' ?>"
            >
                <svg class="nav-icon" width="16" height="16" style="width:16px;height:16px;min-width:16px;max-width:16px;flex:0 0 16px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M3 11l19-9v20L3 13v-2z"></path>
                    <path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"></path>
                </svg>
                <span>Announcements</span>
            </a>

            <a
                href="owner_reports.php"
                class="<?= $page === 'reports' ? 'active' : '' ?>"
            >
                <svg class="nav-icon" width="16" height="16" style="width:16px;height:16px;min-width:16px;max-width:16px;flex:0 0 16px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <line x1="18" y1="20" x2="18" y2="10"></line>
                    <line x1="12" y1="20" x2="12" y2="4"></line>
                    <line x1="6" y1="20" x2="6" y2="14"></line>
                </svg>
                <span>Reports</span>
            </a>

        </nav>

        <a class="logout" href="../logout.php" style="display: inline-flex; align-items: center; gap: 10px;">
            <svg class="nav-icon" width="16" height="16" style="width:16px;height:16px;min-width:16px;max-width:16px;flex:0 0 16px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                <polyline points="16 17 21 12 16 7"></polyline>
                <line x1="21" y1="12" x2="9" y2="12"></line>
            </svg>
            <span>Log out</span>
        </a>

    </aside>


    <!-- =====================================
         MAIN CONTENT
         ===================================== -->

    <main class="main">

        <!-- HERO HEADER -->
        <section class="hero-banner">
            <div class="hero-left">
                <div class="hero-avatar"><?= e($initials) ?></div>
                <div class="hero-greeting">
                    <span class="hero-time"><?= e($greetingText) ?></span>
                    <h1>Welcome, <?= e($ownerDisplayName) ?>!</h1>
                    <div class="hero-meta">
                        <span><?= e($currentDate) ?></span>
                        <span class="hero-status">Owner</span>
                    </div>
                </div>
            </div>

            <div class="hero-metrics">
                <div class="hero-metric">
                    <span class="hero-metric-label">Occupancy</span>
                    <strong class="hero-metric-value"><?= (int) $occupancyRate ?>%</strong>
                    <span class="hero-metric-sub"><?= (int) $occupiedRooms ?> of <?= (int) $totalRooms ?> rooms</span>
                </div>
                <div class="hero-metric">
                    <span class="hero-metric-label">Active Tenants</span>
                    <strong class="hero-metric-value"><?= (int) $activeTenants ?></strong>
                    <span class="hero-metric-sub">Assigned tenants</span>
                </div>
                <div class="hero-metric">
                    <span class="hero-metric-label">Open Repairs</span>
                    <strong class="hero-metric-value"><?= (int) $openRepairs ?></strong>
                    <span class="hero-metric-sub"><?= (int) $unpaidPayments ?> unpaid/partial</span>
                </div>
            </div>
        </section>


        <!-- =================================
             DASHBOARD STATISTICS
             ================================= -->

        <section class="stats">

            <!-- Total Rooms -->

            <article class="stat">

                <span class="label">
                    Total Rooms
                </span>

                <strong>
                    <?= $totalRooms ?>
                </strong>

                <small>
                    <?= $occupiedRooms ?> occupied ·
                    <?= $availableRooms ?> available
                </small>

            </article>


            <!-- Active Tenants -->

            <article class="stat">

                <span class="label">
                    Active Tenants
                </span>

                <strong>
                    <?= $activeTenants ?>
                </strong>

                <small>
                    Currently assigned to a room
                </small>

            </article>


            <!-- Monthly Income -->

            <article class="stat">

                <span class="label">
                    Income This Month
                </span>

                <strong>
                    ₱<?= number_format($monthlyIncome, 2) ?>
                </strong>

                <small>
                    Payments received this month
                </small>

            </article>


            <!-- Unpaid Payments -->

            <article class="stat warn">

                <span class="label">
                    Unpaid / Partial
                </span>

                <strong>
                    <?= $unpaidPayments ?>
                </strong>

                <small>
                    Payments needing follow-up
                </small>

            </article>


            <!-- Open Repairs -->

            <article class="stat">

                <span class="label">
                    Open Repairs
                </span>

                <strong>
                    <?= $openRepairs ?>
                </strong>

                <small>
                    Pending or in progress
                </small>

            </article>


            <!-- Occupancy -->

            <article class="stat">

                <span class="label">
                    Occupancy
                </span>

                <strong>
                    <?= $occupancyRate ?>%
                </strong>

                <div class="bar">
                    <span style="width: <?= $occupancyRate ?>%"></span>
                </div>

            </article>

        </section>


        <!-- =================================
             QUICK ACTIONS
             ================================= -->

        <section class="actions">

            <a href="owner_tenants.php?action=add" class="btn ghost">
                + Add Tenant
            </a>

            <a href="owner_billing.php?action=record" class="btn ghost">
                + Record Payment
            </a>

            <a href="owner_rooms.php?action=add" class="btn ghost">
                + Add Room
            </a>

            <a href="owner_announcement.php?action=new" class="btn ghost">
                + New Announcement
            </a>

        </section>


        <!-- =================================
             RECENT ACTIVITY
             ================================= -->

        <div class="grid2">


            <!-- UPCOMING PAYMENTS -->

            <section class="panel">

                <h2>Upcoming Payments</h2>

                <?php if (!empty($upcomingPayments)): ?>

                    <div class="table-wrap">

                        <table>

                            <thead>
                                <tr>
                                    <th>Tenant</th>
                                    <th>Due Date</th>
                                    <th>Amount</th>
                                    <th></th>
                                </tr>
                            </thead>

                            <tbody>

                                <?php foreach ($upcomingPayments as $payment): ?>

                                    <tr>

                                        <td>
                                            <?= e($payment['full_name']) ?>
                                        </td>

                                        <td>
                                            <?php
                                            $paymentDate = strtotime($payment['due_date']);

                                            echo $paymentDate
                                                ? e(date('M j, Y', $paymentDate))
                                                : '—';
                                            ?>
                                        </td>

                                        <td>
                                            ₱<?= number_format(
                                                (float) $payment['amount'],
                                                2
                                            ) ?>
                                        </td>

                                        <td>

                                            <span class="pill <?= e(statusClass($payment['status'])) ?>">
                                                <?= e($payment['status']) ?>
                                            </span>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php else: ?>

                    <p class="empty">
                        No upcoming payments.
                    </p>

                <?php endif; ?>

            </section>


            <!-- MAINTENANCE REQUESTS -->

            <section class="panel">

                <h2>Maintenance Requests</h2>

                <?php if (!empty($maintenanceRequests)): ?>

                    <ul class="list">

                        <?php foreach ($maintenanceRequests as $repair): ?>

                            <li>

                                <div>

                                    <strong>
                                        <?= e($repair['full_name']) ?>
                                    </strong>

                                    <span>
                                        <?= e($repair['description']) ?>
                                    </span>

                                </div>

                                <span class="pill <?= e(statusClass($repair['status'])) ?>">
                                    <?= e($repair['status']) ?>
                                </span>

                            </li>

                        <?php endforeach; ?>

                    </ul>

                <?php else: ?>

                    <p class="empty">
                        No open maintenance requests.
                    </p>

                <?php endif; ?>

            </section>


        </div>

    </main>

</div>

</body>
</html>
