<?php
if (session_status() === PHP_SESSION_NONE)
    session_start();

$stType = strtoupper((string) ($_SESSION['rhu_staff_login']['staff_type'] ?? ''));
if (empty($_SESSION['rhu_staff_login']) || ($stType !== 'MEDTECH' && strpos($stType, 'MEDTECH') === false && strpos($stType, 'TECHNOLOGIST') === false)) {
    header('Location: RHULogin.php');
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/portal.php';
portalHandleNotificationApi($pdo);

function esc($v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
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

function flagClass(string $flag): string
{
    if ($flag === 'normal') return 'bg-emerald-100 text-emerald-800 border-emerald-200';
    if ($flag === 'notable') return 'bg-sky-100 text-sky-800 border-sky-200';
    if ($flag === 'pending') return 'bg-amber-100 text-amber-800 border-amber-200';
    return 'bg-rose-100 text-rose-800 border-rose-200';
}

$tabs = [
    'overview' => ['Overview', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>'],
    'rapid' => ['Rapid Diagnostic Tests', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M10 2v7.527a2 2 0 0 1-.211.896L4.72 20.55a1 1 0 0 0 .9 1.45h12.76a1 1 0 0 0 .9-1.45l-5.069-10.127A2 2 0 0 1 14 9.527V2"/><path d="M8.5 2h7"/><path d="M7 16h10"/></svg>'],
    'referrals' => ['Specimen Referrals', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" x2="12" y1="2" y2="15"/></svg>'],
    'supplies' => ['Test Kit Supplies', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>'],
    'reports' => ['Laboratory Reports', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/></svg>'],
];

$tab = $_GET['tab'] ?? 'overview';
if (!isset($tabs[$tab]))
    $tab = 'overview';

$modal = $_GET['modal'] ?? '';
$flashSuccess = $_SESSION['medtech_flash_success'] ?? '';
$flashError = $_SESSION['medtech_flash_error'] ?? '';
unset($_SESSION['medtech_flash_success'], $_SESSION['medtech_flash_error']);

$medtechStaffId = (int) ($_SESSION['rhu_staff_login']['staff_id'] ?? 0);
$medtechUserId = (int) ($_SESSION['rhu_staff_login']['id'] ?? $_SESSION['rhu_staff_login']['user_id'] ?? 0);

$medtechConsultations = [];
$consultationOptions = [];
$residentOptions = [];
$staffOptions = [];
$tests = [];
$referrals = [];
$supplies = [];
$medtechCertificateTypes = [];

// ----------------------------------------------------
// 1. POST FORM HANDLERS FOR MEDTECH DIAGNOSTICS & SUPPLIES
// ----------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !empty($pdo)) {
    $action = $_POST['action'] ?? '';

    if ($action === 'issue_certificate') {
        try {
            $issued = portalIssueResidentCertificate($pdo, $_POST, $medtechStaffId, 'Medical Technologist');
            $_SESSION['medtech_flash_success'] = "{$issued['type']} {$issued['number']} was issued and sent to the Resident.";
        } catch (Throwable $e) {
            $_SESSION['medtech_flash_error'] = 'Certificate Error: ' . $e->getMessage();
        }
        header('Location: ' . tabUrl('certificates'));
        exit;
    }

    // Action: Answer / Update Resident Laboratory Consultation
    if ($action === 'answer_consultation') {
        $cslId = (int) ($_POST['consultation_id'] ?? 0);
        $resId = (int) ($_POST['resident_id'] ?? 0);
        $diagnosis = trim($_POST['diagnosis'] ?? '');
        $notes = trim($_POST['consultation_notes'] ?? '');
        $status = trim($_POST['consultation_status'] ?? 'Completed');

        if ($cslId > 0 && !empty($pdo)) {
            try {
                $stmt = $pdo->prepare("UPDATE consultations SET diagnosis = :dx, remarks = :notes, updated_at = NOW() WHERE id = :id");
                $stmt->execute([
                    'dx' => $diagnosis,
                    'notes' => trim($notes . "\nStatus: {$status}"),
                    'id' => $cslId
                ]);
                portalSaveHealthRecordEntry($pdo, $resId, [
                    'record_type' => 'Laboratory consultation response',
                    'diagnosis' => $diagnosis,
                    'notes' => $notes,
                    'status' => $status,
                    'recorded_by_id' => $medtechStaffId ?: null,
                ]);
                if ($resId > 0) {
                    portalNotifyResident($pdo, $resId, "Your Laboratory / Diagnostic request result has been updated by MedTech. Status: {$status}. Assessment: {$diagnosis}", "ResidentDashboard.php?tab=appointments");
                }
                $_SESSION['medtech_flash_success'] = 'Laboratory consultation updated and response sent to resident!';
            } catch (Exception $e) {
                $_SESSION['medtech_flash_error'] = 'Error updating consultation: ' . $e->getMessage();
            }
        }
        header('Location: ' . tabUrl('overview'));
        exit;
    }

    if ($action === 'save_test') {
        $consultationId = (int) ($_POST['consultation_id'] ?? 0);
        $testName = trim($_POST['test_name'] ?? '');
        $testType = trim($_POST['test_type'] ?? '');
        $testDate = $_POST['test_date'] ?? date('Y-m-d');
        $results = trim($_POST['results'] ?? '');
        $testStatus = trim($_POST['test_status'] ?? 'Normal');
        $orderedById = (int) ($_POST['ordered_by_id'] ?? 0) ?: null;

        if ($consultationId <= 0 || empty($testName)) {
            $_SESSION['medtech_flash_error'] = 'Please select a valid consultation and enter the test name.';
        } else {
            try {
                $statement = $pdo->prepare("UPDATE consultations SET remarks = CONCAT(COALESCE(remarks, ''), :lab_note), updated_at = NOW() WHERE id = :id");
                $statement->execute([
                    'lab_note' => "\nLAB TEST {$testDate}: {$testName} ({$testType}) - {$testStatus}. {$results}",
                    'id' => $consultationId,
                ]);
                try {
                    $resStmt = $pdo->prepare("SELECT resident_id FROM consultations WHERE id = :cid LIMIT 1");
                    $resStmt->execute(['cid' => $consultationId]);
                    if ($resId = $resStmt->fetchColumn()) {
                        portalSaveHealthRecordEntry($pdo, (int) $resId, [
                            'record_type' => 'Laboratory test result',
                            'diagnosis' => "{$testName} - {$testStatus}",
                            'notes' => trim("Test type: {$testType}\nResults: {$results}"),
                            'status' => $testStatus,
                            'date' => $testDate,
                            'recorded_by_id' => $orderedById ?: ($medtechStaffId ?: null),
                        ]);
                        portalNotifyResident($pdo, (int) $resId, "Your diagnostic lab test result ({$testName}: {$testStatus}) is available.", "ResidentDashboard.php?tab=history");
                    }
                } catch (Throwable $tNotif) {
                }
                $_SESSION['medtech_flash_success'] = 'Rapid Diagnostic Test result saved successfully!';
            } catch (Exception $e) {
                $_SESSION['medtech_flash_error'] = 'Database Error: ' . $e->getMessage();
            }
        }
        header('Location: ' . tabUrl('rapid'));
        exit;
    }

    if ($action === 'save_lab_referral') {
        $residentId = (int) ($_POST['resident_id'] ?? 0);
        $testRequested = trim($_POST['test_requested'] ?? '');
        $facility = trim($_POST['destination_facility'] ?? '');
        $referralDate = $_POST['referral_date'] ?? date('Y-m-d');
        $notes = trim($_POST['clinical_notes'] ?? '');
        $referredById = (int) ($_POST['referred_by_id'] ?? 0) ?: null;

        if ($residentId <= 0 || empty($testRequested) || empty($facility)) {
            $_SESSION['medtech_flash_error'] = 'Please select a resident, specify the test requested, and destination facility.';
        } else {
            try {
                $statement = $pdo->prepare("INSERT INTO consultations (resident_id, health_worker_id, consultation_date, consultation_time, chief_complaint, diagnosis, remarks, created_at) VALUES (?, ?, ?, CURTIME(), ?, 'Laboratory referral pending', ?, NOW())");
                $statement->execute([$residentId, $referredById ?: $medtechStaffId ?: null, $referralDate, "Laboratory Referral: {$testRequested}", "Destination: {$facility}\n{$notes}"]);
                portalSaveHealthRecordEntry($pdo, $residentId, [
                    'record_type' => 'Laboratory referral',
                    'chief_complaint' => "Laboratory Referral: {$testRequested}",
                    'diagnosis' => 'Laboratory referral pending',
                    'treatment' => 'Referred to ' . $facility,
                    'notes' => $notes,
                    'status' => 'Referred',
                    'date' => $referralDate,
                    'recorded_by_id' => $referredById ?: ($medtechStaffId ?: null),
                ]);
                portalNotifyResident($pdo, $residentId, "Laboratory specimen referral created for {$testRequested} at {$facility}.", "ResidentDashboard.php?tab=history");
                $_SESSION['medtech_flash_success'] = 'Specimen referral recorded successfully!';
            } catch (Exception $e) {
                $_SESSION['medtech_flash_error'] = 'Database Error: ' . $e->getMessage();
            }
        }
        header('Location: ' . tabUrl('referrals'));
        exit;
    }

    if ($action === 'save_lab_supply') {
        $itemName = trim($_POST['item_name'] ?? '');
        $category = trim($_POST['category'] ?? 'Laboratory');
        $quantity = (int) ($_POST['quantity'] ?? 0);
        $unit = trim($_POST['unit'] ?? 'kits');
        $reorderLevel = (int) ($_POST['reorder_level'] ?? 5);
        $expiryDate = $_POST['expiry_date'] ?: null;

        if (empty($itemName)) {
            $_SESSION['medtech_flash_error'] = 'Please enter a valid supply item name.';
        } else {
            try {
                $statement = $pdo->prepare("INSERT INTO medicine_inventory (item_name, item_type, category, unit, quantity, reorder_level, expiration_date, status, created_at) VALUES (?, 'Laboratory', ?, ?, ?, ?, ?, 'Available', NOW())");
                $statement->execute([$itemName, $category, $unit, $quantity, $reorderLevel, $expiryDate]);
                $_SESSION['medtech_flash_success'] = 'Laboratory supply item added to inventory!';
            } catch (Exception $e) {
                $_SESSION['medtech_flash_error'] = 'Database Error: ' . $e->getMessage();
            }
        }
        header('Location: ' . tabUrl('supplies'));
        exit;
    }
}

// ----------------------------------------------------
// 2. LIVE DATABASE HYDRATION
// ----------------------------------------------------
if (!empty($pdo)) {
    try {
        $mtStmt = $pdo->prepare("
            SELECT c.id, c.resident_id, CONCAT(r.first_name, ' ', r.last_name) AS patientName, TIMESTAMPDIFF(YEAR, r.birthdate, CURDATE()) as age, r.sex as gender, c.chief_complaint as chiefComplaint, c.diagnosis, COALESCE(b.name, 'Unassigned') as barangay, c.consultation_date as date, c.remarks as consultation_notes, CASE WHEN c.diagnosis IS NOT NULL AND c.diagnosis <> '' THEN 'Completed' ELSE 'In Progress' END AS consultation_status
            FROM consultations c
            JOIN residents r ON c.resident_id = r.id
            LEFT JOIN barangays b ON b.id = r.barangay_id
            WHERE (c.health_worker_id = :sid_hw OR c.physician_id = :sid_phy)
            ORDER BY c.id DESC
        ");
        $mtStmt->execute(['sid_hw' => $medtechStaffId, 'sid_phy' => $medtechStaffId]);
        $medtechConsultations = $mtStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
    }

    try {
        $consultationOptions = $pdo->query("SELECT c.id, CONCAT(r.first_name, ' ', r.last_name) patient, c.chief_complaint FROM consultations c JOIN residents r ON r.id=c.resident_id ORDER BY c.id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $residentOptions = $pdo->query(rhuResidentListSql() . " ORDER BY r.first_name, r.last_name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $medtechCertificateTypes = portalEnsureCertificateTypes($pdo, ['Laboratory Result Certificate', 'Diagnostic Test Certificate', 'Medical Laboratory Clearance', 'Specimen Examination Certificate']);
        $staffOptions = $pdo->query("SELECT id, CONCAT(first_name, ' ', last_name) name, position_title AS staff_type FROM health_workers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $testRows = $pdo->query("SELECT c.id, CONCAT(r.first_name, ' ', r.last_name) patient, TIMESTAMPDIFF(YEAR, r.birthdate, CURDATE()) age, COALESCE(b.name, 'Unassigned') barangay, c.chief_complaint AS test_name, c.remarks AS results, CASE WHEN c.remarks LIKE '%positive%' OR c.remarks LIKE '%abnormal%' THEN 'Abnormal' WHEN c.remarks LIKE '%pending%' THEN 'Pending' ELSE 'Normal' END AS test_status, 'Laboratory' AS test_type, COALESCE(CONCAT(hw.first_name, ' ', hw.last_name), 'RHU MedTech') ordered_by, c.consultation_date AS test_date FROM consultations c JOIN residents r ON r.id=c.resident_id LEFT JOIN barangays b ON b.id=r.barangay_id LEFT JOIN health_workers hw ON hw.id=c.health_worker_id WHERE c.chief_complaint LIKE '%Laboratory%' OR c.chief_complaint LIKE '%RDT%' OR c.remarks LIKE '%LAB TEST%' ORDER BY c.id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($testRows as $row) {
            $status = strtolower((string) $row['test_status']);
            $flag = str_contains($status, 'abnormal') || str_contains($status, 'positive') ? 'abnormal' : (str_contains($status, 'pending') ? 'pending' : 'normal');
            $tests[] = ['RDT-' . $row['id'], $row['patient'], (int) $row['age'], $row['barangay'], $row['test_name'], $row['results'] ?: 'Pending', $flag, $row['test_type'] ?: 'Standard RDT', trim((string) $row['ordered_by']) ?: 'Attending Physician', $row['test_date']];
        }

        $referralRows = $pdo->query("SELECT c.id, c.resident_id, c.chief_complaint AS test_requested, c.remarks AS clinical_notes, c.consultation_date AS referral_date, c.diagnosis AS status, CONCAT(r.first_name, ' ', r.last_name) patient, TIMESTAMPDIFF(YEAR, r.birthdate, CURDATE()) age FROM consultations c JOIN residents r ON r.id=c.resident_id WHERE c.chief_complaint LIKE 'Laboratory Referral:%' ORDER BY c.id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($referralRows as $row) {
            $destination = preg_match('/Destination:\s*([^\n]+)/i', (string)$row['clinical_notes'], $m) ? $m[1] : 'External laboratory';
            $referrals[] = ['SPR-' . $row['id'], $row['patient'], (int) $row['age'], $row['test_requested'], $destination, $row['referral_date'], 'pending', 'Awaiting', $row['clinical_notes'] ?: 'Routine specimen reference'];
        }

        $supplyRows = $pdo->query("SELECT item_name, category, quantity, unit, reorder_level, expiration_date AS expiry_date FROM medicine_inventory WHERE item_type = 'Laboratory' ORDER BY item_name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($supplyRows as $row) {
            $status = (int) $row['quantity'] <= 0 ? 'critical' : ((int) $row['quantity'] <= (int) $row['reorder_level'] ? 'low' : 'adequate');
            $supplies[] = [$row['item_name'], $row['category'] ?: 'Laboratory', (int) $row['quantity'], $row['unit'], (int) $row['reorder_level'], $row['expiry_date'], $status];
        }
    } catch (Exception $e) {
    }
}

$today = array_filter($tests, function ($r) { return $r[9] === date('Y-m-d'); });
$abnormal = count(array_filter($tests, function ($r) { return in_array($r[6], ['abnormal', 'notable']); }));
$pending = count(array_filter($referrals, function ($r) { return $r[6] === 'pending'; }));
$critical = count(array_filter($supplies, function ($s) { return $s[6] === 'critical'; }));
$low = count(array_filter($supplies, function ($s) { return $s[6] === 'low'; }));
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="favicon.ico?v=20260915" type="image/x-icon">
    <link rel="shortcut icon" href="favicon.ico?v=20260915" type="image/x-icon">
    <title>Medical Technologist Portal — ResiHUnity RHU</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        html {
            scroll-behavior: auto;
        }

        body.rhu-medtech-ui {
            overflow: hidden;
            background: #f3f6f4;
            color: #0f172a;
            height: 100vh;
            height: 100dvh;
        }

        .medtech-sidebar {
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
            overflow: hidden;
        }

        .medtech-sidebar-brand {
            position: relative;
            overflow: hidden;
            min-height: 4.25rem;
            padding: 0.9rem 1rem;
            flex-shrink: 0;
        }

        .medtech-sidebar-brand .brand-bg {
            position: absolute;
            inset: 0;
            background-image: url('../../../assets/admin-municipal-background.png');
            background-size: cover;
            background-position: center;
            filter: saturate(1.2) brightness(0.52);
        }

        .medtech-sidebar-brand .brand-overlay {
            position: absolute;
            inset: 0;
            background: linear-gradient(100deg, rgba(13, 53, 24, 0.92) 0%, rgba(23, 63, 45, 0.82) 55%, rgba(47, 111, 73, 0.7) 100%);
        }

        .admin-shell-header {
            background: #0b3c35;
            border-bottom: 1px solid rgba(10, 51, 43, 0.9);
            box-shadow: 0 10px 28px rgba(2, 28, 23, 0.18);
            min-height: 5rem;
            position: sticky;
            top: 0;
            z-index: 50;
        }

        .nav-section-label {
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: #94a3b8;
            padding: 0.85rem 1.1rem 0.35rem;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin: 0.1rem 0.65rem;
            padding: 0.65rem 0.85rem;
            border-radius: 0.85rem;
            font-size: 0.8125rem;
            font-weight: 600;
            color: #475569;
            transition: background .15s ease, color .15s ease;
        }

        .nav-item:hover {
            background: #f0fdf6;
            color: #0f766e;
        }

        .nav-item.is-active {
            background: #e8f8ef;
            color: #0b3c35;
            font-weight: 800;
            box-shadow: inset 0 0 0 1px #c6ebd4;
        }

        .nav-item.is-active svg {
            color: #0f766e;
        }

        .medtech-main-wrap {
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
            overflow: hidden;
        }

        .medtech-main-wrap::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image: url('../../../assets/admin-municipal-background.png');
            background-size: cover;
            background-position: center top;
            opacity: 0.06;
            pointer-events: none;
            z-index: 0;
        }

        .medtech-main-wrap>* {
            position: relative;
            z-index: 1;
        }

        .medtech-main-wrap>header.admin-shell-header {
            position: sticky;
            top: 0;
            z-index: 50;
            flex-shrink: 0;
        }

        .medtech-main-wrap>main {
            z-index: 1;
            position: relative;
            flex: 1;
            min-height: 0;
            overflow-y: auto;
            overflow-x: hidden;
        }

        .dashboard-card {
            background: rgba(255, 255, 255, 0.95);
            transition: border-color .18s ease, box-shadow .18s ease, transform .18s ease;
        }

        .dashboard-card:hover {
            border-color: #a7e0bc;
            box-shadow: 0 6px 18px rgba(11, 60, 53, 0.08);
            transform: translateY(-1px);
        }

        @media (prefers-reduced-motion: reduce) {
            .dashboard-card:hover {
                transform: none;
            }
        }

        input:focus,
        select:focus,
        textarea:focus {
            border-color: #0d9488 !important;
            box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.14) !important;
        }

        @media (max-width: 1023px) {
            .medtech-sidebar {
                position: fixed;
                inset: 0 auto 0 0;
                z-index: 60;
                height: 100vh;
                transform: translateX(-105%);
                transition: transform .2s ease;
                box-shadow: 12px 0 40px rgba(15, 23, 42, .18);
            }

            .medtech-sidebar.is-open {
                transform: translateX(0);
            }

            .sidebar-backdrop {
                position: fixed;
                inset: 0;
                z-index: 50;
                background: rgba(2, 6, 23, .42);
                opacity: 0;
                pointer-events: none;
                transition: opacity .15s ease;
            }

            .sidebar-backdrop.is-open {
                opacity: 1;
                pointer-events: auto;
            }

            body.drawer-open {
                overflow: hidden;
            }
        }

        @media (min-width: 1024px) {
            .sidebar-backdrop {
                display: none !important;
            }

            .medtech-sidebar {
                transform: none !important;
            }
        }
    </style>
    <link rel="stylesheet" href="dashboard-enhancements.css">
</head>

<body class="rhu-medtech-ui antialiased">
    <div class="flex h-screen max-h-screen overflow-hidden">
        <div data-drawer-backdrop class="sidebar-backdrop lg:hidden" aria-hidden="true"></div>
        <aside id="medtech-sidebar" data-feature-drawer class="medtech-sidebar shrink-0"
            aria-label="MedTech navigation">
            <div class="medtech-sidebar-brand">
                <div class="brand-bg" aria-hidden="true"></div>
                <div class="brand-overlay" aria-hidden="true"></div>
                <div class="relative z-10 flex items-center gap-3">
                    <span
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-white/30 bg-white shadow-md overflow-hidden">
                        <img src="../../../assets/nasugbu_seal.png" alt="Nasugbu Seal" class="h-10 w-10 object-contain"
                            onerror="this.onerror=null;this.src='nasugbu_seal.png';" />
                    </span>
                    <div class="min-w-0 text-white">
                        <p class="text-[14px] font-black leading-tight tracking-tight drop-shadow-sm">RURAL HEALTH UNIT
                        </p>
                        <p class="text-[11px] font-semibold text-white/90 truncate">
                            <?= esc($tabs[$tab][0] ?? 'Overview') ?></p>
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
                    'Diagnostics' => ['rapid', 'referrals', 'reports'],
                    'Inventory' => ['supplies'],
                ];
                foreach ($drawerGroups as $groupLabel => $groupTabs):
                    $visible = array_values(array_filter($groupTabs, function ($k) use ($tabs) { return isset($tabs[$k]); }));
                    if (!$visible)
                        continue;
                    ?>
                    <p class="nav-section-label"><?= esc($groupLabel) ?></p>
                    <?php foreach ($visible as $id):
                        [$label, $icon] = $tabs[$id];
                        $active = $tab === $id;
                        ?>
                        <a href="<?= esc(tabUrl($id)) ?>" class="nav-item <?= $active ? 'is-active' : '' ?>">
                            <span class="shrink-0 opacity-90"><?= $icon ?></span>
                            <span class="truncate flex-1"><?= esc($label) ?></span>
                            <?php if ($active): ?><span class="text-teal-700 text-sm font-black">→</span><?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </nav>
            <div class="border-t border-slate-100 p-3 shrink-0">
                <a href="StaffLogout.php" data-staff-logout
                    class="nav-item text-slate-500 hover:bg-rose-50 hover:text-rose-700">
                    <?= iconSvg('logout', 'w-5 h-5') ?>
                    <span>Log Out</span>
                </a>
            </div>
        </aside>
        <div class="medtech-main-wrap">
            <header class="admin-shell-header dashboard-header sticky top-0 z-50 text-[#f4faf7]">
                <div class="flex h-20 items-center justify-between gap-3 px-5 sm:px-7">
                    <div class="flex items-center gap-2.5 min-w-0">
                        <button type="button" data-drawer-open
                            class="lg:hidden flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-white/15 text-white/95 hover:bg-white/10"
                            aria-label="Open menu" aria-expanded="false">
                            <?= iconSvg('menu', 'w-4 h-4') ?>
                        </button>
                        <div class="flex items-center gap-2">
                            <span
                                class="flex h-7 w-7 items-center justify-center rounded-full border border-[#e8f3d8]/80 bg-[#dfeecb] text-[#0b3b2f]">
                                <?= iconSvg('shield', 'w-3.5 h-3.5') ?>
                            </span>
                            <span class="text-xs font-black uppercase tracking-[0.16em] text-[#f5f5f2]">MedTech
                                Panel</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 sm:gap-2.5">
                        <?php if (function_exists('portalRenderNotificationButton')) {
                            echo portalRenderNotificationButton();
                        } ?>
                        <div
                            class="flex items-center gap-2 rounded-full border border-[#dbeadf]/20 bg-white/10 pl-1 pr-2.5 py-1">
                            <div
                                class="flex h-9 w-9 items-center justify-center rounded-full bg-[#dceec4] text-sm font-black text-[#0b3b2f]">
                                <?= esc(strtoupper(substr($_SESSION['rhu_staff_login']['name'] ?? 'M', 0, 1))) ?>
                            </div>
                            <div class="hidden sm:block text-left leading-tight pr-1">
                                <p class="text-[12px] font-bold text-white">
                                    <?= esc($_SESSION['rhu_staff_login']['name'] ?? 'Medical Technologist') ?></p>
                                <p class="text-[9px] font-semibold uppercase tracking-wider text-[#cfe5d8]">MedTech</p>
                            </div>
                        </div>
                        <a href="StaffLogout.php" data-staff-logout
                            class="inline-flex h-9 items-center gap-1.5 rounded-full border border-white/20 bg-[#f3faf4] px-3 text-xs font-bold text-[#0c3a32] hover:bg-white transition">
                            <?= iconSvg('logout', 'w-3.5 h-3.5') ?>
                            <span class="hidden sm:inline">Log out</span>
                        </a>
                    </div>
                </div>
            </header>
            <main class="flex-1 mx-auto w-full max-w-7xl p-4 sm:p-6 space-y-5 pb-6">


                <!-- FLASH NOTIFICATIONS -->
                <?php if (!empty($flashSuccess)): ?>
                    <div
                        class="flex items-center justify-between rounded-2xl border border-emerald-200 bg-emerald-50/90 p-4 text-sm font-semibold text-emerald-900 shadow-sm backdrop-blur-sm">
                        <div class="flex items-center gap-3">
                            <span
                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-emerald-600 text-white font-bold"></span>
                            <p><?= esc($flashSuccess) ?></p>
                        </div>
                        <button type="button" onclick="this.parentElement.remove()"
                            class="text-emerald-700 hover:text-emerald-950 font-bold px-2">×</button>
                    </div>
                <?php endif; ?>

                <?php if (!empty($flashError)): ?>
                    <div
                        class="flex items-center justify-between rounded-2xl border border-rose-200 bg-rose-50/90 p-4 text-sm font-semibold text-rose-900 shadow-sm backdrop-blur-sm">
                        <div class="flex items-center gap-3">
                            <span
                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-rose-600 text-white font-bold"></span>
                            <p><?= esc($flashError) ?></p>
                        </div>
                        <button type="button" onclick="this.parentElement.remove()"
                            class="text-rose-700 hover:text-rose-950 font-bold px-2">×</button>
                    </div>
                <?php endif; ?>

                <!-- TAB 1: OVERVIEW -->
                <?php if ($tab === 'overview'): ?>

                    <!-- Critical Stock Warning Banner -->
                    <?php if ($critical > 0): ?>
                        <section
                            class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 rounded-2xl border border-rose-300 bg-linear-to-r from-rose-500 via-rose-600 to-red-600 p-5 text-white shadow-lg">
                            <div class="flex items-start gap-3">
                                <span
                                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-white/20 text-2xl backdrop-blur-sm"></span>
                                <div>
                                    <h3 class="text-base font-bold tracking-tight"><?= $critical ?> Test Kit / Laboratory Supply
                                        Item(s) Critically Low</h3>
                                    <p class="text-xs text-rose-100 mt-0.5">
                                        Immediate resupply request required for:
                                        <span class="font-bold underline decoration-rose-300">
                                            <?php
                                            $critNames = [];
                                            foreach ($supplies as $s)
                                                if ($s[6] === 'critical')
                                                    $critNames[] = $s[0];
                                            echo esc(implode(' · ', $critNames));
                                            ?>
                                        </span>
                                    </p>
                                </div>
                            </div>
                            <a href="<?= tabUrl('supplies') ?>"
                                class="shrink-0 rounded-xl bg-white px-4 py-2 text-xs font-extrabold text-rose-700 shadow-md hover:bg-rose-50 transition">
                                Manage Inventory →
                            </a>
                        </section>
                    <?php endif; ?>

                    <!-- Quick KPI Summary Widgets -->
                    <section class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                        <div
                            class="rounded-2xl border border-violet-100 bg-white p-5 shadow-sm transition hover:shadow-md hover:border-violet-300">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Today's RDTs</span>
                                <span
                                    class="flex h-8 w-8 items-center justify-center rounded-xl bg-violet-100 text-violet-700 font-bold"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M10 2v7.527a2 2 0 0 1-.211.896L4.72 20.55a1 1 0 0 0 .9 1.45h12.76a1 1 0 0 0 .9-1.45l-5.069-10.127A2 2 0 0 1 14 9.527V2"/><path d="M8.5 2h7"/><path d="M7 16h10"/></svg></span>
                            </div>
                            <p class="mt-3 text-3xl font-black text-violet-900"><?= count($today) ?></p>
                            <p class="mt-1 text-xs font-semibold text-slate-500">Tests completed today</p>
                        </div>

                        <div
                            class="rounded-2xl border border-rose-100 bg-white p-5 shadow-sm transition hover:shadow-md hover:border-rose-300">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Abnormal
                                    Flags</span>
                                <span
                                    class="flex h-8 w-8 items-center justify-center rounded-xl bg-rose-100 text-rose-700 font-bold"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg></span>
                            </div>
                            <p class="mt-3 text-3xl font-black text-rose-600"><?= $abnormal ?></p>
                            <p class="mt-1 text-xs font-semibold text-rose-600/80">Requires physician review</p>
                        </div>

                        <div
                            class="rounded-2xl border border-sky-100 bg-white p-5 shadow-sm transition hover:shadow-md hover:border-sky-300">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Pending
                                    Referrals</span>
                                <span
                                    class="flex h-8 w-8 items-center justify-center rounded-xl bg-sky-100 text-sky-700 font-bold"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" x2="12" y1="2" y2="15"/></svg></span>
                            </div>
                            <p class="mt-3 text-3xl font-black text-sky-700"><?= $pending ?></p>
                            <p class="mt-1 text-xs font-semibold text-slate-500">External specimen referrals</p>
                        </div>

                        <div
                            class="rounded-2xl border border-amber-100 bg-white p-5 shadow-sm transition hover:shadow-md hover:border-amber-300">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Low Stock
                                    Kits</span>
                                <span
                                    class="flex h-8 w-8 items-center justify-center rounded-xl bg-amber-100 text-amber-700 font-bold"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg></span>
                            </div>
                            <p class="mt-3 text-3xl font-black text-amber-600"><?= $critical + $low ?></p>
                            <p class="mt-1 text-xs font-semibold text-slate-500"><?= $critical ?> critical · <?= $low ?> low
                            </p>
                        </div>
                    </section>

                    <!-- Live Laboratory Consultation Queue -->
                    <section class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm">
                        <div
                            class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 border-b border-slate-100 pb-4">
                            <div>
                                <h2 class="text-base font-bold text-slate-900 flex items-center gap-2">
                                    <span class="inline-flex text-teal-700"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M10 2v7.527a2 2 0 0 1-.211.896L4.72 20.55a1 1 0 0 0 .9 1.45h12.76a1 1 0 0 0 .9-1.45l-5.069-10.127A2 2 0 0 1 14 9.527V2"/><path d="M8.5 2h7"/><path d="M7 16h10"/></svg></span><span>Laboratory Consultation Queue</span>
                                    <span
                                        class="rounded-full bg-violet-100 px-2.5 py-0.5 text-xs font-extrabold text-violet-800 border border-violet-200">
                                        <?= count($medtechConsultations) ?> Pending
                                    </span>
                                </h2>
                                <p class="text-xs text-slate-500 mt-0.5">Assigned patients waiting for diagnostic lab
                                    requests or rapid diagnostic testing.</p>
                            </div>
                            <a href="?tab=rapid&modal=test"
                                class="inline-flex items-center gap-1.5 rounded-xl bg-teal-700 hover:bg-teal-800 px-3.5 py-2 text-xs font-bold text-white shadow-sm transition">
                                <span></span>
                                <span>Record RDT Result</span>
                            </a>
                        </div>

                        <?php if (empty($medtechConsultations)): ?>
                            <div class="py-8 text-center">
                                <span class="mx-auto mb-2 flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-400"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg></span>
                                <p class="mt-2 text-sm font-semibold text-slate-600">No active laboratory requests assigned in
                                    queue.</p>
                                <p class="text-xs text-slate-400">New diagnostic requests from RHU doctors will appear here in
                                    real time.</p>
                            </div>
                        <?php else: ?>
                            <div class="mt-4 divide-y divide-slate-100 ">
                                <?php foreach ($medtechConsultations as $mc): ?>
                                    <div
                                        class="py-3.5 flex flex-col space-y-3 bg-slate-50/50 p-3 rounded-xl border border-slate-200/60 mb-2">
                                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                                            <div class="space-y-1">
                                                <div class="flex items-center gap-2">
                                                    <b class="text-sm text-slate-900"><?= esc($mc['patientName']) ?></b>
                                                    <span class="text-xs text-slate-500 font-medium">
                                                        (<?= esc($mc['age'] ?? 'N/A') ?>y • <?= esc($mc['gender'] ?? 'N/A') ?> •
                                                        <?= esc($mc['barangay']) ?>)
                                                    </span>
                                                </div>
                                                <p class="text-xs font-semibold text-violet-700">Complaint / Lab Request:
                                                    <?= esc($mc['chiefComplaint']) ?></p>
                                                <p class="text-[11px] text-slate-400 font-mono"> Requested Date:
                                                    <?= esc($mc['date']) ?></p>
                                            </div>
                                            <div class="flex items-center gap-2 shrink-0">
                                                <span
                                                    class="rounded-lg bg-amber-50 px-2.5 py-1 text-[11px] font-extrabold text-amber-700 border border-amber-200">
                                                    Status: <?= esc($mc['consultation_status']) ?>
                                                </span>
                                                <a href="?tab=rapid&modal=test"
                                                    class="rounded-lg border border-teal-200 bg-teal-50 px-3 py-1 text-xs font-bold text-teal-700 hover:bg-teal-600 hover:text-white transition">
                                                    Perform RDT →
                                                </a>
                                            </div>
                                        </div>

                                        <!-- MEDTECH RESPONSE / UPDATE FORM -->
                                        <details class="group border-t border-teal-100 pt-2" open>
                                            <summary
                                                class="cursor-pointer text-xs font-bold text-teal-700 hover:text-teal-900 flex items-center justify-between py-1">
                                                <span> Answer / Update Diagnostic Test Response for Resident</span>
                                            </summary>
                                            <form method="post"
                                                class="mt-2 bg-white p-3 rounded-xl border border-teal-200/70 space-y-2.5">
                                                <input type="hidden" name="action" value="answer_consultation">
                                                <input type="hidden" name="consultation_id" value="<?= (int) $mc['id']; ?>">
                                                <input type="hidden" name="resident_id"
                                                    value="<?= (int) ($mc['resident_id'] ?? 0); ?>">

                                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                                    <div>
                                                        <label class="block text-[11px] font-bold text-slate-700 mb-0.5">Diagnostic
                                                            Result Summary</label>
                                                        <input type="text" name="diagnosis"
                                                            value="<?= esc($mc['diagnosis'] ?? ''); ?>"
                                                            placeholder="e.g. Fasting Blood Sugar: 95 mg/dL (Normal)"
                                                            class="w-full p-2 border border-slate-300 rounded-lg text-xs outline-none focus:border-teal-500 bg-white"
                                                            required>
                                                    </div>
                                                    <div>
                                                        <label
                                                            class="block text-[11px] font-bold text-slate-700 mb-0.5">Status</label>
                                                        <select name="consultation_status"
                                                            class="w-full p-2 border border-slate-300 rounded-lg text-xs outline-none focus:border-teal-500 bg-white font-bold text-teal-900">
                                                            <option value="Completed" <?= ($mc['consultation_status'] ?? '') === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                                                            <option value="In Progress" <?= ($mc['consultation_status'] ?? '') === 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                                                            <option value="Scheduled" <?= ($mc['consultation_status'] ?? '') === 'Scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                                                            <option value="Referred" <?= ($mc['consultation_status'] ?? '') === 'Referred' ? 'selected' : ''; ?>>Referred to Doctor</option>
                                                        </select>
                                                    </div>
                                                </div>

                                                <div>
                                                    <label class="block text-[11px] font-bold text-slate-700 mb-0.5">MedTech Remarks
                                                        &amp; Lab Notes for Resident</label>
                                                    <textarea name="consultation_notes" rows="2"
                                                        placeholder="Enter laboratory testing details, specimen notes, or collection instructions..."
                                                        class="w-full p-2 border border-slate-300 rounded-lg text-xs outline-none focus:border-violet-500 bg-white resize-none"><?= esc($mc['consultation_notes'] ?? ''); ?></textarea>
                                                </div>

                                                <div class="flex justify-end pt-1">
                                                    <button type="submit"
                                                        class="px-4 py-2 bg-teal-700 hover:bg-teal-800 text-white text-xs font-extrabold rounded-lg shadow-sm transition flex items-center gap-1">
                                                        <span></span> Save Response &amp; Notify Resident
                                                    </button>
                                                </div>
                                            </form>
                                        </details>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>

                    <!-- RHU Rapid Diagnostic Capabilities -->
                    <section
                        class="rounded-2xl border border-violet-200/70 bg-linear-to-br from-violet-50 via-indigo-50/40 to-slate-50 p-6 shadow-sm">
                        <div class="flex items-center gap-2 mb-3">
                            <span class="text-lg">▣</span>
                            <h3 class="text-sm font-bold text-violet-950 uppercase tracking-wide">Available On-Site Rapid
                                Diagnostic Tests (RDTs)</h3>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <?php
                            $rdtCapabilities = [
                                'Blood Glucose (Glucometer)',
                                'Urinalysis (10-Parameter Dipstick)',
                                'HCG Pregnancy Test (Urine)',
                                'HBsAg Rapid Test (Hepatitis B)',
                                'HIV 1/2 Rapid Antibody',
                                'Syphilis (VDRL/RPR) Rapid',
                                'Dengue NS1 Antigen & IgG/IgM RDT',
                                'Malaria Ag Rapid Diagnostic Test',
                                'TB Sputum GeneXpert / AFB Collection'
                            ];
                            foreach ($rdtCapabilities as $capability):
                                ?>
                                <span
                                    class="inline-flex items-center gap-1.5 rounded-xl border border-violet-200 bg-white px-3 py-1.5 text-xs font-bold text-violet-800 shadow-sm hover:border-violet-400 transition">
                                    <span class="w-1.5 h-1.5 rounded-full bg-violet-600"></span>
                                    <?= $capability ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                        <p class="mt-3 text-xs text-violet-700/80 italic">
                            Note: Complex automated chemistry, full CBC counter, and bacterial cultures are processed via
                            the Specimen Referrals module to designated secondary & tertiary hospital laboratories.
                        </p>
                    </section>

                    <!-- Today's RDT Results Widget -->
                    <section class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm">
                        <div class="flex items-center justify-between border-b border-slate-100 pb-3 mb-4">
                            <h3 class="text-sm font-bold text-slate-900 flex items-center gap-2">
                                <span>▤ Today's Completed Diagnostic Tests</span>
                                <span
                                    class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-bold text-slate-600"><?= count($today) ?>
                                    Total</span>
                            </h3>
                            <a href="<?= tabUrl('rapid') ?>" class="text-xs font-bold text-violet-700 hover:underline">View
                                All Test Logs →</a>
                        </div>

                        <?php if (empty($today)): ?>
                            <p class="text-xs text-slate-400 italic py-4 text-center">No diagnostic test results recorded for
                                today yet.</p>
                        <?php else: ?>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <?php foreach ($today as $r): ?>
                                    <div
                                        class="flex items-start gap-3 rounded-xl border <?= in_array($r[6], ['abnormal', 'notable']) ? 'border-rose-200 bg-rose-50/50' : 'border-slate-100 bg-slate-50/60' ?> p-3.5 shadow-2xs">
                                        <span
                                            class="mt-1 h-2.5 w-2.5 shrink-0 rounded-full <?= in_array($r[6], ['abnormal', 'notable']) ? 'bg-rose-500 ' : 'bg-emerald-500' ?>"></span>
                                        <div class="min-w-0 flex-1 space-y-1">
                                            <div class="flex items-center justify-between gap-2">
                                                <b class="truncate text-xs text-slate-900 font-bold"><?= esc($r[1]) ?></b>
                                                <span
                                                    class="rounded-md border px-2 py-0.5 text-[10px] font-extrabold <?= flagClass($r[6]) ?>">
                                                    <?= ucfirst($r[6]) ?>
                                                </span>
                                            </div>
                                            <p class="text-xs font-semibold text-violet-700"><?= esc($r[4]) ?></p>
                                            <p class="text-xs font-bold text-slate-800">Result: <?= esc($r[5]) ?></p>
                                            <p class="text-[10px] text-slate-400">Ordered by: <?= esc($r[8]) ?></p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>

                    <!-- TAB 2: RAPID DIAGNOSTIC TESTS -->
                <?php elseif ($tab === 'rapid'): ?>


                    <div
                        class="flex flex-wrap items-center justify-between gap-3 dashboard-card bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
                        <div>
                            <h2 class="text-base sm:text-lg font-bold text-slate-800">Rapid Diagnostic Test (RDT) Log</h2>
                            <p class="text-xs text-slate-500 font-medium">Manage on-site rapid laboratory test findings and
                                patient diagnostic reports.</p>
                        </div>
                        <a href="<?= esc(tabUrl('rapid', ['modal' => 'test'])); ?>"
                            class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white text-xs font-bold rounded-xl shadow-md shadow-teal-600/20 transition flex items-center gap-1.5">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                <path d="M5 12h14" />
                                <path d="M12 5v14" />
                            </svg> Record RDT Result
                        </a>
                    </div>


                    <!-- MODAL: RECORD RDT RESULT -->


                    <!-- Critical Clinical Notice -->
                    <div
                        class="rounded-2xl border border-amber-200 bg-amber-50/90 p-4 text-xs font-semibold text-amber-900 flex items-center gap-3">
                        <span class="text-base"></span>
                        <p>Clinical Directive: All abnormal, positive, or critical diagnostic results must be immediately
                            communicated to the attending MHO physician or requesting nurse for rapid clinical intervention.
                        </p>
                    </div>

                    <!-- Test Search Bar -->
                    <div class="flex items-center gap-2 rounded-2xl border border-slate-200 bg-white p-3 shadow-2xs">
                        <span class="text-slate-400"></span>
                        <input type="text" id="rdtSearchInput" onkeyup="filterRDTs()"
                            placeholder="Search test results by patient name, test type, or status..."
                            class="w-full text-xs outline-none bg-transparent">
                    </div>

                    <!-- RDT Cards List -->
                    <div id="rdtListContainer" class="space-y-3">
                        <?php if (empty($tests)): ?>
                            <div class="rounded-2xl border border-slate-200 bg-white p-8 text-center">
                                <span class="mx-auto mb-2 flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-400"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg></span>
                                <p class="mt-2 text-sm font-bold text-slate-700">No RDT entries logged yet.</p>
                                <p class="text-xs text-slate-400">Click "Record RDT Result" to log a new diagnostic finding.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($tests as $r): ?>
                                <article
                                    class="rdt-item rounded-2xl border <?= in_array($r[6], ['abnormal', 'notable']) ? 'border-rose-200 bg-rose-50/30' : 'border-slate-200/80 bg-white' ?> p-4 shadow-sm hover:shadow-md transition">
                                    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
                                        <div class="space-y-1 min-w-0">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span
                                                    class="rounded-md bg-slate-100 px-2 py-0.5 font-mono text-[11px] font-bold text-slate-600 border border-slate-200"><?= esc($r[0]) ?></span>
                                                <span
                                                    class="rounded-full border px-2.5 py-0.5 text-xs font-extrabold <?= flagClass($r[6]) ?>">
                                                    <?= ucfirst($r[6]) ?>
                                                </span>
                                                <span class="text-xs text-slate-400"> <?= esc($r[9]) ?></span>
                                            </div>
                                            <h3 class="text-sm font-bold text-slate-900 mt-1">
                                                <?= esc($r[1]) ?>, <span class="font-medium text-slate-600"><?= $r[2] ?> yrs ·
                                                    <?= esc($r[3]) ?></span>
                                            </h3>
                                            <p class="text-xs font-bold text-violet-700"><?= esc($r[4]) ?></p>
                                            <p
                                                class="text-sm font-extrabold <?= $r[6] === 'normal' ? 'text-slate-800' : 'text-rose-600' ?>">
                                                Result: <?= esc($r[5]) ?>
                                                <span class="text-xs font-normal text-slate-400">(Type/Ref:
                                                    <?= esc($r[7]) ?>)</span>
                                            </p>
                                        </div>
                                        <div class="text-left sm:text-right shrink-0 text-xs text-slate-500">
                                            <p class="font-semibold text-slate-700">Ordered by:</p>
                                            <p><?= esc($r[8]) ?></p>
                                        </div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <script>
                        function filterRDTs() {
                            const query = document.getElementById('rdtSearchInput').value.toLowerCase();
                            const items = document.querySelectorAll('.rdt-item');
                            items.forEach(item => {
                                const text = item.textContent.toLowerCase();
                                item.style.display = text.includes(query) ? '' : 'none';
                            });
                        }
                    </script>

                    <!-- TAB 3: SPECIMEN REFERRALS -->
                <?php elseif ($tab === 'referrals'): ?>


                    <div
                        class="flex flex-wrap items-center justify-between gap-3 dashboard-card bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
                        <div>
                            <h2 class="text-base sm:text-lg font-bold text-slate-800">Specimen Referrals &amp; External
                                Testing</h2>
                            <p class="text-xs text-slate-500 font-medium">Track diagnostic specimens sent to higher-level
                                partner hospital laboratories.</p>
                        </div>
                        <a href="<?= esc(tabUrl('referrals', ['modal' => 'referral'])); ?>"
                            class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white text-xs font-bold rounded-xl shadow-md shadow-teal-600/20 transition flex items-center gap-1.5">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                <path d="M5 12h14" />
                                <path d="M12 5v14" />
                            </svg> New Referral
                        </a>
                    </div>


                    <!-- MODAL: NEW REFERRAL -->


                    <div class="grid grid-cols-3 gap-4">
                        <div class="rounded-2xl border border-amber-100 bg-white p-4 text-center shadow-sm">
                            <b class="text-2xl font-black text-amber-600"><?= $pending ?></b>
                            <p class="text-xs font-bold text-slate-500">Pending Results</p>
                        </div>
                        <div class="rounded-2xl border border-emerald-100 bg-white p-4 text-center shadow-sm">
                            <b class="text-2xl font-black text-emerald-600"><?= count($referrals) - $pending ?></b>
                            <p class="text-xs font-bold text-slate-500">Results Received</p>
                        </div>
                        <div class="rounded-2xl border border-sky-100 bg-white p-4 text-center shadow-sm">
                            <b class="text-2xl font-black text-sky-700"><?= count($referrals) ?></b>
                            <p class="text-xs font-bold text-slate-500">Total Referrals</p>
                        </div>
                    </div>

                    <div class="space-y-3">
                        <?php if (empty($referrals)): ?>
                            <div class="rounded-2xl border border-slate-200 bg-white p-8 text-center">
                                <span class="mx-auto mb-2 flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-400"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg></span>
                                <p class="mt-2 text-sm font-bold text-slate-700">No specimen referrals logged.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($referrals as $r): ?>
                                <article
                                    class="rounded-2xl border <?= $r[6] === 'pending' ? 'border-amber-200 bg-amber-50/20' : 'border-slate-200/80 bg-white' ?> p-4 shadow-sm hover:shadow-md transition">
                                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-2">
                                        <div class="flex items-center gap-2">
                                            <span
                                                class="rounded bg-slate-100 px-2 py-0.5 font-mono text-xs font-bold text-slate-600 border border-slate-200"><?= $r[0] ?></span>
                                            <span
                                                class="rounded-full border px-2.5 py-0.5 text-xs font-extrabold <?= $r[6] === 'pending' ? 'bg-amber-100 text-amber-800 border-amber-200' : 'bg-emerald-100 text-emerald-800 border-emerald-200' ?>">
                                                <?= $r[6] === 'pending' ? 'Pending External Result' : ' Result Received' ?>
                                            </span>
                                        </div>
                                        <span class="text-xs text-slate-400"> Date: <?= $r[5] ?></span>
                                    </div>
                                    <div class="mt-3 space-y-1">
                                        <b class="text-sm text-slate-900"><?= esc($r[1]) ?>, <?= $r[2] ?> yrs</b>
                                        <p class="text-xs font-bold text-sky-700">Requested: <?= esc($r[3]) ?></p>
                                        <p class="text-xs font-semibold text-slate-600">→ Destination Facility: <?= esc($r[4]) ?>
                                        </p>
                                        <?php if ($r[6] !== 'pending'): ?>
                                            <div
                                                class="mt-2 rounded-xl bg-emerald-50 p-2.5 text-xs font-bold text-emerald-800 border border-emerald-200">
                                                Result Findings: <?= esc($r[7]) ?>
                                            </div>
                                        <?php endif; ?>
                                        <p class="text-[11px] text-slate-400 mt-1">Notes: <?= esc($r[8]) ?></p>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- TAB 4: TEST SUPPLIES -->
                <?php elseif ($tab === 'supplies'): ?>


                    <div
                        class="flex flex-wrap items-center justify-between gap-3 dashboard-card bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
                        <div>
                            <h2 class="text-base sm:text-lg font-bold text-slate-800">Diagnostic Test Kit &amp; Supply
                                Inventory</h2>
                            <p class="text-xs text-slate-500 font-medium">Track RDT test strip availability, reorder
                                thresholds, and expiration dates.</p>
                        </div>
                        <a href="<?= esc(tabUrl('supplies', ['modal' => 'supply'])); ?>"
                            class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white text-xs font-bold rounded-xl shadow-md shadow-teal-600/20 transition flex items-center gap-1.5">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                <path d="M5 12h14" />
                                <path d="M12 5v14" />
                            </svg> Add Supply Item
                        </a>
                    </div>


                    <!-- MODAL: ADD SUPPLY ITEM -->


                    <div class="grid grid-cols-3 gap-4">
                        <div class="rounded-2xl border border-rose-100 bg-rose-50 p-4 text-center">
                            <b class="text-2xl font-black text-rose-600"><?= $critical ?></b>
                            <p class="text-xs font-bold text-rose-800">Critical Outages</p>
                        </div>
                        <div class="rounded-2xl border border-amber-100 bg-amber-50 p-4 text-center">
                            <b class="text-2xl font-black text-amber-600"><?= $low ?></b>
                            <p class="text-xs font-bold text-amber-800">Low Stock Items</p>
                        </div>
                        <div class="rounded-2xl border border-emerald-100 bg-emerald-50 p-4 text-center">
                            <b class="text-2xl font-black text-emerald-600"><?= count($supplies) - $critical - $low ?></b>
                            <p class="text-xs font-bold text-emerald-800">Adequate Supplies</p>
                        </div>
                    </div>

                    <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <table class="w-full min-w-[620px] text-xs">
                            <thead class="bg-slate-50 text-left uppercase text-slate-500 border-b border-slate-200">
                                <tr>
                                    <th class="p-3.5 font-extrabold">Item Description</th>
                                    <th class="p-3.5 font-extrabold">Category</th>
                                    <th class="p-3.5 font-extrabold">In Stock</th>
                                    <th class="p-3.5 font-extrabold">Reorder Threshold</th>
                                    <th class="p-3.5 font-extrabold">Expiration Date</th>
                                    <th class="p-3.5 font-extrabold">Stock Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($supplies as $s): ?>
                                    <tr class="hover:bg-slate-50/80 transition">
                                        <td class="p-3.5 font-bold text-slate-900"><?= esc($s[0]) ?></td>
                                        <td class="p-3.5 text-slate-600"><?= esc($s[1]) ?></td>
                                        <td class="p-3.5 font-black text-slate-900"><?= $s[2] ?> <span
                                                class="font-normal text-slate-400"><?= esc($s[3]) ?></span></td>
                                        <td class="p-3.5 text-slate-600"><?= $s[4] ?>         <?= esc($s[3]) ?></td>
                                        <td class="p-3.5 text-slate-600 font-mono"><?= $s[5] ?: 'N/A' ?></td>
                                        <td class="p-3.5">
                                            <span
                                                class="rounded-full border px-2.5 py-1 text-[11px] font-extrabold <?= $s[6] === 'critical' ? 'bg-rose-100 text-rose-800 border-rose-200' : ($s[6] === 'low' ? 'bg-amber-100 text-amber-800 border-amber-200' : 'bg-emerald-100 text-emerald-800 border-emerald-200') ?>">
                                                <?= strtoupper($s[6]) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- TAB 5: REPORTS -->
                <?php else: ?>

                    <div class="border-b border-slate-200/80 pb-4">
                        <h2 class="text-xl font-black text-slate-900 tracking-tight flex items-center gap-2">
                            <span class="inline-flex text-teal-700"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/></svg></span><span>Laboratory Diagnostic Reports &amp; Analytics</span>
                        </h2>
                        <p class="text-xs text-slate-500 mt-0.5">Statistical breakdown of RDT tests performed and referral
                            trends.</p>
                    </div>

                    <?php
                    $testCounts = [];
                    foreach ($tests as $recordedTest) {
                        $testCounts[$recordedTest[4]] = ($testCounts[$recordedTest[4]] ?? 0) + 1;
                    }
                    arsort($testCounts);
                    $monthly = array_map(function ($name, $count) { return [$name, $count]; }, array_keys($testCounts), array_values($testCounts));
                    $total = array_sum(array_column($monthly, 1));
                    $chartMaximum = $monthly ? max(array_column($monthly, 1)) : 1;

                    $facilityCounts = [];
                    foreach ($referrals as $recordedReferral) {
                        $facilityCounts[$recordedReferral[4]] = ($facilityCounts[$recordedReferral[4]] ?? 0) + 1;
                    }
                    arsort($facilityCounts);
                    ?>

                    <section class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm space-y-4">
                        <h3 class="font-bold text-slate-900 text-sm">Diagnostic Test Volume Breakdown</h3>
                        <?php if (!$monthly): ?>
                            <p class="text-xs text-slate-400 italic">No diagnostic test records available for reporting.</p>
                        <?php endif; ?>

                        <div class="space-y-3">
                            <?php foreach ($monthly as [$name, $count]): ?>
                                <div class="flex items-center gap-3">
                                    <span class="w-48 text-xs font-bold text-slate-700 truncate"><?= esc($name) ?></span>
                                    <div class="h-3 flex-1 rounded-full bg-slate-100 overflow-hidden">
                                        <div class="h-3 rounded-full bg-violet-600 transition duration-500"
                                            style="width:<?= round($count / $chartMaximum * 100) ?>%"></div>
                                    </div>
                                    <b class="w-10 text-right text-xs text-slate-900 font-extrabold"><?= $count ?></b>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <section class="grid gap-4 md:grid-cols-2">
                        <div class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm space-y-3">
                            <h3 class="font-bold text-slate-900 text-sm border-b border-slate-100 pb-2">Monthly Activity
                                Summary</h3>
                            <?php foreach ([
                                ['Total RDTs Performed', $total],
                                ['External Specimen Referrals', count($referrals)],
                                ['Abnormal Diagnostic Flags', $abnormal],
                                ['Tracked Supply Items', count($supplies)]
                            ] as [$label, $value]): ?>
                                <div class="flex justify-between py-2 text-xs border-b border-slate-50">
                                    <span class="text-slate-600 font-medium"><?= $label ?></span>
                                    <b class="text-slate-900 font-extrabold text-sm"><?= $value ?></b>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm space-y-3">
                            <h3 class="font-bold text-slate-900 text-sm border-b border-slate-100 pb-2">Referral Facility
                                Breakdown</h3>
                            <?php if (!$facilityCounts): ?>
                                <p class="text-xs text-slate-400 italic">No external laboratory referrals recorded.</p>
                            <?php endif; ?>

                            <?php foreach ($facilityCounts as $facility => $facilityCount): ?>
                                <div class="flex items-center justify-between rounded-xl bg-slate-50 p-3 text-xs">
                                    <span class="font-bold text-slate-800"><?= esc($facility) ?></span>
                                    <b
                                        class="rounded-full bg-sky-100 px-2.5 py-0.5 text-xs text-sky-800 font-extrabold border border-sky-200"><?= (int) $facilityCount ?>
                                        Referral(s)</b>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>

                <?php endif; ?>

                <?php if ($tab === 'certificates'): ?>
                    <?= portalRenderCertificateIssuancePanel($pdo, $residentOptions, $medtechCertificateTypes, $medtechStaffId, 'violet') ?>
                <?php endif; ?>

            </main>
        </div>
    </div>


    <!-- MOBILE BOTTOM NAVIGATION -->

    </div>

    <?php if ($modal === 'test'): ?>
        <div
            class="fixed inset-0 bg-slate-950/45 backdrop-blur-sm flex items-end sm:items-center justify-center z-50 p-3 sm:p-4">
            <div
                class="bg-white rounded-2xl shadow-2xl w-full sm:max-w-xl max-h-[92vh] flex flex-col overflow-hidden border border-slate-200">
                <div
                    class="p-5 border-b border-slate-200 flex items-center justify-between bg-white rounded-t-2xl shrink-0">
                    <div>
                        <h2 class="text-base font-bold text-slate-800">Record Rapid Diagnostic Test</h2>
                        <p class="text-xs text-slate-500">Log RDT result and notify the resident</p>
                    </div>
                    <a href="<?= esc(tabUrl('rapid')); ?>"
                        class="text-slate-400 hover:text-slate-700 w-8 h-8 rounded-full hover:bg-slate-100 flex items-center justify-center"><svg
                            class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path d="M18 6 6 18" />
                            <path d="m6 6 12 12" />
                        </svg></a>
                </div>
                <form class="p-5 space-y-4 text-xs overflow-y-auto" method="post">

                    <input type="hidden" name="action" value="save_test">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Patient Consultation *</label>
                        <select required name="consultation_id"
                            class="w-full p-3 border border-slate-300 rounded-xl text-sm font-semibold focus:border-teal-500 focus:ring-2 focus:ring-teal-200 outline-none">
                            <option value="">Select Patient Consultation...</option>
                            <?php foreach ($consultationOptions as $o): ?>
                                <option value="<?= (int) $o['id'] ?>"><?= esc($o['patient']) ?> —
                                    <?= esc($o['chief_complaint']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Test Name / Kit *</label>
                            <input required name="test_name" placeholder="e.g. Blood Glucose, Dengue NS1"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Test Type / Reference</label>
                            <input name="test_type" placeholder="e.g. Fasting Blood Sugar (70-99 mg/dL)"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Test Date *</label>
                            <input required type="date" name="test_date" value="<?= date('Y-m-d') ?>"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm font-mono">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Result Status</label>
                            <select name="test_status" class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option>Normal</option>
                                <option>Abnormal</option>
                                <option>Positive</option>
                                <option>Negative</option>
                                <option>Pending</option>
                            </select>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block font-bold text-slate-700 mb-1">Ordered By</label>
                            <select name="ordered_by_id" class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option value="">Select staff...</option>
                                <?php foreach ($staffOptions as $o): ?>
                                    <option value="<?= (int) $o['id'] ?>"><?= esc($o['name']) ?> (<?= esc($o['staff_type']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Results / Findings</label>
                        <textarea name="results" rows="3" placeholder="Enter laboratory findings..."
                            class="w-full p-3 border border-slate-300 rounded-xl text-xs resize-none"></textarea>
                    </div>

                    <div class="flex gap-3 pt-2">
                        <a href="<?= esc(tabUrl('rapid')); ?>"
                            class="flex-1 py-2.5 border border-slate-300 rounded-xl text-xs font-bold text-slate-700 hover:bg-slate-50 text-center">Cancel</a>
                        <button type="submit"
                            class="flex-1 py-2.5 bg-teal-600 text-white rounded-xl text-xs font-bold hover:bg-teal-700 shadow-md shadow-teal-600/20 transition">Save
                            Record</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($modal === 'referral'): ?>
        <div
            class="fixed inset-0 bg-slate-950/45 backdrop-blur-sm flex items-end sm:items-center justify-center z-50 p-3 sm:p-4">
            <div
                class="bg-white rounded-2xl shadow-2xl w-full sm:max-w-xl max-h-[92vh] flex flex-col overflow-hidden border border-slate-200">
                <div
                    class="p-5 border-b border-slate-200 flex items-center justify-between bg-white rounded-t-2xl shrink-0">
                    <div>
                        <h2 class="text-base font-bold text-slate-800">Create Specimen Referral</h2>
                        <p class="text-xs text-slate-500">Send specimen to external laboratory</p>
                    </div>
                    <a href="<?= esc(tabUrl('referrals')); ?>"
                        class="text-slate-400 hover:text-slate-700 w-8 h-8 rounded-full hover:bg-slate-100 flex items-center justify-center"><svg
                            class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path d="M18 6 6 18" />
                            <path d="m6 6 12 12" />
                        </svg></a>
                </div>
                <form class="p-5 space-y-4 text-xs overflow-y-auto" method="post">

                    <input type="hidden" name="action" value="save_lab_referral">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Resident Patient *</label>
                        <select required name="resident_id"
                            class="w-full p-3 border border-slate-300 rounded-xl text-sm font-semibold focus:border-teal-500 focus:ring-2 focus:ring-teal-200 outline-none">
                            <option value="">Select Resident Patient...</option>
                            <?php foreach ($residentOptions as $o): ?>
                                <option value="<?= (int) $o['id'] ?>"><?= esc($o['name']) ?> (<?= esc($o['barangay']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Test Requested *</label>
                            <input required name="test_requested" placeholder="e.g. Full CBC, Lipid Profile"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Destination Facility *</label>
                            <input required name="destination_facility"
                                placeholder="e.g. Apacible Memorial District Hospital"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Referral Date *</label>
                            <input required type="date" name="referral_date" value="<?= date('Y-m-d') ?>"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm font-mono">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Referring Staff</label>
                            <select name="referred_by_id" class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                                <option value="">Select staff...</option>
                                <?php foreach ($staffOptions as $o): ?>
                                    <option value="<?= (int) $o['id'] ?>"><?= esc($o['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Clinical Notes</label>
                        <textarea name="clinical_notes" rows="3" placeholder="Reason for referral..."
                            class="w-full p-3 border border-slate-300 rounded-xl text-xs resize-none"></textarea>
                    </div>

                    <div class="flex gap-3 pt-2">
                        <a href="<?= esc(tabUrl('referrals')); ?>"
                            class="flex-1 py-2.5 border border-slate-300 rounded-xl text-xs font-bold text-slate-700 hover:bg-slate-50 text-center">Cancel</a>
                        <button type="submit"
                            class="flex-1 py-2.5 bg-teal-600 text-white rounded-xl text-xs font-bold hover:bg-teal-700 shadow-md shadow-teal-600/20 transition">Save
                            Record</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($modal === 'supply'): ?>
        <div
            class="fixed inset-0 bg-slate-950/45 backdrop-blur-sm flex items-end sm:items-center justify-center z-50 p-3 sm:p-4">
            <div
                class="bg-white rounded-2xl shadow-2xl w-full sm:max-w-xl max-h-[92vh] flex flex-col overflow-hidden border border-slate-200">
                <div
                    class="p-5 border-b border-slate-200 flex items-center justify-between bg-white rounded-t-2xl shrink-0">
                    <div>
                        <h2 class="text-base font-bold text-slate-800">Add Laboratory Supply</h2>
                        <p class="text-xs text-slate-500">Register test kit or reagent inventory</p>
                    </div>
                    <a href="<?= esc(tabUrl('supplies')); ?>"
                        class="text-slate-400 hover:text-slate-700 w-8 h-8 rounded-full hover:bg-slate-100 flex items-center justify-center"><svg
                            class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path d="M18 6 6 18" />
                            <path d="m6 6 12 12" />
                        </svg></a>
                </div>
                <form class="p-5 space-y-4 text-xs overflow-y-auto" method="post">

                    <input type="hidden" name="action" value="save_lab_supply">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="sm:col-span-2">
                            <label class="block font-bold text-slate-700 mb-1">Item Name *</label>
                            <input required name="item_name" placeholder="e.g. Glucometer Test Strips"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Category</label>
                            <input name="category" placeholder="e.g. Rapid Test Kits"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Quantity *</label>
                            <input required type="number" min="0" name="quantity" placeholder="0"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Unit *</label>
                            <input required name="unit" value="kits" placeholder="kits, boxes, strips"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Reorder Level *</label>
                            <input required type="number" min="0" name="reorder_level" value="10"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block font-bold text-slate-700 mb-1">Expiration Date</label>
                            <input type="date" name="expiry_date"
                                class="w-full p-3 border border-slate-300 rounded-xl text-sm font-mono">
                        </div>
                    </div>

                    <div class="flex gap-3 pt-2">
                        <a href="<?= esc(tabUrl('supplies')); ?>"
                            class="flex-1 py-2.5 border border-slate-300 rounded-xl text-xs font-bold text-slate-700 hover:bg-slate-50 text-center">Cancel</a>
                        <button type="submit"
                            class="flex-1 py-2.5 bg-teal-600 text-white rounded-xl text-xs font-bold hover:bg-teal-700 shadow-md shadow-teal-600/20 transition">Save
                            Record</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?= portalRenderNotificationPanel(); ?>

    <script>
        (function () {
            const sidebar = document.querySelector('[data-feature-drawer]');
            const backdrop = document.querySelector('[data-drawer-backdrop]');
            const openBtn = document.querySelector('[data-drawer-open]');
            const closeBtn = document.querySelector('[data-drawer-close]');
            const setOpen = (open) => {
                sidebar?.classList.toggle('is-open', open);
                backdrop?.classList.toggle('is-open', open);
                document.body.classList.toggle('drawer-open', open);
                openBtn?.setAttribute('aria-expanded', String(!!open));
            };
            openBtn?.addEventListener('click', () => setOpen(true));
            closeBtn?.addEventListener('click', () => setOpen(false));
            backdrop?.addEventListener('click', () => setOpen(false));
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape') setOpen(false); });
        })();
    </script>
</body>

</html>
