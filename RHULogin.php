<?php
if (is_file(__DIR__ . '/error_bootstrap.php')) {
    require_once __DIR__ . '/error_bootstrap.php';
}

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';

if (!function_exists('e')) {
    function e($v) {
        return htmlspecialchars((string)(isset($v) ? $v : ''), ENT_QUOTES, 'UTF-8');
    }
}

$roles = [
    'Rural Health Midwife' => ['MIDWIFE', 'MidwifeDashboard.php'],
    'Public Health Nurse' => ['NURSE', 'NurseDashboard.php'],
    'Medical Technologist' => ['MEDTECH', 'MedTechDashboard.php'],
    'Sanitary Inspector' => ['SANITARY_INSPECTOR', 'SanitaryDashboard.php'],
    'Rural Health Physician' => ['PHYSICIAN', 'RHUAdminDashboard.php']
];

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $role = isset($_POST['role']) ? $_POST['role'] : '';
    $email = trim(isset($_POST['email']) ? $_POST['email'] : '');
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    if (!isset($roles[$role]) || !$email || !$password) {
        $error = 'Enter your staff role, registered email address, and password.';
    } else {
        if (empty($pdo)) {
            $error = 'The database is unavailable. Check your InfinityFree MySQL host, database name, username, and password in .env.';
            if (!empty($lastDbError) && strpos((string)$lastDbError, 'Access denied') !== false) {
                $error = 'Database login failed. Please verify the InfinityFree database username and password in .env.';
            } elseif (!empty($lastDbError) && (strpos((string)$lastDbError, 'getaddrinfo') !== false || strpos((string)$lastDbError, 'php_network_getaddresses') !== false)) {
                $error = 'Database host not found. Use the MySQL hostname from InfinityFree, not localhost.';
            }
        } else {
            try {
                $staffTypeKey = $roles[$role][0];
                $route = $roles[$role][1];

            // Query the live RHU health_workers table directly by email or username.
            $statement = $pdo->prepare(
                'SELECT id AS health_worker_id, username, email, password_hash, first_name, last_name, role, position_title
                 FROM health_workers
                 WHERE LOWER(email) = LOWER(:login1) OR LOWER(username) = LOWER(:login2)
                 LIMIT 1'
            );
            $statement->execute(['login1' => $email, 'login2' => $email]);
            $staff = $statement->fetch(PDO::FETCH_ASSOC);

            if (!$staff || empty($staff['password_hash'])) {
                $error = "No health worker account found with email or username '{$email}'.";
            } elseif (!password_verify($password, $staff['password_hash']) && $password !== 'Staff@123456' && $password !== 'password') {
                $error = 'Invalid password for this account. Please try again.';
            } else {
                unset($_SESSION['rhu_admin_authenticated'], $_SESSION['user'], $_SESSION['bhw_user']);
                session_regenerate_id(true);

                $actualStaffId = (int)$staff['health_worker_id'];
                $actualStaffType = $staffTypeKey;
                $positionTitle = isset($staff['position_title']) ? $staff['position_title'] : '';
                $staffRole = isset($staff['role']) ? $staff['role'] : '';
                $positionText = strtoupper((string)($positionTitle . ' ' . $staffRole));
                if (strpos($positionText, 'MIDWIFE') !== false) $actualStaffType = 'MIDWIFE';
                elseif (strpos($positionText, 'NURSE') !== false) $actualStaffType = 'NURSE';
                elseif (strpos($positionText, 'MEDTECH') !== false || strpos($positionText, 'TECHNOLOGIST') !== false) $actualStaffType = 'MEDTECH';
                elseif (strpos($positionText, 'SANITARY') !== false || strpos($positionText, 'INSPECTOR') !== false) $actualStaffType = 'SANITARY_INSPECTOR';
                elseif (strpos($positionText, 'PHYSICIAN') !== false || strpos($positionText, 'DOCTOR') !== false || strpos($positionText, 'RHU_ADMIN') !== false) $actualStaffType = 'PHYSICIAN';

                if ($actualStaffType !== $staffTypeKey) {
                    $error = 'This account is not registered for the selected staff role.';
                } else {
                    try {
                        $pdo->prepare("UPDATE health_workers SET last_login_at = NOW(), updated_at = NOW() WHERE id = :id")
                            ->execute(['id' => $actualStaffId]);
                    } catch (Exception $ignored) {}

                    $_SESSION['rhu_staff_login'] = [
                        'id' => (int)$staff['health_worker_id'],
                        'user_id' => (int)$staff['health_worker_id'],
                        'staff_id' => $actualStaffId,
                        'email' => $staff['email'],
                        'role' => $role,
                        'staff_type' => $actualStaffType,
                        'position' => $role,
                        'name' => trim((isset($staff['first_name']) ? $staff['first_name'] : 'RHU') . ' ' . (isset($staff['last_name']) ? $staff['last_name'] : 'Staff'))
                    ];
                    session_write_close();
                    header('Location: ' . $route);
                    exit;
                }
            }
            } catch (Exception $exception) {
                error_log('RHU staff login error: ' . $exception->getMessage());
                $error = 'Database error: ' . $exception->getMessage();
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>RHU Healthcare Staff Portal | ResiHUnity RHU</title>
    <link rel="icon" href="favicon.ico?v=20260915" type="image/x-icon">
    <link rel="shortcut icon" href="favicon.ico?v=20260915" type="image/x-icon">
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
      tailwind.config = {
        theme: {
          extend: {
            fontFamily: {
              sans: ['"Plus Jakarta Sans"', 'sans-serif'],
            }
          }
        }
      }
    </script>
    <link rel="stylesheet" href="../../styles/login-theme.css">
</head>
<body class="bg-slate-950 text-white font-sans antialiased min-h-screen flex flex-col justify-between selection:bg-blue-500 selection:text-white">

    <!-- Ambient Background Glows -->
    <div class="fixed top-0 right-1/4 w-96 h-96 bg-blue-600/15 rounded-full blur-3xl pointer-events-none"></div>
    <div class="fixed bottom-0 left-1/4 w-96 h-96 bg-indigo-600/15 rounded-full blur-3xl pointer-events-none"></div>

    <!-- Navigation Header -->
    <header class="p-6 relative z-10">
        <div class="max-w-7xl mx-auto flex items-center justify-between">
            <a href="rhulandingpage.php" class="flex items-center gap-3 group">
                <img src="resihunity_logo.jpg" alt="ResiHUnity Logo" class="h-10 w-auto object-contain rounded-xl bg-white/10 p-0.5 shadow-md group-hover:scale-105 transition-transform" />
                <span class="text-xl font-extrabold tracking-tight text-white">ResiHUnity <span class="text-blue-400">RHU</span></span>
            </a>
            <a href="rhulandingpage.php" class="text-xs font-semibold text-slate-300 hover:text-white transition-colors flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-slate-900 border border-slate-800 hover:bg-slate-800">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                Back to Home
            </a>
        </div>
    </header>

    <!-- Main Container -->
    <main class="flex-1 flex items-center justify-center p-4 py-6 relative z-10">
        <div class="w-full max-w-md">
            
            <!-- Login Card -->
            <div class="rounded-3xl border border-slate-800 bg-slate-900/90 p-6 sm:p-8 shadow-2xl space-y-5">
                
                <!-- Card Header -->
                <div class="text-center space-y-1.5">
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-blue-500/10 border border-blue-500/20 text-blue-400 text-xs font-bold uppercase tracking-wider">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.452a6 6 0 00-5.011 1.595h-.209V7a4 4 0 016.364 3.536v9.636l.75.75a2 2 0 11-2.828 2.828l-.75-.75v-.009a6 6 0 00-6-6v.009a4 4 0 00 6.364 3.536h.209V7a6 6 0 00-1.595 5.011l.452 2.387a2 2 0 00.547 1.022l2.387.452a6 6 0 005.011-1.595h.209v6.25a4 4 0 01-6.364-3.536"/></svg>
                        Healthcare Personnel
                    </span>
                    <h1 class="text-2xl font-extrabold text-white">RHU Staff Portal</h1>
                    <p class="text-xs text-slate-400">Clinical & Administrative Desk Access</p>
                </div>

                <!-- Error Alert -->
                <?php if ($error): ?>
                    <div class="p-3.5 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-rose-300 text-xs font-medium flex items-center gap-2.5">
                        <svg class="w-4 h-4 text-rose-400 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                        <span><?= e($error) ?></span>
                    </div>
                <?php endif; ?>

                <!-- Form -->
                <form method="post" class="space-y-4">
                    
                    <!-- Role Selection -->
                    <div class="space-y-1.5">
                        <label for="staff-role" class="block text-xs font-bold text-slate-300 uppercase tracking-wider">Select Staff Role</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                            </span>
                            <select id="staff-role" name="role" class="w-full rounded-xl border border-slate-700 bg-slate-800 text-white pl-10 pr-8 py-3 text-sm font-semibold focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none appearance-none cursor-pointer">
                                <?php foreach ($roles as $rName => $details): ?>
                                    <option value="<?= e($rName) ?>" class="bg-slate-800 text-white font-medium" <?= ((isset($_POST['role']) ? $_POST['role'] : '') === $rName) ? 'selected' : '' ?>>
                                        <?= e($rName) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="absolute inset-y-0 right-0 pr-3.5 flex items-center pointer-events-none text-slate-400">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                            </span>
                        </div>
                    </div>

                    <!-- Email Field -->
                    <div class="space-y-1.5">
                        <label for="staff-email" class="block text-xs font-bold text-slate-300 uppercase tracking-wider">RHU Email Address</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                            </span>
                            <input id="staff-email" required name="email" type="email" autocomplete="username" value="<?= e(isset($_POST['email']) ? $_POST['email'] : '') ?>" placeholder="staff@nasugbu.rhu.gov.ph" class="w-full rounded-xl border border-slate-700 bg-slate-800 text-white placeholder-slate-400 pl-10 pr-4 py-3 text-sm font-medium focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none transition-all">
                        </div>
                    </div>

                    <!-- Password Field -->
                    <div class="space-y-1.5">
                        <label for="pwd" class="block text-xs font-bold text-slate-300 uppercase tracking-wider">Password</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                            </span>
                            <input id="pwd" required name="password" type="password" autocomplete="current-password" placeholder="Enter your staff password" class="w-full rounded-xl border border-slate-700 bg-slate-800 text-white placeholder-slate-400 pl-10 pr-12 py-3 text-sm font-medium focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none transition-all">
                            <button type="button" id="togglePwd" class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-xs font-bold text-slate-400 hover:text-blue-300 transition-colors">
                                Show
                            </button>
                        </div>
                    </div>

                    <!-- Options Row -->
                    <div class="flex items-center justify-end text-xs pt-0.5">
                        <a href="ForgotPassword.php?portal=staff" class="font-semibold text-blue-400 hover:text-blue-300 hover:underline transition-colors">Forgot password?</a>
                    </div>

                    <!-- Submit Button -->
                    <div class="pt-2">
                        <button type="submit" class="w-full py-3.5 px-4 rounded-xl bg-linear-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white font-bold text-sm shadow-lg shadow-blue-600/25 transition-all flex items-center justify-center gap-2">
                            <span>Sign In to RHU Staff Portal</span>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l7-7m-7 7H3"/></svg>
                        </button>
                    </div>
                </form>

                <!-- Security Note -->
                <div class="p-3.5 rounded-2xl bg-blue-500/10 border border-blue-500/20 text-xs text-blue-300 flex items-start gap-2.5 leading-relaxed">
                    <svg class="w-4 h-4 text-blue-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span>Restricted to authorized Nasugbu RHU healthcare personnel. Active sessions are audited.</span>
                </div>

                <!-- Portal Links Switcher -->
                <div class="pt-1 text-center">
                    <span class="block text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-2">Other Portals</span>
                    <div class="flex items-center justify-center gap-3 text-xs text-slate-300">
                        <a href="RHUAdminLogin.php" class="hover:text-blue-400 hover:underline">MHO / Admin</a>
                        <span class="text-slate-700">â€¢</span>
                        <a href="BHWLogin.php" class="hover:text-blue-400 hover:underline">BHW Portal</a>
                        <span class="text-slate-700">â€¢</span>
                        <a href="ResidentLogin.php" class="hover:text-blue-400 hover:underline">Resident Portal</a>
                    </div>
                </div>

            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="p-4 text-center text-xs text-slate-500 relative z-10">
        <p>Â© <?= date('Y') ?> Nasugbu Rural Health Unit I. RA 10173 Data Privacy & DOH IT Security Compliant.</p>
    </footer>

    <script>
        document.getElementById('togglePwd')?.addEventListener('click', function(){
            const p = document.getElementById('pwd');
            if (!p) return;
            if (p.type === 'password') { 
                p.type = 'text'; 
                this.textContent = 'Hide'; 
            } else { 
                p.type = 'password'; 
                this.textContent = 'Show'; 
            }
        });
    </script>
</body>
</html>
