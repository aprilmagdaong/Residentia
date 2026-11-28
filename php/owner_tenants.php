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

/* Ilipat ang tenant mula sa kasalukuyang room papunta sa bagong room */
function reassignRoom(PDO $pdo, int $tenantId, int $roomId, int $ownerId): void {
    $q = $pdo->prepare(
        "SELECT ra.assignment_id, ra.room_id
         FROM room_assignment ra
         INNER JOIN room r ON r.room_id = ra.room_id
         INNER JOIN boarding_house bh ON bh.bh_id = r.bh_id
         WHERE ra.tenant_id = ? AND ra.moveout_date IS NULL AND bh.owner_id = ?
         FOR UPDATE"
    );
    $q->execute([$tenantId, $ownerId]);
    $current = $q->fetch(PDO::FETCH_ASSOC);
    if (!$current) { throw new Exception('This tenant has no current room to reassign.'); }
    if ((int)$current['room_id'] === $roomId) { throw new Exception('Please select a different room.'); }

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

    $pdo->prepare("UPDATE room_assignment SET moveout_date = CURDATE() WHERE assignment_id = ?")
        ->execute([(int)$current['assignment_id']]);
    syncRoomStatus($pdo, (int)$current['room_id']);

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
            $postedContactNumbers = $_POST['contact_numbers'] ?? [];
            $contactNumbers = [];
            if (is_array($postedContactNumbers)) {
                foreach ($postedContactNumbers as $number) {
                    if (is_scalar($number)) {
                        $number = trim((string)$number);
                        if ($number !== '') { $contactNumbers[] = $number; }
                    }
                }
            }
            $postedEmergencyNumbers = $_POST['emergency_numbers'] ?? [];
            $emergencyNumbers = [];
            if (is_array($postedEmergencyNumbers)) {
                foreach ($postedEmergencyNumbers as $number) {
                    if (is_scalar($number)) {
                        $number = trim((string)$number);
                        if ($number !== '') { $emergencyNumbers[] = $number; }
                    }
                }
            }
            $barangay     = trim($_POST['barangay'] ?? '');
            $municipality = trim($_POST['municipality'] ?? '');
            $province     = trim($_POST['province'] ?? '');
            $emergencyName   = trim($_POST['emergency_name'] ?? '');
            $relationship    = trim($_POST['relationship'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $roomId   = (int)($_POST['room_id'] ?? 0);

            foreach ([$firstName, $lastName, $email, $gender, $birthdate, $occupation,
                      $barangay, $municipality, $province, $emergencyName, $relationship,
                      $username, $password] as $field) {
                if ($field === '') { throw new Exception('Please complete all required fields.'); }
            }
            if (!$contactNumbers) { throw new Exception('Please enter at least one contact number.'); }
            if (!$emergencyNumbers) { throw new Exception('Please enter at least one emergency contact number.'); }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { throw new Exception('Please enter a valid email address.'); }
            if (!in_array($gender, ['Male', 'Female'], true)) { throw new Exception('Please select a valid gender.'); }

            $date = DateTime::createFromFormat('!Y-m-d', $birthdate);
            if (!$date || $date->format('Y-m-d') !== $birthdate || $birthdate > date('Y-m-d')) {
                throw new Exception('Please enter a valid birthdate.');
            }
            if (strlen($username) < 3 || strlen($username) > 50) { throw new Exception('Username must be 3 to 50 characters.'); }
            if (strlen($password) < 8) { throw new Exception('Password must contain at least 8 characters.'); }
            foreach ($contactNumbers as $contactNumber) {
                if (!preg_match('/^[0-9+\-\s]{7,15}$/', $contactNumber)) {
                    throw new Exception('Please enter a valid contact number.');
                }
            }
            foreach ($emergencyNumbers as $emergencyNumber) {
                if (!preg_match('/^[0-9+\-\s]{7,15}$/', $emergencyNumber)) {
                    throw new Exception('Please enter a valid emergency contact number.');
                }
            }

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

            $contactNumberInsert = $pdo->prepare(
                "INSERT INTO tenant_contact_number (tenant_id, contact_number) VALUES (?, ?)"
            );
            foreach ($contactNumbers as $contactNumber) {
                $contactNumberInsert->execute([$tenantId, $contactNumber]);
            }
            $pdo->prepare("INSERT INTO tenant_address (tenant_id, barangay, municipality, province) VALUES (?, ?, ?, ?)")
                ->execute([$tenantId, $barangay, $municipality, $province]);
            $emergencyNumberInsert = $pdo->prepare(
                "INSERT INTO tenant_emergency_contact (tenant_id, contact_name, relationship, contact_number) VALUES (?, ?, ?, ?)"
            );
            foreach ($emergencyNumbers as $emergencyNumber) {
                $emergencyNumberInsert->execute([$tenantId, $emergencyName, $relationship, $emergencyNumber]);
            }
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

            $q = $pdo->prepare(
                "SELECT 1
                 FROM room_assignment ra
                 INNER JOIN room r ON r.room_id = ra.room_id
                 INNER JOIN boarding_house bh ON bh.bh_id = r.bh_id
                 WHERE ra.tenant_id = ? AND ra.moveout_date IS NULL AND bh.owner_id = ?
                 LIMIT 1"
            );
            $q->execute([$tenantId, $ownerId]);
            if ($q->fetch()) {
                reassignRoom($pdo, $tenantId, $roomId, $ownerId);
                $msg = 'Room reassigned successfully!';
            } else {
                assignRoom($pdo, $tenantId, $roomId, $ownerId);
                $msg = 'Room assigned successfully!';
            }
            $pdo->commit();

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
    if (!isset($emer[$x['tenant_id']])) {
        $emer[$x['tenant_id']] = [
            'name' => $x['contact_name'],
            'relationship' => $x['relationship'],
            'phone' => $x['contact_number'],
        ];
    }
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

$transactions = [];
$stmt = $pdo->prepare(
    "SELECT b.billing_id, b.tenant_id, b.billing_start_date, b.billing_end_date, b.due_date, b.payment_status,
            COALESCE((SELECT SUM(bd.amount) FROM billing_details bd WHERE bd.billing_id = b.billing_id), 0) AS total_bill,
            COALESCE((SELECT SUM(p.amount_paid) FROM payment p WHERE p.billing_id = b.billing_id), 0) AS total_paid
     FROM billing b
     WHERE b.owner_id = ?
     ORDER BY b.billing_start_date DESC, b.billing_id DESC"
);
$stmt->execute([$ownerId]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $bill) {
    $tenantId = (int)$bill['tenant_id'];
    $totalBill = (float)$bill['total_bill'];
    $totalPaid = (float)$bill['total_paid'];
    $balance = max(0, round($totalBill - $totalPaid, 2));
    if ($totalBill <= 0) {
        $status = $bill['payment_status'];
    } elseif ($balance <= 0) {
        $status = 'Paid';
    } elseif ($bill['due_date'] < date('Y-m-d')) {
        $status = 'Overdue';
    } elseif ($totalPaid > 0) {
        $status = 'Partial';
    } else {
        $status = 'Unpaid';
    }
    $transactions[$tenantId][] = [
        'sort' => strtotime($bill['billing_start_date']),
        'type' => 'Bill',
        'date' => fdate($bill['billing_start_date']),
        'description' => 'Billing period ' . fdate($bill['billing_start_date']) . ' – ' . fdate($bill['billing_end_date'])
            . ' · Due ' . fdate($bill['due_date']),
        'amount' => $totalBill,
        'status' => $status,
        'reference' => 'Bill #' . (int)$bill['billing_id'],
    ];
}
$stmt = $pdo->prepare(
    "SELECT p.payment_id, p.receipt_number, p.payment_method, p.payment_date, p.amount_paid,
            b.tenant_id, b.billing_start_date, b.billing_end_date
     FROM payment p
     INNER JOIN billing b ON b.billing_id = p.billing_id
     WHERE p.owner_id = ? AND b.owner_id = ?
     ORDER BY p.payment_date DESC, p.payment_id DESC"
);
$stmt->execute([$ownerId, $ownerId]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $payment) {
    $tenantId = (int)$payment['tenant_id'];
    $transactions[$tenantId][] = [
        'sort' => strtotime($payment['payment_date']),
        'type' => 'Payment',
        'date' => date('M j, Y · h:i A', strtotime($payment['payment_date'])),
        'description' => $payment['payment_method'] . ' · Receipt ' . $payment['receipt_number']
            . ' · Billing period ' . fdate($payment['billing_start_date']) . ' – ' . fdate($payment['billing_end_date']),
        'amount' => (float)$payment['amount_paid'],
        'status' => 'Paid',
        'reference' => 'Payment #' . (int)$payment['payment_id'],
    ];
}
foreach ($transactions as &$tenantTransactions) {
    usort($tenantTransactions, function ($a, $b) { return $b['sort'] <=> $a['sort']; });
    foreach ($tenantTransactions as &$transaction) { unset($transaction['sort']); }
    unset($transaction);
}
unset($tenantTransactions);

$detail = [];
$total = count($tenants);
foreach ($tenants as $t) {
    $id   = (int)$t['tenant_id'];
    $name = trim($t['first_name'] . ' ' . ($t['middle_name'] ? $t['middle_name'] . ' ' : '') . $t['last_name']);
    $latestAssignment = $hist[$id][0] ?? null;
    $hasMovedOut = !$t['room_number']
        && $latestAssignment
        && $latestAssignment['out'] !== 'Present';
    $detail[$id] = [
        'name' => $name, 'initials' => initials($t['first_name'] . ' ' . $t['last_name']),
        'status' => $t['status'], 'email' => $t['email'], 'phone' => $t['contact_number'] ?? '—',
        'gender' => $t['gender'], 'birthdate' => fdate($t['birthdate']), 'occupation' => $t['occupation'],
        'address' => $addr[$id] ?? '—',
        'emergency' => $emer[$id] ?? ['name' => '—', 'relationship' => '', 'phone' => '—'],
        'room' => $t['room_number'], 'since' => $t['movein_date'] ? fdate($t['movein_date']) : null,
        'movedOut' => (bool)$hasMovedOut,
        'history' => $hist[$id] ?? [],
        'transactions' => $transactions[$id] ?? [],
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
    <link rel="stylesheet" href="../css/owner_tenants.css?v=28">
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
    <div class="tenant-page">

        <header class="tn-header page-header-banner">
            <div>
                <h1>Tenants</h1>
                <p>Register tenants, assign rooms and keep their records in one place.</p>
            </div>
            <button type="button" class="tn-add" id="openAdd">+ Add Tenant</button>
        </header>

        <?php if (($flash['type'] ?? '') === 'success'): ?>
            <dialog class="tn-success-dialog" id="tenantSuccessDialog" aria-labelledby="tenantSuccessTitle" aria-describedby="tenantSuccessMessage">
                <h2 id="tenantSuccessTitle">Success</h2>
                <p id="tenantSuccessMessage"><?= e($flash['msg'] ?? '') ?></p>
                <form method="dialog">
                    <button type="submit" class="tn-success-ok">OK</button>
                </form>
            </dialog>
        <?php elseif ($flash): ?>
            <div class="tn-alert <?= e($flash['type']) ?>" role="status"><?= e($flash['msg'] ?? '') ?></div>
        <?php endif; ?>
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
        <input type="hidden" name="current_step" id="tenantCurrentStep" value="0">

        <div class="tn-dlg-head">
            <div>
                <h2 id="addTitle">Add New Tenant</h2>
                <p>Fill in the tenant’s details and create their portal account.</p>
            </div>
            <button type="button" class="tn-x js-close" aria-label="Close">&times;</button>
        </div>

        <?php if ($error && $old): ?><div class="tn-alert error" role="alert"><?= e($error) ?></div><?php endif; ?>

        <ol class="tn-stepper" id="tenantStepper" aria-label="Tenant registration steps">
            <li class="tn-step active" aria-current="step"><span class="tn-step-number">1</span><span class="tn-step-label">Personal Info</span></li>
            <li class="tn-step"><span class="tn-step-number">2</span><span class="tn-step-label">Contact</span></li>
            <li class="tn-step"><span class="tn-step-number">3</span><span class="tn-step-label">Emergency</span></li>
            <li class="tn-step"><span class="tn-step-number">4</span><span class="tn-step-label">Room</span></li>
            <li class="tn-step"><span class="tn-step-number">5</span><span class="tn-step-label">Login</span></li>
        </ol>

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

            <div class="tn-section" hidden>
                <h3>Contact &amp; Address</h3>
                <div class="tn-fields">
                    <div class="tn-field full tn-number-list-field">
                        <span class="tn-number-list-label">Contact Numbers *</span>
                        <p class="tn-number-list-hint">Add at least one number. You can include more than one.</p>
                        <div class="tn-number-list" id="tenantContactNumberList">
                            <div class="tn-number-row">
                                <div class="tn-field">
                                    <label for="contact_number_0">Contact number 1</label>
                                    <input id="contact_number_0" type="tel" name="contact_numbers[]" maxlength="15" placeholder="09XXXXXXXXX" required>
                                </div>
                                <button type="button" class="tn-btn danger tn-number-remove" data-remove-number hidden>Remove</button>
                            </div>
                        </div>
                        <button type="button" class="tn-btn ghost tn-number-add" id="addTenantContactNumber">+ Add contact number</button>
                    </div>
                    <div class="tn-field"><label for="barangay">Barangay *</label><input id="barangay" type="text" name="barangay" maxlength="100" required></div>
                    <div class="tn-field"><label for="municipality">Municipality / City *</label><input id="municipality" type="text" name="municipality" maxlength="100" required></div>
                    <div class="tn-field full"><label for="province">Province *</label><input id="province" type="text" name="province" maxlength="100" required></div>
                </div>
            </div>

            <div class="tn-section" hidden>
                <h3>Emergency Contact</h3>
                <div class="tn-fields">
                    <div class="tn-field"><label for="emergency_name">Contact Person *</label><input id="emergency_name" type="text" name="emergency_name" maxlength="100" required></div>
                    <div class="tn-field"><label for="relationship">Relationship *</label><input id="relationship" type="text" name="relationship" maxlength="50" placeholder="e.g. Mother, Father" required></div>
                    <div class="tn-field full tn-number-list-field">
                        <span class="tn-number-list-label">Emergency Contact Numbers *</span>
                        <p class="tn-number-list-hint">Add at least one number. You can include more than one.</p>
                        <div class="tn-number-list" id="tenantEmergencyNumberList">
                            <div class="tn-number-row">
                                <div class="tn-field">
                                    <label for="emergency_number_0">Emergency contact number 1</label>
                                    <input id="emergency_number_0" type="tel" name="emergency_numbers[]" maxlength="15" required>
                                </div>
                                <button type="button" class="tn-btn danger tn-number-remove" data-remove-number hidden>Remove</button>
                            </div>
                        </div>
                        <button type="button" class="tn-btn ghost tn-number-add" id="addTenantEmergencyNumber">+ Add emergency contact number</button>
                    </div>
                </div>
            </div>

            <div class="tn-section" hidden>
                <h3>Room Assignment</h3>
                <div class="tn-fields">
                    <div class="tn-field full"><label for="room_id">Assign Room (optional)</label>
                        <select id="room_id" name="room_id"><option value="0">No room assignment yet</option><?= $roomOpts ?></select></div>
                </div>
            </div>

            <div class="tn-section" hidden>
                <h3>Tenant Login Account</h3>
                <div class="tn-fields">
                    <div class="tn-field"><label for="username">Username *</label><input id="username" type="text" name="username" minlength="3" maxlength="50" autocomplete="off" required></div>
                    <div class="tn-field"><label for="password">Password *</label><input id="password" type="password" name="password" minlength="8" autocomplete="new-password" placeholder="At least 8 characters" required></div>
                </div>
            </div>
        </div>

        <div class="tn-dlg-foot">
            <button type="button" class="tn-btn ghost js-close">Cancel</button>
            <div class="tn-step-controls">
                <span class="tn-step-count" id="tenantStepCount" aria-live="polite">Step 1 of 5</span>
                <button type="button" class="tn-btn ghost" id="tenantBack" hidden>Back</button>
                <button type="button" class="tn-btn" id="tenantNext">Next</button>
                <button type="submit" class="tn-btn" id="tenantSubmit" hidden>Save Tenant</button>
            </div>
        </div>
    </form>
</dialog>

<template id="tenantContactNumberTemplate">
    <div class="tn-number-row">
        <div class="tn-field">
            <label>Contact number</label>
            <input type="tel" name="contact_numbers[]" maxlength="15" placeholder="09XXXXXXXXX" required>
        </div>
        <button type="button" class="tn-btn danger tn-number-remove" data-remove-number>Remove</button>
    </div>
</template>

<template id="tenantEmergencyNumberTemplate">
    <div class="tn-number-row">
        <div class="tn-field">
            <label>Emergency contact number</label>
            <input type="tel" name="emergency_numbers[]" maxlength="15" required>
        </div>
        <button type="button" class="tn-btn danger tn-number-remove" data-remove-number>Remove</button>
    </div>
</template>

<!-- VIEW TENANT DIALOG -->
<dialog id="viewDialog" class="tn-dialog view-dialog" aria-labelledby="vName">
    <div class="tn-form">
        <div class="tn-dlg-head">
            <div class="tn-vhead">
                <span class="tn-av lg" id="vAv"></span>
                <div>
                    <h2 id="vName"></h2>
                    <div class="tn-vmeta"><span class="tn-pill" id="vStatus"></span><span>Tenant Profile</span></div>
                </div>
            </div>
            <button type="button" class="tn-x js-close" aria-label="Close">&times;</button>
        </div>

        <div class="tn-tabs" role="tablist" aria-label="Tenant details">
            <button type="button" class="tn-tab active" id="vTabPersonal" role="tab"
                    aria-selected="true" aria-controls="vPanelPersonal" data-view-tab="personal">Personal Info</button>
            <button type="button" class="tn-tab" id="vTabRoom" role="tab"
                    aria-selected="false" aria-controls="vPanelRoom" data-view-tab="room" tabindex="-1">Room Details</button>
            <button type="button" class="tn-tab" id="vTabTransactions" role="tab"
                    aria-selected="false" aria-controls="vPanelTransactions" data-view-tab="transactions" tabindex="-1">Transactions</button>
        </div>

        <div class="tn-tab-panel" id="vPanelPersonal" role="tabpanel" aria-labelledby="vTabPersonal">
            <h3 class="tn-profile-section">Basic Information</h3>
            <div class="tn-basic-grid" id="vInfo"></div>
            <div class="tn-emergency">
                <h3><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.79 19.79 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.12.9.33 1.78.63 2.63a2 2 0 0 1-.45 2.11L8.03 9.72a16 16 0 0 0 6 6l1.26-1.26a2 2 0 0 1 2.11-.45c.85.3 1.73.51 2.63.63A2 2 0 0 1 22 16.92z"/></svg> Emergency Contact</h3>
                <p class="tn-emergency-name" id="vEmergencyName"></p>
                <p class="tn-emergency-phone" id="vEmergencyPhone"></p>
            </div>
        </div>

        <div class="tn-tab-panel" id="vPanelRoom" role="tabpanel" aria-labelledby="vTabRoom" hidden>
            <h3 class="tn-sub" id="vRoomTitle">Current Room</h3>
            <div id="vRoomBox"></div>

            <h3 class="tn-sub">Room History</h3>
            <ul class="tn-hist" id="vHist"></ul>

            <div class="tn-action-row" id="tenantActions" hidden>
                <form method="POST" id="assignForm" class="tn-action-form" hidden>
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="assign_room">
                    <input type="hidden" name="tenant_id" class="js-tid">
                    <select name="room_id" required aria-label="Select a room">
                        <option value="">Select an available room</option>
                        <?= $roomOpts ?>
                    </select>
                    <button type="submit" class="tn-btn" id="assignSubmit">Assign Room</button>
                </form>

                <form method="POST" id="moveOutForm" class="tn-action-form" hidden>
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="move_out">
                    <input type="hidden" name="tenant_id" class="js-tid">
                    <button type="submit" class="tn-btn danger">Move Out</button>
                </form>
            </div>
        </div>

        <div class="tn-tab-panel" id="vPanelTransactions" role="tabpanel"
             aria-labelledby="vTabTransactions" hidden>
            <div class="tn-transaction-wrap">
                <table class="tn-transaction-table">
                    <thead>
                        <tr><th>Date</th><th>Transaction</th><th>Amount</th><th>Status</th></tr>
                    </thead>
                    <tbody id="vTransactions"></tbody>
                </table>
                <p class="tn-none tn-no-transactions" id="vNoTransactions" hidden>No billing or payment history yet.</p>
            </div>
        </div>

        <div class="tn-dlg-foot">
            <button type="button" class="tn-btn ghost js-close">Close</button>
        </div>
    </div>
</dialog>

<dialog id="moveOutConfirmDialog" class="tn-dialog confirm-dialog" aria-labelledby="moveOutConfirmTitle" aria-describedby="moveOutConfirmMessage">
    <div class="tn-confirm-content">
        <div class="tn-dlg-head">
            <div>
                <h2 id="moveOutConfirmTitle">Confirm Move Out</h2>
                <p id="moveOutConfirmMessage">Move this tenant out? Their room history will be kept.</p>
            </div>
        </div>

        <div class="tn-dlg-foot">
            <button type="button" class="tn-btn ghost" id="moveOutCancel">Cancel</button>
            <button type="button" class="tn-btn danger" id="moveOutConfirm">Move Out</button>
        </div>
    </div>
</dialog>

<script>
(function () {
    var detail = <?= json_encode($detail, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
    var successDlg = document.getElementById('tenantSuccessDialog');
    if (successDlg) successDlg.showModal();

    var addDlg = document.getElementById('addDialog');
    var viewDlg = document.getElementById('viewDialog');
    var moveOutDlg = document.getElementById('moveOutConfirmDialog');
    var moveOutForm = document.getElementById('moveOutForm');
    var moveOutConfirmed = false;
    var addForm = document.getElementById('addForm');
    var tenantSteps = Array.prototype.slice.call(addForm.querySelectorAll('.tn-section'));
    var tenantStepper = document.getElementById('tenantStepper');
    var stepItems = Array.prototype.slice.call(document.querySelectorAll('#tenantStepper .tn-step'));
    var tenantBack = document.getElementById('tenantBack');
    var tenantNext = document.getElementById('tenantNext');
    var tenantSubmit = document.getElementById('tenantSubmit');
    var tenantStepCount = document.getElementById('tenantStepCount');
    var currentStep = 0;

    function setupNumberList(listId, templateId, addButtonId, inputIdPrefix, labelPrefix) {
        var list = document.getElementById(listId);
        var template = document.getElementById(templateId);
        var addButton = document.getElementById(addButtonId);

        function updateLabels() {
            var rows = Array.prototype.slice.call(list.querySelectorAll('.tn-number-row'));
            rows.forEach(function (row, index) {
                var input = row.querySelector('input');
                var label = row.querySelector('label');
                var removeButton = row.querySelector('[data-remove-number]');
                input.id = inputIdPrefix + index;
                label.htmlFor = input.id;
                label.textContent = labelPrefix + ' ' + (index + 1);
                removeButton.hidden = rows.length === 1;
                removeButton.setAttribute('aria-label', 'Remove ' + labelPrefix + ' ' + (index + 1));
            });
        }

        function addNumber(value) {
            var row = template.content.firstElementChild.cloneNode(true);
            if (typeof value === 'string') {
                row.querySelector('input').value = value;
            }
            list.appendChild(row);
            updateLabels();
        }

        function reset() {
            var rows = Array.prototype.slice.call(list.querySelectorAll('.tn-number-row'));
            rows.slice(1).forEach(function (row) { row.remove(); });
            rows[0].querySelector('input').value = '';
            updateLabels();
        }

        function restore(values) {
            if (!Array.isArray(values)) {
                values = values ? [values] : [''];
            }
            if (!values.length) values = [''];
            reset();
            list.querySelector('input').value = String(values[0] || '');
            values.slice(1).forEach(function (number) { addNumber(String(number)); });
        }

        updateLabels();
        addButton.addEventListener('click', function () {
            addNumber('');
            var inputs = list.querySelectorAll('input');
            inputs[inputs.length - 1].focus();
        });
        list.addEventListener('click', function (event) {
            var removeButton = event.target.closest('[data-remove-number]');
            if (!removeButton || list.querySelectorAll('.tn-number-row').length <= 1) return;
            removeButton.closest('.tn-number-row').remove();
            updateLabels();
        });

        return { reset: reset, restore: restore };
    }

    var contactNumberFields = setupNumberList(
        'tenantContactNumberList',
        'tenantContactNumberTemplate',
        'addTenantContactNumber',
        'contact_number_',
        'Contact number'
    );
    var emergencyNumberFields = setupNumberList(
        'tenantEmergencyNumberList',
        'tenantEmergencyNumberTemplate',
        'addTenantEmergencyNumber',
        'emergency_number_',
        'Emergency contact number'
    );

    function showTenantStep(index, focusFirst) {
        currentStep = Math.max(0, Math.min(index, tenantSteps.length - 1));
        tenantSteps.forEach(function (step, i) { step.hidden = i !== currentStep; });
        stepItems.forEach(function (step, i) {
            step.classList.toggle('active', i === currentStep);
            step.classList.toggle('done', i < currentStep);
            if (i === currentStep) step.setAttribute('aria-current', 'step');
            else step.removeAttribute('aria-current');
        });
        tenantStepper.style.setProperty('--tn-progress',
            (currentStep / (tenantSteps.length - 1) * 80) + '%');
        document.getElementById('tenantCurrentStep').value = currentStep;
        tenantStepCount.textContent = 'Step ' + (currentStep + 1) + ' of ' + tenantSteps.length;
        tenantBack.hidden = currentStep === 0;
        tenantNext.hidden = currentStep === tenantSteps.length - 1;
        tenantSubmit.hidden = currentStep !== tenantSteps.length - 1;
        addDlg.scrollTop = 0;
        if (focusFirst) {
            var firstField = tenantSteps[currentStep].querySelector('input:not([type="hidden"]), select, textarea');
            if (firstField) firstField.focus();
        }
    }

    function currentTenantStepIsValid() {
        var invalidField = tenantSteps[currentStep].querySelector(':invalid');
        if (!invalidField) return true;
        invalidField.reportValidity();
        return false;
    }

    /* ---- close buttons + click sa labas ---- */
    [addDlg, viewDlg].forEach(function (d) {
        d.querySelectorAll('.js-close').forEach(function (b) { b.addEventListener('click', function () { d.close(); }); });
        d.addEventListener('click', function (e) { if (e.target === d) d.close(); });
    });

    moveOutForm.addEventListener('submit', function (event) {
        if (moveOutConfirmed) {
            moveOutConfirmed = false;
            return;
        }

        event.preventDefault();
        moveOutDlg.showModal();
        document.getElementById('moveOutCancel').focus();
    });

    document.getElementById('moveOutCancel').addEventListener('click', function () {
        moveOutDlg.close();
    });

    document.getElementById('moveOutConfirm').addEventListener('click', function () {
        moveOutConfirmed = true;
        moveOutDlg.close();
        moveOutForm.requestSubmit();
    });

    /* ---- Add tenant ---- */
    document.getElementById('openAdd').addEventListener('click', function () {
        addForm.reset();
        contactNumberFields.reset();
        emergencyNumberFields.reset();
        showTenantStep(0, false);
        addDlg.showModal();
        document.getElementById('first_name').focus();
    });

    if (new URLSearchParams(window.location.search).get('action') === 'add') {
        addForm.reset();
        contactNumberFields.reset();
        emergencyNumberFields.reset();
        showTenantStep(0, false);
        addDlg.showModal();
        document.getElementById('first_name').focus();
    }

    tenantNext.addEventListener('click', function () {
        if (currentTenantStepIsValid()) showTenantStep(currentStep + 1, true);
    });
    tenantBack.addEventListener('click', function () {
        showTenantStep(currentStep - 1, true);
    });
    addForm.addEventListener('submit', function () {
        document.getElementById('tenantCurrentStep').value = currentStep;
    });

    <?php if ($old): ?>
    // May error: ibalik ang mga ininput (maliban sa password)
    var oldVals = <?= json_encode($old, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
    contactNumberFields.restore(oldVals.contact_numbers);
    emergencyNumberFields.restore(oldVals.emergency_numbers);
    Object.keys(oldVals).forEach(function (k) {
        if (k === 'contact_numbers' || k === 'emergency_numbers') return;
        var el = document.querySelector('#addForm [name="' + k + '"]');
        if (el && el.type !== 'hidden') { el.value = oldVals[k]; }
    });
    var restoredStep = parseInt(oldVals.current_step, 10);
    showTenantStep(isNaN(restoredStep) ? 0 : restoredStep, false);
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

    var viewTabs = document.querySelectorAll('#viewDialog [data-view-tab]');
    function activateViewTab(name) {
        viewTabs.forEach(function (tab) {
            var selected = tab.dataset.viewTab === name;
            tab.classList.toggle('active', selected);
            tab.setAttribute('aria-selected', selected ? 'true' : 'false');
            tab.tabIndex = selected ? 0 : -1;
            var panelName = tab.dataset.viewTab;
            document.getElementById('vPanel' + panelName.charAt(0).toUpperCase() + panelName.slice(1)).hidden = !selected;
        });
    }
    viewTabs.forEach(function (tab, index) {
        tab.addEventListener('click', function () { activateViewTab(tab.dataset.viewTab); });
        tab.addEventListener('keydown', function (event) {
            var next = index;
            if (event.key === 'ArrowRight') { next = (index + 1) % viewTabs.length; }
            else if (event.key === 'ArrowLeft') { next = (index + viewTabs.length - 1) % viewTabs.length; }
            else if (event.key === 'Home') { next = 0; }
            else if (event.key === 'End') { next = viewTabs.length - 1; }
            else { return; }
            event.preventDefault();
            viewTabs[next].focus();
            activateViewTab(viewTabs[next].dataset.viewTab);
        });
    });

    document.querySelectorAll('.js-view').forEach(function (b) {
        b.addEventListener('click', function () {
            var t = detail[b.dataset.id];
            if (!t) return;
            activateViewTab('personal');

            document.getElementById('vAv').textContent = t.initials;
            document.getElementById('vName').textContent = t.name;
            var pill = document.getElementById('vStatus');
            pill.textContent = t.movedOut ? 'Moved Out' : t.status;
            pill.className = 'tn-pill ' + (t.movedOut ? 'movedout' : t.status.toLowerCase());

            var info = document.getElementById('vInfo');
            info.innerHTML = '';
            [
                ['Email', t.email],
                ['Contact', t.phone],
                ['Gender', t.gender],
                ['Birthdate', t.birthdate],
                ['Occupation', t.occupation],
                ['Address', t.address]
            ].forEach(function (item) {
                var card = el('div', 'tn-info-card');
                card.appendChild(el('span', '', item[0]));
                card.appendChild(el('strong', '', item[1]));
                info.appendChild(card);
            });
            var emergencyName = (t.emergency.name || '—');
            if (t.emergency.relationship) emergencyName += ' (' + t.emergency.relationship + ')';
            document.getElementById('vEmergencyName').textContent = emergencyName;
            document.getElementById('vEmergencyPhone').textContent = t.emergency.phone || '—';

            var box = document.getElementById('vRoomBox');
            box.innerHTML = '';
            var roomTitle = document.getElementById('vRoomTitle');
            roomTitle.hidden = t.movedOut;
            box.hidden = t.movedOut;
            if (!t.movedOut) {
                if (t.room) {
                    var c = el('div', 'tn-curroom');
                    c.appendChild(el('span', 'tn-room', t.room));
                    c.appendChild(el('small', '', 'Moved in ' + t.since));
                    box.appendChild(c);
                } else {
                    box.appendChild(el('p', 'tn-none', 'This tenant is not assigned to a room.'));
                }
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

            var transactionBody = document.getElementById('vTransactions');
            transactionBody.innerHTML = '';
            document.getElementById('vNoTransactions').hidden = t.transactions.length > 0;
            t.transactions.forEach(function (transaction) {
                var tr = el('tr');
                tr.appendChild(el('td', '', transaction.date));
                var description = el('td', 'tn-transaction-description');
                description.appendChild(el('strong', '', transaction.type));
                description.appendChild(el('small', '', transaction.reference + ' · ' + transaction.description));
                tr.appendChild(description);
                tr.appendChild(el('td', 'tn-transaction-amount',
                    '₱' + Number(transaction.amount).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2})));
                var status = transaction.status.toLowerCase().replace(/\s+/g, '-');
                var statusCell = el('td');
                statusCell.appendChild(el('span', 'tn-pill ' + status, transaction.status));
                tr.appendChild(statusCell);
                transactionBody.appendChild(tr);
            });

            // Move out kung may room; Assign/Reassign kung active
            var mo = document.getElementById('moveOutForm'), as = document.getElementById('assignForm');
            mo.hidden = !t.room;
            as.hidden = t.status !== 'Active' || t.movedOut;
            document.getElementById('tenantActions').hidden = mo.hidden && as.hidden;
            document.getElementById('assignSubmit').textContent = t.room ? 'Reassign Room' : 'Assign Room';
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