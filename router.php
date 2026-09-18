<?php
// This script converts all .tsx component files to PHP
// Place all remaining .tsx files content below and convert to PHP

if (session_status() === PHP_SESSION_NONE) session_start();

// Handle file-based routing for PHP components
$component = isset($_GET['component']) ? basename($_GET['component']) : 'LandingPage';
$basePath = __DIR__;

$components = [
    'AdminDashboard', 'AdminLogin', 'AdminStaffDashboard',
    'DeviceIndicator', 'DonationCertificate', 'DonorDashboard', 'HospitalRegistration',
    'LandingPage', 'LoginSelection', 'MedTechDashboard', 'MidwifeDashboard', 'NurseDashboard',
    'PlatformAwareDashboard', 'ResidentDashboard', 'ResidentLogin', 'RHUAdminDashboard',
    'RHUAdminLogin', 'RHUDashboard', 'RHULogin', 'SanitaryDashboard'
];

// Verify component exists
if (!in_array($component, $components)) {
    http_response_code(404);
    die('Component not found');
}

// Include the component PHP file
$componentFile = $basePath . '/' . $component . '.php';
if (file_exists($componentFile)) {
    include $componentFile;
} else {
    // Generate response if file doesn't exist yet
    echo "<!-- Component $component not yet converted -->";
}
?>
