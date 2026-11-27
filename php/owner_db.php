
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
   5. MAINTENANCE REQUESTS
   ========================================= */


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
    [$_SESSION['owner_id']]
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

$currentDate = date('l, F j, Y');

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
    <link rel="stylesheet" href="../css/owner_db.css?v=3">
    <link rel="stylesheet" href="../css/owner_ui.css?v=1">
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
                Dashboard
            </a>

            <a href="owner_rooms.php">Rooms</a>

            <a href="owner_tenants.php">Tenants</a>

            <a href="owner_billing.php">Billing &amp; Payments</a>

            <a href="owner_maintenance.php">Maintenance</a>

            <a href="owner_announcement.php">Announcements</a>

            <a href="owner_reports.php">Reports</a>

        </nav>

        <a class="logout" href="../logout.php">
            Log out
        </a>

    </aside>


    <!-- =====================================
         MAIN CONTENT
         ===================================== -->

    <main class="main">

        <!-- HEADER -->

        <header class="top">

            <div>
                <h1>
                    Welcome, <?= e($username) ?>!
                </h1>

                <p class="sub">
                    <?= e($currentDate) ?>
                </p>
            </div>

            <span class="badge">
                Owner
            </span>

        </header>


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
