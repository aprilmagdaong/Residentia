<?php
session_start();
require_once __DIR__ . '/../php/index_config.php';

/* =========================================
   1. OWNER ACCESS
========================================= */
if (strtolower(trim($_SESSION['role'] ?? '')) !== 'owner') {
    header('Location: ../index.php');
    exit;
}

function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function initials($name): string {
    $out = '';
    foreach (array_slice(preg_split('/\s+/', trim((string)$name)), 0, 2) as $w) { $out .= mb_strtoupper(mb_substr($w, 0, 1)); }
    return $out;
}
function fdate($d): string { return $d ? date('M j, Y', strtotime($d)) : '—'; }

$pdo     = db();
$ownerId = (int)($_SESSION['owner_id'] ?? 0);
if ($ownerId <= 0) { die('Owner account not found. Please log in again.'); }

if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$error = '';
$old   = null;

/* =========================================
   2. HELPERS (room assignment)
========================================= */

/* Itugma ang status ng room sa dami ng kasalukuyang nakatira */
function syncRoomStatus(PDO $pdo, int $roomId): void {
    $q = $pdo->prepare("SELECT capacity, room_status FROM room WHERE room_id = ?");
    $q->execute([$roomId]);
    $room = $q->fetch(PDO::FETCH_ASSOC);
    if (!$room || $room['room_status'] === 'Maintenance') { return; }

    $q = $pdo->prepare("SELECT COUNT(*) FROM room_assignment WHERE room_id = ? AND moveout_date IS NULL");
    $q->execute([$roomId]);
    $occupants = (int)$q->fetchColumn();

    $new = $occupants >= (int)$room['capacity'] ? 'Occupied' : 'Available';
    $pdo->prepare("UPDATE room SET room_status = ? WHERE room_id = ?")->execute([$new, $roomId]);
}

/* Mag-assign ng tenant sa room (kailangang nasa loob ng transaction) */
function assignRoom(PDO $pdo, int $tenantId, int $roomId, int $ownerId): void {
    // Business rule: isang room lang kada tenant
    $q = $pdo->prepare("SELECT 1 FROM room_assignment WHERE tenant_id = ? AND moveout_date IS NULL");
    $q->execute([$tenantId]);
    if ($q->fetch()) { throw new Exception('This tenant is already assigned to a room.'); }

    $q = $pdo->prepare(
        "SELECT r.room_id, r.capacity, r.room_status
         FROM room r
         INNER JOIN boarding_house bh ON r.bh_id = bh.bh_id
         WHERE r.room_id = ? AND bh.owner_id = ?
         FOR UPDATE"
    );
    $q->execute([$roomId, $ownerId]);
    $room = $q->fetch(PDO::FETCH_ASSOC);
    if (!$room || $room['room_status'] === 'Maintenance') { throw new Exception('Selected room is not available.'); }

    $q = $pdo->prepare("SELECT COUNT(*) FROM room_assignment WHERE room_id = ? AND moveout_date IS NULL");
    $q->execute([$roomId]);
    if ((int)$q->fetchColumn() >= (int)$room['capacity']) { throw new Exception('Selected room is already full.'); }

    $pdo->prepare("INSERT INTO room_assignment (tenant_id, room_id, movein_date) VALUES (?, ?, CURDATE())")
        ->execute([$tenantId, $roomId]);
    syncRoomStatus($pdo, $roomId);
}

/* =========================================
   3. ACTIONS (POST)
========================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid form submission. Refresh the page and try again.');
        }

        /* ---------- ADD TENANT ---------- */
        if ($action === 'add_tenant') {
            $old = $_POST;
            unset($old['password'], $old['csrf_token']);

            $firstName  = trim($_POST['first_name'] ?? '');
            $middleName = trim($_POST['middle_name'] ?? '');
            $lastName   = trim($_POST['last_name'] ?? '');
            $email      = trim($_POST['email'] ?? '');
            $gender     = $_POST['gender'] ?? '';
            $birthdate  = $_POST['birthdate'] ?? '';
            $occupation = trim($_POST['occupation'] ?? '');
            $contactNumber = trim($_POST['contact_number'] ?? '');
            $barangay     = trim($_POST['barangay'] ?? '');
            $municipality = trim($_POST['municipality'] ?? '');
            $province     = trim($_POST['province'] ?? '');
            $emergencyName   = trim($_POST['emergency_name'] ?? '');
            $relationship    = trim($_POST['relationship'] ?? '');
            $emergencyNumber = trim($_POST['emergency_number'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $roomId   = (int)($_POST['room_id'] ?? 0);

            foreach ([$firstName, $lastName, $email, $gender, $birthdate, $occupation, $contactNumber,
                      $barangay, $municipality, $province, $emergencyName, $relationship,
                      $emergencyNumber, $username, $password] as $field) {
                if ($field === '') { throw new Exception('Please complete all required fields.'); }
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { throw new Exception('Please enter a valid email address.'); }
            if (!in_array($gender, ['Male', 'Female'], true)) { throw new Exception('Please select a valid gender.'); }

            $date = DateTime::createFromFormat('!Y-m-d', $birthdate);
            if (!$date || $date->format('Y-m-d') !== $birthdate || $birthdate > date('Y-m-d')) {
                throw new Exception('Please enter a valid birthdate.');
            }
            if (strlen($username) < 3 || strlen($username) > 50) { throw new Exception('Username must be 3 to 50 characters.'); }
            if (strlen($password) < 8) { throw new Exception('Password must contain at least 8 characters.'); }
            if (!preg_match('/^[0-9+\-\s]{7,15}$/', $contactNumber))   { throw new Exception('Please enter a valid contact number.'); }
            if (!preg_match('/^[0-9+\-\s]{7,15}$/', $emergencyNumber)) { throw new Exception('Please enter a valid emergency contact number.'); }

            $pdo->beginTransaction();

            $q = $pdo->prepare("SELECT tenant_id FROM tenant WHERE email = ?");
            $q->execute([$email]);
            if ($q->fetch()) { throw new Exception('Email address is already registered.'); }

            $q = $pdo->prepare("SELECT account_id FROM accounts WHERE username = ?");
            $q->execute([$username]);
            if ($q->fetch()) { throw new Exception('Username is already taken.'); }

            $pdo->prepare("INSERT INTO tenant (first_name, middle_name, last_name, email, gender, birthdate, occupation, status)
                           VALUES (?, ?, ?, ?, ?, ?, ?, 'Active')")
                ->execute([$firstName, $middleName !== '' ? $middleName : null, $lastName, $email, $gender, $birthdate, $occupation]);
            $tenantId = (int)$pdo->lastInsertId();

            $pdo->prepare("INSERT INTO tenant_contact_number (tenant_id, contact_number) VALUES (?, ?)")
                ->execute([$tenantId, $contactNumber]);
            $pdo->prepare("INSERT INTO tenant_address (tenant_id, barangay, municipality, province) VALUES (?, ?, ?, ?)")
                ->execute([$tenantId, $barangay, $municipality, $province]);
            $pdo->prepare("INSERT INTO tenant_emergency_contact (tenant_id, contact_name, relationship, contact_number) VALUES (?, ?, ?, ?)")
                ->execute([$tenantId, $emergencyName, $relationship, $emergencyNumber]);
            $pdo->prepare("INSERT INTO accounts (tenant_id, username, password, user_role) VALUES (?, ?, ?, 'Tenant')")
                ->execute([$tenantId, $username, password_hash($password, PASSWORD_DEFAULT)]);

            if ($roomId > 0) { assignRoom($pdo, $tenantId, $roomId, $ownerId); }

            $pdo->commit();
            $msg = 'Tenant successfully added!';

        /* ---------- ASSIGN ROOM (existing tenant) ---------- */
        } elseif ($action === 'assign_room') {
            $tenantId = (int)($_POST['tenant_id'] ?? 0);
            $roomId   = (int)($_POST['room_id'] ?? 0);
            if ($tenantId <= 0 || $roomId <= 0) { throw new Exception('Please select a room.'); }

            $pdo->beginTransaction();
            $q = $pdo->prepare("SELECT status FROM tenant WHERE tenant_id = ?");
            $q->execute([$tenantId]);
            $status = $q->fetchColumn();
            if ($status === false) { throw new Exception('Tenant not found.'); }
            if ($status !== 'Active') { throw new Exception('Only active tenants can be assigned to a room.'); }

            assignRoom($pdo, $tenantId, $roomId, $ownerId);
            $pdo->commit();
            $msg = 'Room assigned successfully!';

        /* ---------- MOVE OUT ---------- */
        } elseif ($action === 'move_out') {
            $tenantId = (int)($_POST['tenant_id'] ?? 0);
            if ($tenantId <= 0) { throw new Exception('Tenant not found.'); }

            $pdo->beginTransaction();
            $q = $pdo->prepare(
                "SELECT ra.assignment_id, ra.room_id
                 FROM room_assignment ra
                 INNER JOIN room r ON r.room_id = ra.room_id
                 INNER JOIN boarding_house bh ON bh.bh_id = r.bh_id
                 WHERE ra.tenant_id = ? AND ra.moveout_date IS NULL AND bh.owner_id = ?
                 FOR UPDATE"
            );
            $q->execute([$tenantId, $ownerId]);
            $as = $q->fetch(PDO::FETCH_ASSOC);
            if (!$as) { throw new Exception('This tenant has no current room assignment.'); }

            // Hindi dine-delete ang record; nilalagyan lang ng moveout_date para manatili ang history
            $pdo->prepare("UPDATE room_assignment SET moveout_date = CURDATE() WHERE assignment_id = ?")
                ->execute([$as['assignment_id']]);
            syncRoomStatus($pdo, (int)$as['room_id']);
            $pdo->commit();
            $msg = 'Tenant moved out. The room record has been updated.';

        } else {
            throw new Exception('Invalid action.');
        }

        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['flash'] = ['type' => 'success', 'msg' => $msg];
        header('Location: owner_tenants.php');      // iwas double-submit kapag nag-refresh
        exit;

    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }

        if ($ex instanceof PDOException) {
            error_log($ex->getMessage());
            $error = $ex->getCode() === '23000'
                ? 'Email or username already exists.'
                : 'Unable to save. Please check the database.';
        } else {
            $error = $ex->getMessage();
        }
        if ($action !== 'add_tenant') { $old = null; }
    }
}

/* =========================================
   4. DATA
========================================= */

/* Mga room na may bakante (hindi Maintenance) */
$stmt = $pdo->prepare(
    "SELECT r.room_id, r.room_number, r.room_type, r.capacity, r.monthly_rate,
            COUNT(ra.assignment_id) AS occupants
     FROM room r
     INNER JOIN boarding_house bh ON r.bh_id = bh.bh_id
     LEFT JOIN room_assignment ra ON r.room_id = ra.room_id AND ra.moveout_date IS NULL
     WHERE bh.owner_id = ? AND r.room_status != 'Maintenance'
     GROUP BY r.room_id, r.room_number, r.room_type, r.capacity, r.monthly_rate
     HAVING occupants < r.capacity
     ORDER BY r.room_number"
);
$stmt->execute([$ownerId]);
$rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

function roomOptions(array $rooms): string {
    $h = '';
    foreach ($rooms as $r) {
        $h .= '<option value="' . (int)$r['room_id'] . '">'
            . e($r['room_number']) . ' · ' . e($r['room_type']) . ' · ₱' . number_format((float)$r['monthly_rate'], 2)
            . '/month (' . ((int)$r['capacity'] - (int)$r['occupants']) . ' slot/s left)</option>';
    }
    return $h;
}

/* Mga tenant (isang row bawat tenant) */
$tenants = $pdo->query(
    "SELECT t.tenant_id, t.first_name, t.middle_name, t.last_name, t.email, t.gender, t.birthdate,
            t.occupation, t.status,
            (SELECT c.contact_number FROM tenant_contact_number c
              WHERE c.tenant_id = t.tenant_id ORDER BY c.number_id LIMIT 1) AS contact_number,
            ra.assignment_id, ra.movein_date, r.room_number
     FROM tenant t
     LEFT JOIN room_assignment ra ON ra.tenant_id = t.tenant_id AND ra.moveout_date IS NULL
     LEFT JOIN room r ON r.room_id = ra.room_id
     ORDER BY t.last_name ASC, t.first_name ASC"
)->fetchAll(PDO::FETCH_ASSOC);

/* Detalye para sa "View" dialog */
$addr = []; foreach ($pdo->query("SELECT tenant_id, barangay, municipality, province FROM tenant_address ORDER BY add_id") as $a) {
    if (!isset($addr[$a['tenant_id']])) { $addr[$a['tenant_id']] = $a['barangay'] . ', ' . $a['municipality'] . ', ' . $a['province']; }
}
$emer = []; foreach ($pdo->query("SELECT tenant_id, contact_name, relationship, contact_number FROM tenant_emergency_contact ORDER BY ec_id") as $x) {
    if (!isset($emer[$x['tenant_id']])) { $emer[$x['tenant_id']] = $x['contact_name'] . ' (' . $x['relationship'] . ') · ' . $x['contact_number']; }
}
$hist = []; foreach ($pdo->query(
    "SELECT ra.tenant_id, r.room_number, ra.movein_date, ra.moveout_date
     FROM room_assignment ra INNER JOIN room r ON r.room_id = ra.room_id
     ORDER BY ra.movein_date DESC, ra.assignment_id DESC") as $h) {
    $hist[$h['tenant_id']][] = [
        'room' => $h['room_number'],
        'in'   => fdate($h['movein_date']),
        'out'  => $h['moveout_date'] ? fdate($h['moveout_date']) : 'Present',
    ];
}

$detail = [];
$total = count($tenants);
foreach ($tenants as $t) {
    $id   = (int)$t['tenant_id'];
    $name = trim($t['first_name'] . ' ' . ($t['middle_name'] ? $t['middle_name'] . ' ' : '') . $t['last_name']);
    $detail[$id] = [
        'name' => $name, 'initials' => initials($t['first_name'] . ' ' . $t['last_name']),
        'status' => $t['status'], 'email' => $t['email'], 'phone' => $t['contact_number'] ?? '—',
        'gender' => $t['gender'], 'birthdate' => fdate($t['birthdate']), 'occupation' => $t['occupation'],
        'address' => $addr[$id] ?? '—', 'emergency' => $emer[$id] ?? '—',
        'room' => $t['room_number'], 'since' => $t['movein_date'] ? fdate($t['movein_date']) : null,
        'history' => $hist[$id] ?? [],
    ];
}
$roomOpts = roomOptions($rooms);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tenants | Residentia</title>

    <link rel="stylesheet" href="../css/owner_db.css?v=4">
    <link rel="stylesheet" href="../css/owner_tenants.css?v=11">
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
            <a href="owner_tenants.php" class="active">Tenants</a>
            <a href="owner_billing.php">Billing &amp; Payments</a>
            <a href="owner_maintenance.php">Maintenance</a>
            <a href="owner_announcement.php">Announcements</a>
            <a href="owner_reports.php">Reports</a>
        </nav>

        <a class="logout" href="../logout.php">Log out</a>
    </aside>

    <!-- MAIN -->
    <main class="main">
    <div class="tenant-page">

        <header class="tn-header">
            <div>
                <h1>Tenants</h1>
                <p>Register tenants, assign rooms and keep their records in one place.</p>
            </div>
            <button type="button" class="tn-add" id="openAdd">+ Add Tenant</button>
        </header>

        <?php if ($flash): ?><div class="tn-alert <?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>
        <?php if ($error && !$old): ?><div class="tn-alert error" role="alert"><?= e($error) ?></div><?php endif; ?>

        <!-- LIST -->
        <section class="tn-card">
            <div class="tn-toolbar">
                <div class="tn-heading">
                    <h2>Registered Tenants</h2>
                    <p><span id="tnShown"><?= $total ?></span> tenant(s) shown</p>
                </div>
                <div class="tn-controls">
                    <div class="tn-search">
                        <input type="search" id="tnSearch" placeholder="Search name, email, room" aria-label="Search tenants">
                    </div>
                    <div class="tn-chips" role="group" aria-label="Filter tenants">
                        <button type="button" class="tn-chip active" data-filter="active">Active</button>
                        <button type="button" class="tn-chip" data-filter="movedout">Moved Out</button>
                    </div>
                </div>
            </div>

            <div class="tn-table-wrap">
                <table class="tn-table">
                    <thead>
                        <tr>
                            <th>Tenant</th>
                            <th>Room</th>
                            <th>Moved In</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="tnBody">
                    <?php if (!$tenants): ?>
                        <tr><td colspan="5" class="tn-empty">No tenants have been added yet.</td></tr>
                    <?php else: foreach ($tenants as $t):
                        $id   = (int)$t['tenant_id'];
                        $d    = $detail[$id];
                        $st   = strtolower($t['status']);
                        $has  = (bool)$t['room_number'];
                        $hasCurrentAssignment = $t['assignment_id'] !== null;
                        $latestAssignment = $d['history'][0] ?? null;
                        $hasMovedOut = !$hasCurrentAssignment
                            && $latestAssignment
                            && $latestAssignment['out'] !== 'Present';
                        $filterState = $hasMovedOut ? 'movedout' : $st;
                        $displayStatus = $filterState === 'movedout' ? 'Moved Out' : $t['status'];
                        $displayStatusClass = $filterState === 'movedout' ? 'movedout' : $st;
                        $displayRoom = $hasCurrentAssignment ? $t['room_number'] : ($latestAssignment['room'] ?? null);
                        $displayMoveIn = $hasCurrentAssignment ? fdate($t['movein_date']) : ($latestAssignment['in'] ?? '—');
                        $searchRoom = $displayRoom ?? '';
                    ?>
                        <tr data-status="<?= e($st) ?>" data-filter-state="<?= e($filterState) ?>" data-room="<?= $has ? 'yes' : 'no' ?>"
                            data-search="<?= e(strtolower($d['name'] . ' ' . $t['email'] . ' ' . ($t['contact_number'] ?? '') . ' ' . $searchRoom)) ?>">
                            <td class="tn-left">
                                <div class="tn-person">
                                    <span class="tn-av"><?= e($d['initials']) ?></span>
                                    <div>
                                        <strong><?= e($d['name']) ?></strong>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?php if ($displayRoom): ?>
                                    <span class="tn-room"><?= e($displayRoom) ?></span>
                                <?php else: ?>
                                    <span class="tn-none">Not assigned</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($displayMoveIn) ?></td>
                            <td><span class="tn-pill <?= e($displayStatusClass) ?>"><?= e($displayStatus) ?></span></td>
                            <td><button type="button" class="tn-link js-view" data-id="<?= $id ?>">View</button></td>
                        </tr>
                    <?php endforeach; endif; ?>
                        <tr id="tnNoMatch" hidden><td colspan="5" class="tn-empty">No tenants match your search.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

    </div>
    </main>
</div>

<!-- ADD TENANT DIALOG -->
<dialog id="addDialog" class="tn-dialog wide" aria-labelledby="addTitle">
    <form method="POST" class="tn-form" id="addForm" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="add_tenant">

        <div class="tn-dlg-head">
            <div>
                <h2 id="addTitle">Add New Tenant</h2>
                <p>Fill in the tenant’s details and create their portal account.</p>
            </div>
            <button type="button" class="tn-x js-close" aria-label="Close">&times;</button>
        </div>

        <?php if ($error && $old): ?><div class="tn-alert error" role="alert"><?= e($error) ?></div><?php endif; ?>

        <div class="tn-scroll">
            <div class="tn-section">
                <h3>Personal Information</h3>
                <div class="tn-fields">
                    <div class="tn-field"><label for="first_name">First Name *</label><input id="first_name" type="text" name="first_name" maxlength="50" required></div>
                    <div class="tn-field"><label for="middle_name">Middle Name</label><input id="middle_name" type="text" name="middle_name" maxlength="50"></div>
                    <div class="tn-field"><label for="last_name">Last Name *</label><input id="last_name" type="text" name="last_name" maxlength="50" required></div>
                    <div class="tn-field"><label for="email">Email Address *</label><input id="email" type="email" name="email" maxlength="100" required></div>
                    <div class="tn-field"><label for="gender">Gender *</label>
                        <select id="gender" name="gender" required><option value="">Select gender</option><option value="Male">Male</option><option value="Female">Female</option></select></div>
                    <div class="tn-field"><label for="birthdate">Birthdate *</label><input id="birthdate" type="date" name="birthdate" max="<?= date('Y-m-d') ?>" required></div>
                    <div class="tn-field full"><label for="occupation">Occupation *</label><input id="occupation" type="text" name="occupation" maxlength="50" placeholder="e.g. Student, Teacher, Employee" required></div>
                </div>
            </div>

            <div class="tn-section">
                <h3>Contact &amp; Address</h3>
                <div class="tn-fields">
                    <div class="tn-field full"><label for="contact_number">Contact Number *</label><input id="contact_number" type="tel" name="contact_number" maxlength="15" placeholder="09XXXXXXXXX" required></div>
                    <div class="tn-field"><label for="barangay">Barangay *</label><input id="barangay" type="text" name="barangay" maxlength="100" required></div>
                    <div class="tn-field"><label for="municipality">Municipality / City *</label><input id="municipality" type="text" name="municipality" maxlength="100" required></div>
                    <div class="tn-field full"><label for="province">Province *</label><input id="province" type="text" name="province" maxlength="100" required></div>
                </div>
            </div>

            <div class="tn-section">
                <h3>Emergency Contact</h3>
                <div class="tn-fields">
                    <div class="tn-field"><label for="emergency_name">Contact Person *</label><input id="emergency_name" type="text" name="emergency_name" maxlength="100" required></div>
                    <div class="tn-field"><label for="relationship">Relationship *</label><input id="relationship" type="text" name="relationship" maxlength="50" placeholder="e.g. Mother, Father" required></div>
                    <div class="tn-field full"><label for="emergency_number">Emergency Contact Number *</label><input id="emergency_number" type="tel" name="emergency_number" maxlength="15" required></div>
                </div>
            </div>

            <div class="tn-section">
                <h3>Room Assignment</h3>
                <div class="tn-fields">
                    <div class="tn-field full"><label for="room_id">Assign Room (optional)</label>
                        <select id="room_id" name="room_id"><option value="0">No room assignment yet</option><?= $roomOpts ?></select></div>
                </div>
            </div>

            <div class="tn-section">
                <h3>Tenant Login Account</h3>
                <div class="tn-fields">
                    <div class="tn-field"><label for="username">Username *</label><input id="username" type="text" name="username" minlength="3" maxlength="50" autocomplete="off" required></div>
                    <div class="tn-field"><label for="password">Password *</label><input id="password" type="password" name="password" minlength="8" autocomplete="new-password" placeholder="At least 8 characters" required></div>
                </div>
            </div>
        </div>

        <div class="tn-dlg-foot">
            <button type="button" class="tn-btn ghost js-close">Cancel</button>
            <button type="submit" class="tn-btn">Save Tenant</button>
        </div>
    </form>
</dialog>

<!-- VIEW TENANT DIALOG -->
<dialog id="viewDialog" class="tn-dialog" aria-labelledby="vName">
    <div class="tn-form">
        <div class="tn-dlg-head">
            <div class="tn-vhead">
                <span class="tn-av lg" id="vAv"></span>
                <div>
                    <h2 id="vName"></h2>
                    <span class="tn-pill" id="vStatus"></span>
                </div>
            </div>
            <button type="button" class="tn-x js-close" aria-label="Close">&times;</button>
        </div>

        <div class="tn-scroll small">
            <dl class="tn-dl" id="vInfo"></dl>

            <h3 class="tn-sub">Room</h3>
            <div id="vRoomBox"></div>

            <h3 class="tn-sub">Room History</h3>
            <ul class="tn-hist" id="vHist"></ul>
        </div>

        <!-- Move out / Assign -->
        <form method="POST" id="moveOutForm" class="tn-action-form" hidden
              onsubmit="return confirm('Move this tenant out of the room? The record will be kept as history.');">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="move_out">
            <input type="hidden" name="tenant_id" class="js-tid">
            <button type="submit" class="tn-btn danger">Move Out</button>
        </form>

        <form method="POST" id="assignForm" class="tn-action-form" hidden>
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="assign_room">
            <input type="hidden" name="tenant_id" class="js-tid">
            <select name="room_id" required aria-label="Select a room">
                <option value="">Select an available room</option>
                <?= $roomOpts ?>
            </select>
            <button type="submit" class="tn-btn">Assign Room</button>
        </form>

        <div class="tn-dlg-foot">
            <button type="button" class="tn-btn ghost js-close">Close</button>
        </div>
    </div>
</dialog>

<script>
(function () {
    var detail = <?= json_encode($detail, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
    var addDlg = document.getElementById('addDialog');
    var viewDlg = document.getElementById('viewDialog');

    /* ---- close buttons + click sa labas ---- */
    [addDlg, viewDlg].forEach(function (d) {
        d.querySelectorAll('.js-close').forEach(function (b) { b.addEventListener('click', function () { d.close(); }); });
        d.addEventListener('click', function (e) { if (e.target === d) d.close(); });
    });

    /* ---- Add tenant ---- */
    document.getElementById('openAdd').addEventListener('click', function () {
        document.getElementById('addForm').reset();
        addDlg.showModal();
        document.getElementById('first_name').focus();
    });

    if (new URLSearchParams(window.location.search).get('action') === 'add') {
        document.getElementById('addForm').reset();
        addDlg.showModal();
        document.getElementById('first_name').focus();
    }

    <?php if ($old): ?>
    // May error: ibalik ang mga ininput (maliban sa password)
    var oldVals = <?= json_encode($old, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
    Object.keys(oldVals).forEach(function (k) {
        var el = document.querySelector('#addForm [name="' + k + '"]');
        if (el && el.type !== 'hidden') { el.value = oldVals[k]; }
    });
    addDlg.showModal();
    <?php endif; ?>

    /* ---- View tenant ---- */
    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined) n.textContent = text;
        return n;
    }
    function row(dl, label, value) {
        dl.appendChild(el('dt', '', label));
        dl.appendChild(el('dd', '', value));
    }

    document.querySelectorAll('.js-view').forEach(function (b) {
        b.addEventListener('click', function () {
            var t = detail[b.dataset.id];
            if (!t) return;

            document.getElementById('vAv').textContent = t.initials;
            document.getElementById('vName').textContent = t.name;
            var pill = document.getElementById('vStatus');
            pill.textContent = t.status;
            pill.className = 'tn-pill ' + t.status.toLowerCase();

            var dl = document.getElementById('vInfo');
            dl.innerHTML = '';
            row(dl, 'Email', t.email);
            row(dl, 'Contact', t.phone);
            row(dl, 'Gender', t.gender);
            row(dl, 'Birthdate', t.birthdate);
            row(dl, 'Occupation', t.occupation);
            row(dl, 'Address', t.address);
            row(dl, 'Emergency', t.emergency);

            var box = document.getElementById('vRoomBox');
            box.innerHTML = '';
            if (t.room) {
                var c = el('div', 'tn-curroom');
                c.appendChild(el('span', 'tn-room', t.room));
                c.appendChild(el('small', '', 'Moved in ' + t.since));
                box.appendChild(c);
            } else {
                box.appendChild(el('p', 'tn-none', 'This tenant is not assigned to a room.'));
            }

            var hist = document.getElementById('vHist');
            hist.innerHTML = '';
            if (!t.history.length) {
                hist.appendChild(el('li', 'tn-none', 'No room history yet.'));
            }
            t.history.forEach(function (h) {
                var li = el('li');
                li.appendChild(el('strong', '', h.room));
                li.appendChild(el('span', '', h.in + ' → ' + h.out));
                hist.appendChild(li);
            });

            // Move out kung may room, Assign kung wala
            var mo = document.getElementById('moveOutForm'), as = document.getElementById('assignForm');
            mo.hidden = !t.room;
            as.hidden = !!t.room || t.status !== 'Active';
            document.querySelectorAll('#viewDialog .js-tid').forEach(function (i) { i.value = b.dataset.id; });

            viewDlg.showModal();
        });
    });

    /* ---- Search + filter ---- */
    var search = document.getElementById('tnSearch');
    var chips  = document.querySelectorAll('.tn-chip');
    var rows   = document.querySelectorAll('#tnBody tr[data-status]');
    var shown  = document.getElementById('tnShown');
    var none   = document.getElementById('tnNoMatch');
    var filter = 'active';

    function apply() {
        var q = (search.value || '').trim().toLowerCase(), n = 0;
        rows.forEach(function (r) {
            var okF = r.dataset.filterState === filter;
            var ok = okF && r.dataset.search.indexOf(q) !== -1;
            r.hidden = !ok;
            if (ok) n++;
        });
        shown.textContent = n;
        if (none) none.hidden = !(rows.length > 0 && n === 0);
    }
    apply();
    search.addEventListener('input', apply);
    chips.forEach(function (c) {
        c.addEventListener('click', function () {
            chips.forEach(function (x) { x.classList.remove('active'); });
            c.classList.add('active');
            filter = c.dataset.filter;
            apply();
        });
    });
})();
</script>
</body>
</html>