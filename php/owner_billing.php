<?php
session_start();
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/../php/index_config.php';

// Owner access only
if (!isset($_SESSION['role']) || strtolower(trim($_SESSION['role'])) !== 'owner') {
    header('Location: ../index.php');
    exit;
}

$owner_id = (int)($_SESSION['owner_id'] ?? 0);
if ($owner_id <= 0) {
    die('Owner ID is missing from your session. Please log in again.');
}

$pdo = db();

// Month currently displayed on the Billing & Payments page (defaults to current month).
$requestedMonth = $_GET['month'] ?? $_POST['selected_month'] ?? date('Y-m');
$selectedMonth = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$requestedMonth)
    ? (string)$requestedMonth
    : date('Y-m');
$monthStart = $selectedMonth . '-01';
$monthEnd = (new DateTimeImmutable($monthStart))->modify('first day of next month')->format('Y-m-d');
$monthLabel = date('F Y', strtotime($monthStart));

function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function peso($amount): string { return '₱' . number_format((float)$amount, 2); }
function fdate($d): string { return $d ? date('M j, Y', strtotime($d)) : '—'; }
function fperiod($s): string {
    return date('F Y', strtotime($s));
}

/** Calculate the current monthly billing cycle anchored to the original move-in day. */
function tenantBillingCycle(string $moveInDate, ?string $today = null): ?array {
    $moveIn = DateTimeImmutable::createFromFormat('!Y-m-d', $moveInDate);
    $current = DateTimeImmutable::createFromFormat('!Y-m-d', $today ?: date('Y-m-d'));
    if (!$moveIn || !$current || $moveIn->format('Y-m-d') !== $moveInDate || $current < $moveIn) return null;

    $offset = (((int)$current->format('Y') - (int)$moveIn->format('Y')) * 12)
        + ((int)$current->format('n') - (int)$moveIn->format('n'));
    $anniversary = static function (DateTimeImmutable $anchor, int $months): DateTimeImmutable {
        $month = $anchor->modify('first day of this month')->modify(($months >= 0 ? '+' : '') . $months . ' months');
        return $month->setDate((int)$month->format('Y'), (int)$month->format('n'), min((int)$anchor->format('j'), (int)$month->format('t')));
    };
    $start = $anniversary($moveIn, $offset);
    if ($start > $current) { $offset--; $start = $anniversary($moveIn, $offset); }
    $end = $anniversary($moveIn, $offset + 1);
    return ['start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d'), 'due' => $end->format('Y-m-d')];
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$error = '';
$old = null;          // mga ininput kapag may error
$oldForm = '';        // 'pay' o 'bill'

/* =========================================
   ACTIONS
========================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            throw new Exception('Invalid request. Please refresh the page.');
        }

        /* ---------- RECORD PAYMENT ---------- */
        if ($action === 'record_payment') {
            $old = $_POST; $oldForm = 'pay';

            $billing_id = filter_input(INPUT_POST, 'billing_id', FILTER_VALIDATE_INT);
            $amount     = filter_input(INPUT_POST, 'amount_paid', FILTER_VALIDATE_FLOAT);
            $method     = $_POST['payment_method'] ?? '';
            $receipt    = trim($_POST['receipt_number'] ?? '');

            if (!$billing_id) { throw new Exception('Please select a billing record.'); }
            if ($amount === false || $amount === null || $amount <= 0) { throw new Exception('Please enter a valid payment amount.'); }
            if (!in_array($method, ['Cash', 'GCash', 'Bank Transfer'], true)) { throw new Exception('Please select a valid payment method.'); }
            if ($receipt === '') { throw new Exception('Receipt number is required.'); }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                SELECT b.billing_id, b.due_date,
                       COALESCE((SELECT SUM(bd.amount) FROM billing_details bd WHERE bd.billing_id = b.billing_id), 0) AS total_bill,
                       COALESCE((SELECT SUM(p.amount_paid) FROM payment p WHERE p.billing_id = b.billing_id), 0) AS total_paid
                FROM billing b
                WHERE b.billing_id = ? AND b.owner_id = ?
                FOR UPDATE
            ");
            $stmt->execute([$billing_id, $owner_id]);
            $bill = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$bill) { throw new Exception('Billing record not found.'); }

            $balance = round((float)$bill['total_bill'] - (float)$bill['total_paid'], 2);
            if ($balance <= 0) { throw new Exception('This bill is already fully paid.'); }
            if ($amount > $balance + 0.001) { throw new Exception('Payment exceeds the remaining balance of ' . peso($balance) . '.'); }

            $q = $pdo->prepare("SELECT payment_id FROM payment WHERE receipt_number = ?");
            $q->execute([$receipt]);
            if ($q->fetch()) { throw new Exception('Receipt number already exists. Please use another receipt number.'); }

            $pdo->prepare("INSERT INTO payment (owner_id, billing_id, payment_method, receipt_number, amount_paid)
                           VALUES (?, ?, ?, ?, ?)")
                ->execute([$owner_id, $billing_id, $method, $receipt, $amount]);

            // Business rule: Paid / Partial / Unpaid (Overdue kapag lampas na sa due date)
            $newPaid = (float)$bill['total_paid'] + $amount;
            if ($newPaid + 0.001 >= (float)$bill['total_bill']) {
                $newStatus = 'Paid';
            } elseif (!empty($bill['due_date']) && strtotime($bill['due_date']) < strtotime(date('Y-m-d'))) {
                $newStatus = 'Overdue';
            } else {
                $newStatus = 'Partial';
            }
            $pdo->prepare("UPDATE billing SET payment_status = ? WHERE billing_id = ? AND owner_id = ?")
                ->execute([$newStatus, $billing_id, $owner_id]);

            $pdo->commit();
            $msg = 'Payment of ' . peso($amount) . ' recorded!';

        /* ---------- CREATE BILL ---------- */
        } elseif ($action === 'create_bill') {
            $old = $_POST; $oldForm = 'bill';

            $tenant_id = filter_input(INPUT_POST, 'tenant_id', FILTER_VALIDATE_INT);
            $start = $end = $due = ''; // Recomputed from the tenant's move-in date below.

            $charges = [];
            foreach (['Rent' => 'charge_rent', 'Water' => 'charge_water', 'Electricity' => 'charge_electricity', 'Internet' => 'charge_internet'] as $type => $field) {
                $v = trim($_POST[$field] ?? '');
                if ($v === '') { continue; }
                $v = filter_var($v, FILTER_VALIDATE_FLOAT);
                if ($v === false || $v < 0) { throw new Exception('Please enter valid charge amounts.'); }
                if ($v > 0) { $charges[$type] = round($v, 2); }
            }

            if (!$tenant_id) { throw new Exception('Please select a tenant.'); }
            if (!$charges) { throw new Exception('Add at least one charge greater than zero.'); }

            $pdo->beginTransaction();

            // Tenant ay dapat nakatira sa room ng owner na ito
            $q = $pdo->prepare("
                SELECT ra.movein_date
                FROM room_assignment ra
                INNER JOIN tenant t ON t.tenant_id = ra.tenant_id AND t.status = 'Active'
                INNER JOIN room r ON r.room_id = ra.room_id
                INNER JOIN boarding_house bh ON bh.bh_id = r.bh_id
                WHERE ra.tenant_id = ? AND ra.moveout_date IS NULL AND bh.owner_id = ?
                ORDER BY ra.movein_date DESC
                LIMIT 1
                FOR UPDATE
            ");
            $q->execute([$tenant_id, $owner_id]);
            $moveInDate = $q->fetchColumn();
            if (!$moveInDate) { throw new Exception('Selected tenant is not staying in one of your rooms.'); }
            $cycle = tenantBillingCycle((string)$moveInDate);
            if (!$cycle) { throw new Exception('This tenant has no active billing cycle yet. Please check the move-in date.'); }
            $start = $cycle['start'];
            $end = $cycle['end'];
            $due = $cycle['due'];

            // Iwas dobleng bill sa parehong period
            $q = $pdo->prepare("SELECT billing_id FROM billing WHERE tenant_id = ? AND owner_id = ? AND billing_start_date = ?");
            $q->execute([$tenant_id, $owner_id, $start]);
            if ($q->fetch()) { throw new Exception('This tenant already has a bill for that billing period.'); }

            $pdo->prepare("INSERT INTO billing (owner_id, tenant_id, billing_start_date, billing_end_date, due_date, payment_status)
                           VALUES (?, ?, ?, ?, ?, 'Unpaid')")
                ->execute([$owner_id, $tenant_id, $start, $end, $due]);
            $billing_id = (int)$pdo->lastInsertId();

            $ins = $pdo->prepare("INSERT INTO billing_details (billing_id, charge_type, amount) VALUES (?, ?, ?)");
            foreach ($charges as $type => $amt) { $ins->execute([$billing_id, $type, $amt]); }

            $pdo->commit();
            $msg = 'Bill created for ' . peso(array_sum($charges)) . '!';

        } else {
            throw new Exception('Invalid action.');
        }

        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['flash'] = ['type' => 'success', 'msg' => $msg];
        header('Location: owner_billing.php?month=' . urlencode($selectedMonth));      // iwas double-submit kapag nag-refresh
        exit;

    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log($ex->getMessage());
        $error = $ex->getCode() === '23000'
            ? 'Receipt number already exists. Please use another receipt number.'
            : 'Unable to save. Please check the database.';
    } catch (Exception $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $error = $ex->getMessage();
    }
}

/* =========================================
   DATA
========================================= */
$bhName = $pdo->prepare("SELECT bh_name FROM boarding_house WHERE owner_id = ? ORDER BY bh_id LIMIT 1");
$bhName->execute([$owner_id]);
$bhName = $bhName->fetchColumn() ?: 'Residentia Boarding House';

// Billing records
$stmt = $pdo->prepare("
    SELECT b.billing_id, b.tenant_id, b.billing_start_date, b.billing_end_date, b.due_date, b.payment_status,
           t.first_name, t.last_name,
           COALESCE((SELECT SUM(bd.amount) FROM billing_details bd WHERE bd.billing_id = b.billing_id), 0) AS total_bill,
           COALESCE((SELECT SUM(p.amount_paid) FROM payment p WHERE p.billing_id = b.billing_id), 0) AS total_paid
    FROM billing b
    INNER JOIN tenant t ON t.tenant_id = b.tenant_id
    WHERE b.owner_id = ?
    ORDER BY b.due_date DESC, b.billing_id DESC
");
$stmt->execute([$owner_id]);
$allBillings = $stmt->fetchAll(PDO::FETCH_ASSOC);
$billings = array_values(array_filter($allBillings, static function ($b) use ($monthStart, $monthEnd) {
    return $b['billing_start_date'] >= $monthStart && $b['billing_start_date'] < $monthEnd;
}));

// Charges (breakdown)
$stmt = $pdo->prepare("
    SELECT bd.billing_id, bd.charge_type, bd.amount
    FROM billing_details bd INNER JOIN billing b ON b.billing_id = bd.billing_id
    WHERE b.owner_id = ? ORDER BY bd.billing_details_id
");
$stmt->execute([$owner_id]);
$charges = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $charges[$c['billing_id']][] = ['type' => $c['charge_type'], 'amount' => (float)$c['amount']];
}

// Payment history
$stmt = $pdo->prepare("
    SELECT p.payment_id, p.billing_id, p.receipt_number, p.payment_method, p.payment_date, p.amount_paid,
           b.billing_start_date, b.billing_end_date, t.first_name, t.last_name
    FROM payment p
    INNER JOIN billing b ON b.billing_id = p.billing_id
    INNER JOIN tenant t ON t.tenant_id = b.tenant_id
    WHERE p.owner_id = ?
    ORDER BY p.payment_date DESC, p.payment_id DESC
");
$stmt->execute([$owner_id]);
$allPayments = $stmt->fetchAll(PDO::FETCH_ASSOC);
$payments = array_values(array_filter($allPayments, static function ($p) use ($monthStart, $monthEnd) {
    $paymentDate = substr((string)$p['payment_date'], 0, 10);
    return $paymentDate >= $monthStart && $paymentDate < $monthEnd;
}));

// Only active tenants whose current monthly billing cycle has not been billed.
$today = date('Y-m-d');
$stmt = $pdo->prepare("
    SELECT t.tenant_id, t.first_name, t.last_name, r.room_number, r.monthly_rate, ra.movein_date
    FROM tenant t
    INNER JOIN room_assignment ra ON ra.tenant_id = t.tenant_id AND ra.moveout_date IS NULL
    INNER JOIN room r ON r.room_id = ra.room_id
    INNER JOIN boarding_house bh ON bh.bh_id = r.bh_id
    WHERE bh.owner_id = ? AND t.status = 'Active'
    ORDER BY t.last_name, t.first_name
");
$stmt->execute([$owner_id]);
$activeTenants = $stmt->fetchAll(PDO::FETCH_ASSOC);
$billable = [];
$existingCycleBill = $pdo->prepare("SELECT billing_id FROM billing WHERE tenant_id = ? AND owner_id = ? AND billing_start_date = ? LIMIT 1");
foreach ($activeTenants as $tenant) {
    $cycle = tenantBillingCycle((string)$tenant['movein_date'], $today);
    if (!$cycle) continue; // Not yet at or past the move-in date.
    $existingCycleBill->execute([$tenant['tenant_id'], $owner_id, $cycle['start']]);
    if ($existingCycleBill->fetchColumn()) continue;
    $tenant['billing_start_date'] = $cycle['start'];
    $tenant['billing_end_date'] = $cycle['end'];
    $tenant['due_date'] = $cycle['due'];
    $billable[] = $tenant;
}

/* Kuwenta */
$totalCollected = 0; $totalOutstanding = 0; $overdueCount = 0; $overdueAmount = 0;
$billData = []; $payByBill = [];

foreach ($allPayments as $p) {
    $payByBill[$p['billing_id']][] = [
        'receipt' => $p['receipt_number'], 'date' => date('M j, Y h:i A', strtotime($p['payment_date'])),
        'method' => $p['payment_method'], 'amount' => (float)$p['amount_paid'],
    ];
}

foreach ($billings as &$b) {
    $b['balance'] = max(0, round((float)$b['total_bill'] - (float)$b['total_paid'], 2));
    // Status na ipinapakita: batay sa totoong balanse at due date
    if ((float)$b['total_bill'] <= 0)            { $b['shown'] = $b['payment_status']; }
    elseif ($b['balance'] <= 0)                   { $b['shown'] = 'Paid'; }
    elseif ($b['due_date'] < $today)              { $b['shown'] = 'Overdue'; }
    elseif ((float)$b['total_paid'] > 0)          { $b['shown'] = 'Partial'; }
    else                                          { $b['shown'] = 'Unpaid'; }

    $totalOutstanding += $b['balance'];
    if ($b['shown'] === 'Overdue') { $overdueCount++; $overdueAmount += $b['balance']; }

    $billData[$b['billing_id']] = [
        'tenant' => $b['first_name'] . ' ' . $b['last_name'],
        'period' => fperiod($b['billing_start_date']),
        'due' => fdate($b['due_date']), 'status' => $b['shown'],
        'total' => (float)$b['total_bill'], 'paid' => (float)$b['total_paid'], 'balance' => $b['balance'],
        'charges' => $charges[$b['billing_id']] ?? [], 'payments' => $payByBill[$b['billing_id']] ?? [],
    ];
}
unset($b);
$totalCollected = 0;
foreach ($payments as $payment) {
    $totalCollected += (float)$payment['amount_paid'];
}

$receipts = [];
foreach ($payments as $p) {
    $bd = $billData[$p['billing_id']] ?? null;
    $receipts[$p['payment_id']] = [
        'receipt' => $p['receipt_number'], 'date' => date('M j, Y h:i A', strtotime($p['payment_date'])),
        'tenant' => $p['first_name'] . ' ' . $p['last_name'], 'period' => fperiod($p['billing_start_date']),
        'method' => $p['payment_method'], 'amount' => (float)$p['amount_paid'],
        'total' => $bd['total'] ?? 0, 'paid' => $bd['paid'] ?? 0, 'balance' => $bd['balance'] ?? 0,
    ];
}

// Generate unique suggested receipt number
do {
    $suggestReceipt = 'OR-' . date('Ymd-His') . '-' . random_int(10, 99);
    $chkReceipt = $pdo->prepare("SELECT 1 FROM payment WHERE receipt_number = ? LIMIT 1");
    $chkReceipt->execute([$suggestReceipt]);
} while ($chkReceipt->fetchColumn());
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Billing &amp; Payments | Residentia</title>

    <link rel="stylesheet" href="../css/owner_db.css?v=4">
    <link rel="stylesheet" href="../css/owner_billing.css?v=12">
    <link rel="stylesheet" href="../css/owner_ui.css?v=11">
</head>
<body>
<div class="layout">

    <!-- SIDEBAR -->
    <aside class="sidebar">
        <picture>
            <img class="logo" src="../image/db_logo.png" alt="Residentia Boarding House">
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

    <!-- MAIN -->
    <main class="main">
    <div class="billing-page">

        <header class="bl-header page-header-banner">
            <div>
                <h1>Billing &amp; Payments</h1>
                <p>Create bills, record payments and monitor outstanding balances.</p>
            </div>
            <div class="bl-header-actions">
                <button type="button" class="bl-btn-ghost" id="openBill">+ Create Bill</button>
                <button type="button" class="bl-add" id="openPay">+ Record Payment</button>
            </div>
        </header>

        <?php if (($flash['type'] ?? '') === 'success'): ?>
            <dialog class="bl-dialog bl-success-dialog" id="blSuccessDialog" aria-labelledby="blSuccessTitle" aria-describedby="blSuccessMessage">
                <h2 id="blSuccessTitle">Success</h2>
                <p id="blSuccessMessage"><?= e($flash['msg'] ?? '') ?></p>
                <form method="dialog">
                    <button type="submit" class="bl-btn">OK</button>
                </form>
            </dialog>
        <?php elseif ($flash): ?>
            <div class="bl-alert error" role="alert"><?= e($flash['msg'] ?? '') ?></div>
        <?php endif; ?>
        <?php if ($error && !$old): ?><div class="bl-alert error" role="alert"><?= e($error) ?></div><?php endif; ?>

        <!-- SUMMARY -->
        <section class="bl-stats">
            <article class="bl-stat collected">
                <span class="bl-stat-label">Total Collected</span>
                <strong><?= peso($totalCollected) ?></strong>
                <small>Payments received</small>
            </article>
            <article class="bl-stat outstanding">
                <span class="bl-stat-label">Outstanding Balance</span>
                <strong><?= peso($totalOutstanding) ?></strong>
                <small>Still to be collected</small>
            </article>
            <article class="bl-stat overdue">
                <span class="bl-stat-label">Overdue</span>
                <strong><?= $overdueCount ?> <span class="bl-unit">bill<?= $overdueCount === 1 ? '' : 's' ?></span></strong>
                <small><?= peso($overdueAmount) ?> past due</small>
            </article>
        </section>

        <!-- TABS -->
        <section class="bl-card">
            <div class="bl-tabs" role="tablist">
                <button type="button" class="bl-tab active" role="tab" data-tab="bills">Billing Records <span><?= count($billings) ?></span></button>
                <button type="button" class="bl-tab" role="tab" data-tab="history">Payment History <span><?= count($payments) ?></span></button>
            </div>

            <!-- BILLING RECORDS -->
            <div class="bl-panel" id="panel-bills">
                <div class="bl-toolbar">
                    <p class="bl-count"><span id="blShown"><?= count($billings) ?></span> bill(s) shown</p>
                    <div class="bl-controls">
                        <div class="bl-search"><input type="search" id="blSearch" placeholder="Search tenant" aria-label="Search bills"></div>
                        <div class="bl-chips" role="group" aria-label="Filter bills">
                            <button type="button" class="bl-chip active" data-filter="all">All</button>
                            <button type="button" class="bl-chip" data-filter="unpaid">Unpaid</button>
                            <button type="button" class="bl-chip" data-filter="partial">Partial</button>
                            <button type="button" class="bl-chip" data-filter="overdue">Overdue</button>
                            <button type="button" class="bl-chip" data-filter="paid">Paid</button>
                        </div>
                    </div>
                </div>

                <div class="bl-table-wrap">
                    <table class="bl-table">
                        <thead>
                            <tr>
                                <th class="bl-left">Tenant</th>
                                <th>Billing Period</th>
                                <th>Due Date</th>
                                <th>Total</th>
                                <th>Paid</th>
                                <th>Balance</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="blBody">
                        <?php if (!$billings): ?>
                            <tr><td colspan="8" class="bl-empty">No billing records for <?= e($monthLabel) ?>.</td></tr>
                        <?php else: foreach ($billings as $b):
                            $st = strtolower($b['shown']);
                            $name = $b['first_name'] . ' ' . $b['last_name'];
                        ?>
                            <tr data-status="<?= e($st) ?>" data-search="<?= e(mb_strtolower($name)) ?>">
                                <td class="bl-left">
                                    <strong><?= e($name) ?></strong>
                                </td>
                                <td><?= e(fperiod($b['billing_start_date'])) ?></td>
                                <td><?= e(fdate($b['due_date'])) ?></td>
                                <td><?= peso($b['total_bill']) ?></td>
                                <td><?= peso($b['total_paid']) ?></td>
                                <td class="<?= $b['balance'] > 0 ? 'bl-due' : 'bl-zero' ?>"><?= peso($b['balance']) ?></td>
                                <td><span class="bl-pill <?= e($st) ?>"><?= e($b['shown']) ?></span></td>
                                <td><button type="button" class="bl-link js-view" data-id="<?= (int)$b['billing_id'] ?>">View</button></td>
                            </tr>
                        <?php endforeach; endif; ?>
                            <tr id="blNoMatch" hidden><td colspan="8" class="bl-empty">No bills match your search.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- PAYMENT HISTORY -->
            <div class="bl-panel" id="panel-history" hidden>
                <div class="bl-toolbar">
                    <p class="bl-count"><span id="phShown"><?= count($payments) ?></span> payment(s) shown</p>
                    <div class="bl-controls">
                        <div class="bl-search"><input type="search" id="phSearch" placeholder="Search tenant or receipt no." aria-label="Search payments"></div>
                    </div>
                </div>

                <div class="bl-table-wrap">
                    <table class="bl-table">
                        <thead>
                            <tr>
                                <th>Receipt No.</th>
                                <th class="bl-left">Tenant</th>
                                <th>Billing Period</th>
                                <th>Payment Date</th>
                                <th>Method</th>
                                <th>Amount Paid</th>
                                <th>Receipt</th>
                            </tr>
                        </thead>
                        <tbody id="phBody">
                        <?php if (!$payments): ?>
                            <tr><td colspan="7" class="bl-empty">No payments recorded yet.</td></tr>
                        <?php else: foreach ($payments as $p):
                            $name = $p['first_name'] . ' ' . $p['last_name'];
                        ?>
                            <tr data-search="<?= e(mb_strtolower($name . ' ' . $p['receipt_number'])) ?>">
                                <td><strong><?= e($p['receipt_number']) ?></strong></td>
                                <td class="bl-left"><?= e($name) ?></td>
                                <td><?= e(date('M Y', strtotime($p['billing_start_date']))) ?></td>
                                <td><?= e(date('M j, Y · h:i A', strtotime($p['payment_date']))) ?></td>
                                <td><?= e($p['payment_method']) ?></td>
                                <td><strong><?= peso($p['amount_paid']) ?></strong></td>
                                <td><button type="button" class="bl-link js-receipt" data-id="<?= (int)$p['payment_id'] ?>">Print</button></td>
                            </tr>
                        <?php endforeach; endif; ?>
                            <tr id="phNoMatch" hidden><td colspan="7" class="bl-empty">No payments match your search.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

    </div>
    </main>
</div>

<!-- RECORD PAYMENT DIALOG -->
<dialog id="payDialog" class="bl-dialog payment-dialog" aria-labelledby="payTitle">
    <form method="POST" class="bl-form payment-form" id="payForm" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="selected_month" value="<?= e($selectedMonth) ?>">
        <input type="hidden" name="action" value="record_payment">

        <div class="bl-dlg-head">
            <div class="bl-dlg-title">
                <span class="bl-dlg-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M5 3.75h14a1 1 0 0 1 1 1v15.5l-3.5-2-3.5 2-3.5-2-3.5 2V4.75a1 1 0 0 1 1-1Z"/><path d="M9 8h6M9 12h6"/></svg>
                </span>
                <div><h2 id="payTitle">Record Payment</h2><p>Select a bill and enter the payment details.</p></div>
            </div>
            <button type="button" class="bl-x js-close" aria-label="Close">&times;</button>
        </div>

        <div class="payment-content" id="paymentContent">
        <?php if ($error && $oldForm === 'pay'): ?><div class="bl-alert error" role="alert"><?= e($error) ?></div><?php endif; ?>

        <section class="bl-form-section">
            <h3 class="bl-section-heading"><span>01</span> Billing Record</h3>
            <div class="bl-fields">
                <div class="bl-field full">
                    <label for="billingSearch">Select Tenant / Bill</label>
                    <div class="bl-tenant-picker" id="paymentPicker">
                        <div class="bl-tenant-search-wrap">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.8"/><path d="m16 16 5 5"/></svg>
                            <input type="search" id="billingSearch" placeholder="Search tenant name or billing period..." autocomplete="off" aria-controls="billingOptions" aria-expanded="false">
                            <span class="bl-search-hint">⌕</span>
                        </div>
                        <div class="bl-tenant-options" id="billingOptions" role="listbox" hidden></div>
                        <div class="bl-selected-tenant" id="selectedBilling" hidden>
                            <span class="bl-tenant-avatar" id="billingAvatar">T</span>
                            <span class="bl-selected-copy">
                                <strong id="selectedBillingName"></strong>
                                <small id="selectedBillingMeta"></small>
                            </span>
                            <span class="bl-selected-balance" id="selectedBillingBalance"></span>
                            <button type="button" class="bl-change-tenant" id="changeBilling">Change</button>
                        </div>
                    </div>
                    <select name="billing_id" id="billing_id" class="bl-native-tenant" tabindex="-1" aria-hidden="true">
                        <option value="">Select a bill</option>
                        <?php foreach ($billings as $b): if ($b['balance'] <= 0) continue; ?>
                            <option value="<?= (int)$b['billing_id'] ?>"
                                data-balance="<?= e(number_format($b['balance'], 2, '.', '')) ?>"
                                data-name="<?= e($b['first_name'] . ' ' . $b['last_name']) ?>"
                                data-period="<?= e(fperiod($b['billing_start_date'])) ?>"
                                data-due="<?= e(fdate($b['due_date'])) ?>">
                                <?= e($b['first_name'] . ' ' . $b['last_name']) ?> · <?= e(fperiod($b['billing_start_date'])) ?> · Balance <?= peso($b['balance']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="bl-field-hint">Only bills with an outstanding balance are listed.</small>
                </div>
            </div>
        </section>

        <section class="bl-form-section">
            <h3 class="bl-section-heading"><span>02</span> Payment Details</h3>
            <div class="bl-fields">
            <div class="bl-field">
                <label for="remaining_balance">Remaining Balance</label>
                <input type="text" id="remaining_balance" value="₱0.00" readonly>
            </div>
            <div class="bl-field">
                <label for="amount_paid">Amount Paid <button type="button" class="bl-inline" id="payFull">Pay full balance</button></label>
                <input type="number" name="amount_paid" id="amount_paid" min="0.01" step="0.01" placeholder="0.00" required>
            </div>
            <div class="bl-field">
                <label for="paymentMethodTrigger">Payment Method</label>
                <div class="bl-method-picker" id="paymentMethodPicker">
                    <button type="button" class="bl-method-trigger" id="paymentMethodTrigger" aria-haspopup="listbox" aria-expanded="false" aria-controls="paymentMethodOptions" aria-required="true">
                        <span id="paymentMethodLabel">Select method</span>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                    </button>
                    <div class="bl-method-options" id="paymentMethodOptions" role="listbox" aria-label="Payment method" hidden>
                        <button type="button" class="bl-method-option" role="option" data-value="Cash" aria-selected="false">
                            <span class="bl-method-option-copy"><strong>Cash</strong><small>Paid in cash</small></span>
                        </button>
                        <button type="button" class="bl-method-option" role="option" data-value="GCash" aria-selected="false">
                            <span class="bl-method-option-copy"><strong>GCash</strong><small>Mobile wallet payment</small></span>
                        </button>
                        <button type="button" class="bl-method-option" role="option" data-value="Bank Transfer" aria-selected="false">
                            <span class="bl-method-option-copy"><strong>Bank Transfer</strong><small>Direct bank payment</small></span>
                        </button>
                    </div>
                </div>
                <select name="payment_method" id="payment_method" class="bl-native-tenant" tabindex="-1" aria-hidden="true">
                    <option value="">Select method</option>
                    <option value="Cash">Cash</option>
                    <option value="GCash">GCash</option>
                    <option value="Bank Transfer">Bank Transfer</option>
                </select>
                <small class="bl-method-error" id="paymentMethodError" role="alert" hidden>Please select a payment method.</small>
            </div>
            <div class="bl-field">
                <label for="receipt_number">Receipt Number</label>
                <input
                    type="text"
                    name="receipt_number"
                    id="receipt_number"
                    maxlength="50"
                    value="<?= e($oldForm === 'pay' && isset($old['receipt_number']) ? $old['receipt_number'] : $suggestReceipt) ?>"
                    required
                >
            </div>
            </div>
        </section>
        </div>

        <div class="bl-dlg-foot">
            <button type="button" class="bl-btn ghost js-close">Cancel</button>
            <button type="submit" class="bl-btn">Record Payment</button>
        </div>
    </form>
</dialog>

<!-- CREATE BILL DIALOG -->
<dialog id="billDialog" class="bl-dialog bill-dialog" aria-labelledby="billTitle">
    <form method="POST" class="bl-form bill-form" id="billForm" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="selected_month" value="<?= e($selectedMonth) ?>">
        <input type="hidden" name="action" value="create_bill">

        <div class="bl-dlg-head">
            <div class="bl-dlg-title">
                <span class="bl-dlg-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M6 3.75h12a1 1 0 0 1 1 1v15.5l-3.5-2-3.5 2-3.5-2-3.5 2V4.75a1 1 0 0 1 1-1Z"/><path d="M9 8h6M9 12h6"/></svg>
                </span>
                <div><h2 id="billTitle">Create Bill</h2><p>Generate a bill for rent and utilities.</p></div>
            </div>
            <button type="button" class="bl-x js-close" aria-label="Close">&times;</button>
        </div>

        <?php if ($error && $oldForm === 'bill'): ?><div class="bl-alert error" role="alert"><?= e($error) ?></div><?php endif; ?>

        <?php if (!$billable): ?>
            <div class="bl-alert error">No tenants are eligible for a new bill right now. They may already have a bill for the current cycle or have no active room assignment.</div>
        <?php endif; ?>

        <section class="bl-form-section">
            <h3 class="bl-section-heading"><span>01</span> Tenant Information</h3>
            <div class="bl-fields">
                <div class="bl-field full">
                    <label for="tenantSearch">Select Tenant</label>
                    <div class="bl-tenant-picker" id="tenantPicker">
                        <div class="bl-tenant-search-wrap">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.8"/><path d="m16 16 5 5"/></svg>
                            <input type="search" id="tenantSearch" placeholder="Search tenant name or room number..." autocomplete="off" aria-controls="tenantOptions" aria-expanded="false">
                            <span class="bl-search-hint">⌕</span>
                        </div>
                        <div class="bl-tenant-options" id="tenantOptions" role="listbox" hidden></div>
                        <div class="bl-selected-tenant" id="selectedTenant" hidden>
                            <span class="bl-tenant-avatar" id="tenantAvatar">T</span>
                            <span class="bl-selected-copy">
                                <strong id="selectedTenantName"></strong>
                                <small id="selectedTenantRoom"></small>
                            </span>
                            <button type="button" class="bl-change-tenant" id="changeTenant">Change</button>
                        </div>
                    </div>
                    <select name="tenant_id" id="tenant_id" class="bl-native-tenant" tabindex="-1" aria-hidden="true">
                        <option value="">Select a tenant</option>
                        <?php foreach ($billable as $t): ?>
                            <option value="<?= (int)$t['tenant_id'] ?>"
                                data-rent="<?= e(number_format((float)$t['monthly_rate'], 2, '.', '')) ?>"
                                data-start="<?= e($t['billing_start_date']) ?>"
                                data-end="<?= e($t['billing_end_date']) ?>"
                                data-due="<?= e($t['due_date']) ?>">
                                <?= e($t['first_name'] . ' ' . $t['last_name']) ?> · <?= e($t['room_number']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="bl-field-hint">Only active tenants without a bill for the current monthly cycle are listed.</small>
                </div>
            </div>
        </section>

        <section class="bl-form-section">
            <h3 class="bl-section-heading"><span>02</span> Billing Period</h3>
            <div class="bl-date-fields">
                <div class="bl-field">
                    <label for="billing_month_display">Billing Month</label>
                    <input type="hidden" name="billing_start_date" id="billing_start_date" value="">
                    <input type="hidden" name="billing_end_date" id="billing_end_date" value="">
                    <input type="text" id="billing_month_display" class="bl-date-display" value="" placeholder="Month and year" readonly aria-label="Billing month and year">
                </div>
                <div class="bl-field">
                    <label for="due_date_display">Due Date</label>
                    <input type="hidden" name="due_date" id="due_date" value="">
                    <input type="text" id="due_date_display" class="bl-date-display" value="" placeholder="MM/DD/YYYY" readonly aria-label="Due Date (month/day/year)">
                </div>
            </div>
            <small class="bl-field-hint bl-cycle-hint">Billing month and due date are automatically based on the tenant's move-in date.</small>
        </section>

        <section class="bl-form-section bl-charge-section">
            <h3 class="bl-section-heading"><span>03</span> Bill Charges</h3>
            <div class="bl-fields">
                <div class="bl-field">
                    <label for="charge_rent"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-6v-7h-4v7H4a1 1 0 0 1-1-1z"/></svg>Rent</label>
                    <div class="bl-currency-field"><span>₱</span><input type="number" name="charge_rent" id="charge_rent" min="0" step="0.01" placeholder="0.00"></div>
                </div>
                <div class="bl-field">
                    <label for="charge_water"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3s7 7.3 7 12a7 7 0 0 1-14 0c0-4.7 7-12 7-12Z"/><path d="M9 16a3 3 0 0 0 3 3"/></svg>Water</label>
                    <div class="bl-currency-field"><span>₱</span><input type="number" name="charge_water" id="charge_water" min="0" step="0.01" placeholder="0.00"></div>
                </div>
                <div class="bl-field">
                    <label for="charge_electricity"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m13 2-3 8H5l6 12 3-8h5L13 2Z"/></svg>Electricity</label>
                    <div class="bl-currency-field"><span>₱</span><input type="number" name="charge_electricity" id="charge_electricity" min="0" step="0.01" placeholder="0.00"></div>
                </div>
                <div class="bl-field">
                    <label for="charge_internet"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 8.8a15 15 0 0 1 20 0M5 12a10.5 10.5 0 0 1 14 0m-11 3.2a5.8 5.8 0 0 1 8 0M12 19h.01"/></svg>Internet</label>
                    <div class="bl-currency-field"><span>₱</span><input type="number" name="charge_internet" id="charge_internet" min="0" step="0.01" placeholder="0.00"></div>
                </div>
            </div>
        </section>
        <div class="bl-total"><span>Total Amount Due</span><strong id="billTotal">₱0.00</strong></div>

        <div class="bl-dlg-foot">
            <button type="button" class="bl-btn ghost js-close">Cancel</button>
            <button type="submit" class="bl-btn" <?= $billable ? '' : 'disabled' ?>>Create Bill</button>
        </div>
    </form>
</dialog>

<!-- BILL DETAILS DIALOG -->
<dialog id="viewDialog" class="bl-dialog view-dialog" aria-labelledby="vTenant">
    <div class="bl-form bl-view-form">
        <!-- Fixed header: tenant, billing period, due date and status stay visible. -->
        <div class="bl-view-head">
            <div class="bl-dlg-head">
                <div class="bl-view-heading-copy">
                    <h2 id="vTenant"></h2>
                    <p><span id="vPeriod"></span> · Due <span id="vDue"></span></p>
                </div>
                <button type="button" class="bl-x js-close" aria-label="Close">&times;</button>
            </div>
            <div class="bl-vstatus"><span class="bl-pill" id="vStatus"></span></div>
        </div>

        <!-- Only the bill details area scrolls. -->
        <div class="bl-view-content">
            <h3 class="bl-sub">Charges</h3>
            <ul class="bl-lines" id="vCharges"></ul>
            <div class="bl-sum" id="vSum"></div>

            <h3 class="bl-sub">Payments</h3>
            <ul class="bl-lines" id="vPays"></ul>
        </div>

        <!-- Fixed footer actions. -->
        <div class="bl-dlg-foot bl-view-foot">
            <button type="button" class="bl-btn ghost js-close">Close</button>
            <button type="button" class="bl-btn" id="vPay">Record Payment</button>
        </div>
    </div>
</dialog>

<script>
(function () {
    var $ = function (id) { return document.getElementById(id); };
    var billData = <?= json_encode($billData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
    var receipts = <?= json_encode($receipts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
    var bhName   = <?= json_encode($bhName, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
    var suggest  = <?= json_encode($suggestReceipt) ?>;

    function peso(n) { return '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; }

    var successDialog = $('blSuccessDialog');
    if (successDialog) successDialog.showModal();

    var payDlg = $('payDialog'), billDlg = $('billDialog'), viewDlg = $('viewDialog');
    [payDlg, billDlg, viewDlg].forEach(function (d) {
        d.querySelectorAll('.js-close').forEach(function (b) { b.addEventListener('click', function () { d.close(); }); });
        d.addEventListener('click', function (e) { if (e.target === d) d.close(); });
    });

    /* ---------- Record payment ---------- */
    var sel = $('billing_id'), balInput = $('remaining_balance'), amt = $('amount_paid');
    var billingSearch = $('billingSearch'), billingOptions = $('billingOptions');
    var selectedBilling = $('selectedBilling'), paymentPicker = $('paymentPicker');
    var methodSel = $('payment_method');
    var methodPicker = $('paymentMethodPicker');
    var methodTrigger = $('paymentMethodTrigger');
    var methodMenu = $('paymentMethodOptions');
    var paymentContent = $('paymentContent');
    var methodLabel = $('paymentMethodLabel');
    var methodError = $('paymentMethodError');

    function closeMethodMenu() {
        methodMenu.hidden = true;
        methodMenu.style.maxHeight = '';
        methodTrigger.setAttribute('aria-expanded', 'false');
        methodPicker.classList.remove('opens-up');
    }

    function openMethodMenu() {
        methodMenu.hidden = false;
        var triggerRect = methodTrigger.getBoundingClientRect();
        var contentRect = paymentContent.getBoundingClientRect();
        var menuHeight = Math.min(220, methodMenu.scrollHeight);
        var spaceBelow = Math.max(0, contentRect.bottom - triggerRect.bottom - 8);
        var spaceAbove = Math.max(0, triggerRect.top - contentRect.top - 8);
        var opensUp = spaceBelow < menuHeight;
        var availableSpace = opensUp ? spaceAbove : spaceBelow;

        methodPicker.classList.toggle('opens-up', opensUp);
        methodMenu.style.maxHeight = Math.max(80, Math.min(220, availableSpace)) + 'px';
        methodTrigger.setAttribute('aria-expanded', 'true');
    }

    function setPaymentMethod(value) {
        methodSel.value = value || '';
        methodLabel.textContent = methodSel.value || 'Select method';
        methodMenu.querySelectorAll('.bl-method-option').forEach(function (option) {
            var selected = option.dataset.value === methodSel.value;
            option.setAttribute('aria-selected', selected ? 'true' : 'false');
            option.classList.toggle('active', selected);
        });
        closeMethodMenu();
        methodTrigger.setAttribute('aria-invalid', 'false');
        methodPicker.classList.remove('is-invalid');
        methodError.hidden = true;
    }

    methodTrigger.addEventListener('click', function () {
        if (methodMenu.hidden) {
            openMethodMenu();
        } else {
            closeMethodMenu();
        }
    });
    methodMenu.querySelectorAll('.bl-method-option').forEach(function (option) {
        option.addEventListener('click', function () { setPaymentMethod(option.dataset.value); });
    });
    methodTrigger.addEventListener('keydown', function (event) {
        if (event.key === 'ArrowDown' && methodMenu.hidden) {
            event.preventDefault();
            openMethodMenu();
            methodMenu.querySelector('.bl-method-option').focus();
        }
        if (event.key === 'Escape') {
            closeMethodMenu();
        }
    });
    methodMenu.querySelectorAll('.bl-method-option').forEach(function (option, index, options) {
        option.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeMethodMenu();
                methodTrigger.focus();
            } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                var next = event.key === 'ArrowDown' ? (index + 1) % options.length : (index - 1 + options.length) % options.length;
                options[next].focus();
            }
        });
    });

    function paymentOptionData(option) {
        return {
            id: option.value,
            name: option.dataset.name || '',
            period: option.dataset.period || '',
            due: option.dataset.due || '',
            balance: option.dataset.balance || '0.00'
        };
    }

    function syncBalance() {
        var option = sel.options[sel.selectedIndex];
        var balance = parseFloat((option && option.dataset.balance) || 0);
        balInput.value = peso(balance);
        amt.max = balance > 0 ? balance.toFixed(2) : '';
        if (option && option.value && !selectedBilling.hidden) {
            $('selectedBillingBalance').textContent = 'Balance ' + peso(balance);
        }
    }

    function renderBillingOptions(query) {
        var q = (query || '').trim().toLowerCase();
        billingOptions.innerHTML = '';
        var matches = Array.prototype.slice.call(sel.options).filter(function (option) {
            if (!option.value) return false;
            var d = paymentOptionData(option);
            return (d.name + ' ' + d.period + ' ' + d.due + ' ' + d.balance).toLowerCase().indexOf(q) !== -1;
        });

        if (!matches.length) {
            billingOptions.appendChild(el('div', 'bl-tenant-empty', 'No unpaid or partially paid bills match your search.'));
        } else {
            matches.forEach(function (option) {
                var d = paymentOptionData(option);
                var item = el('button', 'bl-tenant-option bl-payment-option');
                item.type = 'button';
                item.setAttribute('role', 'option');
                var copy = el('span', 'bl-tenant-option-copy');
                copy.appendChild(el('strong', '', d.name));
                copy.appendChild(el('small', '', d.period + ' · Due ' + d.due));
                item.appendChild(copy);
                item.appendChild(el('strong', 'bl-tenant-option-balance', peso(d.balance)));
                item.addEventListener('click', function () { chooseBilling(d.id); });
                billingOptions.appendChild(item);
            });
        }
        billingOptions.hidden = false;
        billingSearch.setAttribute('aria-expanded', 'true');
    }

    function chooseBilling(id) {
        billingSearch.setCustomValidity('');
        sel.value = String(id);
        var option = sel.options[sel.selectedIndex];
        if (!option || !option.value) return;
        var d = paymentOptionData(option);
        $('selectedBillingName').textContent = d.name;
        $('selectedBillingMeta').textContent = d.period + ' · Due ' + d.due;
        $('billingAvatar').textContent = d.name.charAt(0).toUpperCase();
        $('selectedBillingBalance').textContent = 'Balance ' + peso(d.balance);
        selectedBilling.hidden = false;
        billingSearch.value = '';
        billingSearch.hidden = true;
        billingOptions.hidden = true;
        billingSearch.setAttribute('aria-expanded', 'false');
        syncBalance();
        amt.value = '';
    }

    function clearBilling() {
        sel.value = '';
        selectedBilling.hidden = true;
        billingSearch.hidden = false;
        billingSearch.value = '';
        billingOptions.hidden = true;
        balInput.value = peso(0);
        amt.value = '';
        amt.removeAttribute('max');
        billingSearch.focus();
        renderBillingOptions('');
    }

    billingSearch.addEventListener('focus', function () { renderBillingOptions(billingSearch.value); });
    billingSearch.addEventListener('input', function () {
        billingSearch.setCustomValidity('');
        renderBillingOptions(billingSearch.value);
    });
    $('changeBilling').addEventListener('click', clearBilling);
    document.addEventListener('click', function (event) {
        if (!paymentPicker.contains(event.target)) {
            billingOptions.hidden = true;
            billingSearch.setAttribute('aria-expanded', 'false');
        }
        if (!methodPicker.contains(event.target)) {
            closeMethodMenu();
        }
    });
    $('payForm').addEventListener('submit', function (event) {
        if (!sel.value) {
            event.preventDefault();
            billingSearch.setCustomValidity('Please select a bill.');
            billingSearch.reportValidity();
            billingSearch.focus();
            return;
        }
        if (!methodSel.value) {
            event.preventDefault();
            methodPicker.classList.add('is-invalid');
            methodTrigger.setAttribute('aria-invalid', 'true');
            methodError.hidden = false;
            methodTrigger.focus();
        }
    });

    $('payFull').addEventListener('click', function () {
        var option = sel.options[sel.selectedIndex];
        if (option && option.dataset.balance) { amt.value = option.dataset.balance; }
    });

    function openPay(billId) {
        $('payForm').reset();
        sel.value = '';
        setPaymentMethod('');
        selectedBilling.hidden = true;
        billingSearch.hidden = false;
        billingSearch.value = '';
        billingOptions.hidden = true;
        balInput.value = peso(0);
        amt.removeAttribute('max');
        $('receipt_number').value = suggest;
        if (billId) { chooseBilling(billId); }
        payDlg.showModal();
        (billId ? amt : billingSearch).focus();
    }
    $('openPay').addEventListener('click', function () { openPay(null); });
    if (new URLSearchParams(window.location.search).get('action') === 'record') {
        openPay(null);
    }

    /* ---------- Create bill ---------- */
    var tenantSel = $('tenant_id'), rent = $('charge_rent');
    var tenantSearch = $('tenantSearch'), tenantOptions = $('tenantOptions');
    var selectedTenant = $('selectedTenant'), tenantPicker = $('tenantPicker');
    var chargeIds = ['charge_rent', 'charge_water', 'charge_electricity', 'charge_internet'];

    function billTotal() {
        var t = 0;
        chargeIds.forEach(function (id) { t += parseFloat($(id).value) || 0; });
        $('billTotal').textContent = peso(t);
    }

    function tenantOptionData(option) {
        var parts = option.textContent.trim().split('·');
        return {
            id: option.value,
            name: (parts[0] || '').trim(),
            room: (parts[1] || 'Room not assigned').trim(),
            rent: option.dataset.rent || '',
            start: option.dataset.start || '',
            end: option.dataset.end || '',
            due: option.dataset.due || ''
        };
    }

    function renderTenantOptions(query) {
        var q = (query || '').trim().toLowerCase();
        tenantOptions.innerHTML = '';
        var matches = Array.prototype.slice.call(tenantSel.options).filter(function (o) {
            if (!o.value) return false;
            var d = tenantOptionData(o);
            return (d.name + ' ' + d.room).toLowerCase().indexOf(q) !== -1;
        });

        if (!matches.length) {
            var empty = el('div', 'bl-tenant-empty', 'No matching tenant found.');
            tenantOptions.appendChild(empty);
        } else {
            matches.forEach(function (option) {
                var d = tenantOptionData(option);
                var item = el('button', 'bl-tenant-option');
                item.type = 'button';
                item.setAttribute('role', 'option');
                var copy = el('span', 'bl-tenant-option-copy');
                copy.appendChild(el('strong', '', d.name));
                copy.appendChild(el('small', '', d.room));
                item.appendChild(copy);
                item.addEventListener('click', function () { chooseTenant(d.id); });
                tenantOptions.appendChild(item);
            });
        }
        tenantOptions.hidden = false;
        tenantSearch.setAttribute('aria-expanded', 'true');
    }

    function formatDateMDY(isoDate) {
        var match = String(isoDate || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
        return match ? match[2] + '/' + match[3] + '/' + match[1] : '';
    }

    function formatMonthYear(isoDate) {
        var match = String(isoDate || '').match(/^(\d{4})-(\d{2})-\d{2}$/);
        if (!match) return '';
        var date = new Date(Number(match[1]), Number(match[2]) - 1, 1);
        return date.toLocaleDateString('en-PH', { month: 'long', year: 'numeric' });
    }

    function setBillingDates(start, end, due) {
        // Keep the exact billing-cycle dates in hidden fields; display a simple month/year.
        $('billing_start_date').value = start || '';
        $('billing_end_date').value = end || '';
        $('due_date').value = due || '';
        $('billing_month_display').value = formatMonthYear(start);
        $('due_date_display').value = formatDateMDY(due);
    }

    function chooseTenant(id) {
        tenantSel.value = String(id);
        var option = tenantSel.options[tenantSel.selectedIndex];
        if (!option || !option.value) return;
        var d = tenantOptionData(option);
        $('selectedTenantName').textContent = d.name;
        $('selectedTenantRoom').textContent = d.room;
        $('tenantAvatar').textContent = d.name.charAt(0).toUpperCase();
        selectedTenant.hidden = false;
        tenantSearch.value = '';
        tenantSearch.hidden = true;
        tenantOptions.hidden = true;
        tenantSearch.setAttribute('aria-expanded', 'false');
        rent.value = d.rent;
        setBillingDates(d.start, d.end, d.due);
        billTotal();
    }

    function clearTenant() {
        tenantSel.value = '';
        selectedTenant.hidden = true;
        tenantSearch.hidden = false;
        tenantSearch.value = '';
        rent.value = '';
        setBillingDates('', '', '');
        billTotal();
        tenantSearch.focus();
        renderTenantOptions('');
    }

    tenantSearch.addEventListener('focus', function () { renderTenantOptions(tenantSearch.value); });
    tenantSearch.addEventListener('input', function () { renderTenantOptions(tenantSearch.value); });
    $('changeTenant').addEventListener('click', clearTenant);
    document.addEventListener('click', function (event) {
        if (!tenantPicker.contains(event.target)) {
            tenantOptions.hidden = true;
            tenantSearch.setAttribute('aria-expanded', 'false');
        }
    });
    $('billForm').addEventListener('submit', function (event) {
        if (!tenantSel.value) {
            event.preventDefault();
            tenantSearch.hidden = false;
            tenantSearch.setCustomValidity('Please select a tenant.');
            tenantSearch.reportValidity();
            tenantSearch.addEventListener('input', function clearValidity() {
                tenantSearch.setCustomValidity('');
                tenantSearch.removeEventListener('input', clearValidity);
            });
            tenantSearch.focus();
        }
    });

    chargeIds.forEach(function (id) { $(id).addEventListener('input', billTotal); });
    $('openBill').addEventListener('click', function () {
        $('billForm').reset();
        tenantSel.value = '';
        selectedTenant.hidden = true;
        tenantSearch.hidden = false;
        tenantSearch.value = '';
        tenantOptions.hidden = true;
        setBillingDates('', '', '');
        billTotal();
        billDlg.showModal();
        tenantSearch.focus();
    });

    /* ---------- Bill details ---------- */
    var currentBill = null;
    function line(ul, label, value, strong) {
        var li = el('li'); li.appendChild(el(strong ? 'strong' : 'span', '', label)); li.appendChild(el(strong ? 'strong' : 'span', 'v', value)); ul.appendChild(li);
    }
    document.querySelectorAll('.js-view').forEach(function (b) {
        b.addEventListener('click', function () {
            var d = billData[b.dataset.id]; if (!d) return;
            currentBill = b.dataset.id;
            $('vTenant').textContent = d.tenant;
            $('vPeriod').textContent = d.period;
            $('vDue').textContent = d.due;
            var pill = $('vStatus'); pill.textContent = d.status; pill.className = 'bl-pill ' + d.status.toLowerCase();

            var ch = $('vCharges'); ch.innerHTML = '';
            if (!d.charges.length) { ch.appendChild(el('li', 'bl-none', 'No charges recorded.')); }
            d.charges.forEach(function (c) { line(ch, c.type, peso(c.amount)); });

            var sum = $('vSum'); sum.innerHTML = '';
            [['Total bill', peso(d.total)], ['Paid', peso(d.paid)], ['Balance', peso(d.balance)]].forEach(function (r, i) {
                var row = el('div', i === 2 ? 'row last' : 'row'); row.appendChild(el('span', '', r[0])); row.appendChild(el('strong', '', r[1])); sum.appendChild(row);
            });

            var ps = $('vPays'); ps.innerHTML = '';
            if (!d.payments.length) { ps.appendChild(el('li', 'bl-none', 'No payments yet.')); }
            d.payments.forEach(function (p) {
                var li = el('li'); var l = el('div'); l.appendChild(el('strong', '', p.receipt)); l.appendChild(el('small', '', p.date + ' · ' + p.method));
                li.appendChild(l); li.appendChild(el('span', 'v', peso(p.amount))); ps.appendChild(li);
            });

            $('vPay').hidden = !(d.balance > 0);
            viewDlg.showModal();
        });
    });
    $('vPay').addEventListener('click', function () { viewDlg.close(); openPay(currentBill); });

    /* ---------- Print receipt ---------- */
    function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    document.querySelectorAll('.js-receipt').forEach(function (b) {
        b.addEventListener('click', function () {
            var r = receipts[b.dataset.id]; if (!r) return;
            var html = '<!doctype html><html><head><meta charset="utf-8"><title>Receipt ' + esc(r.receipt) + '</title><style>' +
                'body{font-family:"Segoe UI",Arial,sans-serif;color:#1f2a2e;margin:0;padding:32px}.box{max-width:420px;margin:0 auto;border:1px solid #d9d6cc;border-radius:12px;padding:28px}' +
                'h1{font-size:20px;margin:0}.sub{color:#6b7a80;font-size:12px;margin:4px 0 18px}.no{display:flex;justify-content:space-between;font-size:13px;padding-bottom:14px;border-bottom:1px dashed #cfd6d2;margin-bottom:14px}' +
                '.row{display:flex;justify-content:space-between;gap:12px;font-size:13px;padding:6px 0}.row span:first-child{color:#6b7a80}' +
                '.amt{text-align:center;margin:18px 0;padding:16px;background:#f3f8f6;border-radius:10px}.amt small{display:block;color:#6b7a80;font-size:12px}.amt strong{font-size:28px}' +
                '.foot{text-align:center;color:#6b7a80;font-size:12px;margin-top:20px}</style></head><body><div class="box">' +
                '<h1>' + esc(bhName) + '</h1><p class="sub">Payment Receipt</p>' +
                '<div class="no"><span>Receipt No.</span><strong>' + esc(r.receipt) + '</strong></div>' +
                '<div class="row"><span>Date</span><span>' + esc(r.date) + '</span></div>' +
                '<div class="row"><span>Received from</span><span>' + esc(r.tenant) + '</span></div>' +
                '<div class="row"><span>Billing period</span><span>' + esc(r.period) + '</span></div>' +
                '<div class="row"><span>Method</span><span>' + esc(r.method) + '</span></div>' +
                '<div class="amt"><small>Amount paid</small><strong>' + esc(peso(r.amount)) + '</strong></div>' +
                '<div class="row"><span>Total bill</span><span>' + esc(peso(r.total)) + '</span></div>' +
                '<div class="row"><span>Total paid so far</span><span>' + esc(peso(r.paid)) + '</span></div>' +
                '<div class="row"><span>Remaining balance</span><span>' + esc(peso(r.balance)) + '</span></div>' +
                '<p class="foot">Thank you for your payment.</p></div></body></html>';
            var f = document.createElement('iframe'), done = false;
            f.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0';
            function doPrint() {
                if (done) return; done = true;
                f.contentWindow.focus(); f.contentWindow.print();
                setTimeout(function () { f.remove(); }, 2000);
            }
            f.onload = doPrint;
            f.srcdoc = html;
            document.body.appendChild(f);
        });
    });

    <?php if ($old && $oldForm === 'pay'): ?>
    (function () {
        var o = <?= json_encode($old, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
        if (o.billing_id) { chooseBilling(o.billing_id); } else { syncBalance(); }
        $('amount_paid').value = o.amount_paid || ''; setPaymentMethod(o.payment_method || '');
        $('receipt_number').value = o.receipt_number || suggest;
        payDlg.showModal();
    })();
    <?php elseif ($old && $oldForm === 'bill'): ?>
    (function () {
        var o = <?= json_encode($old, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
        ['tenant_id', 'billing_start_date', 'billing_end_date', 'due_date', 'charge_rent', 'charge_water', 'charge_electricity', 'charge_internet'].forEach(function (k) {
            if ($(k) && o[k] !== undefined) { $(k).value = o[k]; }
        });
        if (o.tenant_id) { chooseTenant(o.tenant_id); }
        billTotal(); billDlg.showModal();
    })();
    <?php endif; ?>

    /* ---------- Tabs ---------- */
    var tabs = document.querySelectorAll('.bl-tab');
    tabs.forEach(function (t) {
        t.addEventListener('click', function () {
            tabs.forEach(function (x) { x.classList.toggle('active', x === t); });
            $('panel-bills').hidden = t.dataset.tab !== 'bills';
            $('panel-history').hidden = t.dataset.tab !== 'history';
        });
    });

    /* ---------- Filters ---------- */
    var blSearch = $('blSearch'), blShown = $('blShown'), blNoMatch = $('blNoMatch');
    var activeFilter = 'all';

    function applyBillFilters() {
        var q = (blSearch && blSearch.value ? blSearch.value : '').trim().toLowerCase();
        var rows = document.querySelectorAll('#blBody tr[data-status]');
        var visible = 0;
        rows.forEach(function (row) {
            var st = row.dataset.status || '';
            var hay = row.dataset.search || '';
            var show = (activeFilter === 'all' || st === activeFilter) && (!q || hay.indexOf(q) !== -1);
            row.hidden = !show;
            if (show) visible++;
        });
        if (blShown) blShown.textContent = visible;
        if (blNoMatch) blNoMatch.hidden = visible > 0 || rows.length === 0;
    }

    document.querySelectorAll('.bl-chip').forEach(function (chip) {
        chip.addEventListener('click', function () {
            document.querySelectorAll('.bl-chip').forEach(function (c) { c.classList.toggle('active', c === chip); });
            activeFilter = chip.dataset.filter || 'all';
            applyBillFilters();
        });
    });
    if (blSearch) blSearch.addEventListener('input', applyBillFilters);

    var phSearch = $('phSearch'), phShown = $('phShown'), phNoMatch = $('phNoMatch');
    if (phSearch) {
        phSearch.addEventListener('input', function () {
            var q = (phSearch.value || '').trim().toLowerCase();
            var rows = document.querySelectorAll('#phBody tr[data-search]');
            var visible = 0;
            rows.forEach(function (row) {
                var hay = row.dataset.search || '';
                var show = !q || hay.indexOf(q) !== -1;
                row.hidden = !show;
                if (show) visible++;
            });
            if (phShown) phShown.textContent = visible;
            if (phNoMatch) phNoMatch.hidden = visible > 0 || rows.length === 0;
        });
    }
})();
</script>
</body>
</html>