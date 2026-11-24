<?php
session_start();
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

function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function peso($amount): string { return '₱' . number_format((float)$amount, 2); }
function fdate($d): string { return $d ? date('M j, Y', strtotime($d)) : '—'; }
function fperiod($s, $en): string {
    $a = strtotime($s); $b = strtotime($en);
    return date('Y', $a) === date('Y', $b)
        ? date('M j', $a) . ' – ' . date('M j, Y', $b)
        : date('M j, Y', $a) . ' – ' . date('M j, Y', $b);
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
            $start = $_POST['billing_start_date'] ?? '';
            $end   = $_POST['billing_end_date'] ?? '';
            $due   = $_POST['due_date'] ?? '';

            $charges = [];
            foreach (['Rent' => 'charge_rent', 'Water' => 'charge_water', 'Electricity' => 'charge_electricity', 'Internet' => 'charge_internet'] as $type => $field) {
                $v = trim($_POST[$field] ?? '');
                if ($v === '') { continue; }
                $v = filter_var($v, FILTER_VALIDATE_FLOAT);
                if ($v === false || $v < 0) { throw new Exception('Please enter valid charge amounts.'); }
                if ($v > 0) { $charges[$type] = round($v, 2); }
            }

            if (!$tenant_id) { throw new Exception('Please select a tenant.'); }
            foreach ([$start, $end, $due] as $d) {
                $dt = DateTime::createFromFormat('!Y-m-d', $d);
                if (!$dt || $dt->format('Y-m-d') !== $d) { throw new Exception('Please enter valid dates.'); }
            }
            if ($end < $start) { throw new Exception('The billing period end date cannot be before the start date.'); }
            if ($due < $start) { throw new Exception('The due date cannot be before the billing period starts.'); }
            if (!$charges) { throw new Exception('Add at least one charge greater than zero.'); }

            $pdo->beginTransaction();

            // Tenant ay dapat nakatira sa room ng owner na ito
            $q = $pdo->prepare("
                SELECT 1 FROM room_assignment ra
                INNER JOIN room r ON r.room_id = ra.room_id
                INNER JOIN boarding_house bh ON bh.bh_id = r.bh_id
                WHERE ra.tenant_id = ? AND ra.moveout_date IS NULL AND bh.owner_id = ?
            ");
            $q->execute([$tenant_id, $owner_id]);
            if (!$q->fetch()) { throw new Exception('Selected tenant is not staying in one of your rooms.'); }

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
        header('Location: owner_billing.php');      // iwas double-submit kapag nag-refresh
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
$billings = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Tenants na puwedeng bayaran / i-bill (may room ngayon)
$stmt = $pdo->prepare("
    SELECT t.tenant_id, t.first_name, t.last_name, r.room_number, r.monthly_rate
    FROM tenant t
    INNER JOIN room_assignment ra ON ra.tenant_id = t.tenant_id AND ra.moveout_date IS NULL
    INNER JOIN room r ON r.room_id = ra.room_id
    INNER JOIN boarding_house bh ON bh.bh_id = r.bh_id
    WHERE bh.owner_id = ? AND t.status = 'Active'
    ORDER BY t.last_name, t.first_name
");
$stmt->execute([$owner_id]);
$billable = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Kuwenta */
$today = date('Y-m-d');
$totalCollected = 0; $totalOutstanding = 0; $overdueCount = 0; $overdueAmount = 0;
$billData = []; $payByBill = [];

foreach ($payments as $p) {
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

    $totalCollected   += (float)$b['total_paid'];
    $totalOutstanding += $b['balance'];
    if ($b['shown'] === 'Overdue') { $overdueCount++; $overdueAmount += $b['balance']; }

    $billData[$b['billing_id']] = [
        'tenant' => $b['first_name'] . ' ' . $b['last_name'],
        'period' => fperiod($b['billing_start_date'], $b['billing_end_date']),
        'due' => fdate($b['due_date']), 'status' => $b['shown'],
        'total' => (float)$b['total_bill'], 'paid' => (float)$b['total_paid'], 'balance' => $b['balance'],
        'charges' => $charges[$b['billing_id']] ?? [], 'payments' => $payByBill[$b['billing_id']] ?? [],
    ];
}
unset($b);

$receipts = [];
foreach ($payments as $p) {
    $bd = $billData[$p['billing_id']] ?? null;
    $receipts[$p['payment_id']] = [
        'receipt' => $p['receipt_number'], 'date' => date('M j, Y h:i A', strtotime($p['payment_date'])),
        'tenant' => $p['first_name'] . ' ' . $p['last_name'], 'period' => fperiod($p['billing_start_date'], $p['billing_end_date']),
        'method' => $p['payment_method'], 'amount' => (float)$p['amount_paid'],
        'total' => $bd['total'] ?? 0, 'paid' => $bd['paid'] ?? 0, 'balance' => $bd['balance'] ?? 0,
    ];
}

$suggestReceipt = 'OR-' . date('Ymd') . '-' . str_pad((string)(count($payments) + 1), 3, '0', STR_PAD_LEFT);
$monthStart = date('Y-m-01'); $monthEnd = date('Y-m-t'); $monthDue = date('Y-m-05');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Billing &amp; Payments | Residentia</title>

    <link rel="stylesheet" href="../css/owner_db.css?v=4">
    <link rel="stylesheet" href="../css/owner_billing.css?v=6">
    <link rel="stylesheet" href="../css/owner_ui.css?v=1">
</head>
<body>
<div class="layout">

    <!-- SIDEBAR -->
    <aside class="sidebar">
        <picture>
            <img class="logo" src="../image/db_logo.png" alt="Residentia Boarding House">
        </picture>

        <nav class="nav">
            <a href="owner_db.php">Dashboard</a>
            <a href="owner_rooms.php">Rooms</a>
            <a href="owner_tenants.php">Tenants</a>
            <a href="owner_billing.php" class="active">Billing &amp; Payments</a>
            <a href="owner_maintenance.php">Maintenance</a>
            <a href="owner_announcement.php">Announcements</a>
            <a href="owner_reports.php">Reports</a>
        </nav>

        <a class="logout" href="../logout.php">Log out</a>
    </aside>

    <!-- MAIN -->
    <main class="main">
    <div class="billing-page">

        <header class="bl-header">
            <div>
                <h1>Billing &amp; Payments</h1>
                <p>Create bills, record payments and monitor outstanding balances.</p>
            </div>
            <div class="bl-header-actions">
                <button type="button" class="bl-btn-ghost" id="openBill">+ Create Bill</button>
                <button type="button" class="bl-add" id="openPay">+ Record Payment</button>
            </div>
        </header>

        <?php if ($flash): ?><div class="bl-alert <?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>
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
            <article class="bl-stat">
                <span class="bl-stat-label">Billing Records</span>
                <strong><?= count($billings) ?></strong>
                <small>Bills created</small>
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
                            <tr><td colspan="8" class="bl-empty">No billing records yet. Click “Create Bill” to add one.</td></tr>
                        <?php else: foreach ($billings as $b):
                            $st = strtolower($b['shown']);
                            $name = $b['first_name'] . ' ' . $b['last_name'];
                        ?>
                            <tr data-status="<?= e($st) ?>" data-search="<?= e(mb_strtolower($name)) ?>">
                                <td class="bl-left">
                                    <strong><?= e($name) ?></strong>
                                    <small>Bill #<?= (int)$b['billing_id'] ?></small>
                                </td>
                                <td><?= e(fperiod($b['billing_start_date'], $b['billing_end_date'])) ?></td>
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

        <?php if ($error && $oldForm === 'pay'): ?><div class="bl-alert error" role="alert"><?= e($error) ?></div><?php endif; ?>

        <section class="bl-form-section">
            <h3 class="bl-section-heading"><span>01</span> Billing Record</h3>
            <div class="bl-fields">
                <div class="bl-field full">
                    <label for="billing_id">Tenant / Billing Record</label>
                    <select name="billing_id" id="billing_id" required>
                        <option value="">Select a bill</option>
                        <?php foreach ($billings as $b): if ($b['balance'] <= 0) continue; ?>
                            <option value="<?= (int)$b['billing_id'] ?>" data-balance="<?= e(number_format($b['balance'], 2, '.', '')) ?>">
                                <?= e($b['first_name'] . ' ' . $b['last_name']) ?> · <?= e(date('M Y', strtotime($b['billing_start_date']))) ?> · Balance <?= peso($b['balance']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
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
                <label for="payment_method">Payment Method</label>
                <select name="payment_method" id="payment_method" required>
                    <option value="">Select method</option>
                    <option value="Cash">Cash</option>
                    <option value="GCash">GCash</option>
                    <option value="Bank Transfer">Bank Transfer</option>
                </select>
            </div>
            <div class="bl-field">
                <label for="receipt_number">Receipt Number</label>
                <input type="text" name="receipt_number" id="receipt_number" maxlength="50" required>
            </div>
            </div>
        </section>

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
            <div class="bl-alert error">No tenants are currently assigned to a room, so there is nobody to bill yet.</div>
        <?php endif; ?>

        <section class="bl-form-section">
            <h3 class="bl-section-heading"><span>01</span> Tenant Information</h3>
            <div class="bl-fields">
                <div class="bl-field full">
                    <label for="tenant_id">Tenant</label>
                    <select name="tenant_id" id="tenant_id" required>
                        <option value="">Select a tenant</option>
                        <?php foreach ($billable as $t): ?>
                            <option value="<?= (int)$t['tenant_id'] ?>" data-rent="<?= e(number_format((float)$t['monthly_rate'], 2, '.', '')) ?>">
                                <?= e($t['first_name'] . ' ' . $t['last_name']) ?> · <?= e($t['room_number']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </section>

        <section class="bl-form-section">
            <h3 class="bl-section-heading"><span>02</span> Billing Period</h3>
            <div class="bl-date-fields">
                <div class="bl-field">
                    <label for="billing_start_date">Period Start</label>
                    <input type="date" name="billing_start_date" id="billing_start_date" value="<?= $monthStart ?>" required>
                </div>
                <div class="bl-field">
                    <label for="billing_end_date">Period End</label>
                    <input type="date" name="billing_end_date" id="billing_end_date" value="<?= $monthEnd ?>" required>
                </div>
                <div class="bl-field">
                    <label for="due_date">Due Date</label>
                    <input type="date" name="due_date" id="due_date" value="<?= $monthDue ?>" required>
                </div>
            </div>
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
<dialog id="viewDialog" class="bl-dialog" aria-labelledby="vTenant">
    <div class="bl-form">
        <div class="bl-dlg-head">
            <div>
                <h2 id="vTenant"></h2>
                <p><span id="vPeriod"></span> · Due <span id="vDue"></span></p>
            </div>
            <button type="button" class="bl-x js-close" aria-label="Close">&times;</button>
        </div>

        <div class="bl-vstatus"><span class="bl-pill" id="vStatus"></span></div>

        <h3 class="bl-sub">Charges</h3>
        <ul class="bl-lines" id="vCharges"></ul>
        <div class="bl-sum" id="vSum"></div>

        <h3 class="bl-sub">Payments</h3>
        <ul class="bl-lines" id="vPays"></ul>

        <div class="bl-dlg-foot">
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

    var payDlg = $('payDialog'), billDlg = $('billDialog'), viewDlg = $('viewDialog');
    [payDlg, billDlg, viewDlg].forEach(function (d) {
        d.querySelectorAll('.js-close').forEach(function (b) { b.addEventListener('click', function () { d.close(); }); });
        d.addEventListener('click', function (e) { if (e.target === d) d.close(); });
    });

    /* ---------- Record payment ---------- */
    var sel = $('billing_id'), balInput = $('remaining_balance'), amt = $('amount_paid');
    function syncBalance() {
        var o = sel.options[sel.selectedIndex], b = parseFloat((o && o.dataset.balance) || 0);
        balInput.value = peso(b);
        amt.max = b > 0 ? b.toFixed(2) : '';
    }
    sel.addEventListener('change', function () { syncBalance(); amt.value = ''; });
    $('payFull').addEventListener('click', function () {
        var o = sel.options[sel.selectedIndex];
        if (o && o.dataset.balance) { amt.value = o.dataset.balance; }
    });
    function openPay(billId) {
        $('payForm').reset();
        $('receipt_number').value = suggest;
        if (billId) { sel.value = String(billId); }
        syncBalance();
        payDlg.showModal();
        (billId ? amt : sel).focus();
    }
    $('openPay').addEventListener('click', function () { openPay(null); });
    if (new URLSearchParams(window.location.search).get('action') === 'record') {
        openPay(null);
    }

    /* ---------- Create bill ---------- */
    var tenantSel = $('tenant_id'), rent = $('charge_rent');
    var chargeIds = ['charge_rent', 'charge_water', 'charge_electricity', 'charge_internet'];
    function billTotal() {
        var t = 0;
        chargeIds.forEach(function (id) { t += parseFloat($(id).value) || 0; });
        $('billTotal').textContent = peso(t);
    }
    chargeIds.forEach(function (id) { $(id).addEventListener('input', billTotal); });
    tenantSel.addEventListener('change', function () {
        var o = tenantSel.options[tenantSel.selectedIndex];
        rent.value = (o && o.dataset.rent) ? o.dataset.rent : '';
        billTotal();
    });
    $('openBill').addEventListener('click', function () {
        $('billForm').reset();
        billTotal();
        billDlg.showModal();
        tenantSel.focus();
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
        $('billing_id').value = o.billing_id || ''; syncBalance();
        $('amount_paid').value = o.amount_paid || ''; $('payment_method').value = o.payment_method || '';
        $('receipt_number').value = o.receipt_number || suggest;
        payDlg.showModal();
    })();
    <?php elseif ($old && $oldForm === 'bill'): ?>
    (function () {
        var o = <?= json_encode($old, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
        ['tenant_id', 'billing_start_date', 'billing_end_date', 'due_date', 'charge_rent', 'charge_water', 'charge_electricity', 'charge_internet'].forEach(function (k) {
            if ($(k) && o[k] !== undefined) { $(k).value = o[k]; }
        });
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
    function setupFilter(searchId, rowSel, shownId, noneId, chipSel, useChips) {
        var s = $(searchId), rows = document.querySelectorAll(rowSel), shown = $(shownId), none = $(noneId), f = 'all';
        function apply() {
            var q = (s.value || '').trim().toLowerCase(), n = 0;
            rows.forEach(function (r) {
                var ok = (!useChips || f === 'all' || r.dataset.status === f) && r.dataset.search.indexOf(q) !== -1;
                r.hidden = !ok; if (ok) n++;
            });
            shown.textContent = n; none.hidden = !(rows.length > 0 && n === 0);
        }
        s.addEventListener('input', apply);
        if (useChips) {
            var chips = document.querySelectorAll(chipSel);
            chips.forEach(function (c) {
                c.addEventListener('click', function () {
                    chips.forEach(function (x) { x.classList.remove('active'); });
                    c.classList.add('active'); f = c.dataset.filter; apply();
                });
            });
        }
    }
    setupFilter('blSearch', '#blBody tr[data-status]', 'blShown', 'blNoMatch', '.bl-chip', true);
    setupFilter('phSearch', '#phBody tr[data-search]', 'phShown', 'phNoMatch', '', false);
})();
</script>
</body>
</html>