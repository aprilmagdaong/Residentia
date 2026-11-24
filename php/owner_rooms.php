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
    die('Owner ID is missing. Please log in again.');
}

$pdo = db();

function e($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function peso($amount) { return '₱' . number_format((float)$amount, 2); }
function initials($name) {
    $out = '';
    foreach (array_slice(preg_split('/\s+/', trim((string)$name)), 0, 2) as $w) { $out .= mb_strtoupper(mb_substr($w, 0, 1)); }
    return $out;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Flash message galing sa nakaraang action (Post/Redirect/Get)
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$error = '';
$old = null;   // para ma-reopen ang form kapag may error

// Boarding houses ng owner
$stmt = $pdo->prepare("SELECT bh_id, bh_name FROM boarding_house WHERE owner_id = ? ORDER BY bh_name ASC");
$stmt->execute([$owner_id]);
$boardingHouses = $stmt->fetchAll(PDO::FETCH_ASSOC);

$allowedStatuses = ['Available', 'Occupied', 'Maintenance'];
$allowedTypes    = ['Bedspace', 'Solo Room'];

/* Kunin ang room kung pag-aari ng owner, kasama ang bilang ng kasalukuyang tenant */
function ownedRoom(PDO $pdo, int $room_id, int $owner_id) {
    $q = $pdo->prepare("
        SELECT r.*, 
          (SELECT COUNT(*) FROM room_assignment ra WHERE ra.room_id = r.room_id AND ra.moveout_date IS NULL) AS occupants,
          (SELECT COUNT(*) FROM room_assignment ra WHERE ra.room_id = r.room_id) AS history_count
        FROM room r
        INNER JOIN boarding_house bh ON bh.bh_id = r.bh_id
        WHERE r.room_id = ? AND bh.owner_id = ?");
    $q->execute([$room_id, $owner_id]);
    return $q->fetch(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            throw new Exception('Invalid request. Please refresh the page.');
        }

        if ($action === 'update_status') {
            $room_id = filter_input(INPUT_POST, 'room_id', FILTER_VALIDATE_INT);
            $room_status = $_POST['room_status'] ?? '';
            if (!$room_id || !in_array($room_status, $allowedStatuses, true)) {
                throw new Exception('Please select a valid room status.');
            }
            if (!ownedRoom($pdo, $room_id, $owner_id)) {
                throw new Exception('Room not found.');
            }
            $pdo->prepare("UPDATE room SET room_status = ? WHERE room_id = ?")->execute([$room_status, $room_id]);
            $msg = 'Room status updated.';

        } elseif ($action === 'add' || $action === 'edit') {
            $room_id      = filter_input(INPUT_POST, 'room_id', FILTER_VALIDATE_INT);
            $bh_id        = count($boardingHouses) === 1
                              ? (int)$boardingHouses[0]['bh_id']
                              : filter_input(INPUT_POST, 'bh_id', FILTER_VALIDATE_INT);
            $room_number  = trim($_POST['room_number'] ?? '');
            $room_type    = $_POST['room_type'] ?? '';
            $capacity     = filter_input(INPUT_POST, 'capacity', FILTER_VALIDATE_INT);
            $monthly_rate = filter_input(INPUT_POST, 'monthly_rate', FILTER_VALIDATE_FLOAT);
            $old = $_POST;

            if (!$bh_id || $room_number === '') throw new Exception('Please complete the required fields.');
            if (!in_array($room_type, $allowedTypes, true)) throw new Exception('Please select a valid room type.');
            if (!$capacity || $capacity < 1) throw new Exception('Capacity must be at least 1.');
            if ($monthly_rate === false || $monthly_rate === null || $monthly_rate < 0) throw new Exception('Please enter a valid monthly rate.');
            $q = $pdo->prepare("SELECT bh_id FROM boarding_house WHERE bh_id = ? AND owner_id = ?");
            $q->execute([$bh_id, $owner_id]);
            if (!$q->fetch()) throw new Exception('Invalid boarding house selected.');

            // Duplicate room number sa parehong boarding house
            $q = $pdo->prepare("SELECT room_id FROM room WHERE bh_id = ? AND room_number = ? AND room_id <> ?");
            $q->execute([$bh_id, $room_number, $action === 'edit' ? (int)$room_id : 0]);
            if ($q->fetch()) throw new Exception('This room number already exists in the selected boarding house.');

            if ($action === 'add') {
                $pdo->prepare("INSERT INTO room (bh_id, room_number, room_type, capacity, monthly_rate, room_status)
                               VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$bh_id, $room_number, $room_type, $capacity, $monthly_rate, 'Available']);
                $msg = 'Room successfully added!';
            } else {
                $room = $room_id ? ownedRoom($pdo, $room_id, $owner_id) : false;
                if (!$room) throw new Exception('Room not found.');
                // Business rule: hindi puwedeng mas mababa ang capacity sa kasalukuyang tenant
                if ($capacity < (int)$room['occupants']) {
                    throw new Exception('Capacity cannot be lower than the ' . (int)$room['occupants'] . ' tenant(s) currently assigned.');
                }
                $pdo->prepare("UPDATE room SET bh_id=?, room_number=?, room_type=?, capacity=?, monthly_rate=? WHERE room_id=?")
                    ->execute([$bh_id, $room_number, $room_type, $capacity, $monthly_rate, $room_id]);
                $msg = 'Room updated successfully!';
            }

        } else {
            throw new Exception('Invalid action.');
        }

        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['flash'] = ['type' => 'success', 'msg' => $msg];
        header('Location: owner_rooms.php');   // iwas double-submit kapag nag-refresh
        exit;

    } catch (PDOException $ex) {
        $error = 'Database error: ' . $ex->getMessage();
    } catch (Exception $ex) {
        $error = $ex->getMessage();
    }
    if ($action === 'update_status') { $old = null; }
}

// Lahat ng rooms ng owner + occupancy
$stmt = $pdo->prepare("
    SELECT r.room_id, r.bh_id, r.room_number, r.room_type, r.capacity, r.monthly_rate, r.room_status,
           (SELECT COUNT(*) FROM room_assignment ra WHERE ra.room_id = r.room_id AND ra.moveout_date IS NULL) AS occupants
    FROM room r
    INNER JOIN boarding_house bh ON bh.bh_id = r.bh_id
    WHERE bh.owner_id = ?
    ORDER BY bh.bh_name ASC, r.room_number ASC
");
$stmt->execute([$owner_id]);
$rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Sino ang kasalukuyang nakatira sa bawat room (moveout_date ay wala pa)
$stmt = $pdo->prepare("
    SELECT ra.room_id, t.tenant_id,
           CONCAT(t.first_name, ' ', t.last_name) AS full_name,
           ra.movein_date
    FROM room_assignment ra
    INNER JOIN tenant t          ON t.tenant_id = ra.tenant_id
    INNER JOIN room r            ON r.room_id   = ra.room_id
    INNER JOIN boarding_house bh ON bh.bh_id    = r.bh_id
    WHERE bh.owner_id = ? AND ra.moveout_date IS NULL
    ORDER BY t.last_name ASC, t.first_name ASC
");
$stmt->execute([$owner_id]);
$occupantsByRoom = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $o) {
    $occupantsByRoom[(int)$o['room_id']][] = [
        'name'  => $o['full_name'],
        'since' => date('M j, Y', strtotime($o['movein_date'])),
    ];
}

$count = ['Available' => 0, 'Occupied' => 0, 'Maintenance' => 0];
$beds = 0; $filled = 0;
foreach ($rooms as $r) {
    if (isset($count[$r['room_status']])) $count[$r['room_status']]++;
    $beds   += (int)$r['capacity'];
    $filled += (int)$r['occupants'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rooms | Residentia</title>

    <link rel="stylesheet" href="../css/owner_db.css?v=4">
    <link rel="stylesheet" href="../css/owner_rooms.css?v=15">
    <link rel="stylesheet" href="../css/owner_ui.css?v=1">

    
</head>
<body>
<div class="layout">

    <!-- SIDEBAR -->
    <aside class="sidebar">
        <picture>
            <img class="logo" src="../image/db_logo.png" alt="Residentia Logo">
        </picture>

        <nav class="nav">
            <a href="owner_db.php">Dashboard</a>
            <a href="owner_rooms.php" class="active">Rooms</a>
            <a href="owner_tenants.php">Tenants</a>
            <a href="owner_billing.php">Billing &amp; Payments</a>
            <a href="owner_maintenance.php">Maintenance</a>
            <a href="owner_announcement.php">Announcements</a>
            <a href="owner_reports.php">Reports</a>
        </nav>

        <a class="logout" href="../logout.php">Log out</a>
    </aside>

    <!-- MAIN -->
    <main class="main">
    <div class="room-page">

        <header class="rm-header">
            <div>
                <h1>Rooms</h1>
                <p>Manage your boarding house rooms, capacity and availability.</p>
            </div>
            <?php if (!empty($boardingHouses)): ?>
                <button type="button" class="rm-add" id="openAdd">+ Add Room</button>
            <?php endif; ?>
        </header>

        <?php if ($flash): ?>
            <div class="rm-alert <?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
        <?php endif; ?>
        <?php if ($error && !$old): ?>
            <div class="rm-alert error" role="alert"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if (empty($boardingHouses)): ?>
            <div class="rm-alert error">No boarding house is registered under your account. Please register your boarding house first.</div>
        <?php endif; ?>

        <!-- SUMMARY -->
        <section class="rm-stats">
            <article class="rm-stat">
                <span class="rm-stat-label">Total Rooms</span>
                <strong><?= count($rooms) ?></strong>
                <small><?= $filled ?> of <?= $beds ?> beds filled</small>
            </article>
            <article class="rm-stat available">
                <span class="rm-stat-label">Available</span>
                <strong><?= $count['Available'] ?></strong>
                <small>Ready for move-in</small>
            </article>
            <article class="rm-stat occupied">
                <span class="rm-stat-label">Occupied</span>
                <strong><?= $count['Occupied'] ?></strong>
                <small>Fully occupied</small>
            </article>
            <article class="rm-stat maintenance">
                <span class="rm-stat-label">Maintenance</span>
                <strong><?= $count['Maintenance'] ?></strong>
                <small>Not available</small>
            </article>
        </section>

        <!-- ROOM LIST -->
        <section class="rm-card">
            <div class="rm-toolbar">
                <div class="rm-heading">
                    <h2>Registered Rooms</h2>
                    <p><span id="rmShown"><?= count($rooms) ?></span> room(s) shown</p>
                </div>

                <div class="rm-controls">
                    <div class="rm-search">
                        <input type="search" id="rmSearch" placeholder="Search room number or type" aria-label="Search rooms">
                    </div>
                    <div class="rm-chips" role="group" aria-label="Filter by status">
                        <button type="button" class="rm-chip active" data-filter="all">All</button>
                        <button type="button" class="rm-chip" data-filter="available">Available</button>
                        <button type="button" class="rm-chip" data-filter="occupied">Occupied</button>
                        <button type="button" class="rm-chip" data-filter="maintenance">Maintenance</button>
                    </div>
                </div>
            </div>

            <div class="rm-table-wrap">
                <table class="rm-table">
                    <thead>
                        <tr>
                            <th>Room</th>
                            <th>Type</th>
                            <th>Occupancy</th>
                            <th>Tenants</th>
                            <th>Monthly Rate</th>
                            <th>Status</th>
                            <th class="rm-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="rmBody">
                    <?php if (empty($rooms)): ?>
                        <tr><td colspan="7" class="rm-empty">No rooms have been added yet.</td></tr>
                    <?php else: foreach ($rooms as $room):
                        $cap = max((int)$room['capacity'], 1);
                        $occ = (int)$room['occupants'];
                        $pct = min(100, round($occ / $cap * 100));
                        $st  = strtolower($room['room_status']);
                    ?>
                        <tr data-status="<?= e($st) ?>" data-search="<?= e(strtolower($room['room_number'] . ' ' . $room['room_type'])) ?>">
                            <td><strong class="rm-num"><?= e($room['room_number']) ?></strong></td>
                            <td><span class="rm-type"><?= e($room['room_type']) ?></span></td>
                            <td>
                                <div class="rm-occ">
                                    <span class="rm-occ-text"><?= $occ ?> / <?= (int)$room['capacity'] ?></span>
                                    <span class="rm-bar<?= $occ >= $cap ? ' full' : '' ?>"><span style="width:<?= $pct ?>%"></span></span>
                                </div>
                            </td>
                            <td>
                                <?php $list = $occupantsByRoom[(int)$room['room_id']] ?? []; ?>
                                <button type="button" class="rm-view js-occ"
                                        data-id="<?= (int)$room['room_id'] ?>"
                                        data-room="<?= e($room['room_number']) ?>"
                                        data-cap="<?= (int)$room['capacity'] ?>"
                                        data-status="<?= e($room['room_status']) ?>"
                                        aria-label="View room <?= e($room['room_number']) ?>">
                                    <?php if ($list): ?>
                                        <span class="rm-av sm" aria-hidden="true"><?= e(initials($list[0]['name'])) ?></span>
                                        <?php if (count($list) > 1): ?><span class="rm-plus" aria-hidden="true">+<?= count($list) - 1 ?></span><?php endif; ?>
                                    <?php endif; ?>
                                    <span class="rm-view-text">View</span>
                                </button>
                            </td>
                            <td><?= peso($room['monthly_rate']) ?></td>
                            <td><span class="rm-status <?= e($st) ?>"><?= e($room['room_status']) ?></span></td>
                            <td class="rm-right">
                                <div class="rm-row-actions">
                                    <button type="button" class="rm-link js-edit"
                                        data-id="<?= (int)$room['room_id'] ?>"
                                        data-bh="<?= (int)$room['bh_id'] ?>"
                                        data-number="<?= e($room['room_number']) ?>"
                                        data-type="<?= e($room['room_type']) ?>"
                                        data-capacity="<?= (int)$room['capacity'] ?>"
                                        data-rate="<?= e($room['monthly_rate']) ?>"
                                        data-status="<?= e($room['room_status']) ?>">Edit</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                        <tr id="rmNoMatch" hidden><td colspan="7" class="rm-empty">No rooms match your search.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

    </div>
    </main>
</div>

<!-- ADD / EDIT DIALOG -->
<?php if (!empty($boardingHouses)): ?>
<dialog id="roomDialog" class="rm-dialog" aria-labelledby="dlgTitle">
    <form method="POST" class="rm-form" id="roomForm">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" id="fAction" value="add">
        <input type="hidden" name="room_id" id="fId" value="">

        <div class="rm-dlg-head">
            <div>
                <h2 id="dlgTitle">Add New Room</h2>
                <p id="dlgSub">Enter the details of the room you want to register.</p>
            </div>
            <button type="button" class="rm-x" id="dlgClose" aria-label="Close">&times;</button>
        </div>

        <?php if ($error && $old): ?>
            <div class="rm-alert error" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <div class="rm-fields">
            <?php if (count($boardingHouses) > 1): ?>
                <div class="rm-field full">
                    <label for="fBh">Boarding House</label>
                    <select name="bh_id" id="fBh" required>
                        <option value="">Select boarding house</option>
                        <?php foreach ($boardingHouses as $h): ?>
                            <option value="<?= (int)$h['bh_id'] ?>"><?= e($h['bh_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div class="rm-field">
                <label for="fNumber">Room Number</label>
                <input type="text" name="room_number" id="fNumber" placeholder="e.g. RM-101" maxlength="20" required>
            </div>
            <div class="rm-field">
                <label for="fType">Room Type</label>
                <select name="room_type" id="fType" required>
                    <option value="">Select room type</option>
                    <option value="Bedspace">Bedspace</option>
                    <option value="Solo Room">Solo Room</option>
                </select>
            </div>
            <div class="rm-field">
                <label for="fCapacity">Capacity</label>
                <input type="number" name="capacity" id="fCapacity" min="1" placeholder="No. of tenants" required>
            </div>
            <div class="rm-field">
                <label for="fRate">Monthly Rate (₱)</label>
                <input type="number" name="monthly_rate" id="fRate" min="0" step="0.01" placeholder="0.00" required>
            </div>
        </div>

        <div class="rm-dlg-foot">
            <button type="button" class="rm-btn ghost" id="dlgCancel">Cancel</button>
            <button type="submit" class="rm-btn" id="dlgSubmit">Add Room</button>
        </div>
    </form>
</dialog>
<?php endif; ?>

<!-- OCCUPANTS DIALOG -->
<dialog id="occDialog" class="rm-dialog rm-occ-dialog" aria-labelledby="occTitle">
    <div class="rm-form">
        <div class="rm-dlg-head">
            <div>
                <h2 id="occTitle">Room</h2>
                <p id="occSub"></p>
            </div>
            <button type="button" class="rm-x" id="occClose" aria-label="Close">&times;</button>
        </div>
        <form method="POST" class="rm-status-edit">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="room_id" id="occRoomId" value="">
            <div class="rm-field">
                <label for="occStatus">Room Status</label>
                <select name="room_status" id="occStatus" required>
                    <?php foreach ($allowedStatuses as $s): ?>
                        <option value="<?= e($s) ?>"><?= e($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="rm-btn">Save Status</button>
        </form>
        <ul class="rm-occ-list" id="occList"></ul>
        <div class="rm-dlg-foot">
            <a class="rm-btn ghost" href="owner_tenants.php">Go to Tenants</a>
            <button type="button" class="rm-btn" id="occDone">Close</button>
        </div>
    </div>
</dialog>

<script>
(function () {
    var dlg = document.getElementById('roomDialog');

    /* ---------- Who is staying in the room ---------- */
    var occupants = <?= json_encode($occupantsByRoom, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    var occDlg = document.getElementById('occDialog');
    function initials(n) { return n.split(' ').filter(Boolean).slice(0, 2).map(function (w) { return w[0].toUpperCase(); }).join(''); }
    document.querySelectorAll('.js-occ').forEach(function (b) {
        b.addEventListener('click', function () {
            var list = occupants[b.dataset.id] || [];
            document.getElementById('occTitle').textContent = 'Room ' + b.dataset.room;
            document.getElementById('occSub').textContent = list.length + ' of ' + b.dataset.cap + ' beds occupied';
            document.getElementById('occRoomId').value = b.dataset.id;
            document.getElementById('occStatus').value = b.dataset.status;
            var ul = document.getElementById('occList');
            ul.innerHTML = '';
            if (!list.length) {
                var li = document.createElement('li');
                li.className = 'rm-occ-empty';
                li.textContent = 'No tenants are assigned to this room.';
                ul.appendChild(li);
            }
            list.forEach(function (t) {
                var li = document.createElement('li');
                var av = document.createElement('span'); av.className = 'rm-av'; av.textContent = initials(t.name);
                var box = document.createElement('div');
                var nm = document.createElement('strong'); nm.textContent = t.name;
                var sn = document.createElement('small'); sn.textContent = 'Moved in ' + t.since;
                box.appendChild(nm); box.appendChild(sn);
                li.appendChild(av); li.appendChild(box);
                ul.appendChild(li);
            });
            occDlg.showModal();
        });
    });
    ['occClose', 'occDone'].forEach(function (id) {
        document.getElementById(id).addEventListener('click', function () { occDlg.close(); });
    });
    occDlg.addEventListener('click', function (e) { if (e.target === occDlg) occDlg.close(); });

    /* ---------- Add / Edit dialog ---------- */
    if (dlg) {
        var $ = function (id) { return document.getElementById(id); };
        var form = $('roomForm');

        function fill(d) {
            $('fAction').value   = d.action;
            $('fId').value       = d.id || '';
            $('fNumber').value   = d.number || '';
            $('fType').value     = d.type || '';
            $('fCapacity').value = d.capacity || '';
            $('fRate').value     = d.rate || '';
            if ($('fBh')) $('fBh').value = d.bh || '';
            $('dlgTitle').textContent  = d.action === 'edit' ? 'Edit Room' : 'Add New Room';
            $('dlgSub').textContent    = d.action === 'edit' ? 'Update the details of this room.' : 'Enter the details of the room you want to register.';
            $('dlgSubmit').textContent = d.action === 'edit' ? 'Save Changes' : 'Add Room';
        }
        function openDlg(d) { fill(d); dlg.showModal(); $('fNumber').focus(); }
        function closeDlg() { dlg.close(); }

        var addBtn = $('openAdd');
        if (addBtn) addBtn.addEventListener('click', function () { openDlg({ action: 'add' }); });
        if (new URLSearchParams(window.location.search).get('action') === 'add') {
            openDlg({ action: 'add' });
        }

        document.querySelectorAll('.js-edit').forEach(function (b) {
            b.addEventListener('click', function () {
                openDlg({ action: 'edit', id: b.dataset.id, bh: b.dataset.bh, number: b.dataset.number,
                          type: b.dataset.type, capacity: b.dataset.capacity, rate: b.dataset.rate });
            });
        });

        $('dlgClose').addEventListener('click', closeDlg);
        $('dlgCancel').addEventListener('click', closeDlg);
        dlg.addEventListener('click', function (e) { if (e.target === dlg) closeDlg(); });   // click sa labas

        <?php if ($old): ?>
        // May error sa form: buksan ulit na nakalagay pa ang mga ininput
        openDlg(<?= json_encode([
            'action'   => ($old['action'] ?? 'add') === 'edit' ? 'edit' : 'add',
            'id'       => $old['room_id'] ?? '',
            'bh'       => $old['bh_id'] ?? '',
            'number'   => $old['room_number'] ?? '',
            'type'     => $old['room_type'] ?? '',
            'capacity' => $old['capacity'] ?? '',
            'rate'     => $old['monthly_rate'] ?? '',
        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>);
        <?php endif; ?>
    }

    /* ---------- Search + filter ---------- */
    var search = document.getElementById('rmSearch');
    var chips  = document.querySelectorAll('.rm-chip');
    var rows   = document.querySelectorAll('#rmBody tr[data-status]');
    var shown  = document.getElementById('rmShown');
    var none   = document.getElementById('rmNoMatch');
    var filter = 'all';

    function apply() {
        var q = (search.value || '').trim().toLowerCase(), n = 0;
        rows.forEach(function (r) {
            var ok = (filter === 'all' || r.dataset.status === filter) && r.dataset.search.indexOf(q) !== -1;
            r.hidden = !ok;
            if (ok) n++;
        });
        shown.textContent = n;
        if (none) none.hidden = !(rows.length > 0 && n === 0);
    }
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