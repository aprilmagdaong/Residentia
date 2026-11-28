<?php
session_start();
require_once __DIR__ . '/index_config.php';

if (strtolower(trim($_SESSION['role'] ?? '')) !== 'tenant') {
    header('Location: ../index.php');
    exit;
}

$tenantId = (int)($_SESSION['tenant_id'] ?? 0);
if ($tenantId <= 0) {
    http_response_code(403);
    exit('Tenant account not found. Please log in again.');
}

$pdo = db();
$page = 'profile';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$tenantQuery = $pdo->prepare(
    'SELECT first_name, middle_name, last_name, email, gender, birthdate, occupation
     FROM tenant WHERE tenant_id = ?'
);
$tenantQuery->execute([$tenantId]);
$tenant = $tenantQuery->fetch(PDO::FETCH_ASSOC);
if (!$tenant) {
    http_response_code(404);
    exit('Tenant record not found.');
}
$displayName = trim(
    (string)$tenant['first_name'] . ' ' .
    (string)($tenant['middle_name'] ?? '') . ' ' .
    (string)$tenant['last_name']
);
$profileInitials = mb_strtoupper(
    mb_substr((string)$tenant['first_name'], 0, 1, 'UTF-8') .
    mb_substr((string)$tenant['last_name'], 0, 1, 'UTF-8'),
    'UTF-8'
);

$phoneQuery = $pdo->prepare(
    'SELECT number_id, contact_number
     FROM tenant_contact_number WHERE tenant_id = ? ORDER BY number_id'
);
$phoneQuery->execute([$tenantId]);
$contactRows = $phoneQuery->fetchAll(PDO::FETCH_ASSOC);
$contactNumbers = array_map(
    static function ($row) {
        return (string)$row['contact_number'];
    },
    $contactRows
);

$addressQuery = $pdo->prepare(
    'SELECT add_id, barangay, municipality, province
     FROM tenant_address WHERE tenant_id = ? ORDER BY add_id LIMIT 1'
);
$addressQuery->execute([$tenantId]);
$address = $addressQuery->fetch(PDO::FETCH_ASSOC) ?: [];

$emergencyQuery = $pdo->prepare(
    'SELECT ec_id, contact_name, relationship, contact_number
     FROM tenant_emergency_contact WHERE tenant_id = ? ORDER BY ec_id'
);
$emergencyQuery->execute([$tenantId]);
$emergencyRows = $emergencyQuery->fetchAll(PDO::FETCH_ASSOC);
$emergency = $emergencyRows[0] ?? [];
$emergencyNumbers = array_map(
    static function ($row) {
        return (string)$row['contact_number'];
    },
    $emergencyRows
);

$addressParts = [];
foreach ([$address['barangay'] ?? '', $address['municipality'] ?? '', $address['province'] ?? ''] as $part) {
    if (trim((string)$part) !== '') {
        $addressParts[] = trim((string)$part);
    }
}
$displayAddress = $addressParts ? implode(', ', $addressParts) : '—';
$displayBirthdate = !empty($tenant['birthdate'])
    ? date('F j, Y', strtotime($tenant['birthdate']))
    : '—';

$form = [
    'first_name' => (string)($tenant['first_name'] ?? ''),
    'middle_name' => (string)($tenant['middle_name'] ?? ''),
    'last_name' => (string)($tenant['last_name'] ?? ''),
    'email' => (string)($tenant['email'] ?? ''),
    'gender' => (string)($tenant['gender'] ?? ''),
    'birthdate' => (string)($tenant['birthdate'] ?? ''),
    'occupation' => (string)($tenant['occupation'] ?? ''),
    'contact_numbers' => $contactNumbers ?: [''],
    'barangay' => (string)($address['barangay'] ?? ''),
    'municipality' => (string)($address['municipality'] ?? ''),
    'province' => (string)($address['province'] ?? ''),
    'emergency_name' => (string)($emergency['contact_name'] ?? ''),
    'relationship' => (string)($emergency['relationship'] ?? ''),
    'emergency_numbers' => $emergencyNumbers ?: [''],
];

$errors = [];
$success = $_SESSION['tenant_profile_success'] ?? '';
unset($_SESSION['tenant_profile_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ([
        'first_name', 'middle_name', 'last_name', 'email', 'gender', 'birthdate', 'occupation',
        'barangay', 'municipality', 'province', 'emergency_name', 'relationship',
    ] as $field) {
        $form[$field] = trim((string)($_POST[$field] ?? ''));
    }

    $postedNumbers = $_POST['contact_numbers'] ?? [];
    $form['contact_numbers'] = [];
    if (is_array($postedNumbers)) {
        foreach ($postedNumbers as $number) {
            if (is_scalar($number)) {
                $form['contact_numbers'][] = trim((string)$number);
            }
        }
    }
    if (!$form['contact_numbers']) {
        $form['contact_numbers'] = [''];
    }

    $postedEmergencyNumbers = $_POST['emergency_numbers'] ?? [];
    $form['emergency_numbers'] = [];
    if (is_array($postedEmergencyNumbers)) {
        foreach ($postedEmergencyNumbers as $number) {
            if (is_scalar($number)) {
                $form['emergency_numbers'][] = trim((string)$number);
            }
        }
    }
    if (!$form['emergency_numbers']) {
        $form['emergency_numbers'] = [''];
    }

    $numbersToSave = [];
    foreach ($form['contact_numbers'] as $number) {
        if ($number !== '') {
            $numbersToSave[] = $number;
        }
    }
    $emergencyNumbersToSave = [];
    foreach ($form['emergency_numbers'] as $number) {
        if ($number !== '') {
            $emergencyNumbersToSave[] = $number;
        }
    }

    $csrfToken = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        http_response_code(400);
        $errors[] = 'Your session expired. Refresh the page and submit again.';
    }

    foreach (['first_name', 'last_name', 'email', 'gender', 'birthdate', 'occupation',
              'barangay', 'municipality', 'province', 'emergency_name', 'relationship'] as $requiredField) {
        if ($form[$requiredField] === '') {
            $errors[] = 'Please complete all required fields.';
            break;
        }
    }
    if (!$numbersToSave) {
        $errors[] = 'Enter at least one contact number.';
    }
    if (!$emergencyNumbersToSave) {
        $errors[] = 'Enter at least one emergency contact number.';
    }

    $lengthLimits = [
        'first_name' => 50,
        'middle_name' => 50,
        'last_name' => 50,
        'email' => 100,
        'occupation' => 50,
        'barangay' => 100,
        'municipality' => 100,
        'province' => 100,
        'emergency_name' => 100,
        'relationship' => 50,
    ];
    foreach ($lengthLimits as $field => $limit) {
        if (mb_strlen($form[$field], 'UTF-8') > $limit) {
            $errors[] = 'One or more fields exceed the allowed length.';
            break;
        }
    }

    if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if (!in_array($form['gender'], ['Male', 'Female'], true)) {
        $errors[] = 'Please select a valid gender.';
    }

    $birthdate = DateTime::createFromFormat('!Y-m-d', $form['birthdate']);
    if (!$birthdate || $birthdate->format('Y-m-d') !== $form['birthdate'] || $form['birthdate'] > date('Y-m-d')) {
        $errors[] = 'Please enter a valid birthdate.';
    }
    foreach ($numbersToSave as $number) {
        if (!preg_match('/^[0-9+\-\s]{7,15}$/', $number)) {
            $errors[] = 'Please enter valid contact numbers (7 to 15 digits).';
            break;
        }
    }
    foreach ($emergencyNumbersToSave as $number) {
        if (!preg_match('/^[0-9+\-\s]{7,15}$/', $number)) {
            $errors[] = 'Please enter valid emergency contact numbers (7 to 15 digits).';
            break;
        }
    }

    if (!$errors) {
        try {
            $emailQuery = $pdo->prepare(
                'SELECT tenant_id FROM tenant WHERE email = ? AND tenant_id <> ? LIMIT 1'
            );
            $emailQuery->execute([$form['email'], $tenantId]);
            if ($emailQuery->fetchColumn()) {
                $errors[] = 'That email address is already in use.';
            } else {
                $pdo->beginTransaction();

                $pdo->prepare(
                    'UPDATE tenant
                     SET first_name = ?, middle_name = ?, last_name = ?, email = ?,
                         gender = ?, birthdate = ?, occupation = ?
                     WHERE tenant_id = ?'
                )->execute([
                    $form['first_name'],
                    $form['middle_name'] !== '' ? $form['middle_name'] : null,
                    $form['last_name'],
                    $form['email'],
                    $form['gender'],
                    $form['birthdate'],
                    $form['occupation'],
                    $tenantId,
                ]);

                $updatePhone = $pdo->prepare(
                    'UPDATE tenant_contact_number SET contact_number = ? WHERE number_id = ? AND tenant_id = ?'
                );
                $insertPhone = $pdo->prepare(
                    'INSERT INTO tenant_contact_number (tenant_id, contact_number) VALUES (?, ?)'
                );
                foreach ($numbersToSave as $index => $number) {
                    if (isset($contactRows[$index])) {
                        $updatePhone->execute([$number, $contactRows[$index]['number_id'], $tenantId]);
                    } else {
                        $insertPhone->execute([$tenantId, $number]);
                    }
                }
                for ($index = count($numbersToSave); $index < count($contactRows); $index++) {
                    $pdo->prepare(
                        'DELETE FROM tenant_contact_number WHERE number_id = ? AND tenant_id = ?'
                    )->execute([$contactRows[$index]['number_id'], $tenantId]);
                }

                if (!empty($address['add_id'])) {
                    $pdo->prepare(
                        'UPDATE tenant_address
                         SET barangay = ?, municipality = ?, province = ?
                         WHERE add_id = ? AND tenant_id = ?'
                    )->execute([
                        $form['barangay'],
                        $form['municipality'],
                        $form['province'],
                        $address['add_id'],
                        $tenantId,
                    ]);
                } else {
                    $pdo->prepare(
                        'INSERT INTO tenant_address (tenant_id, barangay, municipality, province) VALUES (?, ?, ?, ?)'
                    )->execute([$tenantId, $form['barangay'], $form['municipality'], $form['province']]);
                }

                $updateEmergency = $pdo->prepare(
                    'UPDATE tenant_emergency_contact
                     SET contact_name = ?, relationship = ?, contact_number = ?
                     WHERE ec_id = ? AND tenant_id = ?'
                );
                $insertEmergency = $pdo->prepare(
                    'INSERT INTO tenant_emergency_contact (tenant_id, contact_name, relationship, contact_number)
                     VALUES (?, ?, ?, ?)'
                );
                foreach ($emergencyNumbersToSave as $index => $number) {
                    if (isset($emergencyRows[$index])) {
                        $updateEmergency->execute([
                            $form['emergency_name'],
                            $form['relationship'],
                            $number,
                            $emergencyRows[$index]['ec_id'],
                            $tenantId,
                        ]);
                    } else {
                        $insertEmergency->execute([
                            $tenantId,
                            $form['emergency_name'],
                            $form['relationship'],
                            $number,
                        ]);
                    }
                }
                for ($index = count($emergencyNumbersToSave); $index < count($emergencyRows); $index++) {
                    $pdo->prepare(
                        'DELETE FROM tenant_emergency_contact WHERE ec_id = ? AND tenant_id = ?'
                    )->execute([$emergencyRows[$index]['ec_id'], $tenantId]);
                }

                $pdo->commit();
                $_SESSION['tenant_profile_success'] = 'Your profile has been updated.';
                header('Location: tenant_profile.php');
                exit;
            }
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Tenant profile update failed: ' . $exception->getMessage());
            $errors[] = 'Could not save your profile. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile | Residentia</title>
    <link rel="stylesheet" href="../css/owner_db.css?v=10">
    <link rel="stylesheet" href="../css/tenant_db.css?v=10">
    <link rel="stylesheet" href="../css/tenant_portal.css?v=3">
    <link rel="stylesheet" href="../css/owner_ui.css?v=11">
    <link rel="stylesheet" href="../css/tenant_profile.css?v=3">
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <picture><img class="logo" src="../image/db_logo.png" alt="Residentia Boarding House"></picture>
        <nav class="nav">
            <a href="tenant_db.php">
                <svg class="nav-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <rect x="3" y="3" width="7" height="9" rx="1"></rect>
                    <rect x="14" y="3" width="7" height="5" rx="1"></rect>
                    <rect x="14" y="12" width="7" height="9" rx="1"></rect>
                    <rect x="3" y="16" width="7" height="5" rx="1"></rect>
                </svg>
                <span>Dashboard</span>
            </a>
            <a href="tenant_billing.php">
                <svg class="nav-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <rect x="2" y="5" width="20" height="14" rx="2"></rect>
                    <line x1="2" y1="10" x2="22" y2="10"></line>
                </svg>
                <span>Billing &amp; Payments</span>
            </a>
            <a href="tenant_maintenance.php">
                <svg class="nav-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"></path>
                </svg>
                <span>Maintenance</span>
            </a>
            <a href="tenant_announcement.php">
                <svg class="nav-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M3 11l19-9v20L3 13v-2z"></path>
                    <path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"></path>
                </svg>
                <span>Announcements</span>
            </a>
            <a href="tenant_policies.php">
                <svg class="nav-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                    <polyline points="14 2 14 8 20 8"></polyline>
                    <line x1="16" y1="13" x2="8" y2="13"></line>
                    <line x1="16" y1="17" x2="8" y2="17"></line>
                </svg>
                <span>Policies &amp; Rules</span>
            </a>
            <a href="tenant_profile.php" class="active" aria-current="page">
                <svg class="nav-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="8" r="4"></circle>
                    <path d="M5 21a7 7 0 0 1 14 0"></path>
                </svg>
                <span>Profile</span>
            </a>
        </nav>
        <a class="logout" href="../logout.php">
            <svg class="nav-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                <polyline points="16 17 21 12 16 7"></polyline>
                <line x1="21" y1="12" x2="9" y2="12"></line>
            </svg>
            <span>Log out</span>
        </a>
    </aside>

    <main class="main">
        <div class="tenant-profile-page">
            <header class="profile-header page-header-banner">
                <div class="profile-heading">
                    <span class="profile-avatar" aria-hidden="true"><?= e($profileInitials ?: 'T') ?></span>
                    <div class="profile-identity">
                        <span class="profile-kicker">Tenant Profile</span>
                        <h1><?= e($displayName) ?></h1>
                        <p><?= e($tenant['email']) ?></p>
                    </div>
                </div>
                <button class="profile-edit-trigger" id="openProfileEditor" type="button">Edit Profile</button>
            </header>

            <?php if ($success): ?>
                <dialog class="tenant-success-dialog" id="tenantSuccessDialog" aria-labelledby="tenantSuccessTitle" aria-describedby="tenantSuccessMessage">
                    <h2 id="tenantSuccessTitle">Profile updated</h2>
                    <p id="tenantSuccessMessage"><?= e($success) ?></p>
                    <form method="dialog">
                        <button type="submit">OK</button>
                    </form>
                </dialog>
            <?php endif; ?>
            <?php if ($errors): ?>
                <div class="profile-alert error" role="alert">
                    <ul>
                        <?php foreach (array_unique($errors) as $error): ?>
                            <li><?= e($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="profile-card">
                <section class="profile-section">
                    <h2>Personal Information</h2>
                    <div class="profile-view-grid">
                        <div class="profile-view-field">
                            <span>First name</span>
                            <strong><?= e($tenant['first_name'] ?: '—') ?></strong>
                        </div>
                        <div class="profile-view-field">
                            <span>Middle name</span>
                            <strong><?= e($tenant['middle_name'] ?: '—') ?></strong>
                        </div>
                        <div class="profile-view-field">
                            <span>Last name</span>
                            <strong><?= e($tenant['last_name'] ?: '—') ?></strong>
                        </div>
                        <div class="profile-view-field">
                            <span>Email address</span>
                            <strong><?= e($tenant['email'] ?: '—') ?></strong>
                        </div>
                        <div class="profile-view-field">
                            <span>Gender</span>
                            <strong><?= e($tenant['gender'] ?: '—') ?></strong>
                        </div>
                        <div class="profile-view-field">
                            <span>Birthdate</span>
                            <strong><?= e($displayBirthdate) ?></strong>
                        </div>
                        <div class="profile-view-field">
                            <span>Occupation</span>
                            <strong><?= e($tenant['occupation'] ?: '—') ?></strong>
                        </div>
                    </div>
                </section>

                <section class="profile-section">
                    <h2>Contact &amp; Address</h2>
                    <div class="profile-view-grid">
                        <div class="profile-view-field">
                            <span>Contact numbers</span>
                            <?php if ($contactNumbers): ?>
                                <ul class="profile-contact-list">
                                    <?php foreach ($contactNumbers as $number): ?>
                                        <li><?= e($number) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <strong>—</strong>
                            <?php endif; ?>
                        </div>
                        <div class="profile-view-field">
                            <span>Address</span>
                            <strong><?= e($displayAddress) ?></strong>
                        </div>
                    </div>
                </section>

                <section class="profile-section">
                    <h2>Emergency Contact</h2>
                    <div class="profile-view-grid">
                        <div class="profile-view-field">
                            <span>Contact person</span>
                            <strong><?= e($emergency['contact_name'] ?? '—') ?></strong>
                        </div>
                        <div class="profile-view-field">
                            <span>Relationship</span>
                            <strong><?= e($emergency['relationship'] ?? '—') ?></strong>
                        </div>
                        <div class="profile-view-field">
                            <span>Contact numbers</span>
                            <?php if ($emergencyNumbers): ?>
                                <ul class="profile-contact-list">
                                    <?php foreach ($emergencyNumbers as $number): ?>
                                        <li><?= e($number) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <strong>—</strong>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </main>
</div>
<dialog class="profile-dialog" id="profileEditDialog" aria-labelledby="profileDialogTitle">
    <form class="profile-edit-form" method="POST" action="tenant_profile.php">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
        <header class="profile-dialog-header">
            <div>
                <h2 id="profileDialogTitle">Edit Profile</h2>
                <p>Update your personal, contact, and emergency details.</p>
            </div>
            <button class="profile-close" id="closeProfileEditor" type="button" aria-label="Close">×</button>
        </header>
        <div class="profile-dialog-content">
            <section class="profile-section">
                <h3>Personal Information</h3>
                <div class="profile-grid">
                    <div class="profile-field">
                        <label for="first_name">First name *</label>
                        <input id="first_name" name="first_name" type="text" maxlength="50" autocomplete="given-name" value="<?= e($form['first_name']) ?>" required>
                    </div>
                    <div class="profile-field">
                        <label for="middle_name">Middle name</label>
                        <input id="middle_name" name="middle_name" type="text" maxlength="50" autocomplete="additional-name" value="<?= e($form['middle_name']) ?>">
                    </div>
                    <div class="profile-field">
                        <label for="last_name">Last name *</label>
                        <input id="last_name" name="last_name" type="text" maxlength="50" autocomplete="family-name" value="<?= e($form['last_name']) ?>" required>
                    </div>
                    <div class="profile-field">
                        <label for="email">Email address *</label>
                        <input id="email" name="email" type="email" maxlength="100" autocomplete="email" value="<?= e($form['email']) ?>" required>
                    </div>
                    <div class="profile-field">
                        <label for="gender">Gender *</label>
                        <select id="gender" name="gender" required>
                            <option value="">Select gender</option>
                            <option value="Male" <?= $form['gender'] === 'Male' ? 'selected' : '' ?>>Male</option>
                            <option value="Female" <?= $form['gender'] === 'Female' ? 'selected' : '' ?>>Female</option>
                        </select>
                    </div>
                    <div class="profile-field">
                        <label for="birthdate">Birthdate *</label>
                        <input id="birthdate" name="birthdate" type="date" max="<?= date('Y-m-d') ?>" value="<?= e($form['birthdate']) ?>" required>
                    </div>
                    <div class="profile-field profile-field-full">
                        <label for="occupation">Occupation *</label>
                        <input id="occupation" name="occupation" type="text" maxlength="50" value="<?= e($form['occupation']) ?>" required>
                    </div>
                </div>
            </section>

            <section class="profile-section">
                <h3>Contact &amp; Address</h3>
                <div class="profile-grid">
                    <div class="profile-field profile-field-full">
                        <span class="profile-field-label">Contact numbers *</span>
                        <p class="profile-field-hint">Add at least one number. You can include more than one.</p>
                        <div class="profile-number-list" id="contactNumberList">
                            <?php foreach ($form['contact_numbers'] as $index => $number): ?>
                                <div class="profile-number-row">
                                    <label for="contact_number_<?= (int)$index ?>">Contact number <?= (int)$index + 1 ?></label>
                                    <div class="profile-number-input-wrap">
                                        <input id="contact_number_<?= (int)$index ?>" name="contact_numbers[]" type="tel" maxlength="15" autocomplete="tel" value="<?= e($number) ?>">
                                                <button class="profile-number-remove" type="button" data-remove-number aria-label="Remove contact number <?= (int)$index + 1 ?>">Remove</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button class="profile-add-number" id="addContactNumber" type="button">+ Add contact number</button>
                    </div>
                    <div class="profile-field">
                        <label for="barangay">Barangay *</label>
                        <input id="barangay" name="barangay" type="text" maxlength="100" autocomplete="address-level3" value="<?= e($form['barangay']) ?>" required>
                    </div>
                    <div class="profile-field">
                        <label for="municipality">Municipality / City *</label>
                        <input id="municipality" name="municipality" type="text" maxlength="100" autocomplete="address-level2" value="<?= e($form['municipality']) ?>" required>
                    </div>
                    <div class="profile-field profile-field-full">
                        <label for="province">Province *</label>
                        <input id="province" name="province" type="text" maxlength="100" autocomplete="address-level1" value="<?= e($form['province']) ?>" required>
                    </div>
                </div>
            </section>

            <section class="profile-section">
                <h3>Emergency Contact</h3>
                <div class="profile-grid">
                    <div class="profile-field">
                        <label for="emergency_name">Contact person *</label>
                        <input id="emergency_name" name="emergency_name" type="text" maxlength="100" value="<?= e($form['emergency_name']) ?>" required>
                    </div>
                    <div class="profile-field">
                        <label for="relationship">Relationship *</label>
                        <input id="relationship" name="relationship" type="text" maxlength="50" value="<?= e($form['relationship']) ?>" required>
                    </div>
                    <div class="profile-field profile-field-full">
                        <span class="profile-field-label">Emergency contact numbers *</span>
                        <p class="profile-field-hint">Add at least one emergency number. You can include more than one.</p>
                        <div class="profile-number-list" id="emergencyNumberList">
                            <?php foreach ($form['emergency_numbers'] as $index => $number): ?>
                                <div class="profile-number-row">
                                    <label for="emergency_number_<?= (int)$index ?>">Emergency contact number <?= (int)$index + 1 ?></label>
                                    <div class="profile-number-input-wrap">
                                        <input id="emergency_number_<?= (int)$index ?>" name="emergency_numbers[]" type="tel" maxlength="15" value="<?= e($number) ?>">
                                        <button class="profile-number-remove" type="button" data-remove-number aria-label="Remove emergency contact number <?= (int)$index + 1 ?>">Remove</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button class="profile-add-number" id="addEmergencyNumber" type="button">+ Add emergency number</button>
                    </div>
                </div>
            </section>
        </div>
        <footer class="profile-actions">
            <button class="profile-cancel" id="cancelProfileEditor" type="button">Cancel</button>
            <button class="profile-save" type="submit">Save Changes</button>
        </footer>
    </form>
</dialog>
<template id="contactNumberTemplate">
    <div class="profile-number-row">
        <label>Contact number</label>
        <div class="profile-number-input-wrap">
            <input name="contact_numbers[]" type="tel" maxlength="15" autocomplete="tel">
            <button class="profile-number-remove" type="button" data-remove-number>Remove</button>
        </div>
    </div>
</template>
<template id="emergencyNumberTemplate">
    <div class="profile-number-row">
        <label>Emergency contact number</label>
        <div class="profile-number-input-wrap">
            <input name="emergency_numbers[]" type="tel" maxlength="15">
            <button class="profile-number-remove" type="button" data-remove-number>Remove</button>
        </div>
    </div>
</template>
<script>
(function () {
    var dialog = document.getElementById('profileEditDialog');
    var successDialog = document.getElementById('tenantSuccessDialog');
    if (successDialog) {
        successDialog.showModal();
    }

    function setupNumberList(listId, templateId, addButtonId, inputIdPrefix, labelPrefix) {
        var list = document.getElementById(listId);
        var template = document.getElementById(templateId);
        var addButton = document.getElementById(addButtonId);

        function updateLabels() {
            var rows = list.querySelectorAll('.profile-number-row');
            Array.prototype.forEach.call(rows, function (row, index) {
                var input = row.querySelector('input');
                var label = row.querySelector('label');
                var removeButton = row.querySelector('[data-remove-number]');
                input.id = inputIdPrefix + index;
                label.htmlFor = input.id;
                label.textContent = labelPrefix + ' ' + (index + 1);
                removeButton.setAttribute('aria-label', 'Remove ' + labelPrefix + ' ' + (index + 1));
            });
        }

        addButton.addEventListener('click', function () {
            list.appendChild(template.content.cloneNode(true));
            updateLabels();
            list.lastElementChild.querySelector('input').focus();
        });

        list.addEventListener('click', function (event) {
            var removeButton = event.target.closest('[data-remove-number]');
            if (!removeButton) {
                return;
            }

            var rows = list.querySelectorAll('.profile-number-row');
            if (rows.length === 1) {
                rows[0].querySelector('input').value = '';
            } else {
                removeButton.closest('.profile-number-row').remove();
                updateLabels();
            }
        });

        updateLabels();
    }

    document.getElementById('openProfileEditor').addEventListener('click', function () {
        dialog.showModal();
    });
    document.getElementById('closeProfileEditor').addEventListener('click', function () {
        dialog.close();
    });
    document.getElementById('cancelProfileEditor').addEventListener('click', function () {
        dialog.close();
    });
    setupNumberList('contactNumberList', 'contactNumberTemplate', 'addContactNumber', 'contact_number_', 'Contact number');
    setupNumberList('emergencyNumberList', 'emergencyNumberTemplate', 'addEmergencyNumber', 'emergency_number_', 'Emergency contact number');
    <?php if ($errors): ?>
    dialog.showModal();
    <?php endif; ?>
})();
</script>
</body>
</html>
