<?php
date_default_timezone_set('Asia/Manila');
session_start();
require_once __DIR__ . '/../php/index_config.php';

if (!isset($_SESSION['role']) || strtolower(trim($_SESSION['role'])) !== 'owner') {
    header('Location: ../index.php');
    exit;
}

$owner_id = (int)($_SESSION['owner_id'] ?? 0);
if ($owner_id <= 0) {
    die('Owner ID is missing. Please log in again.');
}

$pdo = db();

function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function peso($amount): string { return '₱' . number_format((float)$amount, 2); }
function fdate($d): string { return $d ? date('M j, Y', strtotime($d)) : '—'; }
function fullName($r): string {
    return trim($r['first_name'] . ' ' . (!empty($r['middle_name']) ? $r['middle_name'] . ' ' : '') . $r['last_name']);
}
function validDate($d): bool {
    $dt = DateTime::createFromFormat('!Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d;
}

function displayDate($d): string {
    return $d ? date('m/d/Y', strtotime($d)) : '';
}

function normalizeReportDate($d): string {
    $d = trim((string)$d);
    $dt = DateTime::createFromFormat('!m/d/Y', $d);
    if ($dt && $dt->format('m/d/Y') === $d) {
        return $dt->format('Y-m-d');
    }
    return $d;
}

/* =========================================
   DATE RANGE + TAB
========================================= */
$startDate = normalizeReportDate($_GET['start_date'] ?? '');
$endDate   = normalizeReportDate($_GET['end_date'] ?? '');
if (!validDate($startDate)) { $startDate = date('Y-m-01'); }
if (!validDate($endDate))   { $endDate   = date('Y-m-d'); }
if ($startDate > $endDate)  { [$startDate, $endDate] = [$endDate, $startDate]; }

$tabs = [
    'overview'    => 'Overview',
    'payments'    => 'Payments',
    'expenses'    => 'Expenses',
    'occupancy'   => 'Occupancy',
    'tenants'     => 'Tenants',
    'maintenance' => 'Maintenance',
];
$tab = $_GET['tab'] ?? 'overview';
if (!isset($tabs[$tab])) { $tab = 'overview'; }

// Mabilis na saklaw ng petsa
$d3 = new DateTime('first day of this month');
$d3->modify('-2 months');
$quick = [
    'This month'    => [date('Y-m-01'), date('Y-m-d')],
    'Last month'    => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('first day of last month'))],
    'Last 3 months' => [$d3->format('Y-m-d'), date('Y-m-d')],
    'This year'     => [date('Y-01-01'), date('Y-m-d')],
];

$error = '';
$totalRooms = $totalBeds = $occupants = $activeTenants = $openMaintenance = 0;
$collected = $expensesTotal = $outstanding = 0.0;
$payments = $expenses = $methods = $roomsReport = $tenantReport = $maintenanceReport = [];
$monthly = [];

try {
    // Rooms at beds
    $q = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(r.capacity),0) FROM room r
                        INNER JOIN boarding_house bh ON bh.bh_id = r.bh_id WHERE bh.owner_id = ?");
    $q->execute([$owner_id]);
    [$totalRooms, $totalBeds] = array_map('intval', $q->fetch(PDO::FETCH_NUM));

    // Kasalukuyang nakatira
    $q = $pdo->prepare("SELECT COUNT(*) FROM room_assignment ra
                        INNER JOIN room r ON r.room_id = ra.room_id
                        INNER JOIN boarding_house bh ON bh.bh_id = r.bh_id
                        WHERE bh.owner_id = ? AND ra.moveout_date IS NULL");
    $q->execute([$owner_id]);
    $occupants = (int)$q->fetchColumn();

    $q = $pdo->prepare("SELECT COUNT(DISTINCT t.tenant_id) FROM tenant t
                        INNER JOIN room_assignment ra ON ra.tenant_id = t.tenant_id AND ra.moveout_date IS NULL
                        INNER JOIN room r ON r.room_id = ra.room_id
                        INNER JOIN boarding_house bh ON bh.bh_id = r.bh_id
                        WHERE bh.owner_id = ? AND t.status = 'Active'");
    $q->execute([$owner_id]);
    $activeTenants = (int)$q->fetchColumn();

    // Pera (sa napiling petsa)
    $q = $pdo->prepare("SELECT COALESCE(SUM(amount_paid),0) FROM payment WHERE owner_id = ? AND DATE(payment_date) BETWEEN ? AND ?");
    $q->execute([$owner_id, $startDate, $endDate]);
    $collected = (float)$q->fetchColumn();

    $q = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expense WHERE owner_id = ? AND expense_date BETWEEN ? AND ?");
    $q->execute([$owner_id, $startDate, $endDate]);
    $expensesTotal = (float)$q->fetchColumn();

    // Outstanding (lahat ng bill)
    $q = $pdo->prepare("
        SELECT COALESCE(SUM(GREATEST(t.total_bill - t.total_paid, 0)), 0) FROM (
            SELECT COALESCE((SELECT SUM(bd.amount) FROM billing_details bd WHERE bd.billing_id = b.billing_id), 0) AS total_bill,
                   COALESCE((SELECT SUM(p.amount_paid) FROM payment p WHERE p.billing_id = b.billing_id), 0) AS total_paid
            FROM billing b WHERE b.owner_id = ?
        ) t");
    $q->execute([$owner_id]);
    $outstanding = (float)$q->fetchColumn();

    $q = $pdo->prepare("SELECT COUNT(*) FROM maintenance_request WHERE owner_id = ? AND status IN ('Pending','In Progress')");
    $q->execute([$owner_id]);
    $openMaintenance = (int)$q->fetchColumn();

    // Payments
    $q = $pdo->prepare("
        SELECT p.payment_id, p.receipt_number, p.payment_method, p.payment_date, p.amount_paid,
               t.first_name, t.middle_name, t.last_name, b.billing_start_date
        FROM payment p
        INNER JOIN billing b ON b.billing_id = p.billing_id
        INNER JOIN tenant t ON t.tenant_id = b.tenant_id
        WHERE p.owner_id = ? AND DATE(p.payment_date) BETWEEN ? AND ?
        ORDER BY p.payment_date DESC, p.payment_id DESC");
    $q->execute([$owner_id, $startDate, $endDate]);
    $payments = $q->fetchAll(PDO::FETCH_ASSOC);

    // Payment summary ayon sa method
    $q = $pdo->prepare("SELECT payment_method, COUNT(*) AS n, SUM(amount_paid) AS total FROM payment
                        WHERE owner_id = ? AND DATE(payment_date) BETWEEN ? AND ?
                        GROUP BY payment_method ORDER BY total DESC");
    $q->execute([$owner_id, $startDate, $endDate]);
    $methods = $q->fetchAll(PDO::FETCH_ASSOC);

    // Expenses
    $q = $pdo->prepare("SELECT expense_id, expense_category, description, amount, expense_date FROM expense
                        WHERE owner_id = ? AND expense_date BETWEEN ? AND ?
                        ORDER BY expense_date DESC, expense_id DESC");
    $q->execute([$owner_id, $startDate, $endDate]);
    $expenses = $q->fetchAll(PDO::FETCH_ASSOC);

    // Buwanang kita vs gastos (huling 6 na buwan hanggang sa buwan ng "To")
    $months = [];
    $baseMonth = new DateTime(date('Y-m-01', strtotime($endDate)));
    for ($i = 5; $i >= 0; $i--) {
        $mDt = clone $baseMonth;
        if ($i > 0) {
            $mDt->modify("-{$i} months");
        }
        $ym = $mDt->format('Y-m');
        $months[$ym] = [
            'label'   => $mDt->format('M'),
            'full'    => $mDt->format('F Y'),
            'income'  => 0.0,
            'expense' => 0.0,
        ];
    }
    $first = array_key_first($months) . '-01';
    $last  = date('Y-m-t', strtotime(array_key_last($months) . '-01'));

    $q = $pdo->prepare("SELECT DATE_FORMAT(payment_date,'%Y-%m') ym, SUM(amount_paid) s FROM payment
                        WHERE owner_id = ? AND DATE(payment_date) BETWEEN ? AND ? GROUP BY ym");
    $q->execute([$owner_id, $first, $last]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (isset($months[$r['ym']])) { $months[$r['ym']]['income'] = (float)$r['s']; }
    }

    $q = $pdo->prepare("SELECT DATE_FORMAT(expense_date,'%Y-%m') ym, SUM(amount) s FROM expense
                        WHERE owner_id = ? AND expense_date BETWEEN ? AND ? GROUP BY ym");
    $q->execute([$owner_id, $first, $last]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (isset($months[$r['ym']])) { $months[$r['ym']]['expense'] = (float)$r['s']; }
    }
    $monthly = $months;

    // Occupancy ng bawat room
    $q = $pdo->prepare("
        SELECT r.room_number, r.room_type, r.capacity, r.monthly_rate, r.room_status, bh.bh_name,
               (SELECT COUNT(*) FROM room_assignment ra WHERE ra.room_id = r.room_id AND ra.moveout_date IS NULL) AS occupants
        FROM room r INNER JOIN boarding_house bh ON bh.bh_id = r.bh_id
        WHERE bh.owner_id = ? ORDER BY bh.bh_name, r.room_number");
    $q->execute([$owner_id]);
    $roomsReport = $q->fetchAll(PDO::FETCH_ASSOC);

    // Mga tenant na nakatira ngayon
    $q = $pdo->prepare("
        SELECT t.first_name, t.middle_name, t.last_name, t.email, t.status, r.room_number, bh.bh_name, ra.movein_date,
               (SELECT c.contact_number FROM tenant_contact_number c WHERE c.tenant_id = t.tenant_id ORDER BY c.number_id LIMIT 1) AS contact_number
        FROM tenant t
        INNER JOIN room_assignment ra ON ra.tenant_id = t.tenant_id AND ra.moveout_date IS NULL
        INNER JOIN room r ON r.room_id = ra.room_id
        INNER JOIN boarding_house bh ON bh.bh_id = r.bh_id
        WHERE bh.owner_id = ? ORDER BY t.last_name, t.first_name");
    $q->execute([$owner_id]);
    $tenantReport = $q->fetchAll(PDO::FETCH_ASSOC);

    // Maintenance: sa saklaw ng petsa, kasama ang lahat ng bukas pa
    $q = $pdo->prepare("
        SELECT mr.request_id, mr.request_title, mr.request_description, mr.request_date, mr.status, mr.completion_date,
               t.first_name, t.middle_name, t.last_name, r.room_number
        FROM maintenance_request mr
        INNER JOIN tenant t ON t.tenant_id = mr.tenant_id
        INNER JOIN room r ON r.room_id = mr.room_id
        WHERE mr.owner_id = ?
          AND (mr.request_date BETWEEN ? AND ? OR mr.status IN ('Pending','In Progress'))
        ORDER BY mr.request_date DESC, mr.request_id DESC");
    $q->execute([$owner_id, $startDate, $endDate]);
    $maintenanceReport = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $ex) {
    error_log($ex->getMessage());
    $error = 'Unable to generate report data. Please check the database.';
}

/* =========================================
   CSV EXPORT (bago mag-render ng HTML)
========================================= */
$export = $_GET['export'] ?? '';
if (($export === 'payments' || $export === 'expenses') && !$error) {
    if (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="residentia-' . $export . '-' . $startDate . '-to-' . $endDate . '.csv"');
    $out = fopen('php://output', 'w');
    $safe = fn($v) => preg_match('/^[=+\-@]/', (string)$v) ? "'" . $v : $v;
    if ($export === 'payments') {
        fputcsv($out, ['Receipt', 'Tenant', 'Billing Period', 'Payment Date', 'Method', 'Amount']);
        foreach ($payments as $p) {
            fputcsv($out, [
                $p['receipt_number'],
                $safe(fullName($p)),
                date('M Y', strtotime($p['billing_start_date'])),
                date('M d, Y h:i A', strtotime($p['payment_date'])),
                $p['payment_method'],
                number_format((float)$p['amount_paid'], 2, '.', ''),
            ]);
        }
    } else {
        fputcsv($out, ['Date', 'Category', 'Description', 'Amount']);
        foreach ($expenses as $x) {
            fputcsv($out, [
                $x['expense_date'],
                $x['expense_category'],
                $safe($x['description']),
                number_format((float)$x['amount'], 2, '.', ''),
            ]);
        }
    }
    fclose($out);
    exit;
}

$net = $collected - $expensesTotal;
$occRate = $totalBeds > 0 ? round($occupants / $totalBeds * 100) : 0;
$maxMonth = 0;
foreach ($monthly as $m) { $maxMonth = max($maxMonth, $m['income'], $m['expense']); }
$methodMax = 0;
foreach ($methods as $m) { $methodMax = max($methodMax, (float)$m['total']); }

function url(array $extra = []): string {
    global $startDate, $endDate, $tab;
    return 'owner_reports.php?' . http_build_query(array_merge([
        'start_date' => $startDate,
        'end_date'   => $endDate,
        'tab'        => $tab,
    ], $extra));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports | Residentia</title>
    <link rel="stylesheet" href="../css/owner_db.css?v=5">
    <link rel="stylesheet" href="../css/owner_reports.css?v=6">
    <link rel="stylesheet" href="../css/owner_ui.css?v=14">
</head>
<body>
<div class="layout">

    <!-- SIDEBAR -->
    <aside class="sidebar">
        <picture><img class="logo" src="../image/db_logo.png" alt="Residentia Boarding House"></picture>
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
    <div class="reports-page">

        <header class="rp-header page-header-banner">
            <div>
                <h1>Reports</h1>
                <p>Review income, expenses and property performance.</p>
            </div>
            <button type="button" class="rp-print" onclick="window.print()">Print Report</button>
        </header>

        <div class="rp-printhead">
            <strong>Residentia Boarding House — Report</strong>
            <span><?= e(fdate($startDate)) ?> to <?= e(fdate($endDate)) ?></span>
        </div>

        <?php if ($error): ?><div class="rp-alert error" role="alert"><?= e($error) ?></div><?php endif; ?>

        <!-- SUMMARY -->
        <section class="rp-stats">
            <article class="rp-stat income">
                <span class="rp-stat-label">Collected</span>
                <strong><?= peso($collected) ?></strong>
                <small>Payments in selected dates</small>
            </article>
            <article class="rp-stat expense">
                <span class="rp-stat-label">Expenses</span>
                <strong><?= peso($expensesTotal) ?></strong>
                <small>Recorded in selected dates</small>
            </article>
            <article class="rp-stat net <?= $net < 0 ? 'neg' : 'pos' ?>">
                <span class="rp-stat-label">Net Income</span>
                <strong><?= $net < 0 ? '−' : '' ?><?= peso(abs($net)) ?></strong>
                <small>Collected minus expenses</small>
            </article>
            <article class="rp-stat out">
                <span class="rp-stat-label">Outstanding</span>
                <strong><?= peso($outstanding) ?></strong>
                <small>Unpaid balances (all bills)</small>
            </article>
        </section>

        <!-- TABS -->
        <section class="rp-card">
            <!-- FILTER -->
            <section class="rp-filter">
                <div class="rp-quick" role="group" aria-label="Quick date ranges">
                    <?php foreach ($quick as $label => [$s, $en]): $on = ($s === $startDate && $en === $endDate); ?>
                        <a class="rp-chip js-keep<?= $on ? ' active' : '' ?>" href="<?= e(url(['start_date' => $s, 'end_date' => $en])) ?>"><?= e($label) ?></a>
                    <?php endforeach; ?>
                </div>
                <form method="GET" class="rp-dates" id="rpForm">
                    <input type="hidden" name="tab" id="rpTab" value="<?= e($tab) ?>">
                    <input type="hidden" name="start_date" id="start_date_iso">
                    <input type="hidden" name="end_date" id="end_date_iso">
                    <div class="rp-field"><label for="start_date_display">From</label><input type="text" id="start_date_display" class="rp-date-display" value="<?= e(displayDate($startDate)) ?>" placeholder="MM/DD/YYYY" maxlength="10" inputmode="numeric" autocomplete="off"></div>
                    <div class="rp-field"><label for="end_date_display">To</label><input type="text" id="end_date_display" class="rp-date-display" value="<?= e(displayDate($endDate)) ?>" placeholder="MM/DD/YYYY" maxlength="10" inputmode="numeric" autocomplete="off"></div>
                    <button type="submit" class="rp-apply">Apply</button>
                </form>
            </section>

            <div class="rp-tabs" role="tablist">
                <?php foreach ($tabs as $key => $label):
                    $count = [
                        'payments'    => count($payments),
                        'expenses'    => count($expenses),
                        'occupancy'   => count($roomsReport),
                        'tenants'     => count($tenantReport),
                        'maintenance' => count($maintenanceReport),
                    ][$key] ?? null; ?>
                    <button type="button" class="rp-tab<?= $tab === $key ? ' active' : '' ?>" role="tab" data-tab="<?= e($key) ?>">
                        <?= e($label) ?><?php if ($count !== null): ?> <span><?= $count ?></span><?php endif; ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <!-- OVERVIEW -->
            <div class="rp-panel" id="panel-overview"<?= $tab === 'overview' ? '' : ' hidden' ?>>
                <h3 class="rp-ptitle">Overview</h3>
                <div class="rp-overview">
                    <div class="rp-box">
                        <div class="rp-box-head">
                            <h4>Monthly Income vs Expenses <small class="rp-subnote">(full months)</small></h4>
                            <div class="rp-legend"><span class="inc">Income</span><span class="exp">Expenses</span></div>
                        </div>
                        <div class="rp-chart" role="img" aria-label="Income and expenses for the last 6 months">
                            <?php foreach ($monthly as $m):
                                $hi = $maxMonth > 0 ? ($m['income'] / $maxMonth * 100) : 0;
                                $he = $maxMonth > 0 ? ($m['expense'] / $maxMonth * 100) : 0; ?>
                                <div class="rp-col">
                                    <div class="rp-bars">
                                        <span class="rp-bar inc" style="height:<?= $m['income'] > 0 ? max($hi, 3) : 0 ?>%" title="<?= e($m['full'] . ' income: ' . peso($m['income'])) ?>"></span>
                                        <span class="rp-bar exp" style="height:<?= $m['expense'] > 0 ? max($he, 3) : 0 ?>%" title="<?= e($m['full'] . ' expenses: ' . peso($m['expense'])) ?>"></span>
                                    </div>
                                    <div class="rp-mlabel"><?= e($m['label']) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="rp-table-wrap">
                            <table class="rp-table compact">
                                <thead><tr><th class="rp-left">Month</th><th>Income</th><th>Expenses</th><th>Net</th></tr></thead>
                                <tbody>
                                <?php foreach (array_reverse($monthly, true) as $m): $n = $m['income'] - $m['expense']; ?>
                                    <tr>
                                        <td class="rp-left"><?= e($m['full']) ?></td>
                                        <td><?= peso($m['income']) ?></td>
                                        <td><?= peso($m['expense']) ?></td>
                                        <td class="<?= $n < 0 ? 'rp-neg' : '' ?>"><strong><?= $n < 0 ? '−' : '' ?><?= peso(abs($n)) ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="rp-side">
                        <div class="rp-box">
                            <h4>Property Snapshot</h4>
                            <div class="rp-meter">
                                <div class="rp-meter-top"><span>Beds filled</span><strong><?= $occRate ?>%</strong></div>
                                <div class="rp-bar-track"><span style="width:<?= min(100, $occRate) ?>%"></span></div>
                                <small><?= $occupants ?> of <?= $totalBeds ?> beds · <?= $totalRooms ?> rooms</small>
                            </div>
                            <ul class="rp-facts">
                                <li><span>Active tenants</span><strong><?= $activeTenants ?></strong></li>
                                <li><span>Open maintenance</span><strong><?= $openMaintenance ?></strong></li>
                            </ul>
                        </div>

                        <div class="rp-box">
                            <h4>Payments by Method <small class="rp-subnote">(selected dates)</small></h4>
                            <?php if (!$methods): ?>
                                <p class="rp-none">No payments in this period.</p>
                            <?php else: foreach ($methods as $m): $w = $methodMax > 0 ? ($m['total'] / $methodMax * 100) : 0; ?>
                                <div class="rp-method">
                                    <div class="rp-method-top">
                                        <span><?= e($m['payment_method']) ?> <small>· <?= (int)$m['n'] ?> payment<?= (int)$m['n'] === 1 ? '' : 's' ?></small></span>
                                        <strong><?= peso($m['total']) ?></strong>
                                    </div>
                                    <div class="rp-bar-track thin"><span style="width:<?= round($w) ?>%"></span></div>
                                </div>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- PAYMENTS -->
            <div class="rp-panel" id="panel-payments"<?= $tab === 'payments' ? '' : ' hidden' ?>>
                <div class="rp-phead">
                    <h3 class="rp-ptitle">Payment Report <small>· <?= count($payments) ?> payment(s) · <?= peso($collected) ?></small></h3>
                    <a class="rp-export" href="<?= e(url(['export' => 'payments'])) ?>">Export CSV</a>
                </div>
                <div class="rp-table-wrap"><table class="rp-table">
                    <thead><tr><th>Receipt</th><th class="rp-left">Tenant</th><th>Billing Period</th><th>Payment Date</th><th>Method</th><th>Amount</th></tr></thead>
                    <tbody>
                    <?php if (!$payments): ?><tr><td colspan="6" class="rp-empty">No payments found for this period.</td></tr>
                    <?php else: foreach ($payments as $p): ?>
                        <tr>
                            <td><strong><?= e($p['receipt_number']) ?></strong></td>
                            <td class="rp-left"><?= e(fullName($p)) ?></td>
                            <td><?= e(date('M Y', strtotime($p['billing_start_date']))) ?></td>
                            <td><?= e(date('M j, Y · h:i A', strtotime($p['payment_date']))) ?></td>
                            <td><?= e($p['payment_method']) ?></td>
                            <td><strong><?= peso($p['amount_paid']) ?></strong></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table></div>
            </div>

            <!-- EXPENSES -->
            <div class="rp-panel" id="panel-expenses"<?= $tab === 'expenses' ? '' : ' hidden' ?>>
                <div class="rp-phead">
                    <h3 class="rp-ptitle">Expense Report <small>· <?= count($expenses) ?> expense(s) · <?= peso($expensesTotal) ?></small></h3>
                    <a class="rp-export" href="<?= e(url(['export' => 'expenses'])) ?>">Export CSV</a>
                </div>
                <div class="rp-table-wrap"><table class="rp-table">
                    <thead><tr><th>Date</th><th>Category</th><th class="rp-left">Description</th><th>Amount</th></tr></thead>
                    <tbody>
                    <?php if (!$expenses): ?><tr><td colspan="4" class="rp-empty">No expenses found for this period.</td></tr>
                    <?php else: foreach ($expenses as $x): ?>
                        <tr>
                            <td><?= e(fdate($x['expense_date'])) ?></td>
                            <td><?= e($x['expense_category']) ?></td>
                            <td class="rp-left rp-wrap"><?= e($x['description']) ?></td>
                            <td><strong><?= peso($x['amount']) ?></strong></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table></div>
            </div>

            <!-- OCCUPANCY -->
            <div class="rp-panel" id="panel-occupancy"<?= $tab === 'occupancy' ? '' : ' hidden' ?>>
                <h3 class="rp-ptitle">Room Occupancy <small>· <?= $occupants ?> of <?= $totalBeds ?> beds filled (<?= $occRate ?>%)</small></h3>
                <div class="rp-table-wrap"><table class="rp-table">
                    <thead><tr><th>Room</th><th>Type</th><th>Occupancy</th><th>Monthly Rate</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if (!$roomsReport): ?><tr><td colspan="5" class="rp-empty">No rooms found.</td></tr>
                    <?php else: foreach ($roomsReport as $r): $st = strtolower($r['room_status']); ?>
                        <tr>
                            <td><strong><?= e($r['room_number']) ?></strong></td>
                            <td><?= e($r['room_type']) ?></td>
                            <td><strong><?= (int)$r['occupants'] ?> / <?= (int)$r['capacity'] ?></strong></td>
                            <td><?= peso($r['monthly_rate']) ?></td>
                            <td><span class="rp-pill <?= e($st) ?>"><?= e($r['room_status']) ?></span></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table></div>
            </div>

            <!-- TENANTS -->
            <div class="rp-panel" id="panel-tenants"<?= $tab === 'tenants' ? '' : ' hidden' ?>>
                <h3 class="rp-ptitle">Tenant List <small>· current occupants</small></h3>
                <div class="rp-table-wrap"><table class="rp-table">
                    <thead><tr><th class="rp-left">Tenant</th><th>Contact</th><th>Room</th><th>Moved In</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if (!$tenantReport): ?><tr><td colspan="5" class="rp-empty">No tenants are currently staying.</td></tr>
                    <?php else: foreach ($tenantReport as $t): $st = strtolower($t['status']); ?>
                        <tr>
                            <td class="rp-left"><strong><?= e(fullName($t)) ?></strong><small><?= e($t['email']) ?></small></td>
                            <td><?= e($t['contact_number'] ?? '—') ?></td>
                            <td><strong><?= e($t['room_number']) ?></strong></td>
                            <td><?= e(fdate($t['movein_date'])) ?></td>
                            <td><span class="rp-pill <?= e($st) ?>"><?= e($t['status']) ?></span></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table></div>
            </div>

            <!-- MAINTENANCE -->
            <div class="rp-panel" id="panel-maintenance"<?= $tab === 'maintenance' ? '' : ' hidden' ?>>
                <h3 class="rp-ptitle">Maintenance Report <small>· requests in this period and all open requests</small></h3>
                <div class="rp-table-wrap"><table class="rp-table">
                    <thead><tr><th class="rp-left">Request</th><th>Tenant</th><th>Room</th><th>Requested</th><th>Completed</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if (!$maintenanceReport): ?><tr><td colspan="6" class="rp-empty">No maintenance requests found.</td></tr>
                    <?php else: foreach ($maintenanceReport as $m): $st = strtolower(str_replace(' ', '', $m['status'])); ?>
                        <tr>
                            <td class="rp-left rp-wrap"><strong><?= e($m['request_title']) ?></strong><small><?= e($m['request_description']) ?></small></td>
                            <td><?= e(fullName($m)) ?></td>
                            <td><strong><?= e($m['room_number']) ?></strong></td>
                            <td><?= e(fdate($m['request_date'])) ?></td>
                            <td><?= $m['completion_date'] ? e(fdate($m['completion_date'])) : '—' ?></td>
                            <td><span class="rp-pill <?= e($st) ?>"><?= e($m['status']) ?></span></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table></div>
            </div>
        </section>

    </div>
    </main>
</div>

<script>
(function () {
    var tabs = document.querySelectorAll('.rp-tab');
    var panels = document.querySelectorAll('.rp-panel');
    var tabInput = document.getElementById('rpTab');

    var dateDisplays = document.querySelectorAll('.rp-date-display');
    var isoStart = document.getElementById('start_date_iso');
    var isoEnd = document.getElementById('end_date_iso');

    function normalizeDisplay(value) {
        var digits = value.replace(/\D/g, '').slice(0, 8);
        if (digits.length > 4) return digits.slice(0, 2) + '/' + digits.slice(2, 4) + '/' + digits.slice(4);
        if (digits.length > 2) return digits.slice(0, 2) + '/' + digits.slice(2);
        return digits;
    }

    function toIso(value) {
        var match = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(value);
        if (!match) return '';
        var date = new Date(Number(match[3]), Number(match[1]) - 1, Number(match[2]));
        if (date.getFullYear() !== Number(match[3]) ||
            date.getMonth() !== Number(match[1]) - 1 ||
            date.getDate() !== Number(match[2])) return '';
        return match[3] + '-' + match[1] + '-' + match[2];
    }

    dateDisplays.forEach(function (input) {
        input.addEventListener('input', function () {
            input.value = normalizeDisplay(input.value);
        });
    });

    document.getElementById('rpForm').addEventListener('submit', function (event) {
        var start = toIso(document.getElementById('start_date_display').value);
        var end = toIso(document.getElementById('end_date_display').value);
        if (!start || !end) {
            event.preventDefault();
            alert('Please enter valid dates in MM/DD/YYYY format.');
            return;
        }
        isoStart.value = start;
        isoEnd.value = end;
    });

    function setTab(key) {
        tabs.forEach(function (t) { t.classList.toggle('active', t.dataset.tab === key); });
        panels.forEach(function (p) { p.hidden = p.id !== 'panel-' + key; });
        tabInput.value = key;
        document.querySelectorAll('.js-keep').forEach(function (a) {
            var u = new URL(a.getAttribute('href'), location.href);
            u.searchParams.set('tab', key);
            a.setAttribute('href', u.pathname + u.search);
        });
    }
    tabs.forEach(function (t) { t.addEventListener('click', function () { setTab(t.dataset.tab); }); });
})();
</script>
</body>
</html>