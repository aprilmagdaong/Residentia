<?php
session_start();
require_once __DIR__ . '/../php/index_config.php';

if (!isset($_SESSION['role']) || strtolower(trim((string)$_SESSION['role'])) !== 'owner') {
    header('Location: ../index.php');
    exit;
}

$owner_id = (int)($_SESSION['owner_id'] ?? 0);
if ($owner_id <= 0) {
    die('Owner ID is missing. Please log in again.');
}

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

const TITLE_MAX = 100;

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* Active tenants currently assigned to this owner's rooms. */
$tenantQuery = $pdo->prepare(
    'SELECT DISTINCT t.tenant_id, t.first_name, t.middle_name, t.last_name, t.email
     FROM tenant t
     INNER JOIN room_assignment ra ON ra.tenant_id = t.tenant_id
     INNER JOIN room r ON r.room_id = ra.room_id
     INNER JOIN boarding_house bh ON bh.bh_id = r.bh_id
     WHERE bh.owner_id = ? AND ra.moveout_date IS NULL
     ORDER BY t.last_name, t.first_name'
);
$tenantQuery->execute([$owner_id]);
$eligibleTenants = $tenantQuery->fetchAll(PDO::FETCH_ASSOC);
$eligibleTenantIds = array_map('intval', array_column($eligibleTenants, 'tenant_id'));

/* Rooms with currently assigned tenants. */
$roomQuery = $pdo->prepare(
    'SELECT DISTINCT r.room_id, r.room_number, ra.tenant_id
     FROM room r
     INNER JOIN boarding_house bh ON bh.bh_id = r.bh_id
     INNER JOIN room_assignment ra ON ra.room_id = r.room_id
     WHERE bh.owner_id = ? AND ra.moveout_date IS NULL
     ORDER BY r.room_number, ra.tenant_id'
);
$roomQuery->execute([$owner_id]);

$roomTenantMap = [];
$eligibleRooms = [];

foreach ($roomQuery->fetchAll(PDO::FETCH_ASSOC) as $roomRow) {
    $roomId = (int)$roomRow['room_id'];

    if (!isset($roomTenantMap[$roomId])) {
        $roomTenantMap[$roomId] = [];
        $eligibleRooms[$roomId] = [
            'room_id' => $roomId,
            'room_number' => (string)$roomRow['room_number']
        ];
    }

    $roomTenantMap[$roomId][] = (int)$roomRow['tenant_id'];
}

$eligibleRoomIds = array_map('intval', array_keys($eligibleRooms));

/* Resolve and validate the recipients for create and update actions. */
$resolveRecipients = static function (
    string $mode,
    array $post,
    array $eligibleTenantIds,
    array $eligibleRoomIds,
    array $roomTenantMap
): array {
    if ($mode === 'all') {
        $recipientIds = $eligibleTenantIds;

    } elseif ($mode === 'selected') {
        $submittedIds = $post['recipient_ids'] ?? [];

        if (!is_array($submittedIds)) {
            $submittedIds = [];
        }

        $submittedIds = array_values(array_unique(array_filter(
            array_map('intval', $submittedIds),
            static fn($id) => $id > 0
        )));

        $recipientIds = array_values(array_intersect($submittedIds, $eligibleTenantIds));

        if (count($recipientIds) !== count($submittedIds)) {
            throw new RuntimeException(
                'One or more selected tenants are no longer eligible. Please refresh and try again.'
            );
        }

    } elseif ($mode === 'room') {
        $selectedRoomId = (int)($post['recipient_room_id'] ?? 0);

        if (!in_array($selectedRoomId, $eligibleRoomIds, true)) {
            throw new RuntimeException('Please choose a room that has current tenants assigned.');
        }

        $recipientIds = array_values(array_unique($roomTenantMap[$selectedRoomId] ?? []));

    } else {
        throw new RuntimeException('Please choose who will receive this announcement.');
    }

    if (!$recipientIds) {
        throw new RuntimeException('There are no active tenants to receive this announcement.');
    }

    return $recipientIds;
};

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$error = '';
$old = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    try {
        $postedToken = (string)($_POST['csrf_token'] ?? '');
        $sessionToken = (string)($_SESSION['csrf_token'] ?? '');

        if ($postedToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $postedToken)) {
            throw new RuntimeException('Your session token is invalid. Please refresh the page and try again.');
        }

        if ($action === 'create' || $action === 'update') {
            $title = trim((string)($_POST['announcement_title'] ?? ''));
            $message = trim((string)($_POST['announcement_message'] ?? ''));
            $recipientMode = (string)($_POST['recipient_mode'] ?? 'all');

            $old = [
                'action' => $action,
                'announcement_id' => trim((string)($_POST['announcement_id'] ?? '')),
                'announcement_title' => $title,
                'announcement_message' => $message,
                'recipient_mode' => $recipientMode,
                'recipient_ids' => array_map('intval', (array)($_POST['recipient_ids'] ?? [])),
                'recipient_room_id' => (int)($_POST['recipient_room_id'] ?? 0)
            ];

            if ($title === '' || $message === '') {
                throw new RuntimeException('Please complete the announcement title and message.');
            }

            if (mb_strlen($title, 'UTF-8') > TITLE_MAX) {
                throw new RuntimeException('The title must not exceed ' . TITLE_MAX . ' characters.');
            }

            $recipientIds = $resolveRecipients(
                $recipientMode,
                $_POST,
                $eligibleTenantIds,
                $eligibleRoomIds,
                $roomTenantMap
            );

            $pdo->beginTransaction();

            if ($action === 'create') {
                $create = $pdo->prepare(
                    'INSERT INTO announcement
                        (owner_id, announcement_title, announcement_message, date_published)
                     VALUES (?, ?, ?, NOW())'
                );
                $create->execute([$owner_id, $title, $message]);

                $announcementId = (int)$pdo->lastInsertId();
                $successMessage = 'Announcement published successfully to ' . count($recipientIds) . ' tenant(s).';

            } else {
                $announcementId = filter_var(
                    $old['announcement_id'],
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1]]
                );

                if (!$announcementId) {
                    throw new RuntimeException('Invalid announcement selected.');
                }

                $check = $pdo->prepare(
                    'SELECT announcement_id
                     FROM announcement
                     WHERE announcement_id = ? AND owner_id = ?
                     LIMIT 1'
                );
                $check->execute([$announcementId, $owner_id]);

                if (!$check->fetchColumn()) {
                    throw new RuntimeException('Announcement not found or you do not have permission to edit it.');
                }

                $update = $pdo->prepare(
                    'UPDATE announcement
                     SET announcement_title = ?, announcement_message = ?
                     WHERE announcement_id = ? AND owner_id = ?'
                );
                $update->execute([$title, $message, $announcementId, $owner_id]);

                /* Replace the previous recipient list with the new selection. */
                $deleteRecipients = $pdo->prepare(
                    'DELETE FROM announcement_recipient WHERE announcement_id = ?'
                );
                $deleteRecipients->execute([$announcementId]);

                $successMessage = 'Announcement and recipients updated successfully.';
            }

            $addRecipient = $pdo->prepare(
                'INSERT INTO announcement_recipient
                    (tenant_id, announcement_id, date_received)
                 VALUES (?, ?, NOW())'
            );

            foreach ($recipientIds as $tenantId) {
                $addRecipient->execute([$tenantId, $announcementId]);
            }

            $pdo->commit();

        } elseif ($action === 'delete') {
            $announcementId = filter_var(
                $_POST['announcement_id'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );

            if (!$announcementId) {
                throw new RuntimeException('Invalid announcement selected.');
            }

            $pdo->beginTransaction();

            $check = $pdo->prepare(
                'SELECT announcement_id
                 FROM announcement
                 WHERE announcement_id = ? AND owner_id = ?
                 LIMIT 1'
            );
            $check->execute([$announcementId, $owner_id]);

            if (!$check->fetchColumn()) {
                throw new RuntimeException('Announcement not found or you do not have permission to delete it.');
            }

            $deleteRecipients = $pdo->prepare(
                'DELETE FROM announcement_recipient WHERE announcement_id = ?'
            );
            $deleteRecipients->execute([$announcementId]);

            $deleteAnnouncement = $pdo->prepare(
                'DELETE FROM announcement
                 WHERE announcement_id = ? AND owner_id = ?'
            );
            $deleteAnnouncement->execute([$announcementId, $owner_id]);

            $pdo->commit();
            $successMessage = 'Announcement deleted successfully.';

        } else {
            throw new RuntimeException('Invalid request action.');
        }

        if ($pdo->inTransaction()) {
            $pdo->commit();
        }

        $old = null;
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['flash'] = ['type' => 'success', 'msg' => $successMessage];

        header('Location: owner_announcement.php');
        exit;

    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($ex instanceof PDOException) {
            error_log('Residentia announcement error: ' . $ex->getMessage());
            $error = 'Unable to process the announcement. Please check the database configuration and try again.';
        } else {
            $error = $ex->getMessage();
        }

        if ($action === 'delete') {
            $old = null;
        } elseif ($action === 'create' || $action === 'update') {
            $old = [
                'action' => $action,
                'announcement_id' => trim((string)($_POST['announcement_id'] ?? '')),
                'announcement_title' => trim((string)($_POST['announcement_title'] ?? '')),
                'announcement_message' => trim((string)($_POST['announcement_message'] ?? '')),
                'recipient_mode' => (string)($_POST['recipient_mode'] ?? 'all'),
                'recipient_ids' => array_map('intval', (array)($_POST['recipient_ids'] ?? [])),
                'recipient_room_id' => (int)($_POST['recipient_room_id'] ?? 0)
            ];
        }
    }
}

/* Load announcements belonging to the logged-in owner. */
$list = $pdo->prepare(
    'SELECT a.announcement_id, a.announcement_title,
            a.announcement_message, a.date_published
     FROM announcement a
     WHERE a.owner_id = ?
     ORDER BY a.date_published DESC, a.announcement_id DESC'
);
$list->execute([$owner_id]);
$announcements = $list->fetchAll(PDO::FETCH_ASSOC);

/* Load each announcement's current recipients for the Edit dialog. */
$recipientListQuery = $pdo->prepare(
    'SELECT ar.announcement_id, ar.tenant_id
     FROM announcement_recipient ar
     INNER JOIN announcement a ON a.announcement_id = ar.announcement_id
     WHERE a.owner_id = ?
     ORDER BY ar.tenant_id'
);
$recipientListQuery->execute([$owner_id]);

$announcementRecipients = [];
foreach ($recipientListQuery->fetchAll(PDO::FETCH_ASSOC) as $recipient) {
    $announcementId = (int)$recipient['announcement_id'];
    $announcementRecipients[$announcementId][] = (int)$recipient['tenant_id'];
}

$curKey = date('Y-m');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Announcements | Residentia</title>
    <link rel="stylesheet" href="../css/owner_db.css?v=4">
    <link rel="stylesheet" href="../css/owner_ui.css?v=1">
    <link rel="stylesheet" href="../css/owner_announcement.css?v=6">
</head>
<body>
<div class="layout">
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
            <a href="owner_announcement.php" class="active" aria-current="page">Announcements</a>
            <a href="owner_reports.php">Reports</a>
        </nav>

        <a class="logout" href="../logout.php">Log out</a>
    </aside>

    <main class="main">
        <div class="announcement-page">
            <header class="an-header">
                <div>
                    <h1>Announcements</h1>
                    <p>Share important updates and reminders with your tenants.</p>
                </div>
                <button type="button" class="an-add" id="anAdd">
                    + Add Announcement
                </button>
            </header>

            <?php if ($flash): ?>
                <div class="an-alert <?= ($flash['type'] ?? '') === 'success' ? 'success' : 'error' ?>" role="status">
                    <?= e($flash['msg'] ?? '') ?>
                </div>
            <?php endif; ?>

            <?php if ($error && !$old): ?>
                <div class="an-alert error" role="alert"><?= e($error) ?></div>
            <?php endif; ?>

            <section class="an-card">
                <div class="an-toolbar">
                    <div class="an-heading">
                        <h2>Published Announcements</h2>
                        <p><span id="anShown"><?= count($announcements) ?></span> of <?= count($announcements) ?> announcement(s)</p>
                    </div>

                    <div class="an-controls">
                        <label class="an-search" for="anSearch">
                            <span class="sr-only">Search announcements</span>
                            <input type="search" id="anSearch" placeholder="Search announcements..." autocomplete="off">
                        </label>

                        <div class="an-chips" role="group" aria-label="Filter announcements">
                            <button type="button" class="an-chip active" data-filter="all" aria-pressed="true">All</button>
                            <button type="button" class="an-chip" data-filter="month" aria-pressed="false">This month</button>
                            <button type="button" class="an-chip" data-filter="earlier" aria-pressed="false">Earlier</button>
                        </div>
                    </div>
                </div>

                <?php if (!$announcements): ?>
                    <div class="an-empty an-empty-initial">
                        <h3>No announcements yet</h3>
                        <p>There are no announcements to display yet.</p>
                        <button type="button" class="an-empty-action" id="anAddEmpty">Add your first announcement</button>
                    </div>
                <?php else: ?>
                    <div class="an-list" id="anList">
                        <?php foreach ($announcements as $a):
                            $timestamp = strtotime((string)$a['date_published']);
                            $period = substr((string)$a['date_published'], 0, 7) === $curKey ? 'month' : 'earlier';
                            $announcementId = (int)$a['announcement_id'];
                        ?>
                            <article class="an-item" data-period="<?= e($period) ?>">
                                <div class="an-date" aria-label="<?= e(date('F j, Y', $timestamp)) ?>">
                                    <span><?= e(strtoupper(date('M', $timestamp))) ?></span>
                                    <strong><?= e(date('j', $timestamp)) ?></strong>
                                </div>

                                <div class="an-body">
                                    <h3><?= e($a['announcement_title']) ?></h3>
                                    <p class="an-msg"><?= nl2br(e($a['announcement_message'])) ?></p>
                                    <button type="button" class="an-more" hidden>Read more</button>
                                    <div class="an-meta">
                                        <span><?= e(date('M j, Y', $timestamp)) ?></span>
                                    </div>
                                </div>

                                <div class="an-actions">
                                    <button type="button" class="an-link js-edit"
                                        data-id="<?= $announcementId ?>"
                                        data-title="<?= e($a['announcement_title']) ?>"
                                        data-message="<?= e($a['announcement_message']) ?>"
                                        data-recipients="<?= e(json_encode(
                                            $announcementRecipients[$announcementId] ?? [],
                                            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
                                        )) ?>">
                                        Edit
                                    </button>

                                    <form method="POST" class="an-delete-form">
                                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="announcement_id" value="<?= $announcementId ?>">
                                        <button type="submit" class="an-link danger">Delete</button>
                                    </form>
                                </div>
                            </article>
                        <?php endforeach; ?>

                        <div class="an-empty an-no-match" id="anNoMatch" hidden>
                            <h3>No announcements found</h3>
                            <p>Try another keyword or choose a different filter.</p>
                            <button type="button" class="an-reset" id="resetSearch">Clear search and filters</button>
                        </div>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>
</div>

<dialog id="anDialog" class="an-dialog" aria-labelledby="dlgTitle">
    <form method="POST" class="an-form" id="anForm">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" id="fAction" value="create">
        <input type="hidden" name="announcement_id" id="fId" value="">

        <div class="an-dlg-head">
            <div>
                <h2 id="dlgTitle">Add Announcement</h2>
                <p id="dlgSub">Create an update and choose who will receive it.</p>
            </div>
            <button type="button" class="an-x" id="dlgClose" aria-label="Close dialog">&times;</button>
        </div>

        <?php if ($error && $old): ?>
            <div class="an-alert error" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <div class="an-form-grid">
            <div class="an-content-fields">
                <div class="an-field">
                    <label for="fTitle">Announcement title <span class="required">*</span></label>
                    <input type="text" name="announcement_title" id="fTitle"
                        maxlength="<?= TITLE_MAX ?>" placeholder="e.g. Scheduled water interruption" required>
                    <span class="an-count" id="titleCount">0 / <?= TITLE_MAX ?></span>
                </div>

                <div class="an-field">
                    <label for="fMessage">Message <span class="required">*</span></label>
                    <textarea name="announcement_message" id="fMessage" rows="8"
                        placeholder="Write the details of your announcement here..." required></textarea>
                    <small class="an-hint">Write a clear message and include important dates or instructions when needed.</small>
                </div>
            </div>

            <section class="an-recipient-section" id="recipientSection">
                <div class="an-recipient-heading">
                    <label>Who should receive this announcement? <span class="required">*</span></label>
                    <small>Choose all current tenants, specific tenants, or everyone assigned to one room.</small>
                </div>

                <label class="an-radio-option">
                    <input type="radio" name="recipient_mode" value="all" checked>
                    <span>
                        <strong>All Active Tenants</strong>
                        <small>Send to every tenant currently assigned to one of your rooms.</small>
                    </span>
                </label>

                <label class="an-radio-option">
                    <input type="radio" name="recipient_mode" value="selected">
                    <span>
                        <strong>Selected Tenants</strong>
                        <small>Choose only the tenants you want to notify.</small>
                    </span>
                </label>

                <div class="an-tenant-picker" id="tenantPicker" hidden>
                    <div class="an-tenant-picker-top">
                        <strong>Select recipients</strong>
                        <span id="recipientCount">0 selected</span>
                    </div>
                    <input type="search" id="tenantSearch" placeholder="Search tenant name or email..." autocomplete="off">
                    <div class="an-tenant-list">
                        <?php foreach ($eligibleTenants as $tenant):
                            $fullName = trim(implode(' ', array_filter([
                                $tenant['first_name'] ?? '',
                                $tenant['middle_name'] ?? '',
                                $tenant['last_name'] ?? ''
                            ])));
                        ?>
                            <label class="an-tenant-option"
                                data-search="<?= e(strtolower($fullName . ' ' . ($tenant['email'] ?? ''))) ?>">
                                <input type="checkbox" name="recipient_ids[]" value="<?= (int)$tenant['tenant_id'] ?>">
                                <span>
                                    <strong><?= e($fullName) ?></strong>
                                    <small><?= e($tenant['email'] ?? 'No email address') ?></small>
                                </span>
                            </label>
                        <?php endforeach; ?>

                        <?php if (!$eligibleTenants): ?>
                            <p class="an-no-tenants">No active tenants are currently assigned to your rooms.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <label class="an-radio-option">
                    <input type="radio" name="recipient_mode" value="room">
                    <span>
                        <strong>By Room</strong>
                        <small>Send to all current tenants assigned to a specific room.</small>
                    </span>
                </label>

                <div class="an-room-picker" id="roomPicker" hidden>
                    <label for="recipientRoom">Choose room</label>
                    <select name="recipient_room_id" id="recipientRoom">
                        <option value="">Select a room</option>
                        <?php foreach ($eligibleRooms as $room): ?>
                            <?php $roomCount = count($roomTenantMap[(int)$room['room_id']]); ?>
                            <option value="<?= (int)$room['room_id'] ?>">
                                Room <?= e($room['room_number']) ?> (<?= $roomCount ?> tenant<?= $roomCount === 1 ? '' : 's' ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <?php if (!$eligibleRooms): ?>
                        <small class="an-no-tenants">No rooms currently have active tenants assigned.</small>
                    <?php else: ?>
                        <small class="an-hint">Only current tenants assigned to the selected room will receive this announcement.</small>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <div class="an-dlg-foot">
            <button type="button" class="an-btn ghost" id="dlgCancel">Cancel</button>
            <button type="submit" class="an-btn" id="dlgSubmit">Publish announcement</button>
        </div>
    </form>
</dialog>

<script>
(function () {
    'use strict';

    const dialog = document.getElementById('anDialog');
    const form = document.getElementById('anForm');
    const titleInput = document.getElementById('fTitle');
    const messageInput = document.getElementById('fMessage');
    const titleCount = document.getElementById('titleCount');
    const searchInput = document.getElementById('anSearch');
    const shownCount = document.getElementById('anShown');
    const noMatch = document.getElementById('anNoMatch');
    const chips = Array.from(document.querySelectorAll('.an-chip'));
    const items = Array.from(document.querySelectorAll('.an-item'));
    const maxTitle = <?= TITLE_MAX ?>;

    const recipientSection = document.getElementById('recipientSection');
    const tenantPicker = document.getElementById('tenantPicker');
    const roomPicker = document.getElementById('roomPicker');
    const recipientRoom = document.getElementById('recipientRoom');
    const recipientCount = document.getElementById('recipientCount');
    const tenantSearch = document.getElementById('tenantSearch');

    function updateTitleCount() {
        titleCount.textContent = titleInput.value.length + ' / ' + maxTitle;
    }

    function updateRecipientCount() {
        const count = document.querySelectorAll('input[name="recipient_ids[]"]:checked').length;
        recipientCount.textContent = count + ' selected';
    }

    function updateRecipientMode() {
        const selectedMode = document.querySelector('input[name="recipient_mode"]:checked')?.value || 'all';
        tenantPicker.hidden = selectedMode !== 'selected';
        roomPicker.hidden = selectedMode !== 'room';
        updateRecipientCount();
    }

    function openDialog(data) {
        form.reset();

        document.querySelectorAll('input[name="recipient_ids[]"]').forEach(box => {
            box.checked = false;
        });

        if (recipientRoom) recipientRoom.value = '';
        if (tenantSearch) tenantSearch.value = '';

        document.querySelectorAll('.an-tenant-option').forEach(option => {
            option.hidden = false;
        });

        const isCreate = data.action === 'create';

        document.getElementById('fAction').value = isCreate ? 'create' : 'update';
        document.getElementById('fId').value = data.id || '';

        titleInput.value = data.title || '';
        messageInput.value = data.message || '';

        document.getElementById('dlgTitle').textContent =
            isCreate ? 'Add Announcement' : 'Edit Announcement';

        document.getElementById('dlgSub').textContent =
            isCreate
                ? 'Create an update and choose who will receive it.'
                : 'Update the announcement details and recipients.';

        document.getElementById('dlgSubmit').textContent =
            isCreate ? 'Publish announcement' : 'Save changes';

        recipientSection.hidden = false;

        /* Default edit mode to selected so the current recipient list is preserved. */
        const mode = data.recipientMode || (isCreate ? 'all' : 'selected');
        const modeRadio = document.querySelector(
            'input[name="recipient_mode"][value="' + mode + '"]'
        );

        if (modeRadio) modeRadio.checked = true;

        (data.recipientIds || []).forEach(id => {
            const checkbox = document.querySelector(
                'input[name="recipient_ids[]"][value="' + String(id) + '"]'
            );
            if (checkbox) checkbox.checked = true;
        });

        if (recipientRoom && data.recipientRoomId) {
            recipientRoom.value = String(data.recipientRoomId);
        }

        updateRecipientMode();
        updateTitleCount();

        if (!dialog.open) dialog.showModal();
        window.setTimeout(() => titleInput.focus(), 0);
    }

    function closeDialog() {
        if (dialog.open) dialog.close();
    }

    function openCreateDialog() {
        openDialog({
            action: 'create',
            id: '',
            title: '',
            message: '',
            recipientMode: 'all',
            recipientIds: []
        });
    }

    document.getElementById('anAdd')?.addEventListener('click', openCreateDialog);
    document.getElementById('anAddEmpty')?.addEventListener('click', openCreateDialog);

    document.querySelectorAll('input[name="recipient_mode"]').forEach(radio => {
        radio.addEventListener('change', updateRecipientMode);
    });

    document.querySelectorAll('input[name="recipient_ids[]"]').forEach(box => {
        box.addEventListener('change', updateRecipientCount);
    });

    tenantSearch?.addEventListener('input', () => {
        const keyword = tenantSearch.value.trim().toLocaleLowerCase();

        document.querySelectorAll('.an-tenant-option').forEach(option => {
            option.hidden = !option.dataset.search.includes(keyword);
        });
    });

    form.addEventListener('submit', event => {
        const mode = document.querySelector('input[name="recipient_mode"]:checked')?.value || 'all';
        const selected = document.querySelectorAll('input[name="recipient_ids[]"]:checked').length;

        if (mode === 'selected' && selected === 0) {
            event.preventDefault();
            alert('Please select at least one tenant to receive this announcement.');
            return;
        }

        if (mode === 'room' && !recipientRoom.value) {
            event.preventDefault();
            alert('Please choose a room for this announcement.');
        }
    });

    document.querySelectorAll('.js-edit').forEach(button => {
        button.addEventListener('click', () => {
            let recipientIds = [];

            try {
                recipientIds = JSON.parse(button.dataset.recipients || '[]');
            } catch (error) {
                recipientIds = [];
            }

            openDialog({
                action: 'update',
                id: button.dataset.id,
                title: button.dataset.title,
                message: button.dataset.message,
                recipientMode: 'selected',
                recipientIds: recipientIds
            });
        });
    });

    document.getElementById('dlgClose').addEventListener('click', closeDialog);
    document.getElementById('dlgCancel').addEventListener('click', closeDialog);

    dialog.addEventListener('click', event => {
        if (event.target === dialog) closeDialog();
    });

    titleInput.addEventListener('input', updateTitleCount);

    document.querySelectorAll('.an-delete-form').forEach(deleteForm => {
        deleteForm.addEventListener('submit', event => {
            if (!window.confirm('Delete this announcement?')) {
                event.preventDefault();
            }
        });
    });

    function setupReadMore() {
        document.querySelectorAll('.an-item').forEach(item => {
            const message = item.querySelector('.an-msg');
            const button = item.querySelector('.an-more');

            if (!message || !button) return;

            if (message.scrollHeight > message.clientHeight + 2) {
                button.hidden = false;
            }

            button.addEventListener('click', () => {
                const expanded = message.classList.toggle('open');
                button.textContent = expanded ? 'Show less' : 'Read more';
                button.setAttribute('aria-expanded', String(expanded));
            });
        });
    }

    let activeFilter = 'all';

    function applyFilters() {
        const keyword = (searchInput?.value || '').trim().toLocaleLowerCase();
        let visible = 0;

        items.forEach(item => {
            const text = (
                (item.querySelector('.an-body h3')?.textContent || '') + ' ' +
                (item.querySelector('.an-msg')?.textContent || '')
            ).toLocaleLowerCase();

            const matchesPeriod = activeFilter === 'all' || item.dataset.period === activeFilter;
            const matchesText = keyword === '' || text.includes(keyword);
            const show = matchesPeriod && matchesText;

            item.hidden = !show;
            if (show) visible++;
        });

        if (shownCount) shownCount.textContent = visible;
        if (noMatch) noMatch.hidden = !(items.length > 0 && visible === 0);
    }

    searchInput?.addEventListener('input', applyFilters);

    chips.forEach(chip => {
        chip.addEventListener('click', () => {
            activeFilter = chip.dataset.filter || 'all';

            chips.forEach(button => {
                const selected = button === chip;
                button.classList.toggle('active', selected);
                button.setAttribute('aria-pressed', String(selected));
            });

            applyFilters();
        });
    });

    document.getElementById('resetSearch')?.addEventListener('click', () => {
        if (searchInput) searchInput.value = '';
        activeFilter = 'all';

        chips.forEach(button => {
            const selected = button.dataset.filter === 'all';
            button.classList.toggle('active', selected);
            button.setAttribute('aria-pressed', String(selected));
        });

        applyFilters();
    });

    setupReadMore();

    <?php if ($old): ?>
    openDialog(<?= json_encode([
        'action' => $old['action'] ?? 'create',
        'id' => $old['announcement_id'] ?? '',
        'title' => $old['announcement_title'] ?? '',
        'message' => $old['announcement_message'] ?? '',
        'recipientMode' => $old['recipient_mode'] ?? 'all',
        'recipientIds' => $old['recipient_ids'] ?? [],
        'recipientRoomId' => $old['recipient_room_id'] ?? 0
    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>);
    <?php endif; ?>
})();
</script>
</body>
</html>
