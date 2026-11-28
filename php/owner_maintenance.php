<?php
date_default_timezone_set('Asia/Manila');
session_start();

require_once __DIR__ . '/../php/index_config.php';

if (
    !isset($_SESSION['role']) ||
    strtolower(trim($_SESSION['role'])) !== 'owner'
) {
    header('Location: ../index.php');
    exit;
}

$owner_id = (int)($_SESSION['owner_id'] ?? 0);

if ($owner_id <= 0) {
    die('Owner ID is missing. Please log in again.');
}

$pdo = db();

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (
            !isset($_POST['csrf_token']) ||
            !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
        ) {
            throw new Exception('Invalid request. Please refresh the page.');
        }

        $requestId = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
        $status = trim($_POST['status'] ?? '');
        $allowedStatuses = ['Pending', 'In Progress', 'Resolved', 'Cancelled'];

        if (!$requestId) {
            throw new Exception('Invalid maintenance request.');
        }

        if (!in_array($status, $allowedStatuses, true)) {
            throw new Exception('Please select a valid status.');
        }

        // Confirm that this maintenance request belongs to one of the owner's
        // currently assigned tenants before allowing an update.
        $stmt = $pdo->prepare("
            SELECT mr.request_id
            FROM maintenance_request mr
            INNER JOIN tenant t ON t.tenant_id = mr.tenant_id
            INNER JOIN room_assignment ra ON ra.tenant_id = t.tenant_id
            INNER JOIN room r ON r.room_id = ra.room_id
            INNER JOIN boarding_house bh ON bh.bh_id = r.bh_id
            WHERE mr.request_id = ?
              AND bh.owner_id = ?
              AND ra.moveout_date IS NULL
            LIMIT 1
        ");
        $stmt->execute([$requestId, $owner_id]);

        if (!$stmt->fetch()) {
            throw new Exception('Maintenance request not found.');
        }

        // Awtomatikong itakda ang completion_date kapag 'Resolved', at i-clear sa NULL kung hindi
        $completionDate = ($status === 'Resolved') ? date('Y-m-d') : null;

        $stmt = $pdo->prepare("
            UPDATE maintenance_request
            SET status = ?,
                completion_date = ?
            WHERE request_id = ?
        ");
        $stmt->execute([$status, $completionDate, $requestId]);

        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $success = 'Maintenance request status updated successfully.';
    } catch (PDOException $ex) {
        $error = 'A database error occurred while updating the request.';
    } catch (Exception $ex) {
        $error = $ex->getMessage();
    }
}

$stmt = $pdo->prepare("
    SELECT
        mr.request_id,
        mr.request_description,
        mr.status,
        mr.completion_date,
        t.first_name,
        t.middle_name,
        t.last_name,
        r.room_number
    FROM maintenance_request mr
    INNER JOIN tenant t ON t.tenant_id = mr.tenant_id
    INNER JOIN room_assignment ra ON ra.tenant_id = t.tenant_id
    INNER JOIN room r ON r.room_id = ra.room_id
    INNER JOIN boarding_house bh ON bh.bh_id = r.bh_id
    WHERE bh.owner_id = ?
      AND ra.moveout_date IS NULL
    ORDER BY mr.request_id DESC
");
$stmt->execute([$owner_id]);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

$counts = [
    'All' => count($requests),
    'Pending' => 0,
    'In Progress' => 0,
    'Resolved' => 0,
    'Cancelled' => 0
];

foreach ($requests as $request) {
    if (isset($counts[$request['status']])) {
        $counts[$request['status']]++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maintenance | Residentia</title>
    <link rel="stylesheet" href="../css/owner_db.css?v=4">
    <link rel="stylesheet" href="../css/owner_maintenance.css?v=5">
    <link rel="stylesheet" href="../css/owner_ui.css?v=11">
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <picture>
            <img class="logo" src="../image/db_logo.png" alt="Residentia Logo">
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

    <main class="main">
        <div class="maintenance-page">
            <header class="maintenance-header page-header-banner">
                <div>
                    <h1>Maintenance Requests</h1>
                    <p>Review tenant repair requests and update their progress.</p>
                </div>
            </header>

            <section class="maintenance-list">
                <div class="maintenance-section-heading">
                    <div>
                        <h2>Request List</h2>
                        <p>All maintenance requests from your tenants.</p>
                    </div>
                    <div class="maintenance-filter" aria-label="Filter maintenance requests">
                        <button type="button" class="maintenance-filter-button active" data-filter="All">
                            All
                        </button>
                        <button type="button" class="maintenance-filter-button" data-filter="Pending">
                            Pending
                        </button>
                        <button type="button" class="maintenance-filter-button" data-filter="In Progress">
                            In Progress
                        </button>
                        <button type="button" class="maintenance-filter-button" data-filter="Resolved">
                            Resolved
                        </button>
                        <button type="button" class="maintenance-filter-button" data-filter="Cancelled">
                            Cancelled
                        </button>
                    </div>
                </div>

                <?php if (empty($requests)): ?>
                    <div class="maintenance-empty">
                        <h3>No maintenance requests</h3>
                        <p>Tenant maintenance requests will appear here.</p>
                    </div>
                <?php else: ?>
                    <div class="maintenance-table-wrapper">
                        <table class="maintenance-table">
                            <thead>
                                <tr>
                                    <th class="tenant-column">Tenant</th>
                                    <th class="room-column">Room</th>
                                    <th class="request-column">Request</th>
                                    <th class="status-column">Status</th>
                                    <th class="view-column">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($requests as $request): ?>
                                <?php
                                $tenantName = trim(
                                    $request['first_name'] . ' ' .
                                    ($request['middle_name'] ? $request['middle_name'] . ' ' : '') .
                                    $request['last_name']
                                );
                                $statusClass = strtolower(str_replace(' ', '-', $request['status']));
                                ?>
                                <tr data-status="<?= e($request['status']) ?>">
                                    <td class="tenant-cell">
                                        <strong><?= e($tenantName) ?></strong>
                                    </td>
                                    <td><?= e($request['room_number']) ?></td>
                                    <td class="maintenance-description">
                                        <?= nl2br(e($request['request_description'])) ?>
                                    </td>
                                    <td>
                                        <span class="maintenance-status <?= e($statusClass) ?>">
                                            <?= e($request['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button
                                            type="button"
                                            class="maintenance-view-button"
                                            data-request-id="<?= (int)$request['request_id'] ?>"
                                            data-tenant="<?= e($tenantName) ?>"
                                            data-room="<?= e($request['room_number']) ?>"
                                            data-description="<?= e($request['request_description']) ?>"
                                            data-status="<?= e($request['status']) ?>"
                                        >
                                            View
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>
</div>

<!-- Custom Success Message -->
<?php if ($success): ?>
<div class="maintenance-success-modal show" id="maintenanceSuccessModal" role="dialog" aria-modal="true" aria-labelledby="successTitle">
    <div class="maintenance-success-backdrop"></div>
    <div class="maintenance-success-box">
        <h2 id="successTitle">Success!</h2>
        <p><?= e($success) ?></p>
        <button type="button" id="closeSuccessModal">OK</button>
    </div>
</div>
<?php endif; ?>

<!-- Maintenance Request Details Modal -->
<div class="maintenance-modal" id="maintenanceModal" aria-hidden="true">
    <div class="maintenance-modal-backdrop" data-close-modal></div>

    <div class="maintenance-modal-content" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
        <div class="maintenance-modal-header">
            <div>
                <h2 id="modalTitle">Maintenance Request</h2>
                <p>Review the request details and update its status.</p>
            </div>
            <button type="button" class="maintenance-modal-close" id="closeMaintenanceModal" aria-label="Close">
                &times;
            </button>
        </div>

        <div class="maintenance-modal-body">
            <div class="maintenance-detail-grid">
                <div class="maintenance-detail-item">
                    <span>Tenant</span>
                    <strong id="modalTenant"></strong>
                </div>
                <div class="maintenance-detail-item">
                    <span>Room</span>
                    <strong id="modalRoom"></strong>
                </div>
            </div>

            <div class="maintenance-detail-item request-detail">
                <span>Request Description</span>
                <p id="modalDescription"></p>
            </div>

            <form method="POST" class="maintenance-modal-form">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="request_id" id="modalRequestId">

                <label for="modalStatus">Update Status</label>
                <select name="status" id="modalStatus" required>
                    <option value="Pending">Pending</option>
                    <option value="In Progress">In Progress</option>
                    <option value="Resolved">Resolved</option>
                    <option value="Cancelled">Cancelled</option>
                </select>

                <div class="maintenance-modal-actions">
                    <button type="button" class="maintenance-cancel-button" data-close-modal>
                        Cancel
                    </button>
                    <button type="submit" class="maintenance-save-button">
                        Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const filterButtons = document.querySelectorAll('.maintenance-filter-button');
    const requestRows = document.querySelectorAll('.maintenance-table tbody tr');
    function applyMaintenanceFilter(filter) {
        requestRows.forEach(function (row) {
            const matches = filter === 'All' || row.dataset.status === filter;
            row.hidden = !matches;
        });
    }

    filterButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            filterButtons.forEach(function (item) {
                item.classList.remove('active');
            });
            button.classList.add('active');
            applyMaintenanceFilter(button.dataset.filter);
        });
    });

    const successModal = document.getElementById('maintenanceSuccessModal');
    const successCloseButton = document.getElementById('closeSuccessModal');

    if (successModal && successCloseButton) {
        successCloseButton.addEventListener('click', function () {
            successModal.classList.remove('show');
            successModal.setAttribute('aria-hidden', 'true');
        });
    }

    const modal = document.getElementById('maintenanceModal');
    const closeButton = document.getElementById('closeMaintenanceModal');
    const viewButtons = document.querySelectorAll('.maintenance-view-button');

    const modalTenant = document.getElementById('modalTenant');
    const modalRoom = document.getElementById('modalRoom');
    const modalDescription = document.getElementById('modalDescription');
    const modalRequestId = document.getElementById('modalRequestId');
    const modalStatus = document.getElementById('modalStatus');

    function openModal(button) {
        modalRequestId.value = button.dataset.requestId;
        modalTenant.textContent = button.dataset.tenant;
        modalRoom.textContent = button.dataset.room;
        modalDescription.textContent = button.dataset.description;
        modalStatus.value = button.dataset.status;

        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('maintenance-modal-open');
    }

    function closeModal() {
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('maintenance-modal-open');
    }

    viewButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            openModal(button);
        });
    });

    closeButton.addEventListener('click', closeModal);

    modal.querySelectorAll('[data-close-modal]').forEach(function (element) {
        element.addEventListener('click', closeModal);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal.classList.contains('show')) {
            closeModal();
        }
    });
});
</script>
</body>
</html>