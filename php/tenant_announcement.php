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

function announcementDate($value): string
{
    $timestamp = $value ? strtotime($value) : false;
    return $timestamp ? date('M j, Y', $timestamp) : '—';
}

$pdo = db();

$stmt = $pdo->prepare("
    SELECT
        a.announcement_id,
        a.announcement_title,
        a.announcement_message,
        a.date_published,
        o.first_name,
        o.middle_name,
        o.last_name
    FROM announcement_recipient ar
    INNER JOIN announcement a ON a.announcement_id = ar.announcement_id
    LEFT JOIN owner o ON o.owner_id = a.owner_id
    WHERE ar.tenant_id = ?
    ORDER BY a.date_published DESC, a.announcement_id DESC
");
$stmt->execute([$tenantId]);
$announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page = 'announcements';
$currentMonth = date('Y-m');
$username = $_SESSION['username'] ?? 'Tenant';
$currentDate = date('l, F j, Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Announcements | Residentia</title>

    <link rel="stylesheet" href="../css/owner_db.css?v=5">
    <link rel="stylesheet" href="../css/tenant_db.css?v=4">
    <link rel="stylesheet" href="../css/tenant_portal.css?v=2">
    <link rel="stylesheet" href="../css/owner_ui.css?v=11">
    <link rel="stylesheet" href="../css/tenant_announcement.css?v=2">
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
        <div class="tenant-main tenant-announcement-page">
            <header class="tenant-header page-header-banner">
                <div>
                    <h1>Announcements</h1>
                    <p>View important notices, schedules, and reminders from your boarding house.</p>
                </div>
            </header>

            <section class="tenant-panel announcement-list-panel" aria-labelledby="announcements-heading">
                <div class="announcement-toolbar">
                    <div class="tenant-panel-head">
                        <div>
                            <h2 id="announcements-heading">My Announcements</h2>
                            <p><span id="shownCount"><?= count($announcements) ?></span> announcement(s) shown</p>
                        </div>
                    </div>
                    <div class="announcement-controls">
                        <label class="announcement-search">
                            <span class="visually-hidden">Search announcements</span>
                            <input type="search" id="announcementSearch" placeholder="Search title or message">
                        </label>
                        <div class="announcement-filters" role="group" aria-label="Filter announcements">
                            <button type="button" class="filter-button active" data-filter="all">All</button>
                            <button type="button" class="filter-button" data-filter="month">This month</button>
                            <button type="button" class="filter-button" data-filter="earlier">Earlier</button>
                        </div>
                    </div>
                </div>

                <?php if (!$announcements): ?>
                    <div class="announcement-empty">
                        <h3>No announcements yet</h3>
                        <p>New updates from your owner will appear here.</p>
                    </div>
                <?php else: ?>
                    <div class="announcement-list" id="announcementList">
                        <?php foreach ($announcements as $announcement): ?>
                            <?php
                            $timestamp = strtotime($announcement['date_published']);
                            $period = substr((string) $announcement['date_published'], 0, 7) === $currentMonth
                                ? 'month'
                                : 'earlier';
                            $ownerName = trim(
                                ($announcement['first_name'] ?? '') . ' ' .
                                (!empty($announcement['middle_name']) ? $announcement['middle_name'] . ' ' : '') .
                                ($announcement['last_name'] ?? '')
                            );
                            $searchText = mb_strtolower(
                                (string) $announcement['announcement_title'] . ' ' .
                                (string) $announcement['announcement_message'] . ' ' . $ownerName
                            );
                            ?>
                            <article class="announcement-item"
                                     data-period="<?= e($period) ?>"
                                     data-search="<?= e($searchText) ?>">
                                <div class="announcement-date" aria-hidden="true">
                                    <span><?= e(strtoupper(date('M', $timestamp))) ?></span>
                                    <strong><?= e(date('j', $timestamp)) ?></strong>
                                </div>
                                <div class="announcement-body">
                                    <h3><?= e($announcement['announcement_title']) ?></h3>
                                    <p class="announcement-message"><?= nl2br(e($announcement['announcement_message'])) ?></p>
                                    <button type="button" class="announcement-more" hidden>Read more</button>
                                    <div class="announcement-meta">
                                        <?= e(announcementDate($announcement['date_published'])) ?>
                                        <?php if ($ownerName !== ''): ?>
                                            · Posted by <?= e($ownerName) ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                        <div class="announcement-empty" id="noAnnouncementMatch" hidden>
                            <h3>No matches</h3>
                            <p>Try a different search or filter.</p>
                        </div>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>
</div>

<script>
(function () {
    var search = document.getElementById('announcementSearch');
    var filters = document.querySelectorAll('.filter-button');
    var items = document.querySelectorAll('.announcement-item');
    var shownCount = document.getElementById('shownCount');
    var noMatch = document.getElementById('noAnnouncementMatch');
    var activeFilter = 'all';

    function applyFilters() {
        var query = (search.value || '').trim().toLowerCase();
        var shown = 0;

        items.forEach(function (item) {
            var matchesFilter = activeFilter === 'all' || item.dataset.period === activeFilter;
            var matchesSearch = item.dataset.search.indexOf(query) !== -1;
            var visible = matchesFilter && matchesSearch;

            item.hidden = !visible;
            if (visible) {
                shown++;
            }
        });

        shownCount.textContent = shown;
        if (noMatch) {
            noMatch.hidden = !(items.length > 0 && shown === 0);
        }
    }

    if (search) {
        search.addEventListener('input', applyFilters);
    }

    filters.forEach(function (button) {
        button.addEventListener('click', function () {
            filters.forEach(function (item) {
                item.classList.remove('active');
            });
            button.classList.add('active');
            activeFilter = button.dataset.filter;
            applyFilters();
        });
    });

    document.querySelectorAll('.announcement-item').forEach(function (item) {
        var message = item.querySelector('.announcement-message');
        var more = item.querySelector('.announcement-more');

        if (message.scrollHeight > message.clientHeight + 2) {
            more.hidden = false;
        }

        more.addEventListener('click', function () {
            var open = message.classList.toggle('open');
            more.textContent = open ? 'Show less' : 'Read more';
        });
    });
})();
</script>
</body>
</html>
