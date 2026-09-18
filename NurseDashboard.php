<?php
if (session_status() === PHP_SESSION_NONE)
    session_start();
$stType = strtoupper((string) ($_SESSION['rhu_staff_login']['staff_type'] ?? ''));
if (empty($_SESSION['rhu_staff_login']) || ($stType !== 'NURSE' && strpos($stType, 'NURSE') === false)) {
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

function parseTriageVitals(string $notes): array
{
    $vitals = [];
    if (preg_match('/BP:\s*([^\s,]+)/i', $notes, $m))
        $vitals['bp'] = $m[1];
    if (preg_match('/Temp:\s*([^\s,]+)/i', $notes, $m))
        $vitals['temp'] = $m[1];
    if (preg_match('/(?:Wt|Weight):\s*([^\s,]+(?:\s*kg)?)/i', $notes, $m))
        $vitals['weight'] = $m[1];
    if (preg_match('/RR:\s*([^\s,]+)/i', $notes, $m))
        $vitals['rr'] = $m[1];
    if (preg_match('/HR:\s*([^\s,]+)/i', $notes, $m))
        $vitals['hr'] = $m[1];
    return array_filter($vitals);
}

function dbHasColumn(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (!array_key_exists($key, $cache)) {
        try {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE :column_name");
            $stmt->execute(['column_name' => $column]);
            $cache[$key] = (bool) $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $cache[$key] = false;
        }
    }
    return $cache[$key];
}

function dbHasTable(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (!array_key_exists($table, $cache)) {
        try {
            $stmt = $pdo->prepare("SHOW TABLES LIKE :table_name");
            $stmt->execute(['table_name' => $table]);
            $cache[$table] = (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            $cache[$table] = false;
        }
    }
    return $cache[$table];
}

function nurseBarangayId(PDO $pdo, string $barangay): int
{
    $name = trim($barangay);
    if ($name === '') {
        $name = 'Poblacion';
    }
    $stmt = $pdo->prepare("SELECT id FROM barangays WHERE name = :name LIMIT 1");
    $stmt->execute(['name' => $name]);
    $id = (int) ($stmt->fetchColumn() ?: 0);
    if ($id > 0) {
        return $id;
    }
    return (int) ($pdo->query("SELECT id FROM barangays ORDER BY id LIMIT 1")->fetchColumn() ?: 1);
}

$tabs = [
    'overview' => ['Overview', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>'],
    'opd' => ['OPD Triage', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>'],
    'requests' => ['Resident Requests', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M21 15a4 4 0 0 1-4 4H7l-4 4V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z"/><path d="M8 8h8M8 12h6"/></svg>'],
    'patients' => ['Patient Records', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>'],
    'immunization' => ['Immunization', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="m18 2 4 4"/><path d="m17 7 3-3"/><path d="M19 9 8.7 19.3c-1 1-2.5 1-3.4 0l-.6-.6c-1-1-1-2.5 0-3.4L15 5"/><path d="m9 11 4 4"/><path d="m5 19-3 3"/><path d="m14 4 6 6"/></svg>'],
    'nutrition' => ['Nutrition (OPT+)', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M12 20.94c1.5-1.35 4.5-4.05 4.5-7.44A4.5 4.5 0 0 0 12 9a4.5 4.5 0 0 0-4.5 4.5c0 3.39 3 6.09 4.5 7.44z"/><path d="M12 9V5"/><path d="M12 5c.5-1 1.5-2 3-2"/></svg>'],
    'tb' => ['TB-DOTS', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M9 3h6v4a3 3 0 0 1-6 0V3z"/><path d="M9 7v13"/><path d="M15 7v13"/><path d="M9 12h6"/></svg>'],
    'disease' => ['Disease Surveillance', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>'],
    'bhw' => ['BHW Management', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>'],
];

$tab = $_GET['tab'] ?? 'overview';
if (!isset($tabs[$tab]))
    $tab = 'overview';

$modal = $_GET['modal'] ?? '';
$selectedResidentId = (int) ($_GET['resident_id'] ?? 0);
$flashSuccess = $_SESSION['nurse_flash_success'] ?? '';
$flashError = $_SESSION['nurse_flash_error'] ?? '';
unset($_SESSION['nurse_flash_success'], $_SESSION['nurse_flash_error']);

// ----------------------------------------------------
// 1. POST FORM HANDLERS FOR NURSING & OPD TRIAGE
// ----------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !empty($pdo)) {
    $action = $_POST['action'] ?? '';

    if ($action === 'issue_certificate') {
        try {
            if (dbHasTable($pdo, 'certificate_types') && dbHasTable($pdo, 'health_certificates')) {
                $issued = portalIssueResidentCertificate($pdo, $_POST, (int) ($_SESSION['rhu_staff_login']['staff_id'] ?? 0), 'Public Health Nurse');
                $_SESSION['nurse_flash_success'] = "{$issued['type']} {$issued['number']} was issued and sent to the Resident.";
            } else {
                $fallbackTypes = [
                    1 => 'Nursing Health Assessment Certificate',
                    2 => 'Immunization Status Certificate',
                    3 => 'Vital Signs Assessment Certificate',
                    4 => 'Community Health Clearance',
                ];
                $residentId = (int) ($_POST['resident_id'] ?? 0);
                $typeId = (int) ($_POST['certificate_type_id'] ?? 0);
                $typeName = $fallbackTypes[$typeId] ?? 'Community Health Clearance';
                $purpose = trim((string) ($_POST['purpose'] ?? ''));
                $issueDate = trim((string) ($_POST['issue_date'] ?? date('Y-m-d')));
                $expiryDate = trim((string) ($_POST['expiry_date'] ?? ''));
                if ($residentId <= 0 || $purpose === '') {
                    throw new InvalidArgumentException('Resident and purpose are required.');
                }
                $certificateNumber = 'NUR-' . date('Ymd-His') . '-' . str_pad((string) $residentId, 4, '0', STR_PAD_LEFT);
                $stmt = $pdo->prepare("INSERT INTO certificates (resident_id, issued_by_id, certificate_number, certificate_type, purpose, issue_date, expiry_date, status, created_at) VALUES (:resident_id, :issued_by_id, :certificate_number, :certificate_type, :purpose, :issue_date, :expiry_date, 'Issued', NOW())");
                $stmt->execute([
                    'resident_id' => $residentId,
                    'issued_by_id' => (int) ($_SESSION['rhu_staff_login']['staff_id'] ?? 0) ?: null,
                    'certificate_number' => $certificateNumber,
                    'certificate_type' => $typeName,
                    'purpose' => $purpose,
                    'issue_date' => $issueDate,
                    'expiry_date' => $expiryDate !== '' ? $expiryDate : null,
                ]);
                portalNotifyResident($pdo, $residentId, "{$typeName} {$certificateNumber} was issued by the Public Health Nurse.", "ResidentDashboard.php?tab=certificates");
                $_SESSION['nurse_flash_success'] = "{$typeName} {$certificateNumber} was issued and sent to the Resident.";
            }
        } catch (Throwable $e) {
            $_SESSION['nurse_flash_error'] = 'Certificate Error: ' . $e->getMessage();
        }
        header('Location: ' . tabUrl('overview'));
        exit;
    }

    // Action: Answer / Update Resident Consultation
    if ($action === 'answer_consultation') {
        $cslId = (int) ($_POST['consultation_id'] ?? 0);
        $resId = (int) ($_POST['resident_id'] ?? 0);
        $diagnosis = trim($_POST['diagnosis'] ?? '');
        $icd10 = trim($_POST['icd10'] ?? '');
        $notes = trim($_POST['consultation_notes'] ?? '');
        $meds = trim($_POST['medications_prescribed'] ?? '');
        $status = trim($_POST['consultation_status'] ?? 'Completed');
        $allowedStatuses = ['Completed', 'In Progress', 'Scheduled', 'Referred'];
        if (!in_array($status, $allowedStatuses, true)) {
            $status = 'In Progress';
        }

        if ($cslId <= 0) {
            $_SESSION['nurse_flash_error'] = 'No consultation record was submitted. Please reopen the record and try again.';
            header('Location: ' . tabUrl('opd'));
            exit;
        }

        if ($cslId > 0 && !empty($pdo)) {
            try {
                $baseNotes = trim((string)preg_replace('/^\s*Status:\s*.*$/mi', '', $notes));
                $statusLine = $status !== '' ? "\nStatus: {$status}" : '';
                $icdLine = $icd10 !== '' ? "\nICD-10: {$icd10}" : '';
                $updateColumns = [
                    'diagnosis = :dx',
                    'remarks = :notes',
                    'medications_prescribed = :meds',
                    'treatment = :treatment',
                ];
                $params = [
                    'dx' => $diagnosis,
                    'notes' => trim($baseNotes . $icdLine . $statusLine),
                    'meds' => $meds,
                    'treatment' => $meds,
                    'id' => $cslId
                ];
                if (dbHasColumn($pdo, 'consultations', 'consultation_notes')) {
                    $updateColumns[] = 'consultation_notes = :consultation_notes';
                    $params['consultation_notes'] = trim($baseNotes . $icdLine . $statusLine);
                }
                if (dbHasColumn($pdo, 'consultations', 'health_worker_id')) {
                    $updateColumns[] = 'health_worker_id = COALESCE(:health_worker_id, health_worker_id)';
                    $params['health_worker_id'] = (int) ($_SESSION['rhu_staff_login']['staff_id'] ?? 0) ?: null;
                }
                if (dbHasColumn($pdo, 'consultations', 'updated_at')) {
                    $updateColumns[] = 'updated_at = NOW()';
                }
                if (dbHasColumn($pdo, 'consultations', 'consultation_status')) {
                    $updateColumns[] = 'consultation_status = :saved_consultation_status';
                    $params['saved_consultation_status'] = $status;
                }
                $stmt = $pdo->prepare("UPDATE consultations SET " . implode(', ', $updateColumns) . " WHERE id = :id");
                $stmt->execute($params);
                if ($stmt->rowCount() === 0) {
                    $existsStmt = $pdo->prepare('SELECT id FROM consultations WHERE id = :id LIMIT 1');
                    $existsStmt->execute(['id' => $cslId]);
                    if (!$existsStmt->fetchColumn()) {
                        throw new RuntimeException("Consultation #{$cslId} was not found.");
                    }
                }
                $savedStatus = $status;
                if (dbHasColumn($pdo, 'consultations', 'consultation_status')) {
                    $statusCheck = $pdo->prepare('SELECT consultation_status FROM consultations WHERE id = :id LIMIT 1');
                    $statusCheck->execute(['id' => $cslId]);
                    $savedStatus = trim((string)($statusCheck->fetchColumn() ?? ''));
                    if ($savedStatus === '') {
                        throw new RuntimeException('Consultation status was not saved.');
                    }
                    if ($savedStatus !== $status) {
                        throw new RuntimeException('Consultation status could not be synchronized.');
                    }
                }
                portalSaveHealthRecordEntry($pdo, $resId, [
                    'record_type' => 'Nurse consultation response',
                    'diagnosis' => trim($diagnosis . ($icd10 !== '' ? " ({$icd10})" : '')),
                    'notes' => $notes,
                    'medications' => $meds,
                    'status' => $status,
                    'recorded_by_id' => (int) ($_SESSION['rhu_staff_login']['staff_id'] ?? 0) ?: null,
                ]);
                if ($resId > 0) {
                    portalNotifyResident($pdo, $resId, "Your OPD Triage / Consultation request has been updated by the Nurse. Status: {$status}. Assessment: {$diagnosis}", "ResidentDashboard.php?tab=appointments");
                }
                $_SESSION['nurse_flash_success'] = "Consultation updated successfully. Saved status: {$savedStatus}. Resident and Admin dashboards will show the same status.";
            } catch (Exception $e) {
                $_SESSION['nurse_flash_error'] = 'Error updating consultation: ' . $e->getMessage();
            }
        }
        $returnTab = ($_POST['return_tab'] ?? '') === 'opd' ? 'opd' : 'overview';
        header('Location: ' . tabUrl($returnTab));
        exit;
    }

    // Action: Save New OPD Triage & Vitals
    if ($action === 'save_triage') {
        $residentId = (int) ($_POST['resident_id'] ?? 0);
        $nurseStaffId = (int) ($_SESSION['rhu_staff_login']['staff_id'] ?? 0);
        $nurseUserId = (int) ($_SESSION['rhu_staff_login']['user_id'] ?? $_SESSION['rhu_staff_login']['id'] ?? 0);
        $physicianId = $nurseStaffId ?: (int) ($_POST['physician_id'] ?? 1);
        $chiefComplaint = trim($_POST['chief_complaint'] ?? '');
        $bp = trim($_POST['bp'] ?? '120/80');
        $temp = trim($_POST['temp'] ?? '36.8°C');
        $weight = trim($_POST['weight'] ?? '60 kg');
        $rr = trim($_POST['rr'] ?? '18/min');
        $hr = trim($_POST['hr'] ?? '75 bpm');
        $diagnosis = trim($_POST['diagnosis'] ?? 'OPD Patient Evaluation');
        $icd10 = trim($_POST['icd10'] ?? 'Z00.0');
        $meds = trim($_POST['medications'] ?? '');

        if ($residentId <= 0 || empty($chiefComplaint)) {
            $_SESSION['nurse_flash_error'] = 'Please select a valid resident patient and fill chief complaint.';
        } else {
            try {
                $notes = "NURSING TRIAGE VITALS: BP: {$bp}, Temp: {$temp}, Wt: {$weight}, RR: {$rr}, HR: {$hr}.";
                if ($icd10 !== '') {
                    $notes .= " ICD-10: {$icd10}.";
                }
                $insertColumns = ['resident_id', 'health_worker_id', 'consultation_date', 'consultation_time', 'chief_complaint', 'diagnosis', 'treatment', 'medications_prescribed', 'remarks'];
                $insertValues = [':res', ':health_worker_id', 'CURDATE()', 'CURTIME()', ':chief', ':dx', ':treatment', ':meds', ':notes'];
                $insertParams = [
                    'res' => $residentId,
                    'health_worker_id' => $nurseStaffId ?: null,
                    'chief' => $chiefComplaint,
                    'dx' => $diagnosis,
                    'treatment' => $meds,
                    'meds' => $meds,
                    'notes' => $notes
                ];
                $stmt = $pdo->prepare("INSERT INTO consultations (" . implode(', ', $insertColumns) . ") VALUES (" . implode(', ', $insertValues) . ")");
                $stmt->execute($insertParams);
                portalSaveHealthRecordEntry($pdo, $residentId, [
                    'record_type' => 'OPD triage and vitals',
                    'chief_complaint' => $chiefComplaint,
                    'diagnosis' => trim($diagnosis . ($icd10 !== '' ? " ({$icd10})" : '')),
                    'medications' => $meds,
                    'bp' => $bp,
                    'temp' => $temp,
                    'weight' => $weight,
                    'notes' => "RR: {$rr}; HR: {$hr}",
                    'status' => 'Triage recorded',
                    'recorded_by_id' => $nurseStaffId ?: ($nurseUserId ?: null),
                ]);
                portalNotifyResident($pdo, $residentId, "Your OPD Triage & Vital Signs (BP: {$bp}, Temp: {$temp}) were recorded by Public Health Nurse.", "ResidentDashboard.php?tab=history");
                portalNotify($pdo, "New OPD Triage recorded for resident patient", null, 'PHYSICIAN', "RHUAdminDashboard.php");
                $_SESSION['nurse_flash_success'] = 'New OPD Triage record and vital signs saved successfully into database!';
            } catch (Exception $e) {
                $_SESSION['nurse_flash_error'] = 'Database Error: ' . $e->getMessage();
            }
        }
        header('Location: ' . tabUrl('opd'));
        exit;
    }

    // Action: Save Resident Medical Record & Profile Info
    if ($action === 'save_patient_record') {
        $residentId = (int) ($_POST['resident_id'] ?? 0);

        $height = trim($_POST['height'] ?? '');
        $weight = trim($_POST['weight'] ?? '');
        $bp = trim($_POST['blood_pressure'] ?? '');
        $allergies = trim($_POST['allergies'] ?? '');
        $medHistory = trim($_POST['medical_history'] ?? '');
        $bloodType = trim($_POST['blood_type'] ?? 'O+');

        try {
            $nurseStaffId = (int) ($_SESSION['rhu_staff_login']['staff_id'] ?? 0);
            $nurseUserId = (int) ($_SESSION['rhu_staff_login']['user_id'] ?? $_SESSION['rhu_staff_login']['id'] ?? 0);

            if ($residentId <= 0) {
                // Register NEW Resident
                $firstName = trim($_POST['first_name'] ?? '');
                $lastName = trim($_POST['last_name'] ?? '');
                $dob = trim($_POST['date_of_birth'] ?? '');
                $gender = trim($_POST['gender'] ?? 'Female');
                $barangay = trim($_POST['barangay'] ?? 'Poblacion');
                $barangayId = nurseBarangayId($pdo, $barangay);
                $philhealthId = trim($_POST['philhealth_id'] ?? '');
                $contactNumber = trim($_POST['contact_number'] ?? '');

                if (empty($firstName) || empty($lastName)) {
                    $_SESSION['nurse_flash_error'] = 'First name and last name are required for new resident registration.';
                    header('Location: ' . tabUrl('patients'));
                    exit;
                }
                $insRes = $pdo->prepare("INSERT INTO residents (first_name, last_name, birthdate, sex, barangay_id, address, philhealth_number, contact_number, created_at) VALUES (:fn, :ln, :dob, :sex, :barangay_id, :addr, :phi, :cn, NOW())");
                $insRes->execute([
                    'fn' => $firstName,
                    'ln' => $lastName,
                    'dob' => !empty($dob) ? $dob : '1990-01-01',
                    'sex' => $gender,
                    'barangay_id' => $barangayId,
                    'addr' => trim($_POST['address'] ?? '') ?: $barangay,
                    'phi' => $philhealthId,
                    'cn' => $contactNumber
                ]);
                $residentId = (int) $pdo->lastInsertId();
            } else {
                // Resident identity is preserved; nurse-editable clinical details are saved in health_records.
            }

            // Save or Update health profile vitals record for the resident
            $chkHp = $pdo->prepare("SELECT id FROM health_records WHERE resident_id = :rid ORDER BY id DESC LIMIT 1");
            $chkHp->execute(['rid' => $residentId]);
            $healthRecordId = (int) ($chkHp->fetchColumn() ?: 0);
            if ($healthRecordId > 0) {
                $upHp = $pdo->prepare("UPDATE health_records SET blood_type = :bt, height_cm = :h, weight_kg = :w, blood_pressure = :bp, allergies = :alg, medical_conditions = :mc, last_checkup_date = CURDATE(), recorded_by_id = :recorded_by, updated_at = NOW() WHERE id = :id");
                $upHp->execute([
                    'bt' => $bloodType,
                    'h' => !empty($height) ? (float) $height : null,
                    'w' => !empty($weight) ? (float) $weight : null,
                    'bp' => $bp,
                    'alg' => $allergies,
                    'mc' => $medHistory,
                    'recorded_by' => $nurseStaffId ?: ($nurseUserId ?: null),
                    'id' => $healthRecordId,
                ]);
            } else {
                $insHp = $pdo->prepare("INSERT INTO health_records (resident_id, blood_type, height_cm, weight_kg, blood_pressure, allergies, medical_conditions, last_checkup_date, recorded_by_id, created_at) VALUES (:rid, :bt, :h, :w, :bp, :alg, :mc, CURDATE(), :recorded_by, NOW())");
                $insHp->execute([
                    'rid' => $residentId,
                    'bt' => $bloodType,
                    'h' => !empty($height) ? (float) $height : null,
                    'w' => !empty($weight) ? (float) $weight : null,
                    'bp' => $bp,
                    'alg' => $allergies,
                    'mc' => $medHistory,
                    'recorded_by' => $nurseStaffId ?: ($nurseUserId ?: null),
                ]);
            }

            if ($residentId > 0) {
                $summaryParts = [];
                if ($bp !== '') $summaryParts[] = "BP: {$bp}";
                if ($bloodType !== '') $summaryParts[] = "Blood type: {$bloodType}";
                $summary = $summaryParts ? ' (' . implode(', ', $summaryParts) . ')' : '';
                portalNotifyResident($pdo, $residentId, "Your patient medical record was updated by the Public Health Nurse{$summary}.", "ResidentDashboard.php?tab=records");
            }

            $_SESSION['nurse_flash_success'] = 'Resident medical profile saved successfully into database!';
        } catch (Exception $e) {
            $_SESSION['nurse_flash_error'] = 'Database Error: ' . $e->getMessage();
        }
        header('Location: ' . tabUrl('patients'));
        exit;
    }
}

// ----------------------------------------------------
// 2. LIVE MYSQL DATA HYDRATION FROM DATABASE `rhu`
// ----------------------------------------------------
$opdConsultations = [];
$patientRecords = [];
$patientProfilesMap = [];
$residentRequests = [];
$immunizationRecords = [];
$nutritionCases = [];
$tbCases = [];
$diseaseReports = [];
$bhwList = [];

$allResidentsList = [];
$nurseCertificateTypes = [];
$allStaffList = [];
$selectedResidentData = null;

if (!empty($pdo)) {
    try {
        // Dropdown option queries
        $allResidentsList = $pdo->query("
            SELECT r.id, CONCAT(r.first_name, ' ', r.last_name) as name, COALESCE(b.name, 'Unassigned') as barangay
            FROM residents r
            LEFT JOIN barangays b ON b.id = r.barangay_id
            ORDER BY r.first_name ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (dbHasTable($pdo, 'certificate_types')) {
            $nurseCertificateTypes = portalEnsureCertificateTypes($pdo, ['Nursing Health Assessment Certificate', 'Immunization Status Certificate', 'Vital Signs Assessment Certificate', 'Community Health Clearance']);
        } else {
            $nurseCertificateTypes = [
                ['id' => 1, 'certificate_type_name' => 'Nursing Health Assessment Certificate'],
                ['id' => 2, 'certificate_type_name' => 'Immunization Status Certificate'],
                ['id' => 3, 'certificate_type_name' => 'Vital Signs Assessment Certificate'],
                ['id' => 4, 'certificate_type_name' => 'Community Health Clearance'],
            ];
        }
        $allStaffList = $pdo->query("SELECT id, CONCAT(first_name, ' ', last_name) as name, position_title as staff_type FROM health_workers ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (dbHasTable($pdo, 'messages') && dbHasTable($pdo, 'consultations')) {
            try {
                $requestMessages = $pdo->query("
                    SELECT m.id, m.resident_id, m.subject, m.message, m.status, m.created_at
                    FROM messages m
                    WHERE m.resident_id > 0
                      AND LOWER(CONCAT(COALESCE(m.subject, ''), ' ', COALESCE(m.message, ''))) REGEXP 'appointment|opd|checkup|check-up|consult|consultation|medical|nurse|doctor|physician'
                    ORDER BY m.id ASC
                ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

                foreach ($requestMessages as $messageRow) {
                    $residentRequestId = (int)($messageRow['resident_id'] ?? 0);
                    $subject = trim((string)($messageRow['subject'] ?? 'Resident Request'));
                    $messageText = trim((string)($messageRow['message'] ?? ''));
                    $createdAt = trim((string)($messageRow['created_at'] ?? ''));
                    if ($residentRequestId <= 0 || $messageText === '') {
                        continue;
                    }

                    $notesDuplicateCondition = dbHasColumn($pdo, 'consultations', 'consultation_notes')
                        ? "(remarks LIKE :message_marker_remarks OR consultation_notes LIKE :message_marker_notes)"
                        : "remarks LIKE :message_marker_remarks";
                    $duplicateCheck = $pdo->prepare("
                        SELECT id
                        FROM consultations
                        WHERE resident_id = :resident_id
                          AND chief_complaint = :chief_complaint
                          AND {$notesDuplicateCondition}
                        LIMIT 1
                    ");
                    $messageMarker = '%Resident Message #' . (int)$messageRow['id'] . '%';
                    $chiefComplaint = "[Resident Message] {$subject}";
                    $duplicateParams = [
                        'resident_id' => $residentRequestId,
                        'chief_complaint' => $chiefComplaint,
                        'message_marker_remarks' => $messageMarker,
                    ];
                    if (dbHasColumn($pdo, 'consultations', 'consultation_notes')) {
                        $duplicateParams['message_marker_notes'] = $messageMarker;
                    }
                    $duplicateCheck->execute($duplicateParams);
                    if ($duplicateCheck->fetchColumn()) {
                        continue;
                    }

                    $consultationColumns = ['resident_id', 'consultation_date', 'consultation_time', 'chief_complaint', 'diagnosis', 'remarks', 'created_at'];
                    $consultationValues = [':resident_id', ':consultation_date', 'CURTIME()', ':chief_complaint', ':diagnosis', ':remarks', ':created_at'];
                    $consultationParams = [
                        'resident_id' => $residentRequestId,
                        'consultation_date' => $createdAt !== '' ? date('Y-m-d', strtotime($createdAt)) : date('Y-m-d'),
                        'chief_complaint' => $chiefComplaint,
                        'diagnosis' => 'Pending OPD Triage',
                        'remarks' => "Resident Message #" . (int)$messageRow['id'] . "\n{$messageText}",
                        'created_at' => $createdAt !== '' ? $createdAt : date('Y-m-d H:i:s'),
                    ];
                    if (dbHasColumn($pdo, 'consultations', 'consultation_notes')) {
                        $consultationColumns[] = 'consultation_notes';
                        $consultationValues[] = ':consultation_notes';
                        $consultationParams['consultation_notes'] = $consultationParams['remarks'];
                    }
                    if (dbHasColumn($pdo, 'consultations', 'consultation_status')) {
                        $consultationColumns[] = 'consultation_status';
                        $consultationValues[] = ':consultation_status';
                        $consultationParams['consultation_status'] = 'Scheduled';
                    }
                    if (dbHasColumn($pdo, 'consultations', 'health_worker_id')) {
                        $consultationColumns[] = 'health_worker_id';
                        $consultationValues[] = ':health_worker_id';
                        $consultationParams['health_worker_id'] = (int)($_SESSION['rhu_staff_login']['staff_id'] ?? 0) ?: null;
                    }
                    if (dbHasColumn($pdo, 'consultations', 'physician_id')) {
                        $consultationColumns[] = 'physician_id';
                        $consultationValues[] = ':physician_id';
                        $consultationParams['physician_id'] = (int)($pdo->query("SELECT id FROM health_workers ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 1);
                    }
                    $importMessageStmt = $pdo->prepare("INSERT INTO consultations (" . implode(', ', $consultationColumns) . ") VALUES (" . implode(', ', $consultationValues) . ")");
                    $importMessageStmt->execute($consultationParams);
                }
            } catch (Throwable $messageImportError) {
                error_log('NurseDashboard message request import: ' . $messageImportError->getMessage());
            }
        }

        // 1. OPD Consultations (All resident records available to nurse dashboard)
        $consultationNotesSelect = dbHasColumn($pdo, 'consultations', 'consultation_notes')
            ? 'COALESCE(c.consultation_notes, c.remarks)'
            : 'c.remarks';
        $consultationStatusSelect = dbHasColumn($pdo, 'consultations', 'consultation_status')
            ? "COALESCE(c.consultation_status, CASE WHEN c.follow_up_date IS NOT NULL THEN 'Scheduled' WHEN c.diagnosis IS NOT NULL AND c.diagnosis <> '' AND c.diagnosis <> 'Pending OPD Triage' THEN 'Completed' ELSE 'In Progress' END)"
            : "CASE WHEN c.follow_up_date IS NOT NULL THEN 'Scheduled' WHEN c.diagnosis IS NOT NULL AND c.diagnosis <> '' AND c.diagnosis <> 'Pending OPD Triage' THEN 'Completed' ELSE 'In Progress' END";
        $nurseStaffId = (int) ($_SESSION['rhu_staff_login']['staff_id'] ?? 0);
        $assignmentWhere = '';
        $assignmentParams = [];
        if ($nurseStaffId > 0) {
            $assignmentParts = [];
            if (dbHasColumn($pdo, 'consultations', 'health_worker_id')) {
                $assignmentParts[] = 'c.health_worker_id = :nurse_staff_id_hw';
                $assignmentParams['nurse_staff_id_hw'] = $nurseStaffId;
            }
            if (dbHasColumn($pdo, 'consultations', 'physician_id')) {
                $assignmentParts[] = 'c.physician_id = :nurse_staff_id_phy';
                $assignmentParams['nurse_staff_id_phy'] = $nurseStaffId;
            }
            if ($assignmentParts) {
                $assignmentWhere = 'WHERE (' . implode(' OR ', $assignmentParts) . ' OR (c.diagnosis = :unassigned_opd_diagnosis AND COALESCE(c.consultation_status, \'Scheduled\') IN (\'Scheduled\', \'In Progress\')))' ;
                $assignmentParams['unassigned_opd_diagnosis'] = 'Pending OPD Triage';
            }
        }
        $opdStmt = $pdo->prepare("
            SELECT c.id, c.resident_id, CONCAT(r.first_name, ' ', r.last_name) AS patientName,
                   TIMESTAMPDIFF(YEAR, r.birthdate, CURDATE()) as age, r.sex as gender,
                   c.chief_complaint as chiefComplaint, c.diagnosis,
                   CASE WHEN c.remarks REGEXP 'ICD-10:[[:space:]]*[A-Z0-9.]+' THEN TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(c.remarks, 'ICD-10:', -1), '.', 1)) ELSE '' END as icd10,
                   c.medications_prescribed as medications, COALESCE(b.name, 'Unassigned') as barangay,
                   c.consultation_date as date, 0 as referral_needed, '' as referral_to,
                   {$consultationNotesSelect} as consultation_notes,
                   {$consultationStatusSelect} AS consultation_status
            FROM consultations c
            JOIN residents r ON c.resident_id = r.id
            LEFT JOIN barangays b ON b.id = r.barangay_id
            {$assignmentWhere}
            ORDER BY c.id DESC
        ");
        $opdStmt->execute($assignmentParams);
        $opdConsultations = $opdStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // 2. Patient Records (Residents + Health Profiles)
        $residentDobSelect = dbHasColumn($pdo, 'residents', 'date_of_birth')
            ? "COALESCE(NULLIF(r.date_of_birth, '0000-00-00'), NULLIF(r.birthdate, '0000-00-00'))"
            : "NULLIF(r.birthdate, '0000-00-00')";
        $residentAgeSelect = dbHasColumn($pdo, 'residents', 'date_of_birth')
            ? "TIMESTAMPDIFF(YEAR, {$residentDobSelect}, CURDATE())"
            : "TIMESTAMPDIFF(YEAR, r.birthdate, CURDATE())";
        $residentPhilhealthSelect = dbHasColumn($pdo, 'residents', 'philhealth_number') && dbHasColumn($pdo, 'residents', 'philhealth_id')
            ? "COALESCE(r.philhealth_number, r.philhealth_id)"
            : (dbHasColumn($pdo, 'residents', 'philhealth_number') ? "r.philhealth_number" : (dbHasColumn($pdo, 'residents', 'philhealth_id') ? "r.philhealth_id" : "''"));
        $residentEmailSelect = dbHasColumn($pdo, 'residents', 'email') ? 'r.email' : "''";
        $residentCivilStatusSelect = dbHasColumn($pdo, 'residents', 'civil_status') ? 'r.civil_status' : "''";
        $residentEmergencyNameSelect = dbHasColumn($pdo, 'residents', 'emergency_contact_name') ? 'r.emergency_contact_name' : "''";
        $residentEmergencyNumberSelect = dbHasColumn($pdo, 'residents', 'emergency_contact_phone') && dbHasColumn($pdo, 'residents', 'emergency_contact_number')
            ? "COALESCE(r.emergency_contact_number, r.emergency_contact_phone, '')"
            : (dbHasColumn($pdo, 'residents', 'emergency_contact_number') ? "COALESCE(r.emergency_contact_number, '')" : (dbHasColumn($pdo, 'residents', 'emergency_contact_phone') ? "COALESCE(r.emergency_contact_phone, '')" : "''"));
        $residentEmergencyRelationshipSelect = dbHasColumn($pdo, 'residents', 'emergency_contact_relationship') ? 'r.emergency_contact_relationship' : "''";
        $patStmt = $pdo->query("
            SELECT r.id, CONCAT(r.first_name, ' ', r.last_name) as name, r.first_name, r.middle_name, r.last_name,
                   {$residentDobSelect} as date_of_birth,
                   {$residentAgeSelect} as age, r.sex as gender,
                   {$residentCivilStatusSelect} as civil_status, {$residentEmailSelect} as email, r.address,
                   COALESCE(hp.blood_type, 'O+') as bloodType,
                   COALESCE(b.name, 'Unassigned') as barangay, {$residentPhilhealthSelect} as philhealthNo, r.contact_number as contactNo, r.created_at as admissionDate,
                   hp.allergies, hp.medical_conditions as medicalHistory,
                   hp.height_cm as height, hp.weight_kg as weight, hp.blood_pressure as bloodPressure, hp.last_checkup_date as lastCheckup,
                   COALESCE({$residentEmergencyNameSelect}, '') as emergencyContactName,
                   {$residentEmergencyNumberSelect} as emergencyContactNo,
                   COALESCE({$residentEmergencyRelationshipSelect}, '') as emergencyContactRelationship
            FROM residents r
            LEFT JOIN barangays b ON b.id = r.barangay_id
            LEFT JOIN (
                SELECT h1.*
                FROM health_records h1
                JOIN (SELECT resident_id, MAX(id) id FROM health_records GROUP BY resident_id) latest ON latest.id = h1.id
            ) hp ON hp.resident_id = r.id
            ORDER BY r.id DESC
        ");
        $patientRecords = $patStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Build associative map keyed by resident_id for JS auto-fill
        foreach ($patientRecords as $pr) {
            $patientProfilesMap[(int) $pr['id']] = [
                'id' => (int) $pr['id'],
                'first_name' => $pr['first_name'] ?? '',
                'middle_name' => $pr['middle_name'] ?? '',
                'last_name' => $pr['last_name'] ?? '',
                'name' => $pr['name'] ?? '',
                'date_of_birth' => $pr['date_of_birth'] ?? '',
                'age' => $pr['age'] ?? '',
                'gender' => $pr['gender'] ?? '',
                'civil_status' => $pr['civil_status'] ?? '',
                'email' => $pr['email'] ?? '',
                'address' => $pr['address'] ?? '',
                'barangay' => $pr['barangay'] ?? '',
                'bloodType' => !empty($pr['bloodType']) ? $pr['bloodType'] : 'O+',
                'philhealthNo' => $pr['philhealthNo'] ?? '',
                'contactNo' => $pr['contactNo'] ?? '',
                'height' => $pr['height'] ?? '',
                'weight' => $pr['weight'] ?? '',
                'bloodPressure' => $pr['bloodPressure'] ?? '',
                'allergies' => $pr['allergies'] ?? '',
                'medicalHistory' => $pr['medicalHistory'] ?? '',
                'lastCheckup' => $pr['lastCheckup'] ?? '',
                'emergencyContactName' => $pr['emergencyContactName'] ?? '',
                'emergencyContactNo' => $pr['emergencyContactNo'] ?? '',
                'emergencyContactRelationship' => $pr['emergencyContactRelationship'] ?? '',
            ];
        }

        if ($selectedResidentId > 0 && isset($patientProfilesMap[$selectedResidentId])) {
            $selectedResidentData = $patientProfilesMap[$selectedResidentId];
        }

        $residentRequests = [];
        $appendResidentRequest = static function (array $request) use (&$residentRequests, $patientProfilesMap): void {
            $residentId = (int)($request['resident_id'] ?? 0);
            $profile = $patientProfilesMap[$residentId] ?? [];
            $request['resident'] = $profile;
            $request['resident_name'] = $profile['name'] ?? ($request['resident_name'] ?? ('Resident #' . $residentId));
            $request['barangay'] = $profile['barangay'] ?? ($request['barangay'] ?? 'Unassigned');
            $request['requested_at'] = $request['requested_at'] ?? '';
            $residentRequests[] = $request;
        };

        if (dbHasTable($pdo, 'consultations')) {
            $requestConsultations = $pdo->query("
                SELECT c.id, c.resident_id, CONCAT(r.first_name, ' ', r.last_name) AS resident_name,
                       c.chief_complaint AS title, {$consultationNotesSelect} AS details,
                       {$consultationStatusSelect} AS status, c.consultation_date AS request_date,
                       COALESCE(c.created_at, c.consultation_date) AS requested_at
                FROM consultations c
                JOIN residents r ON r.id = c.resident_id
                WHERE c.resident_id > 0
                  AND (
                    c.diagnosis = 'Pending OPD Triage'
                    OR c.chief_complaint LIKE '[%]%'
                    OR {$consultationNotesSelect} LIKE '%Requested via Resident Portal%'
                    OR {$consultationNotesSelect} LIKE '%Resident Message #%'
                  )
                ORDER BY requested_at DESC, c.id DESC
                LIMIT 200
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($requestConsultations as $row) {
                $appendResidentRequest([
                    'source' => 'consultation',
                    'source_id' => (int)$row['id'],
                    'resident_id' => (int)$row['resident_id'],
                    'type' => 'OPD / Consultation Request',
                    'title' => $row['title'] ?: 'OPD consultation request',
                    'details' => $row['details'] ?: 'No additional notes provided.',
                    'status' => $row['status'] ?: 'Scheduled',
                    'request_date' => $row['request_date'] ?? '',
                    'requested_at' => $row['requested_at'] ?? '',
                    'action_url' => tabUrl('opd'),
                ]);
            }
        }

        if (dbHasTable($pdo, 'messages')) {
            $messageRequests = $pdo->query("
                SELECT id, resident_id, subject, message, status, created_at
                FROM messages
                WHERE resident_id > 0
                ORDER BY created_at DESC, id DESC
                LIMIT 200
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($messageRequests as $row) {
                $appendResidentRequest([
                    'source' => 'message',
                    'source_id' => (int)$row['id'],
                    'resident_id' => (int)$row['resident_id'],
                    'type' => 'Resident Message',
                    'title' => $row['subject'] ?: 'Resident inquiry',
                    'details' => $row['message'] ?: 'No message body provided.',
                    'status' => $row['status'] ?: 'Pending',
                    'request_date' => !empty($row['created_at']) ? date('Y-m-d', strtotime($row['created_at'])) : '',
                    'requested_at' => $row['created_at'] ?? '',
                    'action_url' => tabUrl('opd'),
                ]);
            }
        }

        if (dbHasTable($pdo, 'certificates')) {
            $certificateRequests = $pdo->query("
                SELECT id, resident_id, certificate_type, purpose, status, created_at, issue_date
                FROM certificates
                WHERE resident_id > 0
                  AND LOWER(COALESCE(status, '')) IN ('pending', 'draft', 'requested', 'pending approval', 'pending doctor approval', 'in review')
                ORDER BY created_at DESC, id DESC
                LIMIT 200
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($certificateRequests as $row) {
                $appendResidentRequest([
                    'source' => 'certificate',
                    'source_id' => (int)$row['id'],
                    'resident_id' => (int)$row['resident_id'],
                    'type' => 'Certificate Request',
                    'title' => $row['certificate_type'] ?: 'Health certificate request',
                    'details' => $row['purpose'] ?: 'No purpose recorded.',
                    'status' => $row['status'] ?: 'Pending',
                    'request_date' => $row['issue_date'] ?? '',
                    'requested_at' => $row['created_at'] ?? '',
                    'action_url' => tabUrl('patients', ['resident_id' => (int)$row['resident_id']]),
                ]);
            }
        }

        if (dbHasTable($pdo, 'audit_logs')) {
            $auditRequests = $pdo->query("
                SELECT id, record_id AS resident_id, action, description, created_at
                FROM audit_logs
                WHERE module_name = 'Resident Portal'
                  AND record_id > 0
                  AND action IN ('URGENT: Emergency Referral', 'Resident Message')
                ORDER BY created_at DESC, id DESC
                LIMIT 200
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($auditRequests as $row) {
                $appendResidentRequest([
                    'source' => 'audit',
                    'source_id' => (int)$row['id'],
                    'resident_id' => (int)$row['resident_id'],
                    'type' => $row['action'] === 'URGENT: Emergency Referral' ? 'Emergency Referral Request' : 'Resident Message',
                    'title' => $row['action'] ?: 'Resident request',
                    'details' => $row['description'] ?: 'No details recorded.',
                    'status' => $row['action'] === 'URGENT: Emergency Referral' ? 'Urgent' : 'Pending',
                    'request_date' => !empty($row['created_at']) ? date('Y-m-d', strtotime($row['created_at'])) : '',
                    'requested_at' => $row['created_at'] ?? '',
                    'action_url' => tabUrl('opd'),
                ]);
            }
        }

        $requestSeen = [];
        $residentRequests = array_values(array_filter($residentRequests, static function (array $request) use (&$requestSeen): bool {
            $key = ($request['source'] ?? '') . ':' . (int)($request['source_id'] ?? 0);
            if (isset($requestSeen[$key])) {
                return false;
            }
            $requestSeen[$key] = true;
            return true;
        }));
        usort($residentRequests, static function (array $left, array $right): int {
            return strcmp((string)($right['requested_at'] ?? ''), (string)($left['requested_at'] ?? ''));
        });

        // 3. Immunization Records
        $immStmt = $pdo->query("
            SELECT vr.id, CONCAT(r.first_name, ' ', r.last_name) as childName, TIMESTAMPDIFF(MONTH, r.birthdate, CURDATE()) as ageMonths,
                   COALESCE(b.name, 'Unassigned') as barangay, vr.vaccine_name as vaccineName, vr.age_group as targetAge,
                   vr.vaccination_date as dateGiven, vr.next_dose_date as nextVisit, vr.batch_number as lot
            FROM vaccination_records vr
            JOIN residents r ON vr.resident_id = r.id
            LEFT JOIN barangays b ON b.id = r.barangay_id
            ORDER BY vr.id DESC
        ");
        $immunizationRecords = $immStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // 4. Nutrition Cases
        $nutrStmt = $pdo->query("
            SELECT hp.id, CONCAT(r.first_name, ' ', r.last_name) as name, TIMESTAMPDIFF(YEAR, r.birthdate, CURDATE()) as age,
                   r.sex as gender, COALESCE(b.name, 'Unassigned') as barangay, hp.height_cm as height, hp.weight_kg as weight
            FROM health_records hp
            JOIN residents r ON hp.resident_id = r.id
            LEFT JOIN barangays b ON b.id = r.barangay_id
            ORDER BY hp.id DESC
        ");
        $nutritionCases = $nutrStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // 5. TB-DOTS Cases inferred from disease surveillance records in the live schema.
        $tbStmt = $pdo->query("
            SELECT dc.id, CONCAT(r.first_name, ' ', r.last_name) as name, TIMESTAMPDIFF(YEAR, r.birthdate, CURDATE()) as age,
                   r.sex as gender, CONCAT('TB-', dc.id) as tb_registration_number, dt.name as classification,
                   dc.case_status as treatment_status, dc.case_date as treatment_start_date, COALESCE(b.name, 'Unassigned') as barangay
            FROM disease_cases dc
            JOIN disease_types dt ON dt.id = dc.disease_type_id
            JOIN residents r ON r.id = dc.resident_id
            LEFT JOIN barangays b ON b.id = r.barangay_id
            WHERE LOWER(CONCAT(dt.name, ' ', COALESCE(dt.icd_code, ''), ' ', COALESCE(dc.symptoms, ''), ' ', COALESCE(dc.remarks, ''))) REGEXP 'tb|tuberculosis|koch'
            ORDER BY dc.id DESC
        ");
        $tbCases = $tbStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // 6. BHW List
        $bhwStmt = $pdo->query("
            SELECT hw.id, CONCAT(hw.first_name, ' ', hw.last_name) as name,
                   COALESCE(b.name, 'Unassigned') as barangay,
                   hw.contact_number as phone_number,
                   (hw.status = 'Active') as is_active
            FROM health_workers hw
            LEFT JOIN barangays b ON b.id = hw.assigned_barangay_id
            WHERE UPPER(CONCAT(hw.role, ' ', hw.position_title)) LIKE '%BHW%'
               OR UPPER(CONCAT(hw.role, ' ', hw.position_title)) LIKE '%BARANGAY HEALTH%'
            ORDER BY hw.id DESC
        ");
        $bhwList = $bhwStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    } catch (Exception $e) {
        error_log("NurseDashboard DB Load Error: " . $e->getMessage());
    }
}

$todayOPDCount = count($opdConsultations);
$diagnosedResidentIds = [];
$immunizationDiagnosisCount = 0;
$tbDiagnosisResidentIds = [];
foreach ($opdConsultations as $consultation) {
    $diagnosisText = strtolower(trim(
        ($consultation['diagnosis'] ?? '') . ' ' .
        ($consultation['chiefComplaint'] ?? '') . ' ' .
        ($consultation['consultation_notes'] ?? '') . ' ' .
        ($consultation['icd10'] ?? '')
    ));
    $residentKey = (int) ($consultation['resident_id'] ?? 0);

    if ($residentKey > 0 && trim((string) ($consultation['diagnosis'] ?? '')) !== '') {
        $diagnosedResidentIds[$residentKey] = true;
    }
    if (preg_match('/\b(immuni[sz]ation|vaccine|vaccination|epi)\b/i', $diagnosisText)) {
        $immunizationDiagnosisCount++;
    }
    if ($residentKey > 0 && preg_match('/\b(tb|tuberculosis|tuberculous|latent\s+tb|latent\s+tuberculosis|tb-dots|dots|koch)\b|a1[5-9]\b|z22\.?7\b|b90\b/i', $diagnosisText)) {
        $tbDiagnosisResidentIds[$residentKey] = true;
    }
}
$diagnosedPatientCount = count($diagnosedResidentIds);
$activeTBCount = count($tbDiagnosisResidentIds);
$opdResidents = [];
foreach ($opdConsultations as $consultation) {
    $residentKey = (int) ($consultation['resident_id'] ?? 0);
    if ($residentKey <= 0) {
        $residentKey = (int) ($consultation['id'] ?? 0);
    }
    if (!isset($opdResidents[$residentKey])) {
        $opdResidents[$residentKey] = [
            'resident_id' => $residentKey,
            'patientName' => $consultation['patientName'] ?? '',
            'age' => $consultation['age'] ?? '',
            'gender' => $consultation['gender'] ?? '',
            'barangay' => $consultation['barangay'] ?? '',
            'records' => [],
        ];
    }
    $opdResidents[$residentKey]['records'][] = $consultation;
}
$opdResidents = array_values($opdResidents);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="favicon.ico?v=20260915" type="image/x-icon">
    <link rel="shortcut icon" href="favicon.ico?v=20260915" type="image/x-icon">
    <title>Public Health Nurse Portal — ResiHUnity RHU</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        html {
            scroll-behavior: auto;
        }

        body.rhu-nurse-ui {
            overflow: hidden;
            background: #f3f6f4;
            color: #0f172a;
            height: 100vh;
            height: 100dvh;
        }

        .nurse-sidebar {
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

        .nurse-sidebar-brand {
            position: relative;
            overflow: hidden;
            min-height: 4.25rem;
            padding: 0.9rem 1rem;
            flex-shrink: 0;
        }

        .nurse-sidebar-brand .brand-bg {
            position: absolute;
            inset: 0;
            background-image: url('../../../assets/admin-municipal-background.png');
            background-size: cover;
            background-position: center;
            filter: saturate(1.2) brightness(0.52);
        }

        .nurse-sidebar-brand .brand-overlay {
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

        .nurse-main-wrap {
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

        .nurse-main-wrap::before {
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

        .nurse-main-wrap>* {
            position: relative;
            z-index: 1;
        }

        .nurse-main-wrap>header.admin-shell-header {
            position: sticky;
            top: 0;
            z-index: 50;
            flex-shrink: 0;
        }

        .nurse-main-wrap>main {
            z-index: 1;
            position: relative;
            flex: 1;
            min-height: 0;
            overflow-y: auto;
            overflow-x: hidden;
            -webkit-overflow-scrolling: touch;
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

        .triage-queue-card {
            border-color: rgba(209, 213, 219, .9);
            box-shadow: 0 8px 18px rgba(15, 23, 42, .04);
        }

        .triage-queue-card:hover {
            border-color: rgba(16, 185, 129, .36);
            box-shadow: 0 10px 22px rgba(15, 23, 42, .055);
        }

        .triage-chip {
            display: inline-flex;
            align-items: center;
            min-height: 1.625rem;
            border-radius: 999px;
            padding: .25rem .625rem;
            font-size: .72rem;
            font-weight: 800;
            line-height: 1;
            white-space: nowrap;
        }

        .triage-vitals-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(96px, 1fr));
            gap: .5rem;
        }

        .triage-vital {
            border: 1px solid rgba(209, 213, 219, .85);
            border-radius: .75rem;
            background: #fff;
            padding: .625rem .75rem;
        }

        .triage-vital span {
            display: block;
            color: #64748b;
            font-size: .62rem;
            font-weight: 900;
            letter-spacing: .04em;
            text-transform: uppercase;
        }

        .triage-vital strong {
            display: block;
            margin-top: .125rem;
            color: #0f172a;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
            font-size: .78rem;
        }

        .triage-response summary::-webkit-details-marker {
            display: none;
        }

        .triage-response summary::after {
            content: '+';
            display: grid;
            place-items: center;
            width: 1.5rem;
            height: 1.5rem;
            border-radius: 999px;
            background: #ecfdf5;
            color: #047857;
            font-weight: 900;
        }

        .triage-response[open] summary::after {
            content: '-';
        }

        .triage-note summary::-webkit-details-marker {
            display: none;
        }

        .triage-note summary {
            list-style: none;
        }

        .triage-workbench {
            --triage-workbench-height: min(36rem, calc(100dvh - 18rem));
            display: grid;
            grid-template-columns: minmax(240px, 340px) minmax(0, 1fr);
            gap: 1.25rem;
            align-items: stretch;
        }

        .triage-suggest-pane {
            min-width: 0;
            overflow: hidden;
            position: relative;
            z-index: 2;
            height: var(--triage-workbench-height);
            display: flex;
            flex-direction: column;
        }

        .triage-view-pane {
            min-width: 0;
            position: relative;
            z-index: 1;
            height: var(--triage-workbench-height);
        }

        .triage-search {
            width: 100%;
            border: 1px solid #cbd5e1;
            border-radius: .875rem;
            background: #fff;
            padding: .625rem .75rem;
            font-size: .82rem;
            font-weight: 700;
            color: #0f172a;
        }

        .triage-patient-list {
            display: grid;
            gap: .5rem;
            min-height: 0;
            overflow-y: auto;
            padding-right: .2rem;
            scrollbar-width: thin;
            scrollbar-color: #94a3b8 transparent;
        }

        .triage-patient-list::-webkit-scrollbar {
            width: 8px;
        }

        .triage-patient-list::-webkit-scrollbar-thumb {
            background: #94a3b8;
            border-radius: 999px;
        }

        .triage-patient-list::-webkit-scrollbar-track {
            background: transparent;
        }

        .triage-patient-tab {
            width: 100%;
            min-width: 0;
            box-sizing: border-box;
            border: 1px solid #e2e8f0;
            border-radius: .875rem;
            background: #fff;
            padding: .75rem;
            text-align: left;
            transition: background-color .15s ease, border-color .15s ease, box-shadow .15s ease;
        }

        .triage-patient-tab:hover,
        .triage-patient-tab.is-active {
            border-color: #34d399;
            background: #ecfdf5;
            box-shadow: inset 3px 0 0 #10b981;
        }

        .triage-detail-panel {
            display: none;
        }

        .triage-detail-panel.is-active {
            display: block;
            height: 100%;
        }

        .triage-detail-panel.is-active > .triage-queue-card {
            height: 100%;
            overflow-y: auto;
        }

        .triage-empty-filter {
            display: none;
        }

        .triage-empty-filter.is-visible {
            display: block;
        }

        .icd-lookup-wrap {
            position: relative;
        }

        .icd-suggestions {
            display: none;
            position: relative;
            z-index: 5;
            margin-top: .4rem;
            max-height: 15rem;
            overflow-y: auto;
            border: 1px solid #cbd5e1;
            border-radius: .875rem;
            background: #fff;
            box-shadow: 0 8px 18px rgba(15, 23, 42, .08);
        }

        .icd-suggestions.is-open {
            display: block;
        }

        .icd-suggestion {
            width: 100%;
            padding: .65rem .75rem;
            text-align: left;
            border-bottom: 1px solid #f1f5f9;
            font-size: .78rem;
            color: #334155;
        }

        .icd-suggestion:last-child {
            border-bottom: 0;
        }

        .icd-suggestion:hover,
        .icd-suggestion:focus {
            background: #ecfdf5;
            outline: none;
        }

        .icd-suggestion strong {
            display: block;
            color: #047857;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
            font-size: .75rem;
        }

        @media (max-width: 900px) {
            .triage-workbench {
                --triage-workbench-height: auto;
                grid-template-columns: 1fr;
            }

            .triage-suggest-pane,
            .triage-view-pane {
                height: auto;
            }

            .triage-patient-list {
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
                max-height: 24rem;
            }
        }

        a.bg-teal-600:hover,
        button.bg-teal-600:hover,
        a.bg-emerald-700:hover,
        button.bg-emerald-700:hover {
            filter: brightness(1.05);
        }

        a.bg-teal-600,
        button.bg-teal-600,
        button.bg-emerald-700,
        a.bg-emerald-700 {
            transition: background-color .15s ease, filter .15s ease, box-shadow .15s ease;
        }

        input:focus,
        select:focus,
        textarea:focus {
            border-color: #0d9488 !important;
            box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.14) !important;
        }

        .resident-record-form input,
        .resident-record-form select,
        .resident-record-form textarea {
            background: #ffffff !important;
            background-color: #ffffff !important;
            color: #000000 !important;
            color-scheme: light;
            -webkit-appearance: none;
            appearance: none;
        }

        .resident-record-form input::placeholder,
        .resident-record-form textarea::placeholder {
            color: #9ca3af !important;
            opacity: 1;
        }

        .resident-record-form option {
            background-color: #ffffff;
            color: #000000;
        }

        @media (max-width: 1023px) {
            .nurse-sidebar {
                position: fixed;
                inset: 0 auto 0 0;
                z-index: 60;
                height: 100vh;
                transform: translateX(-105%);
                transition: transform .2s ease;
                box-shadow: 12px 0 40px rgba(15, 23, 42, .18);
            }

            .nurse-sidebar.is-open {
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

            .nurse-sidebar {
                transform: none !important;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .dashboard-card:hover {
                transform: none;
            }
        }
    </style>
    <link rel="stylesheet" href="dashboard-enhancements.css">
</head>

<body class="rhu-nurse-ui antialiased">

    <div class="flex h-screen max-h-screen overflow-hidden">
        <div data-drawer-backdrop class="sidebar-backdrop lg:hidden" aria-hidden="true"></div>

        <aside id="nurse-sidebar" data-feature-drawer class="nurse-sidebar shrink-0" aria-label="Nurse navigation">
            <div class="nurse-sidebar-brand">
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
                    'Clinical Care' => ['opd', 'patients', 'immunization'],
                    'Programs' => ['nutrition', 'tb', 'disease', 'bhw'],
                    'Records' => ['certificates'],
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

        <div class="nurse-main-wrap">
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
                            <span class="text-[11px] font-black uppercase tracking-[0.16em] text-[#f5f5f2]">Nurse
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
                                <?= esc(strtoupper(substr($_SESSION['rhu_staff_login']['name'] ?? 'N', 0, 1))) ?>
                            </div>
                            <div class="hidden sm:block text-left leading-tight pr-1">
                                <p class="text-[12px] font-bold text-white">
                                    <?= esc($_SESSION['rhu_staff_login']['name'] ?? 'Public Health Nurse') ?></p>
                                <p class="text-[9px] font-semibold uppercase tracking-wider text-[#cfe5d8]">Nurse</p>
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

                <?php if ($flashSuccess): ?>
                    <div
                        class="bg-emerald-50 border border-emerald-200 text-emerald-900 px-4 py-3 rounded-2xl text-sm font-bold shadow-sm flex items-center justify-between">
                        <span class="flex items-center gap-2"><?= esc($flashSuccess); ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($flashError): ?>
                    <div
                        class="bg-red-50 border border-red-200 text-red-900 px-4 py-3 rounded-2xl text-sm font-bold shadow-sm flex items-center justify-between">
                        <span class="flex items-center gap-2"><?= esc($flashError); ?></span>
                    </div>
                <?php endif; ?>

                <!-- TAB 1: OVERVIEW -->
                <?php if ($tab === 'overview'): ?>
                    <div class="space-y-5">
                        <!-- SURVEILLANCE BANNER -->
                        <div
                            class="bg-linear-to-r from-red-50 via-rose-50 to-orange-50 border border-red-200/80 rounded-2xl p-4 sm:p-5 flex items-start gap-3 shadow-sm">
                            <span class="w-9 h-9 rounded-xl bg-red-100 text-red-600 flex items-center justify-center shrink-0"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg></span>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <p class="font-extrabold text-red-900 text-sm sm:text-base">DOH Active Disease
                                        Surveillance Alert</p>
                                    <span class="bg-red-600 text-white text-[10px] font-bold px-2 py-0.5 rounded-full">PIDSR
                                        Active</span>
                                </div>
                                <p class="text-xs sm:text-sm text-red-700 mt-0.5">Continuous community monitoring ongoing
                                    for Dengue, Typhoid, and Leptospirosis across Nasugbu barangays.</p>
                            </div>
                            <a href="<?= esc(tabUrl('disease')); ?>"
                                class="text-xs bg-red-600 hover:bg-red-700 text-white font-bold px-3.5 py-2 rounded-xl whitespace-nowrap shadow-md transition">View
                                Surveillance</a>
                        </div>

                        <!-- METRIC CARDS GRID -->
                        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                            <a href="<?= esc(tabUrl('opd')); ?>"
                                class="dashboard-card group bg-linear-to-br from-emerald-500/10 via-white to-white rounded-2xl p-5 shadow-sm border border-emerald-100/80 transition">
                                <div class="flex items-center justify-between">
                                    <span class="w-10 h-10 rounded-xl bg-emerald-500/10 text-emerald-700 flex items-center justify-center shrink-0"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg></span>
                                    <span
                                        class="text-xs font-bold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-full border border-emerald-200">Active</span>
                                </div>
                                <p
                                    class="text-3xl font-black text-emerald-900 mt-3  transition-transform">
                                    <?= $todayOPDCount; ?></p>
                                <p class="text-xs font-bold text-gray-800 mt-1">Received OPD Consults</p>
                                <p class="text-[11px] text-gray-400 font-medium">Assigned Nursing Triage</p>
                            </a>

                            <a href="<?= esc(tabUrl('patients')); ?>"
                                class="dashboard-card group bg-linear-to-br from-blue-500/10 via-white to-white rounded-2xl p-5 shadow-sm border border-blue-100/80 transition">
                                <div class="flex items-center justify-between">
                                    <span class="w-10 h-10 rounded-xl bg-blue-500/10 text-blue-700 flex items-center justify-center shrink-0"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></span>
                                    <span
                                        class="text-xs font-bold text-blue-700 bg-blue-50 px-2 py-0.5 rounded-full border border-blue-200">Registry</span>
                                </div>
                                <p
                                    class="text-3xl font-black text-blue-900 mt-3  transition-transform">
                                    <?= $diagnosedPatientCount; ?></p>
                                <p class="text-xs font-bold text-gray-800 mt-1">Diagnosed Patients</p>
                                <p class="text-[11px] text-gray-400 font-medium">Residents with Diagnosis</p>
                            </a>

                            <a href="<?= esc(tabUrl('immunization')); ?>"
                                class="dashboard-card group bg-linear-to-br from-purple-500/10 via-white to-white rounded-2xl p-5 shadow-sm border border-purple-100/80 transition">
                                <div class="flex items-center justify-between">
                                    <span class="w-10 h-10 rounded-xl bg-purple-500/10 text-purple-700 flex items-center justify-center shrink-0"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="m18 2 4 4"/><path d="M19 9 8.7 19.3c-1 1-2.5 1-3.4 0l-.6-.6c-1-1-1-2.5 0-3.4L15 5"/></svg></span>
                                    <span
                                        class="text-xs font-bold text-purple-700 bg-purple-50 px-2 py-0.5 rounded-full border border-purple-200">EPI
                                        Program</span>
                                </div>
                                <p
                                    class="text-3xl font-black text-purple-900 mt-3  transition-transform">
                                    <?= $immunizationDiagnosisCount; ?></p>
                                <p class="text-xs font-bold text-gray-800 mt-1">Child Immunizations</p>
                                <p class="text-[11px] text-gray-400 font-medium">Diagnosis-Based Records</p>
                            </a>

                            <a href="<?= esc(tabUrl('tb')); ?>"
                                class="dashboard-card group bg-linear-to-br from-amber-500/10 via-white to-white rounded-2xl p-5 shadow-sm border border-amber-100/80 transition">
                                <div class="flex items-center justify-between">
                                    <span class="w-10 h-10 rounded-xl bg-amber-500/10 text-amber-700 flex items-center justify-center shrink-0"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M9 3h6v4a3 3 0 0 1-6 0V3z"/><path d="M9 7v13"/><path d="M15 7v13"/></svg></span>
                                    <span
                                        class="text-xs font-bold text-amber-700 bg-amber-50 px-2 py-0.5 rounded-full border border-amber-200">DOTS</span>
                                </div>
                                <p
                                    class="text-3xl font-black text-amber-900 mt-3  transition-transform">
                                    <?= $activeTBCount; ?></p>
                                <p class="text-xs font-bold text-gray-800 mt-1">Active TB Cases</p>
                                <p class="text-[11px] text-gray-400 font-medium">Treatment Monitoring</p>
                            </a>
                        </div>

                        <!-- RECEIVED OPD CONSULTATION & TRIAGE LOG SUMMARY -->
                        <div
                            class="dashboard-card bg-white rounded-2xl p-4 sm:p-5 shadow-sm border border-slate-200/80 space-y-4">
                            <div class="flex flex-col gap-3 border-b border-slate-100 pb-4 sm:flex-row sm:items-center sm:justify-between">
                                <div class="min-w-0">
                                    <h3 class="font-extrabold text-gray-950 text-base flex items-center gap-2">
                                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 shrink-0"></span>
                                        Received Resident Consultation & Triage Queue
                                    </h3>
                                    <p class="mt-1 text-xs text-slate-500">All resident consultation records submitted for
                                        Nursing Triage</p>
                                </div>
                            </div>

                            <?php if (empty($opdConsultations)): ?>
                                <div class="text-center py-8 bg-slate-50/50 rounded-2xl border border-dashed border-gray-200">
                                    <span class="text-3xl block mb-2"></span>
                                    <p class="text-sm font-bold text-gray-700">No OPD Consultations Found</p>
                                    <p class="text-xs text-gray-400 mt-0.5">When residents book appointments for nursing care,
                                        they will appear here automatically.</p>
                                </div>
                            <?php else: ?>
                                <?php $queuePreview = $opdConsultations; ?>
                                <div class="triage-workbench" data-triage-workbench>
                                    <aside class="triage-suggest-pane rounded-2xl border border-slate-200 bg-slate-50/80 p-3">
                                        <div class="mb-3">
                                            <label for="triageQueueSearch" class="mb-1 block text-[11px] font-black uppercase tracking-wide text-slate-500">Search Resident</label>
                                            <input id="triageQueueSearch" data-triage-search class="triage-search" type="search" placeholder="Type a name, barangay, status..." autocomplete="off">
                                        </div>
                                        <div class="triage-patient-list" data-triage-list>
                                            <?php foreach ($queuePreview as $index => $c): ?>
                                                <?php
                                                    $tabSearch = strtolower(trim(($c['patientName'] ?? '') . ' ' . ($c['barangay'] ?? '') . ' ' . ($c['chiefComplaint'] ?? '') . ' ' . ($c['consultation_status'] ?? '') . ' ' . ($c['icd10'] ?? '')));
                                                ?>
                                                <button type="button" class="triage-patient-tab <?= $index === 0 ? 'is-active' : ''; ?>" data-triage-tab data-target="triage-panel-<?= (int) $c['id']; ?>" data-search="<?= esc($tabSearch); ?>">
                                                    <span class="block truncate text-sm font-extrabold text-slate-950"><?= esc($c['patientName']); ?></span>
                                                    <span class="mt-1 grid grid-cols-[minmax(0,1fr)_auto] items-center gap-2 text-[11px] font-bold text-slate-500">
                                                        <span class="min-w-0 truncate"><?= esc($c['chiefComplaint']); ?></span>
                                                        <span class="max-w-[92px] shrink-0 truncate rounded-full bg-white px-2 py-0.5 text-emerald-800 border border-emerald-100"><?= esc($c['consultation_status']); ?></span>
                                                    </span>
                                                </button>
                                            <?php endforeach; ?>
                                        </div>
                                        <p class="triage-empty-filter rounded-xl border border-dashed border-slate-300 bg-white p-3 text-center text-xs font-bold text-slate-500" data-triage-empty>No matching resident found.</p>
                                    </aside>

                                    <section class="triage-view-pane">
                                    <?php foreach ($queuePreview as $index => $c): ?>
                                        <?php $vitals = parseTriageVitals($c['consultation_notes'] ?? ''); ?>
                                        <div id="triage-panel-<?= (int) $c['id']; ?>"
                                            class="triage-detail-panel <?= $index === 0 ? 'is-active' : ''; ?>">
                                            <div class="triage-queue-card rounded-2xl bg-white p-4 sm:p-5 transition space-y-4">
                                            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                                <div class="min-w-0 space-y-2">
                                                    <div class="flex flex-wrap items-center gap-2">
                                                        <p class="min-w-0 text-base font-extrabold text-gray-950">
                                                            <?= esc($c['patientName']); ?></p>
                                                        <span
                                                            class="triage-chip bg-emerald-50 text-emerald-800 border border-emerald-100">
                                                            <?= esc($c['age'] ?? 'N/A'); ?>y &bull; <?= esc($c['gender']); ?>
                                                        </span>
                                                        <span
                                                            class="triage-chip bg-violet-50 text-violet-700 border border-violet-100">
                                                            Barangay <?= esc($c['barangay']); ?>
                                                        </span>
                                                    </div>
                                                    <p class="text-sm font-semibold text-slate-900">Chief Complaint: <span
                                                            class="font-normal text-slate-700"><?= esc($c['chiefComplaint']); ?></span>
                                                    </p>
                                                </div>
                                                <div class="flex shrink-0 flex-row items-center gap-2 lg:flex-col lg:items-end">
                                                    <span
                                                        class="font-mono text-xs bg-slate-50 text-slate-800 font-bold px-3 py-1.5 rounded-xl border border-slate-200"><?= esc($c['icd10'] ?: 'OPD Triage'); ?></span>
                                                    <p class="text-xs font-semibold text-slate-400"><?= esc($c['date']); ?>
                                                    </p>
                                                </div>
                                            </div>

                                            <?php if (!empty($vitals)): ?>
                                                <div class="rounded-2xl border border-emerald-100 bg-emerald-50/50 p-3">
                                                    <p class="mb-2 text-[11px] font-black uppercase tracking-wide text-emerald-900">Triage Vitals</p>
                                                    <div class="triage-vitals-grid">
                                                        <?php if (!empty($vitals['bp'])): ?><div class="triage-vital"><span>Blood Pressure</span><strong><?= esc($vitals['bp']); ?></strong></div><?php endif; ?>
                                                        <?php if (!empty($vitals['temp'])): ?><div class="triage-vital"><span>Temperature</span><strong><?= esc($vitals['temp']); ?></strong></div><?php endif; ?>
                                                        <?php if (!empty($vitals['weight'])): ?><div class="triage-vital"><span>Weight</span><strong><?= esc($vitals['weight']); ?></strong></div><?php endif; ?>
                                                        <?php if (!empty($vitals['hr'])): ?><div class="triage-vital"><span>Heart Rate</span><strong><?= esc($vitals['hr']); ?></strong></div><?php endif; ?>
                                                        <?php if (!empty($vitals['rr'])): ?><div class="triage-vital"><span>Resp. Rate</span><strong><?= esc($vitals['rr']); ?></strong></div><?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>

                                            <details class="triage-note rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                                                <summary class="cursor-pointer text-xs font-extrabold text-slate-700">Clinical Notes</summary>
                                                <p class="mt-2 text-xs font-medium leading-relaxed text-slate-600"><?= esc($c['consultation_notes']); ?></p>
                                            </details>

                                            <!-- VIEW-ONLY CONSULTATION RESPONSE -->
                                            <details class="triage-response group border-t border-slate-100 pt-3">
                                                <summary
                                                    class="cursor-pointer list-none text-xs font-bold text-slate-800 hover:text-emerald-800 flex items-center justify-between gap-3 py-1">
                                                    <span>Consultation Response</span>
                                                    <span
                                                        class="ml-auto rounded-full bg-emerald-50 px-3 py-1 text-[11px] font-extrabold text-emerald-800 border border-emerald-100">Status:
                                                        <?= esc($c['consultation_status']); ?></span>
                                                </summary>
                                                <div class="mt-3 grid grid-cols-1 gap-3 rounded-2xl border border-slate-200 bg-white p-3 sm:grid-cols-3 sm:p-4">
                                                    <div class="rounded-xl bg-slate-50 p-3">
                                                        <p class="text-[10px] font-black uppercase tracking-wide text-slate-500">Assessment</p>
                                                        <p class="mt-1 text-sm font-bold text-slate-900"><?= esc($c['diagnosis'] ?: 'No assessment recorded'); ?></p>
                                                    </div>
                                                    <div class="rounded-xl bg-slate-50 p-3">
                                                        <p class="text-[10px] font-black uppercase tracking-wide text-slate-500">Medication</p>
                                                        <p class="mt-1 text-sm font-bold text-slate-900"><?= esc($c['medications'] ?: 'None recorded'); ?></p>
                                                    </div>
                                                    <div class="rounded-xl bg-slate-50 p-3">
                                                        <p class="text-[10px] font-black uppercase tracking-wide text-slate-500">Current Status</p>
                                                        <p class="mt-1 text-sm font-bold text-emerald-800"><?= esc($c['consultation_status']); ?></p>
                                                    </div>
                                                </div>
                                            </details>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    </section>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- TAB: RESIDENT REQUESTS -->
                <?php if ($tab === 'requests'): ?>
                    <div class="space-y-4">
                        <div class="flex flex-wrap items-center justify-between gap-3 bg-white p-4 rounded-2xl border border-emerald-100 shadow-sm">
                            <div>
                                <h2 class="text-base sm:text-xl font-extrabold text-gray-900">Resident Requests</h2>
                                <p class="text-xs text-gray-500">All requests submitted from the Resident Portal with resident profile, contact, and health details.</p>
                            </div>
                            <span class="rounded-full border border-emerald-100 bg-emerald-50 px-3 py-1 text-xs font-extrabold text-emerald-800">
                                <?= count($residentRequests); ?> request<?= count($residentRequests) === 1 ? '' : 's'; ?>
                            </span>
                        </div>

                        <?php if (empty($residentRequests)): ?>
                            <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center shadow-sm">
                                <p class="text-sm font-extrabold text-slate-800">No resident requests found</p>
                                <p class="mt-1 text-xs font-medium text-slate-500">New OPD appointments, resident messages, certificate requests, and emergency referrals will appear here.</p>
                            </div>
                        <?php else: ?>
                            <div class="triage-workbench" data-triage-workbench>
                                <aside class="triage-suggest-pane rounded-2xl border border-slate-200 bg-slate-50/80 p-3">
                                    <div class="mb-3">
                                        <label for="residentRequestSearch" class="mb-1 block text-[11px] font-black uppercase tracking-wide text-slate-500">Search Request</label>
                                        <input id="residentRequestSearch" data-triage-search class="triage-search" type="search" placeholder="Type name, barangay, request, status..." autocomplete="off">
                                    </div>
                                    <div class="triage-patient-list" data-triage-list>
                                        <?php foreach ($residentRequests as $index => $request): ?>
                                            <?php
                                            $residentInfo = $request['resident'] ?? [];
                                            $requestSearch = strtolower(trim(
                                                ($request['resident_name'] ?? '') . ' ' .
                                                ($request['barangay'] ?? '') . ' ' .
                                                ($request['type'] ?? '') . ' ' .
                                                ($request['title'] ?? '') . ' ' .
                                                ($request['details'] ?? '') . ' ' .
                                                ($request['status'] ?? '')
                                            ));
                                            ?>
                                            <button type="button" class="triage-patient-tab <?= $index === 0 ? 'is-active' : ''; ?>" data-triage-tab data-target="resident-request-panel-<?= $index; ?>" data-search="<?= esc($requestSearch); ?>">
                                                <span class="block truncate text-sm font-extrabold text-slate-950"><?= esc($request['resident_name'] ?? 'Resident'); ?></span>
                                                <span class="mt-1 grid grid-cols-[minmax(0,1fr)_auto] items-center gap-2 text-[11px] font-bold text-slate-500">
                                                    <span class="min-w-0 truncate"><?= esc($request['type'] ?? 'Request'); ?></span>
                                                    <span class="max-w-[96px] shrink-0 truncate rounded-full bg-white px-2 py-0.5 text-emerald-800 border border-emerald-100"><?= esc($request['status'] ?? 'Pending'); ?></span>
                                                </span>
                                                <span class="mt-2 block truncate text-[10px] font-bold text-slate-500">
                                                    <?= esc($request['requested_at'] ? date('M d, Y g:i A', strtotime($request['requested_at'])) : ($request['request_date'] ?? 'No date')); ?>
                                                </span>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                    <p class="triage-empty-filter rounded-xl border border-dashed border-slate-300 bg-white p-3 text-center text-xs font-bold text-slate-500" data-triage-empty>No matching request found.</p>
                                </aside>

                                <section class="triage-view-pane">
                                    <?php foreach ($residentRequests as $index => $request): ?>
                                        <?php
                                        $residentInfo = $request['resident'] ?? [];
                                        $requestStatus = strtolower((string)($request['status'] ?? ''));
                                        $statusClass = str_contains($requestStatus, 'urgent') || str_contains($requestStatus, 'emergency')
                                            ? 'bg-rose-50 text-rose-800 border-rose-100'
                                            : (str_contains($requestStatus, 'pending') || str_contains($requestStatus, 'scheduled') ? 'bg-amber-50 text-amber-800 border-amber-100' : 'bg-emerald-50 text-emerald-800 border-emerald-100');
                                        ?>
                                        <div id="resident-request-panel-<?= $index; ?>" class="triage-detail-panel <?= $index === 0 ? 'is-active' : ''; ?>">
                                            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                                                <div class="flex flex-col gap-3 border-b border-slate-100 pb-4 lg:flex-row lg:items-start lg:justify-between">
                                                    <div class="min-w-0 space-y-2">
                                                        <div class="flex flex-wrap items-center gap-2">
                                                            <h3 class="text-lg font-extrabold text-slate-950"><?= esc($request['resident_name'] ?? 'Resident'); ?></h3>
                                                            <span class="triage-chip border border-emerald-100 bg-emerald-50 text-emerald-800">Resident #<?= (int)($request['resident_id'] ?? 0); ?></span>
                                                            <span class="triage-chip border border-violet-100 bg-violet-50 text-violet-700">Barangay <?= esc($request['barangay'] ?? 'Unassigned'); ?></span>
                                                        </div>
                                                        <p class="text-sm font-bold text-slate-900"><?= esc($request['title'] ?? 'Resident request'); ?></p>
                                                        <p class="text-xs font-semibold text-slate-500"><?= esc($request['type'] ?? 'Request'); ?> &bull; Source <?= esc($request['source'] ?? 'portal'); ?> #<?= (int)($request['source_id'] ?? 0); ?></p>
                                                    </div>
                                                    <div class="flex shrink-0 flex-col gap-2 lg:items-end">
                                                        <span class="w-fit rounded-full border px-3 py-1 text-[11px] font-extrabold <?= $statusClass; ?>"><?= esc($request['status'] ?? 'Pending'); ?></span>
                                                        <span class="text-xs font-bold text-slate-500"><?= esc($request['requested_at'] ? date('M d, Y g:i A', strtotime($request['requested_at'])) : ($request['request_date'] ?? 'No date')); ?></span>
                                                    </div>
                                                </div>

                                                <div class="mt-4 grid grid-cols-1 gap-3 lg:grid-cols-3">
                                                    <section class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
                                                        <p class="text-[10px] font-black uppercase tracking-wide text-slate-500">Resident Information</p>
                                                        <dl class="mt-2 space-y-1.5 text-xs">
                                                            <div><dt class="font-bold text-slate-500">Birthdate / Age</dt><dd class="font-semibold text-slate-900"><?= esc($residentInfo['date_of_birth'] ?? 'Not recorded'); ?><?= !empty($residentInfo['age']) ? ' - ' . esc($residentInfo['age']) . ' years old' : ''; ?></dd></div>
                                                            <div><dt class="font-bold text-slate-500">Sex / Civil Status</dt><dd class="font-semibold text-slate-900"><?= esc($residentInfo['gender'] ?? 'Not recorded'); ?><?= !empty($residentInfo['civil_status']) ? ' / ' . esc($residentInfo['civil_status']) : ''; ?></dd></div>
                                                            <div><dt class="font-bold text-slate-500">Address</dt><dd class="font-semibold text-slate-900"><?= esc($residentInfo['address'] ?? 'Not recorded'); ?></dd></div>
                                                        </dl>
                                                    </section>

                                                    <section class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
                                                        <p class="text-[10px] font-black uppercase tracking-wide text-slate-500">Contact Details</p>
                                                        <dl class="mt-2 space-y-1.5 text-xs">
                                                            <div><dt class="font-bold text-slate-500">Phone</dt><dd class="font-semibold text-slate-900"><?= esc($residentInfo['contactNo'] ?? 'Not recorded'); ?></dd></div>
                                                            <div><dt class="font-bold text-slate-500">Email</dt><dd class="break-all font-semibold text-slate-900"><?= esc($residentInfo['email'] ?? 'Not recorded'); ?></dd></div>
                                                            <div><dt class="font-bold text-slate-500">Emergency Contact</dt><dd class="font-semibold text-slate-900"><?= esc(trim(($residentInfo['emergencyContactName'] ?? '') . ' ' . ($residentInfo['emergencyContactNo'] ?? '')) ?: 'Not recorded'); ?></dd></div>
                                                        </dl>
                                                    </section>

                                                    <section class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
                                                        <p class="text-[10px] font-black uppercase tracking-wide text-slate-500">Health Snapshot</p>
                                                        <dl class="mt-2 space-y-1.5 text-xs">
                                                            <div><dt class="font-bold text-slate-500">Blood / PhilHealth</dt><dd class="font-semibold text-slate-900"><?= esc($residentInfo['bloodType'] ?? 'Not recorded'); ?> / <?= esc($residentInfo['philhealthNo'] ?? 'Not recorded'); ?></dd></div>
                                                            <div><dt class="font-bold text-slate-500">Vitals</dt><dd class="font-semibold text-slate-900">Ht <?= esc($residentInfo['height'] ?? '-'); ?> cm, Wt <?= esc($residentInfo['weight'] ?? '-'); ?> kg, BP <?= esc($residentInfo['bloodPressure'] ?? '-'); ?></dd></div>
                                                            <div><dt class="font-bold text-slate-500">Allergies / Conditions</dt><dd class="font-semibold text-slate-900"><?= esc($residentInfo['allergies'] ?? 'None recorded'); ?><?= !empty($residentInfo['medicalHistory']) ? ' / ' . esc($residentInfo['medicalHistory']) : ''; ?></dd></div>
                                                        </dl>
                                                    </section>
                                                </div>

                                                <section class="mt-4 rounded-2xl border border-emerald-100 bg-emerald-50/60 p-4">
                                                    <p class="text-[10px] font-black uppercase tracking-wide text-emerald-900">Complete Request Details</p>
                                                    <p class="mt-2 whitespace-pre-line text-sm font-semibold leading-relaxed text-slate-900"><?= esc($request['details'] ?? 'No details recorded.'); ?></p>
                                                </section>

                                                <div class="mt-4 flex flex-wrap justify-end gap-2">
                                                    <a href="<?= esc(tabUrl('patients', ['resident_id' => (int)($request['resident_id'] ?? 0)])); ?>" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-extrabold text-slate-700 hover:bg-slate-50">Open Patient Record</a>
                                                    <a href="<?= esc($request['action_url'] ?? tabUrl('opd')); ?>" class="rounded-xl bg-emerald-700 px-3 py-2 text-xs font-extrabold text-white shadow-sm hover:bg-emerald-800">Review in Nurse Workflow</a>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </section>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- TAB 2: OPD TRIAGE & NURSING LOG -->
                <?php if ($tab === 'opd'): ?>
                    <div class="space-y-4">
                        <div
                            class="flex flex-wrap items-center justify-between gap-3 bg-white p-4 rounded-2xl border border-emerald-100 shadow-sm">
                            <div>
                                <h2 class="text-base sm:text-xl font-extrabold text-gray-900 flex items-center gap-2">OPD
                                    Triage & Nursing Assessment Log</h2>
                                <p class="text-xs text-gray-500">Record and review vital signs assessment for all outpatient
                                    consultations</p>
                            </div>
                        </div>

                        <section class="dashboard-card bg-white rounded-2xl p-4 sm:p-5 shadow-sm border border-emerald-100/80">
                            <div class="mb-4 flex flex-col gap-2 border-b border-slate-100 pb-3 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <h3 class="text-sm font-extrabold text-slate-950">Record OPD Patient Triage &amp; Vitals</h3>
                                    <p class="mt-0.5 text-xs font-medium text-slate-500">Patient intake, vital signs, diagnosis, and initial medication record.</p>
                                </div>
                                <span class="w-fit rounded-full bg-emerald-50 px-3 py-1 text-[11px] font-extrabold text-emerald-800 border border-emerald-100">New Record</span>
                            </div>
                            <form class="resident-record-form grid grid-cols-1 gap-3 text-xs lg:grid-cols-2" method="post">
                                <input type="hidden" name="action" value="save_triage">

                                <div>
                                    <label class="block font-bold text-gray-700 mb-1">Select Patient Resident *</label>
                                    <select name="resident_id" required class="w-full p-2.5 border border-gray-300 rounded-xl text-sm font-semibold focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 outline-none">
                                        <option value="">-- Select Resident Patient --</option>
                                        <?php foreach ($allResidentsList as $r): ?>
                                            <option value="<?= esc($r['id']); ?>"><?= esc($r['name']); ?> (<?= esc($r['barangay']); ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div>
                                    <label class="block font-bold text-gray-700 mb-1">Attending Physician / Staff *</label>
                                    <input type="hidden" name="physician_id" value="<?= (int) ($_SESSION['rhu_staff_login']['staff_id'] ?? 0); ?>">
                                    <div class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-bold text-slate-900">
                                        <?= esc($_SESSION['rhu_staff_login']['name'] ?? 'Logged-in Nurse'); ?>
                                        <span class="font-semibold text-slate-500">(<?= esc($_SESSION['rhu_staff_login']['staff_type'] ?? 'Public Health Nurse'); ?>)</span>
                                    </div>
                                </div>

                                <div class="lg:col-span-2">
                                    <label class="block font-bold text-gray-700 mb-1">Chief Health Complaint *</label>
                                    <input name="chief_complaint" required placeholder="e.g., High fever, productive cough, and body malaise" class="w-full p-2.5 border border-gray-300 rounded-xl text-sm focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 outline-none">
                                </div>

                                <section class="rounded-xl border border-emerald-100 bg-emerald-50/60 px-3 py-3 lg:col-span-2">
                                    <div class="mb-3 flex items-center justify-between gap-2">
                                        <p class="text-xs font-extrabold text-emerald-900">Vital Signs Assessment</p>
                                        <span class="text-[10px] font-bold uppercase tracking-wide text-emerald-700">Initial Triage</span>
                                    </div>
                                    <div class="mt-3 grid grid-cols-2 gap-2 md:grid-cols-5">
                                        <div>
                                            <label class="block font-bold text-gray-700 mb-1">BP</label>
                                            <input name="bp" value="120/80" class="w-full p-2 border border-gray-300 rounded-lg text-xs font-mono font-bold text-gray-800">
                                        </div>
                                        <div>
                                            <label class="block font-bold text-gray-700 mb-1">Temp</label>
                                            <input name="temp" value="36.8C" class="w-full p-2 border border-gray-300 rounded-lg text-xs font-mono font-bold text-gray-800">
                                        </div>
                                        <div>
                                            <label class="block font-bold text-gray-700 mb-1">Weight</label>
                                            <input name="weight" value="60 kg" class="w-full p-2 border border-gray-300 rounded-lg text-xs font-mono font-bold text-gray-800">
                                        </div>
                                        <div>
                                            <label class="block font-bold text-gray-700 mb-1">Resp. Rate</label>
                                            <input name="rr" value="18/min" class="w-full p-2 border border-gray-300 rounded-lg text-xs font-bold text-gray-800">
                                        </div>
                                        <div>
                                            <label class="block font-bold text-gray-700 mb-1">Heart Rate</label>
                                            <input name="hr" value="75 bpm" class="w-full p-2 border border-gray-300 rounded-lg text-xs font-bold text-gray-800">
                                        </div>
                                    </div>
                                </section>

                                <div class="icd-lookup-wrap">
                                    <label class="block font-bold text-gray-700 mb-1">Primary Diagnosis</label>
                                    <input name="diagnosis" value="Acute Upper Respiratory Tract Infection" class="w-full p-2.5 border border-gray-300 rounded-xl text-xs font-semibold" data-icd-diagnosis-input data-icd-target="new_triage_icd10">
                                    <div class="icd-suggestions" data-icd-suggestions></div>
                                </div>
                                <div>
                                    <label class="block font-bold text-gray-700 mb-1">ICD-10 Code</label>
                                    <input id="new_triage_icd10" name="icd10" value="J06.9" class="w-full p-2.5 border border-gray-300 rounded-xl text-xs font-mono font-bold" data-icd-code-input>
                                </div>

                                <div class="lg:col-span-2">
                                    <label class="block font-bold text-gray-700 mb-1">Prescribed Medications</label>
                                    <input name="medications" placeholder="Paracetamol 500mg, Amoxicillin 500mg" class="w-full p-2.5 border border-gray-300 rounded-xl text-xs">
                                </div>

                                <div class="flex justify-end lg:col-span-2">
                                    <button type="submit" class="w-full rounded-xl bg-emerald-700 px-4 py-2.5 text-xs font-extrabold text-white shadow-sm transition hover:bg-emerald-800 sm:w-auto">Save Nursing Triage Vitals</button>
                                </div>
                            </form>
                        </section>

                        <?php if (empty($opdConsultations)): ?>
                            <div class="text-center py-12 bg-white rounded-2xl border border-gray-200/80 shadow-sm">
                                <span
                                    class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-400"><svg
                                        class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <circle cx="12" cy="12" r="10" />
                                        <path d="M12 8v4" />
                                        <path d="M12 16h.01" />
                                    </svg></span>
                                <p class="text-sm font-bold text-gray-700">No OPD Triage Consultations Recorded</p>
                                <p class="text-xs text-gray-400 mt-0.5">Use the intake form above to start logging patient vital signs.</p>
                            </div>
                        <?php else: ?>
                            <div class="triage-workbench" data-triage-workbench data-consult-date-workbench>
                                <aside class="triage-suggest-pane rounded-2xl border border-slate-200 bg-slate-50/80 p-3">
                                    <div class="mb-3">
                                        <label for="opdTriageSearch" class="mb-1 block text-[11px] font-black uppercase tracking-wide text-slate-500">Search Record</label>
                                        <input id="opdTriageSearch" data-triage-search class="triage-search" type="search" placeholder="Type patient, complaint, status..." autocomplete="off">
                                    </div>
                                    <div class="triage-patient-list" data-triage-list>
                                        <?php foreach ($opdResidents as $index => $resident): ?>
                                            <?php
                                            $latestRecord = $resident['records'][0] ?? [];
                                            $recordDates = array_values(array_unique(array_filter(array_map(function ($item) { return (string) ($item['date'] ?? ''); }, $resident['records']))));
                                            $tabSearch = strtolower(trim(($resident['patientName'] ?? '') . ' ' . ($resident['barangay'] ?? '') . ' ' . implode(' ', array_map(function ($item) { return (($item['chiefComplaint'] ?? '') . ' ' . ($item['consultation_status'] ?? '') . ' ' . ($item['icd10'] ?? '') . ' ' . ($item['date'] ?? '')); }, $resident['records']))));
                                            ?>
                                            <button type="button" class="triage-patient-tab <?= $index === 0 ? 'is-active' : ''; ?>" data-triage-tab data-target="opd-resident-panel-<?= (int) $resident['resident_id']; ?>" data-search="<?= esc($tabSearch); ?>">
                                                <span class="block truncate text-sm font-extrabold text-slate-950"><?= esc($resident['patientName']); ?></span>
                                                <span class="mt-1 grid grid-cols-[minmax(0,1fr)_auto] items-center gap-2 text-[11px] font-bold text-slate-500">
                                                    <span class="min-w-0 truncate"><?= esc($latestRecord['chiefComplaint'] ?? 'No complaint recorded'); ?></span>
                                                    <span class="max-w-[92px] shrink-0 truncate rounded-full bg-white px-2 py-0.5 text-emerald-800 border border-emerald-100"><?= count($resident['records']); ?> visit<?= count($resident['records']) === 1 ? '' : 's'; ?></span>
                                                </span>
                                                <span class="mt-2 flex flex-wrap gap-1 text-[10px] font-bold text-slate-500">
                                                    <?php foreach (array_slice($recordDates, 0, 3) as $recordDate): ?>
                                                        <span class="rounded-full border border-slate-200 bg-white px-2 py-0.5"><?= esc($recordDate); ?></span>
                                                    <?php endforeach; ?>
                                                </span>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                    <p class="triage-empty-filter rounded-xl border border-dashed border-slate-300 bg-white p-3 text-center text-xs font-bold text-slate-500" data-triage-empty>No matching record found.</p>
                                </aside>

                                <section class="triage-view-pane">
                                    <?php foreach ($opdResidents as $index => $resident): ?>
                                        <div id="opd-resident-panel-<?= (int) $resident['resident_id']; ?>" class="triage-detail-panel <?= $index === 0 ? 'is-active' : ''; ?>">
                                            <div class="triage-queue-card rounded-2xl bg-white p-4 sm:p-5 transition">
                                                <div class="mb-4 flex flex-col gap-3 border-b border-slate-100 pb-3 lg:flex-row lg:items-start lg:justify-between">
                                                    <div class="min-w-0 space-y-2">
                                                        <div class="flex flex-wrap items-center gap-2">
                                                            <p class="min-w-0 text-base font-extrabold text-gray-950"><?= esc($resident['patientName']); ?></p>
                                                            <span class="triage-chip bg-emerald-50 text-emerald-800 border border-emerald-100"><?= esc($resident['age'] ?: 'N/A'); ?>y &bull; <?= esc($resident['gender']); ?></span>
                                                            <span class="triage-chip bg-violet-50 text-violet-700 border border-violet-100">Barangay <?= esc($resident['barangay']); ?></span>
                                                        </div>
                                                        <p class="text-xs font-semibold text-slate-500"><?= count($resident['records']); ?> consultation record<?= count($resident['records']) === 1 ? '' : 's'; ?> on file. Select a date to view that visit.</p>
                                                    </div>
                                                    <label class="w-full max-w-xs">
                                                        <span class="mb-1 block text-[11px] font-bold text-slate-700">Filter by Consultation Date</span>
                                                        <select class="w-full rounded-xl border border-slate-300 bg-white p-2.5 text-sm font-bold text-emerald-900 outline-none focus:border-emerald-500" data-consult-date-select>
                                                            <?php foreach ($resident['records'] as $recordIndex => $record): ?>
                                                                <option value="consult-record-<?= (int) $record['id']; ?>" <?= $recordIndex === 0 ? 'selected' : ''; ?>><?= esc($record['date'] ?? 'No date'); ?> - <?= esc($record['consultation_status'] ?? 'Scheduled'); ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </label>
                                                </div>

                                                <?php foreach ($resident['records'] as $recordIndex => $c): ?>
                                                    <?php $vitals = parseTriageVitals($c['consultation_notes'] ?? ''); ?>
                                                    <form method="post" action="NurseDashboard.php?tab=opd" id="consult-record-<?= (int) $c['id']; ?>" class="space-y-4 <?= $recordIndex === 0 ? '' : 'hidden'; ?>" data-consult-date-record>
                                                <input type="hidden" name="action" value="answer_consultation">
                                                <input type="hidden" name="return_tab" value="opd">
                                                <input type="hidden" name="consultation_id" value="<?= (int) $c['id']; ?>">
                                                <input type="hidden" name="resident_id" value="<?= (int) ($c['resident_id'] ?? 0); ?>">

                                                <div class="flex flex-col gap-3 border-b border-slate-100 pb-3 lg:flex-row lg:items-start lg:justify-between">
                                                    <div class="min-w-0 space-y-1">
                                                        <p class="text-sm font-semibold text-slate-900">Chief Complaint: <span class="font-normal text-slate-700"><?= esc($c['chiefComplaint']); ?></span></p>
                                                        <p class="text-xs font-semibold text-slate-400"><?= esc($c['date']); ?></p>
                                                    </div>
                                                    <div class="flex shrink-0 flex-row items-center gap-2 lg:flex-col lg:items-end">
                                                        <span class="font-mono text-xs bg-slate-50 text-slate-800 font-bold px-3 py-1.5 rounded-xl border border-slate-200"><?= esc($c['icd10'] ?: 'OPD Triage'); ?></span>
                                                        <?php if (!empty($c['referral_needed'])): ?>
                                                            <span class="max-w-[180px] truncate rounded-full bg-violet-50 px-3 py-1 text-[11px] font-bold text-violet-700 border border-violet-100">Referred to <?= esc($c['referral_to']); ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>

                                                <?php if (!empty($vitals)): ?>
                                                    <details class="triage-note rounded-xl border border-emerald-100 bg-emerald-50/50 px-3 py-2" open>
                                                        <summary class="cursor-pointer text-xs font-extrabold text-emerald-900">Vitals</summary>
                                                        <div class="triage-vitals-grid mt-2">
                                                            <?php if (!empty($vitals['bp'])): ?><div class="triage-vital"><span>Blood Pressure</span><strong><?= esc($vitals['bp']); ?></strong></div><?php endif; ?>
                                                            <?php if (!empty($vitals['temp'])): ?><div class="triage-vital"><span>Temperature</span><strong><?= esc($vitals['temp']); ?></strong></div><?php endif; ?>
                                                            <?php if (!empty($vitals['weight'])): ?><div class="triage-vital"><span>Weight</span><strong><?= esc($vitals['weight']); ?></strong></div><?php endif; ?>
                                                            <?php if (!empty($vitals['hr'])): ?><div class="triage-vital"><span>Heart Rate</span><strong><?= esc($vitals['hr']); ?></strong></div><?php endif; ?>
                                                            <?php if (!empty($vitals['rr'])): ?><div class="triage-vital"><span>Resp. Rate</span><strong><?= esc($vitals['rr']); ?></strong></div><?php endif; ?>
                                                        </div>
                                                    </details>
                                                <?php endif; ?>

                                                <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
                                                    <div class="icd-lookup-wrap">
                                                        <label class="block text-[11px] font-bold text-slate-700 mb-1">Clinical Diagnosis / Assessment</label>
                                                        <input type="text" name="diagnosis" value="<?= esc($c['diagnosis'] ?? ''); ?>" class="w-full rounded-xl border border-slate-300 bg-white p-2.5 text-sm outline-none focus:border-emerald-500" placeholder="Enter clinical assessment" required data-icd-diagnosis-input data-icd-target="opd_icd10_<?= (int) $c['id']; ?>">
                                                        <div class="icd-suggestions" data-icd-suggestions></div>
                                                    </div>
                                                    <div>
                                                        <label class="block text-[11px] font-bold text-slate-700 mb-1">ICD-10 Code</label>
                                                        <input id="opd_icd10_<?= (int) $c['id']; ?>" name="icd10" value="<?= esc($c['icd10'] ?? ''); ?>" class="w-full rounded-xl border border-slate-300 bg-white p-2.5 text-sm font-mono font-bold outline-none focus:border-emerald-500" placeholder="Auto-filled from diagnosis" data-icd-code-input>
                                                    </div>
                                                </div>

                                                <div>
                                                    <label class="block text-[11px] font-bold text-slate-700 mb-1">Consultation Status</label>
                                                        <select name="consultation_status" class="w-full rounded-xl border border-slate-300 bg-white p-2.5 text-sm font-bold text-emerald-900 outline-none focus:border-emerald-500">
                                                            <option value="Completed" <?= ($c['consultation_status'] ?? '') === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                                                            <option value="In Progress" <?= ($c['consultation_status'] ?? '') === 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                                                            <option value="Scheduled" <?= ($c['consultation_status'] ?? '') === 'Scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                                                            <option value="Referred" <?= ($c['consultation_status'] ?? '') === 'Referred' ? 'selected' : ''; ?>>Referred to Doctor</option>
                                                        </select>
                                                </div>

                                                <section class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-3">
                                                    <div class="mb-3 flex items-center justify-between gap-2">
                                                        <p class="text-xs font-extrabold text-slate-800">Edit Notes & Medication</p>
                                                        <span class="text-[10px] font-bold uppercase tracking-wide text-emerald-700">Clinical Plan</span>
                                                    </div>
                                                    <div class="space-y-3">
                                                        <div>
                                                            <label class="block text-[11px] font-bold text-slate-700 mb-1">Clinical Assessment Notes</label>
                                                            <textarea name="consultation_notes" rows="3" class="w-full min-h-[84px] rounded-xl border border-slate-300 bg-white p-2.5 text-sm outline-none resize-y focus:border-emerald-500" placeholder="Enter notes and recommendations"><?= esc($c['consultation_notes'] ?? ''); ?></textarea>
                                                        </div>
                                                        <div>
                                                            <label class="block text-[11px] font-bold text-slate-700 mb-1">Prescribed Medications</label>
                                                            <input type="text" name="medications_prescribed" value="<?= esc($c['medications'] ?? ''); ?>" class="w-full rounded-xl border border-slate-300 bg-white p-2.5 text-sm outline-none focus:border-emerald-500" placeholder="e.g. Paracetamol, ORS">
                                                        </div>
                                                    </div>
                                                </section>

                                                <div class="flex justify-end">
                                                    <button type="submit" name="save_changes" value="1" class="w-full rounded-xl bg-emerald-700 px-4 py-2.5 text-xs font-extrabold text-white shadow-sm transition hover:bg-emerald-800 sm:w-auto">Save Changes</button>
                                                </div>
                                                    </form>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </section>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- TAB 3: PATIENT RECORDS -->
                <?php if ($tab === 'patients'): ?>
                    <div class="space-y-4">
                        <div
                            class="flex flex-wrap items-center justify-between gap-3 bg-white p-4 rounded-2xl border border-blue-100 shadow-sm">
                            <div>
                                <h2 class="text-base sm:text-xl font-extrabold text-gray-900 flex items-center gap-2">
                                    Municipal Resident Health Records</h2>
                                <p class="text-xs text-gray-500">Complete resident health profiles, medical history,
                                    allergies, and vitals registry</p>
                            </div>
                            <a href="<?= esc(tabUrl('patients', ['modal' => 'new_patient_record'])); ?>"
                                class="px-4 py-2.5 bg-teal-600 hover:bg-teal-700 text-white text-xs font-bold rounded-xl shadow-md transition-all flex items-center gap-1.5">
                                <span>+</span> Add / Register Medical Record
                            </a>
                        </div>

                        <?php if (empty($patientRecords)): ?>
                            <div class="text-center py-12 bg-white rounded-2xl border border-gray-200/80 shadow-sm">
                                <span
                                    class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-400"><svg
                                        class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <circle cx="12" cy="12" r="10" />
                                        <path d="M12 8v4" />
                                        <path d="M12 16h.01" />
                                    </svg></span>
                                <p class="text-sm font-bold text-gray-700">No Patient Records Registered</p>
                                <p class="text-xs text-gray-400 mt-0.5">Click "+ Add / Register Medical Record" above to record
                                    a resident's medical info.</p>
                            </div>
                        <?php else: ?>
                            <div class="space-y-3">
                                <?php foreach ($patientRecords as $p): ?>
                                    <div
                                        class="dashboard-card bg-white rounded-2xl p-5 shadow-sm border border-gray-200/80 hover:border-blue-200 transition-all space-y-3">
                                        <div class="flex flex-wrap items-start justify-between gap-2 border-b border-gray-100 pb-3">
                                            <div>
                                                <div class="flex items-center gap-2">
                                                    <span
                                                        class="font-mono font-bold text-xs bg-slate-100 text-slate-700 px-2 py-0.5 rounded">RES-<?= $p['id']; ?></span>
                                                    <p class="font-extrabold text-gray-900 text-base"><?= esc($p['name']); ?></p>
                                                    <span
                                                        class="text-xs font-bold text-purple-800 bg-purple-50 px-2 py-0.5 rounded border border-purple-100">
                                                        <?= esc($p['barangay']); ?>
                                                    </span>
                                                </div>
                                                <p class="text-xs text-gray-600 mt-1 font-semibold">
                                                    Age: <?= esc($p['age'] ?? 'N/A'); ?>y • Sex: <?= esc($p['gender']); ?> •
                                                    PhilHealth No: <span
                                                        class="font-mono text-blue-900"><?= esc($p['philhealthNo'] ?: 'N/A'); ?></span>
                                                    <?php if (!empty($p['contactNo'])): ?> ·
                                                        <?= esc($p['contactNo']); ?>            <?php endif; ?>
                                                </p>
                                            </div>
                                            <div class="flex items-center gap-2">
                                                <span
                                                    class="font-bold text-xs text-red-700 bg-red-50 px-2.5 py-1 rounded-lg border border-red-200">
                                                    Blood Type: <?= esc($p['bloodType'] ?: 'O+'); ?>
                                                </span>
                                                <a href="<?= esc(tabUrl('patients', ['modal' => 'new_patient_record', 'resident_id' => $p['id']])); ?>"
                                                    class="px-3 py-1 bg-slate-100 hover:bg-blue-50 text-blue-700 border border-slate-200 hover:border-blue-300 font-bold text-xs rounded-xl transition-all">
                                                    Edit Medical Record
                                                </a>
                                            </div>
                                        </div>

                                        <!-- Medical Profile Details Pill Grid -->
                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5 text-xs">
                                            <div class="bg-slate-50/80 p-2.5 rounded-xl border border-slate-100">
                                                <span class="font-bold text-rose-900 block text-[11px]">Known Allergies:</span>
                                                <span
                                                    class="text-gray-800 font-medium"><?= esc($p['allergies'] ?: 'None Reported'); ?></span>
                                            </div>
                                            <div class="bg-slate-50/80 p-2.5 rounded-xl border border-slate-100">
                                                <span class="font-bold text-purple-900 block text-[11px]">Pre-existing Medical
                                                    History:</span>
                                                <span
                                                    class="text-gray-800 font-medium"><?= esc($p['medicalHistory'] ?: 'No chronic conditions recorded'); ?></span>
                                            </div>
                                        </div>

                                        <?php if (!empty($p['height']) || !empty($p['weight']) || !empty($p['bloodPressure'])): ?>
                                            <div
                                                class="flex flex-wrap items-center gap-2 bg-emerald-50/40 p-2 rounded-xl border border-emerald-100/60 text-xs">
                                                <span class="font-bold text-emerald-900 text-[11px] mr-1">Latest Physical
                                                    Profile:</span>
                                                <?php if (!empty($p['height'])): ?><span
                                                        class="bg-white text-emerald-800 font-mono font-bold px-2 py-0.5 rounded border border-emerald-200">Ht:
                                                        <?= esc($p['height']); ?> cm</span><?php endif; ?>
                                                <?php if (!empty($p['weight'])): ?><span
                                                        class="bg-white text-blue-800 font-mono font-bold px-2 py-0.5 rounded border border-blue-200">Wt:
                                                        <?= esc($p['weight']); ?> kg</span><?php endif; ?>
                                                <?php if (!empty($p['bloodPressure'])): ?><span
                                                        class="bg-white text-purple-800 font-mono font-bold px-2 py-0.5 rounded border border-purple-200">BP:
                                                        <?= esc($p['bloodPressure']); ?></span><?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- TAB 4: IMMUNIZATION -->
                <?php if ($tab === 'immunization'): ?>
                    <div class="space-y-4">
                        <h2 class="text-base sm:text-xl font-extrabold text-gray-900 flex items-center gap-2">Expanded
                            Program on Immunization (EPI) Monitoring</h2>
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                            <div
                                class="dashboard-card bg-white rounded-2xl p-4 shadow-sm border border-emerald-100 text-center">
                                <p class="text-3xl font-black text-emerald-700"><?= count($immunizationRecords); ?></p>
                                <p class="text-xs font-bold text-gray-600 mt-1">Administered Records</p>
                            </div>
                            <div
                                class="dashboard-card bg-white rounded-2xl p-4 shadow-sm border border-amber-100 text-center">
                                <p class="text-3xl font-black text-amber-600">3</p>
                                <p class="text-xs font-bold text-gray-600 mt-1">Due This Month</p>
                            </div>
                            <div
                                class="dashboard-card bg-white rounded-2xl p-4 shadow-sm border border-rose-100 text-center">
                                <p class="text-3xl font-black text-rose-600">0</p>
                                <p class="text-xs font-bold text-gray-600 mt-1">Overdue Vaccines</p>
                            </div>
                        </div>

                        <?php if (empty($immunizationRecords)): ?>
                            <div class="text-center py-12 bg-white rounded-2xl border border-gray-200/80 shadow-sm">
                                <span
                                    class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-400"><svg
                                        class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <circle cx="12" cy="12" r="10" />
                                        <path d="M12 8v4" />
                                        <path d="M12 16h.01" />
                                    </svg></span>
                                <p class="text-sm font-bold text-gray-700">No Immunization Records Found</p>
                                <p class="text-xs text-gray-400 mt-0.5">Vaccination records will appear here as doses are
                                    administered and logged.</p>
                            </div>
                        <?php else: ?>
                            <div class="space-y-3">
                                <?php foreach ($immunizationRecords as $im): ?>
                                    <div
                                        class="dashboard-card bg-white rounded-2xl p-4 shadow-sm border border-gray-200/80 flex items-center justify-between gap-3">
                                        <div>
                                            <p class="font-extrabold text-gray-900 text-sm sm:text-base">
                                                <?= esc($im['childName']); ?></p>
                                            <p class="text-xs text-gray-600 mt-0.5">Vaccine: <strong
                                                    class="text-indigo-900 font-extrabold"><?= esc($im['vaccineName']); ?></strong>
                                                (<?= esc($im['targetAge']); ?>) · Batch: <?= esc($im['lot']); ?></p>
                                            <p class="text-[11px] text-gray-400 mt-0.5">Barangay: <?= esc($im['barangay']); ?> ·
                                                Given: <?= esc($im['dateGiven']); ?> · Next Visit: <strong
                                                    class="text-emerald-700 font-bold"><?= esc($im['nextVisit']); ?></strong></p>
                                        </div>
                                        <span
                                            class="px-3 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800 border border-emerald-200 shrink-0">Administered</span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- TAB 5: NUTRITION (OPT+) -->
                <?php if ($tab === 'nutrition'): ?>
                    <div class="space-y-4">
                        <h2 class="text-base sm:text-xl font-extrabold text-gray-900 flex items-center gap-2">Operation
                            Timbang Plus (OPT+) Child Nutrition</h2>
                        <?php if (empty($nutritionCases)): ?>
                            <div class="text-center py-12 bg-white rounded-2xl border border-gray-200/80 shadow-sm">
                                <span
                                    class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-400"><svg
                                        class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <circle cx="12" cy="12" r="10" />
                                        <path d="M12 8v4" />
                                        <path d="M12 16h.01" />
                                    </svg></span>
                                <p class="text-sm font-bold text-gray-700">No Operation Timbang Profiles Recorded</p>
                                <p class="text-xs text-gray-400 mt-0.5">Child growth monitoring measurements will display here.
                                </p>
                            </div>
                        <?php else: ?>
                            <div class="space-y-3">
                                <?php foreach ($nutritionCases as $n): ?>
                                    <div
                                        class="dashboard-card bg-white rounded-2xl p-4 shadow-sm border border-gray-200/80 space-y-2.5">
                                        <div class="flex items-center justify-between">
                                            <p class="font-extrabold text-gray-900 text-sm"><?= esc($n['name']); ?> <span
                                                    class="text-xs text-gray-500 font-semibold">(<?= esc($n['age']); ?>y /
                                                    <?= esc($n['gender']); ?>)</span></p>
                                            <span
                                                class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800 border border-emerald-200">Normal
                                                Growth</span>
                                        </div>
                                        <div
                                            class="flex gap-4 text-xs font-semibold text-gray-600 bg-slate-50 p-3 rounded-xl border border-slate-200/60">
                                            <span>Barangay: <strong
                                                    class="text-gray-900"><?= esc($n['barangay']); ?></strong></span>
                                            <span>Height: <strong class="text-emerald-700"><?= esc($n['height']); ?>
                                                    cm</strong></span>
                                            <span>Weight: <strong class="text-blue-700"><?= esc($n['weight']); ?> kg</strong></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- TAB 6: TB-DOTS -->
                <?php if ($tab === 'tb'): ?>
                    <div class="space-y-4">
                        <h2 class="text-base sm:text-xl font-extrabold text-gray-900 flex items-center gap-2">TB-DOTS Case
                            Management &amp; Treatment Adherence</h2>
                        <?php if (empty($tbCases)): ?>
                            <div class="text-center py-12 bg-white rounded-2xl border border-gray-200/80 shadow-sm">
                                <span
                                    class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-400"><svg
                                        class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <circle cx="12" cy="12" r="10" />
                                        <path d="M12 8v4" />
                                        <path d="M12 16h.01" />
                                    </svg></span>
                                <p class="text-sm font-bold text-gray-700">No TB-DOTS Cases Recorded</p>
                                <p class="text-xs text-gray-400 mt-0.5">Tuberculosis patients on treatment regimen will be
                                    listed here.</p>
                            </div>
                        <?php else: ?>
                            <div class="space-y-3">
                                <?php foreach ($tbCases as $tb): ?>
                                    <div
                                        class="dashboard-card bg-white rounded-2xl p-4 shadow-sm border border-gray-200/80 space-y-2">
                                        <div class="flex items-center justify-between">
                                            <div>
                                                <p class="font-extrabold text-gray-900 text-sm sm:text-base">
                                                    <?= esc($tb['name']); ?> <span
                                                        class="text-xs text-gray-500 font-semibold">(<?= esc($tb['age']); ?>y /
                                                        <?= esc($tb['gender']); ?>)</span></p>
                                                <p class="text-xs text-gray-500 font-mono mt-0.5">DOH Reg No:
                                                    <?= esc($tb['tb_registration_number']); ?> · Type:
                                                    <?= esc($tb['classification']); ?></p>
                                            </div>
                                            <span
                                                class="px-3 py-1 rounded-full text-xs font-bold bg-amber-100 text-amber-900 border border-amber-200"><?= esc($tb['treatment_status']); ?></span>
                                        </div>
                                        <p class="text-xs text-gray-600 bg-slate-50 p-2.5 rounded-xl border border-slate-100">
                                            Barangay: <strong><?= esc($tb['barangay']); ?></strong> · Treatment Started:
                                            <strong><?= esc($tb['treatment_start_date']); ?></strong></p>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- TAB 7: DISEASE SURVEILLANCE (PIDSR) -->
                <?php if ($tab === 'disease'): ?>
                    <div class="space-y-4">
                        <h2 class="text-base sm:text-xl font-extrabold text-gray-900 flex items-center gap-2">Disease
                            Surveillance (PIDSR)</h2>
                        <div class="dashboard-card bg-white rounded-2xl p-5 shadow-sm border border-gray-200/80 space-y-4">
                            <div class="flex items-center justify-between border-b border-gray-100 pb-3">
                                <h3 class="font-extrabold text-gray-900 text-sm">Notifiable Diseases Active Surveillance
                                </h3>
                                <span
                                    class="text-xs font-bold bg-red-100 text-red-800 px-2.5 py-1 rounded-full border border-red-200">DOH
                                    Region IV-A</span>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                                <div class="p-4 bg-red-50/80 border border-red-200 rounded-2xl space-y-1">
                                    <p class="font-extrabold text-red-900 text-sm">Dengue Fever (ICD-10: A90)</p>
                                    <p class="text-red-700">Community vector control and larval source reduction active
                                        across barangays.</p>
                                </div>
                                <div class="p-4 bg-amber-50/80 border border-amber-200 rounded-2xl space-y-1">
                                    <p class="font-extrabold text-amber-900 text-sm">Leptospirosis (ICD-10: A27)</p>
                                    <p class="text-amber-700">Post-flood prophylaxis distribution and health education
                                        ongoing.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- TAB 8: BHW MANAGEMENT -->
                <?php if ($tab === 'bhw'): ?>
                    <div class="space-y-4">
                        <h2 class="text-base sm:text-xl font-extrabold text-gray-900 flex items-center gap-2">Barangay
                            Health Worker (BHW) Supervisory Registry</h2>
                        <?php if (empty($bhwList)): ?>
                            <div class="text-center py-12 bg-white rounded-2xl border border-gray-200/80 shadow-sm">
                                <span
                                    class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-400"><svg
                                        class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <circle cx="12" cy="12" r="10" />
                                        <path d="M12 8v4" />
                                        <path d="M12 16h.01" />
                                    </svg></span>
                                <p class="text-sm font-bold text-gray-700">No BHW Volunteers Registered</p>
                                <p class="text-xs text-gray-400 mt-0.5">Assigned Barangay Health Workers will be listed here.
                                </p>
                            </div>
                        <?php else: ?>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <?php foreach ($bhwList as $b): ?>
                                    <div
                                        class="dashboard-card bg-white rounded-2xl p-4 shadow-sm border border-gray-200/80 flex items-center justify-between gap-3 hover:border-emerald-200 transition-all">
                                        <div>
                                            <p class="font-extrabold text-gray-900 text-sm sm:text-base"><?= esc($b['name']); ?></p>
                                            <p class="text-xs text-gray-600 mt-0.5">Barangay: <strong
                                                    class="text-purple-900"><?= esc($b['barangay']); ?></strong></p>
                                            <p class="text-xs font-mono text-gray-500 mt-0.5">Contact:
                                                <?= esc($b['phone_number'] ?: 'Not recorded'); ?></p>
                                        </div>
                                        <span
                                            class="px-3 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800 border border-emerald-200 shrink-0">Active
                                            BHW</span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php if ($tab === 'certificates'): ?>
                    <?= portalRenderCertificateIssuancePanel($pdo, $allResidentsList, $nurseCertificateTypes, (int) ($_SESSION['rhu_staff_login']['staff_id'] ?? 0), 'emerald') ?>
                <?php endif; ?>

            </main>
        </div>
    </div>



    <!-- MOBILE BOTTOM TAB BAR -->
    <nav class="sm:hidden fixed bottom-0 left-0 right-0 z-50 bg-white border-t border-gray-200 safe-area-pb shadow-2xl">
        <div class="flex items-stretch">
            <?php foreach ($tabs as $id => [$label, $icon]): ?>
                <a href="<?= esc(tabUrl($id)); ?>"
                    class="flex-1 flex flex-col items-center justify-center gap-0.5 py-2 text-[10px] font-semibold transition-colors relative <?= $tab === $id ? 'text-emerald-700 font-extrabold' : 'text-gray-400'; ?>">
                    <?php if ($tab === $id): ?>
                        <span class="absolute top-0 left-1/2 -translate-x-1/2 w-6 h-0.5 bg-emerald-600 rounded-full"></span>
                    <?php endif; ?>
                    <span class="text-base leading-none"><?= $icon; ?></span>
                    <span class="truncate px-0.5"><?= esc($label); ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </nav>

    </div>

    <!-- MODAL: ADD / EDIT RESIDENT MEDICAL RECORD -->
    <?php if ($modal === 'new_patient_record'): ?>
        <div
            class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs flex items-end sm:items-center justify-center z-50 p-3 sm:p-4">
            <div
                class="bg-white rounded-3xl shadow-2xl w-full sm:max-w-2xl max-h-[92vh] flex flex-col overflow-hidden border border-slate-100">
                <div
                    class="p-5 border-b flex items-center justify-between bg-white-700 text-black rounded-t-3xl shrink-0">
                    <div>
                        <h2 class="text-base font-extrabold flex items-center gap-2">Save Resident Medical Record &amp;
                            Health Profile</h2>
                        <p class="text-xs text-black-500">Select a resident to auto-fill their info. Medical history and
                            vitals can be edited.</p>
                    </div>
                    <a href="<?= esc(tabUrl('patients')); ?>"
                        class="text-black-100 hover:text-black800 text-lg font-bold w-8 h-8 rounded-full bg-white/10 flex items-center justify-center">×</a>
                </div>
                <form class="resident-record-form p-5 space-y-4 text-xs overflow-y-auto" method="post">
                    <input type="hidden" name="action" value="save_patient_record">

                    <div class="bg-blue-50/60 p-4 rounded-2xl border border-blue-100 space-y-3">
                        <p class="font-extrabold text-blue-950 text-xs">Resident Identity Selection</p>
                        <div>
                            <label class="block font-bold text-gray-700 mb-1">Select Existing Resident (or leave blank to
                                create new) *</label>
                            <select name="resident_id" id="nurse_resident_select"
                                onchange="onNurseResidentSelectChange(this)"
                                class="w-full p-3 border border-gray-300 rounded-xl text-sm font-semibold focus:border-blue-500 focus:ring-2 focus:ring-blue-200 outline-none">
                                <option value="0">-- Create New Resident Record --</option>
                                <?php foreach ($allResidentsList as $r): ?>
                                    <option value="<?= esc($r['id']); ?>" <?= $selectedResidentId === (int) $r['id'] ? 'selected' : '' ?>><?= esc($r['name']); ?> (<?= esc($r['barangay']); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div id="nurse_new_resident_fields" class="grid grid-cols-2 gap-3 pt-1">
                            <div>
                                <label class="block font-bold text-gray-700 mb-1">First Name</label>
                                <input name="first_name" id="nurse_first_name" placeholder="First Name"
                                    value="<?= esc($selectedResidentData['first_name'] ?? '') ?>"
                                    class="w-full p-2.5 border border-gray-300 rounded-xl text-xs font-semibold">
                            </div>
                            <div>
                                <label class="block font-bold text-gray-700 mb-1">Last Name</label>
                                <input name="last_name" id="nurse_last_name" placeholder="Last Name"
                                    value="<?= esc($selectedResidentData['last_name'] ?? '') ?>"
                                    class="w-full p-2.5 border border-gray-300 rounded-xl text-xs font-semibold">
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-2.5">
                        <div>
                    
                                <span>Blood Type</span>
                                <span class="text-[10px] text-gray-400 font-normal">Locked</span>
                            </label>
                            <select name="blood_type" id="nurse_blood_type"
                                class="w-full p-2.5 border border-gray-300 rounded-xl text-xs font-bold">
                                <?php foreach (['O+', 'O-', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-'] as $bt): ?>
                                    <option value="<?= $bt ?>" <?= ($selectedResidentData['bloodType'] ?? 'O+') === $bt ? 'selected' : '' ?>><?= $bt ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            
                                <span>PhilHealth ID</span>
                                <span class="text-[10px] text-gray-400 font-normal">Locked</span>
                            </label>
                            <input name="philhealth_id" id="nurse_philhealth_id"
                                value="<?= esc($selectedResidentData['philhealthNo'] ?? '') ?>"
                                placeholder="PH-12-345678901-2"
                                class="w-full p-2.5 border border-gray-300 rounded-xl text-xs font-mono">
                        </div>
                        <div>
                                <span>Contact Phone</span>
                                <span class="text-[10px] text-gray-400 font-normal">Locked</span>
                            </label>
                            <input name="contact_number" id="nurse_contact_number"
                                value="<?= esc($selectedResidentData['contactNo'] ?? '') ?>" placeholder="0917 123 4567"
                                class="w-full p-2.5 border border-gray-300 rounded-xl text-xs font-mono">
                        </div>
                    </div>

                    <div class="bg-slate-50 p-4 rounded-2xl border border-slate-200 space-y-3">
                        <div class="flex items-center justify-between">
                            <p class="font-extrabold text-slate-900 text-xs">Physical Vitals Profile</p>
                            <span
                                class="text-[10px] font-bold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded border border-emerald-200">Editable
                                by Nurse</span>
                        </div>
                        <div class="grid grid-cols-3 gap-2.5">
                            <div>
                                <label class="block font-bold text-gray-700 mb-1">Height (cm)</label>
                                <input name="height" id="nurse_height"
                                    value="<?= esc($selectedResidentData['height'] ?? '') ?>" placeholder="e.g. 165"
                                    class="w-full p-2.5 border border-gray-300 rounded-xl text-xs font-bold text-gray-800">
                            </div>
                            <div>
                                <label class="block font-bold text-gray-700 mb-1">Weight (kg)</label>
                                <input name="weight" id="nurse_weight"
                                    value="<?= esc($selectedResidentData['weight'] ?? '') ?>" placeholder="e.g. 62"
                                    class="w-full p-2.5 border border-gray-300 rounded-xl text-xs font-bold text-gray-800">
                            </div>
                            <div>
                                <label class="block font-bold text-gray-700 mb-1">Baseline Blood Pressure</label>
                                <input name="blood_pressure" id="nurse_blood_pressure"
                                    value="<?= esc($selectedResidentData['bloodPressure'] ?? '') ?>" placeholder="120/80"
                                    class="w-full p-2.5 border border-gray-300 rounded-xl text-xs font-mono font-bold text-gray-800">
                            </div>
                        </div>
                    </div>

                    <div>
                        
                            <span>Known Drug & Food Allergies *</span>
                            <span
                                class="text-[10px] font-bold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded border border-emerald-200">Editable
                                by Nurse</span>
                        </label>
                        <textarea name="allergies" id="nurse_allergies" rows="2"
                            class="w-full p-2.5 border border-gray-300 rounded-xl text-xs resize-none focus:border-blue-500 outline-none"
                            placeholder="e.g. Penicillin, Sulfur, Seafood, Latex (Write 'None' if clear)"><?= esc($selectedResidentData['allergies'] ?? '') ?></textarea>
                    </div>

                    <div>
                        
                            <span>Pre-existing Medical History & Chronic Conditions *</span>
                            <span
                                class="text-[10px] font-bold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded border border-emerald-200">Editable
                                by Nurse</span>
                        </label>
                        <textarea name="medical_history" id="nurse_medical_history" rows="2"
                            class="w-full p-2.5 border border-gray-300 rounded-xl text-xs resize-none focus:border-blue-500 outline-none"
                            placeholder="e.g. Essential Hypertension, Type 2 Diabetes Mellitus, Asthma, Previous Appendectomy (2021)"><?= esc($selectedResidentData['medicalHistory'] ?? '') ?></textarea>
                    </div>

                    <div class="flex gap-3 pt-2">
                        <a href="<?= esc(tabUrl('patients')); ?>"
                            class="flex-1 py-3 border border-gray-300 rounded-xl text-xs font-bold text-gray-700 hover:bg-gray-50 text-center">Cancel</a>
                        <button type="submit"
                            class="flex-1 py-3 bg-teal-700 text-white rounded-xl text-xs font-extrabold hover:bg-teal-800 shadow-md transition-all">Save
                            Complete Resident Medical Record</button>
                    </div>
                </form>
            </div>
        </div>

        <script>
            const residentProfilesMap = <?= json_encode($patientProfilesMap, JSON_UNESCAPED_UNICODE) ?> || {};

            function onNurseResidentSelectChange(target) {
                let selectEl = target;
                if (typeof target === 'string') {
                    selectEl = document.getElementById(target);
                }
                if (!selectEl) return;

                const rid = parseInt(selectEl.value || 0, 10);
                const profile = residentProfilesMap[rid];

                const fnInput = document.getElementById('nurse_first_name');
                const lnInput = document.getElementById('nurse_last_name');
                const btSelect = document.getElementById('nurse_blood_type');
                const phiInput = document.getElementById('nurse_philhealth_id');
                const cnInput = document.getElementById('nurse_contact_number');

                const htInput = document.getElementById('nurse_height');
                const wtInput = document.getElementById('nurse_weight');
                const bpInput = document.getElementById('nurse_blood_pressure');
                const algInput = document.getElementById('nurse_allergies');
                const mhInput = document.getElementById('nurse_medical_history');

                const lockedNotice = document.getElementById('nurse_locked_fields_notice');

                if (profile && rid > 0) {
                    if (fnInput) { fnInput.value = profile.first_name || ''; fnInput.readOnly = true; fnInput.classList.add('bg-slate-100', 'cursor-not-allowed'); }
                    if (lnInput) { lnInput.value = profile.last_name || ''; lnInput.readOnly = true; lnInput.classList.add('bg-slate-100', 'cursor-not-allowed'); }

                    if (btSelect) { btSelect.value = profile.bloodType || 'O+'; btSelect.disabled = true; btSelect.classList.add('bg-slate-100', 'cursor-not-allowed'); }
                    if (phiInput) { phiInput.value = profile.philhealthNo || ''; phiInput.readOnly = true; phiInput.classList.add('bg-slate-100', 'cursor-not-allowed'); }
                    if (cnInput) { cnInput.value = profile.contactNo || ''; cnInput.readOnly = true; cnInput.classList.add('bg-slate-100', 'cursor-not-allowed'); }

                    if (htInput) htInput.value = profile.height || '';
                    if (wtInput) wtInput.value = profile.weight || '';
                    if (bpInput) bpInput.value = profile.bloodPressure || '';
                    if (algInput) algInput.value = profile.allergies || '';
                    if (mhInput) mhInput.value = profile.medicalHistory || '';

                    if (lockedNotice) lockedNotice.classList.remove('hidden');
                } else {
                    if (fnInput) { fnInput.value = ''; fnInput.readOnly = false; fnInput.classList.remove('bg-slate-100', 'cursor-not-allowed'); }
                    if (lnInput) { lnInput.value = ''; lnInput.readOnly = false; lnInput.classList.remove('bg-slate-100', 'cursor-not-allowed'); }

                    if (btSelect) { btSelect.value = 'O+'; btSelect.disabled = false; btSelect.classList.remove('bg-slate-100', 'cursor-not-allowed'); }
                    if (phiInput) { phiInput.value = ''; phiInput.readOnly = false; phiInput.classList.remove('bg-slate-100', 'cursor-not-allowed'); }
                    if (cnInput) { cnInput.value = ''; cnInput.readOnly = false; cnInput.classList.remove('bg-slate-100', 'cursor-not-allowed'); }

                    if (htInput) htInput.value = '';
                    if (wtInput) wtInput.value = '';
                    if (bpInput) bpInput.value = '';
                    if (algInput) algInput.value = '';
                    if (mhInput) mhInput.value = '';

                    if (lockedNotice) lockedNotice.classList.add('hidden');
                }
            }

            // Execute auto-fill immediately if a resident is selected or on page load
            (function () {
                const selectEl = document.getElementById('nurse_resident_select');
                if (selectEl) {
                    onNurseResidentSelectChange(selectEl);
                }
            })();
        </script>
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

        (function () {
            document.querySelectorAll('[data-triage-workbench]').forEach((workbench) => {
                const tabs = Array.from(workbench.querySelectorAll('[data-triage-tab]'));
                const panels = Array.from(workbench.querySelectorAll('.triage-detail-panel'));
                const search = workbench.querySelector('[data-triage-search]');
                const empty = workbench.querySelector('[data-triage-empty]');

                const activatePanel = (targetId) => {
                    tabs.forEach((tab) => {
                        const active = tab.dataset.target === targetId;
                        tab.classList.toggle('is-active', active);
                        tab.setAttribute('aria-selected', String(active));
                    });
                    panels.forEach((panel) => panel.classList.toggle('is-active', panel.id === targetId));
                };

                tabs.forEach((tab) => {
                    tab.addEventListener('click', () => activatePanel(tab.dataset.target));
                });

                search?.addEventListener('input', () => {
                    const term = search.value.trim().toLowerCase();
                    let firstVisible = null;
                    let visibleCount = 0;

                    tabs.forEach((tab) => {
                        const matches = !term || (tab.dataset.search || '').includes(term);
                        tab.classList.toggle('hidden', !matches);
                        if (matches) {
                            visibleCount += 1;
                            firstVisible = firstVisible || tab;
                        }
                    });

                    empty?.classList.toggle('is-visible', visibleCount === 0);
                    if (firstVisible && !firstVisible.classList.contains('is-active')) {
                        activatePanel(firstVisible.dataset.target);
                    } else if (!firstVisible) {
                        tabs.forEach((tab) => {
                            tab.classList.remove('is-active');
                            tab.setAttribute('aria-selected', 'false');
                        });
                        panels.forEach((panel) => panel.classList.remove('is-active'));
                    }
                });

                workbench.querySelectorAll('[data-consult-date-select]').forEach((select) => {
                    select.addEventListener('change', () => {
                        const panel = select.closest('.triage-detail-panel');
                        if (!panel) return;
                        panel.querySelectorAll('[data-consult-date-record]').forEach((record) => {
                            record.classList.toggle('hidden', record.id !== select.value);
                        });
                    });
                });
            });
        })();

        (function () {
            const diagnosisInputs = Array.from(document.querySelectorAll('[data-icd-diagnosis-input]'));
            if (!diagnosisInputs.length) return;

            const apiBase = 'https://clinicaltables.nlm.nih.gov/api/icd10cm/v3/search';
            const debounceTimers = new WeakMap();
            const controllers = new WeakMap();

            const closeSuggestions = (box) => {
                if (!box) return;
                box.classList.remove('is-open');
                box.innerHTML = '';
            };

            const getSuggestionBox = (input) => input.closest('.icd-lookup-wrap')?.querySelector('[data-icd-suggestions]');
            const getCodeInput = (input) => document.getElementById(input.dataset.icdTarget || '');
            const normalizeDiagnosisTerm = (term) => {
                const clean = term.trim();
                return clean.toLowerCase() === 'flue' ? 'flu influenza' : clean;
            };

            const renderSuggestions = (input, results) => {
                const box = getSuggestionBox(input);
                const codeInput = getCodeInput(input);
                if (!box || !codeInput) return;

                box.innerHTML = '';
                if (!results.length) {
                    closeSuggestions(box);
                    return;
                }

                results.forEach(({ code, label }) => {
                    const option = document.createElement('button');
                    option.type = 'button';
                    option.className = 'icd-suggestion';
                    const codeEl = document.createElement('strong');
                    const labelEl = document.createElement('span');
                    codeEl.textContent = code;
                    labelEl.textContent = label;
                    option.append(codeEl, labelEl);
                    option.addEventListener('click', () => {
                        input.value = label;
                        codeInput.value = code;
                        closeSuggestions(box);
                    });
                    box.appendChild(option);
                });
                box.classList.add('is-open');
            };

            const lookupIcd10 = async (input) => {
                const term = input.value.trim();
                const lookupTerm = normalizeDiagnosisTerm(term);
                const box = getSuggestionBox(input);
                if (term.length < 3) {
                    closeSuggestions(box);
                    return;
                }

                controllers.get(input)?.abort();
                const controller = new AbortController();
                controllers.set(input, controller);

                try {
                    const url = `${apiBase}?sf=code,name&df=code,name&maxList=8&terms=${encodeURIComponent(lookupTerm)}`;
                    const response = await fetch(url, { signal: controller.signal });
                    if (!response.ok) throw new Error(`ICD API returned ${response.status}`);
                    const [, codes = [], , details = []] = await response.json();
                    const results = details.map((item, index) => ({
                        code: item?.[0] || codes[index] || '',
                        label: item?.[1] || ''
                    })).filter((item) => item.code && item.label);
                    renderSuggestions(input, results);
                } catch (error) {
                    if (error.name !== 'AbortError') {
                        console.error('Error communicating with ICD-10 terminology API:', error);
                    }
                }
            };

            diagnosisInputs.forEach((input) => {
                input.setAttribute('autocomplete', 'off');
                input.addEventListener('input', () => {
                    clearTimeout(debounceTimers.get(input));
                    debounceTimers.set(input, setTimeout(() => lookupIcd10(input), 300));
                });
                input.addEventListener('blur', () => {
                    setTimeout(() => closeSuggestions(getSuggestionBox(input)), 180);
                });
            });
        })();
    </script>
</body>

</html>
