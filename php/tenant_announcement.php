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

$thisMonth = 0;
$currentMonth = date('Y-m');
foreach ($announcements as $announcement) {
    if (!empty($announcement['date_published']) &&
        substr((string) $announcement['date_published'], 0, 7) === $currentMonth) {
        $thisMonth++;
    }
}

$latest = $announcements[0] ?? null;
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
    <link rel="stylesheet" href="../css/owner_ui.css?v=1">
    <link rel="stylesheet" href="../css/tenant_announcement.css?v=1">
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <picture>
            <img class="logo" src="../image/db_logo.png" alt="Residentia Boarding House">
        </picture>
        <nav class="nav">
            <a href="tenant_db.php">Dashboard</a>
            <a href="tenant_billing.php">Billing &amp; Payments</a>
            <a href="tenant_maintenance.php">Maintenance</a>
            <a href="tenant_announcement.php" class="active" aria-current="page">Announcements</a>
        </nav>
        <a class="logout" href="../logout.php">Log out</a>
    </aside>

    <main class="main">
        <div class="tenant-main tenant-announcement-page">
            <header class="tenant-header">
                <div>
                    <h1>Announcements</h1>
                    <p><?= e($currentDate) ?> · Welcome, <?= e($username) ?></p>
                </div>
            </header>

            <section class="tenant-announcement-stats" aria-label="Announcement summary">
                <article class="tenant-panel stat">
                    <span>All Announcements</span>
                    <h2><?= count($announcements) ?></h2>
                    <small>Received so far</small>
                </article>
                <article class="tenant-panel stat">
                    <span>This Month</span>
                    <h2><?= $thisMonth ?></h2>
                    <small><?= e(date('F Y')) ?></small>
                </article>
                <article class="tenant-panel stat latest">
                    <span>Latest Announcement</span>
                    <h2><?= $latest ? e(announcementDate($latest['date_published'])) : '—' ?></h2>
                    <small><?= $latest ? e($latest['announcement_title']) : 'No announcements yet' ?></small>
                </article>
            </section>

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
