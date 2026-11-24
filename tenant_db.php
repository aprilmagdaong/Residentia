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

$pdo = db();

function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function peso($amount): string { return '₱' . number_format((float)$amount, 2); }
function fdate($d): string { return $d ? date('M j, Y', strtotime($d)) : '—'; }
function initials($name): string {
    $out = '';
    foreach (array_slice(preg_split('/\s+/', trim((string)$name)), 0, 2) as $w) { $out .= mb_strtoupper(mb_substr($w, 0, 1)); }
    return $out;
}

/* =========================================
   TENANT PROFILE (sariling impormasyon lang)
========================================= */
$q = $pdo->prepare("SELECT first_name, middle_name, last_name, email, gender, birthdate, occupation, status
                    FROM tenant WHERE tenant_id = ?");
$q->execute([$tenant_id]);
$tenant = $q->fetch(PDO::FETCH_ASSOC);
if (!$tenant) { die('Tenant record not found.'); }
$fullName = trim($tenant['first_name'] . ' ' . ($tenant['middle_name'] ? $tenant['middle_name'] . ' ' : '') . $tenant['last_name']);

$q = $pdo->prepare("SELECT contact_number FROM tenant_contact_number WHERE tenant_id = ? ORDER BY number_id");
$q->execute([$tenant_id]);
$phones = $q->fetchAll(PDO::FETCH_COLUMN);

$q = $pdo->prepare("SELECT barangay, municipality, province FROM tenant_address WHERE tenant_id = ? ORDER BY add_id LIMIT 1");
$q->execute([$tenant_id]);
$addr = $q->fetch(PDO::FETCH_ASSOC);

$q = $pdo->prepare("SELECT contact_name, relationship, contact_number FROM tenant_emergency_contact WHERE tenant_id = ? ORDER BY 1 LIMIT 1");
$q->execute([$tenant_id]);
$emergency = $q->fetch(PDO::FETCH_ASSOC);

$age = '';
if (!empty($tenant['birthdate'])) {
    $age = (new DateTime($tenant['birthdate']))->diff(new DateTime('today'))->y;
}

/* =========================================
   ROOM + BOARDING HOUSE + OWNER
========================================= */
$q = $pdo->prepare("
    SELECT ra.movein_date, r.room_number, r.room_type, r.capacity, r.monthly_rate,
           bh.bh_id, bh.bh_name, bh.bh_type, bh.owner_id,
           (SELECT COUNT(*) FROM room_assignment x WHERE x.room_id = r.room_id AND x.moveout_date IS NULL) AS occupants
    FROM room_assignment ra
    INNER JOIN room r ON r.room_id = ra.room_id
    INNER JOIN boarding_house bh ON bh.bh_id = r.bh_id
    WHERE ra.tenant_id = ? AND ra.moveout_date IS NULL
    LIMIT 1
");
$q->execute([$tenant_id]);
$room = $q->fetch(PDO::FETCH_ASSOC);

$bhAddress = ''; $owner = null; $ownerPhones = [];
if ($room) {
    $q = $pdo->prepare("SELECT barangay, municipality, province FROM boarding_house_address WHERE bh_id = ? LIMIT 1");
    $q->execute([$room['bh_id']]);
    if ($a = $q->fetch(PDO::FETCH_ASSOC)) { $bhAddress = $a['barangay'] . ', ' . $a['municipality'] . ', ' . $a['province']; }

    $q = $pdo->prepare("SELECT first_name, middle_name, last_name FROM owner WHERE owner_id = ?");
    $q->execute([$room['owner_id']]);
    $owner = $q->fetch(PDO::FETCH_ASSOC);

    $q = $pdo->prepare("SELECT contact_number FROM owner_contact_number WHERE owner_id = ? ORDER BY number_id");
    $q->execute([$room['owner_id']]);
    $ownerPhones = $q->fetchAll(PDO::FETCH_COLUMN);
}

/* =========================================
   ANNOUNCEMENTS
========================================= */
$q = $pdo->prepare("
    SELECT a.announcement_title, a.announcement_message, a.date_published
    FROM announcement_recipient ar
    INNER JOIN announcement a ON a.announcement_id = ar.announcement_id
    WHERE ar.tenant_id = ?
    ORDER BY a.date_published DESC, a.announcement_id DESC
    LIMIT 4
");
$q->execute([$tenant_id]);
$announcements = $q->fetchAll(PDO::FETCH_ASSOC);

/* Gaano katagal na sa room */
$stay = '';
$occPct = 0;
if ($room) {
    $diff = (new DateTime($room['movein_date']))->diff(new DateTime('today'));
    $months = $diff->y * 12 + $diff->m;
    $stay = $months >= 1 ? $months . ' month' . ($months === 1 ? '' : 's') : $diff->days . ' day' . ($diff->days === 1 ? '' : 's');
    $occPct = (int)$room['capacity'] > 0 ? min(100, round($room['occupants'] / $room['capacity'] * 100)) : 0;
}

$hour  = (int)date('G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$statusClass = strtolower($tenant['status']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | Residentia</title>

    <link rel="stylesheet" href="../css/owner_db.css?v=5">
    <link rel="stylesheet" href="../css/tenant_db.css?v=3">
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
            <a href="tenant_db.php" class="active">Dashboard</a>
            <a href="tenant_billing.php">Billing &amp; Payments</a>
            <a href="tenant_maintenance.php">Maintenance</a>
            <a href="tenant_announcement.php">Announcements</a>
        </nav>

        <a class="logout" href="../logout.php">Log out</a>
    </aside>

    <!-- MAIN -->
    <main class="main">
    <div class="tenant-home">

        <!-- HERO -->
        <section class="th-hero">
            <div class="th-hero-main">
                <span class="th-hero-av"><?= e(initials($tenant['first_name'] . ' ' . $tenant['last_name'])) ?></span>
                <div>
                    <p class="th-hello"><?= e($greet) ?></p>
                    <h1><?= e($fullName) ?></h1>
                    <p class="th-hero-sub">
                        <?= date('l, F j, Y') ?>
                        <span class="th-tag <?= e($statusClass) ?>"><?= e($tenant['status']) ?></span>
                    </p>
                </div>
            </div>

            <?php if ($room): ?>
            <div class="th-facts">
                <div><span>Room</span><strong><?= e($room['room_number']) ?></strong><small><?= e($room['room_type']) ?></small></div>
                <div><span>Monthly Rate</span><strong><?= peso($room['monthly_rate']) ?></strong><small>Per month</small></div>
                <div><span>Moved In</span><strong><?= e(fdate($room['movein_date'])) ?></strong><small><?= e($stay) ?> so far</small></div>
            </div>
            <?php else: ?>
            <div class="th-facts none">You are not assigned to a room yet. Please contact the owner to get your room.</div>
            <?php endif; ?>
        </section>

        <div class="th-grid">

            <!-- ANNOUNCEMENTS -->
            <section class="th-card">
                <div class="th-card-head">
                    <h2>Announcements</h2>
                    <a class="th-more" href="tenant_announcement.php">View all</a>
                </div>
                <?php if (!$announcements): ?>
                    <p class="th-empty">No announcements yet. New updates from the owner will show up here.</p>
                <?php else: ?>
                    <?php $latest = $announcements[0]; ?>
                    <article class="th-featured">
                        <span class="th-badge">Latest</span>
                        <small><?= e(fdate($latest['date_published'])) ?></small>
                        <h3><?= e($latest['announcement_title']) ?></h3>
                        <p><?= e(mb_strimwidth($latest['announcement_message'], 0, 260, '…', 'UTF-8')) ?></p>
                    </article>

                    <?php if (count($announcements) > 1): ?>
                    <ul class="th-news">
                        <?php foreach (array_slice($announcements, 1) as $a): ?>
                            <li>
                                <small><?= e(fdate($a['date_published'])) ?></small>
                                <strong><?= e($a['announcement_title']) ?></strong>
                                <p><?= e(mb_strimwidth($a['announcement_message'], 0, 120, '…', 'UTF-8')) ?></p>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                <?php endif; ?>
            </section>

            <!-- RIGHT COLUMN -->
            <div class="th-col">

                <!-- ROOM -->
                <section class="th-card">
                    <div class="th-card-head"><h2>My Room</h2></div>
                    <?php if (!$room): ?>
                        <p class="th-empty">No room assigned yet.</p>
                    <?php else: ?>
                        <div class="th-roomrow">
                            <span class="th-roomno"><?= e($room['room_number']) ?></span>
                            <span class="th-roomtype"><?= e($room['room_type']) ?></span>
                        </div>
                        <div class="th-meter">
                            <div class="th-meter-top"><span>Beds taken</span><strong><?= (int)$room['occupants'] ?> of <?= (int)$room['capacity'] ?></strong></div>
                            <div class="th-bar"><span style="width:<?= $occPct ?>%"></span></div>
                        </div>
                        <dl class="th-dl">
                            <dt>Monthly rate</dt><dd><?= peso($room['monthly_rate']) ?></dd>
                            <dt>Moved in</dt><dd><?= e(fdate($room['movein_date'])) ?></dd>
                        </dl>
                    <?php endif; ?>
                </section>

                <!-- BOARDING HOUSE + OWNER -->
                <section class="th-card">
                    <div class="th-card-head"><h2>Boarding House &amp; Owner</h2></div>
                    <?php if (!$room): ?>
                        <p class="th-empty">Details will appear once you are assigned to a room.</p>
                    <?php else: ?>
                        <div class="th-bh">
                            <strong><?= e($room['bh_name']) ?></strong>
                            <span class="th-tag soft"><?= e($room['bh_type']) ?></span>
                        </div>
                        <?php if ($bhAddress): ?><p class="th-addr"><?= e($bhAddress) ?></p><?php endif; ?>

                        <?php if ($owner): ?>
                        <div class="th-owner">
                            <span class="th-av"><?= e(initials($owner['first_name'] . ' ' . $owner['last_name'])) ?></span>
                            <div>
                                <strong><?= e(trim($owner['first_name'] . ' ' . $owner['last_name'])) ?></strong>
                                <small>Owner</small>
                            </div>
                        </div>
                        <?php if ($ownerPhones): ?>
                        <div class="th-calls">
                            <?php foreach ($ownerPhones as $ph): ?>
                                <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $ph)) ?>"><?= e($ph) ?></a>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </section>
            </div>
        </div>

        <div class="th-grid even">

            <!-- PROFILE -->
            <section class="th-card">
                <div class="th-card-head"><h2>My Profile</h2></div>
                <dl class="th-dl wide">
                    <dt>Full name</dt><dd><?= e($fullName) ?></dd>
                    <dt>Username</dt><dd><?= e($_SESSION['username'] ?? '—') ?></dd>
                    <dt>Email</dt><dd><?= e($tenant['email']) ?></dd>
                    <dt>Contact</dt><dd><?= $phones ? e(implode(', ', $phones)) : '—' ?></dd>
                    <dt>Gender</dt><dd><?= e($tenant['gender']) ?></dd>
                    <dt>Birthdate</dt><dd><?= e(fdate($tenant['birthdate'])) ?><?= $age !== '' ? ' (' . (int)$age . ' yrs old)' : '' ?></dd>
                    <dt>Occupation</dt><dd><?= e($tenant['occupation']) ?></dd>
                    <dt>Home address</dt><dd><?= $addr ? e($addr['barangay'] . ', ' . $addr['municipality'] . ', ' . $addr['province']) : '—' ?></dd>
                </dl>
            </section>

            <!-- EMERGENCY CONTACT -->
            <section class="th-card">
                <div class="th-card-head"><h2>Emergency Contact</h2></div>
                <?php if (!$emergency): ?>
                    <p class="th-empty">No emergency contact on file. Please let the owner know.</p>
                <?php else: ?>
                    <div class="th-owner">
                        <span class="th-av"><?= e(initials($emergency['contact_name'])) ?></span>
                        <div>
                            <strong><?= e($emergency['contact_name']) ?></strong>
                            <small><?= e($emergency['relationship']) ?></small>
                        </div>
                    </div>
                    <div class="th-calls">
                        <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $emergency['contact_number'])) ?>"><?= e($emergency['contact_number']) ?></a>
                    </div>
                    <p class="th-note">This is the person the owner will contact in case of an emergency. To change it, please tell the owner.</p>
                <?php endif; ?>
            </section>
        </div>

    </div>
    </main>
</div>
</body>
</html>