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

function billingPeriod($start): string
{
    $startDate = $start ? strtotime($start) : false;
    return $startDate ? date('F Y', $startDate) : '—';
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
    <link rel="stylesheet" href="../css/owner_ui.css?v=11">
    <link rel="stylesheet" href="../css/owner_billing.css?v=1">
    <link rel="stylesheet" href="../css/tenant_billing.css?v=3">
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <picture>
            <img class="logo" src="../image/db_logo.png" alt="Residentia Boarding House">
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

    <main class="main">
        <div class="billing-page tenant-billing-page">
            <header class="bl-header page-header-banner">
                <div>
                    <h1>Billing &amp; Payments</h1>
                    <p>View your billing statements, due dates, and payment history.</p>
                </div>
            </header>

            <section class="bl-card tenant-billing-records" aria-label="Billing records and payment history">
                <div class="tenant-billing-toolbar">
                    <div class="bl-tabs" role="tablist" aria-label="Billing sections">
                        <button
                            type="button"
                            class="bl-tab active"
                            id="tenant-bills-tab"
                            role="tab"
                            aria-selected="true"
                            aria-controls="tenant-bills-panel"
                            data-tab="bills"
                        >
                            Billing Records <span><?= count($billings) ?></span>
                        </button>
                        <button
                            type="button"
                            class="bl-tab"
                            id="tenant-payments-tab"
                            role="tab"
                            aria-selected="false"
                            aria-controls="tenant-payments-panel"
                            tabindex="-1"
                            data-tab="payments"
                        >
                            Payment History <span><?= count($payments) ?></span>
                        </button>
                    </div>
                    <div class="bl-search">
                        <input type="search" id="tenantBillingSearch" placeholder="Search billing records" aria-label="Search billing records">
                    </div>
                </div>

                <section class="bl-panel" id="tenant-bills-panel" role="tabpanel" aria-labelledby="tenant-bills-tab" tabindex="0">
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
                                        <tr data-tenant-bill-row>
                                            <td><strong>Bill #<?= (int) $bill['billing_id'] ?></strong></td>
                                            <td><?= e(billingPeriod($bill['billing_start_date'])) ?></td>
                                            <td><?= e(billingDate($bill['due_date'])) ?></td>
                                            <td><?= e(peso($bill['total_bill'])) ?></td>
                                            <td><?= e(peso($bill['total_paid'])) ?></td>
                                            <td class="<?= $bill['balance'] > 0 ? 'bl-due' : 'bl-zero' ?>"><?= e(peso($bill['balance'])) ?></td>
                                            <td><span class="bl-pill <?= e($statusClass) ?>"><?= e($bill['shown_status']) ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr id="tenantBillsNoMatch" hidden><td colspan="7" class="bl-empty">No billing records match your search.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="bl-panel" id="tenant-payments-panel" role="tabpanel" aria-labelledby="tenant-payments-tab" tabindex="0" hidden>
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
                                        <tr data-tenant-payment-row>
                                            <td><strong><?= e($payment['receipt_number']) ?></strong></td>
                                            <td><?= e(billingPeriod($payment['billing_start_date'])) ?></td>
                                            <td><?= e(billingDate($payment['payment_date'])) ?></td>
                                            <td><?= e($payment['payment_method']) ?></td>
                                            <td><strong><?= e(peso($payment['amount_paid'])) ?></strong></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr id="tenantPaymentsNoMatch" hidden><td colspan="5" class="bl-empty">No payments match your search.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </section>
        </div>
    </main>
</div>
<script>
(function () {
    var tabs = Array.prototype.slice.call(document.querySelectorAll('.tenant-billing-records .bl-tab'));
    var panels = {
        bills: document.getElementById('tenant-bills-panel'),
        payments: document.getElementById('tenant-payments-panel')
    };
    var searchInput = document.getElementById('tenantBillingSearch');
    var activeTab = 'bills';
    var searchQueries = { bills: '', payments: '' };
    var searchTargets = {
        bills: {
            rows: '[data-tenant-bill-row]',
            noMatch: 'tenantBillsNoMatch',
            placeholder: 'Search billing records',
            label: 'Search billing records'
        },
        payments: {
            rows: '[data-tenant-payment-row]',
            noMatch: 'tenantPaymentsNoMatch',
            placeholder: 'Search payment history',
            label: 'Search payment history'
        }
    };

    function applySearch(tabKey) {
        var target = searchTargets[tabKey];
        var query = searchQueries[tabKey].trim().toLowerCase();
        var rows = Array.prototype.slice.call(document.querySelectorAll(target.rows));
        var visibleCount = 0;

        rows.forEach(function (row) {
            var visible = !query || row.textContent.toLowerCase().indexOf(query) !== -1;
            row.hidden = !visible;
            if (visible) {
                visibleCount++;
            }
        });

        var noMatch = document.getElementById(target.noMatch);
        if (noMatch) {
            noMatch.hidden = visibleCount > 0 || rows.length === 0;
        }
    }

    function activateTab(tab, focusTab) {
        activeTab = tab.dataset.tab;

        tabs.forEach(function (item) {
            var active = item === tab;
            item.classList.toggle('active', active);
            item.setAttribute('aria-selected', active ? 'true' : 'false');
            item.tabIndex = active ? 0 : -1;
        });

        Object.keys(panels).forEach(function (key) {
            panels[key].hidden = tab.dataset.tab !== key;
        });

        searchInput.value = searchQueries[activeTab];
        searchInput.placeholder = searchTargets[activeTab].placeholder;
        searchInput.setAttribute('aria-label', searchTargets[activeTab].label);
        applySearch(activeTab);

        if (focusTab) {
            tab.focus();
        }
    }

    tabs.forEach(function (tab, index) {
        tab.addEventListener('click', function () {
            activateTab(tab, false);
        });

        tab.addEventListener('keydown', function (event) {
            var nextIndex = index;
            if (event.key === 'ArrowRight') {
                nextIndex = (index + 1) % tabs.length;
            } else if (event.key === 'ArrowLeft') {
                nextIndex = (index + tabs.length - 1) % tabs.length;
            } else if (event.key === 'Home') {
                nextIndex = 0;
            } else if (event.key === 'End') {
                nextIndex = tabs.length - 1;
            } else {
                return;
            }

            event.preventDefault();
            activateTab(tabs[nextIndex], true);
        });
    });

    searchInput.addEventListener('input', function () {
        searchQueries[activeTab] = searchInput.value;
        applySearch(activeTab);
    });
})();
</script>
</body>
</html>
