<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/portal.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/admin_extended.php';
portalHandleCertificateSignatureApproval($pdo);
if (isset($_GET['export']) && $_GET['export'] === 'sql_backup' && !empty($pdo)) {
    adminDownloadSqlBackup($pdo);
}
if (empty($_SESSION['rhu_admin_authenticated']) && empty($_SESSION['rhu_staff_login'])) {
    header('Location: RHULogin.php');
    exit;
}
if (empty($_SESSION['user']) && !empty($_SESSION['rhu_staff_login'])) {
    $_SESSION['user'] = [
        'user_id' => (int)($_SESSION['rhu_staff_login']['user_id'] ?? $_SESSION['rhu_staff_login']['id'] ?? 0),
        'id' => (int)($_SESSION['rhu_staff_login']['id'] ?? 0),
        'email' => $_SESSION['rhu_staff_login']['email'] ?? '',
        'username' => $_SESSION['rhu_staff_login']['email'] ?? 'rhu_staff',
        'first_name' => strtok($_SESSION['rhu_staff_login']['name'] ?? 'RHU Staff', ' ') ?: 'RHU',
        'last_name' => trim(substr($_SESSION['rhu_staff_login']['name'] ?? 'Staff', strlen(strtok($_SESSION['rhu_staff_login']['name'] ?? 'RHU Staff', ' ') ?: 'RHU'))),
        'admin_code' => $_SESSION['rhu_staff_login']['staff_type'] ?? 'RHU-STAFF',
        'designation' => $_SESSION['rhu_staff_login']['position'] ?? 'RHU Healthcare Staff',
    ];
}
portalHandleNotificationApi($pdo);
ensurePortalTables($pdo);

if (($_GET['api'] ?? '') === 'admin_messages') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (function_exists('rhuTableExists') && rhuTableExists($pdo, 'messages')) {
            $mStmt = $pdo->query("
                SELECT m.id, m.resident_id, r.first_name, r.last_name, r.email, m.subject, m.message, m.admin_reply, m.status, m.created_at
                FROM messages m
                LEFT JOIN residents r ON m.resident_id = r.id
                ORDER BY m.created_at DESC LIMIT 50
            ");
        } else {
            $mStmt = $pdo->query("
                SELECT a.id, a.record_id AS resident_id, r.first_name, r.last_name, r.email,
                       COALESCE(NULLIF(TRIM(a.action), ''), 'Resident Inquiry') AS subject,
                       a.description AS message, '' AS admin_reply, 'Pending' AS status, a.created_at
                FROM audit_logs a
                INNER JOIN residents r ON a.record_id = r.id
                WHERE a.module_name = 'Resident Portal' AND a.action = 'Resident Message'
                ORDER BY a.created_at DESC LIMIT 50
            ");
        }
        $messages = $mStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        echo json_encode(['success' => true, 'messages' => $messages, 'count' => count($messages)]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage(), 'messages' => [], 'count' => 0]);
    }
    exit;
}

if (isset($_GET['logout'])) {
    portalAudit($pdo, (int)($_SESSION['user']['user_id'] ?? 0), 'RHU Admin Logout', 'users', (int)($_SESSION['user']['user_id'] ?? 0));
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    header('Location: RHUAdminLogin.php');
    exit;
}

if (($_GET['export'] ?? '') === 'summary' && $pdo) {
    portalAudit($pdo, (int)$_SESSION['user']['user_id'], 'Exported dashboard summary report', 'reports', null);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="rhu-summary-' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'wb');
    fputcsv($output, ['Metric', 'Count']);
    foreach (
        [
            'Users' => 'health_workers',
            'Active Staff' => 'health_workers WHERE status = \'Active\'',
            'Residents' => 'residents',
            'Consultations' => 'consultations',
            'Pregnancies' => 'pregnancy_records',
            'Vaccinations' => 'vaccination_records',
            'Disease Cases' => 'disease_cases',
            'Medicine Items' => 'medicine_inventory',
        ] as $label => $source
    ) {
        fputcsv($output, [$label, (int)$pdo->query("SELECT COUNT(*) FROM {$source}")->fetchColumn()]);
    }
    fclose($output);
    exit;
}

if (($_GET['backup'] ?? '') === 'sql' && $pdo) {
    portalAudit($pdo, (int)$_SESSION['user']['user_id'], 'Downloaded SQL database backup', 'system', null);
    adminDownloadSqlBackup($pdo);
}

if (isset($_GET['certificate_pdf']) && ctype_digit((string)$_GET['certificate_pdf']) && $pdo) {
    portalAudit($pdo, (int)$_SESSION['user']['user_id'], 'Downloaded certificate PDF', 'certificates', (int)$_GET['certificate_pdf']);
    adminDownloadCertificatePdf($pdo, (int)$_GET['certificate_pdf']);
}

/**
 * Escape output for HTML.
 * @param mixed $value
 */
function esc(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function resolveCurrentAdminEmail(PDO $pdo): string
{
    $email = trim((string)($_SESSION['user']['email'] ?? $_SESSION['rhu_admin_email'] ?? ''));
    if ($email !== '') {
        return $email;
    }

    $userId = (int)($_SESSION['user']['user_id'] ?? $_SESSION['user']['id'] ?? 0);
    if ($userId > 0) {
        $stmt = $pdo->prepare("SELECT email FROM health_workers WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $userId]);
        $email = trim((string)$stmt->fetchColumn());
        if ($email !== '') {
            return $email;
        }
    }

    $username = trim((string)($_SESSION['user']['username'] ?? ''));
    if ($username !== '') {
        $stmt = $pdo->prepare("SELECT email FROM health_workers WHERE username = :username OR email = :email LIMIT 1");
        $stmt->execute(['username' => $username, 'email' => $username]);
        $email = trim((string)$stmt->fetchColumn());
        if ($email !== '') {
            return $email;
        }
    }

    $fallback = $pdo->query("SELECT email FROM health_workers WHERE status = 'Active' AND (UPPER(position_title) IN ('RHU_ADMIN','SUPER_ADMIN','ADMIN_STAFF') OR UPPER(position_title) LIKE '%MUNICIPAL HEALTH OFFICER%' OR UPPER(role) = 'ADMIN') ORDER BY id ASC LIMIT 1")->fetchColumn();
    $email = trim((string)($fallback ?: ''));
    if ($email !== '') {
        $_SESSION['rhu_admin_email'] = $email;
        return $email;
    }

    return '';
}

function iconSvg(string $name, string $class = 'w-5 h-5'): string
{
    $icons = [
        'shield' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
        'users' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        'settings' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06A1.65 1.65 0 0 0 15 19.4a1.65 1.65 0 0 0-1 .6V20a2 2 0 0 1-4 0v-.1a1.65 1.65 0 0 0-1-.6 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-.6-1H4a2 2 0 0 1 0-4h.1a1.65 1.65 0 0 0 .6-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-.6V4a2 2 0 0 1 4 0v.1a1.65 1.65 0 0 0 1 .6 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9c.24.31.4.68.6 1H20a2 2 0 0 1 0 4h-.1a1.65 1.65 0 0 0-.5 1z"/></svg>',
        'bar' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/></svg>',
        'logout' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/><path d="M13 21H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7"/></svg>',
        'bell' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 1 0-12 0v3.2a2 2 0 0 1-.6 1.4L4 17h5"/><path d="M9 17a3 3 0 0 0 6 0"/></svg>',
        'home' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M9 20v-6h6v6"/></svg>',
        'activity' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>',
        'file' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>',
        'database' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/></svg>',
        'lock' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
        'check' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>',
        'clock' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>',
        'trend' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>',
        'right' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M13 5l7 7-7 7"/></svg>',
        'eye' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>',
        'edit' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>',
        'trash' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>',
        'plus' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="M5 12h14"/></svg>',
        'download' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
        'refresh' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>',
        'server' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="8" rx="2"/><rect x="2" y="13" width="20" height="8" rx="2"/><line x1="6" y1="7" x2="6.01" y2="7"/><line x1="6" y1="17" x2="6.01" y2="17"/></svg>',
        'stethoscope' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3v7a6 6 0 0 0 12 0V3"/><path d="M6 10a3 3 0 0 0 6 0"/><path d="M12 20a4 4 0 0 0 8 0v-2"/></svg>',
        'building' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M9 22V12h6v10"/><path d="M9 8h.01"/><path d="M13 8h.01"/><path d="M9 12h.01"/><path d="M13 12h.01"/></svg>',
        'mail' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>',
        'phone' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.8 19.8 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.18 2 2 0 0 1 4.09 2h3a2 2 0 0 1 2 1.72c.12.9.33 1.77.6 2.6a2 2 0 0 1-.45 2.11L8 9a16 16 0 0 0 7 7l.57-1.24a2 2 0 0 1 2.11-.45c.83.27 1.7.48 2.6.6A2 2 0 0 1 22 16.92z"/></svg>',
        'key' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="15" r="4"/><path d="M10.5 12.5L21 2"/><path d="M17 5l2 2"/></svg>',
        'menu' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>',
        'close' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>',
        'baby' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12h.01M15 12h.01"/><path d="M10 16c.5.35 1.17.5 2 .5s1.5-.15 2-.5"/><path d="M12 3a9 9 0 1 0 9 9c0-2.5-1-4.7-2.7-6.3"/><path d="M12 3c2 0 3 1 3 2.5S14 8 12 8"/></svg>',
        'alert' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.3 2.9 1.8 17a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 2.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17h.01"/></svg>',
        'pill' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m10.5 20.5 10-10a5 5 0 0 0-7-7l-10 10a5 5 0 0 0 7 7Z"/><path d="m8.5 8.5 7 7"/></svg>',
    ];

    return $icons[$name] ?? '';
}

function renderAdminFloatingToolsModal(PDO $pdo, string $modalId, string $title, string $description, string $panelTab, string $maxWidth = 'max-w-6xl'): void
{
?>
    <div data-floating-modal="<?php echo esc($modalId); ?>" class="fixed inset-0 z-[90] hidden bg-slate-950/45 p-3 backdrop-blur-sm sm:p-6">
        <div class="mx-auto mt-8 max-h-[86vh] w-full <?php echo esc($maxWidth); ?> overflow-y-auto rounded-2xl border border-white/70 bg-white p-4 shadow-2xl sm:p-5">
            <div class="mb-4 flex items-center justify-between gap-3 border-b border-gray-100 pb-3">
                <div>
                    <h3 class="text-base font-black text-gray-900"><?php echo esc($title); ?></h3>
                    <p class="text-xs text-gray-500"><?php echo esc($description); ?></p>
                </div>
                <button type="button" data-floating-modal-close class="grid h-9 w-9 place-items-center rounded-full border border-gray-200 bg-gray-50 text-lg font-black text-gray-600 hover:bg-gray-100" aria-label="Close <?php echo esc($title); ?>">&times;</button>
            </div>
            <?php renderAdminExtendedPanel($pdo, $panelTab); ?>
        </div>
    </div>
<?php
}

function certificateStaffMatchGroups(string $certificateType): array
{
    $name = strtolower($certificateType);
    $groups = ['physician'];
    if (str_contains($name, 'birth') || str_contains($name, 'maternal') || str_contains($name, 'prenatal') || str_contains($name, 'family planning')) {
        $groups = ['midwife', 'nurse', 'physician'];
    } elseif (str_contains($name, 'immunization') || str_contains($name, 'vaccine') || str_contains($name, 'nursing') || str_contains($name, 'vital signs')) {
        $groups = ['nurse', 'physician'];
    } elseif (str_contains($name, 'laboratory') || str_contains($name, 'diagnostic') || str_contains($name, 'specimen')) {
        $groups = ['medtech', 'physician'];
    } elseif (str_contains($name, 'sanitary') || str_contains($name, 'sanitation') || str_contains($name, 'clearance')) {
        $groups = ['sanitary', 'physician'];
    } elseif (str_contains($name, 'barangay') || str_contains($name, 'community')) {
        $groups = ['bhw', 'nurse', 'physician'];
    }
    return $groups;
}

function certificateStaffMatchesType(string $certificateType, string $staffType, string $specialization = ''): bool
{
    $haystack = strtolower($staffType . ' ' . $specialization);
    foreach (certificateStaffMatchGroups($certificateType) as $group) {
        if ($group === 'physician' && (str_contains($haystack, 'physician') || str_contains($haystack, 'doctor'))) return true;
        if ($group === 'nurse' && str_contains($haystack, 'nurse')) return true;
        if ($group === 'midwife' && str_contains($haystack, 'midwife')) return true;
        if ($group === 'medtech' && (str_contains($haystack, 'medtech') || str_contains($haystack, 'medical technolog') || str_contains($haystack, 'laboratory'))) return true;
        if ($group === 'sanitary' && (str_contains($haystack, 'sanitary') || str_contains($haystack, 'sanitation') || str_contains($haystack, 'inspector'))) return true;
        if ($group === 'bhw' && (str_contains($haystack, 'bhw') || str_contains($haystack, 'barangay health'))) return true;
    }
    return false;
}

$tab = $_GET['tab'] ?? $_POST['tab'] ?? 'overview';
$showNotifs = isset($_GET['notifs']) && $_GET['notifs'] === '1';

// ----------------------------------------------------
// 1. DATABASE POST HANDLERS FOR ACTIONS
// ----------------------------------------------------
$flashSuccess = '';
$flashError = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !empty($pdo)) {
    if (!portalVerifyCsrf()) {
        http_response_code(419);
        exit('Invalid or expired form token. Refresh the page and try again.');
    }
    $action = $_POST['action'] ?? '';

    // Action: Update Facility Profile Info (System Tab)
    if ($action === 'update_rhu_info') {
        portalSaveSettings($pdo, [
            'rhu_name' => $_POST['rhu_name'] ?? '',
            'rhu_mho_name' => $_POST['mho_name'] ?? '',
            'rhu_municipality' => $_POST['municipality'] ?? '',
            'rhu_province' => $_POST['province'] ?? '',
            'rhu_contact_number' => $_POST['contact'] ?? '',
            'rhu_email' => $_POST['email'] ?? '',
        ]);
        portalAudit($pdo, $_SESSION['user']['user_id'] ?? null, "Updated RHU Facility Settings", 'system', 1);
        $flashSuccess = "RHU Facility information updated successfully!";
    }

    // Action: Change Admin Password (Security Tab)
    if ($action === 'change_admin_password') {
        $currentPass = $_POST['current_password'] ?? '';
        $newPass = $_POST['new_password'] ?? '';
        $confirmPass = $_POST['confirm_password'] ?? '';
        $adminUserId = (int)($_SESSION['user']['user_id'] ?? 0);

        if (!$currentPass || !$newPass || !$confirmPass) {
            $flashError = "Please complete all password fields.";
        } elseif ($newPass !== $confirmPass) {
            $flashError = "New password and confirmation do not match.";
        } elseif (strlen($newPass) < 8) {
            $flashError = "New password must be at least 8 characters long.";
        } else {
            $stmt = $pdo->prepare("SELECT password_hash FROM health_workers WHERE id = :id LIMIT 1");
            $stmt->execute(['id' => $adminUserId]);
            $hash = $stmt->fetchColumn();

            if ($hash && password_verify($currentPass, $hash)) {
                $newHash = password_hash($newPass, PASSWORD_BCRYPT);
                $upd = $pdo->prepare("UPDATE health_workers SET password_hash = :hash, updated_at = NOW() WHERE id = :id");
                $upd->execute(['hash' => $newHash, 'id' => $adminUserId]);
                portalAudit($pdo, $adminUserId, "Admin Password Changed Successfully", 'health_workers', $adminUserId);
                $flashSuccess = "Administrator password updated successfully in database!";
            } else {
                $flashError = "Incorrect current password. Please try again.";
            }
        }
    }

    // Action: Send Test Email (System Tab)
    if ($action === 'send_test_email') {
        $targetEmail = trim($_POST['test_email'] ?? 'chedricbascoguin27@gmail.com');
        $testCode = sprintf('%06d', mt_rand(100000, 999999));
        $subject = 'ResiHUnity RHU - System SMTP Integration Test';
        $html = "<h3>ResiHUnity RHU System Test</h3><p>This is a test 2FA email sent from RHU System Settings.</p><div class='code-box'>{$testCode}</div>";
        $res = sendRHUEmail($targetEmail, $subject, $html);
        if ($res['success']) {
            $flashSuccess = "Test email dispatched successfully to {$targetEmail}! (Method: {$res['method']})";
        } else {
            $flashError = "Failed to dispatch test email to {$targetEmail}. Check SMTP settings.";
        }
    }

    // Action: Admin generate certificate and request required e-signature approvals
    if ($action === 'issue_certificate') {
        try {
            $residentId = (int)($_POST['resident_id'] ?? 0);
            $certificateTypeId = (int)($_POST['certificate_type_id'] ?? 0);
            $assignedStaffId = (int)($_POST['assigned_staff_id'] ?? 0);
            $purpose = trim((string)($_POST['purpose'] ?? ''));
            $issueDate = trim((string)($_POST['issue_date'] ?? date('Y-m-d')));
            $expiryDate = trim((string)($_POST['expiry_date'] ?? ''));
            if ($residentId <= 0 || $certificateTypeId <= 0 || $assignedStaffId <= 0 || $purpose === '') {
                throw new InvalidArgumentException('Resident, certificate type, assigned doctor/staff, and purpose are required.');
            }
            $typeName = 'Medical Certificate';
            if (isset($adminCertificateTypes) && is_array($adminCertificateTypes)) {
                foreach ($adminCertificateTypes as $ct) {
                    if ((int)($ct['id'] ?? 0) === $certificateTypeId) {
                        $typeName = (string)($ct['certificate_type_name'] ?? $ct['certificate_type'] ?? 'Medical Certificate');
                        break;
                    }
                }
            }
            $staffCheck = $pdo->prepare("
                SELECT hw.id, hw.position_title AS staff_type, hw.role AS specialization, hw.email, CONCAT(hw.first_name, ' ', hw.last_name) AS name
                FROM health_workers hw
                WHERE hw.id = :staff
                  AND hw.status = 'Active'
                LIMIT 1
            ");
            $staffCheck->execute(['staff' => $assignedStaffId]);
            $selectedStaff = $staffCheck->fetch(PDO::FETCH_ASSOC);
            if (!$selectedStaff) {
                throw new RuntimeException('Selected doctor/staff must be an active health worker.');
            }

            $certNo = 'RHU-' . date('Ymd-His') . '-' . str_pad((string)$residentId, 4, '0', STR_PAD_LEFT) . '-' . random_int(10, 99);
            $insert = $pdo->prepare("INSERT INTO certificates (resident_id, certificate_type, certificate_number, issue_date, expiry_date, issued_by_id, purpose, status, created_at) VALUES (:resident, :type, :number, :issue_date, :expiry_date, :doctor, :purpose, 'Pending Doctor Approval', NOW())");
            $insert->execute([
                'resident' => $residentId,
                'type' => $typeName,
                'number' => $certNo,
                'issue_date' => $issueDate,
                'expiry_date' => $expiryDate !== '' ? $expiryDate : null,
                'purpose' => $purpose,
                'doctor' => $assignedStaffId,
            ]);
            $certificateId = (int)$pdo->lastInsertId();

            $adminSignaturePath = portalCertificateSignatureUploadPath($_FILES['admin_signature_image'] ?? []);
            if ($adminSignaturePath !== '') {
                $targetDir = __DIR__ . '/uploads/signatures';
                if (!is_dir($targetDir)) @mkdir($targetDir, 0775, true);
                @copy(__DIR__ . '/' . ltrim($adminSignaturePath, '/'), $targetDir . '/admin_' . $certificateId . '.png');
            }

            $adminEmail = resolveCurrentAdminEmail($pdo);
            if ($adminEmail === '') {
                throw new RuntimeException('No valid admin email found in the database for this account.');
            }

            $adminPerson = [
                'user_id' => (int)($_SESSION['user']['user_id'] ?? $_SESSION['user']['id'] ?? 0) ?: null,
                'email' => $adminEmail,
                'signature_path' => $adminSignaturePath,
            ];
            $doctorPerson = [
                'staff_id' => (int)$selectedStaff['id'],
                'user_id' => (int)$selectedStaff['id'],
                'email' => (string)($selectedStaff['email'] ?? ''),
            ];

            $approvalResults = portalSendCertificateApprovalRequests($pdo, $certificateId, $adminPerson, $doctorPerson);
            portalAudit($pdo, $_SESSION['user']['user_id'] ?? null, "Issued certificate {$certNo} and requested e-signature from {$selectedStaff['name']}", 'certificates', $certificateId);
            portalNotifyResident($pdo, $residentId, "{$typeName} {$certNo} was created by RHU admin and routed to {$selectedStaff['name']} for e-signature approval.", 'ResidentDashboard.php?tab=certificates');

            $flashSuccess = "{$typeName} {$certNo} was generated. E-signature approval link was emailed to {$selectedStaff['name']} ({$selectedStaff['email']}).";
        } catch (Throwable $e) {
            $flashError = 'Certificate Error: ' . $e->getMessage();
        }
    }

    // Action: Approve requested certificate
    if ($action === 'approve_certificate') {
        $certId = (int)($_POST['request_id'] ?? 0);
        if ($certId > 0) {
            try {
                $certStmt = $pdo->prepare("SELECT resident_id, certificate_type, purpose, issue_date, certificate_number, issued_by_id FROM certificates WHERE id = :id");
                $certStmt->execute(['id' => $certId]);
                $cert = $certStmt->fetch(PDO::FETCH_ASSOC);
                if (!$cert) throw new RuntimeException('Certificate request not found.');
                $certNo = $cert['certificate_number'] ?: ('CERT-' . date('Y') . '-' . str_pad((string)$certId, 4, '0', STR_PAD_LEFT));
                
                $doctorStaffId = (int)($cert['issued_by_id'] ?? 0);
                $docCheck = $pdo->prepare("SELECT id, email, CONCAT(first_name, ' ', last_name) AS name FROM health_workers WHERE id = :id AND status = 'Active' LIMIT 1");
                $docCheck->execute(['id' => $doctorStaffId]);
                $docRow = $docCheck->fetch(PDO::FETCH_ASSOC);
                if (!$docRow) {
                    $docCheck = $pdo->query("SELECT id, email, CONCAT(first_name, ' ', last_name) AS name FROM health_workers WHERE status = 'Active' ORDER BY id ASC LIMIT 1");
                    $docRow = $docCheck->fetch(PDO::FETCH_ASSOC);
                }

                $stmt = $pdo->prepare("UPDATE certificates SET status = 'Pending Doctor Approval', certificate_number = :cert_no, issue_date = COALESCE(issue_date, CURDATE()), updated_at = NOW() WHERE id = :id");
                $stmt->execute(['cert_no' => $certNo, 'id' => $certId]);

                if ($docRow) {
                    $adminEmail = resolveCurrentAdminEmail($pdo);
                    if ($adminEmail === '') {
                        throw new RuntimeException('No valid admin email found in the database for this account.');
                    }

                    $adminPerson = [
                        'user_id' => (int)($_SESSION['user']['user_id'] ?? $_SESSION['user']['id'] ?? 0) ?: null,
                        'email' => $adminEmail,
                    ];
                    $doctorPerson = [
                        'staff_id' => (int)$docRow['id'],
                        'user_id' => (int)$docRow['id'],
                        'email' => (string)($docRow['email'] ?? ''),
                    ];
                    portalSendCertificateApprovalRequests($pdo, $certId, $adminPerson, $doctorPerson);
                }

                portalAudit($pdo, $_SESSION['user']['user_id'] ?? null, "Approved Certificate Request #{$certId}", 'certificates', $certId);
                portalNotifyResident($pdo, (int)$cert['resident_id'], "Your certificate request #{$certId} was approved by Admin and routed for doctor e-signature approval.", 'ResidentDashboard.php?tab=certificates');
                $flashSuccess = "Certificate request #{$certId} was approved and routed to assigned doctor/staff for e-signature.";
            } catch (Throwable $e) {
                $flashError = 'Certificate Error: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'regenerate_certificate') {
        $certId = (int)($_POST['certificate_id'] ?? 0);
        if ($certId > 0) {
            try {
                $html = portalGenerateCertificateHtml($pdo, $certId);
                $stmt = $pdo->prepare("UPDATE certificates SET status = 'Draft' WHERE id = :id");
                $stmt->execute(['id' => $certId]);
                portalCertificateWorkflowLog($pdo, $certId, 'Certificate regenerated', 'Admin regenerated the certificate preview.');
                $flashSuccess = "Certificate #{$certId} was regenerated as Draft.";
            } catch (Throwable $e) {
                $flashError = 'Regenerate failed: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'send_final_certificate') {
        $certId = (int)($_POST['certificate_id'] ?? 0);
        if ($certId > 0) {
            try {
                $status = portalRefreshCertificateWorkflowStatus($pdo, $certId);
                if ($status !== 'Signed') throw new RuntimeException('Both administrator and doctor/staff must approve signature use before sending.');
                $stmt = $pdo->prepare("SELECT hc.certificate_number, r.email, CONCAT(r.first_name, ' ', r.last_name) AS resident_name FROM certificates hc JOIN residents r ON r.id = hc.resident_id WHERE hc.id = :id");
                $stmt->execute(['id' => $certId]);
                $cert = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$cert || empty($cert['email'])) throw new RuntimeException('Resident email is not available.');
                $downloadUrl = portalCertificateUrl(['certificate_pdf' => $certId]);
                $subject = 'Your RHU certificate is ready';
                $html = '<p>Your signed RHU certificate is approved and ready.</p><p><a href="' . esc($downloadUrl) . '">Download certificate</a></p>';
                $result = sendRHUEmail((string)$cert['email'], $subject, $html);
                portalRecordCertificateEmail($pdo, $certId, (string)$cert['email'], 'resident_signed_certificate', $subject, $result);
                $pdo->prepare("UPDATE certificates SET status = 'Approved & Issued' WHERE id = :id")->execute(['id' => $certId]);
                portalNotifyResident($pdo, (int)$pdo->query("SELECT resident_id FROM certificates WHERE id = {$certId}")->fetchColumn(), 'Your signed certificate is ready to download.', 'ResidentDashboard.php?tab=certificates');
                portalCertificateWorkflowLog($pdo, $certId, 'Certificate sent', 'Final signed certificate was emailed and released to the resident portal.');
                $flashSuccess = "Signed certificate was sent to the resident.";
            } catch (Throwable $e) {
                $flashError = 'Send failed: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'save_certificate_doctor_mapping') {
        $certificateTypeId = (int)($_POST['certificate_type_id'] ?? 0);
        $staffId = (int)($_POST['staff_id'] ?? 0);
        $purposeKeyword = trim((string)($_POST['purpose_keyword'] ?? ''));
        if ($certificateTypeId > 0 && $staffId > 0) {
            try {
                $stmt = $pdo->prepare("INSERT INTO certificate_doctor_assignments (certificate_type_id, purpose_keyword, staff_id, is_active) VALUES (:type, :keyword, :staff, 1) ON DUPLICATE KEY UPDATE is_active = 1");
                $stmt->execute(['type' => $certificateTypeId, 'keyword' => $purposeKeyword, 'staff' => $staffId]);
                portalAudit($pdo, $_SESSION['user']['user_id'] ?? null, 'Updated certificate doctor assignment', 'certificate_doctor_assignments', $certificateTypeId);
                $flashSuccess = 'Certificate doctor/staff assignment saved.';
            } catch (Throwable $e) {
                $flashError = 'Mapping failed: ' . $e->getMessage();
            }
        } else {
            $flashError = 'Select a certificate type and doctor/staff.';
        }
    }

    if ($action === 'delete_certificate_doctor_mapping') {
        $mappingId = (int)($_POST['mapping_id'] ?? 0);
        if ($mappingId > 0) {
            $pdo->prepare("UPDATE certificate_doctor_assignments SET is_active = 0 WHERE id = :id")->execute(['id' => $mappingId]);
            portalAudit($pdo, $_SESSION['user']['user_id'] ?? null, 'Removed certificate doctor assignment', 'certificate_doctor_assignments', $mappingId);
            $flashSuccess = 'Certificate doctor/staff assignment removed.';
        }
    }

    if ($action === 'delete_selected_certificates') {
        $certificateIds = array_values(array_unique(array_filter(array_map('intval', $_POST['certificate_ids'] ?? []))));
        if ($certificateIds) {
            $placeholders = implode(',', array_fill(0, count($certificateIds), '?'));
            $pdo->prepare("DELETE FROM certificates WHERE id IN ({$placeholders})")->execute($certificateIds);
            portalAudit($pdo, $_SESSION['user']['user_id'] ?? null, 'Deleted selected certificate requests', 'certificates', null);
            $flashSuccess = count($certificateIds) . ' certificate request(s) deleted.';
        } else {
            $flashError = 'Select at least one certificate request to delete.';
        }
    }

    // Action: Reject Certificate Request
    if ($action === 'reject_certificate') {
        $certId = (int)($_POST['request_id'] ?? 0);
        $reason = trim($_POST['rejection_reason'] ?? 'Requirements incomplete');
        if ($certId > 0) {
            $residentId = (int)$pdo->query("SELECT resident_id FROM certificates WHERE id = {$certId}")->fetchColumn();
            $stmt = $pdo->prepare("UPDATE certificates SET status = 'Rejected' WHERE id = :id");
            $stmt->execute(['id' => $certId]);
            portalAudit($pdo, $_SESSION['user']['user_id'] ?? null, "Rejected Certificate Request #{$certId}", 'certificates', $certId);
            portalNotifyResident($pdo, $residentId, 'Your certificate request was rejected: ' . $reason, 'ResidentDashboard.php?tab=certificates');
            $flashSuccess = "Certificate request #{$certId} rejected.";
        }
    }

    // Action: Reply to Resident Message
    if ($action === 'reply_message') {
        $msgId = (int)($_POST['message_id'] ?? 0);
        $reply = trim($_POST['reply'] ?? '');
        if ($msgId > 0 && $reply !== '') {
            $messageRow = null;
            if (function_exists('rhuTableExists') && rhuTableExists($pdo, 'messages')) {
                $messageStmt = $pdo->prepare("SELECT id, resident_id, subject, message, admin_reply FROM messages WHERE id = :id LIMIT 1");
                $messageStmt->execute(['id' => $msgId]);
                $messageRow = $messageStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }

            if (!$messageRow) {
                $messageStmt = $pdo->prepare("SELECT record_id AS resident_id, action AS subject FROM audit_logs WHERE id = :id LIMIT 1");
                $messageStmt->execute(['id' => $msgId]);
                $messageRow = $messageStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            }

            $residentId = (int)($messageRow['resident_id'] ?? 0);
            $subject = trim((string)($messageRow['subject'] ?? 'your message')) ?: 'your message';

            if (function_exists('rhuTableExists') && rhuTableExists($pdo, 'messages')) {
                $stmt = $pdo->prepare("UPDATE messages SET admin_reply = :reply, status = 'Replied', replied_at = NOW() WHERE id = :id");
            } else {
                $stmt = $pdo->prepare("UPDATE audit_logs SET description = CONCAT(description, ' [Replied: ', :reply, ']') WHERE id = :id");
            }
            $stmt->execute(['reply' => $reply, 'id' => $msgId]);
            portalAudit($pdo, $_SESSION['user']['user_id'] ?? null, "Replied to Resident Message #{$msgId}", 'messages', $msgId);
            if ($residentId > 0 && $stmt->rowCount() > 0) {
                portalNotifyResident($pdo, $residentId, "RHU admin replied to {$subject}: {$reply}", 'ResidentDashboard.php?tab=contact');
            }
            $flashSuccess = "Reply sent to resident message #{$msgId}.";
        }
    }

    // Action: Delete selected resident messages
    if ($action === 'delete_selected_messages') {
        $messageIds = array_values(array_unique(array_filter(array_map('intval', $_POST['message_ids'] ?? []))));
        if ($messageIds) {
            $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
            $stmt = $pdo->prepare("DELETE FROM audit_logs WHERE id IN ({$placeholders})");
            $stmt->execute($messageIds);
            portalAudit($pdo, $_SESSION['user']['user_id'] ?? null, 'Deleted selected resident messages', 'audit_logs', null);
            $flashSuccess = count($messageIds) . " resident message(s) deleted.";
        } else {
            $flashError = 'Select at least one message to delete.';
        }
    }

    // Action: Clean all resident messages
    if ($action === 'delete_all_messages') {
        portalAudit($pdo, $_SESSION['user']['user_id'] ?? null, 'Blocked bulk resident message cleanup', 'audit_logs', null);
        $flashError = 'Bulk message cleanup is disabled. Use Select Delete to remove chosen messages only.';
    }

    // Action: Confirm Event Registration
    if ($action === 'confirm_event') {
        $eventId = (int)($_POST['event_id'] ?? 0);
        if ($eventId > 0) {
            $stmt = $pdo->prepare("UPDATE events SET status = 'Confirmed' WHERE id = :id");
            $stmt->execute(['id' => $eventId]);
            portalAudit($pdo, $_SESSION['user']['user_id'] ?? null, "Confirmed Event #{$eventId}", 'events', $eventId);
            $flashSuccess = "Event #{$eventId} confirmed.";
        }
    }

    if ($action === 'mark_notifications_read') {
        $stmt = $pdo->prepare("UPDATE portal_notifications SET is_read = 1 WHERE user_id = :user_id OR audience_role IN ('RHU_ADMIN', 'SUPER_ADMIN', 'ADMIN_STAFF')");
        $stmt->execute(['user_id' => (int)$_SESSION['user']['user_id']]);
        $flashSuccess = 'Notifications marked as read.';
    }

    if ($action === 'update_resident_status') {
        $id = (int)($_POST['resident_id'] ?? 0);
        $status = (int)($_POST['new_status'] ?? 0);
        $stmt = $pdo->prepare('UPDATE residents SET is_active = :status WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $id]);
        if ($id > 0) {
            portalNotifyResident($pdo, $id, 'Your RHU resident profile status was updated by admin: ' . ($status ? 'Active' : 'Inactive') . '.', 'ResidentDashboard.php?tab=profile');
        }
        portalAudit($pdo, (int)$_SESSION['user']['user_id'], 'Updated resident status', 'residents', $id);
        $flashSuccess = 'Resident status updated.';
    }

    if ($action === 'update_consultation') {
        $id = (int)($_POST['consultation_id'] ?? 0);
        $diag = trim($_POST['diagnosis'] ?? '');
        $plan = trim($_POST['treatment_plan'] ?? '');
        $status = trim($_POST['consultation_status'] ?? 'Scheduled');
        $phyId = ($_POST['physician_id'] ?? '') ?: null;

        $updateColumns = ['diagnosis = :diagnosis'];
        $updateParams = [
            'diagnosis' => $diag,
            'id' => $id,
        ];
        if (function_exists('rhuColumnExists') && rhuColumnExists($pdo, 'consultations', 'treatment_plan')) {
            $updateColumns[] = 'treatment_plan = :treatment_plan';
            $updateParams['treatment_plan'] = $plan;
        } elseif (function_exists('rhuColumnExists') && rhuColumnExists($pdo, 'consultations', 'treatment')) {
            $updateColumns[] = 'treatment = :treatment_plan';
            $updateParams['treatment_plan'] = $plan;
        }
        if (function_exists('rhuColumnExists') && rhuColumnExists($pdo, 'consultations', 'consultation_notes')) {
            $updateColumns[] = 'consultation_notes = :notes';
            $updateParams['notes'] = $plan;
        } elseif (function_exists('rhuColumnExists') && rhuColumnExists($pdo, 'consultations', 'remarks')) {
            $updateColumns[] = 'remarks = :notes';
            $updateParams['notes'] = $plan;
        }
        if (function_exists('rhuColumnExists') && rhuColumnExists($pdo, 'consultations', 'physician_id')) {
            $updateColumns[] = 'physician_id = COALESCE(:physician_id, physician_id)';
            $updateParams['physician_id'] = $phyId;
        }
        if (function_exists('rhuColumnExists') && rhuColumnExists($pdo, 'consultations', 'health_worker_id')) {
            $updateColumns[] = 'health_worker_id = COALESCE(:health_worker_id, health_worker_id)';
            $updateParams['health_worker_id'] = $phyId;
        }
        if (function_exists('rhuColumnExists') && rhuColumnExists($pdo, 'consultations', 'consultation_status')) {
            $updateColumns[] = 'consultation_status = :status';
            $updateParams['status'] = $status;
        }
        if (function_exists('rhuColumnExists') && rhuColumnExists($pdo, 'consultations', 'updated_at')) {
            $updateColumns[] = 'updated_at = NOW()';
        }

        $stmt = $pdo->prepare('UPDATE consultations SET ' . implode(', ', $updateColumns) . ' WHERE id = :id');
        $stmt->execute($updateParams);
        $residentId = (int)($pdo->query("SELECT resident_id FROM consultations WHERE id = {$id}")->fetchColumn() ?: 0);
        if ($residentId > 0) {
            $statusText = $status !== '' ? $status : 'updated';
            portalNotifyResident($pdo, $residentId, "Your RHU consultation was updated. Status: {$statusText}.", 'ResidentDashboard.php?tab=records');
        }
        portalAudit($pdo, (int)$_SESSION['user']['user_id'], 'Updated consultation', 'consultations', $id);
        $flashSuccess = 'Consultation updated.';
    }

    if ($action === 'update_pregnancy') {
        $id = (int)($_POST['pregnancy_id'] ?? 0);
        $status = trim($_POST['status'] ?? 'Active');
        $stmt = $pdo->prepare('UPDATE pregnancy_records SET pregnancy_status = :status, high_risk = :high_risk WHERE id = :id');
        $stmt->execute(['status' => $status, 'high_risk' => isset($_POST['high_risk']) ? 1 : 0, 'id' => $id]);
        $pregnancyResidentId = (int)($pdo->query("SELECT resident_id FROM pregnancy_records WHERE id = {$id}")->fetchColumn() ?: 0);
        if ($pregnancyResidentId > 0) {
            portalNotifyResident($pdo, $pregnancyResidentId, "Your maternal health record was updated by RHU admin. Status: {$status}.", 'ResidentDashboard.php?tab=records');
        }
        portalAudit($pdo, (int)$_SESSION['user']['user_id'], 'Updated maternal case', 'pregnancy_records', $id);
        $flashSuccess = 'Maternal case updated.';
    }

    if ($action === 'update_maternal_referral') {
        $id = (int)($_POST['referral_id'] ?? 0);
        $status = trim($_POST['referral_status'] ?? 'Pending');
        $stmt = $pdo->prepare('UPDATE maternal_referrals SET referral_status = :status, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $id]);
        $residentStmt = $pdo->prepare('SELECT resident_id, referred_to FROM maternal_referrals WHERE id = :id');
        $residentStmt->execute(['id' => $id]);
        if ($referral = $residentStmt->fetch(PDO::FETCH_ASSOC)) {
            portalNotifyResident($pdo, (int)$referral['resident_id'], "Your referral to {$referral['referred_to']} is now {$status}.", 'ResidentDashboard.php?tab=records');
        }
        portalAudit($pdo, (int)$_SESSION['user']['user_id'], 'Updated maternal referral', 'maternal_referrals', $id);
        $flashSuccess = 'Maternal referral updated.';
    }

    if ($action === 'update_disease_case') {
        $id = (int)($_POST['case_id'] ?? 0);
        $classification = trim($_POST['classification'] ?? 'Suspected');
        $outcome = trim($_POST['outcome'] ?? '');
        $stmt = $pdo->prepare('UPDATE disease_cases SET case_classification = :classification, outcome = :outcome, reported_to_doh = :reported WHERE id = :id');
        $stmt->execute([
            'classification' => $classification,
            'outcome' => $outcome,
            'reported' => isset($_POST['reported_to_doh']) ? 1 : 0,
            'id' => $id,
        ]);
        $caseResidentId = (int)($pdo->query("SELECT resident_id FROM disease_cases WHERE id = {$id}")->fetchColumn() ?: 0);
        if ($caseResidentId > 0) {
            $caseStatus = $outcome !== '' ? "{$classification}, outcome: {$outcome}" : $classification;
            portalNotifyResident($pdo, $caseResidentId, "Your disease surveillance record was updated by RHU admin. Status: {$caseStatus}.", 'ResidentDashboard.php?tab=records');
        }
        portalAudit($pdo, (int)$_SESSION['user']['user_id'], 'Updated disease surveillance case', 'disease_cases', $id);
        $flashSuccess = 'Disease case updated.';
    }

    if ($action === 'adjust_medicine_stock') {
        $id = (int)($_POST['medicine_id'] ?? 0);
        $quantity = (int)($_POST['quantity_change'] ?? 0);
        if ($id > 0 && $quantity !== 0) {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('UPDATE medicine_inventory SET quantity_in_stock = GREATEST(0, quantity_in_stock + :quantity), last_updated = NOW() WHERE id = :id');
                $stmt->execute(['quantity' => $quantity, 'id' => $id]);
                $stmt = $pdo->prepare("INSERT INTO stock_transactions (medicine_id, transaction_type, quantity, transaction_date, reason) VALUES (:id, :type, :quantity, CURDATE(), :reason)");
                $stmt->execute(['id' => $id, 'type' => $quantity > 0 ? 'In' : 'Out', 'quantity' => abs($quantity), 'reason' => trim($_POST['reason'] ?? 'Admin adjustment')]);
                $pdo->commit();
                portalAudit($pdo, (int)$_SESSION['user']['user_id'], 'Adjusted medicine stock', 'medicine_inventory', $id);
                $flashSuccess = 'Medicine stock adjusted.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        }
    }

    if ($action === 'update_smtp_settings') {
        portalSaveSettings($pdo, [
            'smtp_host' => $_POST['smtp_host'] ?? '',
            'smtp_port' => $_POST['smtp_port'] ?? '587',
            'smtp_encryption' => $_POST['smtp_encryption'] ?? 'tls',
            'smtp_user' => $_POST['smtp_user'] ?? '',
            'two_factor_enabled' => isset($_POST['two_factor_enabled']) ? '1' : '0',
        ]);
        portalAudit($pdo, (int)$_SESSION['user']['user_id'], 'Updated mail and 2FA settings', 'portal_settings', null);
        $flashSuccess = 'Mail and 2FA settings saved.';
    }

    if ($action === 'create_scheduled_event') {
        $title = trim($_POST['title'] ?? '');
        $scheduledDate = trim($_POST['scheduled_date'] ?? '');
        if ($title !== '' && $scheduledDate !== '') {
            $stmt = $pdo->prepare(
                "INSERT INTO events (title, description, location, start_datetime, organizer_id, status, created_at)
                 VALUES (:title, :description, :location, :start_datetime, :organizer_id, 'Scheduled', NOW())"
            );
            $stmt->execute([
                'title' => $title,
                'description' => trim($_POST['description'] ?? ''),
                'location' => trim($_POST['venue'] ?? 'Nasugbu RHU'),
                'start_datetime' => $scheduledDate . ' ' . (($_POST['start_time'] ?? '') ?: '08:00') . ':00',
                'organizer_id' => (int)($_SESSION['user']['user_id'] ?? 0) ?: null,
            ]);
            portalAudit($pdo, (int)$_SESSION['user']['user_id'], 'Created RHU event', 'events', (int)$pdo->lastInsertId());
            portalNotify($pdo, "New RHU event scheduled: {$title}", null, 'RESIDENT', 'ResidentDashboard.php?tab=events');
            $flashSuccess = 'Event created and published to the Resident Portal.';
        }
    }

    if ($action === 'update_user_role') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $roleId = (int)($_POST['role_id'] ?? 0);
        $roleNames = [1 => 'PHYSICIAN', 2 => 'NURSE', 3 => 'MIDWIFE', 4 => 'MEDTECH', 5 => 'SANITARY_INSPECTOR', 6 => 'BHW', 7 => 'ADMIN_STAFF'];
        $newRoleName = (string)($roleNames[$roleId] ?? trim((string)($_POST['role_name'] ?? '')));
        $stmt = $pdo->prepare('UPDATE health_workers SET role = :role_name, position_title = :position_title, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            'role_name' => $newRoleName !== '' ? $newRoleName : 'Staff',
            'position_title' => $newRoleName !== '' ? $newRoleName : 'Staff',
            'id' => $userId,
        ]);
        portalAudit($pdo, (int)$_SESSION['user']['user_id'], 'Changed user role', 'health_workers', $userId);
        $flashSuccess = 'User role updated.';
    }

    // Action: Toggle User Status
    if ($action === 'toggle_user_status') {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $newStatus = (int)($_POST['new_status'] ?? 0);
        if ($targetUserId > 0) {
            $stmt = $pdo->prepare("UPDATE health_workers SET status = :status, updated_at = NOW() WHERE id = :id");
            $stmt->execute(['status' => $newStatus ? 'Active' : 'Inactive', 'id' => $targetUserId]);
            portalAudit($pdo, $_SESSION['user']['user_id'] ?? null, "Toggled User #{$targetUserId} status to " . ($newStatus ? 'Active' : 'Inactive'), 'health_workers', $targetUserId);
            $flashSuccess = "User status updated successfully.";
        }
    }

    // Action: Delete User
    if ($action === 'delete_user') {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        if ($targetUserId > 0 && $targetUserId !== (int)($_SESSION['user']['user_id'] ?? 0)) {
            $stmt = $pdo->prepare("DELETE FROM health_workers WHERE id = :id");
            $stmt->execute(['id' => $targetUserId]);
            portalAudit($pdo, $_SESSION['user']['user_id'] ?? null, "Deleted User #{$targetUserId}", 'health_workers', $targetUserId);
            $flashSuccess = "User account removed from database.";
        }
    }

    // Action: Toggle Staff Status
    if ($action === 'toggle_staff_status') {
        $staffId = (int)($_POST['staff_id'] ?? 0);
        $newStatus = (int)($_POST['new_status'] ?? 0);
        if ($staffId > 0) {
            $stmt = $pdo->prepare("UPDATE health_workers SET status = :status WHERE id = :id");
            $stmt->execute(['status' => $newStatus ? 'Active' : 'Inactive', 'id' => $staffId]);
            portalAudit($pdo, $_SESSION['user']['user_id'] ?? null, "Toggled Staff #{$staffId} status to " . ($newStatus ? 'Active' : 'Inactive'), 'health_workers', $staffId);
            $flashSuccess = "Staff account status updated.";
        }
    }

    // Action: Create New Healthcare Staff / BHW Account
    if ($action === 'create_staff') {
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $staffType = trim($_POST['staff_type'] ?? 'ADMIN_STAFF');
        $specialization = trim($_POST['specialization'] ?? '');
        $licenseNumber = trim($_POST['license_number'] ?? '');
        $phone = trim($_POST['phone_number'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $barangay = trim($_POST['barangay'] ?? '');
        $customCertNumber = trim($_POST['cert_number'] ?? '');

        if ($firstName === '' || $lastName === '' || $email === '' || $password === '') {
            $flashError = "Please fill in all required fields.";
        } else {
            try {
                $pdo->beginTransaction();
                $check = $pdo->prepare("SELECT id FROM health_workers WHERE email = :email LIMIT 1");
                $check->execute(['email' => $email]);
                if ($check->fetch()) {
                    throw new Exception("An account with email {$email} already exists.");
                }

                $hash = password_hash($password, PASSWORD_DEFAULT);
                $username = strtolower(preg_replace('/[^a-z0-9]/i', '', explode('@', $email)[0])) . rand(100, 999);

                $insUser = $pdo->prepare("INSERT INTO health_workers (username, email, password_hash, first_name, last_name, role, position_title, license_number, contact_number, status, created_at) VALUES (:u, :e, :h, :f, :l, :role_name, :position_title, :license_number, :contact_number, 'Active', NOW())");
                $insUser->execute([
                    'u' => $username,
                    'e' => $email,
                    'h' => $hash,
                    'f' => $firstName,
                    'l' => $lastName,
                    'role_name' => $staffType,
                    'position_title' => $staffType,
                    'license_number' => $licenseNumber,
                    'contact_number' => $phone,
                ]);
                $newUserId = (int)$pdo->lastInsertId();

                $newStaffId = $newUserId;

                if ($pdo->inTransaction()) {
                    $pdo->commit();
                }

                portalAudit($pdo, $_SESSION['user']['user_id'] ?? null, "Created new Healthcare Staff Account for {$email} ({$staffType})", 'health_workers', $newStaffId);
                $flashSuccess = "New healthcare staff account created successfully for {$firstName} {$lastName}!";
            } catch (Exception $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $flashError = $ex->getMessage();
            }
        }
    }

    // Action: Publish Announcement for Landing Page
    if ($action === 'create_announcement') {
        $title = trim($_POST['title'] ?? '');
        $category = trim($_POST['category'] ?? 'Health Awareness');
        $content = trim($_POST['content'] ?? '');
        $badgeText = trim($_POST['badge_text'] ?? 'Awareness');
        $postedBy = trim($_SESSION['user']['username'] ?? 'MHO Admin');
        $imageUrl = '';
        $isPopup = !empty($_POST['is_popup']) ? 1 : 0;

        if (!empty($_FILES['announcement_image']['tmp_name'])) {
            try {
                $imageUrl = portalSaveUploadedImage($_FILES['announcement_image']['tmp_name'], $_FILES['announcement_image']['name'] ?? 'announcement.jpg', 'announcements');
            } catch (Throwable $e) {
                $imageUrl = '';
            }
        }

        if ($title !== '' && $content !== '') {
            $stmt = $pdo->prepare("INSERT INTO audit_logs (action, module_name, record_id, description, created_at)
                VALUES ('Landing Announcement', 'Landing Page', 0, :description, NOW())");
            $stmt->execute(['description' => json_encode([
                'title' => $title,
                'category' => $category,
                'content' => $content,
                'badge_text' => $badgeText,
                'image_url' => $imageUrl,
                'is_popup' => $isPopup,
                'posted_by' => $postedBy,
            ], JSON_UNESCAPED_SLASHES)]);
            portalAudit($pdo, $_SESSION['user']['user_id'] ?? null, "Published Landing Page Awareness Post: {$title}", 'audit_logs', (int)$pdo->lastInsertId());
            portalNotify($pdo, "RHU announcement: {$title}", null, 'RESIDENT', 'ResidentDashboard.php?tab=home');
            $flashSuccess = "Health Awareness & Announcement post published live to Landing Page!";
        } else {
            $flashError = "Please provide both title and content for the announcement.";
        }
    }

    // Action: Delete Announcement
    if ($action === 'delete_announcement') {
        $annId = (int)($_POST['announcement_id'] ?? 0);
        if ($annId > 0) {
            $stmt = $pdo->prepare("DELETE FROM audit_logs WHERE id = :id AND action = 'Landing Announcement' AND module_name = 'Landing Page'");
            $stmt->execute(['id' => $annId]);
            portalAudit($pdo, $_SESSION['user']['user_id'] ?? null, "Deleted Announcement #{$annId}", 'audit_logs', $annId);
            $flashSuccess = "Announcement removed from Landing Page.";
        }
    }

    // Action: Create Health Event
    if ($action === 'create_event') {
        ensurePortalTables($pdo);
        $eventDateInput = trim($_POST['event_date'] ?? '');
        $eventDateObject = DateTime::createFromFormat('!Y-m-d', $eventDateInput);
        $eventDateIsValid = $eventDateObject instanceof DateTime
            && $eventDateObject->format('Y-m-d') === $eventDateInput;
        $eventDate = $eventDateIsValid ? $eventDateObject->format('M d, Y') : '';
        $title = trim($_POST['title'] ?? '');
        $barangayId = (int)($_POST['barangay_id'] ?? 0);
        $venue = '';
        if ($barangayId > 0) {
            $barangayStmt = $pdo->prepare("SELECT name FROM barangays WHERE id = :id LIMIT 1");
            $barangayStmt->execute(['id' => $barangayId]);
            $venue = trim((string)($barangayStmt->fetchColumn() ?: ''));
        }
        $description = trim($_POST['description'] ?? '');
        $badgeColor = trim($_POST['badge_color'] ?? 'bg-emerald-500');
        $imageUrl = '';

        if (!empty($_FILES['event_image']['tmp_name'])) {
            try {
                $imageUrl = portalSaveUploadedImage($_FILES['event_image']['tmp_name'], $_FILES['event_image']['name'] ?? 'event.jpg', 'events');
            } catch (Throwable $e) {
                $imageUrl = '';
            }
        }

        if ($title !== '' && $eventDate !== '' && $venue !== '') {
            $stmt = $pdo->prepare("INSERT INTO events (title, description, barangay_id, location, start_datetime, organizer_id, status, created_at)
                VALUES (:title, :description, :barangay_id, :location, :start_datetime, :organizer_id, 'Scheduled', NOW())");
            $stmt->execute(['title' => $title, 'description' => $description, 'barangay_id' => $barangayId ?: null, 'location' => $venue, 'start_datetime' => $eventDateInput . ' 08:00:00', 'organizer_id' => (int)($_SESSION['user']['user_id'] ?? 0) ?: null]);
            portalAudit($pdo, $_SESSION['user']['user_id'] ?? null, "Created Health Event: {$title}", 'events', (int)$pdo->lastInsertId());
            portalNotify($pdo, "New RHU health event: {$title} at {$venue} on {$eventDate}", null, 'RESIDENT', 'ResidentDashboard.php?tab=events');
            $flashSuccess = "Health Event with picture published live to Landing Page!";
        } else {
            $flashError = "Please select a valid event date, enter a title, and choose a barangay.";
        }
    }

    // Action: Delete Health Event (and attached uploaded picture)
    if ($action === 'delete_event') {
        $evtId = (int)($_POST['event_id'] ?? 0);
        if ($evtId > 0) {
            $stmt = $pdo->prepare("DELETE FROM events WHERE id = :id");
            $stmt->execute(['id' => $evtId]);
            portalAudit($pdo, $_SESSION['user']['user_id'] ?? null, "Deleted Health Event #{$evtId}", 'events', $evtId);
            $flashSuccess = "Health event and attached picture deleted successfully.";
        }
    }

    // Action: Delete Event Cover Picture Only
    if ($action === 'delete_event_picture') {
        $evtId = (int)($_POST['event_id'] ?? 0);
        if ($evtId > 0) {
            $flashError = "Event pictures are not stored in the approved events table.";
        }
    }

    // Action: Add Municipal Official to portal_settings
    if ($action === 'add_official') {
        $name = trim($_POST['official_name'] ?? '');
        $position = trim($_POST['official_position'] ?? '');
        $office = trim($_POST['official_office'] ?? '');

        if ($name !== '' && $position !== '') {
            $settings = portalSettings($pdo);
            $currentOfficials = [];
            if (!empty($settings['rhu_municipal_officials'])) {
                $currentOfficials = json_decode($settings['rhu_municipal_officials'], true) ?: [];
            }
            $currentOfficials[] = [
                'name' => $name,
                'position' => $position,
                'office' => $office ?: 'LGU Nasugbu'
            ];
            $jsonStr = json_encode($currentOfficials);
            $stmt = $pdo->prepare("INSERT INTO portal_settings (setting_key, setting_value) VALUES ('rhu_municipal_officials', :val) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmt->execute(['val' => $jsonStr]);
            portalAudit($pdo, $_SESSION['user']['user_id'] ?? null, "Added Municipal Official: {$name}", 'portal_settings', 1);
            $flashSuccess = "Municipal Official '{$name}' added and updated live on Landing Page!";
        } else {
            $flashError = "Please enter official name and position.";
        }
    }

    // Action: Delete Municipal Official
    if ($action === 'delete_official') {
        $index = (int)($_POST['official_index'] ?? -1);
        $settings = portalSettings($pdo);
        if (!empty($settings['rhu_municipal_officials'])) {
            $currentOfficials = json_decode($settings['rhu_municipal_officials'], true) ?: [];
            if (isset($currentOfficials[$index])) {
                $removed = $currentOfficials[$index]['name'] ?? 'Official';
                array_splice($currentOfficials, $index, 1);
                $jsonStr = json_encode($currentOfficials);
                $stmt = $pdo->prepare("INSERT INTO portal_settings (setting_key, setting_value) VALUES ('rhu_municipal_officials', :val) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                $stmt->execute(['val' => $jsonStr]);
                portalAudit($pdo, $_SESSION['user']['user_id'] ?? null, "Removed Municipal Official #{$index}", 'portal_settings', 1);
                $flashSuccess = "Municipal Official '{$removed}' removed from Landing Page.";
            }
        }
    }

    // Action: Add Photo to Landing Page Event Gallery (stored in portal_settings)
    if ($action === 'add_gallery_photo') {
        $photoTitle = trim($_POST['photo_title'] ?? '');
        $photoCategory = trim($_POST['photo_category'] ?? 'Event Photo');
        $photoDate = trim($_POST['photo_date'] ?? date('M Y'));
        $imageUrl = trim($_POST['image_url'] ?? '');

        if (!empty($_FILES['gallery_file']['tmp_name'])) {
            try {
                $imageUrl = portalSaveUploadedImage($_FILES['gallery_file']['tmp_name'], $_FILES['gallery_file']['name'] ?? 'gallery.jpg', 'events');
            } catch (Throwable $e) {
                $imageUrl = '';
            }
        }

        if ($photoTitle !== '' && $imageUrl !== '') {
            $currentGallery = getPortalEventGallery($pdo);
            array_unshift($currentGallery, [
                'title' => $photoTitle,
                'category' => $photoCategory,
                'image_url' => $imageUrl,
                'date' => $photoDate
            ]);
            $jsonStr = json_encode($currentGallery);
            $stmt = $pdo->prepare("INSERT INTO portal_settings (setting_key, setting_value) VALUES ('rhu_event_gallery', :val) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmt->execute(['val' => $jsonStr]);
            portalAudit($pdo, $_SESSION['user']['user_id'] ?? null, "Added Event Gallery Photo: {$photoTitle}", 'portal_settings', 1);
            $flashSuccess = "Event photo '{$photoTitle}' published to Landing Page gallery!";
        } else {
            $flashError = "Please provide photo title and image file/URL.";
        }
    }

    // Action: Delete Photo from Landing Page Event Gallery
    if ($action === 'delete_gallery_photo') {
        $index = (int)($_POST['photo_index'] ?? -1);
        $currentGallery = getPortalEventGallery($pdo);
        if (isset($currentGallery[$index])) {
            $removed = $currentGallery[$index]['title'] ?? 'Photo';
            $imgFile = $currentGallery[$index]['image_url'] ?? '';

            if (!empty($imgFile) && str_starts_with($imgFile, 'uploads/events/')) {
                portalDeleteUploadedImage($imgFile);
            }

            array_splice($currentGallery, $index, 1);
            $jsonStr = json_encode($currentGallery);
            $stmt = $pdo->prepare("INSERT INTO portal_settings (setting_key, setting_value) VALUES ('rhu_event_gallery', :val) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmt->execute(['val' => $jsonStr]);
            portalAudit($pdo, $_SESSION['user']['user_id'] ?? null, "Deleted Gallery Photo #{$index}", 'portal_settings', 1);
            $flashSuccess = "Picture '{$removed}' deleted successfully from gallery and server.";
        }
    }

    if (empty($flashSuccess) && empty($flashError) && function_exists('adminExtendedAction')) {
        try {
            $msg = adminExtendedAction($pdo, $action, (int)($_SESSION['user']['user_id'] ?? 0));
            if ($msg) {
                $flashSuccess = $msg;
            }
        } catch (Exception $e) {
            $flashError = 'Action failed: ' . $e->getMessage();
        }
    }
}


// RHU Profile Settings
$savedPortalSettings = portalSettings($pdo);
$rhuProfile = [
    'name' => portalSetting($savedPortalSettings, 'rhu_name', 'Nasugbu Rural Health Unit I'),
    'mho_name' => portalSetting($savedPortalSettings, 'rhu_mho_name', 'Municipal Health Officer'),
    'municipality' => portalSetting($savedPortalSettings, 'rhu_municipality', 'Nasugbu'),
    'province' => portalSetting($savedPortalSettings, 'rhu_province', 'Batangas'),
    'contact' => portalSetting($savedPortalSettings, 'rhu_contact_number', '(043) 416-1234'),
    'email' => portalSetting($savedPortalSettings, 'rhu_email', 'rhu.nasugbu@batangas.gov.ph'),
];

// ----------------------------------------------------
// 2. FETCH REAL DATABASE RECORDS
// ----------------------------------------------------
$totalUsersCount = 0;
$totalStaffCount = 0;
$totalBhwCount = 0;
$totalResidentsCount = 0;

$dbUsersList = [];
$dbStaffList = [];
$dbResidentList = [];
$dbAuditLogsList = [];
$dbCertificatesList = [];
$adminCertificateTypes = [];
$adminResidentCertificateOptions = [];
$adminResidentCertificateRequests = [];
$certificateDoctorMappings = [];
$certificateDoctorOptions = [];
$dbMessagesList = [];
$dbEventsList = [];
$dbAnnouncementsList = [];
$dbPortalEventsList = [];
$dbBarangayOptions = [];
$adminNotifications = [];

// Clinical data
$dbConsultationsList = [];
$dbHealthProfileRecords = [];
$dbMaternalCases = [];
$dbVaccinationRecords = [];
$dbFamilyPlanningRecords = [];
$dbMaternalReferrals = [];
$dbDiseaseCases = [];
$dbMedicineInventory = [];
$dbVitalStatistics = [];

$barangayStats = [];
$staffTypeStats = [];
$roleStats = [];
$dbRoles = [];
$consultationStats = [];
$maternalStats = [];
$vaccinationStats = [];
$diseaseStats = [];
$medicineStats = [];
$reportsSelectedBarangay = trim((string)($_GET['analytics_barangay'] ?? ''));
$reportsSelectedDisease = trim((string)($_GET['analytics_disease'] ?? ''));
$reportsMapStats = [];
$reportsDiseaseOptions = [];
$reportsDiseaseByBarangay = [];
$reportsStaffUserMix = [];
$reportsMonthlySignals = [];
$databaseHealth = ['name' => rhuEnv('DB_NAME', 'rhu'), 'version' => 'Unavailable', 'tables' => 0];

if (!empty($pdo)) {
    try {
        $databaseHealth['version'] = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
        $databaseHealth['tables'] = (int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
        $totalUsersCount = (int)$pdo->query("SELECT COUNT(*) FROM health_workers")->fetchColumn();
        $totalStaffCount = (int)$pdo->query("SELECT COUNT(*) FROM health_workers WHERE status = 'Active'")->fetchColumn();
        $totalBhwCount = (int)$pdo->query("SELECT COUNT(*) FROM health_workers WHERE (UPPER(position_title) LIKE '%BHW%' OR UPPER(position_title) LIKE '%BARANGAY HEALTH%') AND status = 'Active'")->fetchColumn();
        $totalResidentsCount = (int)$pdo->query("SELECT COUNT(*) FROM residents")->fetchColumn();
        try {
            $dbBarangayOptions = $pdo->query(
                "SELECT id, name FROM barangays ORDER BY name"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $barangayLoadError) {
            error_log('Admin barangay options: ' . $barangayLoadError->getMessage());
        }

        $uStmt = $pdo->query("
            SELECT id, username, email, first_name, last_name, (status = 'Active') AS is_active, created_at, last_login_at AS last_login, role AS role_name
            FROM health_workers
            ORDER BY id DESC LIMIT 100
        ");
        $dbUsersList = $uStmt->fetchAll(PDO::FETCH_ASSOC);

        $sStmt = $pdo->query("
            SELECT id, id AS user_id, first_name, last_name, email, position_title AS staff_type, role AS specialization, license_number, NULL AS license_expiry, contact_number AS phone_number, '' AS address, (status = 'Active') AS is_active, DATE(created_at) AS date_hired
            FROM health_workers
            ORDER BY id DESC LIMIT 100
        ");
        $dbStaffList = $sStmt->fetchAll(PDO::FETCH_ASSOC);

        $rStmt = $pdo->query("
            SELECT r.id, r.first_name, r.last_name, r.middle_name, r.birthdate AS date_of_birth, r.sex AS sex, r.contact_number, r.email, COALESCE(b.name, '') AS barangay, r.address, r.philhealth_number AS philhealth_id, COALESCE(hr.blood_type, '') AS blood_type, r.created_at, (r.status = 'Active') AS is_active, TIMESTAMPDIFF(YEAR, r.birthdate, CURDATE()) AS age
            FROM residents r
            LEFT JOIN barangays b ON b.id = r.barangay_id
            LEFT JOIN health_records hr ON hr.resident_id = r.id
            ORDER BY r.id DESC LIMIT 200
        ");
        $dbResidentList = $rStmt->fetchAll(PDO::FETCH_ASSOC);
        $adminResidentCertificateOptions = array_map(static fn($resident) => [
            'id' => (int)$resident['id'],
            'name' => trim(($resident['first_name'] ?? '') . ' ' . ($resident['last_name'] ?? '')) ?: 'Resident #' . (int)$resident['id'],
            'barangay' => $resident['barangay'] ?? '',
        ], array_values(array_filter($dbResidentList, static fn($resident) => (int)($resident['is_active'] ?? 1) === 1)));
        $dbCertTypes = $pdo->query("SELECT MIN(id) AS id, certificate_type AS certificate_type_name FROM certificates WHERE certificate_type IS NOT NULL AND TRIM(certificate_type) != '' GROUP BY certificate_type ORDER BY certificate_type")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $defaultCertTypes = [
            ['id' => 1, 'certificate_type_name' => 'Medical Certificate'],
            ['id' => 2, 'certificate_type_name' => 'Sanitary Clearance'],
            ['id' => 3, 'certificate_type_name' => 'Health Clearance'],
            ['id' => 4, 'certificate_type_name' => 'Immunization Certificate'],
            ['id' => 5, 'certificate_type_name' => 'Barangay Health Certificate']
        ];
        if (empty($dbCertTypes)) {
            $adminCertificateTypes = $defaultCertTypes;
        } else {
            $adminCertificateTypes = $dbCertTypes;
            $existingNames = array_map(static fn($t) => strtolower($t['certificate_type_name']), $adminCertificateTypes);
            $nextId = 100;
            foreach ($defaultCertTypes as $dt) {
                if (!in_array(strtolower($dt['certificate_type_name']), $existingNames, true)) {
                    $adminCertificateTypes[] = ['id' => $nextId++, 'certificate_type_name' => $dt['certificate_type_name']];
                }
            }
        }
        $requestStmt = $pdo->query("SELECT id, resident_id, id AS certificate_type_id, purpose
            FROM certificates
            WHERE status IN ('Pending', 'Draft', 'Requested', 'Pending Approval')
            ORDER BY id DESC");
        foreach ($requestStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $request) {
            $residentId = (int)$request['resident_id'];
            if (!isset($adminResidentCertificateRequests[$residentId])) {
                $adminResidentCertificateRequests[$residentId] = [
                    'id' => (int)$request['id'],
                    'certificate_type_id' => (int)$request['certificate_type_id'],
                    'purpose' => (string)($request['purpose'] ?? ''),
                ];
            }
        }
        $certificateDoctorOptions = $pdo->query("
            SELECT id, position_title AS staff_type, role AS specialization, '' AS work_days, 1 AS is_on_duty,
                   CONCAT(first_name, ' ', last_name) AS name, email
            FROM health_workers
            WHERE status = 'Active'
            ORDER BY position_title, first_name, last_name
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $certificateDoctorMappings = [];

        $aStmt = $pdo->query("
            SELECT a.id, a.health_worker_id AS user_id, hw.first_name, hw.last_name, hw.email, hw.role AS role_name, a.action, a.module_name AS table_name, a.record_id, a.created_at AS timestamp, a.ip_address
            FROM audit_logs a
            LEFT JOIN health_workers hw ON hw.id = a.health_worker_id
            ORDER BY a.created_at DESC LIMIT 50
        ");
        $dbAuditLogsList = $aStmt->fetchAll(PDO::FETCH_ASSOC);

        try {
            $cStmt = $pdo->query("
                SELECT c.id, c.resident_id, c.certificate_type AS certificate_type_id, r.first_name, r.last_name, c.certificate_type,
                       c.purpose, c.status, c.status AS workflow_status,
                       c.issue_date, c.certificate_number, c.created_at, NULL AS generated_html, NULL AS sent_at,
                       c.issued_by_id, c.issued_by_id AS assigned_doctor_id, c.issued_by_id AS admin_approver_user_id,
                       1 AS duplicate_count,
                       CONCAT(COALESCE(hw.first_name,''), ' ', COALESCE(hw.last_name,'')) AS assigned_doctor_name,
                       hw.position_title AS assigned_doctor_position
                FROM certificates c
                LEFT JOIN residents r ON c.resident_id = r.id
                LEFT JOIN health_workers hw ON hw.id = c.issued_by_id
                ORDER BY c.created_at DESC LIMIT 50
            ");
            $dbCertificatesList = $cStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('Admin certificates: ' . $e->getMessage());
            $dbCertificatesList = [];
        }

        try {
            if (function_exists('rhuTableExists') && rhuTableExists($pdo, 'messages')) {
                $mStmt = $pdo->query("
                    SELECT m.id, m.resident_id, r.first_name, r.last_name, r.email,
                           m.subject, m.message, m.admin_reply, m.status, m.created_at
                    FROM messages m
                    LEFT JOIN residents r ON m.resident_id = r.id
                    ORDER BY m.created_at DESC LIMIT 50
                ");
            } else {
                $mStmt = $pdo->query("
                    SELECT a.id, a.record_id AS resident_id, r.first_name, r.last_name, r.email,
                           COALESCE(NULLIF(TRIM(a.action), ''), 'Resident Inquiry') AS subject,
                           a.description AS message, '' AS admin_reply, 'Pending' AS status, a.created_at
                    FROM audit_logs a
                    INNER JOIN residents r ON a.record_id = r.id
                    WHERE a.module_name = 'Resident Portal'
                      AND a.record_id IS NOT NULL
                      AND a.record_id > 0
                      AND a.action NOT LIKE '%Deleted%'
                      AND a.action NOT LIKE '%Blocked%'
                      AND a.action NOT LIKE '%Replied%'
                      AND a.action NOT LIKE '%Update%'
                    ORDER BY a.created_at DESC LIMIT 50
                ");
            }
            $dbMessagesList = $mStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            $dbMessagesList = [];
        }

        try {
            $eStmt = $pdo->query("
                SELECT e.id, NULL AS resident_id, '' AS first_name, '' AS last_name, e.title AS event_title,
                       DATE(e.start_datetime) AS event_date, e.status, e.created_at
                FROM events e
                ORDER BY e.created_at DESC LIMIT 50
            ");
            $dbEventsList = $eStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            error_log('Admin events: ' . $e->getMessage());
            $dbEventsList = [];
        }

        $barangayStats = $pdo->query("SELECT COALESCE(b.name, 'Unassigned') AS barangay, COUNT(*) AS count FROM residents r LEFT JOIN barangays b ON b.id = r.barangay_id GROUP BY COALESCE(b.name, 'Unassigned') ORDER BY count DESC")->fetchAll(PDO::FETCH_ASSOC);
        $staffTypeStats = $pdo->query("SELECT COALESCE(position_title, role, 'Staff') AS staff_type, COUNT(*) AS count FROM health_workers GROUP BY COALESCE(position_title, role, 'Staff') ORDER BY count DESC")->fetchAll(PDO::FETCH_ASSOC);
        $dbRoles = $pdo->query("SELECT MIN(id) AS id, COALESCE(role, position_title, 'Staff') AS name FROM health_workers GROUP BY COALESCE(role, position_title, 'Staff') ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

        try {
            $reportsDiseaseOptions = $pdo->query("SELECT name AS disease_name FROM disease_types ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $reportsMapWhere = [];
            $reportsMapParams = [];
            if ($reportsSelectedBarangay !== '') {
                $reportsMapWhere[] = 'b.name = ?';
                $reportsMapParams[] = $reportsSelectedBarangay;
            }
            if ($reportsSelectedDisease !== '') {
                $reportsMapWhere[] = 'dt.name = ?';
                $reportsMapParams[] = $reportsSelectedDisease;
            }
            $reportsMapSql = "
                SELECT b.name AS barangay, NULL AS latitude, NULL AS longitude, b.population,
                       COUNT(DISTINCT r.id) AS residents,
                       COUNT(dc.id) AS disease_cases,
                       COUNT(DISTINCT CASE WHEN dc.case_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN dc.id END) AS recent_cases,
                       SUM(CASE WHEN dc.case_status = 'Confirmed' THEN 1 ELSE 0 END) AS confirmed_cases
                FROM barangays b
                LEFT JOIN residents r ON r.barangay_id = b.id AND r.status = 'Active'
                LEFT JOIN disease_cases dc ON dc.resident_id = r.id
                LEFT JOIN disease_types dt ON dt.id = dc.disease_type_id
                WHERE 1=1" . ($reportsMapWhere ? ' AND ' . implode(' AND ', $reportsMapWhere) : '') . "
                GROUP BY b.id, b.name, b.population
                ORDER BY disease_cases DESC, residents DESC, b.name
            ";
            $reportsMapStmt = $pdo->prepare($reportsMapSql);
            $reportsMapStmt->execute($reportsMapParams);
            $reportsMapStats = $reportsMapStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $reportsDiseaseWhere = [];
            $reportsDiseaseParams = [];
            if ($reportsSelectedBarangay !== '') {
                $reportsDiseaseWhere[] = 'b.name = ?';
                $reportsDiseaseParams[] = $reportsSelectedBarangay;
            }
            if ($reportsSelectedDisease !== '') {
                $reportsDiseaseWhere[] = 'dt.name = ?';
                $reportsDiseaseParams[] = $reportsSelectedDisease;
            }
            $reportsDiseaseSql = "
                SELECT COALESCE(b.name, 'Unassigned') AS barangay, dt.name AS disease_name, COUNT(*) AS cases,
                       SUM(CASE WHEN dc.case_status = 'Confirmed' THEN 1 ELSE 0 END) AS confirmed
                FROM disease_cases dc
                LEFT JOIN residents r ON r.id = dc.resident_id
                LEFT JOIN barangays b ON b.id = r.barangay_id
                LEFT JOIN disease_types dt ON dt.id = dc.disease_type_id
                WHERE r.id IS NOT NULL" . ($reportsDiseaseWhere ? ' AND ' . implode(' AND ', $reportsDiseaseWhere) : '') . "
                GROUP BY COALESCE(b.name, 'Unassigned'), dt.name
                ORDER BY cases DESC, confirmed DESC
                LIMIT 12
            ";
            $reportsDiseaseStmt = $pdo->prepare($reportsDiseaseSql);
            $reportsDiseaseStmt->execute($reportsDiseaseParams);
            $reportsDiseaseByBarangay = $reportsDiseaseStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $reportsStaffUserMix = $pdo->query("
                SELECT COALESCE(position_title, role, 'Staff') AS label, COUNT(id) AS count
                FROM health_workers
                WHERE status = 'Active'
                GROUP BY COALESCE(position_title, role, 'Staff')
                ORDER BY count DESC
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $reportsMonthlySignals = $pdo->query("
                SELECT DATE_FORMAT(month_start, '%b') AS label, DATE_FORMAT(month_start, '%Y-%m') AS month_key,
                       COALESCE(residents, 0) AS residents,
                       COALESCE(consultations, 0) AS consultations,
                       COALESCE(disease_cases, 0) AS disease_cases,
                       COALESCE(vaccinations, 0) AS vaccinations,
                       COALESCE(pregnancies, 0) AS pregnancies
                FROM (
                    SELECT DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01') AS month_start UNION ALL
                    SELECT DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 4 MONTH), '%Y-%m-01') UNION ALL
                    SELECT DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 3 MONTH), '%Y-%m-01') UNION ALL
                    SELECT DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 2 MONTH), '%Y-%m-01') UNION ALL
                    SELECT DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01') UNION ALL
                    SELECT DATE_FORMAT(CURDATE(), '%Y-%m-01')
                ) months
                LEFT JOIN (SELECT DATE_FORMAT(created_at, '%Y-%m-01') m, COUNT(*) residents FROM residents GROUP BY m) r ON r.m = months.month_start
                LEFT JOIN (SELECT DATE_FORMAT(consultation_date, '%Y-%m-01') m, COUNT(*) consultations FROM consultations GROUP BY m) c ON c.m = months.month_start
                LEFT JOIN (SELECT DATE_FORMAT(case_date, '%Y-%m-01') m, COUNT(*) disease_cases FROM disease_cases GROUP BY m) d ON d.m = months.month_start
                LEFT JOIN (SELECT DATE_FORMAT(vaccination_date, '%Y-%m-01') m, COUNT(*) vaccinations FROM vaccination_records GROUP BY m) v ON v.m = months.month_start
                LEFT JOIN (SELECT DATE_FORMAT(created_at, '%Y-%m-01') m, COUNT(*) pregnancies FROM pregnancy_records GROUP BY m) p ON p.m = months.month_start
                ORDER BY months.month_start
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $reportsError) {
            error_log('Admin reports analytics: ' . $reportsError->getMessage());
        }

        // CLINICAL DATA QUERIES
        // Consultations
        try {
            $cslStmt = $pdo->query("
                SELECT c.id, c.resident_id, r.first_name, r.last_name, b.name AS barangay, c.chief_complaint, c.diagnosis, COALESCE(c.treatment_plan, c.treatment) AS treatment_plan, COALESCE(c.consultation_notes, c.remarks) AS consultation_notes, COALESCE(c.physician_id, c.health_worker_id) AS physician_id, c.health_worker_id AS recorded_by_staff_id, c.health_worker_id AS recorded_by_user_id, c.consultation_date, COALESCE(c.consultation_status, 'Scheduled') AS consultation_status
                FROM consultations c
                LEFT JOIN residents r ON c.resident_id = r.id
                LEFT JOIN barangays b ON b.id = r.barangay_id
                ORDER BY c.consultation_date DESC, c.id DESC
            ");
            $dbConsultationsList = $cslStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $dbConsultationsList = [];
        }

        try {
            $dbHealthProfileRecords = $pdo->query("
                SELECT hp.id, hp.resident_id, hp.recorded_by_id AS updated_by_staff_id, hp.recorded_by_id AS updated_by_user_id, hp.height_cm AS height, hp.weight_kg AS weight, hp.blood_pressure, hp.last_checkup_date, hp.updated_at,
                       r.first_name, r.last_name, b.name AS barangay
                FROM health_records hp
                LEFT JOIN residents r ON r.id = hp.resident_id
                LEFT JOIN barangays b ON b.id = r.barangay_id
                ORDER BY hp.updated_at DESC, hp.id DESC
                LIMIT 200
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $dbHealthProfileRecords = [];
        }

        // Maternal Health Cases
        try {
            $mtlStmt = $pdo->query("
                SELECT p.id, p.resident_id, r.first_name, r.last_name, b.name AS barangay, p.last_menstrual_period as lmp, p.expected_delivery_date as edc, p.pregnancy_status as status, (p.risk_level = 'High Risk') as high_risk, hr.blood_type, p.health_worker_id as midwife_staff_id, p.created_at
                FROM pregnancy_records p
                LEFT JOIN residents r ON p.resident_id = r.id
                LEFT JOIN barangays b ON b.id = r.barangay_id
                LEFT JOIN health_records hr ON hr.resident_id = r.id
                ORDER BY p.last_menstrual_period DESC
            ");
            $dbMaternalCases = $mtlStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $dbMaternalCases = [];
        }

        $dbFamilyPlanningRecords = [];
        $dbMaternalReferrals = [];

        // Immunization/Vaccination Records
        try {
            $vccStmt = $pdo->query("
                SELECT v.id, v.resident_id, r.first_name, r.last_name, b.name AS barangay, v.vaccine_name, v.vaccination_date, v.health_worker_id AS healthcare_provider_id, 'Completed' as status
                FROM vaccination_records v
                LEFT JOIN residents r ON v.resident_id = r.id
                LEFT JOIN barangays b ON b.id = r.barangay_id
                ORDER BY v.vaccination_date DESC
            ");
            $dbVaccinationRecords = $vccStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('Admin vaccinations: ' . $e->getMessage());
            $dbVaccinationRecords = [];
        }

        // Disease Surveillance Cases
        try {
            $dcsStmt = $pdo->query("
                SELECT dc.id, dc.disease_type_id AS disease_id, dt.name AS disease_name, dc.resident_id, r.first_name, r.last_name, b.name AS barangay, dc.case_date, dc.reported_by_id, dc.case_status as status, dc.outcome
                FROM disease_cases dc
                LEFT JOIN disease_types dt ON dc.disease_type_id = dt.id
                LEFT JOIN residents r ON dc.resident_id = r.id
                LEFT JOIN barangays b ON b.id = r.barangay_id
                ORDER BY dc.case_date DESC LIMIT 100
            ");
            $dbDiseaseCases = $dcsStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('Admin disease cases: ' . $e->getMessage());
            $dbDiseaseCases = [];
        }

        // Medicine Inventory
        try {
            $medStmt = $pdo->query("
                SELECT id, item_name AS generic_name, item_name AS brand_name, quantity, reorder_level, 0.00 AS unit_price, expiration_date AS expiry_date, batch_number,
                       CASE
                           WHEN quantity <= reorder_level THEN 'critical'
                           WHEN quantity <= (reorder_level * 1.5) THEN 'low'
                           ELSE 'adequate'
                       END as status
                FROM medicine_inventory
                ORDER BY quantity ASC LIMIT 100
            ");
            $dbMedicineInventory = $medStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $dbMedicineInventory = [];
        }

        // Vital Statistics (Births & Deaths)
        try {
            $vitalStmt = $pdo->query("
                SELECT v.id, v.event_type as type, v.full_name AS child_name, '' as location, v.event_date as date_recorded, v.created_at AS record_created_at, v.recorded_by_id AS delivery_attendant_id, r.first_name AS mother_first_name, r.last_name AS mother_last_name, b.name AS barangay
                FROM vital_statistics v
                LEFT JOIN residents r ON r.id = v.resident_id
                LEFT JOIN barangays b ON b.id = v.barangay_id
                ORDER BY v.event_date DESC
            ");
            $dbVitalStatistics = $vitalStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $dbVitalStatistics = [];
        }

        // Statistics Summaries
        try {
            $consultationStats = $pdo->query("SELECT COUNT(*) as total FROM consultations")->fetchAll(PDO::FETCH_ASSOC);
            $maternalStats = $pdo->query("SELECT pregnancy_status as status, COUNT(*) as count FROM pregnancy_records GROUP BY pregnancy_status")->fetchAll(PDO::FETCH_ASSOC);
            $vaccinationStats = $pdo->query("SELECT COUNT(*) as count FROM vaccination_records")->fetchAll(PDO::FETCH_ASSOC);
            $diseaseStats = $pdo->query("SELECT dt.name AS disease_name, COUNT(*) as count FROM disease_cases dc LEFT JOIN disease_types dt ON dc.disease_type_id = dt.id GROUP BY dt.name ORDER BY count DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
            $medicineStats = $pdo->query("SELECT CASE WHEN quantity <= reorder_level THEN 'critical' WHEN quantity <= (reorder_level*1.5) THEN 'low' ELSE 'adequate' END as status, COUNT(*) as count FROM medicine_inventory GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
        }



        $pendingCertCount = (int)($pdo->query("SELECT COUNT(*) FROM certificates WHERE status = 'Pending'")->fetchColumn() ?: 0);
        if ($pendingCertCount > 0) {
            $adminNotifications[] = ['id' => 'n1', 'msg' => "{$pendingCertCount} pending certificate request(s) require review", 'type' => 'alert', 'time' => 'Action Needed', 'unread' => true];
        }

        try {
            $pendingMsgCount = (int)($pdo->query("SELECT COUNT(*) FROM audit_logs WHERE module_name = 'Resident Portal' AND action = 'Resident Message'")->fetchColumn() ?: 0);
            if ($pendingMsgCount > 0) {
                $adminNotifications[] = ['id' => 'n2', 'msg' => "{$pendingMsgCount} unread resident message(s) received", 'type' => 'alert', 'time' => 'Action Needed', 'unread' => true];
            }
        } catch (Exception $e) {
        }

        try {
            if (!rhuTableExists($pdo, 'portal_notifications')) {
                throw new RuntimeException('portal_notifications disabled by 16-table schema');
            }
            $nStmt = $pdo->prepare(
                "SELECT id, message AS msg, created_at AS time, (is_read = 0) AS unread
                 FROM portal_notifications
                 WHERE user_id = :user_id OR audience_role IN ('RHU_ADMIN', 'SUPER_ADMIN', 'ADMIN_STAFF')
                 ORDER BY created_at DESC LIMIT 20"
            );
            $nStmt->execute(['user_id' => (int)($_SESSION['user']['user_id'] ?? 0)]);
            $adminNotifications = array_merge($nStmt->fetchAll(PDO::FETCH_ASSOC) ?: [], $adminNotifications);
        } catch (Exception $e) {
            error_log('Admin notifications: ' . $e->getMessage());
        }

        $adminNotifications[] = ['id' => 'n4', 'msg' => 'System connected to MySQL rhu database.', 'type' => 'check', 'time' => 'Active', 'unread' => false];
    } catch (PDOException $ex) {
        error_log("RHUAdminDashboard DB Error: " . $ex->getMessage());
    }
}

$overviewChartSeries = [];
for ($i = 11; $i >= 0; --$i) {
    $monthDate = new DateTimeImmutable('first day of this month');
    $monthDate = $monthDate->modify("-{$i} months");
    $monthKey = $monthDate->format('Y-m');
    $monthLabel = $monthDate->format('M');
    $monthCount = 0;
    foreach ($dbConsultationsList as $consultation) {
        if (empty($consultation['consultation_date'])) {
            continue;
        }
        $consultationMonth = date('Y-m', strtotime((string)$consultation['consultation_date']));
        if ($consultationMonth === $monthKey) {
            $monthCount++;
        }
    }
    $overviewChartSeries[] = ['label' => $monthLabel, 'value' => $monthCount];
}
$overviewChartMax = max(1, ...array_map(static fn($point) => (int)$point['value'], $overviewChartSeries));
$overviewChartPoints = [];
$overviewChartSoftPoints = [];
$overviewChartWidth = 520;
$overviewChartBaseline = 182;
$overviewChartTop = 36;
$overviewChartUsableHeight = $overviewChartBaseline - $overviewChartTop;
$overviewChartStep = ($overviewChartWidth - 44) / max(1, count($overviewChartSeries) - 1);
foreach ($overviewChartSeries as $index => $point) {
    $x = 22 + ($index * $overviewChartStep);
    $y = $overviewChartBaseline - ((int)$point['value'] / $overviewChartMax) * $overviewChartUsableHeight;
    $overviewChartPoints[] = ['x' => $x, 'y' => $y, 'value' => (int)$point['value']];
    $softValue = min($overviewChartMax, max((int)$point['value'] + (int)ceil($overviewChartMax * 0.22), (int)round($overviewChartMax * 0.12)));
    $softY = $overviewChartBaseline - ($softValue / $overviewChartMax) * $overviewChartUsableHeight;
    $overviewChartSoftPoints[] = ['x' => $x, 'y' => $softY];
}
$overviewChartPolyline = implode(' ', array_map(static fn($point) => $point['x'] . ',' . $point['y'], $overviewChartPoints));
$overviewChartFill = implode(' ', array_map(static fn($point) => $point['x'] . ',' . $point['y'], $overviewChartPoints));
$overviewChartFill .= " 498,{$overviewChartBaseline} 22,{$overviewChartBaseline}";
$overviewChartSoftFill = implode(' ', array_map(static fn($point) => $point['x'] . ',' . $point['y'], $overviewChartSoftPoints));
$overviewChartSoftFill .= " 498,{$overviewChartBaseline} 22,{$overviewChartBaseline}";
$overviewDonutTotal = max(1, $totalUsersCount + $totalStaffCount + $totalBhwCount);
$overviewUsersPct = ($totalUsersCount / $overviewDonutTotal) * 100;
$overviewStaffPct = ($totalStaffCount / $overviewDonutTotal) * 100;
$overviewBhwPct = ($totalBhwCount / $overviewDonutTotal) * 100;

$tabLabelMap = [
    'overview' => 'Overview',
    'users' => 'User Management',
    'staff' => 'Staff Accounts',
    'residents' => 'Resident Registry',
    'announcements' => 'Landing Page Content',
    'consultations' => 'OPD Consultations',
    'maternal' => 'Maternal Health',
    'vaccination' => 'Immunization',
    'disease' => 'Disease Surveillance',
    'medicine' => 'Medicine Inventory',
    'vital' => 'Vital Statistics',
    'reports' => 'Reports & Analytics',
    'audit' => 'Audit Logs',
    'system' => 'System Settings',
    'security' => 'Security',
];

$tabIconMap = [
    'overview' => 'home',
    'users' => 'users',
    'staff' => 'stethoscope',
    'residents' => 'check',
    'announcements' => 'bell',
    'consultations' => 'activity',
    'maternal' => 'baby',
    'vaccination' => 'shield',
    'disease' => 'alert',
    'medicine' => 'pill',
    'vital' => 'file',
    'reports' => 'bar',
    'audit' => 'database',
    'system' => 'settings',
    'security' => 'lock',
];

$tabDescriptionMap = [
    'overview' => 'Monitor system accounts, residents, clinical activity, and administrative priorities.',
    'users' => 'Manage database accounts, roles, activation status, and access credentials.',
    'staff' => 'Review healthcare personnel records, positions, licenses, and account status.',
    'residents' => 'Search and maintain the municipal resident health registry.',
    'announcements' => 'Manage landing-page announcements, alerts, events, and public health content.',
    'consultations' => 'Review OPD encounters, diagnoses, attending staff, and consultation records.',
    'maternal' => 'Monitor prenatal cases, expected delivery dates, and maternal risk levels.',
    'vaccination' => 'Review resident immunization records, schedules, providers, and dose history.',
    'disease' => 'Monitor reportable disease cases and public-health surveillance activity.',
    'medicine' => 'Manage medicine stock, reorder levels, expiration, and supply availability.',
    'vital' => 'Review registered births and other municipal vital-statistics records.',
    'reports' => 'Review database summaries and export administrative reports.',
    'audit' => 'Track accountable administrator and staff actions across the system.',
    'system' => 'Configure RHU facility identity, contact information, and system behavior.',
    'security' => 'Manage administrator security, passwords, sessions, backup, and access controls.',
];

$drawerGroups = [
    'Dashboard' => ['overview', 'announcements'],
    'People & Accounts' => ['users', 'staff', 'residents'],
    'Clinical Services' => ['consultations', 'maternal', 'vaccination', 'disease'],
    'Records & Resources' => ['medicine', 'vital'],
    'Governance' => ['reports', 'audit', 'system', 'security'],
];

function tabUrl(string $tab, bool $notifs = false, array $extra = []): string
{
    $params = ['tab' => $tab];
    if ($notifs) {
        $params['notifs'] = '1';
    }
    foreach ($extra as $key => $value) {
        if ($value !== null) {
            $params[$key] = $value;
        }
    }
    return '?' . http_build_query($params);
}

function renderMetricCard(string $label, mixed $value, string $sub, string $icon, string $iconClass, string $textColor): void
{
    echo '<div class="bg-white rounded-xl p-4 shadow-sm border border-gray-100">';
    echo '<div class="w-9 h-9 ' . esc($iconClass) . ' rounded-lg flex items-center justify-center mb-3 text-white">' . iconSvg($icon, 'w-4.5 h-4.5') . '</div>';
    echo '<p class="text-2xl font-black text-gray-900">' . esc($value) . '</p>';
    echo '<p class="text-sm font-bold text-gray-700">' . esc($label) . '</p>';
    echo '<p class="text-xs text-gray-400">' . esc($sub) . '</p>';
    echo '</div>';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="manifest" href="manifest.webmanifest?v=20260916">
    <meta name="theme-color" content="#0f766e">
    <link rel="apple-touch-icon" href="pwa-icon-192.png?v=20260916">
    <link rel="icon" href="pwa-icon-192.png?v=20260916" type="image/png" sizes="192x192">
    <link rel="shortcut icon" href="pwa-icon-192.png?v=20260916" type="image/png">
    <script src="pwa-install.js?v=20260916" defer></script>
    <title>Unified RHU Dashboard - ResiHUnity RHU</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIINfQ1p8ff9Mnw9x2D2JOGw7trrRnhQh9g=" crossorigin="">
    <script defer src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <style>
        html {
            scroll-behavior: auto;
        }

        body {
            overflow-x: hidden;
        }

        #nasugbuHealthMap {
            display: block;
            height: clamp(560px, 68vh, 760px) !important;
            min-height: 560px;
            width: 100% !important;
            z-index: 0;
            position: relative;
            border-radius: 0.75rem;
            overflow: hidden;
        }

        #nasugbuHealthMap.leaflet-container {
            overflow: hidden;
            position: relative;
            outline-style: none;
            background: #dbeafe;
            font-family: inherit;
        }

        #nasugbuHealthMap .leaflet-pane,
        #nasugbuHealthMap .leaflet-tile,
        #nasugbuHealthMap .leaflet-marker-icon,
        #nasugbuHealthMap .leaflet-marker-shadow,
        #nasugbuHealthMap .leaflet-tile-container,
        #nasugbuHealthMap .leaflet-pane>svg,
        #nasugbuHealthMap .leaflet-pane>canvas,
        #nasugbuHealthMap .leaflet-zoom-box,
        #nasugbuHealthMap .leaflet-image-layer,
        #nasugbuHealthMap .leaflet-layer {
            position: absolute;
            left: 0;
            top: 0;
        }

        #nasugbuHealthMap .leaflet-map-pane,
        #nasugbuHealthMap .leaflet-tile-pane,
        #nasugbuHealthMap .leaflet-overlay-pane,
        #nasugbuHealthMap .leaflet-shadow-pane,
        #nasugbuHealthMap .leaflet-marker-pane,
        #nasugbuHealthMap .leaflet-tooltip-pane,
        #nasugbuHealthMap .leaflet-popup-pane {
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
        }

        #nasugbuHealthMap .leaflet-tile,
        #nasugbuHealthMap .leaflet-marker-icon,
        #nasugbuHealthMap .leaflet-marker-shadow {
            user-select: none;
            -webkit-user-drag: none;
        }

        #nasugbuHealthMap .leaflet-tile-container {
            pointer-events: none;
        }

        #nasugbuHealthMap .leaflet-control-container .leaflet-top,
        #nasugbuHealthMap .leaflet-control-container .leaflet-bottom {
            position: absolute;
            z-index: 1000;
            pointer-events: none;
        }

        #nasugbuHealthMap .leaflet-top {
            top: 0;
        }

        #nasugbuHealthMap .leaflet-right {
            right: 0;
        }

        #nasugbuHealthMap .leaflet-bottom {
            bottom: 0;
        }

        #nasugbuHealthMap .leaflet-left {
            left: 0;
        }

        #nasugbuHealthMap .leaflet-container {
            width: 100%;
            height: 100%;
            background: #f8fafc;
            font-family: inherit;
        }

        #nasugbuHealthMap .leaflet-tile {
            width: 256px !important;
            height: 256px !important;
            max-width: none !important;
            max-height: none !important;
        }

        #nasugbuHealthMap img.leaflet-tile,
        #nasugbuHealthMap .leaflet-marker-icon,
        #nasugbuHealthMap .leaflet-marker-shadow {
            max-width: none !important;
            max-height: none !important;
        }

        #nasugbuHealthMap .leaflet-tile-pane {
            filter: contrast(92%) brightness(103%) saturate(0.75);
            opacity: 1;
        }

        #nasugbuHealthMap .leaflet-popup-content-wrapper {
            border-radius: 12px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.15);
            border: 1px solid #e2e8f0;
        }

        #nasugbuHealthMap .leaflet-tooltip {
            border-radius: 8px;
            font-weight: 700;
            font-size: 11px;
            padding: 4px 8px;
            border: 1px solid #cbd5e1;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            background: #ffffff;
            color: #0f172a;
        }

        #nasugbuHealthMap .leaflet-interactive {
            cursor: pointer;
            pointer-events: auto;
        }

        #nasugbuHealthMap .nasugbu-dot-marker {
            align-items: center;
            background: #10b981;
            border: 3px solid #ffffff;
            border-radius: 999px;
            box-shadow: 0 3px 10px rgba(15, 23, 42, .28);
            cursor: pointer;
            display: flex;
            height: 18px;
            justify-content: center;
            pointer-events: auto;
            transition: transform .12s ease, background-color .12s ease, box-shadow .12s ease;
            width: 18px;
        }

        #nasugbuHealthMap .nasugbu-dot-marker:hover,
        #nasugbuHealthMap .nasugbu-dot-marker.is-hovered {
            background: #059669;
            box-shadow: 0 6px 16px rgba(5, 150, 105, .45);
            transform: scale(1.22);
        }

        #nasugbuHealthMap .nasugbu-dot-marker.is-hotspot {
            background: #ef4444;
            height: 28px;
            width: 28px;
            box-shadow: 0 8px 24px rgba(239, 68, 68, .42);
        }

        #nasugbuHealthMap .nasugbu-dot-marker.is-hotspot:hover,
        #nasugbuHealthMap .nasugbu-dot-marker.is-hotspot.is-hovered {
            background: #dc2626;
            box-shadow: 0 10px 28px rgba(220, 38, 38, .55);
            transform: scale(1.16);
        }

        .dashboard-header {
            transition: box-shadow .28s ease, transform .28s ease;
            will-change: box-shadow;
        }

        .dashboard-header.is-scrolled {
            box-shadow: 0 16px 35px -22px rgba(15, 23, 42, .75);
        }

        .feature-drawer-backdrop {
            opacity: 0;
            pointer-events: none;
            transition: opacity .28s ease;
        }

        .feature-drawer-backdrop.is-open {
            opacity: 1;
            pointer-events: auto;
        }

        .feature-drawer {
            transform: translateX(-102%);
            transition: transform .34s cubic-bezier(.2, .75, .25, 1);
            will-change: transform;
            height: 100vh;
            max-height: 100vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .feature-drawer.is-open {
            transform: translateX(0);
        }

        .feature-drawer nav {
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: thin;
            scrollbar-color: rgba(147, 51, 234, 0.3) transparent;
        }

        .feature-drawer nav::-webkit-scrollbar {
            width: 5px;
        }

        .feature-drawer nav::-webkit-scrollbar-thumb {
            background-color: rgba(147, 51, 234, 0.3);
            border-radius: 9999px;
        }

        .feature-drawer a svg {
            transition: transform .2s ease;
        }

        .feature-drawer a:hover svg {
            transform: scale(1.08);
        }

        body.drawer-open {
            overflow: hidden;
        }

        main>div,
        main>section,
        main .bg-white.rounded-xl,
        main .bg-white.rounded-2xl,
        main details {
            transition: transform .24s ease, box-shadow .24s ease, opacity .24s ease;
        }

        main .bg-white.rounded-xl:hover,
        main .bg-white.rounded-2xl:hover {
            transform: translateY(-2px);
            box-shadow: 0 14px 32px -24px rgba(15, 23, 42, .55);
        }

        [data-scroll-reveal],
        [data-scroll-reveal].is-visible {
            opacity: 1;
            transform: none;
            transition: none;
            transition-delay: 0ms;
        }

        table tbody tr {
            transition: transform .18s ease, box-shadow .18s ease;
        }

        table tbody tr:not([hidden]):hover {
            position: relative;
            z-index: 1;
            transform: translateX(3px);
            box-shadow: -4px 0 0 currentColor;
        }

        button,
        a,
        input,
        select,
        textarea,
        summary {
            transition:
                transform .18s ease,
                box-shadow .18s ease,
                opacity .18s ease,
                border-color .18s ease;
        }

        button:not(:disabled):active,
        a:active {
            transform: scale(.97);
        }

        input:focus,
        select:focus,
        textarea:focus {
            outline: none;
            box-shadow: 0 0 0 3px rgba(15, 23, 42, .09);
        }

        details[open]>summary {
            margin-bottom: .65rem;
        }

        details>summary::marker {
            transition: transform .2s ease;
        }

        .metric-value-pop {
            animation: none !important;
        }

        @keyframes metricPop {
            from {
                opacity: 0;
                transform: translateY(7px) scale(.94);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .scroll-top-button {
            opacity: 0;
            pointer-events: none;
            transform: translateY(12px) scale(.92);
            transition: opacity .22s ease, transform .22s ease;
        }

        .scroll-top-button.is-visible {
            opacity: 1;
            pointer-events: auto;
            transform: translateY(0) scale(1);
        }

        @media (prefers-reduced-motion: reduce) {
            html {
                scroll-behavior: auto;
            }

            *,
            *::before,
            *::after {
                animation-duration: .01ms !important;
                animation-iteration-count: 1 !important;
                scroll-behavior: auto !important;
                transition-duration: .01ms !important;
            }

            [data-scroll-reveal] {
                opacity: 1;
                transform: none;
            }
        }

        /* Embedded rhu-admin-ui.css */
        :root {
            --admin-brand-50: #ecfdf5;
            --admin-brand-100: #d1fae5;
            --admin-brand-200: #a7f3d0;
            --admin-brand-500: #10b981;
            --admin-brand-600: #059669;
            --admin-brand-700: #047857;
            --admin-brand-800: #065f46;
            --admin-brand-900: #064e3b;
            --admin-ink: #0f172a;
            --admin-muted: #64748b;
            --admin-line: #e2e8f0;
            --admin-canvas: #f6f9f8;
        }

        body.rhu-admin-ui {
            min-width: 320px;
            background:
                linear-gradient(135deg, rgba(214, 247, 220, .88), rgba(200, 239, 205, .84) 48%, rgba(228, 245, 235, .92)),
                url("../../../assets/admin-municipal-background.png") center / cover fixed no-repeat,
                var(--admin-canvas);
            color: var(--admin-ink);
        }

        body.rhu-admin-ui::before {
            position: fixed;
            z-index: 0;
            inset: 0;
            pointer-events: none;
            content: "";
            background:
                radial-gradient(circle at 12% 8%, rgba(255, 255, 255, .64), transparent 30rem),
                radial-gradient(circle at 80% 30%, rgba(142, 201, 120, .28), transparent 26rem),
                linear-gradient(to bottom, rgba(11, 72, 48, .14), rgba(6, 78, 59, .08));
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
        }

        .rhu-admin-ui>.min-h-screen {
            position: relative;
            z-index: 1;
        }

        .rhu-admin-ui .admin-shell-header {
            background: linear-gradient(105deg, var(--admin-brand-900), var(--admin-brand-800) 55%, #155e75) !important;
            border-bottom: 1px solid rgba(167, 243, 208, .22);
            box-shadow: 0 10px 30px rgba(15, 23, 42, .14);
        }

        .rhu-admin-ui .admin-brand-logo {
            width: 42px;
            height: 42px;
            padding: 2px;
            border: 2px solid rgba(255, 255, 255, .72);
            border-radius: 12px;
            object-fit: contain;
            background: #fff;
            box-shadow: 0 7px 18px rgba(15, 23, 42, .2);
        }

        .rhu-admin-ui .admin-shell-header [class*="bg-purple-"],
        .rhu-admin-ui .admin-shell-header [class*="text-purple-"] {
            border-color: rgba(255, 255, 255, .2) !important;
            background-color: rgba(255, 255, 255, .13) !important;
            color: #fff !important;
        }

        .rhu-admin-ui .admin-feature-drawer {
            border-color: var(--admin-line);
        }

        .rhu-admin-ui .admin-feature-drawer>div:first-child {
            background: linear-gradient(135deg, var(--admin-brand-50), #fff);
        }

        .rhu-admin-ui .admin-feature-drawer>div:first-child>div>span {
            background: var(--admin-brand-700) !important;
        }

        .rhu-admin-ui .admin-feature-drawer nav {
            padding: 1rem;
            scrollbar-gutter: stable;
            scrollbar-width: thin;
            scrollbar-color: var(--admin-brand-200) transparent;
        }

        .rhu-admin-ui .admin-feature-drawer nav section {
            margin-bottom: 1.35rem;
        }

        .rhu-admin-ui .admin-feature-drawer nav h2 {
            color: var(--admin-brand-700);
        }

        .rhu-admin-ui .admin-feature-drawer nav a {
            min-height: 3rem;
            border: 1px solid transparent;
        }

        .rhu-admin-ui .admin-feature-drawer nav a:hover {
            border-color: var(--admin-brand-100);
            background: var(--admin-brand-50) !important;
            color: var(--admin-brand-800) !important;
            transform: translateX(3px);
        }

        .rhu-admin-ui .admin-feature-drawer nav a[aria-current="page"] {
            border-color: var(--admin-brand-200);
            background: var(--admin-brand-50) !important;
            color: var(--admin-brand-900) !important;
            box-shadow: inset 3px 0 0 var(--admin-brand-600);
        }

        .rhu-admin-ui .admin-feature-drawer nav a[aria-current="page"]>span:first-child {
            background: var(--admin-brand-700) !important;
        }

        .rhu-admin-ui .admin-main {
            width: min(100%, 1600px);
            max-width: none !important;
            margin-inline: auto;
            padding: 1.25rem clamp(1rem, 2.2vw, 2rem) 2.5rem;
        }

        .rhu-admin-ui .admin-main>section,
        .rhu-admin-ui .admin-main>div {
            position: relative;
        }

        .rhu-admin-ui .admin-page-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: 1.1rem 1.25rem;
            border: 1px solid var(--admin-line);
            border-radius: 1rem;
            background: rgba(255, 255, 255, .9);
            box-shadow: 0 12px 30px rgba(15, 23, 42, .08);
            backdrop-filter: blur(14px);
        }

        .rhu-admin-ui .admin-main .bg-white {
            background-color: rgba(255, 255, 255, .93) !important;
            box-shadow: 0 12px 28px rgba(15, 23, 42, .07);
            backdrop-filter: blur(10px);
        }

        @media (max-width: 767px) {
            body.rhu-admin-ui {
                background-position: 58% center;
                background-attachment: scroll;
            }
        }

        .rhu-admin-ui .admin-page-heading-icon {
            display: grid;
            width: 2.75rem;
            height: 2.75rem;
            flex: 0 0 2.75rem;
            place-items: center;
            border-radius: .85rem;
            background: var(--admin-brand-50);
            color: var(--admin-brand-700);
            box-shadow: inset 0 0 0 1px var(--admin-brand-100);
        }

        .rhu-admin-ui .admin-page-heading h2 {
            font-size: clamp(1.05rem, 2vw, 1.35rem);
            font-weight: 850;
            letter-spacing: -.02em;
        }

        .rhu-admin-ui .admin-page-heading p {
            margin-top: .2rem;
            color: var(--admin-muted);
            font-size: .78rem;
        }

        .rhu-admin-ui .admin-live-badge {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            flex: 0 0 auto;
            padding: .5rem .7rem;
            border: 1px solid var(--admin-brand-200);
            border-radius: 999px;
            background: var(--admin-brand-50);
            color: var(--admin-brand-800);
            font-size: .68rem;
            font-weight: 800;
        }

        .rhu-admin-ui .admin-live-badge::before {
            content: "";
            width: .45rem;
            height: .45rem;
            border-radius: 999px;
            background: var(--admin-brand-500);
            box-shadow: 0 0 0 4px rgba(16, 185, 129, .12);
        }

        .rhu-admin-ui .admin-main :is(.rounded-xl, .rounded-2xl).bg-white {
            border-color: var(--admin-line);
            box-shadow: 0 5px 18px rgba(15, 23, 42, .04);
        }

        .rhu-admin-ui .admin-main :is(.rounded-xl, .rounded-2xl).bg-white:hover {
            transform: translateY(-1px);
            border-color: #cbd5e1;
            box-shadow: 0 10px 25px rgba(15, 23, 42, .07);
        }

        .rhu-admin-ui .admin-main table {
            border-collapse: separate;
            border-spacing: 0;
        }

        .rhu-admin-ui .admin-main table thead {
            position: sticky;
            top: 0;
            z-index: 2;
            background: #f8fafc;
        }

        .rhu-admin-ui .admin-main table th {
            padding-block: .75rem;
            color: #475569;
            font-size: .68rem;
            font-weight: 800;
            letter-spacing: .035em;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .rhu-admin-ui .admin-main table td {
            padding-block: .8rem;
            vertical-align: middle;
        }

        .rhu-admin-ui .admin-main table tbody tr {
            transition: background-color 150ms ease;
        }

        .rhu-admin-ui .admin-main table tbody tr:hover {
            background: #f8fafc;
        }

        .rhu-admin-ui .admin-main .overflow-x-auto {
            border-radius: .8rem;
            scrollbar-width: thin;
            scrollbar-color: var(--admin-brand-200) transparent;
        }

        .rhu-admin-ui .admin-main table+*,
        .rhu-admin-ui .admin-main [class*="border-b"][class*="bg-gray-50"] {
            border-color: var(--admin-line);
        }

        .rhu-admin-ui .admin-main :is(input, select, textarea) {
            min-height: 2.7rem;
            border-color: #cbd5e1;
            background: #fff;
        }

        .rhu-admin-ui .admin-main textarea {
            min-height: 5rem;
        }

        .rhu-admin-ui .admin-main label {
            color: #334155;
            font-weight: 700;
        }

        .rhu-admin-ui :is(button, a):focus-visible {
            outline: 3px solid rgba(16, 185, 129, .32);
            outline-offset: 3px;
        }

        .rhu-admin-ui details {
            border-color: var(--admin-line) !important;
            background: #fff;
        }

        .rhu-admin-ui details summary {
            min-height: 3rem;
        }

        .rhu-admin-ui .scroll-top-button {
            background: var(--admin-brand-700) !important;
            color: #fff !important;
        }

        @media (max-width: 767px) {
            .rhu-admin-ui .admin-shell-header>div {
                padding-block: .65rem;
            }

            .rhu-admin-ui .staff-logout-trigger {
                width: 2.75rem;
                height: 2.75rem;
                padding: 0;
            }

            .rhu-admin-ui .staff-logout-label {
                display: none;
            }

            .rhu-admin-ui .admin-brand-logo {
                width: 38px;
                height: 38px;
            }

            .rhu-admin-ui .admin-main {
                padding-top: .9rem;
            }

            .rhu-admin-ui .admin-page-heading {
                align-items: flex-start;
                padding: .9rem;
            }

            .rhu-admin-ui .admin-live-badge {
                padding: .4rem;
                font-size: 0;
            }

            .rhu-admin-ui .admin-main :is(.rounded-xl, .rounded-2xl).bg-white {
                padding: .9rem;
            }
        }

        @media (max-width: 420px) {
            .rhu-admin-ui .admin-shell-header h1 {
                font-size: .95rem;
            }

            .rhu-admin-ui .admin-shell-header p {
                max-width: 12rem;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            .rhu-admin-ui .admin-page-heading-icon {
                width: 2.4rem;
                height: 2.4rem;
                flex-basis: 2.4rem;
            }
        }

        @media (prefers-reduced-motion: reduce) {

            .rhu-admin-ui *,
            .rhu-admin-ui *::before,
            .rhu-admin-ui *::after {
                scroll-behavior: auto !important;
                transition-duration: .01ms !important;
            }
        }

        /*
         * Unified admin + RHU clinical dashboard skin aligned with the landing page.
         */

        body.rhu-admin-ui {
            --dash-pink: #a7d291;
            --dash-purple: #c6dfab;
            --dash-cyan: #dff5c7;
            --dash-amber: #edf9d4;
            --dash-orange: #8f3f18;
            --dash-ink: #172015;
            --dash-muted: #5f6b58;
            --dash-line: #e5eadf;
            --dash-canvas: #faf9f3;
            --dash-soft: #f4f7ee;
            --dash-deep: #082812;
            background: var(--dash-canvas) !important;
        }

        body.rhu-admin-ui::before {
            background:
                radial-gradient(circle at 88% 10%, rgba(169, 200, 119, .18), transparent 22rem),
                radial-gradient(circle at 8% 78%, rgba(40, 91, 45, .10), transparent 22rem) !important;
        }

        .rhu-admin-ui>.min-h-screen {
            min-height: 100vh;
        }

        .rhu-admin-ui .admin-shell-header {
            top: 0;
            background: linear-gradient(90deg, #0a3d35 0%, #0d4c42 50%, #0a5a52 100%) !important;
            border-bottom: 1px solid rgba(196, 227, 201, 0.24) !important;
            color: #f4faf7 !important;
            box-shadow: 0 10px 28px rgba(7, 41, 34, 0.2) !important;
        }

        .rhu-admin-ui .admin-shell-header,
        .rhu-admin-ui .admin-main {
            transition: margin-left .25s ease, width .25s ease;
        }

        .rhu-admin-ui .admin-shell-header h1,
        .rhu-admin-ui .admin-shell-header p,
        .rhu-admin-ui .admin-shell-header span,
        .rhu-admin-ui .admin-shell-header a,
        .rhu-admin-ui .admin-shell-header button {
            color: #f4faf7 !important;
        }

        .rhu-admin-ui .admin-shell-header [class*="bg-white/10"],
        .rhu-admin-ui .admin-shell-header a,
        .rhu-admin-ui .admin-shell-header button {
            background: rgba(255, 255, 255, 0.06) !important;
            border-color: rgba(232, 247, 238, 0.2) !important;
        }

        .rhu-admin-ui .admin-shell-header .staff-logout-trigger {
            color: #f4faf7 !important;
            background: #f3faf4 !important;
            border-color: rgba(215, 232, 217, 0.28) !important;
            color: #0d4038 !important;
        }

        .rhu-admin-ui .admin-brand-logo {
            border-radius: 50%;
            border: 0;
            box-shadow: none;
        }

        .rhu-admin-ui .admin-feature-drawer {
            background: #fff !important;
            border-right: 1px solid var(--dash-line) !important;
            box-shadow: 12px 0 34px rgba(13, 53, 24, .06) !important;
        }

        .rhu-admin-ui .admin-feature-drawer>div:first-child {
            min-height: 74px;
            background: linear-gradient(135deg, var(--dash-deep), var(--dash-purple)) !important;
            color: #fff !important;
        }

        .rhu-admin-ui .admin-feature-drawer>div:first-child p,
        .rhu-admin-ui .admin-feature-drawer>div:first-child button {
            color: #fff !important;
        }

        .rhu-admin-ui .admin-feature-drawer>div:first-child>div>span {
            background: rgba(255, 255, 255, .18) !important;
        }

        .rhu-admin-ui .admin-feature-drawer nav {
            padding: 1rem .85rem;
        }

        .rhu-admin-ui .admin-feature-drawer nav h2 {
            color: #89957f;
            letter-spacing: 0 !important;
        }

        .rhu-admin-ui .admin-feature-drawer nav a {
            min-height: 2.45rem;
            border-radius: .55rem;
            color: var(--dash-muted) !important;
            font-size: .78rem;
            box-shadow: none !important;
        }

        .rhu-admin-ui .admin-feature-drawer nav a:hover,
        .rhu-admin-ui .admin-feature-drawer nav a[aria-current="page"] {
            background: #eef5e5 !important;
            border-color: transparent !important;
            color: var(--dash-pink) !important;
            transform: none;
            box-shadow: inset 3px 0 0 var(--dash-pink) !important;
        }

        .rhu-admin-ui .admin-feature-drawer nav a>span:first-child,
        .rhu-admin-ui .admin-feature-drawer nav a[aria-current="page"]>span:first-child {
            width: 1.75rem;
            height: 1.75rem;
            background: transparent !important;
            color: currentColor !important;
        }

        .rhu-admin-ui .admin-feature-drawer>div:last-child {
            background: #fff !important;
        }

        .rhu-admin-ui .admin-main {
            width: min(100%, 1420px);
            padding: 1.15rem clamp(.9rem, 1.7vw, 1.5rem) 2rem;
        }

        .rhu-admin-ui .admin-page-heading {
            border-color: var(--dash-line);
            border-radius: .65rem;
            background: #fff;
            box-shadow: 0 10px 22px rgba(13, 53, 24, .055);
        }

        .rhu-admin-ui .admin-page-heading-icon {
            background: linear-gradient(135deg, #eef5e5, #f4f7ee);
            color: var(--dash-pink);
            box-shadow: inset 0 0 0 1px #dfe8d5;
        }

        .rhu-admin-ui .admin-page-heading h2,
        .rhu-admin-ui .admin-main h2,
        .rhu-admin-ui .admin-main h3 {
            color: var(--dash-ink) !important;
            letter-spacing: 0 !important;
        }

        .rhu-admin-ui .admin-page-heading p,
        .rhu-admin-ui .admin-main p,
        .rhu-admin-ui .admin-main .text-gray-500,
        .rhu-admin-ui .admin-main .text-gray-400 {
            color: var(--dash-muted) !important;
        }

        .rhu-admin-ui .admin-live-badge {
            border-color: #dfe8d5;
            background: #f2f6e9;
            color: var(--dash-pink);
        }

        .rhu-admin-ui .admin-live-badge::before {
            background: var(--dash-cyan);
            box-shadow: 0 0 0 4px rgba(169, 200, 119, .18);
        }

        .rhu-admin-ui .admin-main .bg-white,
        .rhu-admin-ui .admin-main :is(.rounded-xl, .rounded-2xl).bg-white {
            border: 1px solid rgba(223, 236, 216, .9) !important;
            border-radius: .65rem !important;
            background: rgba(255, 255, 255, .70) !important;
            backdrop-filter: blur(10px) saturate(1.08) !important;
            -webkit-backdrop-filter: blur(10px) saturate(1.08) !important;
            box-shadow: 0 10px 24px rgba(13, 53, 24, .10) !important;
            color: var(--dash-ink) !important;
        }

        .rhu-admin-ui .admin-main .bg-white>*,
        .rhu-admin-ui .admin-main :is(.rounded-xl, .rounded-2xl).bg-white>* {
            color: inherit !important;
        }

        .rhu-admin-ui .admin-main .grid.grid-cols-2.sm\:grid-cols-4>.bg-white:nth-child(1),
        .rhu-admin-ui .admin-main .grid.grid-cols-2.sm\:grid-cols-4>.bg-white:nth-child(2),
        .rhu-admin-ui .admin-main .grid.grid-cols-2.sm\:grid-cols-4>.bg-white:nth-child(3),
        .rhu-admin-ui .admin-main .grid.grid-cols-2.sm\:grid-cols-4>.bg-white:nth-child(4) {
            color: #fff !important;
            overflow: hidden;
        }

        .rhu-admin-ui .admin-main .grid.grid-cols-2.sm\:grid-cols-4>.bg-white:nth-child(1) {
            background: linear-gradient(135deg, #0d3518, #285b2d) !important;
        }

        .rhu-admin-ui .admin-main .grid.grid-cols-2.sm\:grid-cols-4>.bg-white:nth-child(2) {
            background: linear-gradient(135deg, #285b2d, #4f7a39) !important;
        }

        .rhu-admin-ui .admin-main .grid.grid-cols-2.sm\:grid-cols-4>.bg-white:nth-child(3) {
            background: linear-gradient(135deg, #082812, #35663a) !important;
        }

        .rhu-admin-ui .admin-main .grid.grid-cols-2.sm\:grid-cols-4>.bg-white:nth-child(4) {
            background: linear-gradient(135deg, #285b2d, #8f3f18) !important;
        }

        .rhu-admin-ui .admin-main .grid.grid-cols-2.sm\:grid-cols-4>.bg-white :is(p, span, svg) {
            color: #fff !important;
        }

        .rhu-admin-ui .admin-main .grid.grid-cols-2.sm\:grid-cols-4>.bg-white>div:first-child {
            background: rgba(255, 255, 255, .17) !important;
        }

        .unified-chart-card {
            position: relative;
            min-height: 250px;
            overflow: hidden;
            border-radius: .85rem;
            background: linear-gradient(180deg, #f9faf4 0%, #f2f5ee 100%);
            border: 1px solid rgba(15, 60, 38, 0.08);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.8), 0 12px 22px rgba(22, 58, 31, 0.08);
            animation: chartFadeIn .85s ease-out both;
        }

        .unified-chart-grid {
            position: absolute;
            inset: 0;
            background-image: linear-gradient(to bottom, rgba(37, 79, 52, 0.06), rgba(37, 79, 52, 0));
            animation: gridPulse .7s ease-out both;
        }

        .unified-chart-svg {
            position: absolute;
            inset: 14px 12px 18px 12px;
            width: calc(100% - 24px);
            height: calc(100% - 32px);
            overflow: visible;
        }

        .unified-chart-line {
            fill: none;
            stroke: url(#overviewChartStroke);
            stroke-width: 2.4;
            stroke-linecap: round;
            stroke-linejoin: round;
            filter: drop-shadow(0 6px 12px rgba(27, 78, 45, .18));
            stroke-dasharray: none;
            stroke-dashoffset: 0;
            animation: none;
        }

        .unified-chart-fill {
            fill: rgba(51, 111, 70, 0.68);
            animation: none;
        }

        .unified-chart-soft-fill {
            fill: rgba(169, 212, 147, 0.36);
            animation: none;
        }

        .unified-chart-baseline {
            stroke: rgba(30, 77, 50, .28);
            stroke-width: 1.2;
        }

        .unified-chart-month {
            fill: rgba(76, 86, 76, .78);
            font-size: 10px;
            font-weight: 700;
        }

        .unified-chart-dot {
            position: absolute;
            width: .7rem;
            height: .7rem;
            border: 2px solid #fff;
            border-radius: 999px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, .15);
            animation: none;
        }

        .unified-chart-dot-one {
            left: 58%;
            top: 36%;
            background: var(--dash-amber);
        }

        .unified-chart-dot-two {
            left: 54%;
            top: 58%;
            background: var(--dash-purple);
        }

        .unified-chart-point {
            fill: #dff0cc;
            stroke: #2f7a4b;
            stroke-width: 2;
            cursor: pointer;
            filter: drop-shadow(0 4px 8px rgba(17, 58, 39, 0.18));
            transition: fill .18s ease, transform .18s ease, stroke-width .18s ease;
            transform-origin: center;
        }

        .unified-chart-point.is-active {
            fill: #efd76d;
            stroke: #1c4a32;
            stroke-width: 3;
            transform: scale(1.24);
        }

        .unified-chart-hover-line {
            position: absolute;
            top: 0;
            bottom: 0;
            width: 1px;
            background: linear-gradient(to bottom, rgba(24, 73, 46, 0), rgba(24, 73, 46, 0.7), rgba(24, 73, 46, 0));
            opacity: 0;
            transform: translateX(-50%);
            transition: opacity .15s ease;
            pointer-events: none;
        }

        .unified-chart-hover-line.is-visible {
            opacity: 1;
        }

        .unified-chart-tooltip {
            position: absolute;
            left: 0;
            top: 0;
            transform: translate(-50%, -118%);
            min-width: 112px;
            padding: 8px 10px;
            border-radius: 12px;
            border: 1px solid rgba(14, 59, 34, 0.18);
            background: rgba(13, 44, 26, 0.96);
            color: #f2f7ee;
            box-shadow: 0 16px 28px rgba(8, 28, 17, 0.25);
            opacity: 0;
            pointer-events: none;
            transition: opacity .12s ease, transform .12s ease;
            z-index: 5;
        }

        .unified-chart-tooltip.is-visible {
            opacity: 1;
            transform: translate(-50%, -128%);
        }

        .unified-chart-tooltip-label {
            display: block;
            font-size: 9px;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: rgba(242, 247, 238, 0.7);
        }

        .unified-chart-tooltip-value {
            display: block;
            margin-top: 2px;
            font-size: 15px;
            font-weight: 800;
            line-height: 1.2;
            color: #fefefe;
        }

        .unified-donut {
            width: min(168px, 46vw);
            aspect-ratio: 1;
            border-radius: 50%;
            background: conic-gradient(#8bb36a 0 var(--users-pct, 35%),
                    #a7d291 var(--users-pct, 35%) calc(var(--users-pct, 35%) + var(--staff-pct, 40%)),
                    #dceeb9 calc(var(--users-pct, 35%) + var(--staff-pct, 40%)) calc(var(--users-pct, 35%) + var(--staff-pct, 40%) + var(--bhw-pct, 25%)),
                    #edf5d1 calc(var(--users-pct, 35%) + var(--staff-pct, 40%) + var(--bhw-pct, 25%)) 100%);
            position: relative;
            transform: scale(0.92);
            animation: donutReveal .9s cubic-bezier(.2, .7, .2, 1) both;
            box-shadow: inset 0 0 0 1px rgba(0, 0, 0, .04);
        }

        .unified-donut::after {
            content: "";
            position: absolute;
            inset: 23%;
            border-radius: inherit;
            background: #fff;
            box-shadow: inset 0 0 0 1px rgba(17, 24, 39, .04);
        }

        .overview-card-polished {
            border: 1px solid rgba(195, 215, 200, .85) !important;
            background: rgba(255, 255, 255, .84) !important;
            box-shadow: 0 18px 42px rgba(28, 64, 48, .12), inset 0 1px 0 rgba(255, 255, 255, .72) !important;
        }

        .official-certificate-template {
            position: relative;
            overflow: hidden;
            margin: 0 auto;
            width: min(100%, 760px);
            min-height: 1040px;
            border: 0;
            background: #fff;
            padding: 58px 68px 44px;
            color: #050505;
            font-family: Arial, Helvetica, sans-serif;
            line-height: 1.45;
            box-shadow: 0 1px 0 rgba(15, 23, 42, .08);
        }

        .official-certificate-template .cert-header {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: 112px 1fr 112px;
            align-items: center;
            text-align: center;
            margin-bottom: 10px;
        }

        .official-certificate-template .cert-seal {
            width: 96px;
            height: 96px;
            object-fit: contain;
            justify-self: center;
        }

        .official-certificate-template .cert-watermark {
            position: absolute;
            z-index: 0;
            left: 50%;
            top: 285px;
            width: 560px;
            height: 560px;
            transform: translateX(-50%);
            object-fit: contain;
            opacity: .1;
            pointer-events: none;
        }

        .official-certificate-template .cert-header-copy {
            font-size: 11px;
            line-height: 1.2;
        }

        .official-certificate-template .cert-header-copy p {
            margin: 0;
        }

        .official-certificate-template .cert-republic {
            font-family: Georgia, "Times New Roman", serif;
            font-style: italic;
            font-size: 13px;
        }

        .official-certificate-template .cert-rule {
            position: relative;
            z-index: 1;
            border-top: 2px solid #111;
            border-bottom: 1px solid #111;
            height: 4px;
            margin: 6px 0 42px;
        }

        .official-certificate-template h1,
        .official-certificate-template h2,
        .official-certificate-template h3 {
            position: relative;
            z-index: 1;
            margin: 5px 0;
            text-align: center;
            font-weight: 900;
            text-transform: uppercase;
        }

        .official-certificate-template h1 {
            font-size: 18px;
            letter-spacing: 0;
        }

        .official-certificate-template h2 {
            font-size: 21px;
            font-style: italic;
        }

        .official-certificate-template h3 {
            font-size: 28px;
            letter-spacing: 0;
            margin-bottom: 2px;
        }

        .official-certificate-template .cert-no {
            position: relative;
            z-index: 1;
            text-align: center;
            font-family: "Courier New", monospace;
            font-size: 10px;
            font-weight: 700;
            color: #334155;
            margin: 0 0 44px;
        }

        .official-certificate-template .cert-body {
            position: relative;
            z-index: 1;
            margin: 0;
            font-size: 12px;
            line-height: 1.65;
            text-align: justify;
        }

        .official-certificate-template .cert-body p {
            margin: 0 0 18px;
            text-indent: 34px;
        }

        .official-certificate-template .cert-body .cert-greeting {
            text-indent: 0;
            margin-bottom: 26px;
            text-align: left;
        }

        .official-certificate-template .cert-dates {
            display: flex;
            gap: 34px;
            margin-top: 6px;
            font-size: 10px;
            text-align: left;
        }

        .official-certificate-template .cert-signatures {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 52px;
            margin-top: 118px;
            text-align: center;
        }

        .official-certificate-template .cert-signatures>div {
            min-height: 104px;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            align-items: center;
            font-size: 12px;
        }

        .official-certificate-template .cert-signatures strong {
            display: block;
            min-width: 250px;
            padding-top: 5px;
            font-weight: 900;
            text-transform: uppercase;
        }

        .official-certificate-template .signature-wrap {
            position: relative;
            width: 170px;
            height: 58px;
            margin: 0 auto;
        }

        .official-certificate-template .certificate-signature-image {
            position: absolute;
            left: 50%;
            bottom: 6px;
            transform: translateX(-50%);
            display: block;
            width: 170px;
            height: 52px;
            object-fit: contain;
            object-position: center bottom;
            mix-blend-mode: multiply;
            z-index: 2;
        }

        .official-certificate-template .signature-line {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            height: 1px;
            width: 170px;
            margin: 0;
            border-top: 1px solid #111;
            background: transparent;
        }

        .official-certificate-template small,
        .official-certificate-template .cert-footer {
            display: block;
            color: #111;
            font-size: 10px;
        }

        .official-certificate-template .cert-footer {
            position: absolute;
            z-index: 1;
            left: 68px;
            right: 68px;
            bottom: 40px;
            display: flex;
            justify-content: space-between;
            border-top: 1px solid #64748b;
            padding-top: 6px;
            font-family: "Courier New", monospace;
            color: #0f172a;
        }

        @media (max-width: 820px) {
            .official-certificate-template {
                width: 680px;
                padding: 48px 50px 38px;
            }
        }

        @keyframes drawLine {
            to {
                stroke-dashoffset: 0;
            }
        }

        @keyframes fillRise {
            from {
                opacity: 0;
                transform: translateY(12px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes donutReveal {
            from {
                opacity: 0;
                transform: scale(.75) rotate(-12deg);
            }

            to {
                opacity: 1;
                transform: scale(1) rotate(0deg);
            }
        }

        @keyframes chartFadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes gridPulse {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        @keyframes dotPulse {
            from {
                transform: scale(.92);
            }

            to {
                transform: scale(1.12);
            }
        }

        .staff-folder-panel {
            overflow: hidden;
        }

        .staff-folder-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(96px, 1fr));
            gap: 1rem .85rem;
        }

        .staff-folder-card {
            display: grid;
            justify-items: center;
            align-content: start;
            gap: .28rem;
            min-height: 104px;
            padding: .35rem .25rem .55rem;
            border: 1px solid transparent;
            border-radius: .65rem;
            color: var(--dash-muted) !important;
            text-align: center;
            box-shadow: none !important;
        }

        .staff-folder-card:hover,
        .staff-folder-card.is-active {
            background: #eef5e5 !important;
            border-color: #dfe8d5 !important;
            color: var(--dash-pink) !important;
            transform: translateY(-2px);
        }

        .staff-folder-icon {
            position: relative;
            width: 68px;
            height: 46px;
            margin-bottom: .15rem;
            border-radius: 7px 7px 5px 5px;
            background: linear-gradient(180deg, #d7d9db 0%, #aeb2b4 72%, #989da0 100%);
            box-shadow:
                inset 0 1px 0 rgba(255, 255, 255, .72),
                inset 0 -2px 0 rgba(80, 85, 90, .16),
                0 6px 12px rgba(39, 45, 52, .16);
        }

        .staff-folder-icon::before {
            content: "";
            position: absolute;
            left: 0;
            top: -7px;
            width: 31px;
            height: 11px;
            border-radius: 6px 6px 0 0;
            background: linear-gradient(180deg, #bfc3c5, #a2a7aa);
        }

        .staff-folder-card.is-active .staff-folder-icon,
        .staff-folder-card:hover .staff-folder-icon {
            background: linear-gradient(180deg, #d7efb4 0%, #8fb56f 72%, #285b2d 100%);
        }

        .staff-folder-card.is-active .staff-folder-icon::before,
        .staff-folder-card:hover .staff-folder-icon::before {
            background: linear-gradient(180deg, #e5f4ca, #9fc27b);
        }

        .staff-folder-name {
            max-width: 94px;
            color: inherit !important;
            font-size: .68rem;
            font-weight: 800;
            line-height: 1.1;
        }

        .staff-folder-count {
            color: #8b957f !important;
            font-size: .58rem;
            font-weight: 700;
        }

        .rhu-admin-ui .staff-folder-card.is-active {
            background: #063f2f !important;
            border-color: #b7d78a !important;
            box-shadow: 0 14px 28px rgba(6, 63, 47, .18) !important;
        }

        .rhu-admin-ui .staff-folder-card.is-active .staff-folder-name,
        .rhu-admin-ui .staff-folder-card.is-active .staff-folder-count {
            color: #f4faf0 !important;
            opacity: 1 !important;
        }

        .rhu-admin-ui .staff-folder-card.is-active .staff-folder-icon {
            background: linear-gradient(180deg, #e2f5bf 0%, #a8d47c 65%, #6f9d4f 100%) !important;
            box-shadow:
                inset 0 1px 0 rgba(255, 255, 255, .78),
                inset 0 -2px 0 rgba(8, 40, 18, .16),
                0 8px 16px rgba(4, 33, 23, .28) !important;
        }

        .rhu-admin-ui .staff-folder-card.is-active .staff-folder-icon::before {
            background: linear-gradient(180deg, #ecf8cf, #b9dc90) !important;
        }

        .rhu-admin-ui .admin-main details.mb-4 {
            display: inline-block;
            border: 0 !important;
            border-radius: 0 !important;
            background: transparent !important;
            box-shadow: none !important;
            overflow: visible;
        }

        .rhu-admin-ui .admin-main details.mb-4>summary {
            width: fit-content;
            min-height: 44px;
            padding: 0 .95rem;
            border: 1px solid rgba(13, 53, 24, .22);
            border-radius: 999px;
            background: linear-gradient(135deg, #bfe4b5, var(--dash-pink));
            color: #21472b !important;
            list-style: none;
            justify-content: center;
            gap: .55rem;
            box-shadow: 0 12px 24px rgba(110, 154, 98, .18);
            transition: transform .18s ease, box-shadow .18s ease, filter .18s ease;
        }

        .rhu-admin-ui .admin-main details.mb-4>summary::-webkit-details-marker {
            display: none;
        }

        .rhu-admin-ui .admin-main details.mb-4[open]>summary {
            border-color: rgba(40, 91, 45, .28);
            background: linear-gradient(135deg, var(--dash-purple), var(--dash-deep));
        }

        .rhu-admin-ui .admin-main details.mb-4>summary::before {
            content: "";
            order: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: .48rem;
            height: .48rem;
            border-right: 2px solid currentColor;
            border-bottom: 2px solid currentColor;
            transform: rotate(-45deg);
            transition: transform .18s ease;
        }

        .rhu-admin-ui .admin-main details.mb-4[open]>summary::before {
            content: "";
            transform: rotate(45deg);
        }

        .rhu-admin-ui .admin-main details.mb-4>summary:hover::before {
            color: inherit;
        }

        .rhu-admin-ui .admin-main details.mb-4>summary:hover {
            filter: brightness(1.03);
            box-shadow: 0 16px 30px rgba(13, 53, 24, .24);
            transform: translateY(-1px);
        }

        .rhu-admin-ui .admin-main details.mb-4>summary>span {
            font-size: .9rem;
            font-weight: 900;
            min-width: 0;
        }

        .rhu-admin-ui .admin-main details.mb-4>summary>span:first-child {
            display: none;
        }

        .rhu-admin-ui .admin-main details.mb-4>summary>span:first-child::before {
            content: "";
            width: .45rem;
            height: .45rem;
            border-right: 2px solid currentColor;
            border-bottom: 2px solid currentColor;
            transform: rotate(-45deg);
            transition: transform .18s ease;
        }

        .rhu-admin-ui .admin-main details.mb-4[open]>summary>span:first-child::before {
            transform: rotate(45deg);
        }

        .rhu-admin-ui .admin-main details.mb-4>summary>span:last-child {
            order: 1;
            flex: 0 1 auto;
        }

        .rhu-admin-ui .admin-main details.mb-4>div {
            width: min(100%, 100vw - 2rem);
            margin-top: .9rem;
            padding: 1rem;
            border: 1px solid var(--dash-line);
            border-radius: .85rem;
            background: #fff;
            box-shadow: 0 12px 28px rgba(13, 53, 24, .055);
        }

        .rhu-admin-ui .admin-main details.mb-4 form {
            border-radius: .75rem;
        }

        .rhu-admin-ui .admin-main details.mb-4 form.grid {
            grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
            gap: .75rem !important;
            padding: 1rem;
            border: 1px solid #dfe8d5;
            background: #fbfaf5;
        }

        .rhu-admin-ui .admin-main details.mb-4 form.flex {
            display: grid !important;
            grid-template-columns: minmax(220px, 1fr) auto;
            align-items: stretch;
            gap: .75rem !important;
            padding: 1rem;
            border: 1px solid #f3d6c4;
            background: #fff8f1;
        }

        .rhu-admin-ui .admin-main details.mb-4 input,
        .rhu-admin-ui .admin-main details.mb-4 select,
        .create-staff-panel input,
        .create-staff-panel select {
            min-height: 44px;
            border: 1px solid #dce4d4 !important;
            border-radius: .55rem !important;
            background: #fff !important;
            color: var(--dash-ink) !important;
            padding: .65rem .75rem !important;
            font-size: .82rem;
        }

        .rhu-admin-ui .admin-main details.mb-4 input:focus,
        .rhu-admin-ui .admin-main details.mb-4 select:focus,
        .create-staff-panel input:focus,
        .create-staff-panel select:focus {
            border-color: var(--dash-pink) !important;
            box-shadow: 0 0 0 3px rgba(40, 91, 45, .14) !important;
        }

        .rhu-admin-ui .admin-main details.mb-4 button,
        .create-staff-panel button[type="submit"] {
            min-height: 44px;
            border-radius: .55rem !important;
            background: var(--dash-pink) !important;
            color: #fff !important;
            font-weight: 900 !important;
            box-shadow: 0 10px 18px rgba(13, 53, 24, .18) !important;
        }

        .rhu-admin-ui .admin-main details.mb-4 form.flex button {
            background: var(--dash-pink) !important;
            box-shadow: 0 10px 18px rgba(13, 53, 24, .18) !important;
        }

        .create-staff-panel {
            padding: 0 !important;
            overflow: hidden;
        }

        .create-staff-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: 1rem 1.1rem;
            border-bottom: 1px solid var(--dash-line);
            background: linear-gradient(90deg, #f4f7ee, #fff);
        }

        .create-staff-heading>span {
            display: grid;
            width: 2.4rem;
            height: 2.4rem;
            place-items: center;
            border-radius: .75rem;
            background: #eef5e5;
            color: var(--dash-pink);
        }

        .create-staff-form {
            padding: 1rem !important;
        }

        .create-staff-form label {
            display: grid !important;
            gap: .4rem;
            color: var(--dash-muted) !important;
            font-size: .72rem;
            font-weight: 900 !important;
            text-transform: uppercase;
        }

        .create-staff-form .sm\:col-span-2 {
            padding-top: .4rem !important;
            border-top: 1px solid var(--dash-line);
        }

        .create-staff-form a {
            border-radius: .55rem !important;
            background: #f4f7ee !important;
            color: var(--dash-muted) !important;
        }

        .rhu-admin-ui table thead {
            background: var(--dash-deep) !important;
        }

        .rhu-admin-ui table th {
            color: #fff !important;
        }

        .rhu-admin-ui .admin-main table tbody tr:hover {
            background: #f4f7ee !important;
        }

        .rhu-admin-ui .admin-main :is(button, .rounded.bg-purple-700, .bg-purple-700, .bg-blue-600, .bg-indigo-600) {
            background-color: var(--dash-pink) !important;
        }

        .rhu-admin-ui :is(.bg-purple-50,
            .bg-purple-100,
            .bg-blue-50,
            .bg-blue-100,
            .bg-indigo-100,
            .bg-emerald-50,
            .bg-emerald-100,
            .bg-green-50,
            .bg-green-100,
            .bg-pink-50,
            .bg-pink-100,
            .bg-red-50,
            .bg-red-100,
            .bg-amber-50,
            .bg-amber-100) {
            background-color: var(--dash-soft) !important;
        }

        .rhu-admin-ui :is(.text-purple-600,
            .text-purple-700,
            .text-purple-800,
            .text-purple-900,
            .text-purple-950,
            .text-blue-600,
            .text-blue-700,
            .text-blue-800,
            .text-blue-900,
            .text-indigo-800,
            .text-emerald-600,
            .text-emerald-700,
            .text-emerald-800,
            .text-emerald-900,
            .text-green-600,
            .text-green-700,
            .text-green-800,
            .text-pink-600,
            .text-red-600,
            .text-red-700,
            .text-red-800,
            .text-amber-700,
            .text-amber-800) {
            color: var(--dash-pink) !important;
        }

        .rhu-admin-ui :is(.border-purple-100,
            .border-purple-200,
            .border-purple-600,
            .border-blue-200,
            .border-emerald-200,
            .border-green-200,
            .border-red-100,
            .border-red-200,
            .border-amber-200) {
            border-color: var(--dash-line) !important;
        }

        .rhu-admin-ui :is(button.bg-purple-500,
            button.bg-purple-600,
            button.bg-purple-700,
            button.bg-purple-800,
            button.bg-blue-600,
            button.bg-blue-700,
            button.bg-indigo-600,
            button.bg-indigo-700,
            button.bg-emerald-600,
            button.bg-emerald-700,
            button.bg-green-600,
            button.bg-red-600,
            button.bg-red-700,
            button.bg-pink-500,
            a.bg-purple-500,
            a.bg-purple-600,
            a.bg-purple-700,
            a.bg-blue-600,
            a.bg-indigo-600,
            a.bg-emerald-700) {
            background-color: var(--dash-pink) !important;
            color: #fff !important;
            box-shadow: 0 10px 18px rgba(13, 53, 24, .16) !important;
        }

        .rhu-admin-ui :is(button.hover\:bg-purple-800:hover,
            button.hover\:bg-blue-700:hover,
            button.hover\:bg-blue-800:hover,
            button.hover\:bg-indigo-800:hover,
            button.hover\:bg-emerald-700:hover,
            button.hover\:bg-emerald-800:hover,
            button.hover\:bg-green-700:hover,
            button.hover\:bg-red-700:hover,
            button.hover\:bg-red-800:hover,
            a.hover\:bg-purple-800:hover) {
            background-color: var(--dash-purple) !important;
            color: #fff !important;
        }

        .rhu-admin-ui .admin-main,
        .rhu-admin-ui .admin-main :is(td, label, input, select, textarea, li, dd),
        .rhu-admin-ui .admin-page-heading,
        .rhu-admin-ui .admin-feature-drawer {
            color: var(--dash-ink) !important;
        }

        .rhu-admin-ui .admin-main :is(h1, h2, h3, h4, h5, h6, strong, b, th),
        .rhu-admin-ui .admin-shell-header :is(h1, h2, h3, strong, b),
        .rhu-admin-ui .admin-feature-drawer :is(strong, b) {
            color: var(--dash-deep) !important;
        }

        .rhu-admin-ui .admin-main :is(p, small, figcaption),
        .rhu-admin-ui .admin-main :is(.text-xs, .text-sm).text-gray-400,
        .rhu-admin-ui .admin-main :is(.text-xs, .text-sm).text-gray-500,
        .rhu-admin-ui .admin-main :is(.text-xs, .text-sm).text-slate-400,
        .rhu-admin-ui .admin-main :is(.text-xs, .text-sm).text-slate-500,
        .rhu-admin-ui .admin-shell-header p,
        .rhu-admin-ui .admin-feature-drawer nav h2,
        .rhu-admin-ui .admin-feature-drawer>div:last-child {
            color: var(--dash-muted) !important;
        }

        .rhu-admin-ui .admin-main :is(a:not([class*="bg-"]), .font-mono) {
            color: var(--dash-pink) !important;
        }

        .rhu-admin-ui .admin-main :is(.bg-white, .bg-gray-50, .bg-slate-50, .bg-slate-100, .bg-gray-100, .rounded-xl, .rounded-2xl) :is(p, span, td, label, div, a):not(button *):not(.text-white):not([class*="bg-"]) {
            color: inherit;
        }

        .rhu-admin-ui .admin-main :is(.bg-white, .bg-gray-50, .bg-slate-50, .bg-slate-100, .bg-gray-100, .rounded-xl, .rounded-2xl) {
            color: var(--dash-ink) !important;
        }

        .rhu-admin-ui .admin-main :is(.text-gray-300,
            .text-gray-400,
            .text-gray-500,
            .text-slate-300,
            .text-slate-400,
            .text-slate-500,
            .text-purple-200,
            .text-purple-300) {
            color: var(--dash-muted) !important;
        }

        .rhu-admin-ui .admin-main :is(.text-gray-600,
            .text-gray-700,
            .text-gray-800,
            .text-gray-900,
            .text-slate-600,
            .text-slate-700,
            .text-slate-800,
            .text-slate-900) {
            color: var(--dash-ink) !important;
        }

        .rhu-admin-ui,
        .rhu-admin-ui * {
            text-shadow: none !important;
        }

        .rhu-admin-ui .admin-main *,
        .rhu-admin-ui .admin-shell-header *,
        .rhu-admin-ui .admin-feature-drawer nav * {
            color: var(--dash-ink) !important;
        }

        .rhu-admin-ui .admin-main :is(h1, h2, h3, h4, h5, h6, strong, b, th, .font-bold, .font-black, .font-semibold) {
            color: var(--dash-deep) !important;
        }

        .rhu-admin-ui .admin-main :is(p, small, figcaption, .text-xs, .text-sm, .text-gray-400, .text-gray-500, .text-slate-400, .text-slate-500),
        .rhu-admin-ui .admin-shell-header p,
        .rhu-admin-ui .admin-feature-drawer nav h2 {
            color: var(--dash-muted) !important;
        }

        .rhu-admin-ui .admin-main :is(a, .font-mono):not(button):not([class*="bg-"]) {
            color: var(--dash-pink) !important;
        }

        .rhu-admin-ui :is(button,
            a.bg-purple-500,
            a.bg-purple-600,
            a.bg-purple-700,
            a.bg-blue-600,
            a.bg-blue-700,
            a.bg-indigo-600,
            a.bg-indigo-700,
            a.bg-emerald-600,
            a.bg-emerald-700,
            a.bg-green-600,
            a.bg-red-600,
            a.bg-red-700,
            a.bg-pink-500,
            .admin-main details.mb-4 > summary),
        .rhu-admin-ui :is(button,
            a.bg-purple-500,
            a.bg-purple-600,
            a.bg-purple-700,
            a.bg-blue-600,
            a.bg-blue-700,
            a.bg-indigo-600,
            a.bg-indigo-700,
            a.bg-emerald-600,
            a.bg-emerald-700,
            a.bg-green-600,
            a.bg-red-600,
            a.bg-red-700,
            a.bg-pink-500,
            .admin-main details.mb-4 > summary) * {
            color: #fff !important;
        }

        .rhu-admin-ui .admin-main .grid.grid-cols-2.sm\:grid-cols-4>.bg-white,
        .rhu-admin-ui .admin-main .grid.grid-cols-2.sm\:grid-cols-4>.bg-white * {
            color: #fff !important;
        }

        .rhu-admin-ui .admin-feature-drawer>div:first-child,
        .rhu-admin-ui .admin-feature-drawer>div:first-child * {
            color: #fff !important;
        }

        .rhu-admin-ui .admin-main :is(.bg-purple-50,
            .bg-purple-100,
            .bg-blue-50,
            .bg-blue-100,
            .bg-indigo-100,
            .bg-emerald-50,
            .bg-emerald-100,
            .bg-green-50,
            .bg-green-100,
            .bg-pink-50,
            .bg-pink-100,
            .bg-red-50,
            .bg-red-100,
            .bg-amber-50,
            .bg-amber-100,
            .bg-gray-50,
            .bg-gray-100,
            .bg-slate-50,
            .bg-slate-100,
            .bg-white),
        .rhu-admin-ui .admin-main :is(.bg-purple-50,
            .bg-purple-100,
            .bg-blue-50,
            .bg-blue-100,
            .bg-indigo-100,
            .bg-emerald-50,
            .bg-emerald-100,
            .bg-green-50,
            .bg-green-100,
            .bg-pink-50,
            .bg-pink-100,
            .bg-red-50,
            .bg-red-100,
            .bg-amber-50,
            .bg-amber-100,
            .bg-gray-50,
            .bg-gray-100,
            .bg-slate-50,
            .bg-slate-100,
            .bg-white) *:not(button):not(button *) {
            color: var(--dash-ink) !important;
        }

        .rhu-admin-ui :is(button.text-red-600, button.text-red-700, button.text-red-800, a.text-red-600, a.text-red-700, a.text-red-800):not([class*="bg-"]) {
            color: var(--dash-pink) !important;
        }

        .rhu-admin-ui .admin-main :is(input::placeholder, textarea::placeholder) {
            color: #65745d !important;
            opacity: 1 !important;
        }

        .rhu-admin-ui .admin-main .grid.grid-cols-2.sm\:grid-cols-4>.bg-white,
        .rhu-admin-ui .admin-main .grid.grid-cols-2.sm\:grid-cols-4>.bg-white *,
        .rhu-admin-ui table thead,
        .rhu-admin-ui table thead *,
        .rhu-admin-ui .admin-feature-drawer>div:first-child,
        .rhu-admin-ui .admin-feature-drawer>div:first-child *,
        .rhu-admin-ui :is(button, button *, a[class*="bg-"], a[class*="bg-"] *) {
            color: #fff !important;
        }

        .rhu-admin-ui :is(.bg-white,
            .bg-gray-50,
            .bg-gray-100,
            .bg-slate-50,
            .bg-slate-100,
            .bg-purple-50,
            .bg-purple-100,
            .bg-blue-50,
            .bg-blue-100,
            .bg-indigo-100,
            .bg-emerald-50,
            .bg-emerald-100,
            .bg-green-50,
            .bg-green-100,
            .bg-pink-50,
            .bg-pink-100,
            .bg-red-50,
            .bg-red-100,
            .bg-amber-50,
            .bg-amber-100,
            .rounded-xl,
            .rounded-2xl,
            .admin-page-heading,
            .create-staff-panel),
        .rhu-admin-ui :is(.bg-white,
            .bg-gray-50,
            .bg-gray-100,
            .bg-slate-50,
            .bg-slate-100,
            .bg-purple-50,
            .bg-purple-100,
            .bg-blue-50,
            .bg-blue-100,
            .bg-indigo-100,
            .bg-emerald-50,
            .bg-emerald-100,
            .bg-green-50,
            .bg-green-100,
            .bg-pink-50,
            .bg-pink-100,
            .bg-red-50,
            .bg-red-100,
            .bg-amber-50,
            .bg-amber-100,
            .rounded-xl,
            .rounded-2xl,
            .admin-page-heading,
            .create-staff-panel) *:not(button):not(button *):not(a[class*="bg-"]):not(a[class*="bg-"] *) {
            color: var(--dash-ink) !important;
        }

        .rhu-admin-ui :is(.bg-white,
            .bg-gray-50,
            .bg-gray-100,
            .bg-slate-50,
            .bg-slate-100,
            .bg-purple-50,
            .bg-purple-100,
            .bg-blue-50,
            .bg-blue-100,
            .bg-indigo-100,
            .bg-emerald-50,
            .bg-emerald-100,
            .bg-green-50,
            .bg-green-100,
            .bg-pink-50,
            .bg-pink-100,
            .bg-red-50,
            .bg-red-100,
            .bg-amber-50,
            .bg-amber-100,
            .rounded-xl,
            .rounded-2xl,
            .admin-page-heading,
            .create-staff-panel) :is(p, small, figcaption, .text-xs, .text-sm, .text-gray-300, .text-gray-400, .text-gray-500, .text-slate-300, .text-slate-400, .text-slate-500):not(button):not(button *) {
            color: var(--dash-muted) !important;
        }

        .rhu-admin-ui :is(.bg-black,
            .bg-gray-800,
            .bg-gray-900,
            .bg-slate-800,
            .bg-slate-900,
            .bg-slate-950,
            .bg-green-800,
            .bg-green-900,
            .bg-emerald-800,
            .bg-emerald-900,
            .bg-teal-800,
            .bg-teal-900,
            .bg-cyan-900,
            .from-emerald-800,
            .from-green-900,
            .from-slate-900,
            .via-teal-800,
            .to-cyan-900,
            .to-green-900,
            .to-slate-950,
            table thead),
        .rhu-admin-ui :is(.bg-black,
            .bg-gray-800,
            .bg-gray-900,
            .bg-slate-800,
            .bg-slate-900,
            .bg-slate-950,
            .bg-green-800,
            .bg-green-900,
            .bg-emerald-800,
            .bg-emerald-900,
            .bg-teal-800,
            .bg-teal-900,
            .bg-cyan-900,
            .from-emerald-800,
            .from-green-900,
            .from-slate-900,
            .via-teal-800,
            .to-cyan-900,
            .to-green-900,
            .to-slate-950,
            table thead) * {
            color: #fff !important;
        }

        .rhu-admin-ui :is(.bg-black,
            .bg-gray-800,
            .bg-gray-900,
            .bg-slate-800,
            .bg-slate-900,
            .bg-slate-950,
            .bg-green-800,
            .bg-green-900,
            .bg-emerald-800,
            .bg-emerald-900,
            .bg-teal-800,
            .bg-teal-900,
            .bg-cyan-900,
            .from-emerald-800,
            .from-green-900,
            .from-slate-900,
            .via-teal-800,
            .to-cyan-900,
            .to-green-900,
            .to-slate-950,
            table thead) :is(p, small, span, .text-xs, .text-sm, .text-gray-400, .text-gray-500, .text-slate-400, .text-slate-500, .text-purple-200, .text-purple-300) {
            color: #e9f2df !important;
        }

        .rhu-admin-ui .admin-shell-header,
        .rhu-admin-ui .admin-shell-header * {
            color: var(--dash-ink) !important;
        }

        .rhu-admin-ui .admin-shell-header p,
        .rhu-admin-ui .admin-shell-header :is(.text-xs, .text-sm, .text-gray-400, .text-gray-500, .text-slate-400, .text-slate-500, .text-purple-200, .text-purple-300) {
            color: var(--dash-muted) !important;
        }

        .rhu-admin-ui .admin-main .grid.grid-cols-2.sm\:grid-cols-4>.bg-white,
        .rhu-admin-ui .admin-main .grid.grid-cols-2.sm\:grid-cols-4>.bg-white *,
        .rhu-admin-ui .admin-main [style*="background: linear-gradient"],
        .rhu-admin-ui .admin-main [style*="background: linear-gradient"] *,
        .rhu-admin-ui .admin-main [class*="bg-gradient-to-"],
        .rhu-admin-ui .admin-main [class*="bg-gradient-to-"] * {
            color: #fff !important;
        }

        .rhu-admin-ui .admin-main .grid.grid-cols-2.sm\:grid-cols-4>.bg-white :is(p, small, span, .text-xs, .text-sm),
        .rhu-admin-ui .admin-main [style*="background: linear-gradient"] :is(p, small, span, .text-xs, .text-sm),
        .rhu-admin-ui .admin-main [class*="bg-gradient-to-"] :is(p, small, span, .text-xs, .text-sm) {
            color: #e9f2df !important;
        }

        @media (min-width: 1024px) {
            body.rhu-admin-ui {
                overflow: hidden;
            }

            .rhu-admin-ui>.min-h-screen {
                height: 100vh;
                overflow: hidden;
            }

            .rhu-admin-ui .admin-feature-drawer {
                transform: none !important;
                visibility: visible !important;
                max-width: none;
                width: 220px;
            }

            .rhu-admin-ui .feature-drawer-backdrop,
            .rhu-admin-ui [data-drawer-open],
            .rhu-admin-ui [data-drawer-close] {
                display: none !important;
            }

            .rhu-admin-ui .admin-shell-header {
                margin-left: 220px;
                position: fixed !important;
                right: 0;
                left: 0;
                z-index: 45;
            }

            .rhu-admin-ui .admin-main {
                margin-left: 220px;
                width: calc(100% - 220px);
                max-width: none !important;
                height: calc(100vh - 72px);
                margin-top: 72px;
                overflow-y: auto;
                overflow-x: hidden;
                scrollbar-gutter: stable;
                scrollbar-width: thin;
                scrollbar-color: #dfe8d5 transparent;
            }

            .rhu-admin-ui .admin-main::-webkit-scrollbar,
            .rhu-admin-ui .admin-feature-drawer nav::-webkit-scrollbar {
                width: 8px;
            }

            .rhu-admin-ui .admin-main::-webkit-scrollbar-thumb,
            .rhu-admin-ui .admin-feature-drawer nav::-webkit-scrollbar-thumb {
                border-radius: 999px;
                background: #dfe8d5;
            }

            .rhu-admin-ui .admin-feature-drawer {
                z-index: 50;
            }

            .rhu-admin-ui .admin-feature-drawer nav {
                max-height: calc(100vh - 150px);
            }
        }

        @media (max-width: 767px) {
            body.rhu-admin-ui {
                overflow-x: hidden;
            }

            .rhu-admin-ui .admin-shell-header {
                position: sticky !important;
                z-index: 70;
            }

            .rhu-admin-ui [data-drawer-open] {
                min-height: 44px;
                min-width: 44px;
                position: relative;
                z-index: 80;
                touch-action: manipulation;
            }

            .rhu-admin-ui .admin-feature-drawer {
                width: min(88vw, 340px);
            }

            .rhu-admin-ui .admin-feature-drawer nav {
                max-height: calc(100vh - 150px);
            }

            .rhu-admin-ui .admin-main {
                overflow: visible;
            }

            .unified-chart-card {
                min-height: 180px;
            }

            .rhu-admin-ui .admin-main .grid.grid-cols-2.sm\:grid-cols-4 {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .rhu-admin-ui .admin-main details.mb-4 form.grid,
            .create-staff-form {
                grid-template-columns: 1fr !important;
            }

            .rhu-admin-ui .admin-main details.mb-4 form.flex {
                grid-template-columns: 1fr;
            }

            .create-staff-heading {
                align-items: flex-start;
            }
        }

        @media (min-width: 768px) and (max-width: 1023px) {
            body.rhu-admin-ui {
                overflow-x: hidden;
            }

            .rhu-admin-ui .admin-shell-header {
                position: sticky !important;
            }

            .rhu-admin-ui .admin-feature-drawer {
                width: min(82vw, 360px);
            }

            .rhu-admin-ui .admin-main {
                overflow: visible;
            }

            .rhu-admin-ui .admin-main details.mb-4 form.grid {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            }
        }

        /* Final contrast guard: keep every admin label readable after Tailwind utility clashes. */
        .rhu-admin-ui .admin-main {
            color: var(--dash-ink) !important;
        }

        .rhu-admin-ui .admin-main :is(h1, h2, h3, h4, h5, h6, p, span, div, td, label, li, small, strong, b, input, select, textarea):not(button):not(button *):not(a[class*="bg-"]):not(a[class*="bg-"] *) {
            color: var(--dash-ink) !important;
        }

        .rhu-admin-ui .admin-main :is(.text-xs, .text-sm, .text-gray-400, .text-gray-500, .text-slate-400, .text-slate-500):not(button):not(button *):not(a[class*="bg-"]):not(a[class*="bg-"] *) {
            color: var(--dash-muted) !important;
        }

        .rhu-admin-ui .admin-shell-header,
        .rhu-admin-ui .admin-shell-header * {
            color: var(--dash-ink) !important;
        }

        .rhu-admin-ui .admin-shell-header p,
        .rhu-admin-ui .admin-shell-header .text-xs {
            color: var(--dash-muted) !important;
        }

        .rhu-admin-ui .admin-feature-drawer>div:first-child,
        .rhu-admin-ui .admin-feature-drawer>div:first-child *,
        .rhu-admin-ui table thead,
        .rhu-admin-ui table thead *,
        .rhu-admin-ui .admin-main .grid.grid-cols-2.sm\:grid-cols-4>.bg-white,
        .rhu-admin-ui .admin-main .grid.grid-cols-2.sm\:grid-cols-4>.bg-white *,
        .rhu-admin-ui .admin-main details.mb-4>summary,
        .rhu-admin-ui .admin-main details.mb-4>summary *,
        .rhu-admin-ui :is(button, button *, a.bg-purple-500, a.bg-purple-500 *, a.bg-purple-600, a.bg-purple-600 *, a.bg-purple-700, a.bg-purple-700 *, a.bg-blue-600, a.bg-blue-600 *, a.bg-blue-700, a.bg-blue-700 *, a.bg-indigo-600, a.bg-indigo-600 *, a.bg-indigo-700, a.bg-indigo-700 *, a.bg-emerald-600, a.bg-emerald-600 *, a.bg-emerald-700, a.bg-emerald-700 *, a.bg-green-600, a.bg-green-600 *, a.bg-red-600, a.bg-red-600 *, a.bg-red-700, a.bg-red-700 *, a.bg-pink-500, a.bg-pink-500 *) {
            color: #fff !important;
        }

        .rhu-admin-ui .admin-feature-drawer nav a,
        .rhu-admin-ui .admin-feature-drawer nav a *,
        .rhu-admin-ui .admin-feature-drawer nav a[aria-current="page"],
        .rhu-admin-ui .admin-feature-drawer nav a[aria-current="page"] * {
            color: var(--dash-ink) !important;
        }

        .rhu-admin-ui .admin-feature-drawer nav a[aria-current="page"],
        .rhu-admin-ui .admin-feature-drawer nav a:hover {
            background: #eef5e5 !important;
            border-color: #dfe8d5 !important;
        }

        .rhu-admin-ui .admin-feature-drawer>div:last-child,
        .rhu-admin-ui .admin-feature-drawer>div:last-child * {
            color: var(--dash-muted) !important;
        }

        .rhu-admin-ui .admin-feature-drawer>div:last-child :is(strong, b, a) {
            color: var(--dash-ink) !important;
        }

        .rhu-admin-ui .admin-main table thead,
        .rhu-admin-ui .admin-main table thead tr,
        .rhu-admin-ui .admin-main table thead th,
        .rhu-admin-ui .admin-main table thead td,
        .rhu-admin-ui .admin-main :is(.bg-green-900, .bg-emerald-900, .bg-slate-900, .bg-slate-950, .bg-\[\#082812\], .bg-\[\#0d3518\]) {
            background-color: #d7efb4 !important;
            color: #0d3518 !important;
        }

        .rhu-admin-ui .admin-main table thead *,
        .rhu-admin-ui .admin-main :is(.bg-green-900, .bg-emerald-900, .bg-slate-900, .bg-slate-950, .bg-\[\#082812\], .bg-\[\#0d3518\]) * {
            color: #0d3518 !important;
            fill: #0d3518 !important;
            stroke: #0d3518 !important;
        }

        .rhu-admin-ui .admin-main table thead,
        .rhu-admin-ui .admin-main table thead tr,
        .rhu-admin-ui .admin-main table thead th,
        .rhu-admin-ui .admin-main table thead th *,
        .rhu-admin-ui table thead,
        .rhu-admin-ui table thead tr,
        .rhu-admin-ui table thead th,
        .rhu-admin-ui table thead th * {
            background-color: #d7efb4 !important;
            color: #0d3518 !important;
            opacity: 1 !important;
        }

        .rhu-admin-ui .admin-main table thead th {
            font-weight: 900 !important;
            text-shadow: none !important;
        }

        /* Light landing-page button palette */
        .rhu-admin-ui :is(button,
            a.bg-purple-500,
            a.bg-purple-600,
            a.bg-purple-700,
            a.bg-blue-600,
            a.bg-blue-700,
            a.bg-indigo-600,
            a.bg-indigo-700,
            a.bg-emerald-600,
            a.bg-emerald-700,
            a.bg-green-600,
            a.bg-red-600,
            a.bg-red-700,
            a.bg-pink-500,
            a.bg-amber-50) {
            background-color: #c7e1aa !important;
            border: 1px solid #a7d291 !important;
            color: #0d3518 !important;
            box-shadow: 0 8px 18px rgba(13, 53, 24, .10) !important;
        }

        .rhu-admin-ui :is(button *,
            a.bg-purple-500 *,
            a.bg-purple-600 *,
            a.bg-purple-700 *,
            a.bg-blue-600 *,
            a.bg-blue-700 *,
            a.bg-indigo-600 *,
            a.bg-indigo-700 *,
            a.bg-emerald-600 *,
            a.bg-emerald-700 *,
            a.bg-green-600 *,
            a.bg-red-600 *,
            a.bg-red-700 *,
            a.bg-pink-500 *,
            a.bg-amber-50 *) {
            color: #0d3518 !important;
            fill: #0d3518 !important;
            stroke: #0d3518 !important;
        }

        .rhu-admin-ui :is(button:hover,
            a.bg-purple-500:hover,
            a.bg-purple-600:hover,
            a.bg-purple-700:hover,
            a.bg-blue-600:hover,
            a.bg-blue-700:hover,
            a.bg-indigo-600:hover,
            a.bg-indigo-700:hover,
            a.bg-emerald-600:hover,
            a.bg-emerald-700:hover,
            a.bg-green-600:hover,
            a.bg-red-600:hover,
            a.bg-red-700:hover,
            a.bg-pink-500:hover,
            a.bg-amber-50:hover) {
            background-color: #b7d78a !important;
            border-color: #8fb56f !important;
            color: #082812 !important;
        }

        .rhu-admin-ui .admin-main details.mb-4>summary,
        .rhu-admin-ui .admin-main details.mb-4>summary * {
            background: #d7efb4 !important;
            color: #0d3518 !important;
            fill: #0d3518 !important;
            stroke: #0d3518 !important;
        }

        /* Admin municipal background image */
        body.rhu-admin-ui {
            position: relative;
            background:
                linear-gradient(180deg, rgba(250, 249, 243, .88), rgba(250, 249, 243, .82)),
                url("../../../assets/admin-municipal-background.png") center center / cover fixed no-repeat,
                var(--dash-canvas) !important;
        }

        body.rhu-admin-ui::before {
            position: fixed !important;
            inset: 0 !important;
            z-index: 0 !important;
            content: "" !important;
            pointer-events: none !important;
            background: url("../../../assets/admin-municipal-background.png") center center / cover no-repeat !important;
            filter: saturate(.95) brightness(1.02) !important;
            transform: none !important;
            opacity: .58 !important;
        }

        body.rhu-admin-ui::after {
            position: fixed;
            inset: 0;
            z-index: 0;
            content: "";
            pointer-events: none;
            background:
                linear-gradient(180deg, rgba(250, 249, 243, .88), rgba(250, 249, 243, .82)),
                radial-gradient(circle at 85% 5%, rgba(169, 200, 119, .24), transparent 28rem);
        }

        .rhu-admin-ui>.min-h-screen {
            position: relative;
            z-index: 1;
        }

        html.overflow-hidden,
        body.rhu-admin-ui[data-dashboard-scroll-locked="1"] {
            overflow: hidden !important;
            overscroll-behavior: none !important;
            touch-action: none !important;
        }

        body.rhu-admin-ui[data-dashboard-scroll-locked="1"] [data-certificate-preview-modal],
        body.rhu-admin-ui[data-dashboard-scroll-locked="1"] [data-floating-modal],
        body.rhu-admin-ui[data-dashboard-scroll-locked="1"] [data-report-modal-backdrop] {
            overflow: hidden !important;
            overscroll-behavior: none !important;
            touch-action: none !important;
        }

        /* Final admin button guard */
        .rhu-admin-ui button,
        .rhu-admin-ui button[type="button"],
        .rhu-admin-ui button[type="submit"],
        .rhu-admin-ui .admin-main a[class*="bg-"],
        .rhu-admin-ui .admin-shell-header a[class*="bg-"],
        .rhu-admin-ui .scroll-top-button {
            background: #d7efb4 !important;
            background-color: #d7efb4 !important;
            border: 1px solid #a9c877 !important;
            color: #0d3518 !important;
            box-shadow: 0 8px 18px rgba(13, 53, 24, .10) !important;
        }

        .rhu-admin-ui button *,
        .rhu-admin-ui .admin-main a[class*="bg-"] *,
        .rhu-admin-ui .admin-shell-header a[class*="bg-"] *,
        .rhu-admin-ui .scroll-top-button * {
            color: #0d3518 !important;
            fill: #0d3518 !important;
            stroke: #0d3518 !important;
        }

        .rhu-admin-ui button:hover,
        .rhu-admin-ui .admin-main a[class*="bg-"]:hover,
        .rhu-admin-ui .admin-shell-header a[class*="bg-"]:hover,
        .rhu-admin-ui .scroll-top-button:hover {
            background: #c8e89a !important;
            background-color: #c8e89a !important;
            border-color: #8fb56f !important;
            color: #082812 !important;
        }

        .rhu-admin-ui .admin-feature-drawer>div:first-child button,
        .rhu-admin-ui .admin-feature-drawer>div:first-child button *,
        .rhu-admin-ui .admin-main details.mb-4>summary,
        .rhu-admin-ui .admin-main details.mb-4>summary * {
            color: #0d3518 !important;
            fill: #0d3518 !important;
            stroke: #0d3518 !important;
        }

        /* Staff folder contrast guard */
        .rhu-admin-ui .admin-main .staff-folder-card {
            min-height: 116px;
            padding: .5rem .35rem .7rem;
            text-decoration: none !important;
        }

        .rhu-admin-ui .admin-main .staff-folder-card .staff-folder-name {
            max-width: 100%;
            color: #21331c !important;
            font-size: .72rem;
            line-height: 1.15;
        }

        .rhu-admin-ui .admin-main .staff-folder-card .staff-folder-count {
            color: #5f6b58 !important;
            font-size: .6rem;
            line-height: 1.1;
        }

        .rhu-admin-ui .admin-main .staff-folder-card.is-active,
        .rhu-admin-ui .admin-main .staff-folder-card.is-active:hover {
            background: #063f2f !important;
            border-color: #b7d78a !important;
            color: #f8fff0 !important;
        }

        .rhu-admin-ui .admin-main .staff-folder-card.is-active .staff-folder-name,
        .rhu-admin-ui .admin-main .staff-folder-card.is-active .staff-folder-count {
            color: #f8fff0 !important;
            opacity: 1 !important;
            text-shadow: 0 1px 1px rgba(0, 0, 0, .2) !important;
        }
    </style>
    <link rel="stylesheet" href="dashboard-enhancements.css?v=20260728-admin-ui">
    <style>
        /* No refresh flicker: this admin page should render in-place, not animate in after JS loads. */
        body.rhu-admin-ui,
        body.rhu-admin-ui.dashboard-enhanced,
        body.rhu-admin-ui.staff-dashboard-enhanced {
            animation: none !important;
        }

        body.rhu-admin-ui .dashboard-reveal,
        body.rhu-admin-ui .dashboard-reveal.dashboard-visible,
        body.rhu-admin-ui [data-scroll-reveal],
        body.rhu-admin-ui [data-scroll-reveal].is-visible {
            opacity: 1 !important;
            transform: none !important;
            transition: none !important;
            transition-delay: 0ms !important;
            animation: none !important;
        }

        body.rhu-admin-ui .unified-chart-card,
        body.rhu-admin-ui .unified-chart-grid,
        body.rhu-admin-ui .unified-chart-line,
        body.rhu-admin-ui .unified-chart-fill,
        body.rhu-admin-ui .unified-chart-soft-fill,
        body.rhu-admin-ui .unified-donut,
        body.rhu-admin-ui .metric-value-pop {
            animation: none !important;
        }
    </style>
    <script defer src="dashboard-enhancements.js?v=20260726-controls3"></script>
</head>

<body class="rhu-admin-ui min-h-screen bg-gray-50 text-gray-900">
    <div class="min-h-screen flex flex-col">
        <!-- HEADER -->
        <header class="admin-shell-header dashboard-header sticky top-0 z-40 border-b border-[#0a332b]/80 bg-[#0b3c35] text-[#f4faf7] shadow-[0_14px_30px_rgba(2,28,23,0.22)]">
            <div class="px-4 py-3 sm:px-5">
                <div class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-3 min-w-0">
                        <button type="button" data-drawer-open class="relative z-80 flex h-11 w-11 shrink-0 items-center justify-center rounded-lg border border-[#d6e9df]/20 bg-white/0 text-[#f4faf7] transition hover:bg-white/5 focus:outline-none focus:ring-2 focus:ring-white/30" aria-label="Open feature drawer" aria-controls="feature-drawer" aria-expanded="false">
                            <?php echo iconSvg('menu', 'w-4 h-4'); ?>
                        </button>
                        <div class="flex items-center gap-2 text-[#edf7f2]">
                            <span class="flex h-6 w-6 items-center justify-center rounded-full border border-[#e8f3d8] bg-[#dfeecb] text-[#0b3b2f]">
                                <?php echo iconSvg('shield', 'w-3.5 h-3.5'); ?>
                            </span>
                            <span class="text-[11px] font-black uppercase tracking-[0.17em] text-[#f5f5f2]">Admin Panel</span>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 sm:gap-3">
                        <?php echo portalRenderNotificationButton(); ?>
                        <?php
                        $currentAdminName = trim(($_SESSION['user']['first_name'] ?? '') . ' ' . ($_SESSION['user']['last_name'] ?? '')) ?: $rhuProfile['mho_name'];
                        $currentAdminCode = $_SESSION['user']['admin_code'] ?? 'ADM-2026-0001';
                        $currentDesignation = $_SESSION['user']['designation'] ?? 'Municipal Health Officer';
                        ?>
                        <div class="flex items-center gap-3 rounded-2xl border border-[#dbeadf]/20 bg-[#d9f0d0]/10 px-2.5 py-1.5 shadow-sm" title="Admin ID: <?php echo esc($currentAdminCode); ?> | <?php echo esc($currentDesignation); ?>">
                            <div class="flex h-9 w-9 items-center justify-center rounded-full bg-[#dceec4] text-sm font-black text-[#0b3b2f] shadow-inner">
                                <?php echo esc(strtoupper(substr($currentAdminName, 0, 1))); ?>
                            </div>
                            <div class="hidden min-[420px]:block text-left leading-none text-[#f5f7f5]">
                                <p class="text-[12px] font-bold"><?php echo esc($currentAdminName); ?></p>
                                <p class="mt-0.5 text-[10px] text-[#dfeee7]"><?php echo esc($currentAdminCode); ?></p>
                            </div>
                        </div>
                        <a href="RHUAdminDashboard.php?logout=1" data-staff-logout class="staff-logout-trigger inline-flex shrink-0 items-center justify-center gap-2 rounded-xl border border-[#dbeadf]/30 bg-[#f3faf4] px-3 py-2 text-xs font-bold text-[#0c3a32] shadow-sm transition hover:bg-white focus:outline-none focus:ring-2 focus:ring-white/40" aria-label="Sign out" title="Log out">
                            <?php echo iconSvg('logout', 'w-4 h-4'); ?><span class="staff-logout-label">Log out</span>
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <div data-drawer-backdrop class="feature-drawer-backdrop fixed inset-0 z-50 hidden bg-slate-950/40" aria-hidden="true"></div>
        <aside id="feature-drawer" data-feature-drawer class="admin-feature-drawer feature-drawer fixed inset-y-0 left-0 z-60 flex h-full max-h-screen w-[88vw] max-w-sm flex-col border-r border-gray-200 bg-white shadow-2xl overflow-hidden" aria-label="RHU Admin features" aria-hidden="true">
            <div class="relative flex items-center justify-between overflow-hidden border-b border-white/20 p-4 shrink-0 bg-white">
                <div class="absolute inset-0 bg-cover bg-center" style="background-image: url('../../../assets/admin-municipal-background.png'); filter: saturate(1.15) brightness(0.68);"></div>
                <div class="absolute inset-0 bg-linear-to-r from-[#0d3518]/80 via-[#173f2d]/75 to-[#2f6f49]/60"></div>
                <div class="relative z-10 flex items-center gap-3">
                    <span class="flex h-12 w-12 items-center justify-center rounded-full border border-white/25 bg-white/10 backdrop-blur-sm shadow-lg">
                        <img src="nasugbu_seal.png" alt="Nasugbu Seal" class="h-15 w-15 object-contain" />
                    </span>
                    <div class="text-white" style="color: #fff !important;">
                        <p class="text-lg font-black leading-tight text-white drop-shadow-[0_2px_2px_rgba(0,0,0,0.55)]" style="color: #fff !important;">RURAL HEALTH UNIT</p>
                        <p class="text-[11px] font-semibold text-white/95 drop-shadow-[0_1px_2px_rgba(0,0,0,0.55)]" style="color: #fff !important;"><?php echo esc($tabLabelMap[$tab] ?? 'Dashboard'); ?></p>
                    </div>
                </div>
                <button type="button" data-drawer-close class="relative z-10 flex h-9 w-9 items-center justify-center rounded-lg border border-white/25 bg-white/10 text-white hover:bg-white/20" aria-label="Close feature drawer"><?php echo iconSvg('close', 'w-5 h-5'); ?></button>
            </div>
            <nav class="flex-1 overflow-y-auto min-h-0 p-3 overscroll-contain">
                <?php foreach ($drawerGroups as $groupLabel => $groupTabs): ?>
                    <section class="mb-4">
                        <h2 class="mb-1.5 px-3 text-[10px] font-black uppercase tracking-[0.16em] text-gray-400"><?php echo esc($groupLabel); ?></h2>
                        <div class="space-y-1">
                            <?php foreach ($groupTabs as $key): ?>
                                <?php $active = $tab === $key; ?>
                                <a href="<?php echo esc(tabUrl($key)); ?>" class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold <?php echo $active ? 'bg-purple-100 text-purple-900 shadow-sm' : 'text-gray-700 hover:bg-gray-50'; ?>" <?php echo $active ? 'aria-current="page"' : ''; ?>>
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg <?php echo $active ? 'bg-purple-700 text-white' : 'bg-gray-100 text-gray-600'; ?>"><?php echo iconSvg($tabIconMap[$key], 'w-4 h-4'); ?></span>
                                    <span class="flex-1"><?php echo esc($tabLabelMap[$key]); ?></span>
                                    <?php if ($active): ?><span aria-hidden="true"><?php echo iconSvg('right', 'w-3.5 h-3.5'); ?></span><?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            </nav>
            <div class="border-t border-gray-100 p-3 shrink-0 bg-white">
                <a href="RHUAdminDashboard.php?logout=1" data-staff-logout class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50"><span class="flex h-8 w-8 items-center justify-center rounded-lg bg-gray-100"><?php echo iconSvg('logout', 'w-4 h-4'); ?></span>Sign Out</a>
            </div>
        </aside>

        <!-- MAIN CONTENT AREA -->
        <main class="admin-main flex-1 max-w-7xl mx-auto w-full px-3 sm:px-4 py-4 sm:py-6 space-y-4 sm:space-y-5 pb-6">
            <?php if ($flashSuccess): ?>
                <div class="rounded-xl border border-green-200 bg-green-50 p-4 text-sm font-semibold text-green-800 flex items-center justify-between shadow-sm">
                    <span class="flex items-center gap-2"><?php echo iconSvg('check', 'w-4 h-4 shrink-0'); ?> <?php echo esc($flashSuccess); ?></span>
                    <button onclick="this.parentElement.remove()" class="text-green-600 hover:text-green-800 font-bold">&times;</button>
                </div>
            <?php endif; ?>
            <?php if ($flashError): ?>
                <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-800 flex items-center justify-between shadow-sm">
                    <span class="flex items-center gap-2"><?php echo iconSvg('alert', 'w-4 h-4 shrink-0'); ?> <?php echo esc($flashError); ?></span>
                    <button onclick="this.parentElement.remove()" class="text-red-600 hover:text-red-800 font-bold">&times;</button>
                </div>
            <?php endif; ?>

            <section class="admin-page-heading" aria-labelledby="adminCurrentPageTitle">
                <div class="flex min-w-0 items-center gap-3">
                    <span class="admin-page-heading-icon" aria-hidden="true"><?php echo iconSvg($tabIconMap[$tab] ?? 'shield', 'w-5 h-5'); ?></span>
                    <div class="min-w-0">
                        <h2 id="adminCurrentPageTitle"><?php echo esc($tabLabelMap[$tab] ?? 'Admin Dashboard'); ?></h2>
                        <p><?php echo esc($tabDescriptionMap[$tab] ?? 'Manage Rural Health Unit administration and governance.'); ?></p>
                    </div>
                </div>
                <span class="admin-live-badge">Live database</span>
            </section>

            <!-- OVERVIEW TAB -->
            <?php if ($tab === 'overview'): ?>
                <div class="space-y-4 sm:space-y-5">
                    <div class="unified-dashboard-hero grid grid-cols-1 lg:grid-cols-12 gap-4">
                        <section class="overview-card-polished lg:col-span-8 rounded-xl p-4 sm:p-5">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-[11px] font-black uppercase text-gray-400">Dashboard</p>
                                    <h3 class="text-base font-black text-gray-900">Clinical Activity Overview</h3>
                                    <p class="text-xs text-gray-500">Monthly snapshot across admin and RHU operations</p>
                                </div>
                                <div class="hidden sm:flex items-center gap-4 text-[11px] font-bold text-gray-500">
                                    <span class="inline-flex items-center gap-1"><i class="h-2 w-2 rounded-full bg-emerald-500"></i> Consultations</span>
                                    <span class="inline-flex items-center gap-1"><i class="h-2 w-2 rounded-full bg-amber-400"></i> Monthly points</span>
                                </div>
                            </div>
                            <div class="mt-4 grid gap-5 md:grid-cols-[170px_1fr] items-stretch">
                                <div>
                                    <p class="text-3xl font-black text-gray-900"><?php echo esc(number_format($totalResidentsCount)); ?></p>
                                    <p class="text-xs font-bold text-gray-500">Resident Registry</p>
                                    <p class="mt-4 text-2xl font-black text-gray-900"><?php echo esc(number_format(count($dbConsultationsList))); ?></p>
                                    <p class="text-xs font-bold text-gray-500">Consultations</p>
                                    <a href="<?php echo esc(tabUrl('reports')); ?>" class="mt-4 inline-flex rounded-lg bg-emerald-600 px-4 py-2 text-xs font-black text-white shadow-sm transition hover:bg-emerald-700">Month Summary</a>
                                </div>
                                <div class="unified-chart-card" aria-label="Consultation trend chart">
                                    <div class="unified-chart-grid"></div>
                                    <svg class="unified-chart-svg" viewBox="0 0 520 220" preserveAspectRatio="none" aria-hidden="true">
                                        <defs>
                                            <linearGradient id="overviewChartStroke" x1="0%" x2="100%" y1="0%" y2="0%">
                                                <stop offset="0%" stop-color="#2f7a4b" />
                                                <stop offset="55%" stop-color="#3d8d58" />
                                                <stop offset="100%" stop-color="#8bb36a" />
                                            </linearGradient>
                                        </defs>
                                        <polygon class="unified-chart-soft-fill" points="<?php echo esc($overviewChartSoftFill); ?>" />
                                        <polygon class="unified-chart-fill" points="<?php echo esc($overviewChartFill); ?>" />
                                        <polyline class="unified-chart-line" points="<?php echo esc($overviewChartPolyline); ?>" />
                                        <?php foreach ($overviewChartPoints as $index => $point): ?>
                                            <circle class="unified-chart-point" data-index="<?php echo esc((string)$index); ?>" data-label="<?php echo esc($overviewChartSeries[$index]['label']); ?>" data-value="<?php echo esc((string)$overviewChartSeries[$index]['value']); ?>" cx="<?php echo esc((string)$point['x']); ?>" cy="<?php echo esc((string)$point['y']); ?>" r="5.5"></circle>
                                        <?php endforeach; ?>
                                        <line class="unified-chart-baseline" x1="22" y1="<?php echo esc((string)$overviewChartBaseline); ?>" x2="498" y2="<?php echo esc((string)$overviewChartBaseline); ?>" />
                                        <?php foreach ($overviewChartPoints as $index => $point): ?>
                                            <text class="unified-chart-month" x="<?php echo esc((string)$point['x']); ?>" y="205" text-anchor="middle"><?php echo esc($overviewChartSeries[$index]['label']); ?></text>
                                        <?php endforeach; ?>
                                    </svg>
                                    <div class="unified-chart-hover-line" aria-hidden="true"></div>
                                    <div class="unified-chart-tooltip" aria-live="polite">
                                        <span class="unified-chart-tooltip-label">Month</span>
                                        <span class="unified-chart-tooltip-value">0</span>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <section class="overview-card-polished lg:col-span-4 rounded-xl p-4 sm:p-5">
                            <div>
                                <p class="text-[11px] font-black uppercase text-gray-400">Accounts</p>
                                <h3 class="text-base font-black text-gray-900">User & Staff Mix</h3>
                                <p class="text-xs text-gray-500">Current active system access groups</p>
                            </div>
                            <div class="mt-5 flex items-center justify-center">
                                <div class="unified-donut" style="--users-pct: <?php echo number_format($overviewUsersPct, 2, '.', ''); ?>%; --staff-pct: <?php echo number_format($overviewStaffPct, 2, '.', ''); ?>%; --bhw-pct: <?php echo number_format($overviewBhwPct, 2, '.', ''); ?>%;"></div>
                            </div>
                            <div class="mt-5 grid grid-cols-3 gap-2 text-center">
                                <div class="rounded-lg bg-white/70 p-2 ring-1 ring-emerald-100">
                                    <p class="text-lg font-black text-gray-900"><?php echo esc($totalUsersCount); ?></p>
                                    <p class="text-[10px] font-bold text-gray-500"><span class="mr-1 inline-block h-2 w-2 rounded-full bg-[#8bb36a]"></span>Users</p>
                                </div>
                                <div class="rounded-lg bg-white/70 p-2 ring-1 ring-emerald-100">
                                    <p class="text-lg font-black text-gray-900"><?php echo esc($totalStaffCount); ?></p>
                                    <p class="text-[10px] font-bold text-gray-500"><span class="mr-1 inline-block h-2 w-2 rounded-full bg-[#a7d291]"></span>Staff</p>
                                </div>
                                <div class="rounded-lg bg-white/70 p-2 ring-1 ring-emerald-100">
                                    <p class="text-lg font-black text-gray-900"><?php echo esc($totalBhwCount); ?></p>
                                    <p class="text-[10px] font-bold text-gray-500"><span class="mr-1 inline-block h-2 w-2 rounded-full bg-[#dceeb9]"></span>BHW</p>
                                </div>
                            </div>
                        </section>
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                        <?php renderMetricCard('Total System Users', $totalUsersCount, 'Database Accounts', 'users', 'bg-purple-600', 'text-purple-600'); ?>
                        <?php renderMetricCard('Registered Residents', $totalResidentsCount, 'Barangay Registry', 'check', 'bg-blue-600', 'text-blue-600'); ?>
                        <?php renderMetricCard('Active Healthcare Staff', $totalStaffCount, 'Plantilla & Contractual', 'stethoscope', 'bg-emerald-600', 'text-emerald-600'); ?>
                        <?php renderMetricCard('Barangay Health Workers', $totalBhwCount, 'Assigned BHWs', 'users', 'bg-teal-600', 'text-teal-600'); ?>
                    </div>

                    <!-- CLINICAL SUMMARY CARDS -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
                        <?php renderMetricCard('OPD Consultations', count($dbConsultationsList), 'View All', 'activity', 'bg-blue-600', 'text-blue-600'); ?>
                        <?php renderMetricCard('Maternal Cases', count($dbMaternalCases), 'Active Pregnancies', 'baby', 'bg-pink-600', 'text-pink-600'); ?>
                        <?php renderMetricCard('Immunizations', count($dbVaccinationRecords), 'Vaccination Records', 'shield', 'bg-emerald-600', 'text-emerald-600'); ?>
                        <?php renderMetricCard('Disease Cases', count($dbDiseaseCases), 'Active Surveillance', 'alert', 'bg-red-600', 'text-red-600'); ?>
                        <?php renderMetricCard('Medicine Items', count($dbMedicineInventory), 'Inventory Stock', 'pill', 'bg-orange-600', 'text-orange-600'); ?>
                    </div>

                    <!-- ADMIN CERTIFICATE WORKFLOW -->
                    <div class="rounded-xl border border-emerald-100 bg-white/95 p-4 sm:p-5 shadow-sm">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 class="text-base font-bold text-gray-900">Certificate Workflow</h3>
                                <p class="text-xs text-gray-500">Create certificate drafts and request doctor/staff e-signature approval before release.</p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-bold text-emerald-800">Admin Issuing</span>
                                <span class="rounded-full bg-blue-100 px-3 py-1 text-xs font-bold text-blue-800">Doctor / Staff Included</span>
                            </div>
                        </div>
                        <div class="mt-3 rounded-lg border border-emerald-100 bg-emerald-50/70 p-3 text-xs font-semibold text-emerald-900">
                            Upload the admin signature here if available. The assigned doctor/staff will receive an email link where they upload their own signature. When both signatures are complete, the resident receives the certificate automatically.
                        </div>
                        <?php if (empty($adminResidentCertificateOptions) || empty($adminCertificateTypes)): ?>
                            <p class="mt-4 rounded-lg border border-amber-100 bg-amber-50 p-3 text-xs font-semibold text-amber-800">Add active residents and certificate types first before issuing certificates.</p>
                        <?php else: ?>
                            <form method="post" enctype="multipart/form-data" class="mt-4 grid gap-3 text-xs sm:grid-cols-2 lg:grid-cols-4">
                                <input type="hidden" name="action" value="issue_certificate">
                                <select required name="resident_id" class="rounded-xl border border-gray-300 bg-white p-3 text-gray-900">
                                    <option value="">Select resident</option>
                                    <?php foreach ($adminResidentCertificateOptions as $residentOption): ?>
                                        <?php $residentRequest = $adminResidentCertificateRequests[(int)$residentOption['id']] ?? null; ?>
                                        <option value="<?php echo (int)$residentOption['id']; ?>" data-request-type-id="<?php echo (int)($residentRequest['certificate_type_id'] ?? 0); ?>" data-request-purpose="<?php echo esc($residentRequest['purpose'] ?? ''); ?>"><?php echo esc($residentOption['name']); ?><?php echo $residentOption['barangay'] !== '' ? ' - ' . esc($residentOption['barangay']) : ''; ?><?php echo $residentRequest ? ' - Pending certificate request' : ''; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select required name="certificate_type_id" class="rounded-xl border border-gray-300 bg-white p-3 text-gray-900">
                                    <option value="">Certificate type</option>
                                    <?php foreach ($adminCertificateTypes as $certificateType): ?>
                                        <option value="<?php echo (int)$certificateType['id']; ?>" data-staff-groups="<?php echo esc(implode(',', certificateStaffMatchGroups($certificateType['certificate_type_name']))); ?>"><?php echo esc($certificateType['certificate_type_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select required name="assigned_staff_id" data-certificate-staff-select class="rounded-xl border border-gray-300 bg-white p-3 text-gray-900">
                                    <option value="">Assigned doctor / staff</option>
                                    <?php foreach ($certificateDoctorOptions as $doctorOption): ?>
                                        <?php
                                        $staffSearch = strtolower(($doctorOption['staff_type'] ?? '') . ' ' . ($doctorOption['specialization'] ?? ''));
                                        $staffGroups = [];
                                        if (str_contains($staffSearch, 'physician') || str_contains($staffSearch, 'doctor')) $staffGroups[] = 'physician';
                                        if (str_contains($staffSearch, 'nurse')) $staffGroups[] = 'nurse';
                                        if (str_contains($staffSearch, 'midwife')) $staffGroups[] = 'midwife';
                                        if (str_contains($staffSearch, 'medtech') || str_contains($staffSearch, 'medical technolog') || str_contains($staffSearch, 'laboratory')) $staffGroups[] = 'medtech';
                                        if (str_contains($staffSearch, 'sanitary') || str_contains($staffSearch, 'sanitation') || str_contains($staffSearch, 'inspector')) $staffGroups[] = 'sanitary';
                                        if (str_contains($staffSearch, 'bhw') || str_contains($staffSearch, 'barangay health')) $staffGroups[] = 'bhw';
                                        ?>
                                        <option value="<?php echo (int)$doctorOption['id']; ?>" data-staff-groups="<?php echo esc(implode(',', array_unique($staffGroups))); ?>">
                                            <?php echo esc(trim($doctorOption['name']) . ' - ' . $doctorOption['staff_type']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input required type="date" name="issue_date" value="<?php echo esc(date('Y-m-d')); ?>" class="rounded-xl border border-gray-300 p-3 text-gray-900">
                                <input type="date" name="expiry_date" value="<?php echo esc(date('Y-m-d', strtotime('+6 months'))); ?>" min="<?php echo esc(date('Y-m-d')); ?>" class="rounded-xl border border-gray-300 p-3 text-gray-900">
                                <label class="rounded-xl border border-dashed border-emerald-300 bg-emerald-50/70 p-3 font-bold text-emerald-900 lg:col-span-2">
                                    Admin signature image
                                    <input type="file" name="admin_signature_image" accept="image/png,image/jpeg,image/webp" class="mt-2 block w-full text-[11px] font-semibold text-gray-700 file:mr-3 file:rounded-lg file:border-0 file:bg-emerald-700 file:px-3 file:py-2 file:text-xs file:font-bold file:text-white">
                                    <span class="mt-1 block text-[10px] font-semibold text-emerald-800">Optional. Upload here to sign as admin immediately instead of approving through email.</span>
                                </label>
                                <textarea required name="purpose" data-certificate-purpose rows="3" maxlength="1000" class="rounded-xl border border-gray-300 p-3 text-gray-900 lg:col-span-3" placeholder="Purpose / certification statement"></textarea>
                                <button type="submit" class="rounded-xl bg-emerald-600 p-3 font-extrabold text-white shadow-sm hover:bg-emerald-700">Create & Request Approval</button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <!-- PENDING CERTIFICATE REQUESTS PANEL -->
                    <div class="bg-white rounded-xl p-4 sm:p-5 shadow-sm border border-gray-100" data-certificate-cleanup-panel>
                        <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
                            <div>
                                <h3 class="font-bold text-gray-900 text-base">Resident Medical Certificate Requests</h3>
                                <p class="text-xs text-gray-500">Live requests submitted by residents requiring Admin approval</p>
                            </div>
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                <span class="text-xs font-bold bg-purple-100 text-purple-800 px-3 py-1 rounded-full"><?php echo count($dbCertificatesList); ?> Records</span>
                                <?php if (!empty($dbCertificatesList)): ?>
                                    <button type="button" data-certificate-select-toggle class="text-xs font-bold rounded-full bg-emerald-100 px-3 py-1.5 text-emerald-900 hover:bg-emerald-200">Select Delete</button>
                                    <button type="submit" form="deleteSelectedCertificatesForm" data-certificate-delete-selected class="hidden text-xs font-bold rounded-full bg-red-100 px-3 py-1.5 text-red-800 hover:bg-red-200" onclick="return confirm('Delete selected certificate requests?')">Delete Selected</button>
                                    <form method="post" id="deleteSelectedCertificatesForm" class="hidden">
                                        <input type="hidden" name="action" value="delete_selected_certificates">
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if (empty($dbCertificatesList)): ?>
                            <p class="text-xs text-gray-400 italic py-3">No certificate requests found in database.</p>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="w-full text-xs">
                                    <thead class="bg-gray-50 text-gray-500 uppercase">
                                        <tr>
                                            <th class="hidden px-3 py-2 text-left" data-certificate-select-header>Select</th>
                                            <th class="px-3 py-2 text-left">Resident Name</th>
                                            <th class="px-3 py-2 text-left">Certificate Type</th>
                                            <th class="px-3 py-2 text-left">Purpose</th>
                                            <th class="px-3 py-2 text-left">Status</th>
                                            <th class="px-3 py-2 text-left">Date Requested</th>
                                            <th class="px-3 py-2 text-left">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <?php foreach ($dbCertificatesList as $cert): ?>
                                            <tr class="hover:bg-gray-50">
                                                <td class="hidden px-3 py-2.5" data-certificate-select-box>
                                                    <input form="deleteSelectedCertificatesForm" type="checkbox" name="certificate_ids[]" value="<?php echo (int)$cert['id']; ?>" class="h-4 w-4 rounded border-gray-300 text-red-600 focus:ring-red-500">
                                                </td>
                                                 <td class="px-3 py-2.5 font-bold text-gray-900"><?php echo esc(trim(($cert['first_name'] ?? '') . ' ' . ($cert['last_name'] ?? ''))) ?: 'Resident #' . (int)$cert['resident_id']; ?></td>
                                                <td class="px-3 py-2.5 text-gray-700"><?php echo esc($cert['certificate_type']); ?></td>
                                                <td class="px-3 py-2.5 text-gray-600 max-w-[200px] truncate">
                                                    <?php
                                                    $workflowStatus = (string)($cert['workflow_status'] ?? $cert['status'] ?? 'Draft');
                                                    $certificateStatusText = strtolower((string)($cert['status'] ?? ''));
                                                    $certificateApproved = in_array($workflowStatus, ['Signed', 'Sent'], true)
                                                        || str_contains($certificateStatusText, 'approved')
                                                        || str_contains($certificateStatusText, 'issued');
                                                    ?>
                                                    <?php echo esc($cert['purpose']); ?>
                                                    <?php if ((int)($cert['duplicate_count'] ?? 1) > 1): ?>
                                                        <div class="mt-1 text-[10px] font-bold text-emerald-700"><?php echo (int)$cert['duplicate_count']; ?> similar requests combined</div>
                                                    <?php endif; ?>
                                                    <div class="mt-1 text-[10px] font-bold text-blue-700">Workflow: <?php echo esc($workflowStatus); ?></div>
                                                    <?php if (trim((string)($cert['assigned_doctor_name'] ?? '')) !== ''): ?>
                                                        <div class="text-[10px] text-gray-500">Approver: <?php echo esc(trim($cert['assigned_doctor_name']) . ' - ' . ($cert['assigned_doctor_position'] ?? '')); ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-3 py-2.5">
                                                    <?php if ($certificateApproved): ?>
                                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-green-100 text-green-700 font-bold"><?php echo iconSvg('check', 'w-3 h-3 shrink-0'); ?> Approved</span>
                                                    <?php elseif ($cert['status'] === 'Rejected' || $workflowStatus === 'Rejected'): ?>
                                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-red-100 text-red-700 font-bold"><?php echo iconSvg('close', 'w-3 h-3 shrink-0'); ?> Rejected</span>
                                                    <?php else: ?>
                                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-amber-100 text-amber-800 font-bold"><?php echo iconSvg('clock', 'w-3 h-3 shrink-0'); ?> Pending</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-3 py-2.5 text-gray-500"><?php echo esc(date('M d, Y', strtotime($cert['created_at']))); ?></td>
                                                <td class="px-3 py-2.5">
                                                    <?php if (in_array($workflowStatus, ['Pending', 'Draft'], true) || $cert['status'] === 'Pending'): ?>
                                                        <div class="flex items-center gap-1.5">
                                                            <form method="post" class="inline">
                                                                <input type="hidden" name="action" value="approve_certificate">
                                                                <input type="hidden" name="request_id" value="<?php echo $cert['id']; ?>">
                                                                <button type="submit" class="px-2.5 py-1 bg-blue-600 text-white rounded font-semibold text-[11px] hover:bg-blue-700">Generate & Request Approval</button>
                                                            </form>
                                                            <?php if (!empty($cert['generated_html'])): ?>
                                                                <button type="button" data-certificate-preview-open="<?php echo (int)$cert['id']; ?>" class="px-2.5 py-1 bg-slate-100 text-slate-700 rounded font-semibold text-[11px] hover:bg-slate-200">Preview</button>
                                                                <template data-certificate-preview-template="<?php echo (int)$cert['id']; ?>"><?php echo $cert['generated_html']; ?></template>
                                                                <form method="post" class="inline">
                                                                    <input type="hidden" name="action" value="regenerate_certificate">
                                                                    <input type="hidden" name="certificate_id" value="<?php echo $cert['id']; ?>">
                                                                    <button type="submit" class="px-2.5 py-1 bg-amber-500 text-white rounded font-semibold text-[11px] hover:bg-amber-600">Regenerate</button>
                                                                </form>
                                                            <?php endif; ?>
                                                            <?php if (($workflowStatus ?? '') === 'Signed'): ?>
                                                                <form method="post" class="inline">
                                                                    <input type="hidden" name="action" value="send_final_certificate">
                                                                    <input type="hidden" name="certificate_id" value="<?php echo $cert['id']; ?>">
                                                                    <button type="submit" class="px-2.5 py-1 bg-green-600 text-white rounded font-semibold text-[11px] hover:bg-green-700">Approve Final & Send</button>
                                                                </form>
                                                            <?php endif; ?>
                                                            <form method="post" class="inline">
                                                                <input type="hidden" name="action" value="reject_certificate">
                                                                <input type="hidden" name="request_id" value="<?php echo $cert['id']; ?>">
                                                                <button type="submit" class="px-2.5 py-1 bg-red-600 text-white rounded font-semibold text-[11px] hover:bg-red-700">Reject</button>
                                                            </form>
                                                        </div>
                                                    <?php else: ?>
                                                        <?php if (!empty($cert['generated_html'])): ?>
                                                            <button type="button" data-certificate-preview-open="<?php echo (int)$cert['id']; ?>" class="mb-1 px-2.5 py-1 bg-slate-100 text-slate-700 rounded font-semibold text-[11px] hover:bg-slate-200">Preview</button>
                                                            <template data-certificate-preview-template="<?php echo (int)$cert['id']; ?>"><?php echo $cert['generated_html']; ?></template>
                                                        <?php endif; ?>
                                                        <?php if (($workflowStatus ?? '') === 'Signed'): ?>
                                                            <form method="post" class="mb-1 inline">
                                                                <input type="hidden" name="action" value="send_final_certificate">
                                                                <input type="hidden" name="certificate_id" value="<?php echo $cert['id']; ?>">
                                                                <button type="submit" class="px-2.5 py-1 bg-green-600 text-white rounded font-semibold text-[11px] hover:bg-green-700">Approve Final & Send</button>
                                                            </form>
                                                        <?php endif; ?>
                                                        <?php if (($workflowStatus ?? '') === 'Sent'): ?>
                                                            <a href="?certificate_pdf=<?php echo (int)$cert['id']; ?>" class="text-[11px] font-bold text-purple-700">Download</a>
                                                        <?php elseif (str_contains((string)($workflowStatus ?? ''), 'Approval')): ?>
                                                            <span class="text-[11px] font-bold text-blue-700">Waiting for e-signature approval</span>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div data-certificate-preview-modal class="fixed inset-0 z-[95] hidden bg-slate-950/50 p-3 backdrop-blur-sm sm:p-6">
                        <div class="mx-auto flex max-h-[90vh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl border border-white/70 bg-white shadow-2xl">
                            <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-4 py-3">
                                <div>
                                    <h3 class="text-sm font-black text-gray-900">Certificate Preview</h3>
                                    <p class="text-xs text-gray-500">Review the generated certificate before final sending.</p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <a href="#" data-certificate-preview-download class="hidden items-center gap-1.5 rounded-lg bg-emerald-700 px-3 py-2 text-xs font-bold text-white shadow-sm hover:bg-emerald-800">
                                        <?php echo iconSvg('download', 'w-4 h-4'); ?><span>Download</span>
                                    </a>
                                    <button type="button" data-certificate-preview-close class="grid h-9 w-9 place-items-center rounded-full border border-gray-200 bg-gray-50 text-lg font-black text-gray-600 hover:bg-gray-100" aria-label="Close certificate preview">&times;</button>
                                </div>
                            </div>
                            <div data-certificate-preview-content class="flex-1 overflow-auto bg-slate-100 p-4 sm:p-6"></div>
                        </div>
                    </div>

                    <!-- INCOMING RESIDENT MESSAGES PANEL -->
                    <div class="bg-white rounded-xl p-4 sm:p-5 shadow-sm border border-gray-100" data-message-cleanup-panel>
                        <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
                            <div>
                                <h3 class="font-bold text-gray-900 text-base">Incoming Resident Inquiries & Messages</h3>
                                <p class="text-xs text-gray-500">Live messages submitted from the Resident Portal</p>
                            </div>
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                <span id="admin-message-count" class="text-xs font-bold bg-blue-100 text-blue-800 px-3 py-1 rounded-full"><?php echo count($dbMessagesList); ?> Messages</span>
                                <button type="button" data-message-select-toggle class="<?php echo empty($dbMessagesList) ? 'hidden ' : ''; ?>text-xs font-bold rounded-full bg-emerald-100 px-3 py-1.5 text-emerald-900 hover:bg-emerald-200">Select Delete</button>
                                <button type="submit" form="deleteSelectedMessagesForm" data-message-delete-selected class="hidden text-xs font-bold rounded-full bg-red-100 px-3 py-1.5 text-red-800 hover:bg-red-200" onclick="return confirm('Delete selected resident messages?')">Delete Selected</button>
                                <form method="post" id="deleteSelectedMessagesForm" class="hidden">
                                    <input type="hidden" name="csrf_token" value="<?php echo esc(portalCsrfToken()); ?>">
                                    <input type="hidden" name="action" value="delete_selected_messages">
                                </form>
                            </div>
                        </div>
                        <div id="admin-message-list">
                            <?php if (empty($dbMessagesList)): ?>
                                <p class="text-xs text-gray-400 italic py-3">No resident messages found in database.</p>
                            <?php else: ?>
                                <div class="space-y-3">
                                    <?php foreach ($dbMessagesList as $msg): ?>
                                        <div class="p-3 bg-gray-50 rounded-xl border border-gray-100 space-y-2">
                                            <div class="flex items-start gap-2 text-xs">
                                                <label class="hidden pt-0.5" data-message-select-box>
                                                    <input form="deleteSelectedMessagesForm" type="checkbox" name="message_ids[]" value="<?php echo (int)$msg['id']; ?>" class="h-4 w-4 rounded border-gray-300 text-red-600 focus:ring-red-500">
                                                    <span class="sr-only">Select message <?php echo (int)$msg['id']; ?></span>
                                                </label>
                                                <div class="flex flex-1 items-center justify-between gap-3">
                                                     <span class="font-bold text-gray-900"><?php echo esc(trim(($msg['first_name'] ?? '') . ' ' . ($msg['last_name'] ?? ''))) ?: 'Resident #' . (int)$msg['resident_id']; ?><?php echo !empty($msg['email']) ? ' (' . esc($msg['email']) . ')' : ''; ?></span>
                                                    <span class="text-gray-400"><?php echo esc(date('M d, Y h:i A', strtotime($msg['created_at']))); ?></span>
                                                </div>
                                            </div>
                                            <p class="text-xs font-semibold text-purple-900">Subject: <?php echo esc($msg['subject']); ?></p>
                                            <p class="text-xs text-gray-700 bg-white p-2.5 rounded border border-gray-200"><?php echo esc($msg['message']); ?></p>

                                            <?php if (!empty($msg['admin_reply'])): ?>
                                                <div class="ml-4 p-2.5 bg-purple-50 rounded border-l-4 border-purple-600 text-xs">
                                                    <p class="font-bold text-purple-900">Admin Response:</p>
                                                    <p class="text-purple-800 mt-0.5"><?php echo esc($msg['admin_reply']); ?></p>
                                                </div>
                                            <?php else: ?>
                                                <form method="post" class="mt-2 flex gap-2">
                                                    <input type="hidden" name="csrf_token" value="<?php echo esc(portalCsrfToken()); ?>">
                                                    <input type="hidden" name="action" value="reply_message">
                                                    <input type="hidden" name="message_id" value="<?php echo $msg['id']; ?>">
                                                    <input required name="reply" class="flex-1 text-xs px-3 py-1.5 rounded border border-gray-300 focus:outline-none focus:border-purple-600" placeholder="Type response to resident...">
                                                    <button type="submit" class="px-3 py-1.5 bg-purple-700 text-white rounded text-xs font-semibold hover:bg-purple-800">Send Reply</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- USER MANAGEMENT TAB -->
            <?php if ($tab === 'users'): ?>
                <div class="space-y-4 sm:space-y-5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-base sm:text-xl font-bold text-gray-900 flex items-center gap-2"><?php echo iconSvg('users', 'w-5 h-5 text-blue-600'); ?> Database User Accounts</h2>
                        <span class="text-xs bg-blue-100 text-blue-800 font-bold px-3 py-1 rounded-full">Total: <?php echo count($dbUsersList); ?> Accounts</span>
                    </div>

                    <section class="rounded-xl border border-emerald-100 bg-emerald-50/60 p-4 shadow-sm">
                        <div class="mb-3">
                            <h3 class="text-sm font-black text-emerald-950">Register Healthcare Staff</h3>
                            <p class="text-xs text-emerald-800">Create an active staff login and save the account to the <code>health_workers</code> table.</p>
                        </div>
                        <form method="post" class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                            <input type="hidden" name="csrf_token" value="<?php echo esc(portalCsrfToken()); ?>">
                            <input type="hidden" name="action" value="create_staff">
                            <input type="hidden" name="tab" value="users">
                            <input required name="first_name" placeholder="First name" autocomplete="given-name" class="rounded-lg border border-emerald-200 bg-white px-3 py-2 text-sm">
                            <input required name="last_name" placeholder="Last name" autocomplete="family-name" class="rounded-lg border border-emerald-200 bg-white px-3 py-2 text-sm">
                            <input required type="email" name="email" placeholder="Email address" autocomplete="email" class="rounded-lg border border-emerald-200 bg-white px-3 py-2 text-sm">
                            <input required type="password" name="password" placeholder="Temporary password" autocomplete="new-password" minlength="8" class="rounded-lg border border-emerald-200 bg-white px-3 py-2 text-sm">
                            <select required name="staff_type" class="rounded-lg border border-emerald-200 bg-white px-3 py-2 text-sm">
                                <option value="">Select staff role</option>
                                <option value="PHYSICIAN">Physician</option>
                                <option value="NURSE">Nurse</option>
                                <option value="MIDWIFE">Midwife</option>
                                <option value="MEDTECH">Medical Technologist</option>
                                <option value="SANITARY_INSPECTOR">Sanitary Inspector</option>
                                <option value="BHW">Barangay Health Worker</option>
                                <option value="ADMIN_STAFF">Admin Staff</option>
                            </select>
                            <input name="license_number" placeholder="License number (optional)" class="rounded-lg border border-emerald-200 bg-white px-3 py-2 text-sm">
                            <input name="phone_number" placeholder="Contact number (optional)" autocomplete="tel" class="rounded-lg border border-emerald-200 bg-white px-3 py-2 text-sm">
                            <button type="submit" class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-black text-white shadow-sm hover:bg-emerald-800">Register Staff Account</button>
                        </form>
                    </section>

                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="w-full text-xs min-w-[700px]">
                                <thead class="bg-gray-50 text-gray-500 uppercase">
                                    <tr>
                                        <th class="px-3 py-2.5 text-left">ID</th>
                                        <th class="px-3 py-2.5 text-left">Name</th>
                                        <th class="px-3 py-2.5 text-left">Email Address</th>
                                        <th class="px-3 py-2.5 text-left">Role</th>
                                        <th class="px-3 py-2.5 text-left">Status</th>
                                        <th class="px-3 py-2.5 text-left">Created At</th>
                                        <th class="px-3 py-2.5 text-left">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php foreach ($dbUsersList as $u): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-3 py-2.5 font-mono font-bold text-gray-500">#<?php echo $u['id']; ?></td>
                                            <td class="px-3 py-2.5 font-bold text-gray-900"><?php echo esc(trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''))) ?: $u['username']; ?></td>
                                            <td class="px-3 py-2.5 text-gray-700 font-mono"><?php echo esc($u['email']); ?></td>
                                            <td class="px-3 py-2.5">
                                                <form method="post" class="flex gap-1"><input type="hidden" name="action" value="update_user_role"><input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>"><select name="role_id" class="rounded border p-1"><?php foreach ($dbRoles as $role): ?><option value="<?php echo (int)$role['id']; ?>" <?php echo ($u['role_name'] ?? '') === $role['name'] ? 'selected' : ''; ?>><?php echo esc($role['name']); ?></option><?php endforeach; ?></select><button class="rounded bg-purple-700 px-2 text-white">Set</button></form>
                                            </td>
                                            <td class="px-3 py-2.5">
                                                <?php if ($u['is_active']): ?>
                                                    <span class="px-2 py-0.5 rounded-full bg-green-100 text-green-700 font-bold">Active</span>
                                                <?php else: ?>
                                                    <span class="px-2 py-0.5 rounded-full bg-red-100 text-red-700 font-bold">Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-3 py-2.5 text-gray-500"><?php echo esc(date('M d, Y', strtotime($u['created_at']))); ?></td>
                                            <td class="px-3 py-2.5">
                                                <div class="flex items-center gap-1.5">
                                                    <form method="post" class="inline">
                                                        <input type="hidden" name="action" value="toggle_user_status">
                                                        <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                                        <input type="hidden" name="new_status" value="<?php echo $u['is_active'] ? 0 : 1; ?>">
                                                        <button type="submit" class="px-2 py-0.5 text-[11px] font-semibold rounded <?php echo $u['is_active'] ?>">
                                                            <?php echo $u['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                                        </button>
                                                    </form>
                                                    <form method="post" class="inline" onsubmit="return confirm('Are you sure you want to delete this user from database?');">
                                                        <input type="hidden" name="action" value="delete_user">
                                                        <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                                        <button type="submit" class="px-2 py-0.5 text-[11px] font-semibold rounded bg-red-100 text-red-700 hover:bg-red-200">Delete</button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- STAFF ACCOUNTS TAB -->
            <?php if ($tab === 'staff'): ?>
                <?php renderAdminFloatingToolsModal($pdo, 'staff-tools', 'Staff Account Tools', 'Create, update, and delete RHU staff accounts without leaving the registry.', 'staff'); ?>
                <?php
                $selectedStaffType = trim($_GET['staff_type'] ?? 'all');
                $selectedStaffId = ctype_digit((string)($_GET['staff_id'] ?? '')) ? (int)$_GET['staff_id'] : 0;
                $staffFolders = [
                    'all' => ['label' => 'All Staff', 'people' => count($dbStaffList), 'records' => 0],
                    'PHYSICIAN' => ['label' => 'Physicians', 'people' => 0, 'records' => 0],
                    'NURSE' => ['label' => 'Nurses', 'people' => 0, 'records' => 0],
                    'MIDWIFE' => ['label' => 'Midwives', 'people' => 0, 'records' => 0],
                    'MEDTECH' => ['label' => 'MedTech', 'people' => 0, 'records' => 0],
                    'SANITARY_INSPECTOR' => ['label' => 'Sanitary', 'people' => 0, 'records' => 0],
                    'BHW' => ['label' => 'BHWs', 'people' => 0, 'records' => 0],
                    'ADMIN_STAFF' => ['label' => 'Admin Staff', 'people' => 0, 'records' => 0],
                ];
                $staffFolderKeyFromType = static function (string $staffType): string {
                    $type = strtoupper(trim($staffType));
                    if ($type === '') return 'ADMIN_STAFF';
                    if (str_contains($type, 'PHYSICIAN') || str_contains($type, 'DOCTOR')) return 'PHYSICIAN';
                    if (str_contains($type, 'NURSE')) return 'NURSE';
                    if (str_contains($type, 'MIDWIFE')) return 'MIDWIFE';
                    if (str_contains($type, 'MEDTECH') || str_contains($type, 'MEDICAL TECHNOLOG') || str_contains($type, 'LABORATORY')) return 'MEDTECH';
                    if (str_contains($type, 'SANITARY') || str_contains($type, 'SANITATION') || str_contains($type, 'INSPECTOR')) return 'SANITARY_INSPECTOR';
                    if (str_contains($type, 'BHW') || str_contains($type, 'BARANGAY HEALTH')) return 'BHW';
                    if (str_contains($type, 'ADMIN')) return 'ADMIN_STAFF';
                    return $type;
                };
                if (!isset($staffFolders[$selectedStaffType])) {
                    $selectedStaffType = 'all';
                }
                $staffById = [];
                $staffIdByUserId = [];
                foreach ($dbStaffList as $staffFolderRow) {
                    $staffId = (int)($staffFolderRow['id'] ?? 0);
                    if ($staffId > 0) {
                        $staffById[$staffId] = $staffFolderRow;
                    }
                    $staffUserId = (int)($staffFolderRow['user_id'] ?? 0);
                    if ($staffUserId > 0 && $staffId > 0) {
                        $staffIdByUserId[$staffUserId] = $staffId;
                    }
                    $folderType = $staffFolderKeyFromType((string)($staffFolderRow['staff_type'] ?? ''));
                    if (isset($staffFolders[$folderType])) {
                        $staffFolders[$folderType]['people']++;
                    }
                }
                $midwifeFolderStaffIds = array_keys(array_filter($staffById, fn($staffRow) => $staffFolderKeyFromType((string)($staffRow['staff_type'] ?? '')) === 'MIDWIFE'));
                $singleMidwifeStaffId = count($midwifeFolderStaffIds) === 1 ? (int)$midwifeFolderStaffIds[0] : 0;
                $staffFolderRecords = [];
                $recordKeysByFolder = [];
                $resolveStaffFolderId = static function (int $candidateId) use ($staffById, $staffIdByUserId): int {
                    if ($candidateId <= 0) return 0;
                    if (!empty($staffById[$candidateId])) return $candidateId;
                    return (int)($staffIdByUserId[$candidateId] ?? 0);
                };
                $addStaffFolderRecord = static function (int $staffId, string $recordType, string $recordId, string $residentName, string $title, string $status, string $date, string $barangay = '') use (&$staffFolderRecords, &$recordKeysByFolder, &$staffFolders, $staffById, $staffFolderKeyFromType): void {
                    if ($staffId <= 0 || empty($staffById[$staffId])) return;
                    $staffRow = $staffById[$staffId];
                    $folderType = $staffFolderKeyFromType((string)($staffRow['staff_type'] ?? ''));
                    if (!isset($staffFolders[$folderType])) return;
                    $uniqueKey = $recordType . ':' . $recordId . ':' . $staffId;
                    if (!empty($recordKeysByFolder[$folderType][$uniqueKey])) return;
                    $recordKeysByFolder[$folderType][$uniqueKey] = true;
                    $recordKeysByFolder['all'][$uniqueKey] = true;
                    $staffFolders[$folderType]['records']++;
                    $staffFolders['all']['records']++;
                    $staffFolderRecords[] = [
                        'folder_type' => $folderType,
                        'record_type' => $recordType,
                        'record_id' => $recordId,
                        'resident_name' => $residentName,
                        'title' => $title,
                        'status' => $status,
                        'date' => $date,
                        'barangay' => $barangay,
                        'staff_id' => $staffId,
                        'staff_name' => trim((string)($staffRow['first_name'] ?? '') . ' ' . (string)($staffRow['last_name'] ?? '')) ?: 'Staff #' . $staffId,
                    ];
                };
                foreach ($dbConsultationsList as $record) {
                    $consultationStaffId = $resolveStaffFolderId((int)($record['physician_id'] ?? 0));
                    $recordedByStaffId = $resolveStaffFolderId((int)($record['recorded_by_staff_id'] ?? 0));
                    if ($recordedByStaffId <= 0) {
                        $recordedByStaffId = (int)($staffIdByUserId[(int)($record['recorded_by_user_id'] ?? 0)] ?? 0);
                    }
                    $consultationText = strtolower((string)($record['chief_complaint'] ?? '') . ' ' . (string)($record['diagnosis'] ?? '') . ' ' . (string)($record['consultation_notes'] ?? ''));
                    if ($consultationStaffId <= 0 && $singleMidwifeStaffId > 0 && (str_contains($consultationText, 'prenatal') || str_contains($consultationText, 'maternal') || str_contains($consultationText, 'midwife'))) {
                        $consultationStaffId = $singleMidwifeStaffId;
                    }
                    $addStaffFolderRecord($consultationStaffId, 'Consultation', (string)($record['id'] ?? ''), trim(($record['first_name'] ?? '') . ' ' . ($record['last_name'] ?? '')), (string)($record['chief_complaint'] ?? $record['diagnosis'] ?? 'OPD consultation'), (string)($record['consultation_status'] ?? 'Scheduled'), (string)($record['consultation_date'] ?? ''), (string)($record['barangay'] ?? ''));
                    $addStaffFolderRecord($recordedByStaffId, 'Nursing Triage', (string)($record['id'] ?? ''), trim(($record['first_name'] ?? '') . ' ' . ($record['last_name'] ?? '')), (string)($record['chief_complaint'] ?? $record['diagnosis'] ?? 'OPD triage'), (string)($record['consultation_status'] ?? 'Recorded'), (string)($record['consultation_date'] ?? ''), (string)($record['barangay'] ?? ''));
                }
                foreach ($dbHealthProfileRecords as $record) {
                    $profileStaffId = $resolveStaffFolderId((int)($record['updated_by_staff_id'] ?? 0));
                    if ($profileStaffId <= 0) {
                        $profileStaffId = (int)($staffIdByUserId[(int)($record['updated_by_user_id'] ?? 0)] ?? 0);
                    }
                    $profileTitleParts = array_filter([
                        !empty($record['height']) ? 'Height: ' . $record['height'] . ' cm' : '',
                        !empty($record['weight']) ? 'Weight: ' . $record['weight'] . ' kg' : '',
                        !empty($record['blood_pressure']) ? 'BP: ' . $record['blood_pressure'] : '',
                    ]);
                    $addStaffFolderRecord($profileStaffId, 'Health Profile', (string)($record['id'] ?? ''), trim(($record['first_name'] ?? '') . ' ' . ($record['last_name'] ?? '')), $profileTitleParts ? implode(' | ', $profileTitleParts) : 'Resident medical profile updated', 'Updated', (string)($record['updated_at'] ?? $record['last_checkup_date'] ?? ''), (string)($record['barangay'] ?? ''));
                }
                foreach ($dbMaternalCases as $record) {
                    $riskLabel = !empty($record['high_risk']) ? 'High Risk' : 'Low Risk';
                    $title = 'Prenatal case - EDC ' . ((string)($record['edc'] ?? '') ?: 'to be set');
                    $maternalStaffId = $resolveStaffFolderId((int)($record['midwife_staff_id'] ?? 0)) ?: $singleMidwifeStaffId;
                    $addStaffFolderRecord($maternalStaffId, 'Prenatal Case', (string)($record['id'] ?? ''), trim(($record['first_name'] ?? '') . ' ' . ($record['last_name'] ?? '')), $title, (string)($record['status'] ?? $riskLabel), (string)($record['created_at'] ?? $record['lmp'] ?? ''), (string)($record['barangay'] ?? ''));
                }
                foreach ($dbVaccinationRecords as $record) {
                    $addStaffFolderRecord($resolveStaffFolderId((int)($record['healthcare_provider_id'] ?? 0)), 'Vaccination', (string)($record['id'] ?? ''), trim(($record['first_name'] ?? '') . ' ' . ($record['last_name'] ?? '')), (string)($record['vaccine_name'] ?? 'Vaccination record'), (string)($record['status'] ?? 'Completed'), (string)($record['vaccination_date'] ?? ''), (string)($record['barangay'] ?? ''));
                }
                foreach ($dbFamilyPlanningRecords as $record) {
                    $addStaffFolderRecord($resolveStaffFolderId((int)($record['healthcare_provider_id'] ?? 0)), 'Family Planning', (string)($record['id'] ?? ''), trim(($record['first_name'] ?? '') . ' ' . ($record['last_name'] ?? '')), (string)($record['contraceptive_method'] ?? 'Family planning record'), (string)($record['status'] ?? 'Active'), (string)($record['created_at'] ?? $record['last_supply_date'] ?? ''), (string)($record['barangay'] ?? ''));
                }
                foreach ($dbMaternalReferrals as $record) {
                    $addStaffFolderRecord($resolveStaffFolderId((int)($record['referred_by_id'] ?? 0)), 'Maternal Referral', (string)($record['id'] ?? ''), trim(($record['first_name'] ?? '') . ' ' . ($record['last_name'] ?? '')), (string)($record['diagnosis'] ?? $record['referral_reason'] ?? 'Maternal referral'), (string)($record['referral_status'] ?? 'Pending'), (string)($record['created_at'] ?? $record['referral_date'] ?? ''), (string)($record['barangay'] ?? ''));
                }
                foreach ($dbVitalStatistics as $record) {
                    if (($record['type'] ?? '') !== 'Birth') continue;
                    $motherName = trim(($record['mother_first_name'] ?? '') . ' ' . ($record['mother_last_name'] ?? ''));
                    $residentName = trim((string)($record['child_name'] ?? 'Birth record') . ($motherName !== '' ? ' (Mother: ' . $motherName . ')' : ''));
                    $birthStaffId = $resolveStaffFolderId((int)($record['delivery_attendant_id'] ?? 0)) ?: $singleMidwifeStaffId;
                    $addStaffFolderRecord($birthStaffId, 'Birth Record', (string)($record['id'] ?? ''), $residentName, (string)($record['location'] ?? 'Birth registration'), 'Registered', (string)($record['record_created_at'] ?? $record['date_recorded'] ?? ''), (string)($record['barangay'] ?? ''));
                }
                foreach ($dbDiseaseCases as $record) {
                    $addStaffFolderRecord($resolveStaffFolderId((int)($record['reported_by_id'] ?? 0)), 'Disease Case', (string)($record['id'] ?? ''), trim(($record['first_name'] ?? '') . ' ' . ($record['last_name'] ?? '')), (string)($record['disease_name'] ?? 'Disease surveillance case'), (string)($record['status'] ?? $record['outcome'] ?? 'Recorded'), (string)($record['case_date'] ?? ''), (string)($record['barangay'] ?? ''));
                }
                foreach ($dbCertificatesList as $record) {
                    $residentName = trim(($record['first_name'] ?? '') . ' ' . ($record['last_name'] ?? ''));
                    $certificateTitle = (string)($record['certificate_type'] ?? 'Health certificate');
                    $certificateStatus = (string)($record['workflow_status'] ?? $record['status'] ?? 'Draft');
                    $certificateDate = (string)($record['issue_date'] ?? $record['created_at'] ?? '');
                    $addStaffFolderRecord($resolveStaffFolderId((int)($record['assigned_doctor_id'] ?? 0)), 'Certificate', (string)($record['id'] ?? ''), $residentName, $certificateTitle, $certificateStatus, $certificateDate);
                    $addStaffFolderRecord($resolveStaffFolderId((int)($record['issued_by_id'] ?? 0)), 'Certificate', (string)($record['id'] ?? ''), $residentName, $certificateTitle, $certificateStatus, $certificateDate);
                    $adminStaffId = $staffIdByUserId[(int)($record['admin_approver_user_id'] ?? 0)] ?? 0;
                    $addStaffFolderRecord((int)$adminStaffId, 'Certificate Approval', (string)($record['id'] ?? ''), $residentName, $certificateTitle, $certificateStatus, $certificateDate);
                }
                usort($staffFolderRecords, static function (array $a, array $b): int {
                    $aTime = strtotime((string)($a['date'] ?? '')) ?: 0;
                    $bTime = strtotime((string)($b['date'] ?? '')) ?: 0;
                    return $bTime <=> $aTime;
                });
                $visibleStaffList = $selectedStaffType === 'all'
                    ? $dbStaffList
                    : array_values(array_filter($dbStaffList, fn($staffRow) => $staffFolderKeyFromType((string)($staffRow['staff_type'] ?? '')) === $selectedStaffType));
                if ($selectedStaffId > 0 && empty($staffById[$selectedStaffId])) {
                    $selectedStaffId = 0;
                }
                $visibleStaffFolderRecords = $selectedStaffType === 'all'
                    ? $staffFolderRecords
                    : array_values(array_filter($staffFolderRecords, fn($record) => (string)($record['folder_type'] ?? '') === $selectedStaffType));
                if ($selectedStaffId > 0) {
                    $visibleStaffFolderRecords = array_values(array_filter($visibleStaffFolderRecords, fn($record) => (int)($record['staff_id'] ?? 0) === $selectedStaffId));
                }
                $selectedStaffName = $selectedStaffId > 0 && !empty($staffById[$selectedStaffId])
                    ? (trim(($staffById[$selectedStaffId]['first_name'] ?? '') . ' ' . ($staffById[$selectedStaffId]['last_name'] ?? '')) ?: 'Staff #' . $selectedStaffId)
                    : '';
                ?>
                <div class="space-y-4 sm:space-y-5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-base sm:text-xl font-bold text-gray-900 flex items-center gap-2"><?php echo iconSvg('stethoscope', 'w-5 h-5 text-emerald-600'); ?> Healthcare Staff Registry</h2>
                        <button type="button" data-floating-modal-open="staff-tools" class="rounded-lg bg-emerald-700 px-4 py-2 text-xs font-bold text-white shadow-sm transition hover:bg-emerald-800">Open Staff Account Tools</button>
                    </div>

                    <div id="staff-folder-live-panel" class="staff-folder-panel bg-white rounded-xl shadow-sm border border-gray-100 p-4">
                        <div class="mb-3 flex items-center justify-between gap-2">
                            <div>
                                <h3 class="text-sm font-black text-gray-900">Staff Folders</h3>
                                <p class="text-xs text-gray-500">Open a folder to view personnel and assigned resident records by role.</p>
                            </div>
                            <?php if ($selectedStaffType !== 'all' || $selectedStaffId > 0): ?>
                                <a href="<?php echo esc(tabUrl('staff')); ?>" class="text-xs font-bold text-pink-600 hover:underline">Show all</a>
                            <?php endif; ?>
                        </div>
                        <div class="staff-folder-grid">
                            <?php foreach ($staffFolders as $folderKey => $folderMeta): ?>
                                <?php $folderActive = $selectedStaffType === $folderKey; ?>
                                <a href="<?php echo esc(tabUrl('staff', false, ['staff_type' => $folderKey])); ?>" class="staff-folder-card <?php echo $folderActive ? 'is-active' : ''; ?>" <?php echo $folderActive ? 'aria-current="page"' : ''; ?>>
                                    <span class="staff-folder-icon" aria-hidden="true"></span>
                                    <span class="staff-folder-name"><?php echo esc($folderMeta['label']); ?></span>
                                    <span class="staff-folder-count"><?php echo (int)$folderMeta['records']; ?> records</span>
                                    <span class="staff-folder-count"><?php echo (int)$folderMeta['people']; ?> personnel</span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <?php if ($selectedStaffId > 0): ?>
                        <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-lime-200 bg-lime-50 px-4 py-3 text-xs">
                            <span class="font-bold text-lime-900">Showing assigned resident records for <?php echo esc($selectedStaffName); ?>.</span>
                            <a href="<?php echo esc(tabUrl('staff', false, ['staff_type' => $selectedStaffType])); ?>" class="font-black text-lime-900 hover:underline">Clear staff filter</a>
                        </div>
                    <?php endif; ?>

                    <div id="staff-personnel-live-panel" class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="w-full text-xs min-w-[700px]">
                                <thead class="bg-gray-50 text-gray-500 uppercase">
                                    <tr>
                                        <th class="px-3 py-2.5 text-left">Staff ID</th>
                                        <th class="px-3 py-2.5 text-left">Full Name</th>
                                        <th class="px-3 py-2.5 text-left">Position</th>
                                        <th class="px-3 py-2.5 text-left">License No.</th>
                                        <th class="px-3 py-2.5 text-left">Contact</th>
                                        <th class="px-3 py-2.5 text-left">Status</th>
                                        <th class="px-3 py-2.5 text-left">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php foreach ($visibleStaffList as $st): ?>
                                        <?php
                                        $staffRowName = trim(($st['first_name'] ?? '') . ' ' . ($st['last_name'] ?? '')) ?: 'Staff #' . (int)$st['id'];
                                        $staffRowFolderType = $staffFolderKeyFromType((string)($st['staff_type'] ?? ''));
                                        $staffRowIsSelected = $selectedStaffId === (int)$st['id'];
                                        ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-3 py-2.5 font-mono font-bold text-gray-500">STF-<?php echo $st['id']; ?></td>
                                            <td class="px-3 py-2.5">
                                                <a href="<?php echo esc(tabUrl('staff', false, ['staff_type' => $staffRowFolderType, 'staff_id' => (int)$st['id']])); ?>" class="inline-flex items-center gap-2 rounded-lg px-2 py-1 font-black <?php echo $staffRowIsSelected ? 'bg-lime-100 text-lime-900' : 'text-gray-900 hover:bg-lime-50 hover:text-lime-900'; ?>">
                                                    <?php echo esc($staffRowName); ?>
                                                    <span class="text-[10px] font-bold text-gray-500">View records</span>
                                                </a>
                                            </td>
                                            <td class="px-3 py-2.5 text-purple-900 font-semibold"><?php echo esc($st['staff_type']); ?></td>
                                            <td class="px-3 py-2.5 font-mono text-gray-600"><?php echo esc($st['license_number'] ?? 'N/A'); ?></td>
                                            <td class="px-3 py-2.5 text-gray-600"><?php echo esc($st['phone_number'] ?? $st['email']); ?></td>
                                            <td class="px-3 py-2.5">
                                                <span class="px-2 py-0.5 rounded-full font-bold <?php echo $st['is_active'] ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                                                    <?php echo $st['is_active'] ? 'Active' : 'Inactive'; ?>
                                                </span>
                                            </td>
                                            <td class="px-3 py-2.5">
                                                <form method="post" class="inline">
                                                    <input type="hidden" name="action" value="toggle_staff_status">
                                                    <input type="hidden" name="staff_id" value="<?php echo $st['id']; ?>">
                                                    <input type="hidden" name="new_status" value="<?php echo $st['is_active'] ? 0 : 1; ?>">
                                                    <button type="submit" class="px-2.5 py-1 text-[11px] font-bold rounded <?php echo $st['is_active'] ? 'bg-amber-100 text-amber-800' : 'bg-green-100 text-green-800'; ?>">
                                                        <?php echo $st['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($visibleStaffList)): ?>
                                        <tr>
                                            <td colspan="7" class="px-3 py-8 text-center text-gray-400">No staff records found in this folder.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div id="staff-records-live-panel" class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-4 py-3">
                            <div>
                                <h3 class="text-sm font-black text-gray-900">Assigned Resident Records</h3>
                                <p class="text-xs text-gray-500">Records connected to the staff/admin folder currently open.</p>
                            </div>
                            <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-black text-emerald-800"><?php echo count($visibleStaffFolderRecords); ?> records</span>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[820px] text-xs">
                                <thead class="bg-gray-50 text-gray-500 uppercase">
                                    <tr>
                                        <th class="px-3 py-2.5 text-left">Record</th>
                                        <th class="px-3 py-2.5 text-left">Resident</th>
                                        <th class="px-3 py-2.5 text-left">Assigned Staff/Admin</th>
                                        <th class="px-3 py-2.5 text-left">Details</th>
                                        <th class="px-3 py-2.5 text-left">Status</th>
                                        <th class="px-3 py-2.5 text-left">Date</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php foreach (array_slice($visibleStaffFolderRecords, 0, 120) as $record): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-3 py-2.5">
                                                <span class="font-mono font-bold text-gray-500">#<?php echo esc($record['record_id']); ?></span>
                                                <span class="ml-2 rounded-full bg-teal-50 px-2 py-0.5 font-bold text-teal-800"><?php echo esc($record['record_type']); ?></span>
                                            </td>
                                            <td class="px-3 py-2.5">
                                                <p class="font-bold text-gray-900"><?php echo esc($record['resident_name'] ?: 'Resident record'); ?></p>
                                                <?php if (!empty($record['barangay'])): ?><p class="text-[11px] text-gray-500"><?php echo esc($record['barangay']); ?></p><?php endif; ?>
                                            </td>
                                            <td class="px-3 py-2.5">
                                                <a href="<?php echo esc(tabUrl('staff', false, ['staff_type' => $record['folder_type'], 'staff_id' => (int)($record['staff_id'] ?? 0)])); ?>" class="font-black text-gray-900 hover:text-lime-900 hover:underline">
                                                    <?php echo esc($record['staff_name']); ?>
                                                </a>
                                            </td>
                                            <td class="px-3 py-2.5 text-gray-600"><?php echo esc($record['title']); ?></td>
                                            <td class="px-3 py-2.5"><span class="rounded-full bg-lime-50 px-2 py-0.5 font-bold text-lime-800"><?php echo esc($record['status'] ?: 'Recorded'); ?></span></td>
                                            <td class="px-3 py-2.5 text-gray-500"><?php echo esc($record['date'] ? date('M d, Y', strtotime((string)$record['date'])) : 'No date'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($visibleStaffFolderRecords)): ?>
                                        <tr>
                                            <td colspan="6" class="px-3 py-8 text-center text-gray-400">No assigned resident records found in this folder yet.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- RESIDENT REGISTRY TAB -->
            <?php if ($tab === 'residents'): ?>
                <?php renderAdminFloatingToolsModal($pdo, 'resident-tools', 'Resident Registry Tools', 'Create and update resident registry records without leaving the table.', 'residents'); ?>
                <div class="space-y-4 sm:space-y-5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-base sm:text-xl font-bold text-gray-900 flex items-center gap-2"><?php echo iconSvg('check', 'w-5 h-5 text-teal-600'); ?> Municipal Resident Registry</h2>
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" data-floating-modal-open="resident-tools" class="rounded-lg bg-emerald-700 px-4 py-2 text-xs font-bold text-white shadow-sm transition hover:bg-emerald-800">Open Resident Registry Tools</button>
                            <span class="text-xs bg-teal-100 text-teal-800 font-bold px-3 py-1 rounded-full">Total: <?php echo count($dbResidentList); ?> Residents</span>
                        </div>
                    </div>

                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="w-full text-xs min-w-[700px]">
                                <thead class="bg-gray-50 text-gray-500 uppercase">
                                    <tr>
                                        <th class="px-3 py-2.5 text-left">Resident ID</th>
                                        <th class="px-3 py-2.5 text-left">Full Name</th>
                                        <th class="px-3 py-2.5 text-left">Age / Sex</th>
                                        <th class="px-3 py-2.5 text-left">Barangay & Address</th>
                                        <th class="px-3 py-2.5 text-left">Contact No.</th>
                                        <th class="px-3 py-2.5 text-left">PhilHealth ID</th>
                                        <th class="px-3 py-2.5 text-left">Email Address</th>
                                        <th class="px-3 py-2.5 text-left">Registered Date</th>
                                        <th class="px-3 py-2.5 text-left">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php foreach ($dbResidentList as $res): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-3 py-2.5 font-mono font-bold text-gray-500">RES-<?php echo $res['id']; ?></td>
                                            <td class="px-3 py-2.5 font-bold text-gray-900"><?php echo esc(trim(($res['first_name'] ?? '') . ' ' . ($res['middle_name'] ?? '') . ' ' . ($res['last_name'] ?? ''))); ?></td>
                                            <td class="px-3 py-2.5 text-gray-700"><?php echo esc(($res['age'] ?? 'N/A') . ' yrs - ' . ($res['sex'] ?? 'N/A')); ?></td>
                                            <td class="px-3 py-2.5 font-semibold text-purple-900"><?php echo esc($res['barangay'] ?? 'Nasugbu'); ?></td>
                                            <td class="px-3 py-2.5 text-gray-600 font-mono"><?php echo esc($res['contact_number'] ?: 'N/A'); ?></td>
                                            <td class="px-3 py-2.5 font-mono text-gray-600"><?php echo esc($res['philhealth_id'] ?: 'N/A'); ?></td>
                                            <td class="px-3 py-2.5 text-gray-600 font-mono"><?php echo esc($res['email'] ?: 'N/A'); ?></td>
                                            <td class="px-3 py-2.5 text-gray-500"><?php echo esc(date('M d, Y', strtotime($res['created_at']))); ?></td>
                                            <td class="px-3 py-2.5">
                                                <form method="post"><input type="hidden" name="action" value="update_resident_status"><input type="hidden" name="resident_id" value="<?php echo (int)$res['id']; ?>"><input type="hidden" name="new_status" value="<?php echo !empty($res['is_active']) ? 0 : 1; ?>"><button class="rounded px-2 py-1 font-bold <?php echo !empty($res['is_active']) ? 'bg-amber-100 text-amber-800' : 'bg-green-100 text-green-800'; ?>"><?php echo !empty($res['is_active']) ? 'Deactivate' : 'Activate'; ?></button></form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- OPD CONSULTATIONS TAB -->
            <?php if ($tab === 'consultations'): ?>
                <div class="space-y-4 sm:space-y-5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-base sm:text-xl font-bold text-gray-900 flex items-center gap-2"><?php echo iconSvg('activity', 'w-5 h-5 text-blue-600'); ?> OPD Consultations</h2>
                        <span class="text-xs bg-blue-100 text-blue-800 font-bold px-3 py-1 rounded-full">Total: <?php echo count($dbConsultationsList); ?> Consultations</span>
                    </div>
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="w-full text-xs min-w-[800px]">
                                <thead class="bg-gray-50 text-gray-500 uppercase">
                                    <tr>
                                        <th class="px-3 py-2.5 text-left">Consultation ID</th>
                                        <th class="px-3 py-2.5 text-left">Patient Name</th>
                                        <th class="px-3 py-2.5 text-left">Barangay</th>
                                        <th class="px-3 py-2.5 text-left">Chief Complaint</th>
                                        <th class="px-3 py-2.5 text-left">Diagnosis</th>
                                        <th class="px-3 py-2.5 text-left">Date</th>
                                        <th class="px-3 py-2.5 text-left">Clinical Update</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php foreach ($dbConsultationsList as $csl): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-3 py-2.5 font-mono font-bold text-gray-500">CSL-<?php echo $csl['id']; ?></td>
                                            <td class="px-3 py-2.5 font-bold text-gray-900"><?php echo esc(($csl['first_name'] ?? '') . ' ' . ($csl['last_name'] ?? '')); ?></td>
                                            <td class="px-3 py-2.5 text-purple-900"><?php echo esc($csl['barangay'] ?? 'N/A'); ?></td>
                                            <td class="px-3 py-2.5 text-gray-700"><?php echo esc(substr($csl['chief_complaint'] ?? '', 0, 30)); ?></td>
                                            <td class="px-3 py-2.5 text-gray-700"><?php echo esc($csl['diagnosis'] ?? 'N/A'); ?></td>
                                            <td class="px-3 py-2.5 text-gray-500"><?php echo esc(date('M d, Y', strtotime($csl['consultation_date']))); ?></td>
                                            <td class="px-3 py-2.5">
                                                <form method="post" class="flex min-w-[520px] gap-1"><input type="hidden" name="action" value="update_consultation"><input type="hidden" name="consultation_id" value="<?php echo (int)$csl['id']; ?>"><input name="diagnosis" value="<?php echo esc($csl['diagnosis'] ?? ''); ?>" placeholder="Diagnosis" class="w-28 rounded border p-1"><input name="treatment_plan" value="<?php echo esc($csl['treatment_plan'] ?? $csl['consultation_notes'] ?? ''); ?>" placeholder="Treatment plan" class="w-32 rounded border p-1"><select name="physician_id" class="rounded border p-1"><?php foreach ($dbStaffList as $provider): ?><option value="<?php echo (int)$provider['id']; ?>" <?php echo (int)$csl['physician_id'] === (int)$provider['id'] ? 'selected' : ''; ?>><?php echo esc($provider['first_name'] . ' ' . $provider['last_name']); ?></option><?php endforeach; ?></select><select name="consultation_status" class="rounded border p-1 font-bold text-gray-800">
                                                        <option value="Scheduled" <?php echo ($csl['consultation_status'] ?? '') === 'Scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                                                        <option value="In Progress" <?php echo ($csl['consultation_status'] ?? '') === 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                                                        <option value="Completed" <?php echo ($csl['consultation_status'] ?? '') === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                                                        <option value="Cancelled" <?php echo ($csl['consultation_status'] ?? '') === 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                                        <option value="Referred" <?php echo ($csl['consultation_status'] ?? '') === 'Referred' ? 'selected' : ''; ?>>Referred</option>
                                                    </select><button class="rounded bg-blue-600 px-2 text-white font-bold">Save</button></form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php if (empty($dbConsultationsList)): ?>
                                <div class="p-4 text-center text-gray-500">No consultation records found.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- MATERNAL HEALTH TAB -->
            <?php if ($tab === 'maternal'): ?>
                <div class="space-y-4 sm:space-y-5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-base sm:text-xl font-bold text-gray-900 flex items-center gap-2"><?php echo iconSvg('baby', 'w-5 h-5 text-pink-600'); ?> Maternal Health Cases</h2>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-xs bg-pink-100 text-pink-800 font-bold px-3 py-1 rounded-full">Total: <?php echo count($dbMaternalCases); ?> Cases</span>
                        </div>
                    </div>
                    <div class="maternal-inline-tools rounded-2xl border border-emerald-100 bg-white p-4 shadow-sm ring-1 ring-emerald-100">
                        <style>.maternal-inline-tools .admin-tool-panel { position: relative; inset: auto; width: 100%; box-shadow: none; }</style>
                        <div class="mb-3 flex items-center gap-2">
                            <?php echo iconSvg('baby', 'h-5 w-5 text-pink-600'); ?>
                            <div>
                                <h3 class="text-sm font-black text-gray-900">Maternal Health Tools</h3>
                                <p class="text-xs text-gray-500">Create pregnancy cases for female residents.</p>
                            </div>
                        </div>
                        <?php renderAdminExtendedPanel($pdo, 'maternal'); ?>
                    </div>
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="w-full text-xs min-w-[850px]">
                                <thead class="bg-gray-50 text-gray-500 uppercase">
                                    <tr>
                                        <th class="px-3 py-2.5 text-left">Case ID</th>
                                        <th class="px-3 py-2.5 text-left">Patient Name</th>
                                        <th class="px-3 py-2.5 text-left">Barangay</th>
                                        <th class="px-3 py-2.5 text-left">LMP</th>
                                        <th class="px-3 py-2.5 text-left">EDC</th>
                                        <th class="px-3 py-2.5 text-left">Blood Type</th>
                                        <th class="px-3 py-2.5 text-left">Status</th>
                                        <th class="px-3 py-2.5 text-left">Update</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php foreach ($dbMaternalCases as $mtl): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-3 py-2.5 font-mono font-bold text-gray-500">MTL-<?php echo $mtl['id']; ?></td>
                                            <td class="px-3 py-2.5 font-bold text-gray-900"><?php echo esc(($mtl['first_name'] ?? '') . ' ' . ($mtl['last_name'] ?? '')); ?></td>
                                            <td class="px-3 py-2.5 text-purple-900"><?php echo esc($mtl['barangay'] ?? 'N/A'); ?></td>
                                            <td class="px-3 py-2.5 text-gray-600"><?php echo esc(date('M d, Y', strtotime($mtl['lmp'] ?? 'now'))); ?></td>
                                            <td class="px-3 py-2.5 text-gray-600"><?php echo esc(date('M d, Y', strtotime($mtl['edc'] ?? 'now'))); ?></td>
                                            <td class="px-3 py-2.5 font-bold"><?php echo esc($mtl['blood_type'] ?? 'N/A'); ?></td>
                                            <td class="px-3 py-2.5"><span class="px-2 py-0.5 rounded-full bg-pink-100 text-pink-800 font-bold"><?php echo esc($mtl['status'] ?? 'Active'); ?></span></td>
                                            <td class="px-3 py-2.5">
                                                <form method="post" class="flex gap-1"><input type="hidden" name="action" value="update_pregnancy"><input type="hidden" name="pregnancy_id" value="<?php echo (int)$mtl['id']; ?>"><select name="status" class="rounded border p-1">
                                                        <option>Active</option>
                                                        <option>Delivered</option>
                                                        <option>Completed</option>
                                                        <option>Referred</option>
                                                    </select><label class="flex items-center gap-1"><input type="checkbox" name="high_risk" <?php echo !empty($mtl['high_risk']) ? 'checked' : ''; ?>> High risk</label><button class="rounded bg-pink-600 px-2 text-white">Save</button></form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php if (empty($dbMaternalCases)): ?>
                                <div class="p-4 text-center text-gray-500">No maternal health cases found.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($tab === 'maternal'): ?>
                <div class="mt-6 grid gap-5 lg:grid-cols-2">
                    <div class="rounded-xl border border-rose-100 bg-white p-4 shadow-sm">
                        <h3 class="font-bold text-rose-900">Family Planning Registry <span class="text-xs text-gray-400">(<?php echo count($dbFamilyPlanningRecords); ?>)</span></h3>
                        <div class="mt-3 max-h-80 space-y-2 overflow-y-auto">
                            <?php foreach ($dbFamilyPlanningRecords as $fp): ?>
                                <div class="rounded-lg border bg-rose-50/40 p-3 text-xs">
                                    <p class="font-bold"><?php echo esc(($fp['first_name'] ?? '') . ' ' . ($fp['last_name'] ?? '')); ?> - <?php echo esc($fp['contraceptive_method']); ?></p>
                                    <p class="mt-1 text-gray-500"><?php echo esc($fp['acceptor_type']); ?> - Next visit: <?php echo esc($fp['next_visit_date'] ?: 'Not scheduled'); ?> - <?php echo esc($fp['status']); ?></p>
                                </div>
                            <?php endforeach; ?>
                            <?php if (!$dbFamilyPlanningRecords): ?><p class="py-4 text-center text-xs text-gray-400">No family planning records.</p><?php endif; ?>
                        </div>
                    </div>
                    <div class="rounded-xl border border-purple-100 bg-white p-4 shadow-sm">
                        <h3 class="font-bold text-purple-900">Maternal Referrals <span class="text-xs text-gray-400">(<?php echo count($dbMaternalReferrals); ?>)</span></h3>
                        <div class="mt-3 max-h-80 space-y-2 overflow-y-auto">
                            <?php foreach ($dbMaternalReferrals as $ref): ?>
                                <form method="post" class="rounded-lg border bg-purple-50/40 p-3 text-xs">
                                    <input type="hidden" name="action" value="update_maternal_referral"><input type="hidden" name="referral_id" value="<?php echo (int)$ref['id']; ?>">
                                    <p class="font-bold"><?php echo esc(($ref['first_name'] ?? '') . ' ' . ($ref['last_name'] ?? '')); ?> -> <?php echo esc($ref['referred_to']); ?></p>
                                    <p class="my-1 text-gray-500"><?php echo esc($ref['diagnosis']); ?> - <?php echo esc($ref['urgency']); ?></p>
                                    <div class="flex gap-2"><select name="referral_status" class="flex-1 rounded border p-1">
                                            <?php foreach (['Pending', 'Accepted', 'Completed', 'Cancelled'] as $referralStatusOption): ?>
                                                <option value="<?php echo esc($referralStatusOption); ?>" <?php echo ((string)($ref['referral_status'] ?? 'Pending') === $referralStatusOption) ? 'selected' : ''; ?>><?php echo esc($referralStatusOption); ?></option>
                                            <?php endforeach; ?>
                                        </select><button class="rounded bg-purple-700 px-3 text-white">Update</button></div>
                                </form>
                            <?php endforeach; ?>
                            <?php if (!$dbMaternalReferrals): ?><p class="py-4 text-center text-xs text-gray-400">No maternal referrals.</p><?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- IMMUNIZATION TAB -->
            <?php if ($tab === 'vaccination'): ?>
                <?php renderAdminFloatingToolsModal($pdo, 'immunization-tools', 'Immunization Tools', 'Create and update vaccination records without leaving Immunization.', 'vaccination'); ?>
                <div class="space-y-4 sm:space-y-5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-base sm:text-xl font-bold text-gray-900 flex items-center gap-2"><?php echo iconSvg('shield', 'w-5 h-5 text-emerald-600'); ?> Immunization Records</h2>
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" data-floating-modal-open="immunization-tools" class="rounded-lg bg-emerald-700 px-4 py-2 text-xs font-bold text-white shadow-sm transition hover:bg-emerald-800">Open Immunization Tools</button>
                            <span class="text-xs bg-emerald-100 text-emerald-800 font-bold px-3 py-1 rounded-full">Total: <?php echo count($dbVaccinationRecords); ?> Records</span>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="w-full text-xs min-w-[750px]">
                                <thead class="bg-gray-50 text-gray-500 uppercase">
                                    <tr>
                                        <th class="px-3 py-2.5 text-left">Record ID</th>
                                        <th class="px-3 py-2.5 text-left">Beneficiary</th>
                                        <th class="px-3 py-2.5 text-left">Barangay</th>
                                        <th class="px-3 py-2.5 text-left">Vaccine Name</th>
                                        <th class="px-3 py-2.5 text-left">Vaccination Date</th>
                                        <th class="px-3 py-2.5 text-left">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php foreach ($dbVaccinationRecords as $vcc): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-3 py-2.5 font-mono font-bold text-gray-500">VCC-<?php echo $vcc['id']; ?></td>
                                            <td class="px-3 py-2.5 font-bold text-gray-900"><?php echo esc(($vcc['first_name'] ?? '') . ' ' . ($vcc['last_name'] ?? '')); ?></td>
                                            <td class="px-3 py-2.5 text-purple-900"><?php echo esc($vcc['barangay'] ?? 'N/A'); ?></td>
                                            <td class="px-3 py-2.5 text-gray-700"><?php echo esc($vcc['vaccine_name'] ?? 'N/A'); ?></td>
                                            <td class="px-3 py-2.5 text-gray-600"><?php echo esc(date('M d, Y', strtotime($vcc['vaccination_date']))); ?></td>
                                            <td class="px-3 py-2.5"><span class="px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-800 font-bold"><?php echo esc($vcc['status'] ?? 'Completed'); ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php if (empty($dbVaccinationRecords)): ?>
                                <div class="p-4 text-center text-gray-500">No vaccination records found.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- DISEASE SURVEILLANCE TAB -->
            <?php if ($tab === 'disease'): ?>
                <?php renderAdminFloatingToolsModal($pdo, 'disease-tools', 'Disease Surveillance Tools', 'Create disease cases and update surveillance records without leaving the case list.', 'disease'); ?>
                <div class="space-y-4 sm:space-y-5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-base sm:text-xl font-bold text-gray-900 flex items-center gap-2"><?php echo iconSvg('alert', 'w-5 h-5 text-red-600'); ?> Disease Surveillance</h2>
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" data-floating-modal-open="disease-tools" class="rounded-lg bg-emerald-700 px-4 py-2 text-xs font-bold text-white shadow-sm transition hover:bg-emerald-800">Open Disease Surveillance Tools</button>
                            <span class="text-xs bg-red-100 text-red-800 font-bold px-3 py-1 rounded-full">Total: <?php echo count($dbDiseaseCases); ?> Cases</span>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="w-full text-xs min-w-[800px]">
                                <thead class="bg-gray-50 text-gray-500 uppercase">
                                    <tr>
                                        <th class="px-3 py-2.5 text-left">Case ID</th>
                                        <th class="px-3 py-2.5 text-left">Disease</th>
                                        <th class="px-3 py-2.5 text-left">Patient</th>
                                        <th class="px-3 py-2.5 text-left">Barangay</th>
                                        <th class="px-3 py-2.5 text-left">Case Date</th>
                                        <th class="px-3 py-2.5 text-left">Classification</th>
                                        <th class="px-3 py-2.5 text-left">Outcome</th>
                                        <th class="px-3 py-2.5 text-left">Update</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php foreach ($dbDiseaseCases as $dcs): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-3 py-2.5 font-mono font-bold text-gray-500">DCS-<?php echo $dcs['id']; ?></td>
                                            <td class="px-3 py-2.5 font-bold text-red-700"><?php echo esc($dcs['disease_name'] ?? 'Unknown'); ?></td>
                                            <td class="px-3 py-2.5 font-bold text-gray-900"><?php echo esc(($dcs['first_name'] ?? '') . ' ' . ($dcs['last_name'] ?? '')); ?></td>
                                            <td class="px-3 py-2.5 text-purple-900"><?php echo esc($dcs['barangay'] ?? 'N/A'); ?></td>
                                            <td class="px-3 py-2.5 text-gray-600"><?php echo esc(date('M d, Y', strtotime($dcs['case_date']))); ?></td>
                                            <td class="px-3 py-2.5"><span class="px-2 py-0.5 rounded-full bg-yellow-100 text-yellow-800 font-bold"><?php echo esc($dcs['status'] ?? 'Pending'); ?></span></td>
                                            <td class="px-3 py-2.5"><span class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-800 font-bold"><?php echo esc($dcs['outcome'] ?? 'Pending'); ?></span></td>
                                            <td class="px-3 py-2.5">
                                                <form method="post" class="flex min-w-[360px] gap-1"><input type="hidden" name="action" value="update_disease_case"><input type="hidden" name="case_id" value="<?php echo (int)$dcs['id']; ?>"><select name="classification" class="rounded border p-1">
                                                        <option>Suspected</option>
                                                        <option>Probable</option>
                                                        <option>Confirmed</option>
                                                    </select><select name="outcome" class="rounded border p-1">
                                                        <option>Active</option>
                                                        <option>Recovered</option>
                                                        <option>Referred</option>
                                                        <option>Died</option>
                                                    </select><label class="flex items-center gap-1"><input type="checkbox" name="reported_to_doh"> DOH</label><button class="rounded bg-red-600 px-2 text-white">Save</button></form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php if (empty($dbDiseaseCases)): ?>
                                <div class="p-4 text-center text-gray-500">No disease cases found.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- MEDICINE INVENTORY TAB -->
            <?php if ($tab === 'medicine'): ?>
                <?php renderAdminFloatingToolsModal($pdo, 'medicine-tools', 'Medicine Inventory Tools', 'Add, update, delete, and manage inventory items without leaving the stock list.', 'medicine'); ?>
                <div class="space-y-4 sm:space-y-5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-base sm:text-xl font-bold text-gray-900 flex items-center gap-2"><?php echo iconSvg('pill', 'w-5 h-5 text-orange-600'); ?> Medicine Inventory</h2>
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" data-floating-modal-open="medicine-tools" class="rounded-lg bg-emerald-700 px-4 py-2 text-xs font-bold text-white shadow-sm transition hover:bg-emerald-800">Open Medicine Inventory Tools</button>
                            <span class="text-xs bg-orange-100 text-orange-800 font-bold px-3 py-1 rounded-full">Total Items: <?php echo count($dbMedicineInventory); ?></span>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="w-full text-xs min-w-[900px]">
                                <thead class="bg-gray-50 text-gray-500 uppercase">
                                    <tr>
                                        <th class="px-3 py-2.5 text-left">Item ID</th>
                                        <th class="px-3 py-2.5 text-left">Generic Name</th>
                                        <th class="px-3 py-2.5 text-left">Brand Name</th>
                                        <th class="px-3 py-2.5 text-left">Quantity</th>
                                        <th class="px-3 py-2.5 text-left">Reorder Level</th>
                                        <th class="px-3 py-2.5 text-left">Unit Cost</th>
                                        <th class="px-3 py-2.5 text-left">Batch No.</th>
                                        <th class="px-3 py-2.5 text-left">Expiry Date</th>
                                        <th class="px-3 py-2.5 text-left">Status</th>
                                        <th class="px-3 py-2.5 text-left">Stock Adjustment</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php foreach ($dbMedicineInventory as $med): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-3 py-2.5 font-mono font-bold text-gray-500">MED-<?php echo $med['id']; ?></td>
                                            <td class="px-3 py-2.5 font-bold text-gray-900"><?php echo esc($med['generic_name'] ?? 'N/A'); ?></td>
                                            <td class="px-3 py-2.5 text-gray-700"><?php echo esc($med['brand_name'] ?? 'N/A'); ?></td>
                                            <td class="px-3 py-2.5 font-bold"><?php echo esc($med['quantity'] ?? 0); ?></td>
                                            <td class="px-3 py-2.5 text-gray-600"><?php echo esc($med['reorder_level'] ?? 10); ?></td>
                                            <td class="px-3 py-2.5 text-gray-600">PHP <?php echo esc(number_format($med['unit_price'] ?? 0, 2)); ?></td>
                                            <td class="px-3 py-2.5 font-mono text-gray-600"><?php echo esc($med['batch_number'] ?? 'N/A'); ?></td>
                                            <td class="px-3 py-2.5 text-gray-600"><?php echo esc(date('M d, Y', strtotime($med['expiry_date']))); ?></td>
                                            <td class="px-3 py-2.5">
                                                <span class="px-2 py-0.5 rounded-full font-bold <?php
                                                                                                if ($med['status'] === 'critical') echo 'bg-red-100 text-red-800';
                                                                                                elseif ($med['status'] === 'low') echo 'bg-yellow-100 text-yellow-800';
                                                                                                else echo 'bg-green-100 text-green-800';
                                                                                                ?>">
                                                    <?php echo esc(ucfirst($med['status'] ?? 'adequate')); ?>
                                                </span>
                                            </td>
                                            <td class="px-3 py-2.5">
                                                <form method="post" class="flex min-w-[280px] gap-1"><input type="hidden" name="action" value="adjust_medicine_stock"><input type="hidden" name="medicine_id" value="<?php echo (int)$med['id']; ?>"><input required type="number" name="quantity_change" placeholder="+/- qty" class="w-20 rounded border p-1"><input name="reason" placeholder="Reason" class="w-28 rounded border p-1"><button class="rounded bg-orange-600 px-2 text-white">Apply</button></form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php if (empty($dbMedicineInventory)): ?>
                                <div class="p-4 text-center text-gray-500">No medicine inventory records found.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- VITAL STATISTICS TAB -->
            <?php if ($tab === 'vital'): ?>
                <div class="space-y-4 sm:space-y-5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-base sm:text-xl font-bold text-gray-900 flex items-center gap-2"><?php echo iconSvg('file', 'w-5 h-5 text-purple-600'); ?> Vital Statistics</h2>
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" data-floating-modal-open="vital-tools" class="rounded-lg bg-emerald-700 px-4 py-2 text-xs font-bold text-white shadow-sm transition hover:bg-emerald-800">Open Vital Record Tools</button>
                            <span class="text-xs bg-purple-100 text-purple-800 font-bold px-3 py-1 rounded-full">Total: <?php echo count($dbVitalStatistics); ?> Records</span>
                        </div>
                    </div>
                    <div data-floating-modal="vital-tools" class="fixed inset-0 z-[90] hidden bg-slate-950/45 p-3 backdrop-blur-sm sm:p-6">
                        <div class="mx-auto mt-8 max-h-[86vh] w-full max-w-6xl overflow-y-auto rounded-2xl border border-white/70 bg-white p-4 shadow-2xl sm:p-5">
                            <div class="mb-4 flex items-center justify-between gap-3 border-b border-gray-100 pb-3">
                                <div>
                                    <h3 class="text-base font-black text-gray-900">Vital Record Tools</h3>
                                    <p class="text-xs text-gray-500">Create birth and death records without leaving Vital Statistics.</p>
                                </div>
                                <button type="button" data-floating-modal-close class="grid h-9 w-9 place-items-center rounded-full border border-gray-200 bg-gray-50 text-lg font-black text-gray-600 hover:bg-gray-100" aria-label="Close vital record tools">&times;</button>
                            </div>
                            <?php renderAdminExtendedPanel($pdo, 'vital'); ?>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="w-full text-xs min-w-[750px]">
                                <thead class="bg-gray-50 text-gray-500 uppercase">
                                    <tr>
                                        <th class="px-3 py-2.5 text-left">Record ID</th>
                                        <th class="px-3 py-2.5 text-left">Type</th>
                                        <th class="px-3 py-2.5 text-left">Name</th>
                                        <th class="px-3 py-2.5 text-left">Location</th>
                                        <th class="px-3 py-2.5 text-left">Date</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php foreach ($dbVitalStatistics as $vital): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-3 py-2.5 font-mono font-bold text-gray-500">VIT-<?php echo $vital['id']; ?></td>
                                            <td class="px-3 py-2.5">
                                                <span class="px-2 py-0.5 rounded-full font-bold <?php echo $vital['type'] === 'Birth' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-800'; ?>">
                                                    <?php echo esc($vital['type']); ?>
                                                </span>
                                            </td>
                                            <td class="px-3 py-2.5 font-bold text-gray-900"><?php echo esc($vital['child_name'] ?? $vital['deceased_name'] ?? 'N/A'); ?></td>
                                            <td class="px-3 py-2.5 text-purple-900"><?php echo esc($vital['location'] ?? 'N/A'); ?></td>
                                            <td class="px-3 py-2.5 text-gray-600"><?php echo esc(date('M d, Y', strtotime($vital['date_recorded']))); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php if (empty($dbVitalStatistics)): ?>
                                <div class="p-4 text-center text-gray-500">No vital statistics records found.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- AUDIT LOGS TAB -->

            <?php if ($tab === 'audit'): ?>
                <div class="space-y-4 sm:space-y-5">
                    <div class="flex items-center justify-between">
                        <h2 class="text-base sm:text-xl font-black text-slate-900 flex items-center gap-2">
                            <span class="p-1.5 bg-slate-100 rounded-lg text-slate-700"><?php echo iconSvg('database', 'w-5 h-5'); ?></span>
                            System Audit Trail
                        </h2>
                        <span class="text-xs font-semibold text-slate-500 bg-slate-100 px-3 py-1 rounded-full border border-slate-200">50 Recent Logs</span>
                    </div>
                    <div class="bg-white rounded-2xl shadow-xs border border-slate-200/80 overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="w-full text-xs min-w-[800px]">
                                <thead class="bg-slate-800 text-slate-100 uppercase font-bold text-[11px] tracking-wider border-b border-slate-700">
                                    <tr>
                                        <th class="px-4 py-3 text-left">ID</th>
                                        <th class="px-4 py-3 text-left">User</th>
                                        <th class="px-4 py-3 text-left">Action</th>
                                        <th class="px-4 py-3 text-left">Table / Record</th>
                                        <th class="px-4 py-3 text-left">Timestamp</th>
                                        <th class="px-4 py-3 text-left">IP Address</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 bg-white">
                                    <?php foreach ($dbAuditLogsList as $log): 
                                        $userName = trim(($log['first_name'] ?? '') . ' ' . ($log['last_name'] ?? ''));
                                        if (!$userName) {
                                            $userName = !empty($log['user_id']) ? 'Health Worker #' . $log['user_id'] : 'Municipal Health Officer';
                                        }
                                    ?>
                                        <tr class="hover:bg-slate-50/80 transition-colors">
                                            <td class="px-4 py-3 font-mono font-bold text-slate-400">#<?php echo $log['id']; ?></td>
                                            <td class="px-4 py-3 font-bold text-slate-900"><?php echo esc($userName); ?></td>
                                            <td class="px-4 py-3 text-purple-900 font-semibold"><?php echo esc($log['action']); ?></td>
                                            <td class="px-4 py-3 font-mono text-slate-500">
                                                <?php if (!empty($log['table_name'])): ?>
                                                    <span class="px-1.5 py-0.5 rounded bg-slate-100 text-slate-700 font-medium text-[10px]"><?php echo esc($log['table_name']); ?></span>
                                                <?php endif; ?>
                                                <?php echo !empty($log['record_id']) ? '#' . esc($log['record_id']) : ''; ?>
                                            </td>
                                            <td class="px-4 py-3 text-slate-500 font-mono text-[11px]"><?php echo esc(date('Y-m-d H:i:s', strtotime($log['timestamp']))); ?></td>
                                            <td class="px-4 py-3 font-mono text-slate-400 text-[11px]"><?php echo esc($log['ip_address'] ?? '127.0.0.1'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- REPORTS & ANALYTICS TAB -->
            <?php if ($tab === 'reports'): ?>
                <div class="space-y-4 sm:space-y-5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-base sm:text-xl font-bold text-gray-900 flex items-center gap-2"><?php echo iconSvg('bar', 'w-5 h-5 text-purple-600'); ?> Database Analytics & Reports</h2>
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" data-report-modal-open class="rounded-lg bg-emerald-700 px-4 py-2 text-xs font-bold text-white shadow-sm transition hover:bg-emerald-800">Open Report Generator</button>
                            <a href="?tab=reports&amp;export=summary" class="rounded-lg bg-purple-700 px-4 py-2 text-xs font-bold text-white">Export CSV Summary</a>
                        </div>
                    </div>

                    <div data-report-modal-backdrop class="fixed inset-0 z-[90] hidden bg-slate-950/45 p-3 backdrop-blur-sm sm:p-6">
                        <div data-report-modal-panel class="mx-auto mt-8 max-h-[86vh] w-full max-w-5xl overflow-y-auto rounded-2xl border border-white/70 bg-white p-4 shadow-2xl sm:p-5">
                            <div class="mb-4 flex items-center justify-between gap-3 border-b border-gray-100 pb-3">
                                <div>
                                    <h3 class="text-base font-black text-gray-900">DOH & Health Report Generator</h3>
                                    <p class="text-xs text-gray-500">Generate FHSIS, PIDSR, and NTP-TB reports without leaving analytics.</p>
                                </div>
                                <button type="button" data-report-modal-close class="grid h-9 w-9 place-items-center rounded-full border border-gray-200 bg-gray-50 text-lg font-black text-gray-600 hover:bg-gray-100" aria-label="Close report generator">&times;</button>
                            </div>
                            <?php renderAdminExtendedPanel($pdo, 'reports'); ?>
                        </div>
                    </div>

                    <?php
                    $reportMonths = array_column($reportsMonthlySignals, 'label') ?: ['Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug'];
                    $consultSeries = array_map('intval', array_column($reportsMonthlySignals, 'consultations')) ?: [2, 4, 5, 7, 8, 10];
                    $caseSeries = array_map('intval', array_column($reportsMonthlySignals, 'disease_cases')) ?: [1, 1, 2, 2, 3, 4];
                    $vaccineSeries = array_map('intval', array_column($reportsMonthlySignals, 'vaccinations')) ?: [3, 5, 7, 8, 10, 12];
                    $predictiveSeries = [];
                    foreach ($consultSeries as $index => $value) {
                        $prev = $consultSeries[max(0, $index - 1)] ?? $value;
                        $cases = $caseSeries[$index] ?? 0;
                        $vaccines = $vaccineSeries[$index] ?? 0;
                        $predictiveSeries[] = max(1, (int)round(($value * 0.52) + ($prev * 0.23) + ($cases * 1.35) + ($vaccines * 0.18)));
                    }
                    $nextPrediction = max(1, (int)round((end($predictiveSeries) ?: 1) * 1.12 + (end($caseSeries) ?: 0)));
                    $actualSeries = $consultSeries;
                    $forecastSeries = $predictiveSeries;
                    $chartMax = max(max($actualSeries ?: [0]), max($forecastSeries ?: [0]), max($caseSeries ?: [0]), max($vaccineSeries ?: [0]), 10);
                    $chartWidth = 560;
                    $chartHeight = 220;
                    $chartPadding = 26;
                    $chartInnerWidth = $chartWidth - ($chartPadding * 2);
                    $chartInnerHeight = $chartHeight - ($chartPadding * 2);

                    $pointList = function ($values) use ($chartPadding, $chartHeight, $chartInnerWidth, $chartInnerHeight, $chartMax) {
                        $points = [];
                        $count = count($values);
                        foreach ($values as $index => $value) {
                            $x = $chartPadding + ($chartInnerWidth * $index / max(1, $count - 1));
                            $y = $chartHeight - $chartPadding - (($value / $chartMax) * $chartInnerHeight);
                            $points[] = [$x, $y];
                        }
                        return $points;
                    };

                    $actualPoints = $pointList($actualSeries);
                    $forecastPoints = $pointList($forecastSeries);
                    $casePoints = $pointList($caseSeries);
                    $vaccinePoints = $pointList($vaccineSeries);
                    $actualPath = implode(' ', array_map(fn($pt) => $pt[0] . ',' . $pt[1], $actualPoints));
                    $forecastPath = implode(' ', array_map(fn($pt) => $pt[0] . ',' . $pt[1], $forecastPoints));
                    $casePath = implode(' ', array_map(fn($pt) => $pt[0] . ',' . $pt[1], $casePoints));
                    $vaccinePath = implode(' ', array_map(fn($pt) => $pt[0] . ',' . $pt[1], $vaccinePoints));
                    $maxMapCases = max(1, ...array_map(fn($row) => (int)($row['disease_cases'] ?? 0), $reportsMapStats ?: [['disease_cases' => 1]]));
                    $hotspot = $reportsMapStats[0] ?? ['barangay' => 'No barangay data', 'disease_cases' => 0, 'residents' => 0, 'recent_cases' => 0];
                    $staffPieTotal = max(1, array_sum(array_map(fn($row) => (int)$row['count'], $reportsStaffUserMix)));
                    $pieColors = ['#7c3aed', '#059669', '#0284c7', '#dc2626', '#f59e0b', '#475569', '#14b8a6'];
                    $pieOffset = 25;
                    ?>

                    <form method="get" class="grid gap-3 rounded-xl border border-gray-100 bg-white p-4 text-xs shadow-sm sm:grid-cols-[1fr_1fr_auto_auto]">
                        <input type="hidden" name="tab" value="reports">
                        <label class="font-bold text-gray-700">Barangay
                            <select name="analytics_barangay" class="mt-1 w-full rounded-lg border border-gray-300 p-2 font-normal">
                                <option value="">All Nasugbu barangays</option>
                                <?php foreach ($dbBarangayOptions as $barangayOption): ?>
                                    <option value="<?php echo esc($barangayOption['name']); ?>" <?php echo $reportsSelectedBarangay === $barangayOption['name'] ? 'selected' : ''; ?>><?php echo esc($barangayOption['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="font-bold text-gray-700">Disease
                            <select name="analytics_disease" class="mt-1 w-full rounded-lg border border-gray-300 p-2 font-normal">
                                <option value="">All disease cases</option>
                                <?php foreach ($reportsDiseaseOptions as $diseaseOption): ?>
                                    <option value="<?php echo esc($diseaseOption); ?>" <?php echo $reportsSelectedDisease === $diseaseOption ? 'selected' : ''; ?>><?php echo esc($diseaseOption); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <button class="self-end rounded-lg bg-purple-700 px-4 py-2 font-bold text-white">Apply Filters</button>
                        <a href="?tab=reports" class="self-end rounded-lg border border-gray-300 px-4 py-2 text-center font-bold text-gray-700">Reset</a>
                    </form>

                    <div class="space-y-4">
                        <div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-5 shadow-sm">
                            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <h3 class="font-bold text-gray-900 text-sm flex items-center gap-2">
                                        Nasugbu Barangay Health Map
                                        <span class="rounded bg-blue-50 px-2 py-0.5 text-[10px] font-bold text-blue-700 border border-blue-100">Live Map</span>
                                    </h3>
                                    <p class="text-[11px] text-gray-500">Bubble size and color intensity follow filtered disease cases per barangay.</p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="rounded-full bg-red-50 px-3 py-1 text-[10px] font-black text-red-700">Most cases: <?php echo esc($hotspot['barangay']); ?></span>
                                    <button type="button" id="recenterNasugbuMapBtn" class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100 transition-colors shadow-xs" title="Recenter on Nasugbu">
                                        <?php echo iconSvg('activity', 'w-3.5 h-3.5 shrink-0'); ?> Recenter
                                    </button>
                                </div>
                            </div>
                            <div id="nasugbuHealthMap" class="overflow-hidden rounded-lg border border-slate-200 bg-slate-50 shadow-inner" data-max-cases="<?php echo (int)$maxMapCases; ?>"></div>
                            <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-[11px] text-slate-600 bg-slate-50/80 px-3 py-2 rounded-lg border border-slate-100">
                                <div class="flex items-center gap-4">
                                    <span class="inline-flex items-center gap-1.5 font-semibold"><span class="h-3 w-3 rounded-full bg-emerald-500 border border-white shadow-xs"></span> Nasugbu Barangay</span>
                                    <span class="inline-flex items-center gap-1.5 font-semibold"><span class="h-3 w-3 rounded-full bg-red-500 border border-white shadow-xs"></span> Most Cases</span>
                                    <span class="font-semibold">Hover a dot to show barangay name</span>
                                </div>
                                <span class="font-medium text-slate-500">Nasugbu Focus - Clutter Free</span>
                            </div>
                            <script type="application/json" id="nasugbuHealthMapData">
                                <?php echo json_encode($reportsMapStats, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>
                            </script>
                        </div>

                        <div class="grid gap-4 lg:grid-cols-2">
                            <div class="rounded-xl border border-red-100 bg-white p-4 shadow-sm">
                                <p class="text-[11px] font-bold uppercase text-red-600">Barangay With Most Cases</p>
                                <p class="mt-1 text-2xl font-black text-gray-900"><?php echo esc($hotspot['barangay']); ?></p>
                                <div class="mt-3 grid grid-cols-3 gap-2 text-xs">
                                    <div class="rounded-lg bg-red-50 p-2">
                                        <p class="text-gray-500">Cases</p>
                                        <p class="font-black text-red-800"><?php echo (int)$hotspot['disease_cases']; ?></p>
                                    </div>
                                    <div class="rounded-lg bg-blue-50 p-2">
                                        <p class="text-gray-500">Residents</p>
                                        <p class="font-black text-blue-800"><?php echo (int)$hotspot['residents']; ?></p>
                                    </div>
                                    <div class="rounded-lg bg-amber-50 p-2">
                                        <p class="text-gray-500">30 Days</p>
                                        <p class="font-black text-amber-800"><?php echo (int)$hotspot['recent_cases']; ?></p>
                                    </div>
                                </div>
                            </div>
                            <div class="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                                <h3 class="font-bold text-gray-900 text-sm">Disease Cases per Barangay</h3>
                                <div class="mt-3 space-y-2">
                                    <?php foreach ($reportsDiseaseByBarangay as $row): ?>
                                        <div class="text-xs">
                                            <div class="flex justify-between gap-2"><span class="font-semibold text-gray-700"><?php echo esc($row['barangay']); ?> - <?php echo esc($row['disease_name'] ?: 'Unknown'); ?></span><span class="font-black text-red-700"><?php echo (int)$row['cases']; ?></span></div>
                                            <div class="mt-1 h-1.5 rounded-full bg-gray-100">
                                                <div class="h-1.5 rounded-full bg-red-500" style="width: <?php echo min(100, ((int)$row['cases'] / $maxMapCases) * 100); ?>%"></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (!$reportsDiseaseByBarangay): ?><p class="text-xs text-gray-500">No disease records match the selected filters.</p><?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-5 shadow-sm">
                        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                            <div>
                                <h3 class="font-bold text-gray-900 text-sm">Predictive Analytics Trend</h3>
                                <p class="text-[11px] text-gray-500">Consultations, disease cases, vaccinations, and predicted service load</p>
                            </div>
                            <div class="flex items-center gap-3 text-[10px] font-bold">
                                <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-emerald-500"></span>Consultations</span>
                                <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-red-500"></span>Cases</span>
                                <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-blue-500"></span>Vaccines</span>
                                <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-amber-400"></span>Prediction</span>
                            </div>
                        </div>

                        <svg viewBox="0 0 <?php echo $chartWidth; ?> <?php echo $chartHeight; ?>" class="w-full h-56 rounded-lg border border-gray-100 bg-gray-50/50 p-2">
                            <?php for ($i = 0; $i <= 4; $i++): ?>
                                <?php $gridValue = ($chartMax / 4) * $i; ?>
                                <?php $gridY = $chartHeight - $chartPadding - (($gridValue / $chartMax) * $chartInnerHeight); ?>
                                <line x1="<?php echo $chartPadding; ?>" x2="<?php echo $chartWidth - $chartPadding; ?>" y1="<?php echo $gridY; ?>" y2="<?php echo $gridY; ?>" stroke="#e5e7eb" stroke-dasharray="4 4" />
                            <?php endfor; ?>

                            <polyline fill="none" stroke="#10b981" stroke-width="3" points="<?php echo $actualPath; ?>" stroke-linecap="round" stroke-linejoin="round" />
                            <polyline fill="none" stroke="#ef4444" stroke-width="2.5" points="<?php echo $casePath; ?>" stroke-linecap="round" stroke-linejoin="round" />
                            <polyline fill="none" stroke="#3b82f6" stroke-width="2.5" points="<?php echo $vaccinePath; ?>" stroke-linecap="round" stroke-linejoin="round" />
                            <polyline fill="none" stroke="#f59e0b" stroke-width="3" stroke-dasharray="8 6" points="<?php echo $forecastPath; ?>" stroke-linecap="round" stroke-linejoin="round" />

                            <?php foreach ($actualPoints as $index => $point): ?>
                                <circle cx="<?php echo $point[0]; ?>" cy="<?php echo $point[1]; ?>" r="4" fill="#10b981" />
                                <text x="<?php echo $point[0]; ?>" y="<?php echo $chartHeight - 8; ?>" text-anchor="middle" fill="#64748b" font-size="10"><?php echo esc($reportMonths[$index]); ?></text>
                            <?php endforeach; ?>
                        </svg>

                        <div class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs">
                            <div class="rounded-lg border border-gray-200 bg-emerald-50 p-2.5">
                                <p class="text-gray-500">Consultations</p>
                                <p class="font-black text-emerald-800"><?php echo array_sum($consultSeries); ?></p>
                            </div>
                            <div class="rounded-lg border border-gray-200 bg-blue-50 p-2.5">
                                <p class="text-gray-500">Vaccinations</p>
                                <p class="font-black text-blue-800"><?php echo array_sum($vaccineSeries); ?></p>
                            </div>
                            <div class="rounded-lg border border-gray-200 bg-violet-50 p-2.5">
                                <p class="text-gray-500">Disease Cases</p>
                                <p class="font-black text-violet-800"><?php echo array_sum($caseSeries); ?></p>
                            </div>
                            <div class="rounded-lg border border-gray-200 bg-amber-50 p-2.5">
                                <p class="text-gray-500">Next Forecast</p>
                                <p class="font-black text-amber-800"><?php echo $nextPrediction; ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="grid md:grid-cols-3 gap-4">
                        <div class="bg-white rounded-xl p-4 sm:p-5 shadow-sm border border-gray-100 space-y-3">
                            <h3 class="font-bold text-gray-900 text-sm">Resident Distribution by Barangay</h3>
                            <?php foreach ($barangayStats as $b): ?>
                                <div class="flex items-center justify-between text-xs py-1 border-b border-gray-50">
                                    <span class="font-semibold text-gray-700"><?php echo esc($b['barangay'] ?: 'Nasugbu Poblacion'); ?></span>
                                    <span class="font-mono font-bold bg-blue-100 text-blue-800 px-2 py-0.5 rounded"><?php echo $b['count']; ?> residents</span>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="bg-white rounded-xl p-4 sm:p-5 shadow-sm border border-gray-100 space-y-3">
                            <h3 class="font-bold text-gray-900 text-sm">Staff Breakdown by Position</h3>
                            <?php foreach ($staffTypeStats as $st): ?>
                                <div class="flex items-center justify-between text-xs py-1 border-b border-gray-50">
                                    <span class="font-semibold text-gray-700"><?php echo esc($st['staff_type']); ?></span>
                                    <span class="font-mono font-bold bg-emerald-100 text-emerald-800 px-2 py-0.5 rounded"><?php echo $st['count']; ?> staff</span>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="bg-white rounded-xl p-4 sm:p-5 shadow-sm border border-gray-100">
                            <h3 class="font-bold text-gray-900 text-sm">Staff User Pie Chart</h3>
                            <div class="mt-4 flex items-center gap-4">
                                <svg viewBox="0 0 42 42" class="h-32 w-32 -rotate-90">
                                    <circle cx="21" cy="21" r="15.915" fill="transparent" stroke="#e5e7eb" stroke-width="8"></circle>
                                    <?php foreach ($reportsStaffUserMix as $index => $slice): ?>
                                        <?php $pct = ((int)$slice['count'] / $staffPieTotal) * 100; ?>
                                        <circle cx="21" cy="21" r="15.915" fill="transparent" stroke="<?php echo $pieColors[$index % count($pieColors)]; ?>" stroke-width="8" stroke-dasharray="<?php echo $pct; ?> <?php echo 100 - $pct; ?>" stroke-dashoffset="<?php echo $pieOffset; ?>"></circle>
                                        <?php $pieOffset -= $pct; ?>
                                    <?php endforeach; ?>
                                </svg>
                                <div class="min-w-0 flex-1 space-y-2 text-xs">
                                    <?php foreach ($reportsStaffUserMix as $index => $slice): ?>
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="min-w-0 truncate font-semibold text-gray-700"><span class="mr-1.5 inline-block h-2.5 w-2.5 rounded-full" style="background: <?php echo $pieColors[$index % count($pieColors)]; ?>"></span><?php echo esc($slice['label']); ?></span>
                                            <span class="font-black text-gray-900"><?php echo (int)$slice['count']; ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- RE-DESIGNED SYSTEM SETTINGS TAB -->
            <?php if ($tab === 'system' || $tab === 'settings'): ?>
                <?php renderAdminFloatingToolsModal($pdo, 'system-tools', 'System Maintenance Tools', 'Run maintenance, download backups, and restore database backups without leaving System Settings.', 'system'); ?>
                <div class="space-y-6">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 pb-4">
                        <div>
                            <h2 class="text-xl font-bold text-gray-900 flex items-center gap-2"><?php echo iconSvg('settings', 'w-6 h-6 text-purple-700'); ?> System & Facility Settings</h2>
                            <p class="text-xs text-gray-500 mt-1">Configure health office profile, SMTP gateways, and system maintenance controls</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" data-floating-modal-open="system-tools" class="rounded-lg bg-emerald-700 px-4 py-2 text-xs font-bold text-white shadow-sm transition hover:bg-emerald-800">Open System Tools</button>
                            <span class="text-xs font-bold bg-purple-100 text-purple-800 px-3 py-1 rounded-full">XAMPP Environment</span>
                        </div>
                    </div>

                    <div class="grid md:grid-cols-2 gap-6">
                        <!-- 1. RHU FACILITY PROFILE FORM -->
                        <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-200 space-y-4">
                            <div class="flex items-center gap-2 border-b border-gray-100 pb-3">
                                <span class="p-2 bg-purple-100 rounded-lg text-purple-700"><?php echo iconSvg('building', 'w-5 h-5'); ?></span>
                                <h3 class="font-bold text-gray-900 text-base">Facility & RHU Profile</h3>
                            </div>
                            <form method="post" class="space-y-4 text-xs">
                                <input type="hidden" name="action" value="update_rhu_info">
                                <div>
                                    <label class="block font-bold text-gray-700 mb-1">Facility Name</label>
                                    <input required name="rhu_name" value="<?php echo esc($rhuProfile['name']); ?>" class="w-full p-2.5 rounded-lg border border-gray-300 text-gray-900 focus:border-purple-600 focus:outline-none">
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block font-bold text-gray-700 mb-1">Municipality</label>
                                        <input required name="municipality" value="<?php echo esc($rhuProfile['municipality']); ?>" class="w-full p-2.5 rounded-lg border border-gray-300 text-gray-900">
                                    </div>
                                    <div>
                                        <label class="block font-bold text-gray-700 mb-1">Province</label>
                                        <input required name="province" value="<?php echo esc($rhuProfile['province']); ?>" class="w-full p-2.5 rounded-lg border border-gray-300 text-gray-900">
                                    </div>
                                </div>
                                <div>
                                    <label class="block font-bold text-gray-700 mb-1">Municipal Health Officer (MHO)</label>
                                    <input required name="mho_name" value="<?php echo esc($rhuProfile['mho_name']); ?>" class="w-full p-2.5 rounded-lg border border-gray-300 text-gray-900">
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block font-bold text-gray-700 mb-1">Contact Telephone</label>
                                        <input required name="contact" value="<?php echo esc($rhuProfile['contact']); ?>" class="w-full p-2.5 rounded-lg border border-gray-300 text-gray-900">
                                    </div>
                                    <div>
                                        <label class="block font-bold text-gray-700 mb-1">Official Email</label>
                                        <input required type="email" name="email" value="<?php echo esc($rhuProfile['email']); ?>" class="w-full p-2.5 rounded-lg border border-gray-300 text-gray-900">
                                    </div>
                                </div>
                                <button type="submit" class="w-full py-2.5 bg-purple-700 text-white rounded-lg font-bold hover:bg-purple-800 transition-all shadow-md">
                                    Save Facility Settings
                                </button>
                            </form>
                        </div>

                        <!-- 2. SMTP MAIL GATEWAY & TEST SENDER -->
                        <div class="space-y-6">
                            <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-200 space-y-4">
                                <div class="flex items-center gap-2 border-b border-gray-100 pb-3">
                                    <span class="p-2 bg-blue-100 rounded-lg text-blue-700"><?php echo iconSvg('mail', 'w-5 h-5'); ?></span>
                                    <h3 class="font-bold text-gray-900 text-base">Email SMTP Gateway Configuration</h3>
                                </div>
                                <div class="space-y-3 text-xs">
                                    <div class="flex justify-between py-2 border-b border-gray-100">
                                        <span class="text-gray-500 font-medium">SMTP Server Host</span>
                                        <span class="font-mono font-bold text-gray-900"><?php echo esc(portalSetting($savedPortalSettings, 'smtp_host', 'Not configured') . ':' . portalSetting($savedPortalSettings, 'smtp_port', '587') . ' (' . strtoupper(portalSetting($savedPortalSettings, 'smtp_encryption', 'tls')) . ')'); ?></span>
                                    </div>
                                    <div class="flex justify-between py-2 border-b border-gray-100">
                                        <span class="text-gray-500 font-medium">Active Email Account</span>
                                        <span class="font-mono font-bold text-purple-800"><?php echo esc(portalSetting($savedPortalSettings, 'smtp_user', 'Not configured')); ?></span>
                                    </div>
                                    <div class="flex justify-between py-2 border-b border-gray-100">
                                        <span class="text-gray-500 font-medium">Authentication Status</span>
                                        <span class="px-2 py-0.5 rounded-full <?php echo portalSetting($savedPortalSettings, 'smtp_user') ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800'; ?> font-bold"><?php echo portalSetting($savedPortalSettings, 'smtp_user') ? 'Configured' : 'Environment fallback'; ?></span>
                                    </div>
                                </div>

                                <form method="post" class="grid grid-cols-2 gap-2 pt-2 text-xs">
                                    <input type="hidden" name="action" value="update_smtp_settings">
                                    <input name="smtp_host" value="<?php echo esc(portalSetting($savedPortalSettings, 'smtp_host')); ?>" placeholder="SMTP host" class="rounded border p-2">
                                    <input type="number" name="smtp_port" value="<?php echo esc(portalSetting($savedPortalSettings, 'smtp_port', '587')); ?>" placeholder="Port" class="rounded border p-2">
                                    <input type="email" name="smtp_user" value="<?php echo esc(portalSetting($savedPortalSettings, 'smtp_user')); ?>" placeholder="SMTP account" class="rounded border p-2">
                                    <select name="smtp_encryption" class="rounded border p-2">
                                        <option value="tls">TLS</option>
                                        <option value="ssl">SSL</option>
                                    </select>
                                    <label class="col-span-2 flex items-center gap-2"><input type="checkbox" name="two_factor_enabled" <?php echo portalSetting($savedPortalSettings, 'two_factor_enabled', '1') === '1' ? 'checked' : ''; ?>> Require admin 2FA</label>
                                    <button class="col-span-2 rounded bg-slate-700 py-2 font-bold text-white">Save Mail &amp; 2FA Settings</button>
                                </form>

                                <form method="post" class="pt-2 space-y-3 text-xs">
                                    <input type="hidden" name="action" value="send_test_email">
                                    <label class="block font-bold text-gray-700">Dispatch Test Notification Email
                                        <div class="mt-1 flex gap-2">
                                            <input required type="email" name="test_email" value="<?php echo esc($rhuProfile['email']); ?>" class="flex-1 p-2 rounded border border-gray-300">
                                            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded font-bold hover:bg-blue-700 whitespace-nowrap">Send Test Email</button>
                                        </div>
                                    </label>
                                </form>
                            </div>

                            <!-- 3. DATABASE MAINTENANCE & OPERATIONS -->
                            <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-200 space-y-4">
                                <div class="flex items-center gap-2 border-b border-gray-100 pb-3">
                                    <span class="p-2 bg-emerald-100 rounded-lg text-emerald-700"><?php echo iconSvg('database', 'w-5 h-5'); ?></span>
                                    <h3 class="font-bold text-gray-900 text-base">Database Health & Maintenance</h3>
                                </div>
                                <div class="grid grid-cols-2 gap-3 text-xs">
                                    <div class="p-3 bg-gray-50 rounded-xl border border-gray-100">
                                        <p class="text-gray-400">Database Name</p>
                                        <p class="font-mono font-bold text-gray-900 mt-1"><?php echo esc($databaseHealth['name']); ?></p>
                                    </div>
                                    <div class="p-3 bg-gray-50 rounded-xl border border-gray-100">
                                        <p class="text-gray-400">Connection Driver</p>
                                        <p class="font-mono font-bold text-green-700 mt-1">PDO MySQL</p>
                                    </div>
                                    <div class="p-3 bg-gray-50 rounded-xl border border-gray-100">
                                        <p class="text-gray-400">PHP Version</p>
                                        <p class="font-mono font-bold text-gray-900 mt-1"><?php echo phpversion(); ?></p>
                                    </div>
                                    <div class="p-3 bg-gray-50 rounded-xl border border-gray-100">
                                        <p class="text-gray-400">Total System Users</p>
                                        <p class="font-mono font-bold text-purple-900 mt-1"><?php echo $totalUsersCount; ?> Accounts</p>
                                    </div>
                                    <div class="p-3 bg-gray-50 rounded-xl border border-gray-100">
                                        <p class="text-gray-400">Database Server</p>
                                        <p class="font-mono font-bold text-gray-900 mt-1"><?php echo esc($databaseHealth['version']); ?></p>
                                    </div>
                                    <div class="p-3 bg-gray-50 rounded-xl border border-gray-100">
                                        <p class="text-gray-400">Schema Tables</p>
                                        <p class="font-mono font-bold text-gray-900 mt-1"><?php echo (int)$databaseHealth['tables']; ?> tables</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- ANNOUNCEMENTS & LANDING PAGE CONTENT TAB -->
            <?php if ($tab === 'announcements'): ?>
                <?php
                $dbAnnouncementsList = getPortalAnnouncements($pdo);
                $dbPortalEventsList = getPortalEvents($pdo);
                ?>
                <div class="space-y-6">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 pb-4">
                        <div>
                            <h2 class="text-xl font-bold text-gray-900 flex items-center gap-2"><?php echo iconSvg('bell', 'w-6 h-6 text-purple-600'); ?> Landing Page Announcements & Health Events</h2>
                            <p class="text-xs text-gray-500 mt-1">Publish health alerts, emergency notices, and upcoming health drives directly to the public Landing Page.</p>
                        </div>
                        <span class="text-xs font-bold bg-purple-100 text-purple-800 px-3 py-1 rounded-full">Live Landing Page Sync</span>
                    </div>

                    <div class="grid md:grid-cols-2 gap-6">
                        <!-- 1. ANNOUNCEMENTS FORM & LIST -->
                        <div class="space-y-6">
                            <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-200 space-y-4">
                                <div class="flex items-center gap-2 border-b border-gray-100 pb-3">
                                    <span class="p-2 bg-red-100 rounded-lg text-red-700"><?php echo iconSvg('bell', 'w-5 h-5'); ?></span>
                                    <h3 class="font-bold text-gray-900 text-base">Publish Health Announcement / Alert</h3>
                                </div>
                                <form method="post" enctype="multipart/form-data" class="space-y-3 text-xs">
                                    <input type="hidden" name="action" value="create_announcement">
                                    <div>
                                        <label class="block font-bold text-gray-700 mb-1">Announcement / Awareness Title *</label>
                                        <input required type="text" name="title" placeholder="e.g. Dengue Awareness Campaign & Clean-Up Drive" class="w-full p-2.5 rounded-lg border border-gray-300">
                                    </div>
                                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                        <div>
                                            <label class="block font-bold text-gray-700 mb-1">Category</label>
                                            <select name="category" class="w-full p-2.5 rounded-lg border border-gray-300 bg-white">
                                                <option value="Health Awareness" selected>Health Awareness</option>
                                                <option value="Emergency Alert">Emergency Alert</option>
                                                <option value="Health Notice">Health Notice</option>
                                                <option value="Vaccine Schedule">Vaccine Schedule</option>
                                                <option value="General Announcement">General Announcement</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block font-bold text-gray-700 mb-1">Badge Label</label>
                                            <input type="text" name="badge_text" value="Health Awareness" class="w-full p-2.5 rounded-lg border border-gray-300">
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block font-bold text-gray-700 mb-1">Awareness Image</label>
                                        <input type="file" name="announcement_image" accept="image/*" class="w-full p-2 rounded-lg border border-gray-300 bg-white text-xs">
                                    </div>
                                    <div>
                                        <label class="block font-bold text-gray-700 mb-1">Announcement Details / Message *</label>
                                        <textarea required name="content" rows="3" placeholder="Write full awareness description..." class="w-full p-2.5 rounded-lg border border-gray-300"></textarea>
                                    </div>
                                    <div class="flex items-center gap-2 p-2.5 bg-purple-50 rounded-lg border border-purple-100">
                                        <input type="checkbox" id="is_popup" name="is_popup" value="1" checked class="rounded text-purple-600 focus:ring-purple-500">
                                        <label for="is_popup" class="font-bold text-purple-900 text-xs cursor-pointer select-none">
                                            Show as Landing Page Opening Screen Modal (1/8 Screen Awareness Banner)
                                        </label>
                                    </div>
                                    <button type="submit" class="w-full py-2.5 bg-purple-700 text-white rounded-lg font-bold hover:bg-purple-800 transition-all shadow-md">
                                        Publish Awareness Post to Landing Page Live
                                    </button>
                                </form>
                            </div>

                            <!-- ANNOUNCEMENTS LIST TABLE -->
                            <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-200 space-y-3">
                                <h4 class="font-bold text-gray-900 text-sm">Active Landing Page Announcements & Awareness Posts</h4>
                                <?php if (empty($dbAnnouncementsList)): ?>
                                    <p class="text-xs text-gray-400 italic py-2">No announcements stored yet. Standard default banner will display.</p>
                                <?php else: ?>
                                    <div class="space-y-2">
                                        <?php foreach ($dbAnnouncementsList as $ann): ?>
                                            <div class="p-3 rounded-xl border border-gray-100 bg-gray-50 flex items-start justify-between gap-3 text-xs">
                                                <div class="flex items-start gap-3">
                                                    <?php if (!empty($ann['image_url'])): ?>
                                                        <img src="<?php echo esc(resolveImageUrl($ann['image_url'])); ?>" alt="Awareness" class="w-12 h-12 rounded-lg object-cover border border-gray-200 shrink-0" referrerpolicy="no-referrer">
                                                    <?php endif; ?>
                                                    <div>
                                                        <div class="flex items-center gap-1.5">
                                                            <span class="px-2 py-0.5 rounded-full bg-purple-100 text-purple-800 font-bold text-[10px]"><?php echo esc($ann['badge_text'] ?: $ann['category']); ?></span>
                                                            <?php if (!empty($ann['is_popup'])): ?>
                                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-amber-100 text-amber-800 font-bold text-[10px]"><?php echo iconSvg('eye', 'w-3 h-3 shrink-0'); ?> 1/8 Screen Popup</span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <h5 class="font-bold text-gray-900 mt-1"><?php echo esc($ann['title']); ?></h5>
                                                        <p class="text-gray-600 mt-0.5 line-clamp-2"><?php echo esc($ann['content']); ?></p>
                                                    </div>
                                                </div>
                                                <form method="post" onsubmit="return confirm('Remove this announcement from Landing Page?')">
                                                    <input type="hidden" name="action" value="delete_announcement">
                                                    <input type="hidden" name="announcement_id" value="<?php echo $ann['id']; ?>">
                                                    <button type="submit" class="p-1.5 text-red-600 hover:bg-red-50 rounded" title="Delete Announcement">
                                                        <?php echo iconSvg('trash', 'w-4 h-4'); ?>
                                                    </button>
                                                </form>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- 2. HEALTH EVENTS FORM & LIST -->
                        <div class="space-y-6">
                            <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-200 space-y-4">
                                <div class="flex items-center gap-2 border-b border-gray-100 pb-3">
                                    <span class="p-2 bg-emerald-100 rounded-lg text-emerald-700"><?php echo iconSvg('activity', 'w-5 h-5'); ?></span>
                                    <h3 class="font-bold text-gray-900 text-base">Add Landing Page Health Event & Picture</h3>
                                </div>
                                <form method="post" enctype="multipart/form-data" class="space-y-3 text-xs">
                                    <input type="hidden" name="action" value="create_event">
                                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                        <div>
                                            <label class="block font-bold text-gray-700 mb-1">Event Date *</label>
                                            <input required type="date" name="event_date" min="<?php echo date('Y-m-d'); ?>" value="<?php echo date('Y-m-d'); ?>" class="w-full p-2.5 rounded-lg border border-gray-300 bg-white" aria-describedby="eventDateHelp">
                                            <p id="eventDateHelp" class="mt-1 text-[10px] font-normal text-gray-500">Choose the scheduled event date from the calendar.</p>
                                        </div>
                                        <div>
                                            <label class="block font-bold text-gray-700 mb-1">Badge Color</label>
                                            <select name="badge_color" class="w-full p-2.5 rounded-lg border border-gray-300 bg-white">
                                                <option value="bg-red-500">Red (Emergency / Blood Drive)</option>
                                                <option value="bg-pink-500">Pink (Women & Maternal)</option>
                                                <option value="bg-emerald-500" selected>Green (Wellness & Senior)</option>
                                                <option value="bg-blue-500">Blue (Child & Vaccine)</option>
                                                <option value="bg-teal-500">Teal (Nutrition)</option>
                                                <option value="bg-purple-500">Purple (Family Planning)</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block font-bold text-gray-700 mb-1">Event Title *</label>
                                        <input required type="text" name="title" placeholder="e.g. Free Senior Citizens ECG & Glucose Check" class="w-full p-2.5 rounded-lg border border-gray-300">
                                    </div>
                                    <div>
                                        <label class="block font-bold text-gray-700 mb-1">Barangay / Event Location *</label>
                                        <select required name="barangay_id" class="w-full p-2.5 rounded-lg border border-gray-300 bg-white">
                                            <option value="">-- Select Barangay from Database --</option>
                                            <?php foreach ($dbBarangayOptions as $barangayOption): ?>
                                                <option value="<?php echo (int)$barangayOption['id']; ?>"><?php echo esc($barangayOption['name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if (empty($dbBarangayOptions)): ?>
                                            <p class="mt-1 text-[10px] font-semibold text-red-600">No barangay records are available. Import the barangays table first.</p>
                                        <?php else: ?>
                                            <p class="mt-1 text-[10px] font-normal text-gray-500"><?php echo count($dbBarangayOptions); ?> barangay location(s) loaded from the database.</p>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <label class="block font-bold text-gray-700 mb-1">Event Description</label>
                                        <textarea name="description" rows="2" placeholder="Brief event details or free services offered..." class="w-full p-2.5 rounded-lg border border-gray-300"></textarea>
                                    </div>
                                    <div>
                                        <label class="block font-bold text-gray-700 mb-1">Event Cover Picture</label>
                                        <input type="file" name="event_image" accept="image/*" class="w-full p-2 rounded-lg border border-gray-300 bg-white text-xs">
                                    </div>
                                    <button type="submit" class="w-full py-2.5 bg-emerald-700 text-white rounded-lg font-bold hover:bg-emerald-800 transition-all shadow-md">
                                        Add Event & Picture to Landing Page
                                    </button>
                                </form>
                            </div>

                            <!-- EVENTS LIST TABLE -->
                            <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-200 space-y-3">
                                <h4 class="font-bold text-gray-900 text-sm">Active Landing Page Health Events</h4>
                                <?php if (empty($dbPortalEventsList)): ?>
                                    <p class="text-xs text-gray-400 italic py-2">No custom events stored yet. Default schedule will display.</p>
                                <?php else: ?>
                                    <div class="space-y-2">
                                        <?php foreach ($dbPortalEventsList as $evt): ?>
                                            <div class="p-3 rounded-xl border border-gray-100 bg-gray-50 flex items-start justify-between gap-3 text-xs">
                                                <div class="flex items-start gap-3">
                                                    <?php if (!empty($evt['image_url'])): ?>
                                                        <div class="h-14 w-14 rounded-lg overflow-hidden shrink-0 bg-slate-200 border border-gray-200">
                                                            <img src="<?php echo esc(portalImgUrl($evt['image_url'])); ?>" alt="<?php echo esc($evt['title']); ?>" class="w-full h-full object-cover" onerror="this.onerror=null; this.src='https://images.unsplash.com/photo-1576091160399-112ba8d25d1d?auto=format&fit=crop&w=100&q=80';">
                                                        </div>
                                                    <?php endif; ?>
                                                    <div>
                                                        <span class="px-2 py-0.5 rounded font-mono bg-gray-200 text-gray-800 font-bold text-[10px]"><?php echo esc($evt['event_date']); ?></span>
                                                        <h5 class="font-bold text-gray-900 mt-1"><?php echo esc($evt['title']); ?></h5>
                                                        <p class="text-xs font-semibold text-emerald-700"><?php echo esc($evt['venue']); ?></p>
                                                        <p class="text-gray-500 mt-0.5"><?php echo esc($evt['description']); ?></p>
                                                    </div>
                                                </div>
                                                <div class="flex items-center gap-1 shrink-0">
                                                    <?php if (!empty($evt['image_url'])): ?>
                                                        <form method="post" onsubmit="return confirm('Delete picture attached to this event?')">
                                                            <input type="hidden" name="action" value="delete_event_picture">
                                                            <input type="hidden" name="event_id" value="<?php echo $evt['id']; ?>">
                                                            <button type="submit" class="px-2 py-1 bg-amber-50 text-amber-700 hover:bg-amber-100 rounded text-[10px] font-bold" title="Delete Cover Picture Only">
                                                                Remove Picture
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                    <form method="post" onsubmit="return confirm('Remove this health event and its picture?')">
                                                        <input type="hidden" name="action" value="delete_event">
                                                        <input type="hidden" name="event_id" value="<?php echo $evt['id']; ?>">
                                                        <button type="submit" class="p-1.5 text-red-600 hover:bg-red-50 rounded" title="Delete Event">
                                                            <?php echo iconSvg('trash', 'w-4 h-4'); ?>
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- 3. MUNICIPAL OFFICIALS MANAGEMENT PANEL -->
                    <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-200 space-y-6">
                        <div class="flex items-center justify-between border-b border-gray-100 pb-3">
                            <div class="flex items-center gap-2">
                                <span class="p-2 bg-blue-100 rounded-lg text-blue-700"><?php echo iconSvg('building', 'w-5 h-5'); ?></span>
                                <div>
                                    <h3 class="font-bold text-gray-900 text-base">Municipal Government Officials</h3>
                                    <p class="text-xs text-gray-500">Manage LGU leaders displayed in the Municipal Leadership section of the Landing Page.</p>
                                </div>
                            </div>
                            <span class="text-xs font-bold bg-blue-50 text-blue-700 px-3 py-1 rounded-full">Stored in Portal Settings</span>
                        </div>

                        <?php
                        $pSettings = portalSettings($pdo);
                        $adminOfficialsList = [];
                        if (!empty($pSettings['rhu_municipal_officials'])) {
                            $adminOfficialsList = json_decode($pSettings['rhu_municipal_officials'], true) ?: [];
                        }
                        ?>

                        <div class="grid md:grid-cols-12 gap-6">
                            <!-- ADD OFFICIAL FORM -->
                            <form method="post" class="md:col-span-5 space-y-3 text-xs bg-gray-50 p-4 rounded-xl border border-gray-200">
                                <input type="hidden" name="action" value="add_official">
                                <h4 class="font-bold text-gray-900 text-sm">Add New Municipal Official</h4>
                                <div>
                                    <label class="block font-bold text-gray-700 mb-1">Full Name & Honorific *</label>
                                    <input required type="text" name="official_name" placeholder="e.g. Hon. Antonio Jose A. Barcelon" class="w-full p-2.5 rounded-lg border border-gray-300 bg-white">
                                </div>
                                <div>
                                    <label class="block font-bold text-gray-700 mb-1">Official Position *</label>
                                    <input required type="text" name="official_position" placeholder="e.g. Municipal Mayor" class="w-full p-2.5 rounded-lg border border-gray-300 bg-white">
                                </div>
                                <div>
                                    <label class="block font-bold text-gray-700 mb-1">Office / Committee Designation</label>
                                    <input type="text" name="official_office" placeholder="e.g. Office of the Municipal Mayor" class="w-full p-2.5 rounded-lg border border-gray-300 bg-white">
                                </div>
                                <button type="submit" class="w-full py-2.5 bg-blue-700 text-white rounded-lg font-bold hover:bg-blue-800 transition-all shadow-md">
                                    Add Municipal Official
                                </button>
                            </form>

                            <!-- OFFICIALS LIST DISPLAY -->
                            <div class="md:col-span-7 space-y-3">
                                <h4 class="font-bold text-gray-900 text-sm">Current Officials Displayed on Landing Page</h4>
                                <?php if (empty($adminOfficialsList)): ?>
                                    <p class="text-xs text-gray-400 italic py-2">No officials specified yet.</p>
                                <?php else: ?>
                                    <div class="grid sm:grid-cols-2 gap-3 max-h-80 overflow-y-auto pr-1">
                                        <?php foreach ($adminOfficialsList as $idx => $off): ?>
                                            <div class="p-3 rounded-xl border border-gray-200 bg-white shadow-xs flex items-center justify-between gap-2 text-xs">
                                                <div>
                                                    <h5 class="font-bold text-gray-900"><?php echo esc($off['name']); ?></h5>
                                                    <p class="text-blue-700 font-semibold text-[11px]"><?php echo esc($off['position']); ?></p>
                                                    <p class="text-gray-400 text-[10px]"><?php echo esc($off['office']); ?></p>
                                                </div>
                                                <form method="post" onsubmit="return confirm('Remove <?php echo esc($off['name']); ?> from Landing Page?')">
                                                    <input type="hidden" name="action" value="delete_official">
                                                    <input type="hidden" name="official_index" value="<?php echo $idx; ?>">
                                                    <button type="submit" class="p-1.5 text-red-600 hover:bg-red-50 rounded shrink-0" title="Remove Official">
                                                        <?php echo iconSvg('trash', 'w-4 h-4'); ?>
                                                    </button>
                                                </form>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <!-- 4. EVENT PHOTO GALLERY MANAGEMENT PANEL (Stored in portal_settings, no new DB table) -->
                                <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-200 space-y-6">
                                    <div class="flex items-center justify-between border-b border-gray-100 pb-3">
                                        <div class="flex items-center gap-2">
                                            <span class="p-2 bg-purple-100 rounded-lg text-purple-700"><?php echo iconSvg('eye', 'w-5 h-5'); ?></span>
                                            <div>
                                                <h3 class="font-bold text-gray-900 text-base">RHU Event Photo Gallery</h3>
                                                <p class="text-xs text-gray-500">Upload or add photo links to the Event & Community Photo Gallery section on the Landing Page.</p>
                                            </div>
                                        </div>
                                        <span class="text-xs font-bold bg-purple-100 text-purple-800 px-3 py-1 rounded-full">Saved in Portal Settings</span>
                                    </div>

                                    <?php $eventGalleryList = getPortalEventGallery($pdo); ?>

                                    <div class="grid md:grid-cols-12 gap-6">
                                        <!-- ADD PHOTO FORM -->
                                        <form method="post" enctype="multipart/form-data" class="md:col-span-5 space-y-3 text-xs bg-gray-50 p-4 rounded-xl border border-gray-200">
                                            <input type="hidden" name="action" value="add_gallery_photo">
                                            <h4 class="font-bold text-gray-900 text-sm">Add Event Gallery Photo</h4>
                                            <div>
                                                <label class="block font-bold text-gray-700 mb-1">Photo Title *</label>
                                                <input required type="text" name="photo_title" placeholder="e.g. Free Dental Mission & Medical Checkup" class="w-full p-2.5 rounded-lg border border-gray-300 bg-white">
                                            </div>
                                            <div class="grid grid-cols-2 gap-2">
                                                <div>
                                                    <label class="block font-bold text-gray-700 mb-1">Category</label>
                                                    <input type="text" name="photo_category" value="Community Health Drive" class="w-full p-2.5 rounded-lg border border-gray-300 bg-white">
                                                </div>
                                                <div>
                                                    <label class="block font-bold text-gray-700 mb-1">Date</label>
                                                    <input type="text" name="photo_date" value="<?php echo date('M Y'); ?>" class="w-full p-2.5 rounded-lg border border-gray-300 bg-white">
                                                </div>
                                            </div>
                                            <div>
                                                <label class="block font-bold text-gray-700 mb-1">Image File Upload or Image URL *</label>
                                                <input type="file" name="gallery_file" accept="image/*" class="w-full p-1 rounded border border-gray-300 text-[11px] mb-2 bg-white">
                                                <input type="text" name="image_url" placeholder="Or paste Image URL (e.g. https://...)" class="w-full p-2.5 rounded-lg border border-gray-300 bg-white">
                                            </div>
                                            <button type="submit" class="w-full py-2.5 bg-purple-700 text-white rounded-lg font-bold hover:bg-purple-800 transition-all shadow-md">
                                                Add Photo to Landing Page Gallery
                                            </button>
                                        </form>

                                        <!-- GALLERY DISPLAY GRID -->
                                        <div class="md:col-span-7 space-y-3">
                                            <h4 class="font-bold text-gray-900 text-sm">Current Landing Page Gallery Photos</h4>
                                            <?php if (empty($eventGalleryList)): ?>
                                                <p class="text-xs text-gray-400 italic py-2">No photos in gallery yet.</p>
                                            <?php else: ?>
                                                <div class="grid sm:grid-cols-2 gap-3 max-h-96 overflow-y-auto pr-1">
                                                    <?php foreach ($eventGalleryList as $gIdx => $gItem): ?>
                                                        <div class="p-3 rounded-xl border border-gray-200 bg-white shadow-xs space-y-2 text-xs relative group">
                                                            <div class="h-28 w-full rounded-lg overflow-hidden bg-slate-100 border border-slate-100">
                                                                <img src="<?php echo esc(portalImgUrl($gItem['image_url'])); ?>" alt="<?php echo esc($gItem['title']); ?>" class="w-full h-full object-cover group-hover:scale-105 transition-transform" onerror="this.onerror=null; this.src='https://images.unsplash.com/photo-1576091160399-112ba8d25d1d?auto=format&fit=crop&w=400&q=80';">
                                                            </div>
                                                            <div class="flex items-start justify-between gap-2">
                                                                <div>
                                                                    <span class="px-2 py-0.5 rounded-full bg-purple-100 text-purple-700 font-bold text-[10px]"><?php echo esc($gItem['category'] ?? 'Event Photo'); ?></span>
                                                                    <h5 class="font-bold text-gray-900 mt-1 truncate max-w-[160px]"><?php echo esc($gItem['title']); ?></h5>
                                                                    <p class="text-[10px] text-gray-400"><?php echo esc($gItem['date'] ?? ''); ?></p>
                                                                </div>
                                                                <form method="post" onsubmit="return confirm('Remove photo from gallery?')">
                                                                    <input type="hidden" name="action" value="delete_gallery_photo">
                                                                    <input type="hidden" name="photo_index" value="<?php echo $gIdx; ?>">
                                                                    <button type="submit" class="p-1.5 text-red-600 hover:bg-red-50 rounded shrink-0" title="Delete Photo">
                                                                        <?php echo iconSvg('trash', 'w-4 h-4'); ?>
                                                                    </button>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- RE-DESIGNED SECURITY TAB -->
                        <?php if ($tab === 'security'): ?>
                            <?php renderAdminFloatingToolsModal($pdo, 'security-tools', 'Security Permission Tools', 'Manage roles, permissions, and access rules without leaving Security.', 'security'); ?>
                            <div class="space-y-6">
                                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 pb-4">
                                    <div>
                                        <h2 class="text-xl font-bold text-gray-900 flex items-center gap-2"><?php echo iconSvg('lock', 'w-6 h-6 text-red-600'); ?> Security Command Center</h2>
                                        <p class="text-xs text-gray-500 mt-1">Manage Admin Credentials, 2-Factor Authentication, Role-Based Access Control, and Audit Security Logs</p>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <button type="button" data-floating-modal-open="security-tools" class="rounded-lg bg-emerald-700 px-4 py-2 text-xs font-bold text-white shadow-sm transition hover:bg-emerald-800">Open Security Tools</button>
                                        <span class="text-xs font-bold bg-red-100 text-red-800 px-3 py-1 rounded-full">RA 10173 Compliant</span>
                                    </div>
                                </div>

                                <div class="grid md:grid-cols-2 gap-6">
                                    <!-- 1. CHANGE ADMIN PASSWORD FORM -->
                                    <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-200 space-y-4">
                                        <div class="flex items-center gap-2 border-b border-gray-100 pb-3">
                                            <span class="p-2 bg-purple-100 rounded-lg text-purple-700"><?php echo iconSvg('key', 'w-5 h-5'); ?></span>
                                            <h3 class="font-bold text-gray-900 text-base">Change Administrator Password</h3>
                                        </div>
                                        <form method="post" class="space-y-4 text-xs">
                                            <input type="hidden" name="action" value="change_admin_password">
                                            <div>
                                                <label class="block font-bold text-gray-700 mb-1">Current Password *</label>
                                                <input required type="password" name="current_password" class="w-full p-2.5 rounded-lg border border-gray-300 text-gray-900 focus:border-purple-600 focus:outline-none" placeholder="Enter current admin password">
                                            </div>
                                            <div>
                                                <label class="block font-bold text-gray-700 mb-1">New Password *</label>
                                                <input required type="password" name="new_password" class="w-full p-2.5 rounded-lg border border-gray-300 text-gray-900 focus:border-purple-600 focus:outline-none" placeholder="Min 8 characters">
                                            </div>
                                            <div>
                                                <label class="block font-bold text-gray-700 mb-1">Confirm New Password *</label>
                                                <input required type="password" name="confirm_password" class="w-full p-2.5 rounded-lg border border-gray-300 text-gray-900 focus:border-purple-600 focus:outline-none" placeholder="Re-type new password">
                                            </div>
                                            <button type="submit" class="w-full py-2.5 bg-red-600 text-white rounded-lg font-bold hover:bg-red-700 transition-all shadow-md">
                                                Update Admin Password in Database
                                            </button>
                                        </form>
                                    </div>

                                    <!-- 2. 2FA ENFORCEMENT & SECURITY STATUS -->
                                    <div class="space-y-6">
                                        <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-200 space-y-4">
                                            <div class="flex items-center gap-2 border-b border-gray-100 pb-3">
                                                <span class="p-2 bg-emerald-100 rounded-lg text-emerald-700"><?php echo iconSvg('shield', 'w-5 h-5'); ?></span>
                                                <h3 class="font-bold text-gray-900 text-base">2-Factor Authentication (2FA) Status</h3>
                                            </div>
                                            <div class="space-y-3 text-xs">
                                                <div class="p-4 bg-purple-50 rounded-xl border border-purple-200">
                                                    <div class="flex items-center justify-between">
                                                        <span class="font-bold text-purple-900">2FA Enforcement</span>
                                                        <span class="px-2.5 py-1 bg-purple-700 text-white font-bold rounded-full text-[10px]"><?php echo portalSetting($savedPortalSettings, 'two_factor_enabled', '1') === '1' ? 'ACTIVE' : 'DISABLED'; ?></span>
                                                    </div>
                                                    <p class="text-purple-800 mt-2">All login attempts require a unique 6-digit OTP code sent directly to your email address.</p>
                                                </div>
                                                <div class="flex justify-between py-2 border-b border-gray-100">
                                                    <span class="text-gray-500 font-medium">Registered 2FA Email</span>
                                                    <span class="font-mono font-bold text-gray-900"><?php echo esc($_SESSION['user']['email'] ?? 'Not available'); ?></span>
                                                </div>
                                                <div class="flex justify-between py-2 border-b border-gray-100">
                                                    <span class="text-gray-500 font-medium">Code Validity Window</span>
                                                    <span class="font-mono font-bold text-gray-900">10 Minutes</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- 3. ROLE-BASED ACCESS CONTROL (RBAC) MATRIX -->
                                <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-200 space-y-4">
                                    <div class="flex items-center justify-between border-b border-gray-100 pb-3">
                                        <div class="flex items-center gap-2">
                                            <span class="p-2 bg-purple-100 rounded-lg text-purple-700"><?php echo iconSvg('users', 'w-5 h-5'); ?></span>
                                            <h3 class="font-bold text-gray-900 text-base">Role-Based Module Permissions Matrix</h3>
                                        </div>
                                        <span class="text-xs bg-gray-100 text-gray-700 px-3 py-1 rounded-full font-semibold">6 System Roles</span>
                                    </div>
                                    <div class="overflow-x-auto">
                                        <table class="w-full text-xs">
                                            <thead class="bg-gray-50 text-gray-500 uppercase">
                                                <tr>
                                                    <th class="px-3 py-2 text-left">Role Name</th>
                                                    <th class="px-3 py-2 text-left">Access Level</th>
                                                    <th class="px-3 py-2 text-left">Accessible Modules</th>
                                                    <th class="px-3 py-2 text-left">Active Users in DB</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-100">
                                                <tr class="hover:bg-gray-50">
                                                    <td class="px-3 py-3 font-bold text-purple-900">RHU_ADMIN</td>
                                                    <td class="px-3 py-3"><span class="px-2 py-0.5 bg-purple-100 text-purple-800 font-bold rounded-full">Full System Control</span></td>
                                                    <td class="px-3 py-3 text-gray-700">All Modules, User Management, Staff Accounts, Audit Logs, Settings</td>
                                                    <td class="px-3 py-3 font-mono font-bold text-gray-900">1 Account</td>
                                                </tr>
                                                <tr class="hover:bg-gray-50">
                                                    <td class="px-3 py-3 font-bold text-blue-900">PHYSICIAN</td>
                                                    <td class="px-3 py-3"><span class="px-2 py-0.5 bg-blue-100 text-blue-800 font-bold rounded-full">Clinical Lead</span></td>
                                                    <td class="px-3 py-3 text-gray-700">OPD Consultations, ICD-10 Prescriptions, Patient Medical Records</td>
                                                    <td class="px-3 py-3 font-mono font-bold text-gray-900">Plantilla Doctors</td>
                                                </tr>
                                                <tr class="hover:bg-gray-50">
                                                    <td class="px-3 py-3 font-bold text-emerald-900">NURSE / MIDWIFE</td>
                                                    <td class="px-3 py-3"><span class="px-2 py-0.5 bg-emerald-100 text-emerald-800 font-bold rounded-full">Healthcare Operations</span></td>
                                                    <td class="px-3 py-3 text-gray-700">Maternal Health, Immunization (EPI), Triage, Vital Signs</td>
                                                    <td class="px-3 py-3 font-mono font-bold text-gray-900">Clinical Staff</td>
                                                </tr>
                                                <tr class="hover:bg-gray-50">
                                                    <td class="px-3 py-3 font-bold text-teal-900">BHW</td>
                                                    <td class="px-3 py-3"><span class="px-2 py-0.5 bg-teal-100 text-teal-800 font-bold rounded-full">Community Health</span></td>
                                                    <td class="px-3 py-3 text-gray-700">Barangay Resident Registry, Health Event Verification</td>
                                                    <td class="px-3 py-3 font-mono font-bold text-gray-900"><?php echo $totalBhwCount; ?> BHWs</td>
                                                </tr>
                                                <tr class="hover:bg-gray-50">
                                                    <td class="px-3 py-3 font-bold text-gray-900">RESIDENT</td>
                                                    <td class="px-3 py-3"><span class="px-2 py-0.5 bg-gray-100 text-gray-800 font-bold rounded-full">Self-Service Portal</span></td>
                                                    <td class="px-3 py-3 text-gray-700">Request Medical Certificates, Send Inquiries, Event Registration</td>
                                                    <td class="px-3 py-3 font-mono font-bold text-gray-900"><?php echo $totalResidentsCount; ?> Residents</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
        </main>
    </div>
    <script>
        document.querySelectorAll('form[method="post"], form[method="POST"]').forEach((form) => {
            if (form.querySelector('input[name="csrf_token"]')) return;
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'csrf_token';
            input.value = <?php echo json_encode(portalCsrfToken(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            form.prepend(input);
        });

        let lockedDashboardScrollY = 0;
        const hasOpenDashboardOverlay = () => Boolean(document.querySelector('[data-report-modal-backdrop]:not(.hidden), [data-floating-modal]:not(.hidden), [data-certificate-preview-modal]:not(.hidden)'));
        const lockDashboardBackground = () => {
            if (document.body.dataset.dashboardScrollLocked === '1') return;
            lockedDashboardScrollY = window.scrollY || document.documentElement.scrollTop || 0;
            document.body.dataset.dashboardScrollLocked = '1';
            document.body.style.position = 'fixed';
            document.body.style.top = `-${lockedDashboardScrollY}px`;
            document.body.style.left = '0';
            document.body.style.right = '0';
            document.body.style.width = '100%';
            document.body.classList.add('overflow-hidden');
            document.documentElement.classList.add('overflow-hidden');
        };
        const unlockDashboardBackground = () => {
            if (hasOpenDashboardOverlay()) return;
            if (document.body.dataset.dashboardScrollLocked !== '1') return;
            document.body.dataset.dashboardScrollLocked = '0';
            document.body.style.position = '';
            document.body.style.top = '';
            document.body.style.left = '';
            document.body.style.right = '';
            document.body.style.width = '';
            document.body.classList.remove('overflow-hidden');
            document.documentElement.classList.remove('overflow-hidden');
            window.scrollTo(0, lockedDashboardScrollY);
        };
        const stopDashboardOverlayScroll = (event) => {
            if (document.body.dataset.dashboardScrollLocked !== '1') return;
            if (!hasOpenDashboardOverlay()) return;
            event.preventDefault();
            event.stopPropagation();
        };
        window.addEventListener('wheel', stopDashboardOverlayScroll, {
            passive: false,
            capture: true
        });
        window.addEventListener('touchmove', stopDashboardOverlayScroll, {
            passive: false,
            capture: true
        });

        const openFloatingModal = (modal) => {
            if (!modal) return;
            lockDashboardBackground();
            modal.classList.remove('hidden');
            window.setTimeout(() => modal.querySelector('[data-floating-modal-close], [data-report-modal-close]')?.focus(), 60);
        };
        const closeFloatingModal = (modal) => {
            if (!modal) return;
            modal.classList.add('hidden');
            unlockDashboardBackground();
        };
        document.querySelector('[data-report-modal-open]')?.addEventListener('click', () => {
            openFloatingModal(document.querySelector('[data-report-modal-backdrop]'));
        });
        document.querySelectorAll('[data-floating-modal-open]').forEach((button) => {
            button.addEventListener('click', () => {
                openFloatingModal(document.querySelector(`[data-floating-modal="${button.dataset.floatingModalOpen}"]`));
            });
        });
        document.querySelectorAll('[data-report-modal-close], [data-floating-modal-close]').forEach((button) => {
            button.addEventListener('click', () => closeFloatingModal(button.closest('[data-report-modal-backdrop], [data-floating-modal]')));
        });
        document.querySelectorAll('[data-report-modal-backdrop], [data-floating-modal]').forEach((modal) => {
            modal.addEventListener('click', (event) => {
                if (event.target === modal) closeFloatingModal(modal);
            });
        });
        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') return;
            document.querySelectorAll('[data-report-modal-backdrop]:not(.hidden), [data-floating-modal]:not(.hidden)').forEach(closeFloatingModal);
        });

        const initializeNasugbuHealthMap = () => {
            const mapElement = document.getElementById('nasugbuHealthMap');
            const dataElement = document.getElementById('nasugbuHealthMapData');
            if (!mapElement || !dataElement || typeof L === 'undefined') return;
            if (mapElement.dataset.initialized === '1') return;
            mapElement.dataset.initialized = '1';

            let barangays = [];
            try {
                barangays = JSON.parse(dataElement.textContent || '[]');
            } catch (error) {
                barangays = [];
            }

            // Accurate coordinates map for Nasugbu, Batangas barangays
            const nasugbuBarangayCoords = {
                'Aga': [14.0980, 120.7420],
                'Balaytiguebalok-Balok': [14.1250, 120.6080],
                'Balaytigue': [14.1250, 120.6080],
                'Balok-Balok': [14.1200, 120.6100],
                'Banilad': [14.0320, 120.6980],
                'Barangay 1 (Pob.)': [14.0720, 120.6320],
                'Barangay 2 (Pob.)': [14.0725, 120.6305],
                'Barangay 3 (Pob.)': [14.0710, 120.6315],
                'Barangay 4 (Pob.)': [14.0700, 120.6330],
                'Barangay 5 (Pob.)': [14.0735, 120.6295],
                'Barangay 6 (Pob.)': [14.0740, 120.6325],
                'Barangay 7 (Pob.)': [14.0690, 120.6340],
                'Barangay 8 (Pob.)': [14.0750, 120.6310],
                'Barangay 9 (Pob.)': [14.0760, 120.6335],
                'Barangay 10 (Pob.)': [14.0680, 120.6300],
                'Barangay 11 (Pob.)': [14.0770, 120.6350],
                'Barangay 12 (Pob.)': [14.0670, 120.6360],
                'Bilaran': [14.0520, 120.6550],
                'Bucana': [14.0840, 120.6320],
                'Bulihan': [14.1120, 120.6380],
                'Bunducan': [14.0480, 120.6400],
                'Butucan': [14.0380, 120.6500],
                'Calayo': [14.1850, 120.6120],
                'Catandaan': [14.0280, 120.6650],
                'Cagunan': [14.0200, 120.6750],
                'Dayap': [14.0150, 120.6850],
                'Kaylaway': [14.0910, 120.7520],
                'Kayrillaw': [14.1020, 120.7600],
                'Latag': [14.0620, 120.6920],
                'Looc': [14.1550, 120.6180],
                'Lumbangan': [14.0450, 120.6850],
                'Malapad na Bato': [14.0880, 120.6700],
                'Mataas na Pulo': [14.0950, 120.6600],
                'Maugat': [14.1050, 120.6500],
                'Munting Indang': [14.0580, 120.7100],
                'Natipuan': [14.1180, 120.6150],
                'Pantalan': [14.0400, 120.6320],
                'Papaya': [14.1980, 120.5980],
                'Putat': [14.0650, 120.6650],
                'Reparo': [14.0350, 120.7150],
                'Talangan': [14.0250, 120.7250],
                'Tumalim': [14.0750, 120.6780],
                'Utod': [14.0320, 120.6480],
                'Wawa': [14.0780, 120.6230]
            };

            const defaultNasugbuCenter = [14.0722, 120.6310]; // Center of Nasugbu, Batangas
            const nasugbuFocusBounds = L.latLngBounds(
                [13.9950, 120.5450],
                [14.2150, 120.7850]
            );

            const map = L.map(mapElement, {
                center: defaultNasugbuCenter,
                zoom: 13,
                minZoom: 12,
                maxZoom: 18,
                maxBounds: nasugbuFocusBounds.pad(0.08),
                maxBoundsViscosity: 1,
                scrollWheelZoom: false,
                zoomControl: true
            });

            const baseLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap contributors',
                tileSize: 256,
                zoomOffset: 0,
                noWrap: false,
                crossOrigin: true
            }).addTo(map);
            baseLayer.on('load', () => {
                map.invalidateSize({
                    animate: false,
                    pan: false
                });
            });
            L.rectangle(nasugbuFocusBounds, {
                color: '#7c3aed',
                weight: 2,
                dashArray: '8 6',
                fillColor: '#7c3aed',
                fillOpacity: 0.04,
                interactive: false
            }).addTo(map);

            const markerGroup = L.featureGroup().addTo(map);

            const escapeMapText = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            })[char]);

            const barangayStatsByName = new Map(
                barangays.map((barangay) => [String(barangay.barangay || '').trim(), barangay])
            );
            const hotspotBarangayName = barangays.reduce((hotspotName, barangay) => {
                const currentCases = Number(barangay.disease_cases || 0);
                const hotspotCases = Number(barangayStatsByName.get(hotspotName)?.disease_cases || 0);
                return currentCases > hotspotCases ? String(barangay.barangay || '').trim() : hotspotName;
            }, '');

            Object.entries(nasugbuBarangayCoords).forEach(([name, coords]) => {
                const [lat, lng] = coords;
                const barangay = barangayStatsByName.get(name) || {};
                const cases = Number(barangay.disease_cases || 0);
                const isHotspot = name === hotspotBarangayName && cases > 0;
                const iconSize = isHotspot ? 28 : 18;
                const iconAnchor = iconSize / 2;
                const marker = L.marker([lat, lng], {
                    keyboard: true,
                    title: name,
                    alt: name,
                    icon: L.divIcon({
                        className: '',
                        html: `<span class="nasugbu-dot-marker${isHotspot ? ' is-hotspot' : ''}" data-barangay="${escapeMapText(name)}" aria-label="${escapeMapText(name)}"></span>`,
                        iconSize: [iconSize, iconSize],
                        iconAnchor: [iconAnchor, iconAnchor],
                        tooltipAnchor: [0, isHotspot ? -18 : -12],
                        popupAnchor: [0, isHotspot ? -18 : -12]
                    })
                });

                // Hover tooltip displaying barangay name & cases
                marker.bindTooltip(`
            <div class="text-center font-sans">
                <span class="font-bold text-slate-900">${escapeMapText(name || 'Barangay')}</span>
            </div>
        `, {
                    permanent: false,
                    direction: 'top',
                    offset: [0, -12],
                    sticky: true,
                    interactive: false
                });
                marker.on('mouseover focus', () => {
                    marker.openTooltip();
                    marker.getElement()?.querySelector('.nasugbu-dot-marker')?.classList.add('is-hovered');
                });
                marker.on('mouseout blur popupclose', () => {
                    marker.closeTooltip();
                    marker.getElement()?.querySelector('.nasugbu-dot-marker')?.classList.remove('is-hovered');
                });

                // Detailed popup on click
                marker.bindPopup(`
            <div class="p-1 font-sans">
                <h4 class="font-bold text-slate-900 text-sm mb-1">${escapeMapText(name || 'Barangay')}</h4>
                <div class="text-xs space-y-1 text-slate-600">
                    <p><span class="font-semibold text-slate-800">${Number(barangay.residents || 0).toLocaleString()}</span> registered residents</p>
                    <p><span class="font-semibold text-red-600">${cases.toLocaleString()}</span> total disease cases</p>
                    <p><span class="font-semibold text-amber-600">${Number(barangay.recent_cases || 0).toLocaleString()}</span> recent cases (30 days)</p>
                </div>
            </div>
        `);

                marker.addTo(markerGroup);
            });

            map.fitBounds(nasugbuFocusBounds, {
                padding: [18, 18],
                maxZoom: 13,
                animate: false
            });

            const recenterBtn = document.getElementById('recenterNasugbuMapBtn');
            if (recenterBtn) {
                recenterBtn.addEventListener('click', () => {
                    map.fitBounds(nasugbuFocusBounds, {
                        padding: [18, 18],
                        maxZoom: 13,
                        animate: true
                    });
                });
            }

            const refreshMapLayout = () => {
                map.invalidateSize({
                    animate: false,
                    pan: false
                });
                map.fitBounds(nasugbuFocusBounds, {
                    padding: [18, 18],
                    maxZoom: 13,
                    animate: false
                });
            };

            [50, 150, 350, 800, 1400, 2200].forEach((delay) => {
                window.setTimeout(refreshMapLayout, delay);
            });
            if ('ResizeObserver' in window) {
                new ResizeObserver(refreshMapLayout).observe(mapElement);
            }
            window.addEventListener('resize', refreshMapLayout);
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initializeNasugbuHealthMap);
        } else {
            initializeNasugbuHealthMap();
        }
        window.addEventListener('load', initializeNasugbuHealthMap);

        const overviewChartCard = document.querySelector('.unified-chart-card');
        const overviewChartTooltip = overviewChartCard?.querySelector('.unified-chart-tooltip');
        const overviewChartHoverLine = overviewChartCard?.querySelector('.unified-chart-hover-line');
        const overviewChartPoints = Array.from(document.querySelectorAll('.unified-chart-point'));

        if (overviewChartCard && overviewChartTooltip && overviewChartHoverLine && overviewChartPoints.length) {
            const updateHover = (event) => {
                const rect = overviewChartCard.getBoundingClientRect();
                const localX = Math.min(Math.max(event.clientX - rect.left, 0), rect.width);
                const chartWidth = 520;
                const xInChart = (localX / rect.width) * chartWidth;
                const nearest = overviewChartPoints.reduce((closest, point) => {
                    const pointX = Number(point.getAttribute('cx'));
                    const currentDistance = Math.abs(pointX - xInChart);
                    const closestDistance = Math.abs(Number(closest.getAttribute('cx')) - xInChart);
                    return currentDistance < closestDistance ? point : closest;
                }, overviewChartPoints[0]);

                const pointX = Number(nearest.getAttribute('cx'));
                const relativeX = (pointX / chartWidth) * rect.width;
                const label = nearest.dataset.label || 'Month';
                const value = nearest.dataset.value || '0';

                overviewChartHoverLine.style.left = `${relativeX}px`;
                overviewChartTooltip.style.left = `${relativeX}px`;
                overviewChartTooltip.style.top = `${Math.max(22, rect.height * 0.18)}px`;
                overviewChartTooltip.querySelector('.unified-chart-tooltip-label').textContent = label;
                overviewChartTooltip.querySelector('.unified-chart-tooltip-value').textContent = `${value} cases`;

                overviewChartPoints.forEach((point) => {
                    point.classList.toggle('is-active', point === nearest);
                });

                overviewChartTooltip.classList.add('is-visible');
                overviewChartHoverLine.classList.add('is-visible');
            };

            overviewChartCard.addEventListener('pointermove', updateHover);
            overviewChartCard.addEventListener('pointerleave', () => {
                overviewChartPoints.forEach((point) => point.classList.remove('is-active'));
                overviewChartTooltip.classList.remove('is-visible');
                overviewChartHoverLine.classList.remove('is-visible');
            });
        }

        document.querySelectorAll('[data-certificate-cleanup-panel]').forEach((panel) => {
            const toggle = panel.querySelector('[data-certificate-select-toggle]');
            const deleteSelected = panel.querySelector('[data-certificate-delete-selected]');
            const selectBoxes = Array.from(panel.querySelectorAll('[data-certificate-select-box]'));
            const selectHeaders = Array.from(panel.querySelectorAll('[data-certificate-select-header]'));
            const inputs = Array.from(panel.querySelectorAll('input[name="certificate_ids[]"]'));
            let selectMode = false;

            const renderSelectMode = () => {
                selectBoxes.forEach((box) => box.classList.toggle('hidden', !selectMode));
                selectHeaders.forEach((box) => box.classList.toggle('hidden', !selectMode));
                const hasSelection = inputs.some((input) => input.checked);
                deleteSelected?.classList.toggle('hidden', !selectMode || !hasSelection);
                if (toggle) {
                    toggle.textContent = selectMode ? 'Cancel Select' : 'Select Delete';
                    toggle.classList.toggle('bg-red-100', selectMode);
                    toggle.classList.toggle('text-red-800', selectMode);
                    toggle.classList.toggle('hover:bg-red-200', selectMode);
                    toggle.classList.toggle('bg-emerald-100', !selectMode);
                    toggle.classList.toggle('text-emerald-900', !selectMode);
                    toggle.classList.toggle('hover:bg-emerald-200', !selectMode);
                }
                if (!selectMode) {
                    inputs.forEach((input) => {
                        input.checked = false;
                    });
                }
            };

            toggle?.addEventListener('click', () => {
                selectMode = !selectMode;
                renderSelectMode();
            });
            inputs.forEach((input) => input.addEventListener('change', renderSelectMode));
            renderSelectMode();
        });

        const initializeAdminMessagePanel = (panel) => {
            const toggle = panel.querySelector('[data-message-select-toggle]');
            const deleteSelected = panel.querySelector('[data-message-delete-selected]');
            let selectMode = false;

            const renderSelectMode = () => {
                const selectBoxes = Array.from(panel.querySelectorAll('[data-message-select-box]'));
                const checkedBoxes = Array.from(panel.querySelectorAll('input[name="message_ids[]"]:checked'));
                panel.dataset.messageSelectMode = selectMode ? '1' : '0';
                selectBoxes.forEach((box) => box.classList.toggle('hidden', !selectMode));
                deleteSelected?.classList.toggle('hidden', !selectMode || checkedBoxes.length === 0);
                if (toggle) {
                    toggle.textContent = selectMode ? 'Cancel Select' : 'Select Delete';
                    toggle.classList.toggle('bg-red-100', selectMode);
                    toggle.classList.toggle('text-red-800', selectMode);
                    toggle.classList.toggle('hover:bg-red-200', selectMode);
                    toggle.classList.toggle('bg-emerald-100', !selectMode);
                    toggle.classList.toggle('text-emerald-900', !selectMode);
                    toggle.classList.toggle('hover:bg-emerald-200', !selectMode);
                }
                if (!selectMode) {
                    panel.querySelectorAll('input[name="message_ids[]"]').forEach((input) => {
                        input.checked = false;
                    });
                }
            };

            panel.querySelectorAll('input[name="message_ids[]"]').forEach((input) => {
                if (input.dataset.liveMessageBound === '1') return;
                input.dataset.liveMessageBound = '1';
                input.addEventListener('change', renderSelectMode);
            });

            if (toggle && toggle.dataset.liveMessageBound !== '1') {
                toggle.dataset.liveMessageBound = '1';
                toggle.addEventListener('click', () => {
                    selectMode = !selectMode;
                    renderSelectMode();
                });
            }

            renderSelectMode();
            panel.renderMessageSelectMode = renderSelectMode;
        };

        document.querySelectorAll('[data-message-cleanup-panel]').forEach(initializeAdminMessagePanel);

        const escapeAdminMessageHtml = (value) => String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');

        const formatAdminMessageDate = (value) => {
            const date = new Date(value);
            if (Number.isNaN(date.getTime())) return value || '';
            return date.toLocaleString([], {
                month: 'short',
                day: '2-digit',
                year: 'numeric',
                hour: 'numeric',
                minute: '2-digit'
            });
        };

        const renderAdminMessagesLive = (messages) => {
            const panel = document.querySelector('[data-message-cleanup-panel]');
            const count = document.getElementById('admin-message-count');
            const list = document.getElementById('admin-message-list');
            if (!panel || !count || !list) return;

            const activeElement = document.activeElement;
            const adminIsTypingReply = activeElement && list.contains(activeElement) && activeElement.matches('input[name="reply"]');
            const adminHasSelectedMessages = panel.querySelectorAll('input[name="message_ids[]"]:checked').length > 0;
            if (adminIsTypingReply || adminHasSelectedMessages) return;

            count.textContent = `${messages.length} Message${messages.length === 1 ? '' : 's'}`;
            panel.querySelector('[data-message-select-toggle]')?.classList.toggle('hidden', messages.length === 0);

            if (!messages.length) {
                list.innerHTML = '<p class="text-xs text-gray-400 italic py-3">No resident messages found in database.</p>';
                panel.renderMessageSelectMode?.();
                return;
            }

            list.innerHTML = `<div class="space-y-3">${messages.map((msg) => {
                const residentName = `${msg.first_name || ''} ${msg.last_name || ''}`.trim() || `Resident #${msg.resident_id || ''}`;
                const email = msg.email ? ` (${escapeAdminMessageHtml(msg.email)})` : '';
                const adminReply = msg.admin_reply
                    ? `<div class="ml-4 p-2.5 bg-purple-50 rounded border-l-4 border-purple-600 text-xs">
                        <p class="font-bold text-purple-900">Admin Response:</p>
                        <p class="text-purple-800 mt-0.5">${escapeAdminMessageHtml(msg.admin_reply)}</p>
                      </div>`
                    : `<form method="post" class="mt-2 flex gap-2">
                        <input type="hidden" name="csrf_token" value="<?php echo esc(portalCsrfToken()); ?>">
                        <input type="hidden" name="action" value="reply_message">
                        <input type="hidden" name="message_id" value="${Number(msg.id) || 0}">
                        <input required name="reply" class="flex-1 text-xs px-3 py-1.5 rounded border border-gray-300 focus:outline-none focus:border-purple-600" placeholder="Type response to resident...">
                        <button type="submit" class="px-3 py-1.5 bg-purple-700 text-white rounded text-xs font-semibold hover:bg-purple-800">Send Reply</button>
                      </form>`;

                return `<div class="p-3 bg-gray-50 rounded-xl border border-gray-100 space-y-2">
                    <div class="flex items-start gap-2 text-xs">
                      <label class="hidden pt-0.5" data-message-select-box>
                        <input form="deleteSelectedMessagesForm" type="checkbox" name="message_ids[]" value="${Number(msg.id) || 0}" class="h-4 w-4 rounded border-gray-300 text-red-600 focus:ring-red-500">
                        <span class="sr-only">Select message ${Number(msg.id) || 0}</span>
                      </label>
                      <div class="flex flex-1 items-center justify-between gap-3">
                        <span class="font-bold text-gray-900">${escapeAdminMessageHtml(residentName)}${email}</span>
                        <span class="text-gray-400">${escapeAdminMessageHtml(formatAdminMessageDate(msg.created_at))}</span>
                      </div>
                    </div>
                    <p class="text-xs font-semibold text-purple-900">Subject: ${escapeAdminMessageHtml(msg.subject || 'General Inquiry')}</p>
                    <p class="text-xs text-gray-700 bg-white p-2.5 rounded border border-gray-200">${escapeAdminMessageHtml(msg.message || '')}</p>
                    ${adminReply}
                  </div>`;
            }).join('')}</div>`;

            panel.querySelectorAll('input[name="message_ids[]"]').forEach((input) => {
                input.addEventListener('change', () => panel.renderMessageSelectMode?.());
            });
            panel.renderMessageSelectMode?.();
        };

        const fetchAdminMessagesLive = async () => {
            try {
                const response = await fetch('RHUAdminDashboard.php?api=admin_messages', {
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json'
                    }
                });
                if (!response.ok) return;
                const data = await response.json();
                if (data.success) {
                    const messages = data.messages || [];
                    const signature = messages.map((message) => `${message.id}:${message.status}:${message.admin_reply || ''}:${message.created_at}`).join('|');
                    const panel = document.querySelector('[data-message-cleanup-panel]');
                    const list = document.getElementById('admin-message-list');
                    const activeElement = document.activeElement;
                    const adminIsTypingReply = activeElement && list?.contains(activeElement) && activeElement.matches('input[name="reply"]');
                    const adminHasSelectedMessages = panel?.querySelectorAll('input[name="message_ids[]"]:checked').length > 0;
                    if (adminIsTypingReply || adminHasSelectedMessages) return;
                    if (!panel || panel.dataset.messageLiveSignature !== signature) {
                        if (panel) panel.dataset.messageLiveSignature = signature;
                        renderAdminMessagesLive(messages);
                    }
                }
            } catch (error) {
                console.error('Failed to refresh resident messages:', error);
            }
        };

        if (document.querySelector('[data-message-cleanup-panel]')) {
            fetchAdminMessagesLive();
            window.setInterval(fetchAdminMessagesLive, 5000);
        }

        document.querySelectorAll('form').forEach((form) => {
            const residentSelect = form.querySelector('select[name="resident_id"]');
            const certificateType = form.querySelector('select[name="certificate_type_id"]');
            const staffSelect = form.querySelector('[data-certificate-staff-select]');
            if (!certificateType || !staffSelect) return;
            const purposeField = form.querySelector('[data-certificate-purpose]');
            const staffOptions = Array.from(staffSelect.options);

            const filterRelatedStaff = () => {
                const selected = certificateType.selectedOptions[0];
                const allowedGroups = (selected?.dataset.staffGroups || '').split(',').filter(Boolean);
                let visibleCount = 0;
                staffOptions.forEach((option, index) => {
                    if (index === 0) {
                        option.hidden = false;
                        option.disabled = false;
                        return;
                    }
                    const staffGroups = (option.dataset.staffGroups || '').split(',').filter(Boolean);
                    const isRelated = allowedGroups.length > 0 && staffGroups.some((group) => allowedGroups.includes(group));
                    option.hidden = !isRelated;
                    option.disabled = !isRelated;
                    if (isRelated) visibleCount++;
                });
                staffSelect.value = '';
                staffSelect.options[0].textContent = visibleCount > 0 ? 'Assigned doctor / staff' : 'No related staff for this certificate type';
            };

            certificateType.addEventListener('change', filterRelatedStaff);
            residentSelect?.addEventListener('change', () => {
                const request = residentSelect.selectedOptions[0]?.dataset || {};
                if (!request.requestTypeId || request.requestTypeId === '0') return;
                certificateType.value = request.requestTypeId;
                if (purposeField && request.requestPurpose) purposeField.value = request.requestPurpose;
                certificateType.dispatchEvent(new Event('change', {
                    bubbles: true
                }));
            });
            filterRelatedStaff();
        });

        const certificatePreviewModal = document.querySelector('[data-certificate-preview-modal]');
        const certificatePreviewContent = certificatePreviewModal?.querySelector('[data-certificate-preview-content]');
        const certificatePreviewClose = certificatePreviewModal?.querySelector('[data-certificate-preview-close]');
        const certificatePreviewDownload = certificatePreviewModal?.querySelector('[data-certificate-preview-download]');
        const closeCertificatePreview = () => {
            certificatePreviewModal?.classList.add('hidden');
            if (certificatePreviewContent) certificatePreviewContent.innerHTML = '';
            certificatePreviewDownload?.classList.add('hidden');
            certificatePreviewDownload?.classList.remove('inline-flex');
            unlockDashboardBackground();
        };
        document.querySelectorAll('[data-certificate-preview-open]').forEach((button) => {
            button.addEventListener('click', () => {
                const certificateId = button.getAttribute('data-certificate-preview-open');
                const template = document.querySelector(`[data-certificate-preview-template="${certificateId}"]`);
                if (!certificatePreviewModal || !certificatePreviewContent || !template) return;
                certificatePreviewContent.innerHTML = template.innerHTML;
                if (certificatePreviewDownload) {
                    certificatePreviewDownload.href = `?certificate_pdf=${encodeURIComponent(certificateId)}`;
                    certificatePreviewDownload.classList.remove('hidden');
                    certificatePreviewDownload.classList.add('inline-flex');
                }
                lockDashboardBackground();
                certificatePreviewModal.classList.remove('hidden');
                window.setTimeout(() => certificatePreviewClose?.focus?.(), 80);
            });
        });
        certificatePreviewClose?.addEventListener('click', closeCertificatePreview);
        certificatePreviewModal?.addEventListener('click', (event) => {
            if (event.target === certificatePreviewModal) closeCertificatePreview();
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && certificatePreviewModal && !certificatePreviewModal.classList.contains('hidden')) {
                closeCertificatePreview();
            }
        });

        const featureDrawer = document.querySelector('[data-feature-drawer]');
        const drawerBackdrop = document.querySelector('[data-drawer-backdrop]');
        const drawerOpenButton = document.querySelector('[data-drawer-open]');
        const drawerCloseButton = document.querySelector('[data-drawer-close]');
        let drawerReturnFocus = null;
        const setDrawerOpen = (open) => {
            featureDrawer?.classList.toggle('is-open', open);
            drawerBackdrop?.classList.toggle('is-open', open);
            drawerBackdrop?.classList.toggle('hidden', !open);
            document.body.classList.toggle('drawer-open', open);
            featureDrawer?.setAttribute('aria-hidden', String(!open));
            drawerBackdrop?.setAttribute('aria-hidden', String(!open));
            drawerOpenButton?.setAttribute('aria-expanded', String(open));
            if (open) {
                drawerReturnFocus = document.activeElement;
                window.setTimeout(() => drawerCloseButton?.focus(), 120);
            } else if (drawerReturnFocus instanceof HTMLElement) {
                drawerReturnFocus.focus();
            }
        };
        drawerBackdrop?.classList.add('hidden');
        drawerOpenButton?.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            setDrawerOpen(true);
        });
        drawerOpenButton?.addEventListener('touchend', (event) => {
            event.preventDefault();
            event.stopPropagation();
            setDrawerOpen(true);
        }, {
            passive: false
        });
        drawerCloseButton?.addEventListener('click', () => setDrawerOpen(false));
        drawerBackdrop?.addEventListener('click', () => setDrawerOpen(false));
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && featureDrawer?.classList.contains('is-open')) setDrawerOpen(false);
        });

        document.querySelectorAll('table').forEach((table, tableIndex) => {
            const body = table.tBodies[0];
            if (!body || body.rows.length < 5) return;
            const rows = Array.from(body.rows);
            const pageSize = 15;
            let page = 1;
            let query = '';
            const toolbar = document.createElement('div');
            toolbar.className = 'flex items-center justify-between gap-2 border-b bg-gray-50 p-2 text-xs';
            toolbar.innerHTML = `<input aria-label="Search table" class="w-full max-w-xs rounded border p-2" placeholder="Search these records..."><span class="whitespace-nowrap"></span><div class="flex gap-1"><button type="button" class="rounded border bg-white px-2 py-1">Previous</button><button type="button" class="rounded border bg-white px-2 py-1">Next</button></div>`;
            table.parentElement.insertBefore(toolbar, table);
            const input = toolbar.querySelector('input');
            const status = toolbar.querySelector('span');
            const [previous, next] = toolbar.querySelectorAll('button');
            const render = () => {
                const matching = rows.filter(row => row.textContent.toLowerCase().includes(query));
                const pages = Math.max(1, Math.ceil(matching.length / pageSize));
                page = Math.min(page, pages);
                rows.forEach(row => row.hidden = true);
                matching.slice((page - 1) * pageSize, page * pageSize).forEach(row => row.hidden = false);
                status.textContent = `${matching.length} records - Page ${page}/${pages}`;
                previous.disabled = page <= 1;
                next.disabled = page >= pages;
            };
            input.addEventListener('input', () => {
                query = input.value.trim().toLowerCase();
                page = 1;
                render();
            });
            previous.addEventListener('click', () => {
                page--;
                render();
            });
            next.addEventListener('click', () => {
                page++;
                render();
            });
            render();
        });

        const dashboardHeader = document.querySelector('.dashboard-header');
        const adminScrollContainer = document.querySelector('.admin-main');
        const featureDrawerNav = document.querySelector('#feature-drawer nav');
        const scrollTopButton = document.querySelector('.scroll-top-button');
        const adminTabScrollKey = 'rhuAdminDashboardScroll';
        const adminNavScrollKey = 'rhuAdminDashboardNavScroll';
        const saveAdminTabScroll = () => {
            const scrollTarget = adminScrollContainer && getComputedStyle(adminScrollContainer).overflowY !== 'visible' ?
                adminScrollContainer.scrollTop :
                window.scrollY;
            sessionStorage.setItem(adminTabScrollKey, String(Math.max(0, Number(scrollTarget) || 0)));
            if (featureDrawerNav) {
                sessionStorage.setItem(adminNavScrollKey, String(Math.max(0, Number(featureDrawerNav.scrollTop) || 0)));
            }
        };
        const restoreAdminTabScroll = () => {
            const savedScroll = Number.parseFloat(sessionStorage.getItem(adminTabScrollKey) || '0');
            const savedNavScroll = Number.parseFloat(sessionStorage.getItem(adminNavScrollKey) || '0');
            requestAnimationFrame(() => {
                if (featureDrawerNav && Number.isFinite(savedNavScroll)) {
                    featureDrawerNav.scrollTop = Math.max(0, savedNavScroll);
                }
                if (Number.isFinite(savedScroll) && savedScroll > 0) {
                    if (adminScrollContainer && getComputedStyle(adminScrollContainer).overflowY !== 'visible') {
                        adminScrollContainer.scrollTop = Math.max(0, savedScroll);
                    } else {
                        window.scrollTo({
                            top: Math.max(0, savedScroll),
                            behavior: 'auto'
                        });
                    }
                }
            });
        };
        document.addEventListener('click', event => {
            const clickedLink = event.target.closest('a[href*="tab="]');
            if (!clickedLink) return;
            saveAdminTabScroll();
        });
        window.addEventListener('beforeunload', saveAdminTabScroll);
        if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
        const updateScrollEffects = () => {
            const scrollY = adminScrollContainer?.scrollTop ?? window.scrollY;
            const scrolled = scrollY > 18;
            dashboardHeader?.classList.toggle('is-scrolled', scrolled);
            scrollTopButton?.classList.toggle('is-visible', scrollY > 420);
        };
        window.addEventListener('scroll', updateScrollEffects, {
            passive: true
        });
        adminScrollContainer?.addEventListener('scroll', updateScrollEffects, {
            passive: true
        });
        restoreAdminTabScroll();
        updateScrollEffects();
        scrollTopButton?.addEventListener('click', () => {
            if (adminScrollContainer && getComputedStyle(adminScrollContainer).overflowY !== 'visible') {
                adminScrollContainer.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
            } else {
                window.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
            }
            saveAdminTabScroll();
        });

        const revealTargets = new Set([
            ...document.querySelectorAll('main > div > div, main > div > section, main .grid > .bg-white, main details')
        ]);
        revealTargets.forEach((element) => {
            element.dataset.scrollReveal = '';
            element.classList.add('is-visible');
            element.style.setProperty('--reveal-delay', '0ms');
        });

        document.querySelectorAll('main .text-2xl.font-black').forEach((value, index) => {
            value.classList.add('metric-value-pop');
            value.style.animationDelay = `${Math.min(index, 8) * 45}ms`;
        });

        const eventImageUrlInput = document.getElementById('eventImageUrl');
        const eventImagePreview = document.getElementById('eventImagePreview');
        if (eventImageUrlInput && eventImagePreview) {
            const previewImage = eventImagePreview.querySelector('[data-event-preview-image]');
            const previewStatus = eventImagePreview.querySelector('[data-event-preview-status]');
            const previewLink = eventImagePreview.querySelector('[data-event-preview-link]');

            previewImage.addEventListener('load', () => {
                previewImage.classList.remove('hidden');
                previewStatus.className = 'rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-[11px] font-semibold text-emerald-700';
                previewStatus.textContent = 'Image link verified. This exact picture will be displayed.';
                eventImageUrlInput.setCustomValidity('');
            });

            previewImage.addEventListener('error', () => {
                previewImage.classList.add('hidden');
                previewImage.removeAttribute('src');
                previewStatus.className = 'rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-[11px] font-semibold text-red-700';
                previewStatus.textContent = 'This is a webpage link, not a direct image. Upload the picture or paste a direct JPG, PNG, WEBP, or image-CDN URL.';
                eventImageUrlInput.setCustomValidity('Please provide a direct image URL or remove this link and upload the image.');
            });

            const updateEventImagePreview = () => {
                const url = eventImageUrlInput.value.trim();
                const validUrl = /^https?:\/\/\S+$/i.test(url);
                eventImagePreview.classList.toggle('hidden', !validUrl);
                eventImageUrlInput.setCustomValidity('');
                if (!validUrl) {
                    previewImage.classList.add('hidden');
                    previewImage.removeAttribute('src');
                    previewLink.textContent = '';
                    return;
                }
                previewImage.classList.add('hidden');
                previewStatus.className = 'rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-[11px] font-semibold text-blue-700';
                previewStatus.textContent = 'Checking image link...';
                previewImage.src = url;
                previewLink.textContent = url;
            };
            eventImageUrlInput.addEventListener('input', updateEventImagePreview);
            updateEventImagePreview();
        }

        const eventSourceUrlInput = document.getElementById('eventSourceUrl');
        const eventImageFileInput = document.getElementById('eventImageFile');
        if (eventSourceUrlInput && eventImagePreview) {
            const resolvedImage = eventImagePreview.querySelector('[data-event-preview-image]');
            const resolvedFacebook = eventImagePreview.querySelector('[data-event-preview-facebook]');
            const resolutionStatus = eventImagePreview.querySelector('[data-event-preview-status]');
            const resolutionLink = eventImagePreview.querySelector('[data-event-preview-link]');
            let resolutionTimer = null;
            let resolutionController = null;

            resolvedImage.addEventListener('load', () => {
                resolvedFacebook.classList.add('hidden');
                resolvedFacebook.removeAttribute('src');
                resolvedImage.classList.remove('hidden');
                resolutionStatus.className = 'rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-[11px] font-semibold text-emerald-700';
                resolutionStatus.textContent = resolvedImage.dataset.previewSource === 'upload' ?
                    'Uploaded picture is ready and will be displayed.' :
                    'Picture found from the link. This image will be displayed.';
                eventSourceUrlInput.setCustomValidity('');
            });

            resolvedImage.addEventListener('error', () => {
                resolvedImage.classList.add('hidden');
                resolvedImage.removeAttribute('src');
                resolutionStatus.className = 'rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-[11px] font-semibold text-red-700';
                const isUploadPreview = resolvedImage.dataset.previewSource === 'upload';
                resolutionStatus.textContent = isUploadPreview ?
                    'This file could not be previewed. Please choose another image.' :
                    'The page provided an image, but its host blocked the preview. Upload the picture instead.';
                if (!isUploadPreview) {
                    eventSourceUrlInput.setCustomValidity('The resolved picture cannot be displayed. Upload the image instead.');
                }
            });

            const resolveEventSourceLink = () => {
                const pageUrl = eventSourceUrlInput.value.trim();
                const validUrl = /^https?:\/\/\S+$/i.test(pageUrl);
                window.clearTimeout(resolutionTimer);
                resolutionController?.abort();
                eventImagePreview.classList.toggle('hidden', !validUrl);
                eventSourceUrlInput.setCustomValidity('');

                if (!validUrl) {
                    resolvedImage.classList.add('hidden');
                    resolvedImage.removeAttribute('src');
                    resolvedFacebook.classList.add('hidden');
                    resolvedFacebook.removeAttribute('src');
                    resolutionLink.textContent = '';
                    return;
                }

                if (eventImageFileInput) {
                    eventImageFileInput.value = '';
                }
                resolvedImage.dataset.previewSource = 'link';
                resolvedImage.classList.add('hidden');
                resolvedImage.removeAttribute('src');
                resolvedFacebook.classList.add('hidden');
                resolvedFacebook.removeAttribute('src');
                resolutionStatus.className = 'rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-[11px] font-semibold text-blue-700';
                resolutionStatus.textContent = 'Reading the link and finding its picture...';
                resolutionLink.textContent = pageUrl;

                resolutionTimer = window.setTimeout(async () => {
                    resolutionController = new AbortController();
                    try {
                        const endpoint = `RHUAdminDashboard.php?resolve_event_image=1&url=${encodeURIComponent(pageUrl)}`;
                        const response = await fetch(endpoint, {
                            signal: resolutionController.signal,
                            headers: {
                                Accept: 'application/json'
                            }
                        });
                        const result = await response.json();
                        if (eventSourceUrlInput.value.trim() !== pageUrl) return;
                        if (!result.ok || !result.resolved_url) {
                            resolutionStatus.className = 'rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-[11px] font-semibold text-red-700';
                            resolutionStatus.textContent = result.error || 'No picture could be found on this link.';
                            eventSourceUrlInput.setCustomValidity(result.error || 'No picture could be found on this link.');
                            return;
                        }
                        if (result.render_type === 'facebook_embed' && result.embed_url) {
                            resolvedImage.classList.add('hidden');
                            resolvedImage.removeAttribute('src');
                            resolvedFacebook.src = result.embed_url;
                            resolvedFacebook.classList.remove('hidden');
                            resolutionStatus.className = 'rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-[11px] font-semibold text-emerald-700';
                            resolutionStatus.textContent = 'Public Facebook post found. Its post picture will be displayed.';
                            resolutionLink.textContent = pageUrl;
                            eventSourceUrlInput.setCustomValidity('');
                            return;
                        }
                        resolutionLink.textContent = result.resolved_url === pageUrl ?
                            pageUrl :
                            `Page link: ${pageUrl}\nPicture found: ${result.resolved_url}`;
                        resolvedImage.src = result.resolved_url;
                    } catch (error) {
                        if (error.name === 'AbortError') return;
                        resolutionStatus.className = 'rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-[11px] font-semibold text-red-700';
                        resolutionStatus.textContent = 'The server could not read this link. Upload the picture instead.';
                        eventSourceUrlInput.setCustomValidity('The server could not read this link.');
                    }
                }, 500);
            };

            eventSourceUrlInput.addEventListener('input', resolveEventSourceLink);
            resolveEventSourceLink();
        }

        if (eventImageFileInput && eventImagePreview) {
            const uploadedPreviewImage = eventImagePreview.querySelector('[data-event-preview-image]');
            const uploadedFacebookPreview = eventImagePreview.querySelector('[data-event-preview-facebook]');
            const uploadedPreviewStatus = eventImagePreview.querySelector('[data-event-preview-status]');
            const uploadedPreviewLink = eventImagePreview.querySelector('[data-event-preview-link]');
            let uploadedObjectUrl = '';

            eventImageFileInput.addEventListener('change', () => {
                const file = eventImageFileInput.files?.[0];
                if (uploadedObjectUrl) {
                    URL.revokeObjectURL(uploadedObjectUrl);
                    uploadedObjectUrl = '';
                }
                eventImageFileInput.setCustomValidity('');

                if (!file) {
                    if (!eventSourceUrlInput?.value.trim()) {
                        eventImagePreview.classList.add('hidden');
                        uploadedPreviewImage.classList.add('hidden');
                        uploadedPreviewImage.removeAttribute('src');
                        uploadedPreviewLink.textContent = '';
                    }
                    return;
                }

                if (!['image/jpeg', 'image/png', 'image/webp', 'image/gif'].includes(file.type)) {
                    eventImageFileInput.setCustomValidity('Please choose a JPG, PNG, WEBP, or GIF image.');
                    eventImageFileInput.reportValidity();
                    eventImagePreview.classList.remove('hidden');
                    uploadedPreviewStatus.className = 'rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-[11px] font-semibold text-red-700';
                    uploadedPreviewStatus.textContent = 'Please choose a JPG, PNG, WEBP, or GIF image.';
                    return;
                }
                if (file.size > 8 * 1024 * 1024) {
                    eventImageFileInput.setCustomValidity('The picture must be no larger than 8 MB.');
                    eventImageFileInput.reportValidity();
                    eventImagePreview.classList.remove('hidden');
                    uploadedPreviewStatus.className = 'rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-[11px] font-semibold text-red-700';
                    uploadedPreviewStatus.textContent = 'The picture must be no larger than 8 MB.';
                    return;
                }

                if (eventSourceUrlInput) {
                    eventSourceUrlInput.value = '';
                    eventSourceUrlInput.setCustomValidity('');
                }
                uploadedFacebookPreview.classList.add('hidden');
                uploadedFacebookPreview.removeAttribute('src');
                uploadedPreviewImage.dataset.previewSource = 'upload';
                uploadedPreviewImage.classList.add('hidden');
                uploadedPreviewStatus.className = 'rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-[11px] font-semibold text-blue-700';
                uploadedPreviewStatus.textContent = 'Loading uploaded picture preview...';
                uploadedPreviewLink.textContent = `${file.name} (${(file.size / 1024 / 1024).toFixed(2)} MB)`;
                eventImagePreview.classList.remove('hidden');
                uploadedObjectUrl = URL.createObjectURL(file);
                uploadedPreviewImage.src = uploadedObjectUrl;
            });
        }
    </script>
    <?php if ($tab === 'staff'): ?>
        <script>
            (() => {
                const livePanelIds = ['staff-folder-live-panel', 'staff-personnel-live-panel', 'staff-records-live-panel'];
                const refreshStaffFolders = async () => {
                    if (document.hidden) return;
                    if (document.querySelector('[data-floating-modal]:not(.hidden), form:focus-within')) return;
                    try {
                        const url = new URL(window.location.href);
                        url.searchParams.set('_live_staff_folders', String(Date.now()));
                        const response = await fetch(url.toString(), {
                            credentials: 'same-origin',
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            }
                        });
                        if (!response.ok) return;
                        const html = await response.text();
                        const nextDoc = new DOMParser().parseFromString(html, 'text/html');
                        livePanelIds.forEach((id) => {
                            const current = document.getElementById(id);
                            const next = nextDoc.getElementById(id);
                            if (current && next) current.replaceWith(next);
                        });
                    } catch (error) {
                        console.error('Staff folder live refresh failed:', error);
                    }
                };
                window.setInterval(refreshStaffFolders, 5000);
                window.addEventListener('focus', refreshStaffFolders);
            })();
        </script>
    <?php endif; ?>
    <?php echo portalRenderNotificationPanel(); ?>
</body>

</html>
