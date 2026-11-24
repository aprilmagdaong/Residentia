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

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

const TITLE_MAX = 100;     // announcement_title ay varchar(100)

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$error = '';
$old   = null;     // para ma-reopen ang form kapag may error

/* =========================================
   ADD / UPDATE / DELETE
========================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            throw new Exception('Invalid request. Please refresh the page.');
        }

        /* ---------- ADD ---------- */
        if ($action === 'add') {
            $old = $_POST;
            $title   = trim($_POST['announcement_title'] ?? '');
            $message = trim($_POST['announcement_message'] ?? '');

            if ($title === '' || $message === '') { throw new Exception('Please complete the announcement title and message.'); }
            if (mb_strlen($title) > TITLE_MAX)     { throw new Exception('Title must be ' . TITLE_MAX . ' characters or less.'); }

            $pdo->beginTransaction();

            $pdo->prepare("INSERT INTO announcement (owner_id, announcement_title, announcement_message, date_published)
                           VALUES (?, ?, ?, CURDATE())")
                ->execute([$owner_id, $title, $message]);
            $announcement_id = (int)$pdo->lastInsertId();

            // Mga tenant na kasalukuyang nakatira sa rooms ng owner na ito
            $stmt = $pdo->prepare("
                SELECT DISTINCT ra.tenant_id
                FROM room_assignment ra
                INNER JOIN room r ON r.room_id = ra.room_id
                INNER JOIN boarding_house bh ON bh.bh_id = r.bh_id
                WHERE bh.owner_id = ? AND ra.moveout_date IS NULL
            ");
            $stmt->execute([$owner_id]);
            $tenantIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if ($tenantIds) {
                $rec = $pdo->prepare("INSERT INTO announcement_recipient (tenant_id, announcement_id, date_received)
                                      VALUES (?, ?, CURDATE())");
                foreach ($tenantIds as $tid) { $rec->execute([(int)$tid, $announcement_id]); }
            }

            $pdo->commit();
            $msg = 'Announcement published to ' . count($tenantIds) . ' tenant(s)!';

        /* ---------- UPDATE ---------- */
        } elseif ($action === 'update') {
            $old = $_POST;
            $announcement_id = filter_input(INPUT_POST, 'announcement_id', FILTER_VALIDATE_INT);
            $title   = trim($_POST['announcement_title'] ?? '');
            $message = trim($_POST['announcement_message'] ?? '');

            if (!$announcement_id || $title === '' || $message === '') { throw new Exception('Please complete all required fields.'); }
            if (mb_strlen($title) > TITLE_MAX) { throw new Exception('Title must be ' . TITLE_MAX . ' characters or less.'); }

            $q = $pdo->prepare("SELECT announcement_id FROM announcement WHERE announcement_id = ? AND owner_id = ?");
            $q->execute([$announcement_id, $owner_id]);
            if (!$q->fetch()) { throw new Exception('Announcement not found.'); }

            $pdo->prepare("UPDATE announcement SET announcement_title = ?, announcement_message = ?
                           WHERE announcement_id = ? AND owner_id = ?")
                ->execute([$title, $message, $announcement_id, $owner_id]);
            $msg = 'Announcement updated!';

        /* ---------- DELETE ---------- */
        } elseif ($action === 'delete') {
            $announcement_id = filter_input(INPUT_POST, 'announcement_id', FILTER_VALIDATE_INT);
            if (!$announcement_id) { throw new Exception('Invalid announcement selected.'); }

            $pdo->beginTransaction();
            $q = $pdo->prepare("SELECT announcement_id FROM announcement WHERE announcement_id = ? AND owner_id = ?");
            $q->execute([$announcement_id, $owner_id]);
            if (!$q->fetch()) { throw new Exception('Announcement not found.'); }

            $pdo->prepare("DELETE FROM announcement_recipient WHERE announcement_id = ?")->execute([$announcement_id]);
            $pdo->prepare("DELETE FROM announcement WHERE announcement_id = ? AND owner_id = ?")->execute([$announcement_id, $owner_id]);
            $pdo->commit();
            $msg = 'Announcement deleted.';

        } else {
            throw new Exception('Invalid action.');
        }

        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['flash'] = ['type' => 'success', 'msg' => $msg];
        header('Location: owner_announcement.php');      // iwas double-submit kapag nag-refresh
        exit;

    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log($ex->getMessage());
        $error = 'Unable to save. Please check the database.';
    } catch (Exception $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $error = $ex->getMessage();
    }
    if ($action === 'delete') { $old = null; }
}

/* =========================================
   LIST
========================================= */
$stmt = $pdo->prepare("
    SELECT a.announcement_id, a.announcement_title, a.announcement_message, a.date_published,
           COUNT(ar.recipient_id) AS recipient_count
    FROM announcement a
    LEFT JOIN announcement_recipient ar ON ar.announcement_id = a.announcement_id
    WHERE a.owner_id = ?
    GROUP BY a.announcement_id, a.announcement_title, a.announcement_message, a.date_published
    ORDER BY a.date_published DESC, a.announcement_id DESC
");
$stmt->execute([$owner_id]);
$announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);

$thisMonth = 0;
$curKey = date('Y-m');
foreach ($announcements as $a) {
    if (substr($a['date_published'], 0, 7) === $curKey) { $thisMonth++; }
}
$latest = $announcements[0] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Announcements | Residentia</title>

    <link rel="stylesheet" href="../css/owner_db.css?v=4">
    <link rel="stylesheet" href="../css/owner_announcement.css?v=2">
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
            <a href="owner_rooms.php">Rooms</a>
            <a href="owner_tenants.php">Tenants</a>
            <a href="owner_billing.php">Billing &amp; Payments</a>
            <a href="owner_maintenance.php">Maintenance</a>
            <a href="owner_announcement.php" class="active">Announcements</a>
            <a href="owner_reports.php">Reports</a>
        </nav>

        <a class="logout" href="../logout.php">Log out</a>
    </aside>

    <!-- MAIN -->
    <main class="main">
    <div class="announcement-page">

        <header class="an-header">
            <div>
                <h1>Announcements</h1>
                <p>Share important updates and reminders with your tenants.</p>
            </div>
            <button type="button" class="an-add" id="openNew">+ New Announcement</button>
        </header>

        <?php if ($flash): ?><div class="an-alert <?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>
        <?php if ($error && !$old): ?><div class="an-alert error" role="alert"><?= e($error) ?></div><?php endif; ?>

        <!-- SUMMARY -->
        <section class="an-stats">
            <article class="an-stat">
                <span class="an-stat-label">Total Announcements</span>
                <strong><?= count($announcements) ?></strong>
                <small>Posted so far</small>
            </article>
            <article class="an-stat month">
                <span class="an-stat-label">Posted This Month</span>
                <strong><?= $thisMonth ?></strong>
                <small><?= date('F Y') ?></small>
            </article>
            <article class="an-stat last">
                <span class="an-stat-label">Last Published</span>
                <strong class="an-stat-date"><?= $latest ? e(date('M j, Y', strtotime($latest['date_published']))) : '—' ?></strong>
                <small class="an-trunc"><?= $latest ? e($latest['announcement_title']) : 'Nothing posted yet' ?></small>
            </article>
        </section>

        <!-- LIST -->
        <section class="an-card">
            <div class="an-toolbar">
                <div class="an-heading">
                    <h2>Published Announcements</h2>
                    <p><span id="anShown"><?= count($announcements) ?></span> announcement(s) shown</p>
                </div>
                <div class="an-controls">
                    <div class="an-search">
                        <input type="search" id="anSearch" placeholder="Search title or message" aria-label="Search announcements">
                    </div>
                    <div class="an-chips" role="group" aria-label="Filter announcements">
                        <button type="button" class="an-chip active" data-filter="all">All</button>
                        <button type="button" class="an-chip" data-filter="month">This month</button>
                        <button type="button" class="an-chip" data-filter="earlier">Earlier</button>
                    </div>
                </div>
            </div>

            <?php if (!$announcements): ?>
                <div class="an-empty">
                    <h3>No announcements yet</h3>
                    <p>Click “New Announcement” to post your first update.</p>
                </div>
            <?php else: ?>
            <div class="an-list" id="anList">
                <?php foreach ($announcements as $a):
                    $ts  = strtotime($a['date_published']);
                    $per = substr($a['date_published'], 0, 7) === $curKey ? 'month' : 'earlier';
                    $n   = (int)$a['recipient_count'];
                ?>
                <article class="an-item" data-period="<?= $per ?>"
                         data-search="<?= e(mb_strtolower($a['announcement_title'] . ' ' . $a['announcement_message'])) ?>">
                    <div class="an-date" aria-hidden="true">
                        <span><?= strtoupper(date('M', $ts)) ?></span>
                        <strong><?= date('j', $ts) ?></strong>
                    </div>

                    <div class="an-body">
                        <h3><?= e($a['announcement_title']) ?></h3>
                        <p class="an-msg"><?= nl2br(e($a['announcement_message'])) ?></p>
                        <button type="button" class="an-more" hidden>Read more</button>
                        <div class="an-meta">
                            <?= date('M j, Y', $ts) ?> ·
                            <?= $n > 0 ? 'Sent to ' . $n . ' tenant' . ($n === 1 ? '' : 's') : 'No recipients' ?>
                        </div>
                    </div>

                    <div class="an-actions">
                        <button type="button" class="an-link js-edit"
                                data-id="<?= (int)$a['announcement_id'] ?>"
                                data-title="<?= e($a['announcement_title']) ?>"
                                data-message="<?= e($a['announcement_message']) ?>">Edit</button>

                        <form method="POST" onsubmit="return confirm('Delete this announcement? Tenants will no longer see it.');">
                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="announcement_id" value="<?= (int)$a['announcement_id'] ?>">
                            <button type="submit" class="an-link danger">Delete</button>
                        </form>
                    </div>
                </article>
                <?php endforeach; ?>
                <div class="an-empty" id="anNoMatch" hidden>
                    <h3>No matches</h3>
                    <p>Try a different search or filter.</p>
                </div>
            </div>
            <?php endif; ?>
        </section>

    </div>
    </main>
</div>

<!-- NEW / EDIT DIALOG -->
<dialog id="anDialog" class="an-dialog" aria-labelledby="dlgTitle">
    <form method="POST" class="an-form" id="anForm">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" id="fAction" value="add">
        <input type="hidden" name="announcement_id" id="fId" value="">

        <div class="an-dlg-head">
            <div>
                <h2 id="dlgTitle">New Announcement</h2>
                <p id="dlgSub">It will be sent to all tenants currently staying in your rooms.</p>
            </div>
            <button type="button" class="an-x" id="dlgClose" aria-label="Close">&times;</button>
        </div>

        <?php if ($error && $old): ?><div class="an-alert error" role="alert"><?= e($error) ?></div><?php endif; ?>

        <div class="an-field">
            <label for="fTitle">Title</label>
            <input type="text" name="announcement_title" id="fTitle" maxlength="<?= TITLE_MAX ?>"
                   placeholder="e.g. Scheduled water interruption" required>
            <span class="an-count" id="titleCount">0 / <?= TITLE_MAX ?></span>
        </div>

        <div class="an-field">
            <label for="fMessage">Message</label>
            <textarea name="announcement_message" id="fMessage" rows="6"
                      placeholder="Write your announcement here..." required></textarea>
        </div>

        <div class="an-dlg-foot">
            <button type="button" class="an-btn ghost" id="dlgCancel">Cancel</button>
            <button type="submit" class="an-btn" id="dlgSubmit">Publish</button>
        </div>
    </form>
</dialog>

<script>
(function () {
    var dlg = document.getElementById('anDialog');
    var $ = function (id) { return document.getElementById(id); };
    var MAX = <?= TITLE_MAX ?>;

    function updateCount() { $('titleCount').textContent = $('fTitle').value.length + ' / ' + MAX; }
    $('fTitle').addEventListener('input', updateCount);

    function openDlg(d) {
        $('fAction').value  = d.action;
        $('fId').value      = d.id || '';
        $('fTitle').value   = d.title || '';
        $('fMessage').value = d.message || '';
        $('dlgTitle').textContent  = d.action === 'update' ? 'Edit Announcement' : 'New Announcement';
        $('dlgSub').textContent    = d.action === 'update'
            ? 'Changes are visible to the tenants who already received it.'
            : 'It will be sent to all tenants currently staying in your rooms.';
        $('dlgSubmit').textContent = d.action === 'update' ? 'Save Changes' : 'Publish';
        updateCount();
        dlg.showModal();
        $('fTitle').focus();
    }
    function closeDlg() { dlg.close(); }

    $('openNew').addEventListener('click', function () { openDlg({ action: 'add' }); });
    if (new URLSearchParams(window.location.search).get('action') === 'new') {
        openDlg({ action: 'add' });
    }
    document.querySelectorAll('.js-edit').forEach(function (b) {
        b.addEventListener('click', function () {
            openDlg({ action: 'update', id: b.dataset.id, title: b.dataset.title, message: b.dataset.message });
        });
    });
    $('dlgClose').addEventListener('click', closeDlg);
    $('dlgCancel').addEventListener('click', closeDlg);
    dlg.addEventListener('click', function (e) { if (e.target === dlg) closeDlg(); });

    <?php if ($old): ?>
    openDlg(<?= json_encode([
        'action'  => ($old['action'] ?? 'add') === 'update' ? 'update' : 'add',
        'id'      => $old['announcement_id'] ?? '',
        'title'   => $old['announcement_title'] ?? '',
        'message' => $old['announcement_message'] ?? '',
    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>);
    <?php endif; ?>

    /* ---- "Read more" para sa mahahabang mensahe ---- */
    document.querySelectorAll('.an-item').forEach(function (item) {
        var msg = item.querySelector('.an-msg'), btn = item.querySelector('.an-more');
        if (msg.scrollHeight > msg.clientHeight + 2) { btn.hidden = false; }
        btn.addEventListener('click', function () {
            var open = msg.classList.toggle('open');
            btn.textContent = open ? 'Show less' : 'Read more';
        });
    });

    /* ---- Search + filter ---- */
    var search = $('anSearch'), chips = document.querySelectorAll('.an-chip');
    var items = document.querySelectorAll('.an-item'), shown = $('anShown'), none = $('anNoMatch');
    var filter = 'all';
    function apply() {
        var q = (search.value || '').trim().toLowerCase(), n = 0;
        items.forEach(function (it) {
            var ok = (filter === 'all' || it.dataset.period === filter) && it.dataset.search.indexOf(q) !== -1;
            it.hidden = !ok;
            if (ok) n++;
        });
        shown.textContent = n;
        if (none) none.hidden = !(items.length > 0 && n === 0);
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