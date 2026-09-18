<?php
if (session_status() === PHP_SESSION_NONE)
    session_start();
$stType = strtoupper((string) ($_SESSION['rhu_staff_login']['staff_type'] ?? ''));
if (empty($_SESSION['rhu_staff_login']) || ($stType !== 'SANITARY_INSPECTOR' && strpos($stType, 'SANITARY') === false && strpos($stType, 'INSPECTOR') === false)) {
    header('Location: RHULogin.php');
    exit;
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/portal.php';
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
function iconSvg(string $name, string $class = 'w-5 h-5'): string
{
    $icons = [
        'menu' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>',
        'logout' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/><path d="M13 21H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7"/></svg>',
        'close' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>',
        'shield' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
    ];
    return $icons[$name] ?? '';
}
function tabUrl(string $tab, array $extra = []): string
{
    return '?' . http_build_query(array_merge(['tab' => $tab], $extra));
}
$barangayOptions = [
    'Aga',
    'Balaytigue',
    'Balok-Balok',
    'Banilad',
    'Barangay 1 (Pob.)',
    'Barangay 2 (Pob.)',
    'Barangay 3 (Pob.)',
    'Barangay 4 (Pob.)',
    'Barangay 5 (Pob.)',
    'Barangay 6 (Pob.)',
    'Barangay 7 (Pob.)',
    'Barangay 8 (Pob.)',
    'Barangay 9 (Pob.)',
    'Barangay 10 (Pob.)',
    'Barangay 11 (Pob.)',
    'Barangay 12 (Pob.)',
    'Bilaran',
    'Bucana',
    'Bulihan',
    'Bunducan',
    'Butucan',
    'Calayo',
    'Catandaan',
    'Cagunan',
    'Dayap',
    'Kaylaway',
    'Kayrillaw',
    'Latag',
    'Looc',
    'Lumbangan',
    'Malapad na Bato',
    'Mataas na Pulo',
    'Maugat',
    'Munting Indang',
    'Natipuan',
    'Pantalan',
    'Papaya',
    'Putat',
    'Reparo',
    'Talangan',
    'Tumalim',
    'Utod',
    'Wawa',
];

$sanitaryFlashSuccess = $_SESSION['sanitary_flash_success'] ?? '';
$sanitaryFlashError = $_SESSION['sanitary_flash_error'] ?? '';
unset($_SESSION['sanitary_flash_success'], $_SESSION['sanitary_flash_error']);
$sanitaryStaffId = (int) ($_SESSION['rhu_staff_login']['staff_id'] ?? 0);
$sanitaryUserId = (int) ($_SESSION['rhu_staff_login']['id'] ?? $_SESSION['rhu_staff_login']['user_id'] ?? 0);

function sanitaryBarangayId(PDO $pdo, string $name)
{
    $name = trim($name);
    if ($name === '') return null;
    $stmt = $pdo->prepare("SELECT id FROM barangays WHERE name = ? LIMIT 1");
    $stmt->execute([$name]);
    $id = (int) ($stmt->fetchColumn() ?: 0);
    return $id > 0 ? $id : null;
}

function sanitaryRecordEvent(PDO $pdo, string $title, string $barangay, string $date, string $location, string $description, int $staffId): int
{
    $eventDate = $date !== '' ? $date : date('Y-m-d');
    $stmt = $pdo->prepare("INSERT INTO events (title, description, barangay_id, location, start_datetime, organizer_id, status, created_at)
        VALUES (:title, :description, :barangay_id, :location, :start_at, :organizer, 'Scheduled', NOW())");
    $stmt->execute([
        'title' => $title,
        'description' => $description,
        'barangay_id' => sanitaryBarangayId($pdo, $barangay),
        'location' => $location !== '' ? $location : $barangay,
        'start_at' => $eventDate . ' 08:00:00',
        'organizer' => $staffId ?: null,
    ]);
    return (int) $pdo->lastInsertId();
}

if (false && !empty($pdo) && function_exists('rhuEnv') && rhuEnv('RHU_ALLOW_SANITARY_SCHEMA', '0') === '1') {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sanitation_notices (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            inspection_id BIGINT UNSIGNED NULL,
            notice_number VARCHAR(60) NOT NULL UNIQUE,
            establishment VARCHAR(200) NULL,
            barangay VARCHAR(120) NULL,
            issued_by_staff_id INT NULL,
            issued_date DATE NOT NULL,
            violations INT NOT NULL DEFAULT 0,
            findings TEXT NULL,
            notice_status VARCHAR(30) NOT NULL DEFAULT 'Pending',
            follow_up_date DATE NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_notice_inspection (inspection_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS water_quality_samples (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            source_name VARCHAR(200) NOT NULL,
            source_type VARCHAR(80) NOT NULL DEFAULT 'Well',
            barangay VARCHAR(120) NULL,
            sample_date DATE NOT NULL,
            ph_level DECIMAL(4,2) NULL,
            turbidity DECIMAL(8,2) NULL,
            coliform_result VARCHAR(40) NULL,
            residual_chlorine DECIMAL(6,2) NULL,
            overall_status VARCHAR(30) NOT NULL DEFAULT 'Safe',
            remarks TEXT NULL,
            sampled_by_staff_id INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS food_safety_inspections (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            establishment VARCHAR(200) NOT NULL,
            establishment_type VARCHAR(80) NOT NULL DEFAULT 'Eatery',
            barangay VARCHAR(120) NULL,
            inspection_date DATE NOT NULL,
            compliance_status VARCHAR(40) NOT NULL DEFAULT 'Compliant',
            score INT NOT NULL DEFAULT 0,
            findings TEXT NULL,
            inspector_staff_id INT NULL,
            next_inspection_date DATE NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS waste_management_records (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            record_type VARCHAR(80) NOT NULL DEFAULT 'Garbage Collection',
            location VARCHAR(200) NOT NULL,
            barangay VARCHAR(120) NULL,
            activity_date DATE NOT NULL,
            volume_estimate VARCHAR(80) NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'Completed',
            notes TEXT NULL,
            recorded_by_staff_id INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS vector_control_activities (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            activity_type VARCHAR(80) NOT NULL DEFAULT 'Breeding Site Inspection',
            location VARCHAR(200) NOT NULL,
            barangay VARCHAR(120) NULL,
            activity_date DATE NOT NULL,
            sites_found INT NOT NULL DEFAULT 0,
            sites_treated INT NOT NULL DEFAULT 0,
            method_used VARCHAR(120) NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'Completed',
            notes TEXT NULL,
            recorded_by_staff_id INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS community_campaigns (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(200) NOT NULL,
            campaign_type VARCHAR(80) NOT NULL DEFAULT 'Hygiene Awareness',
            barangay VARCHAR(120) NULL,
            event_date DATE NOT NULL,
            venue VARCHAR(200) NULL,
            audience_size INT NULL,
            materials TEXT NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'Scheduled',
            notes TEXT NULL,
            organized_by_staff_id INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS sanitation_schedules (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            target_name VARCHAR(200) NOT NULL,
            target_type VARCHAR(80) NOT NULL DEFAULT 'Food Establishment',
            barangay VARCHAR(120) NULL,
            scheduled_date DATE NOT NULL,
            scheduled_time VARCHAR(20) NULL,
            priority VARCHAR(20) NOT NULL DEFAULT 'Normal',
            schedule_status VARCHAR(40) NOT NULL DEFAULT 'Scheduled',
            notes TEXT NULL,
            assigned_staff_id INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
    }

        try {
            $cols = $pdo->query("SHOW COLUMNS FROM sanitation_notices")->fetchAll(PDO::FETCH_COLUMN) ?: [];
            if (!in_array('establishment', $cols, true)) $pdo->exec("ALTER TABLE sanitation_notices ADD COLUMN establishment VARCHAR(200) NULL");
            if (!in_array('barangay', $cols, true)) $pdo->exec("ALTER TABLE sanitation_notices ADD COLUMN barangay VARCHAR(120) NULL");
            if (!in_array('follow_up_date', $cols, true)) $pdo->exec("ALTER TABLE sanitation_notices ADD COLUMN follow_up_date DATE NULL");
            try { $pdo->exec("ALTER TABLE sanitation_notices MODIFY inspection_id BIGINT UNSIGNED NULL"); } catch (Throwable $e) {}
        } catch (Throwable $e) {}

}


if (!empty($pdo) && isset($_GET['print_inspection'])) {
    $printId = (int) $_GET['print_inspection'];
    $printStmt = $pdo->prepare("SELECT si.*, CONCAT(u.first_name,' ',u.last_name) AS inspector_name
        FROM sanitation_inspections si
        LEFT JOIN staff s ON s.id = si.inspector_staff_id
        LEFT JOIN users u ON u.id = s.user_id
        WHERE si.id = :id LIMIT 1");
    $printStmt->execute(['id' => $printId]);
    $report = $printStmt->fetch(PDO::FETCH_ASSOC);
    if (!$report) {
        http_response_code(404);
        exit('Inspection report not found.');
    }
    ?><!doctype html>
    <html>

    <head>
        <meta charset="utf-8">
        <title>Inspection Report <?= e('SI' . $report['id']) ?></title>
        <style>
            body {
                font-family: Arial, sans-serif;
                color: #172033;
                margin: 40px
            }

            .print-button {
                margin-bottom: 18px;
                border: 0;
                border-radius: 8px;
                background: #059669;
                color: #fff;
                padding: 10px 16px;
                font-weight: 700;
                cursor: pointer
            }

            .head {
                display: grid;
                grid-template-columns: 92px 1fr;
                gap: 12px;
                align-items: center;
                border-bottom: 3px solid #0f766e;
                padding-bottom: 16px;
                margin-bottom: 24px
            }

            .grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 12px
            }

            .box {
                border: 1px solid #cbd5e1;
                border-radius: 8px;
                padding: 12px
            }

            .full {
                grid-column: 1/-1
            }

            .label {
                font-size: 11px;
                text-transform: uppercase;
                color: #64748b;
                font-weight: bold
            }

            .value {
                margin-top: 5px;
                font-size: 14px
            }

            .sign {
                margin-top: 70px;
                width: 260px;
                border-top: 1px solid #334155;
                padding-top: 6px;
                text-align: center
            }

            @media print {
                .print-button {
                    display: none
                }
            }
        </style>
    </head>

    <body>
        <button class="print-button" onclick="window.print()">Print this report</button>
        <div class="head">
            <img src="nasugbu_seal.png" alt="Seal" style="width:82px;height:82px;object-fit:contain"
                onerror="this.style.display='none'">
            <div>
                <p
                    style="margin:0;color:#475569;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase">
                    Municipality of Nasugbu · Rural Health Unit I</p>
                <h1 style="margin:0;color:#0f766e;font-size:25px">Sanitation Inspection Report</h1>
                <p style="margin:7px 0 0;color:#475569">Report <?= e('SI' . $report['id']) ?></p>
            </div>
        </div>
        <div class="grid">
            <div class="box">
                <div class="label">Establishment</div>
                <div class="value"><?= e($report['establishment']) ?></div>
            </div>
            <div class="box">
                <div class="label">Barangay</div>
                <div class="value"><?= e($report['barangay']) ?></div>
            </div>
            <div class="box">
                <div class="label">Inspection Date</div>
                <div class="value"><?= e($report['inspection_date']) ?></div>
            </div>
            <div class="box">
                <div class="label">Next Inspection</div>
                <div class="value"><?= e($report['next_inspection_date'] ?: 'Not scheduled') ?></div>
            </div>
            <div class="box">
                <div class="label">Status</div>
                <div class="value"><?= e($report['status']) ?></div>
            </div>
            <div class="box">
                <div class="label">Compliance</div>
                <div class="value"><?= e($report['compliance_rate']) ?>% · <?= (int) $report['violations'] ?> violation(s)
                </div>
            </div>
            <div class="box full">
                <div class="label">Findings</div>
                <div class="value"><?= nl2br(e($report['findings'] ?: 'No findings recorded.')) ?></div>
            </div>
        </div>
        <div class="sign"><?= e($report['inspector_name'] ?: 'Sanitary Inspector') ?><br><small>Inspecting Officer</small>
        </div>
        <script>window.addEventListener('load', () => window.print());</script>
    </body>

    </html>
    <?php exit;
}


$tabs = [
    'overview' => ['Overview', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>'],
    'inspections' => ['Inspections', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>'],
    'water' => ['Water Quality', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z"/></svg>'],
    'food' => ['Food Safety', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2"/><path d="M7 2v20"/><path d="M21 15V2a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3Zm0 0v7"/></svg>'],
    'waste' => ['Waste Mgmt', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>'],
    'vector' => ['Vector Control', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="m8 12 2 2 4-4"/></svg>'],
    'violations' => ['Violations', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>'],
    'community' => ['Community', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>'],
    'reports' => ['Reports', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/></svg>'],
];
$tab = $_GET['tab'] ?? 'overview';
if (!isset($tabs[$tab]))
    $tab = 'overview';
$modal = $_GET['modal'] ?? '';

$residentOptions = $staffOptions = [];
$inspections = $schedules = $waterSamples = $foodInspections = [];
$wasteRecords = $vectorActivities = $notices = $campaigns = [];
$sanitaryConsultations = [];

if (!empty($pdo)) {
    try {
        $residentOptions = $pdo->query("SELECT id, CONCAT(first_name,' ',last_name) name FROM residents ORDER BY first_name, last_name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $staffOptions = $pdo->query("SELECT id, CONCAT(first_name,' ',last_name) name FROM health_workers ORDER BY first_name, last_name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $dbBrgy = $pdo->query("SELECT name FROM barangays ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($dbBrgy as $_b) {
            $_b = trim((string) $_b);
            if ($_b !== '' && !in_array($_b, $barangayOptions, true))
                $barangayOptions[] = $_b;
        }
        sort($barangayOptions, SORT_NATURAL | SORT_FLAG_CASE);
    } catch (Throwable $e) {
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'answer_consultation') {
                $cslId = (int) ($_POST['consultation_id'] ?? 0);
                $resId = (int) ($_POST['resident_id'] ?? 0);
                if ($cslId > 0) {
                    $pdo->prepare("UPDATE consultations SET diagnosis=?, remarks=?, updated_at=NOW() WHERE id=?")
                        ->execute([trim($_POST['diagnosis'] ?? ''), trim($_POST['consultation_notes'] ?? '') . "\nStatus: " . trim($_POST['consultation_status'] ?? 'Completed'), $cslId]);
                    portalSaveHealthRecordEntry($pdo, $resId, [
                        'record_type' => 'Sanitary consultation response',
                        'diagnosis' => trim($_POST['diagnosis'] ?? ''),
                        'notes' => trim($_POST['consultation_notes'] ?? ''),
                        'status' => trim($_POST['consultation_status'] ?? 'Completed'),
                        'recorded_by_id' => $sanitaryStaffId ?: null,
                    ]);
                    if ($resId > 0) {
                        portalNotifyResident($pdo, $resId, 'Your Sanitary request was updated. Status: ' . trim($_POST['consultation_status'] ?? ''), 'ResidentDashboard.php?tab=appointments');
                    }
                    $_SESSION['sanitary_flash_success'] = 'Consultation updated and resident notified.';
                }
                header('Location: ' . tabUrl('overview'));
                exit;
            }
            if ($action === 'save_schedule') {
                sanitaryRecordEvent($pdo, 'Sanitation Schedule: ' . trim($_POST['target_name'] ?? ''), trim($_POST['barangay'] ?? ''), $_POST['scheduled_date'] ?? date('Y-m-d'), trim($_POST['target_name'] ?? ''), 'Type: ' . trim($_POST['target_type'] ?? 'Food Establishment') . "\nPriority: " . trim($_POST['priority'] ?? 'Normal') . "\nTime: " . trim($_POST['scheduled_time'] ?? '') . "\nNotes: " . trim($_POST['notes'] ?? ''), $sanitaryStaffId);
                $_SESSION['sanitary_flash_success'] = 'Inspection scheduled.';
                header('Location: ' . tabUrl('inspections'));
                exit;
            }
            if ($action === 'save_sanitary_inspection') {
                sanitaryRecordEvent($pdo, 'Sanitary Inspection: ' . trim($_POST['establishment'] ?? ''), trim($_POST['barangay'] ?? ''), $_POST['inspection_date'] ?? date('Y-m-d'), trim($_POST['establishment'] ?? ''), 'Status: ' . trim($_POST['status'] ?? 'Compliant') . "\nCompliance: " . trim($_POST['compliance_rate'] ?? '0') . "%\nViolations: " . trim($_POST['violations'] ?? '0') . "\nFindings: " . trim($_POST['findings'] ?? '') . "\nNext inspection: " . trim($_POST['next_inspection_date'] ?? ''), (int) ($_POST['inspector_staff_id'] ?? 0) ?: $sanitaryStaffId);
                $_SESSION['sanitary_flash_success'] = 'Inspection saved.';
                header('Location: ' . tabUrl('inspections'));
                exit;
            }
            if ($action === 'save_water_sample') {
                sanitaryRecordEvent($pdo, 'Water Quality Sample: ' . trim($_POST['source_name'] ?? ''), trim($_POST['barangay'] ?? ''), $_POST['sample_date'] ?? date('Y-m-d'), trim($_POST['source_name'] ?? ''), 'Type: ' . trim($_POST['source_type'] ?? 'Well') . "\nStatus: " . trim($_POST['overall_status'] ?? 'Safe') . "\npH: " . trim($_POST['ph_level'] ?? '') . "\nTurbidity: " . trim($_POST['turbidity'] ?? '') . "\nColiform: " . trim($_POST['coliform_result'] ?? '') . "\nRemarks: " . trim($_POST['remarks'] ?? ''), $sanitaryStaffId);
                $_SESSION['sanitary_flash_success'] = 'Water sample logged.';
                header('Location: ' . tabUrl('water'));
                exit;
            }
            if ($action === 'save_food_inspection') {
                sanitaryRecordEvent($pdo, 'Food Safety Inspection: ' . trim($_POST['establishment'] ?? ''), trim($_POST['barangay'] ?? ''), $_POST['inspection_date'] ?? date('Y-m-d'), trim($_POST['establishment'] ?? ''), 'Type: ' . trim($_POST['establishment_type'] ?? 'Eatery') . "\nCompliance: " . trim($_POST['compliance_status'] ?? 'Compliant') . "\nScore: " . trim($_POST['score'] ?? '0') . "\nFindings: " . trim($_POST['findings'] ?? '') . "\nNext inspection: " . trim($_POST['next_inspection_date'] ?? ''), $sanitaryStaffId);
                $_SESSION['sanitary_flash_success'] = 'Food inspection saved.';
                header('Location: ' . tabUrl('food'));
                exit;
            }
            if ($action === 'save_waste_record') {
                sanitaryRecordEvent($pdo, 'Waste Management: ' . trim($_POST['record_type'] ?? 'Garbage Collection'), trim($_POST['barangay'] ?? ''), $_POST['activity_date'] ?? date('Y-m-d'), trim($_POST['location'] ?? ''), 'Status: ' . trim($_POST['status'] ?? 'Completed') . "\nVolume: " . trim($_POST['volume_estimate'] ?? '') . "\nNotes: " . trim($_POST['notes'] ?? ''), $sanitaryStaffId);
                $_SESSION['sanitary_flash_success'] = 'Waste record saved.';
                header('Location: ' . tabUrl('waste'));
                exit;
            }
            if ($action === 'save_vector_activity') {
                sanitaryRecordEvent($pdo, 'Vector Control: ' . trim($_POST['activity_type'] ?? 'Breeding Site Inspection'), trim($_POST['barangay'] ?? ''), $_POST['activity_date'] ?? date('Y-m-d'), trim($_POST['location'] ?? ''), 'Status: ' . trim($_POST['status'] ?? 'Completed') . "\nSites found: " . trim($_POST['sites_found'] ?? '0') . "\nSites treated: " . trim($_POST['sites_treated'] ?? '0') . "\nMethod: " . trim($_POST['method_used'] ?? '') . "\nNotes: " . trim($_POST['notes'] ?? ''), $sanitaryStaffId);
                $_SESSION['sanitary_flash_success'] = 'Vector activity recorded.';
                header('Location: ' . tabUrl('vector'));
                exit;
            }
            if ($action === 'issue_notice') {
                $violations = (int) ($_POST['violations'] ?? 0);
                if ($violations <= 0)
                    throw new RuntimeException('A notice requires at least one violation.');
                $noticeNumber = 'SN-' . date('Ymd') . '-' . str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT);
                $pdo->prepare("INSERT INTO audit_logs (health_worker_id, action, module_name, description, ip_address, user_agent, created_at) VALUES (?, 'Sanitary Notice Issued', 'Sanitary', ?, ?, ?, NOW())")
                    ->execute([$sanitaryStaffId ?: null, $noticeNumber . "\nEstablishment: " . trim($_POST['establishment'] ?? '') . "\nBarangay: " . trim($_POST['barangay'] ?? '') . "\nViolations: " . $violations . "\nFindings: " . trim($_POST['findings'] ?? '') . "\nFollow-up: " . trim($_POST['follow_up_date'] ?? ''), $_SERVER['REMOTE_ADDR'] ?? null, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]);
                $_SESSION['sanitary_flash_success'] = "Notice {$noticeNumber} issued.";
                header('Location: ' . tabUrl('violations'));
                exit;
            }
            if ($action === 'update_notice_status') {
                portalAudit($pdo, $sanitaryUserId, 'Updated sanitary notice status to ' . trim($_POST['notice_status'] ?? 'Pending'), 'Sanitary', (int) ($_POST['notice_id'] ?? 0));
                $_SESSION['sanitary_flash_success'] = 'Notice status updated.';
                header('Location: ' . tabUrl('violations'));
                exit;
            }
            if ($action === 'save_campaign') {
                sanitaryRecordEvent($pdo, trim($_POST['title'] ?? 'Community Campaign'), trim($_POST['barangay'] ?? ''), $_POST['event_date'] ?? date('Y-m-d'), trim($_POST['venue'] ?? ''), 'Type: ' . trim($_POST['campaign_type'] ?? 'Hygiene Awareness') . "\nAudience: " . trim($_POST['audience_size'] ?? '') . "\nMaterials: " . trim($_POST['materials'] ?? '') . "\nStatus: " . trim($_POST['status'] ?? 'Scheduled') . "\nNotes: " . trim($_POST['notes'] ?? ''), $sanitaryStaffId);
                $_SESSION['sanitary_flash_success'] = 'Campaign saved.';
                header('Location: ' . tabUrl('community'));
                exit;
            }
            if ($action === 'issue_inspection_notice') {
                $inspectionId = (int) ($_POST['inspection_id'] ?? 0);
                $noticeNumber = 'SN-' . date('Ymd') . '-' . str_pad((string) max(1, $inspectionId), 5, '0', STR_PAD_LEFT);
                portalAudit($pdo, $sanitaryUserId, "Issued inspection notice {$noticeNumber}", 'Sanitary', $inspectionId);
                $_SESSION['sanitary_flash_success'] = "Notice {$noticeNumber} issued.";
                header('Location: ' . tabUrl('violations'));
                exit;
            }
        } catch (Throwable $postErr) {
            $_SESSION['sanitary_flash_error'] = $postErr->getMessage();
            header('Location: ' . tabUrl($tab));
            exit;
        }
    }

    try {
        $sanStmt = $pdo->prepare("SELECT c.id,c.resident_id,CONCAT(r.first_name,' ',r.last_name) AS patientName,TIMESTAMPDIFF(YEAR,r.birthdate,CURDATE()) as age,r.sex as gender,c.chief_complaint as chiefComplaint,c.diagnosis,COALESCE(b.name,'Unassigned') as barangay,c.consultation_date as date,c.remarks as consultation_notes,CASE WHEN c.diagnosis IS NOT NULL AND c.diagnosis <> '' THEN 'Completed' ELSE 'In Progress' END AS consultation_status
            FROM consultations c JOIN residents r ON c.resident_id=r.id
            LEFT JOIN barangays b ON b.id = r.barangay_id
            WHERE (c.health_worker_id=:sid_hw OR c.physician_id=:sid_phy)
            ORDER BY c.id DESC LIMIT 40");
        $sanStmt->execute(['sid_hw' => $sanitaryStaffId, 'sid_phy' => $sanitaryStaffId]);
        $sanitaryConsultations = $sanStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
    }

    try {
        $eventRows = $pdo->query("SELECT e.*, b.name AS barangay FROM events e LEFT JOIN barangays b ON b.id = e.barangay_id WHERE e.title LIKE 'Sanitary Inspection:%' OR e.title LIKE 'Sanitation Schedule:%' OR e.title LIKE 'Water Quality Sample:%' OR e.title LIKE 'Food Safety Inspection:%' OR e.title LIKE 'Waste Management:%' OR e.title LIKE 'Vector Control:%' OR e.title LIKE '%Campaign%' ORDER BY e.start_datetime DESC, e.id DESC LIMIT 120")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($eventRows as $row) {
            $title = (string) ($row['title'] ?? '');
            $notes = (string) ($row['description'] ?? '');
            $raw = strtolower($notes);
            $date = substr((string) ($row['start_datetime'] ?? date('Y-m-d')), 0, 10);
            $name = preg_replace('/^[^:]+:\s*/', '', $title);
            $barangay = (string) ($row['barangay'] ?? '');
            $status = (str_contains($raw, 'compliant') && !str_contains($raw, 'non')) ? 'passed' : ((str_contains($raw, 'conditional') || str_contains($raw, 'follow')) ? 'conditional' : 'failed');
            if (str_starts_with($title, 'Sanitary Inspection:') || str_starts_with($title, 'Food Safety Inspection:')) {
                $inspections[] = ['EV' . $row['id'], $name, 'Establishment', $barangay, $date, 'See event notes', $status, $status === 'passed' ? 100 : 0, 0, array_values(array_filter(array_map('trim', preg_split('/[\r\n;]+/', $notes)))), $_SESSION['rhu_staff_login']['name'] ?? 'Sanitary Inspector', (int) $row['id']];
            }
            if (str_starts_with($title, 'Sanitation Schedule:')) $schedules[] = ['id' => $row['id'], 'target_name' => $name, 'target_type' => 'Sanitation', 'barangay' => $barangay, 'scheduled_date' => $date, 'scheduled_time' => substr((string) ($row['start_datetime'] ?? ''), 11, 5), 'priority' => 'Normal', 'schedule_status' => $row['status'] ?? 'Scheduled', 'notes' => $notes];
            if (str_starts_with($title, 'Water Quality Sample:')) $waterSamples[] = ['id' => $row['id'], 'source_name' => $name, 'source_type' => 'Water Source', 'barangay' => $barangay, 'sample_date' => $date, 'overall_status' => str_contains($raw, 'unsafe') || str_contains($raw, 'contaminated') ? 'Unsafe' : 'Recorded', 'remarks' => $notes];
            if (str_starts_with($title, 'Food Safety Inspection:')) $foodInspections[] = ['id' => $row['id'], 'establishment' => $name, 'establishment_type' => 'Food Establishment', 'barangay' => $barangay, 'inspection_date' => $date, 'compliance_status' => $status === 'passed' ? 'Compliant' : 'Needs Follow-up', 'score' => 0, 'findings' => $notes];
            if (str_starts_with($title, 'Waste Management:')) $wasteRecords[] = ['id' => $row['id'], 'record_type' => $name, 'location' => $row['location'] ?? '', 'barangay' => $barangay, 'activity_date' => $date, 'status' => $row['status'] ?? 'Scheduled', 'notes' => $notes];
            if (str_starts_with($title, 'Vector Control:')) $vectorActivities[] = ['id' => $row['id'], 'activity_type' => $name, 'location' => $row['location'] ?? '', 'barangay' => $barangay, 'activity_date' => $date, 'status' => $row['status'] ?? 'Scheduled', 'notes' => $notes];
            if (str_contains(strtolower($title), 'campaign')) $campaigns[] = ['id' => $row['id'], 'title' => $title, 'campaign_type' => 'Community Campaign', 'barangay' => $barangay, 'event_date' => $date, 'venue' => $row['location'] ?? '', 'status' => $row['status'] ?? 'Scheduled', 'notes' => $notes];
        }
        $notices = $pdo->query("SELECT id, record_id AS inspection_id, CONCAT('SN-AUD-', id) AS notice_number, '' AS establishment, '' AS barangay, health_worker_id AS issued_by_staff_id, DATE(created_at) AS issued_date, 1 AS violations, description AS findings, 'Issued' AS notice_status, NULL AS follow_up_date, created_at FROM audit_logs WHERE module_name = 'Sanitary' AND action LIKE '%Notice%' ORDER BY created_at DESC, id DESC LIMIT 80")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
    }
}

$passed = count(array_filter($inspections, function ($i) { return $i[6] === 'passed'; }));
$failed = count(array_filter($inspections, function ($i) { return $i[6] === 'failed'; }));
$conditional = count(array_filter($inspections, function ($i) { return $i[6] === 'conditional'; }));
$avg = count($inspections) ? (int) round(array_sum(array_column($inspections, 7)) / count($inspections)) : 0;
$unsafeWater = count(array_filter($waterSamples, function ($w) { return stripos((string) ($w['overall_status'] ?? ''), 'unsafe') !== false || stripos((string) ($w['overall_status'] ?? ''), 'contaminated') !== false; }));
$foodCompliant = count(array_filter($foodInspections, function ($f) { return stripos((string) ($f['compliance_status'] ?? ''), 'non') === false && stripos((string) ($f['compliance_status'] ?? ''), 'compliant') !== false; }));
$foodTotal = count($foodInspections);
$pendingNotices = count(array_filter($notices, function ($n) { return in_array(strtolower((string) ($n['notice_status'] ?? '')), ['pending', 'issued'], true); }));
$upcomingSchedules = array_values(array_filter($schedules, function ($s) { return ($s['scheduled_date'] ?? '') >= date('Y-m-d') && strtolower((string) ($s['schedule_status'] ?? '')) !== 'cancelled'; }));
$upcomingCampaigns = array_values(array_filter($campaigns, function ($c) { return ($c['event_date'] ?? '') >= date('Y-m-d'); }));
$btnPrimary = 'inline-flex items-center gap-1.5 rounded-xl bg-teal-600 px-4 py-2.5 text-xs font-bold text-white shadow-md shadow-teal-600/20 hover:bg-teal-700 transition';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="favicon.ico?v=20260915" type="image/x-icon">
    <link rel="shortcut icon" href="favicon.ico?v=20260915" type="image/x-icon">
    <title>Sanitary Inspector Portal — ResiHUnity RHU</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        html {
            scroll-behavior: auto
        }

        body.rhu-sanitary-ui {
            overflow: hidden;
            background: #f3f6f4;
            color: #0f172a;
            height: 100vh;
            height: 100dvh
        }

        .sanitary-sidebar {
            width: 14rem;
            background: #fff;
            border-right: 1px solid #e5ebe7;
            display: flex;
            flex-direction: column;
            height: 100vh;
            height: 100dvh;
            position: sticky;
            top: 0;
            z-index: 30;
            flex-shrink: 0;
            overflow: hidden
        }

        .sanitary-sidebar-brand {
            position: relative;
            overflow: hidden;
            min-height: 4.25rem;
            padding: .9rem 1rem;
            flex-shrink: 0
        }

        .sanitary-sidebar-brand .brand-bg {
            position: absolute;
            inset: 0;
            background-image: url('../../../assets/admin-municipal-background.png');
            background-size: cover;
            background-position: center;
            filter: saturate(1.2) brightness(.52)
        }

        .sanitary-sidebar-brand .brand-overlay {
            position: absolute;
            inset: 0;
            background: linear-gradient(100deg, rgba(13, 53, 24, .92) 0%, rgba(23, 63, 45, .82) 55%, rgba(47, 111, 73, .7) 100%)
        }

        .admin-shell-header {
            background: #0b3c35;
            border-bottom: 1px solid rgba(10, 51, 43, .9);
            box-shadow: 0 10px 28px rgba(2, 28, 23, .18);
            min-height: 5rem;
            position: sticky;
            top: 0;
            z-index: 50
        }

        .nav-section-label {
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .14em;
            text-transform: uppercase;
            color: #94a3b8;
            padding: .85rem 1.1rem .35rem
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: .75rem;
            margin: .1rem .65rem;
            padding: .65rem .85rem;
            border-radius: .85rem;
            font-size: .8125rem;
            font-weight: 600;
            color: #475569;
            transition: background .15s ease, color .15s ease
        }

        .nav-item:hover {
            background: #f0fdf6;
            color: #0f766e
        }

        .nav-item.is-active {
            background: #e8f8ef;
            color: #0b3c35;
            font-weight: 800;
            box-shadow: inset 0 0 0 1px #c6ebd4
        }

        .nav-item.is-active svg {
            color: #0f766e
        }

        .sanitary-main-wrap {
            position: relative;
            flex: 1;
            min-width: 0;
            min-height: 0;
            height: 100vh;
            height: 100dvh;
            display: flex;
            flex-direction: column;
            background: #f3f6f4;
            isolation: isolate;
            overflow: hidden
        }

        .sanitary-main-wrap::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image: url('../../../assets/admin-municipal-background.png');
            background-size: cover;
            background-position: center top;
            opacity: .06;
            pointer-events: none;
            z-index: 0
        }

        .sanitary-main-wrap>* {
            position: relative;
            z-index: 1
        }

        .sanitary-main-wrap>header.admin-shell-header {
            position: sticky;
            top: 0;
            z-index: 50;
            flex-shrink: 0
        }

        .sanitary-main-wrap>main {
            z-index: 1;
            position: relative;
            flex: 1;
            min-height: 0;
            overflow-y: auto;
            overflow-x: hidden
        }

        .dashboard-card {
            background: rgba(255, 255, 255, .95);
            transition: border-color .18s ease, box-shadow .18s ease, transform .18s ease
        }

        .dashboard-card:hover {
            border-color: #a7e0bc;
            box-shadow: 0 6px 18px rgba(11, 60, 53, .08);
            transform: translateY(-1px)
        }

        @media (prefers-reduced-motion:reduce) {
            .dashboard-card:hover {
                transform: none
            }
        }

        input:focus,
        select:focus,
        textarea:focus {
            border-color: #0d9488 !important;
            box-shadow: 0 0 0 3px rgba(13, 148, 136, .14) !important
        }

        @media (max-width:1023px) {
            .sanitary-sidebar {
                position: fixed;
                inset: 0 auto 0 0;
                z-index: 60;
                height: 100vh;
                transform: translateX(-105%);
                transition: transform .2s ease;
                box-shadow: 12px 0 40px rgba(15, 23, 42, .18)
            }

            .sanitary-sidebar.is-open {
                transform: translateX(0)
            }

            .sidebar-backdrop {
                position: fixed;
                inset: 0;
                z-index: 50;
                background: rgba(2, 6, 23, .42);
                opacity: 0;
                pointer-events: none;
                transition: opacity .15s ease
            }

            .sidebar-backdrop.is-open {
                opacity: 1;
                pointer-events: auto
            }

            body.drawer-open {
                overflow: hidden
            }
        }

        @media (min-width:1024px) {
            .sidebar-backdrop {
                display: none !important
            }

            .sanitary-sidebar {
                transform: none !important
            }
        }
    </style>
    <link rel="stylesheet" href="dashboard-enhancements.css">
</head>

<body class="rhu-sanitary-ui antialiased">
    <div class="flex h-screen max-h-screen overflow-hidden">
        <div data-drawer-backdrop class="sidebar-backdrop lg:hidden" aria-hidden="true"></div>
        <aside data-feature-drawer class="sanitary-sidebar shrink-0" aria-label="Sanitary navigation">
            <div class="sanitary-sidebar-brand">
                <div class="brand-bg" aria-hidden="true"></div>
                <div class="brand-overlay" aria-hidden="true"></div>
                <div class="relative z-10 flex items-center gap-3">
                    <span
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-white/30 bg-white shadow-md overflow-hidden">
                        <img src="../../../assets/nasugbu_seal.png" alt="Nasugbu Seal" class="h-10 w-10 object-contain"
                            onerror="this.onerror=null;this.src='nasugbu_seal.png'">
                    </span>
                    <div class="min-w-0 text-white">
                        <p class="text-[14px] font-black leading-tight tracking-tight drop-shadow-sm">RURAL HEALTH UNIT
                        </p>
                        <p class="text-[11px] font-semibold text-white/90 truncate">
                            <?= e($tabs[$tab][0] ?? 'Overview') ?></p>
                    </div>
                </div>
                <button type="button" data-drawer-close
                    class="absolute top-2.5 right-2.5 z-10 grid h-8 w-8 place-items-center rounded-full border border-white/25 bg-white/10 text-white lg:hidden"
                    aria-label="Close menu"><?= iconSvg('close', 'w-4 h-4') ?></button>
            </div>
            <nav class="flex-1 overflow-y-auto py-2 min-h-0">
<?php
if (is_file(__DIR__ . '/error_bootstrap.php')) {
    require_once __DIR__ . '/error_bootstrap.php';
}

                $drawerGroups = [
                    'Dashboard' => ['overview'],
                    'Field Operations' => ['inspections', 'water', 'food', 'waste', 'vector'],
                    'Enforcement' => ['violations'],
                    'Outreach & Analytics' => ['community', 'reports'],
                ];
                foreach ($drawerGroups as $groupLabel => $groupTabs):
                    $visible = array_values(array_filter($groupTabs, function ($k) use ($tabs) { return isset($tabs[$k]); }));
                    if (!$visible)
                        continue;
                    ?>
                    <p class="nav-section-label"><?= e($groupLabel) ?></p>
                    <?php foreach ($visible as $id):
                        [$label, $icon] = $tabs[$id];
                        $active = $tab === $id;
                        ?>
                        <a href="<?= e(tabUrl($id)) ?>" class="nav-item <?= $active ? 'is-active' : '' ?>">
                            <span class="shrink-0 opacity-90"><?= $icon ?></span>
                            <span class="truncate flex-1"><?= e($label) ?></span>
                            <?php if ($active): ?><span class="text-teal-700 text-sm font-black">→</span><?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </nav>
            <div class="border-t border-slate-100 p-3 shrink-0">
                <a href="StaffLogout.php" data-staff-logout
                    class="nav-item text-slate-500 hover:bg-rose-50 hover:text-rose-700">
                    <?= iconSvg('logout', 'w-5 h-5') ?><span>Log Out</span>
                </a>
            </div>
        </aside>

        <div class="sanitary-main-wrap">
            <header class="admin-shell-header sticky top-0 z-50 text-[#f4faf7]">
                <div class="flex h-20 items-center justify-between gap-3 px-5 sm:px-7">
                    <div class="flex items-center gap-2.5 min-w-0">
                        <button type="button" data-drawer-open
                            class="lg:hidden flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-white/15 text-white/95 hover:bg-white/10"
                            aria-label="Open menu" aria-expanded="false"><?= iconSvg('menu', 'w-4 h-4') ?></button>
                        <div class="flex items-center gap-2">
                            <span
                                class="flex h-7 w-7 items-center justify-center rounded-full border border-[#e8f3d8]/80 bg-[#dfeecb] text-[#0b3b2f]"><?= iconSvg('shield', 'w-3.5 h-3.5') ?></span>
                            <span class="text-xs font-black uppercase tracking-[0.16em] text-[#f5f5f2]">Sanitary
                                Panel</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 sm:gap-2.5">
                        <?php if (function_exists('portalRenderNotificationButton'))
                            echo portalRenderNotificationButton(); ?>
                        <div
                            class="flex items-center gap-2 rounded-full border border-[#dbeadf]/20 bg-white/10 pl-1 pr-2.5 py-1">
                            <div
                                class="flex h-9 w-9 items-center justify-center rounded-full bg-[#dceec4] text-sm font-black text-[#0b3b2f]">
                                <?= e(strtoupper(substr($_SESSION['rhu_staff_login']['name'] ?? 'S', 0, 1))) ?></div>
                            <div class="hidden sm:block text-left leading-tight pr-1">
                                <p class="text-[12px] font-bold text-white">
                                    <?= e($_SESSION['rhu_staff_login']['name'] ?? 'Sanitary Inspector') ?></p>
                                <p class="text-[9px] font-semibold uppercase tracking-wider text-[#cfe5d8]">Sanitary</p>
                            </div>
                        </div>
                        <a href="StaffLogout.php" data-staff-logout
                            class="inline-flex h-9 items-center gap-1.5 rounded-full border border-white/20 bg-[#f3faf4] px-3 text-xs font-bold text-[#0c3a32] hover:bg-white transition">
                            <?= iconSvg('logout', 'w-3.5 h-3.5') ?><span class="hidden sm:inline">Log out</span>
                        </a>
                    </div>
                </div>
            </header>

            <main class="flex-1 mx-auto w-full max-w-7xl p-4 sm:p-6 space-y-5 pb-6">
                <?php if ($sanitaryFlashSuccess): ?>
                    <div
                        class="dashboard-card rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm font-bold text-emerald-800">
                        <?= e($sanitaryFlashSuccess) ?></div><?php endif; ?>
                <?php if ($sanitaryFlashError): ?>
                    <div
                        class="dashboard-card rounded-xl border border-red-200 bg-red-50 p-3 text-sm font-bold text-red-800">
                        <?= e($sanitaryFlashError) ?></div><?php endif; ?>

                <?php if ($tab === 'overview'): ?>
                    <?php if ($failed > 0 || $unsafeWater > 0 || $pendingNotices > 0): ?>
                        <section class="dashboard-card flex flex-wrap gap-3 rounded-xl border border-red-200 bg-red-50 p-4">
                            <div class="flex-1 min-w-[200px]">
                                <b class="text-sm text-red-800">Priority alerts</b>
                                <p class="text-xs text-red-700 mt-1"><?= $failed ?> failed inspection(s) · <?= $unsafeWater ?>
                                    unsafe water sample(s) · <?= $pendingNotices ?> open notice(s)</p>
                            </div>
                            <a class="rounded-lg bg-red-600 px-3 py-1.5 text-xs text-white font-bold self-center"
                                href="<?= e(tabUrl('violations')) ?>">Review</a>
                        </section>
                    <?php endif; ?>

                                        <?php
                    $inspTotal = count($inspections);
                    $passPct = $inspTotal ? (int) round(($passed / $inspTotal) * 100) : 0;
                    $condPct = $inspTotal ? (int) round(($conditional / $inspTotal) * 100) : 0;
                    $failPct = $inspTotal ? (int) round(($failed / $inspTotal) * 100) : 0;
                    $kpiCards = [
                        [
                            'label' => 'Passed',
                            'value' => (string) $passed,
                            'sub' => $inspTotal ? "{$passPct}% of {$inspTotal}" : 'No inspections yet',
                            'icon_bg' => 'bg-emerald-100 text-emerald-700',
                            'card_bg' => 'bg-linear-to-br from-emerald-50 to-white border-emerald-100',
                            'value_cls' => 'text-emerald-700',
                            'icon' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>',
                        ],
                        [
                            'label' => 'Conditional',
                            'value' => (string) $conditional,
                            'sub' => $inspTotal ? "{$condPct}% of {$inspTotal}" : 'No inspections yet',
                            'icon_bg' => 'bg-amber-100 text-amber-700',
                            'card_bg' => 'bg-linear-to-br from-amber-50 to-white border-amber-100',
                            'value_cls' => 'text-amber-700',
                            'icon' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>',
                        ],
                        [
                            'label' => 'Failed',
                            'value' => (string) $failed,
                            'sub' => $inspTotal ? "{$failPct}% of {$inspTotal}" : 'No inspections yet',
                            'icon_bg' => 'bg-rose-100 text-rose-700',
                            'card_bg' => 'bg-linear-to-br from-rose-50 to-white border-rose-100',
                            'value_cls' => 'text-rose-700',
                            'icon' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>',
                        ],
                        [
                            'label' => 'Avg Compliance',
                            'value' => $avg . '%',
                            'sub' => $inspTotal ? "Across {$inspTotal} inspection" . ($inspTotal === 1 ? '' : 's') : 'No data yet',
                            'icon_bg' => 'bg-teal-100 text-teal-700',
                            'card_bg' => 'bg-linear-to-br from-teal-50 to-white border-teal-100',
                            'value_cls' => 'text-teal-700',
                            'icon' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/></svg>',
                        ],
                    ];
                    ?>
                    <section class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                        <?php foreach ($kpiCards as $kpi): ?>
                            <div class="dashboard-card rounded-2xl border <?= $kpi['card_bg'] ?> p-4 shadow-sm">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="text-[11px] font-bold uppercase tracking-wide text-slate-500"><?= e($kpi['label']) ?></p>
                                        <p class="mt-1 text-2xl font-black tabular-nums <?= $kpi['value_cls'] ?>"><?= e($kpi['value']) ?></p>
                                        <p class="mt-1 text-[11px] font-medium text-slate-500 truncate"><?= e($kpi['sub']) ?></p>
                                    </div>
                                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl <?= $kpi['icon_bg'] ?>">
                                        <?= $kpi['icon'] ?>
                                    </span>
                                </div>
                                <?php if ($kpi['label'] === 'Avg Compliance' && $inspTotal > 0): ?>
                                    <div class="mt-3 h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
                                        <div class="h-1.5 rounded-full bg-teal-500 transition-all" style="width: <?= max(0, min(100, (int)$avg)) ?>%"></div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </section>

                    <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <div class="dashboard-card rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                            <div class="flex items-center justify-between mb-3">
                                <h2 class="font-bold text-sm text-slate-900">Upcoming inspections</h2>
                                <a href="<?= e(tabUrl('inspections', ['modal' => 'schedule'])) ?>"
                                    class="text-[11px] font-bold text-teal-700">Schedule</a>
                            </div>
                            <?php if (!$upcomingSchedules): ?>
                                <p class="text-xs text-slate-400 italic">No upcoming scheduled inspections.</p>
                            <?php else:
                                foreach (array_slice($upcomingSchedules, 0, 5) as $s): ?>
                                    <div class="mb-2 flex justify-between rounded-lg bg-slate-50 p-2.5 text-xs">
                                        <div><b class="block text-slate-900"><?= e($s['target_name']) ?></b><span
                                                class="text-slate-500"><?= e($s['target_type']) ?> ·
                                                <?= e($s['barangay'] ?: '—') ?></span></div>
                                        <div class="text-right"><b class="text-teal-700"><?= e($s['scheduled_date']) ?></b><span
                                                class="block text-slate-400"><?= e($s['scheduled_time'] ?: '') ?></span></div>
                                    </div>
                                <?php endforeach; endif; ?>
                        </div>
                        <div class="dashboard-card rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                            <div class="flex items-center justify-between mb-3">
                                <h2 class="font-bold text-sm text-slate-900">Water quality</h2><a
                                    href="<?= e(tabUrl('water')) ?>" class="text-[11px] font-bold text-teal-700">View
                                    all</a>
                            </div>
                            <p class="text-2xl font-black <?= $unsafeWater ? 'text-red-600' : 'text-emerald-600' ?>">
                                <?= $unsafeWater ?></p>
                            <p class="text-xs text-slate-500">Unsafe / contaminated samples</p>
                            <p class="mt-2 text-xs text-slate-600"><?= count($waterSamples) ?> total logged samples</p>
                        </div>
                        <div class="dashboard-card rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                            <div class="flex items-center justify-between mb-3">
                                <h2 class="font-bold text-sm text-slate-900">Food safety</h2><a
                                    href="<?= e(tabUrl('food')) ?>" class="text-[11px] font-bold text-teal-700">View all</a>
                            </div>
                            <?php $foodPct = $foodTotal ? (int) round(($foodCompliant / $foodTotal) * 100) : 0; ?>
                            <p class="text-2xl font-black text-teal-700"><?= $foodPct ?>%</p>
                            <p class="text-xs text-slate-500"><?= $foodCompliant ?> / <?= $foodTotal ?> compliant</p>
                            <div class="mt-3 h-2 rounded-full bg-slate-100 overflow-hidden">
                                <div class="h-2 rounded-full bg-teal-500" style="width:<?= $foodPct ?>%"></div>
                            </div>
                        </div>
                        <div class="dashboard-card rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                            <h2 class="font-bold text-sm text-slate-900 mb-3">Waste & vector</h2>
                            <div class="space-y-2 text-xs">
                                <div class="flex justify-between"><span class="text-slate-600">Waste
                                        records</span><b><?= count($wasteRecords) ?></b></div>
                                <div class="flex justify-between"><span class="text-slate-600">Vector
                                        activities</span><b><?= count($vectorActivities) ?></b></div>
                                <div class="flex justify-between"><span class="text-slate-600">Open notices</span><b
                                        class="<?= $pendingNotices ? 'text-red-600' : '' ?>"><?= $pendingNotices ?></b>
                                </div>
                            </div>
                        </div>
                        <div class="dashboard-card rounded-xl border border-slate-200 bg-white p-5 shadow-sm md:col-span-2">
                            <div class="flex items-center justify-between mb-3">
                                <h2 class="font-bold text-sm text-slate-900">Community education</h2><a
                                    href="<?= e(tabUrl('community')) ?>"
                                    class="text-[11px] font-bold text-teal-700">Manage</a>
                            </div>
                            <?php if (!$upcomingCampaigns): ?>
                                <p class="text-xs text-slate-400 italic">No upcoming campaigns.</p>
                            <?php else: ?>
                                <div class="grid gap-2 sm:grid-cols-2">
                                    <?php foreach (array_slice($upcomingCampaigns, 0, 4) as $c): ?>
                                        <div class="rounded-lg bg-slate-50 p-3 text-xs"><b
                                                class="block text-slate-900"><?= e($c['title']) ?></b><span
                                                class="text-slate-500"><?= e($c['event_date']) ?> ·
                                                <?= e($c['barangay'] ?: $c['venue'] ?: '—') ?></span></div>
                                    <?php endforeach; ?>
                                </div><?php endif; ?>
                        </div>
                    </section>

                    <section class="dashboard-card rounded-xl border border-teal-200 bg-white p-5 shadow-sm space-y-3">
                        <div class="flex justify-between items-center border-b border-teal-100 pb-2">
                            <h2 class="font-bold text-sm text-teal-950">Sanitary requests queue
                                (<?= count($sanitaryConsultations) ?>)</h2>
                            <span
                                class="text-[10px] font-bold bg-teal-50 text-teal-700 px-2 py-0.5 rounded-full border border-teal-200">Live</span>
                        </div>
                        <?php if (empty($sanitaryConsultations)): ?>
                            <p class="text-xs text-gray-400 italic py-2 text-center">No sanitary requests assigned yet.</p>
                        <?php else:
                            foreach ($sanitaryConsultations as $sc): ?>
                                <div class="py-3 flex flex-col space-y-2 bg-slate-50/50 p-3 rounded-xl border border-teal-100">
                                    <div class="flex items-center justify-between text-xs gap-2">
                                        <div>
                                            <p class="font-bold text-gray-900"><?= e($sc['patientName']) ?> <span
                                                    class="text-gray-500 font-normal">(<?= e($sc['age'] ?? 'N/A') ?>y ·
                                                    <?= e($sc['barangay']) ?>)</span></p>
                                            <p class="text-teal-700 font-medium"><?= e($sc['chiefComplaint']) ?></p>
                                            <p class="text-[10px] text-gray-400 font-mono">Requested: <?= e($sc['date']) ?></p>
                                        </div>
                                        <span
                                            class="px-2 py-1 bg-amber-50 text-amber-700 border border-amber-200 rounded-lg font-bold text-[10px] shrink-0"><?= e($sc['consultation_status']) ?></span>
                                    </div>
                                    <details class="group border-t border-teal-100 pt-2">
                                        <summary class="cursor-pointer text-xs font-bold text-teal-700 py-1">Answer / update
                                            response</summary>
                                        <form method="post"
                                            class="mt-2 bg-white p-3 rounded-xl border border-teal-200/70 space-y-2.5">
                                            <input type="hidden" name="action" value="answer_consultation">
                                            <input type="hidden" name="consultation_id" value="<?= (int) $sc['id'] ?>">
                                            <input type="hidden" name="resident_id" value="<?= (int) ($sc['resident_id'] ?? 0) ?>">
                                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                                <div><label
                                                        class="block text-[11px] font-bold text-gray-700 mb-0.5">Findings</label><input
                                                        type="text" name="diagnosis" value="<?= e($sc['diagnosis'] ?? '') ?>" required
                                                        class="w-full p-2 border border-gray-300 rounded-lg text-xs"></div>
                                                <div><label class="block text-[11px] font-bold text-gray-700 mb-0.5">Status</label>
                                                    <select name="consultation_status"
                                                        class="w-full p-2 border border-gray-300 rounded-lg text-xs font-bold text-teal-900">
                                                        <?php foreach (['Completed', 'In Progress', 'Scheduled', 'Referred'] as $st): ?>
                                                            <option value="<?= $st ?>"
                                                                <?= ($sc['consultation_status'] ?? '') === $st ? 'selected' : '' ?>><?= $st ?>
                                                            </option><?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>
                                            <div><label class="block text-[11px] font-bold text-gray-700 mb-0.5">Notes for
                                                    resident</label><textarea name="consultation_notes" rows="2"
                                                    class="w-full p-2 border border-gray-300 rounded-lg text-xs resize-none"><?= e($sc['consultation_notes'] ?? '') ?></textarea>
                                            </div>
                                            <div class="flex justify-end"><button type="submit"
                                                    class="px-4 py-2 bg-teal-700 hover:bg-teal-800 text-white text-xs font-extrabold rounded-lg">Save
                                                    & notify</button></div>
                                        </form>
                                    </details>
                                </div>
                            <?php endforeach; endif; ?>
                    </section>

                <?php elseif ($tab === 'inspections'): ?>
                    <div class="flex flex-wrap justify-between gap-2 items-center">
                        <div>
                            <h2 class="text-xl font-bold text-slate-900">Inspections & scheduling</h2>
                            <p class="text-xs text-slate-500">Plan and log field inspections.</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <a href="<?= e(tabUrl('inspections', ['modal' => 'schedule'])) ?>"
                                class="<?= $btnPrimary ?>">Schedule inspection</a>
                            <a href="<?= e(tabUrl('inspections', ['modal' => 'inspection'])) ?>" class="<?= $btnPrimary ?>">Log
                                inspection</a>
                        </div>
                    </div>
                    <section class="dashboard-card rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                        <h3 class="font-bold text-sm text-slate-900 mb-3">Upcoming schedule</h3>
                        <?php if (!$upcomingSchedules): ?>
                            <p class="text-xs text-slate-400 italic">No scheduled inspections yet.</p>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="w-full min-w-[640px] text-sm">
                                    <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                                        <tr>
                                            <th class="p-3">Date</th>
                                            <th>Target</th>
                                            <th>Type</th>
                                            <th>Barangay</th>
                                            <th>Priority</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y"><?php foreach ($upcomingSchedules as $s): ?>
                                            <tr class="hover:bg-slate-50/80">
                                                <td class="p-3 font-mono text-xs">
                                                    <?= e($s['scheduled_date']) ?>            <?= $s['scheduled_time'] ? ' ' . e($s['scheduled_time']) : '' ?>
                                                </td>
                                                <td class="font-semibold"><?= e($s['target_name']) ?></td>
                                                <td><?= e($s['target_type']) ?></td>
                                                <td><?= e($s['barangay'] ?: '—') ?></td>
                                                <td><span
                                                        class="rounded-full px-2 py-0.5 text-[11px] font-bold <?= strtolower($s['priority'] ?? '') === 'high' ? 'bg-red-100 text-red-700' : 'bg-slate-100 text-slate-600' ?>"><?= e($s['priority']) ?></span>
                                                </td>
                                                <td><?= e($s['schedule_status']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </section>
                    <div class="space-y-3">
                        <h3 class="font-bold text-sm text-slate-900">Logged findings</h3>
                        <?php if (!$inspections): ?>
                            <p class="text-xs text-slate-400 italic">No inspection records yet.</p><?php endif; ?>
                        <?php foreach ($inspections as $i):
                            $color = $i[6] === 'passed' ? 'green' : ($i[6] === 'conditional' ? 'yellow' : 'red'); ?>
                            <article
                                class="dashboard-card rounded-xl border-l-4 border-<?= $color ?>-400 bg-white p-5 shadow-sm">
                                <div class="flex flex-wrap justify-between gap-2">
                                    <div><b><?= e($i[1]) ?></b>
                                        <p class="text-xs text-gray-500"><?= e($i[3]) ?> · Inspector: <?= e($i[10]) ?></p>
                                        <p class="text-xs text-gray-400">Inspected: <?= e($i[4]) ?> · Next: <?= e($i[5]) ?></p>
                                    </div>
                                    <div class="text-right"><span
                                            class="rounded-full bg-<?= $color ?>-100 px-2 py-1 text-xs font-bold text-<?= $color ?>-700"><?= strtoupper($i[6]) ?></span><b
                                            class="mt-1 block text-lg text-<?= $color ?>-600"><?= $i[7] ?>%</b><small
                                            class="text-gray-400">compliance</small></div>
                                </div>
                                <div class="mt-3 h-2 rounded-full bg-gray-100"><i
                                        class="block h-2 rounded-full bg-<?= $color ?>-500"
                                        style="width:<?= max(0, min(100, $i[7])) ?>%"></i></div>
                                <p class="mt-3 text-xs font-bold text-gray-500">Findings (<?= $i[8] ?>
                                    violation<?= $i[8] === 1 ? '' : 's' ?>)</p>
                                <?php foreach ($i[9] as $finding): ?>
                                    <p class="mt-1 text-xs text-gray-600">• <?= e($finding) ?></p><?php endforeach; ?>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <?php if ($i[8] > 0): ?>
                                        <form method="post" class="inline"
                                            onsubmit="return confirm('Issue a formal sanitation notice?')">
                                            <input type="hidden" name="action" value="issue_inspection_notice">
                                            <input type="hidden" name="inspection_id" value="<?= (int) $i[11] ?>">
                                            <button type="submit"
                                                class="rounded-lg bg-teal-600 px-3 py-1.5 text-xs text-white hover:bg-teal-700">Issue
                                                notice</button>
                                        </form>
                                    <?php endif; ?>
                                    <a href="?print_inspection=<?= (int) $i[11] ?>" target="_blank" rel="noopener"
                                        class="inline-block rounded-lg border border-gray-300 px-3 py-1.5 text-xs text-gray-600 hover:bg-gray-50">Print
                                        report</a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>

                <?php elseif ($tab === 'water'): ?>
                    <div class="flex flex-wrap justify-between gap-2 items-center">
                        <div>
                            <h2 class="text-xl font-bold text-slate-900">Water quality monitoring</h2>
                            <p class="text-xs text-slate-500">Log sample results and track contamination alerts.</p>
                        </div>
                        <a href="<?= e(tabUrl('water', ['modal' => 'water'])) ?>" class="<?= $btnPrimary ?>">Log sample</a>
                    </div>
                    <?php if ($unsafeWater > 0): ?>
                        <div
                            class="dashboard-card rounded-xl border border-red-200 bg-red-50 p-3 text-sm font-bold text-red-800">
                            <?= $unsafeWater ?> sample(s) flagged unsafe / contaminated.</div><?php endif; ?>
                    <div class="dashboard-card overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                        <table class="w-full min-w-[800px] text-sm">
                            <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                                <tr>
                                    <th class="p-3">Date</th>
                                    <th>Source</th>
                                    <th>Type</th>
                                    <th>Barangay</th>
                                    <th>pH</th>
                                    <th>Turbidity</th>
                                    <th>Coliform</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <?php if (!$waterSamples): ?>
                                    <tr>
                                        <td colspan="8" class="p-6 text-center text-xs text-slate-400 italic">No water samples
                                            logged yet.</td>
                                    </tr><?php endif; ?>
                                <?php foreach ($waterSamples as $w):
                                    $bad = stripos((string) $w['overall_status'], 'unsafe') !== false || stripos((string) $w['overall_status'], 'contaminated') !== false; ?>
                                    <tr class="hover:bg-slate-50/80 <?= $bad ? 'bg-red-50/40' : '' ?>">
                                        <td class="p-3 font-mono text-xs"><?= e($w['sample_date']) ?></td>
                                        <td class="font-semibold"><?= e($w['source_name']) ?></td>
                                        <td><?= e($w['source_type']) ?></td>
                                        <td><?= e($w['barangay'] ?: '—') ?></td>
                                        <td><?= e($w['ph_level'] ?? '—') ?></td>
                                        <td><?= e($w['turbidity'] ?? '—') ?></td>
                                        <td><?= e($w['coliform_result'] ?: '—') ?></td>
                                        <td><span
                                                class="rounded-full px-2 py-0.5 text-[11px] font-bold <?= $bad ? 'bg-red-100 text-red-700' : 'bg-emerald-100 text-emerald-700' ?>"><?= e($w['overall_status']) ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                <?php elseif ($tab === 'food'): ?>
                    <div class="flex flex-wrap justify-between gap-2 items-center">
                        <div>
                            <h2 class="text-xl font-bold text-slate-900">Food safety reports</h2>
                            <p class="text-xs text-slate-500">Eateries, markets, and food vendors.</p>
                        </div>
                        <a href="<?= e(tabUrl('food', ['modal' => 'food'])) ?>" class="<?= $btnPrimary ?>">New food
                            inspection</a>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="dashboard-card rounded-xl border bg-emerald-50 p-4"><b
                                class="text-2xl text-emerald-700"><?= $foodCompliant ?></b>
                            <p class="text-xs font-bold">Compliant</p>
                        </div>
                        <div class="dashboard-card rounded-xl border bg-red-50 p-4"><b
                                class="text-2xl text-red-700"><?= max(0, $foodTotal - $foodCompliant) ?></b>
                            <p class="text-xs font-bold">Non-compliant</p>
                        </div>
                        <div class="dashboard-card rounded-xl border bg-teal-50 p-4"><b
                                class="text-2xl text-teal-700"><?= $foodTotal ? (int) round(($foodCompliant / $foodTotal) * 100) : 0 ?>%</b>
                            <p class="text-xs font-bold">Compliance rate</p>
                        </div>
                    </div>
                    <div class="dashboard-card overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                        <table class="w-full min-w-[720px] text-sm">
                            <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                                <tr>
                                    <th class="p-3">Date</th>
                                    <th>Establishment</th>
                                    <th>Type</th>
                                    <th>Barangay</th>
                                    <th>Score</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <?php if (!$foodInspections): ?>
                                    <tr>
                                        <td colspan="6" class="p-6 text-center text-xs text-slate-400 italic">No food
                                            inspections yet.</td>
                                    </tr><?php endif; ?>
                                <?php foreach ($foodInspections as $f):
                                    $ok = stripos((string) $f['compliance_status'], 'non') === false; ?>
                                    <tr class="hover:bg-slate-50/80">
                                        <td class="p-3 font-mono text-xs"><?= e($f['inspection_date']) ?></td>
                                        <td class="font-semibold"><?= e($f['establishment']) ?></td>
                                        <td><?= e($f['establishment_type']) ?></td>
                                        <td><?= e($f['barangay'] ?: '—') ?></td>
                                        <td><?= (int) $f['score'] ?></td>
                                        <td><span
                                                class="rounded-full px-2 py-0.5 text-[11px] font-bold <?= $ok ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700' ?>"><?= e($f['compliance_status']) ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                <?php elseif ($tab === 'waste'): ?>
                    <div class="flex flex-wrap justify-between gap-2 items-center">
                        <div>
                            <h2 class="text-xl font-bold text-slate-900">Waste management</h2>
                            <p class="text-xs text-slate-500">Garbage, sewage, and sanitation projects.</p>
                        </div>
                        <a href="<?= e(tabUrl('waste', ['modal' => 'waste'])) ?>" class="<?= $btnPrimary ?>">Add record</a>
                    </div>
                    <div class="dashboard-card overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                        <table class="w-full min-w-[700px] text-sm">
                            <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                                <tr>
                                    <th class="p-3">Date</th>
                                    <th>Type</th>
                                    <th>Location</th>
                                    <th>Barangay</th>
                                    <th>Volume</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <?php if (!$wasteRecords): ?>
                                    <tr>
                                        <td colspan="6" class="p-6 text-center text-xs text-slate-400 italic">No waste records
                                            yet.</td>
                                    </tr><?php endif; ?>
                                <?php foreach ($wasteRecords as $w): ?>
                                    <tr class="hover:bg-slate-50/80">
                                        <td class="p-3 font-mono text-xs"><?= e($w['activity_date']) ?></td>
                                        <td class="font-semibold"><?= e($w['record_type']) ?></td>
                                        <td><?= e($w['location']) ?></td>
                                        <td><?= e($w['barangay'] ?: '—') ?></td>
                                        <td><?= e($w['volume_estimate'] ?: '—') ?></td>
                                        <td><?= e($w['status']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                <?php elseif ($tab === 'vector'): ?>
                    <div class="flex flex-wrap justify-between gap-2 items-center">
                        <div>
                            <h2 class="text-xl font-bold text-slate-900">Vector control</h2>
                            <p class="text-xs text-slate-500">Breeding sites, fumigation, pest control.</p>
                        </div>
                        <a href="<?= e(tabUrl('vector', ['modal' => 'vector'])) ?>" class="<?= $btnPrimary ?>">Log activity</a>
                    </div>
                    <div class="dashboard-card overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                        <table class="w-full min-w-[760px] text-sm">
                            <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                                <tr>
                                    <th class="p-3">Date</th>
                                    <th>Activity</th>
                                    <th>Location</th>
                                    <th>Barangay</th>
                                    <th>Found</th>
                                    <th>Treated</th>
                                    <th>Method</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <?php if (!$vectorActivities): ?>
                                    <tr>
                                        <td colspan="8" class="p-6 text-center text-xs text-slate-400 italic">No vector
                                            activities yet.</td>
                                    </tr><?php endif; ?>
                                <?php foreach ($vectorActivities as $v): ?>
                                    <tr class="hover:bg-slate-50/80">
                                        <td class="p-3 font-mono text-xs"><?= e($v['activity_date']) ?></td>
                                        <td class="font-semibold"><?= e($v['activity_type']) ?></td>
                                        <td><?= e($v['location']) ?></td>
                                        <td><?= e($v['barangay'] ?: '—') ?></td>
                                        <td><?= (int) $v['sites_found'] ?></td>
                                        <td><?= (int) $v['sites_treated'] ?></td>
                                        <td><?= e($v['method_used'] ?: '—') ?></td>
                                        <td><?= e($v['status']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                <?php elseif ($tab === 'violations'): ?>
                    <div class="flex flex-wrap justify-between gap-2 items-center">
                        <div>
                            <h2 class="text-xl font-bold text-slate-900">Violation notices</h2>
                            <p class="text-xs text-slate-500">Issue, track, and resolve non-compliance notices.</p>
                        </div>
                        <a href="<?= e(tabUrl('violations', ['modal' => 'notice'])) ?>" class="<?= $btnPrimary ?>">Issue
                            notice</a>
                    </div>
                    <div class="dashboard-card overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                        <table class="w-full min-w-[800px] text-sm">
                            <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                                <tr>
                                    <th class="p-3">Notice #</th>
                                    <th>Establishment</th>
                                    <th>Barangay</th>
                                    <th>Issued</th>
                                    <th>Violations</th>
                                    <th>Status</th>
                                    <th>Follow-up</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <?php if (!$notices): ?>
                                    <tr>
                                        <td colspan="8" class="p-6 text-center text-xs text-slate-400 italic">No notices yet.
                                        </td>
                                    </tr><?php endif; ?>
                                <?php foreach ($notices as $n):
                                    $st = strtolower((string) ($n['notice_status'] ?? 'pending'));
                                    $stClass = $st === 'resolved' ? 'bg-emerald-100 text-emerald-700' : (($st === 'pending' || $st === 'issued') ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-700'); ?>
                                    <tr class="hover:bg-slate-50/80">
                                        <td class="p-3 font-mono text-xs font-bold"><?= e($n['notice_number'] ?? '—') ?></td>
                                        <td class="font-semibold"><?= e($n['establishment'] ?? '—') ?></td>
                                        <td><?= e($n['barangay'] ?? '—') ?></td>
                                        <td class="font-mono text-xs"><?= e($n['issued_date'] ?? '—') ?></td>
                                        <td><?= (int) $n['violations'] ?></td>
                                        <td><span
                                                class="rounded-full px-2 py-0.5 text-[11px] font-bold <?= $stClass ?>"><?= e($n['notice_status'] ?? '—') ?></span>
                                        </td>
                                        <td class="text-xs"><?= e($n['follow_up_date'] ?? '—') ?></td>
                                        <td><?php if ($st !== 'resolved'): ?>
                                                <form method="post" class="inline"><input type="hidden" name="action"
                                                        value="update_notice_status"><input type="hidden" name="notice_id"
                                                        value="<?= (int) $n['id'] ?>"><input type="hidden" name="notice_status"
                                                        value="Resolved"><button type="submit"
                                                        class="text-[11px] font-bold text-teal-700 hover:underline">Mark
                                                        resolved</button></form><?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                <?php elseif ($tab === 'community'): ?>
                    <div class="flex flex-wrap justify-between gap-2 items-center">
                        <div>
                            <h2 class="text-xl font-bold text-slate-900">Community education</h2>
                            <p class="text-xs text-slate-500">Hygiene campaigns and sanitation drives.</p>
                        </div>
                        <a href="<?= e(tabUrl('community', ['modal' => 'campaign'])) ?>" class="<?= $btnPrimary ?>">Add
                            campaign</a>
                    </div>
                    <div class="grid gap-3 md:grid-cols-2">
                        <?php if (!$campaigns): ?>
                            <p class="text-xs text-slate-400 italic col-span-2">No campaigns yet.</p><?php endif; ?>
                        <?php foreach ($campaigns as $c): ?>
                            <article class="dashboard-card rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                                <div class="flex justify-between gap-2">
                                    <div><b class="text-slate-900"><?= e($c['title']) ?></b>
                                        <p class="text-xs text-slate-500 mt-0.5"><?= e($c['campaign_type']) ?> ·
                                            <?= e($c['barangay'] ?: '—') ?></p>
                                    </div>
                                    <span
                                        class="rounded-full bg-teal-50 text-teal-800 border border-teal-100 px-2 py-0.5 text-[11px] font-bold h-fit"><?= e($c['status']) ?></span>
                                </div>
                                <p class="mt-3 text-xs text-slate-600"><span
                                        class="font-mono"><?= e($c['event_date']) ?></span><?= $c['venue'] ? ' · ' . e($c['venue']) : '' ?><?= $c['audience_size'] ? ' · Audience: ' . (int) $c['audience_size'] : '' ?>
                                </p>
                                <?php if (!empty($c['materials'])): ?>
                                    <p class="mt-2 text-xs text-slate-500"><span class="font-bold text-slate-700">Materials:</span>
                                        <?= e($c['materials']) ?></p><?php endif; ?>
                                <?php if (!empty($c['notes'])): ?>
                                    <p class="mt-1 text-xs text-slate-500"><?= e($c['notes']) ?></p><?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>

                <?php else: /* reports */ ?>
                    <div>
                        <h2 class="text-xl font-bold text-slate-900">Reports & analytics</h2>
                        <p class="text-xs text-slate-500">Sanitation trends and module activity.</p>
                    </div>
                    <section class="grid gap-4 md:grid-cols-2">
                        <div class="dashboard-card rounded-xl border border-slate-200 bg-white p-5 shadow-sm space-y-3">
                            <h3 class="font-bold text-sm text-slate-900 border-b border-slate-100 pb-2">Inspection outcomes
                            </h3>
                            <?php $inspTotal = max(1, count($inspections));
                            foreach ([['Passed', $passed, 'bg-emerald-500'], ['Conditional', $conditional, 'bg-amber-500'], ['Failed', $failed, 'bg-rose-500']] as [$lab, $cnt, $bar]):
                                $pct = (int) round(($cnt / $inspTotal) * 100); ?>
                                <div>
                                    <div class="flex justify-between text-xs mb-1"><span
                                            class="font-semibold text-slate-700"><?= $lab ?></span><b><?= $cnt ?>
                                            (<?= $pct ?>%)</b></div>
                                    <div class="h-2.5 rounded-full bg-slate-100 overflow-hidden">
                                        <div class="h-2.5 rounded-full <?= $bar ?>" style="width:<?= $pct ?>%"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <p class="text-xs text-slate-500 pt-1">Average compliance: <b><?= $avg ?>%</b></p>
                        </div>
                        <div class="dashboard-card rounded-xl border border-slate-200 bg-white p-5 shadow-sm space-y-3">
                            <h3 class="font-bold text-sm text-slate-900 border-b border-slate-100 pb-2">Module activity</h3>
                            <?php foreach ([['Water samples', count($waterSamples), $unsafeWater . ' unsafe'], ['Food inspections', $foodTotal, $foodCompliant . ' compliant'], ['Waste records', count($wasteRecords), ''], ['Vector activities', count($vectorActivities), ''], ['Violation notices', count($notices), $pendingNotices . ' open'], ['Community campaigns', count($campaigns), count($upcomingCampaigns) . ' upcoming']] as [$lab, $val, $extra]): ?>
                                <div class="flex justify-between text-xs border-b border-slate-50 py-1.5"><span
                                        class="text-slate-600 font-medium"><?= $lab ?></span><span><b
                                            class="text-slate-900"><?= $val ?></b><?= $extra ? ' <span class="text-slate-400">· ' . e($extra) . '</span>' : '' ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                    <section class="dashboard-card rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                        <h3 class="font-bold text-sm text-slate-900 mb-3">Export inspection reports</h3>
                        <p class="text-xs text-slate-500 mb-3">Open any logged inspection and use <b>Print report</b> for
                            PDF-ready output.</p>
                        <div class="flex flex-wrap gap-2">
                            <a href="<?= e(tabUrl('inspections')) ?>"
                                class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50">Inspections</a>
                            <a href="<?= e(tabUrl('water')) ?>"
                                class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50">Water
                                quality</a>
                            <a href="<?= e(tabUrl('food')) ?>"
                                class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50">Food
                                safety</a>
                            <a href="<?= e(tabUrl('violations')) ?>"
                                class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50">Violations</a>
                        </div>
                    </section>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <?php if ($modal === 'schedule'): ?>
        <div
            class="fixed inset-0 bg-slate-950/45 backdrop-blur-sm flex items-end sm:items-center justify-center z-50 p-3 sm:p-4">
            <div
                class="bg-white rounded-2xl shadow-2xl w-full sm:max-w-xl max-h-[92vh] flex flex-col overflow-hidden border border-slate-200">
                <div class="p-5 border-b border-slate-200 flex items-center justify-between shrink-0">
                    <div>
                        <h2 class="text-base font-bold text-slate-800">Schedule inspection</h2>
                        <p class="text-xs text-slate-500">Calendar plan for field inspection</p>
                    </div>
                    <a href="<?= e(tabUrl('inspections')) ?>"
                        class="text-slate-400 hover:text-slate-700 w-8 h-8 rounded-full hover:bg-slate-100 flex items-center justify-center"><svg
                            class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path d="M18 6 6 18" />
                            <path d="m6 6 12 12" />
                        </svg></a>
                </div>
                <form method="post" class="p-5 space-y-4 text-xs overflow-y-auto">

                    <input type="hidden" name="action" value="save_schedule">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="sm:col-span-2"><label class="block font-bold text-slate-700 mb-1">Target name
                                *</label><input required name="target_name"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm"
                                placeholder="Establishment / facility"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Type</label><select name="target_type"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option>Food Establishment</option>
                                <option>Water Source</option>
                                <option>School</option>
                                <option>Public Facility</option>
                                <option>Market</option>
                            </select></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Barangay</label><select name="barangay"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option value="">Select barangay...</option>
                                <?php foreach ($barangayOptions as $b): ?>
                                    <option value="<?= e($b) ?>"><?= e($b) ?></option>
                                <?php endforeach; ?>
                            </select></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Date *</label><input required type="date"
                                name="scheduled_date" value="<?= date('Y-m-d') ?>"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm font-mono"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Time</label><input name="scheduled_time"
                                placeholder="09:00 AM" class="w-full p-3 border border-slate-300 rounded-xl text-sm"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Priority</label><select name="priority"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option>Normal</option>
                                <option>High</option>
                                <option>Low</option>
                            </select></div>
                        <div class="sm:col-span-2"><label class="block font-bold text-slate-700 mb-1">Notes</label><textarea
                                name="notes" rows="2"
                                class="w-full p-3 border border-slate-300 rounded-xl text-xs resize-none"></textarea></div>
                    </div>

                    <div class="flex gap-3 pt-2">
                        <a href="<?= e(tabUrl('inspections')) ?>"
                            class="flex-1 py-2.5 border border-slate-300 rounded-xl text-xs font-bold text-slate-700 hover:bg-slate-50 text-center">Cancel</a>
                        <button type="submit"
                            class="flex-1 py-2.5 bg-teal-600 text-white rounded-xl text-xs font-bold hover:bg-teal-700 shadow-md shadow-teal-600/20 transition">Save</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($modal === 'inspection'): ?>
        <div
            class="fixed inset-0 bg-slate-950/45 backdrop-blur-sm flex items-end sm:items-center justify-center z-50 p-3 sm:p-4">
            <div
                class="bg-white rounded-2xl shadow-2xl w-full sm:max-w-xl max-h-[92vh] flex flex-col overflow-hidden border border-slate-200">
                <div class="p-5 border-b border-slate-200 flex items-center justify-between shrink-0">
                    <div>
                        <h2 class="text-base font-bold text-slate-800">Log inspection</h2>
                        <p class="text-xs text-slate-500">Record establishment inspection findings</p>
                    </div>
                    <a href="<?= e(tabUrl('inspections')) ?>"
                        class="text-slate-400 hover:text-slate-700 w-8 h-8 rounded-full hover:bg-slate-100 flex items-center justify-center"><svg
                            class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path d="M18 6 6 18" />
                            <path d="m6 6 12 12" />
                        </svg></a>
                </div>
                <form method="post" class="p-5 space-y-4 text-xs overflow-y-auto">

                    <input type="hidden" name="action" value="save_sanitary_inspection">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="sm:col-span-2"><label class="block font-bold text-slate-700 mb-1">Establishment
                                *</label><input required name="establishment"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Barangay *</label><select required
                                name="barangay" class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option value="">Select barangay...</option>
                                <?php foreach ($barangayOptions as $b): ?>
                                    <option value="<?= e($b) ?>"><?= e($b) ?></option>
                                <?php endforeach; ?>
                            </select></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Inspector</label><select
                                name="inspector_staff_id" class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option value="">Select...</option><?php foreach ($staffOptions as $o): ?>
                                    <option value="<?= (int) $o['id'] ?>"><?= e($o['name']) ?></option><?php endforeach; ?>
                            </select></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Inspection date *</label><input required
                                type="date" name="inspection_date" value="<?= date('Y-m-d') ?>"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm font-mono"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Next inspection</label><input type="date"
                                name="next_inspection_date"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm font-mono"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Status</label><select name="status"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option>Compliant</option>
                                <option>Conditional</option>
                                <option>Non-compliant</option>
                            </select></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Compliance % *</label><input required
                                type="number" min="0" max="100" name="compliance_rate"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Violations *</label><input required
                                type="number" min="0" name="violations"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm"></div>
                        <div class="sm:col-span-2"><label
                                class="block font-bold text-slate-700 mb-1">Findings</label><textarea name="findings"
                                rows="3"
                                class="w-full p-3 border border-slate-300 rounded-xl text-xs resize-none"></textarea></div>
                    </div>

                    <div class="flex gap-3 pt-2">
                        <a href="<?= e(tabUrl('inspections')) ?>"
                            class="flex-1 py-2.5 border border-slate-300 rounded-xl text-xs font-bold text-slate-700 hover:bg-slate-50 text-center">Cancel</a>
                        <button type="submit"
                            class="flex-1 py-2.5 bg-teal-600 text-white rounded-xl text-xs font-bold hover:bg-teal-700 shadow-md shadow-teal-600/20 transition">Save</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($modal === 'water'): ?>
        <div
            class="fixed inset-0 bg-slate-950/45 backdrop-blur-sm flex items-end sm:items-center justify-center z-50 p-3 sm:p-4">
            <div
                class="bg-white rounded-2xl shadow-2xl w-full sm:max-w-xl max-h-[92vh] flex flex-col overflow-hidden border border-slate-200">
                <div class="p-5 border-b border-slate-200 flex items-center justify-between shrink-0">
                    <div>
                        <h2 class="text-base font-bold text-slate-800">Log water sample</h2>
                        <p class="text-xs text-slate-500">Record water quality test results</p>
                    </div>
                    <a href="<?= e(tabUrl('water')) ?>"
                        class="text-slate-400 hover:text-slate-700 w-8 h-8 rounded-full hover:bg-slate-100 flex items-center justify-center"><svg
                            class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path d="M18 6 6 18" />
                            <path d="m6 6 12 12" />
                        </svg></a>
                </div>
                <form method="post" class="p-5 space-y-4 text-xs overflow-y-auto">

                    <input type="hidden" name="action" value="save_water_sample">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="sm:col-span-2"><label class="block font-bold text-slate-700 mb-1">Source name
                                *</label><input required name="source_name"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm"
                                placeholder="Well / spring / system"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Source type</label><select
                                name="source_type" class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option>Well</option>
                                <option>Spring</option>
                                <option>Piped System</option>
                                <option>Other</option>
                            </select></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Barangay</label><select name="barangay"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option value="">Select barangay...</option>
                                <?php foreach ($barangayOptions as $b): ?>
                                    <option value="<?= e($b) ?>"><?= e($b) ?></option>
                                <?php endforeach; ?>
                            </select></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Sample date *</label><input required
                                type="date" name="sample_date" value="<?= date('Y-m-d') ?>"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm font-mono"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Overall status</label><select
                                name="overall_status" class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option>Safe</option>
                                <option>Watch</option>
                                <option>Unsafe</option>
                                <option>Contaminated</option>
                            </select></div>
                        <div><label class="block font-bold text-slate-700 mb-1">pH</label><input type="number" step="0.01"
                                name="ph_level" class="w-full p-3 border border-slate-300 rounded-xl text-sm"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Turbidity</label><input type="number"
                                step="0.01" name="turbidity" class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                        </div>
                        <div><label class="block font-bold text-slate-700 mb-1">Coliform result</label><input
                                name="coliform_result" class="w-full p-3 border border-slate-300 rounded-xl text-sm"
                                placeholder="Absent / Present"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Residual chlorine</label><input
                                type="number" step="0.01" name="residual_chlorine"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm"></div>
                        <div class="sm:col-span-2"><label
                                class="block font-bold text-slate-700 mb-1">Remarks</label><textarea name="remarks" rows="2"
                                class="w-full p-3 border border-slate-300 rounded-xl text-xs resize-none"></textarea></div>
                    </div>

                    <div class="flex gap-3 pt-2">
                        <a href="<?= e(tabUrl('water')) ?>"
                            class="flex-1 py-2.5 border border-slate-300 rounded-xl text-xs font-bold text-slate-700 hover:bg-slate-50 text-center">Cancel</a>
                        <button type="submit"
                            class="flex-1 py-2.5 bg-teal-600 text-white rounded-xl text-xs font-bold hover:bg-teal-700 shadow-md shadow-teal-600/20 transition">Save</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($modal === 'food'): ?>
        <div
            class="fixed inset-0 bg-slate-950/45 backdrop-blur-sm flex items-end sm:items-center justify-center z-50 p-3 sm:p-4">
            <div
                class="bg-white rounded-2xl shadow-2xl w-full sm:max-w-xl max-h-[92vh] flex flex-col overflow-hidden border border-slate-200">
                <div class="p-5 border-b border-slate-200 flex items-center justify-between shrink-0">
                    <div>
                        <h2 class="text-base font-bold text-slate-800">Food safety inspection</h2>
                        <p class="text-xs text-slate-500">Document eatery / market compliance</p>
                    </div>
                    <a href="<?= e(tabUrl('food')) ?>"
                        class="text-slate-400 hover:text-slate-700 w-8 h-8 rounded-full hover:bg-slate-100 flex items-center justify-center"><svg
                            class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path d="M18 6 6 18" />
                            <path d="m6 6 12 12" />
                        </svg></a>
                </div>
                <form method="post" class="p-5 space-y-4 text-xs overflow-y-auto">

                    <input type="hidden" name="action" value="save_food_inspection">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="sm:col-span-2"><label class="block font-bold text-slate-700 mb-1">Establishment
                                *</label><input required name="establishment"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Type</label><select
                                name="establishment_type" class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option>Eatery</option>
                                <option>Market Vendor</option>
                                <option>Canteen</option>
                                <option>Catering</option>
                                <option>Other</option>
                            </select></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Barangay</label><select name="barangay"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option value="">Select barangay...</option>
                                <?php foreach ($barangayOptions as $b): ?>
                                    <option value="<?= e($b) ?>"><?= e($b) ?></option>
                                <?php endforeach; ?>
                            </select></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Date *</label><input required type="date"
                                name="inspection_date" value="<?= date('Y-m-d') ?>"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm font-mono"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Compliance</label><select
                                name="compliance_status" class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option>Compliant</option>
                                <option>Conditional</option>
                                <option>Non-compliant</option>
                            </select></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Score (0-100)</label><input type="number"
                                min="0" max="100" name="score" value="80"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Next inspection</label><input type="date"
                                name="next_inspection_date"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm font-mono"></div>
                        <div class="sm:col-span-2"><label
                                class="block font-bold text-slate-700 mb-1">Findings</label><textarea name="findings"
                                rows="3"
                                class="w-full p-3 border border-slate-300 rounded-xl text-xs resize-none"></textarea></div>
                    </div>

                    <div class="flex gap-3 pt-2">
                        <a href="<?= e(tabUrl('food')) ?>"
                            class="flex-1 py-2.5 border border-slate-300 rounded-xl text-xs font-bold text-slate-700 hover:bg-slate-50 text-center">Cancel</a>
                        <button type="submit"
                            class="flex-1 py-2.5 bg-teal-600 text-white rounded-xl text-xs font-bold hover:bg-teal-700 shadow-md shadow-teal-600/20 transition">Save</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($modal === 'waste'): ?>
        <div
            class="fixed inset-0 bg-slate-950/45 backdrop-blur-sm flex items-end sm:items-center justify-center z-50 p-3 sm:p-4">
            <div
                class="bg-white rounded-2xl shadow-2xl w-full sm:max-w-xl max-h-[92vh] flex flex-col overflow-hidden border border-slate-200">
                <div class="p-5 border-b border-slate-200 flex items-center justify-between shrink-0">
                    <div>
                        <h2 class="text-base font-bold text-slate-800">Waste management record</h2>
                        <p class="text-xs text-slate-500">Log collection or sanitation activity</p>
                    </div>
                    <a href="<?= e(tabUrl('waste')) ?>"
                        class="text-slate-400 hover:text-slate-700 w-8 h-8 rounded-full hover:bg-slate-100 flex items-center justify-center"><svg
                            class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path d="M18 6 6 18" />
                            <path d="m6 6 12 12" />
                        </svg></a>
                </div>
                <form method="post" class="p-5 space-y-4 text-xs overflow-y-auto">

                    <input type="hidden" name="action" value="save_waste_record">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div><label class="block font-bold text-slate-700 mb-1">Record type</label><select
                                name="record_type" class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option>Garbage Collection</option>
                                <option>Sewage Disposal</option>
                                <option>Community Cleanup</option>
                                <option>Sanitation Project</option>
                            </select></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Date *</label><input required type="date"
                                name="activity_date" value="<?= date('Y-m-d') ?>"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm font-mono"></div>
                        <div class="sm:col-span-2"><label class="block font-bold text-slate-700 mb-1">Location
                                *</label><input required name="location"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Barangay</label><select name="barangay"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option value="">Select barangay...</option>
                                <?php foreach ($barangayOptions as $b): ?>
                                    <option value="<?= e($b) ?>"><?= e($b) ?></option>
                                <?php endforeach; ?>
                            </select></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Volume estimate</label><input
                                name="volume_estimate" class="w-full p-3 border border-slate-300 rounded-xl text-sm"
                                placeholder="e.g. 12 sacks"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Status</label><select name="status"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option>Completed</option>
                                <option>Scheduled</option>
                                <option>In Progress</option>
                            </select></div>
                        <div class="sm:col-span-2"><label class="block font-bold text-slate-700 mb-1">Notes</label><textarea
                                name="notes" rows="2"
                                class="w-full p-3 border border-slate-300 rounded-xl text-xs resize-none"></textarea></div>
                    </div>

                    <div class="flex gap-3 pt-2">
                        <a href="<?= e(tabUrl('waste')) ?>"
                            class="flex-1 py-2.5 border border-slate-300 rounded-xl text-xs font-bold text-slate-700 hover:bg-slate-50 text-center">Cancel</a>
                        <button type="submit"
                            class="flex-1 py-2.5 bg-teal-600 text-white rounded-xl text-xs font-bold hover:bg-teal-700 shadow-md shadow-teal-600/20 transition">Save</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($modal === 'vector'): ?>
        <div
            class="fixed inset-0 bg-slate-950/45 backdrop-blur-sm flex items-end sm:items-center justify-center z-50 p-3 sm:p-4">
            <div
                class="bg-white rounded-2xl shadow-2xl w-full sm:max-w-xl max-h-[92vh] flex flex-col overflow-hidden border border-slate-200">
                <div class="p-5 border-b border-slate-200 flex items-center justify-between shrink-0">
                    <div>
                        <h2 class="text-base font-bold text-slate-800">Vector control activity</h2>
                        <p class="text-xs text-slate-500">Record breeding site or fumigation work</p>
                    </div>
                    <a href="<?= e(tabUrl('vector')) ?>"
                        class="text-slate-400 hover:text-slate-700 w-8 h-8 rounded-full hover:bg-slate-100 flex items-center justify-center"><svg
                            class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path d="M18 6 6 18" />
                            <path d="m6 6 12 12" />
                        </svg></a>
                </div>
                <form method="post" class="p-5 space-y-4 text-xs overflow-y-auto">

                    <input type="hidden" name="action" value="save_vector_activity">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div><label class="block font-bold text-slate-700 mb-1">Activity type</label><select
                                name="activity_type" class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option>Breeding Site Inspection</option>
                                <option>Fumigation</option>
                                <option>Larviciding</option>
                                <option>Pest Control</option>
                            </select></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Date *</label><input required type="date"
                                name="activity_date" value="<?= date('Y-m-d') ?>"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm font-mono"></div>
                        <div class="sm:col-span-2"><label class="block font-bold text-slate-700 mb-1">Location
                                *</label><input required name="location"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Barangay</label><select name="barangay"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option value="">Select barangay...</option>
                                <?php foreach ($barangayOptions as $b): ?>
                                    <option value="<?= e($b) ?>"><?= e($b) ?></option>
                                <?php endforeach; ?>
                            </select></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Method</label><input name="method_used"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm"
                                placeholder="e.g. Thermal fogging"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Sites found</label><input type="number"
                                min="0" name="sites_found" value="0"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Sites treated</label><input type="number"
                                min="0" name="sites_treated" value="0"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Status</label><select name="status"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option>Completed</option>
                                <option>Scheduled</option>
                                <option>In Progress</option>
                            </select></div>
                        <div class="sm:col-span-2"><label class="block font-bold text-slate-700 mb-1">Notes</label><textarea
                                name="notes" rows="2"
                                class="w-full p-3 border border-slate-300 rounded-xl text-xs resize-none"></textarea></div>
                    </div>

                    <div class="flex gap-3 pt-2">
                        <a href="<?= e(tabUrl('vector')) ?>"
                            class="flex-1 py-2.5 border border-slate-300 rounded-xl text-xs font-bold text-slate-700 hover:bg-slate-50 text-center">Cancel</a>
                        <button type="submit"
                            class="flex-1 py-2.5 bg-teal-600 text-white rounded-xl text-xs font-bold hover:bg-teal-700 shadow-md shadow-teal-600/20 transition">Save</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($modal === 'notice'): ?>
        <div
            class="fixed inset-0 bg-slate-950/45 backdrop-blur-sm flex items-end sm:items-center justify-center z-50 p-3 sm:p-4">
            <div
                class="bg-white rounded-2xl shadow-2xl w-full sm:max-w-xl max-h-[92vh] flex flex-col overflow-hidden border border-slate-200">
                <div class="p-5 border-b border-slate-200 flex items-center justify-between shrink-0">
                    <div>
                        <h2 class="text-base font-bold text-slate-800">Issue violation notice</h2>
                        <p class="text-xs text-slate-500">Create formal sanitation notice</p>
                    </div>
                    <a href="<?= e(tabUrl('violations')) ?>"
                        class="text-slate-400 hover:text-slate-700 w-8 h-8 rounded-full hover:bg-slate-100 flex items-center justify-center"><svg
                            class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path d="M18 6 6 18" />
                            <path d="m6 6 12 12" />
                        </svg></a>
                </div>
                <form method="post" class="p-5 space-y-4 text-xs overflow-y-auto">

                    <input type="hidden" name="action" value="issue_notice">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="sm:col-span-2"><label class="block font-bold text-slate-700 mb-1">Establishment
                                *</label><input required name="establishment"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Barangay</label><select name="barangay"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option value="">Select barangay...</option>
                                <?php foreach ($barangayOptions as $b): ?>
                                    <option value="<?= e($b) ?>"><?= e($b) ?></option>
                                <?php endforeach; ?>
                            </select></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Violations *</label><input required
                                type="number" min="1" name="violations" value="1"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Status</label><select name="notice_status"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option>Pending</option>
                                <option>Issued</option>
                            </select></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Follow-up date</label><input type="date"
                                name="follow_up_date"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm font-mono"></div>
                        <div class="sm:col-span-2"><label class="block font-bold text-slate-700 mb-1">Findings /
                                grounds</label><textarea name="findings" rows="3"
                                class="w-full p-3 border border-slate-300 rounded-xl text-xs resize-none"></textarea></div>
                    </div>

                    <div class="flex gap-3 pt-2">
                        <a href="<?= e(tabUrl('violations')) ?>"
                            class="flex-1 py-2.5 border border-slate-300 rounded-xl text-xs font-bold text-slate-700 hover:bg-slate-50 text-center">Cancel</a>
                        <button type="submit"
                            class="flex-1 py-2.5 bg-teal-600 text-white rounded-xl text-xs font-bold hover:bg-teal-700 shadow-md shadow-teal-600/20 transition">Save</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($modal === 'campaign'): ?>
        <div
            class="fixed inset-0 bg-slate-950/45 backdrop-blur-sm flex items-end sm:items-center justify-center z-50 p-3 sm:p-4">
            <div
                class="bg-white rounded-2xl shadow-2xl w-full sm:max-w-xl max-h-[92vh] flex flex-col overflow-hidden border border-slate-200">
                <div class="p-5 border-b border-slate-200 flex items-center justify-between shrink-0">
                    <div>
                        <h2 class="text-base font-bold text-slate-800">Community campaign</h2>
                        <p class="text-xs text-slate-500">Schedule education or sanitation drive</p>
                    </div>
                    <a href="<?= e(tabUrl('community')) ?>"
                        class="text-slate-400 hover:text-slate-700 w-8 h-8 rounded-full hover:bg-slate-100 flex items-center justify-center"><svg
                            class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path d="M18 6 6 18" />
                            <path d="m6 6 12 12" />
                        </svg></a>
                </div>
                <form method="post" class="p-5 space-y-4 text-xs overflow-y-auto">

                    <input type="hidden" name="action" value="save_campaign">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="sm:col-span-2"><label class="block font-bold text-slate-700 mb-1">Title *</label><input
                                required name="title" class="w-full p-3 border border-slate-300 rounded-xl text-sm"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Type</label><select name="campaign_type"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option>Hygiene Awareness</option>
                                <option>Sanitation Drive</option>
                                <option>Handwashing Campaign</option>
                                <option>Dengue Awareness</option>
                                <option>Other</option>
                            </select></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Event date *</label><input required
                                type="date" name="event_date" value="<?= date('Y-m-d') ?>"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm font-mono"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Barangay</label><select name="barangay"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option value="">Select barangay...</option>
                                <?php foreach ($barangayOptions as $b): ?>
                                    <option value="<?= e($b) ?>"><?= e($b) ?></option>
                                <?php endforeach; ?>
                            </select></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Venue</label><input name="venue"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm"></div>
                        <div><label class="block font-bold text-slate-700 mb-1">Audience size</label><input type="number"
                                min="0" name="audience_size" class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                        </div>
                        <div><label class="block font-bold text-slate-700 mb-1">Status</label><select name="status"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option>Scheduled</option>
                                <option>Completed</option>
                                <option>Cancelled</option>
                            </select></div>
                        <div class="sm:col-span-2"><label class="block font-bold text-slate-700 mb-1">Materials /
                                posters</label><textarea name="materials" rows="2"
                                class="w-full p-3 border border-slate-300 rounded-xl text-xs resize-none"
                                placeholder="List materials or announcement notes"></textarea></div>
                        <div class="sm:col-span-2"><label class="block font-bold text-slate-700 mb-1">Notes</label><textarea
                                name="notes" rows="2"
                                class="w-full p-3 border border-slate-300 rounded-xl text-xs resize-none"></textarea></div>
                    </div>

                    <div class="flex gap-3 pt-2">
                        <a href="<?= e(tabUrl('community')) ?>"
                            class="flex-1 py-2.5 border border-slate-300 rounded-xl text-xs font-bold text-slate-700 hover:bg-slate-50 text-center">Cancel</a>
                        <button type="submit"
                            class="flex-1 py-2.5 bg-teal-600 text-white rounded-xl text-xs font-bold hover:bg-teal-700 shadow-md shadow-teal-600/20 transition">Save</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if (function_exists('portalRenderNotificationPanel')) echo portalRenderNotificationPanel(); ?>

    <script>
        (function () {
            const sidebar = document.querySelector('[data-feature-drawer]');
            const backdrop = document.querySelector('[data-drawer-backdrop]');
            const openBtn = document.querySelector('[data-drawer-open]');
            const closeBtn = document.querySelector('[data-drawer-close]');
            const setOpen = (open) => { sidebar?.classList.toggle('is-open', open); backdrop?.classList.toggle('is-open', open); document.body.classList.toggle('drawer-open', open); openBtn?.setAttribute('aria-expanded', String(!!open)); };
            openBtn?.addEventListener('click', () => setOpen(true));
            closeBtn?.addEventListener('click', () => setOpen(false));
            backdrop?.addEventListener('click', () => setOpen(false));
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape') setOpen(false); });
        })();
    </script>
</body>

</html>
