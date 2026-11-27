<?php
session_start();
require_once __DIR__ . '/../php/index_config.php';

// Tenant access only
if (!isset($_SESSION['role']) || strtolower(trim($_SESSION['role'])) !== 'tenant') {
    header('Location: ../index.php');
    exit;
}

$tenant_id = (int)($_SESSION['tenant_id'] ?? 0);
if ($tenant_id <= 0) {
    die('Tenant account not found. Please log in again.');
}

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$page = 'policies';

$policies = [
    [
        'title' => 'Quiet Hours & Curfew',
        'tag' => 'Daily',
        'summary' => 'Keep noise low in rooms, hallways, and common areas from 10:00 PM to 6:00 AM so all residents can rest.'
    ],
    [
        'title' => 'Visitors & Guests',
        'tag' => 'Security',
        'summary' => 'Visitors are welcome during daytime and early evening hours and must log in at the entrance. Overnight stays require prior owner approval.'
    ],
    [
        'title' => 'Rent, Billing & Due Dates',
        'tag' => 'Billing',
        'summary' => 'Settle monthly room rent and utility charges on or before the scheduled due date, and keep receipts or payment confirmations for reference.'
    ],
    [
        'title' => 'Room Care, Cleanliness & Maintenance',
        'tag' => 'Upkeep',
        'summary' => 'Keep assigned rooms and shared spaces clean, dispose of trash properly, and report plumbing, electrical, or fixture issues right away.'
    ],
    [
        'title' => 'Safety, Appliances & Prohibited Items',
        'tag' => 'Safety',
        'summary' => 'Turn off lights, fans, and high-wattage appliances before leaving the room. Flammable materials, illegal items, and unauthorized alterations are not allowed.'
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Policies &amp; Rules | Residentia</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="../css/owner_db.css?v=8">
    <link rel="stylesheet" href="../css/tenant_db.css?v=8">
    <link rel="stylesheet" href="../css/owner_ui.css?v=8">
</head>
<body class="tenant-dashboard">
<div class="layout">

    <!-- SIDEBAR -->
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
        </nav>

        <a class="logout" href="../logout.php">Log out</a>
    </aside>

    <!-- MAIN -->
    <main class="main">
        <div class="tenant-home">
            <section class="th-hero">
                <div class="th-hero-main">
                    <div>
                        <p class="th-hello">Resident Guidelines</p>
                        <h1>Policies &amp; Rules</h1>
                        <p class="th-hero-sub">
                            <span>House standards for a safe, respectful, and comfortable stay</span>
                        </p>
                    </div>
                </div>
            </section>

            <div class="th-grid">
                <?php foreach ($policies as $rule): ?>
                    <section class="th-card">
                        <div class="th-card-head">
                            <h2><?= e($rule['title']) ?></h2>
                            <span class="th-tag soft"><?= e($rule['tag']) ?></span>
                        </div>
                        <p class="th-empty"><?= e($rule['summary']) ?></p>
                    </section>
                <?php endforeach; ?>
            </div>
        </div>
    </main>

</div>
</body>
</html>
