<?php
session_start();

require_once __DIR__ . '/index_config.php';

if (strtolower(trim($_SESSION['role'] ?? '')) !== 'tenant') {
    header('Location: ../index.php');
    exit;
}

$tenantId = (int) ($_SESSION['tenant_id'] ?? 0);
if ($tenantId <= 0) {
    http_response_code(403);
    exit('Tenant account not found. Please log in again.');
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function peso($amount): string
{
    return '₱' . number_format((float) $amount, 2);
}

function billingDate($value): string
{
    $timestamp = $value ? strtotime($value) : false;
    return $timestamp ? date('M j, Y', $timestamp) : '—';
}

function billingPeriod($start, $end): string
{
    $startDate = $start ? strtotime($start) : false;
    $endDate = $end ? strtotime($end) : false;

    if (!$startDate || !$endDate) {
        return '—';
    }

    return date('M j, Y', $startDate) . ' – ' . date('M j, Y', $endDate);
}

$pdo = db();

$stmt = $pdo->prepare("
    SELECT b.billing_id, b.billing_start_date, b.billing_end_date, b.due_date, b.payment_status,
           COALESCE((SELECT SUM(bd.amount)
                     FROM billing_details bd
                     WHERE bd.billing_id = b.billing_id), 0) AS total_bill,
           COALESCE((SELECT SUM(p.amount_paid)
                     FROM payment p
                     WHERE p.billing_id = b.billing_id), 0) AS total_paid
    FROM billing b
    WHERE b.tenant_id = ?
    ORDER BY b.due_date DESC, b.billing_id DESC
");
$stmt->execute([$tenantId]);
$billings = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT p.payment_id, p.receipt_number, p.payment_method, p.payment_date, p.amount_paid,
           b.billing_start_date, b.billing_end_date
    FROM payment p
    INNER JOIN billing b ON b.billing_id = p.billing_id
    WHERE b.tenant_id = ?
    ORDER BY p.payment_date DESC, p.payment_id DESC
");
$stmt->execute([$tenantId]);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$today = date('Y-m-d');
$totalOutstanding = 0.0;
$totalPaid = 0.0;
$overdueCount = 0;
$overdueAmount = 0.0;

foreach ($billings as &$bill) {
    $bill['total_bill'] = (float) $bill['total_bill'];
    $bill['total_paid'] = (float) $bill['total_paid'];
    $bill['balance'] = max(0, round($bill['total_bill'] - $bill['total_paid'], 2));
    $totalOutstanding += $bill['balance'];
    $totalPaid += $bill['total_paid'];

    if ($bill['total_bill'] > 0 && $bill['balance'] <= 0) {
        $bill['shown_status'] = 'Paid';
    } elseif ($bill['balance'] > 0 && $bill['due_date'] && $bill['due_date'] < $today) {
        $bill['shown_status'] = 'Overdue';
        $overdueCount++;
        $overdueAmount += $bill['balance'];
    } elseif ($bill['total_paid'] > 0) {
        $bill['shown_status'] = 'Partial';
    } else {
        $bill['shown_status'] = $bill['payment_status'];
    }
}
unset($bill);

$username = $_SESSION['username'] ?? 'Tenant';
$currentDate = date('l, F j, Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Billing &amp; Payments | Residentia</title>
    <link rel="stylesheet" href="../css/owner_db.css?v=2">
    <link rel="stylesheet" href="../css/owner_ui.css?v=1">
    <link rel="stylesheet" href="../css/owner_billing.css?v=1">
    <link rel="stylesheet" href="../css/tenant_billing.css?v=1">
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <picture>
            <img class="logo" src="../image/db_logo.png" alt="Residentia Boarding House">
        </picture>
        <nav class="nav">
            <a href="tenant_db.php">Dashboard</a>
            <a href="tenant_billing.php" class="active" aria-current="page">Billing &amp; Payments</a>
            <a href="tenant_maintenance.php">Maintenance</a>
            <a href="tenant_announcement.php">Announcements</a>
        </nav>
        <a class="logout" href="../logout.php">Log out</a>
    </aside>

    <main class="main">
        <div class="billing-page tenant-billing-page">
            <header class="bl-header">
                <div>
                    <h1>Billing &amp; Payments</h1>
                    <p><?= e($currentDate) ?> · Welcome, <?= e($username) ?></p>
                </div>
            </header>

            <section class="bl-stats" aria-label="Billing summary">
                <article class="bl-stat outstanding">
                    <span class="bl-stat-label">Outstanding Balance</span>
                    <strong><?= e(peso($totalOutstanding)) ?></strong>
                    <small>Across all bills</small>
                </article>
                <article class="bl-stat collected">
                    <span class="bl-stat-label">Total Paid</span>
                    <strong><?= e(peso($totalPaid)) ?></strong>
                    <small>Payments recorded</small>
                </article>
                <article class="bl-stat overdue">
                    <span class="bl-stat-label">Overdue Balance</span>
                    <strong><?= e(peso($overdueAmount)) ?></strong>
                    <small><?= $overdueCount ?> overdue <?= $overdueCount === 1 ? 'bill' : 'bills' ?></small>
                </article>
                <article class="bl-stat">
                    <span class="bl-stat-label">Bills</span>
                    <strong><?= count($billings) ?></strong>
                    <small>All billing records</small>
                </article>
            </section>

            <section class="bl-card" aria-labelledby="bills-heading">
                <div class="bl-header">
                    <div>
                        <h2 id="bills-heading">My Bills</h2>
                        <p>Review your billing periods, due dates, and remaining balances.</p>
                    </div>
                </div>
                <div class="bl-table-wrap">
                    <table class="bl-table">
                        <thead>
                            <tr>
                                <th>Bill</th>
                                <th>Billing Period</th>
                                <th>Due Date</th>
                                <th>Total</th>
                                <th>Paid</th>
                                <th>Balance</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$billings): ?>
                                <tr><td colspan="7" class="bl-empty">No billing records yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($billings as $bill): ?>
                                    <?php $statusClass = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '', (string) $bill['shown_status'])); ?>
                                    <tr>
                                        <td><strong>Bill #<?= (int) $bill['billing_id'] ?></strong></td>
                                        <td><?= e(billingPeriod($bill['billing_start_date'], $bill['billing_end_date'])) ?></td>
                                        <td><?= e(billingDate($bill['due_date'])) ?></td>
                                        <td><?= e(peso($bill['total_bill'])) ?></td>
                                        <td><?= e(peso($bill['total_paid'])) ?></td>
                                        <td class="<?= $bill['balance'] > 0 ? 'bl-due' : 'bl-zero' ?>"><?= e(peso($bill['balance'])) ?></td>
                                        <td><span class="bl-pill <?= e($statusClass) ?>"><?= e($bill['shown_status']) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="bl-card" aria-labelledby="payments-heading">
                <div class="bl-header">
                    <div>
                        <h2 id="payments-heading">Payment History</h2>
                        <p>Payments recorded for your bills.</p>
                    </div>
                </div>
                <div class="bl-table-wrap">
                    <table class="bl-table">
                        <thead>
                            <tr>
                                <th>Receipt No.</th>
                                <th>Billing Period</th>
                                <th>Payment Date</th>
                                <th>Method</th>
                                <th>Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$payments): ?>
                                <tr><td colspan="5" class="bl-empty">No payments have been recorded yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($payments as $payment): ?>
                                    <tr>
                                        <td><strong><?= e($payment['receipt_number']) ?></strong></td>
                                        <td><?= e(billingPeriod($payment['billing_start_date'], $payment['billing_end_date'])) ?></td>
                                        <td><?= e(billingDate($payment['payment_date'])) ?></td>
                                        <td><?= e($payment['payment_method']) ?></td>
                                        <td><strong><?= e(peso($payment['amount_paid'])) ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </main>
</div>
</body>
</html>
