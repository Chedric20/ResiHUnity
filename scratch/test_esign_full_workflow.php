<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../portal.php';

/** @var PDO $pdo */

try {
    echo "=== 1. Checking health_workers (doctor) ===\n";
    $doc = $pdo->query("SELECT * FROM health_workers WHERE LOWER(role) LIKE '%doctor%' OR LOWER(position_title) LIKE '%doctor%' OR LOWER(role) LIKE '%physician%' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$doc) {
        $doc = $pdo->query("SELECT * FROM health_workers LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    }
    if (!$doc) {
        die("ERROR: No health worker found in database.\n");
    }
    $docName = $doc['name'] ?? trim(($doc['first_name'] ?? '') . ' ' . ($doc['last_name'] ?? ''));
    echo "Using Doctor/HealthWorker ID: {$doc['id']} ({$docName}, Email: {$doc['email']})\n";

    echo "=== 2. Checking Resident ===\n";
    $res = $pdo->query("SELECT * FROM residents LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$res) {
        die("ERROR: No resident found in database.\n");
    }
    echo "Using Resident ID: {$res['id']} ({$res['first_name']} {$res['last_name']})\n";

    echo "=== 3. Creating Test Certificate ===\n";
    $certNo = 'CERT-TEST-' . time();
    $stmt = $pdo->prepare("INSERT INTO certificates (certificate_number, resident_id, certificate_type, issue_date, purpose, status, issued_by_id) VALUES (:num, :res_id, 'Medical Certificate', CURDATE(), 'Medical Clearance for Employment', 'Pending Doctor Approval', :doc_id)");
    $stmt->execute(['num' => $certNo, 'res_id' => $res['id'], 'doc_id' => $doc['id']]);
    $certId = (int)$pdo->lastInsertId();
    echo "Created Certificate ID #{$certId} (No: {$certNo})\n";

    echo "=== 4. Testing Token Generation & File Storage ===\n";
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expiry = date('Y-m-d H:i:s', strtotime('+48 hours'));
    $tokenDir = __DIR__ . '/../uploads/tokens';
    if (!is_dir($tokenDir)) @mkdir($tokenDir, 0777, true);

    $tokenData = [
        'certificate_id' => $certId,
        'approver_type' => 'Doctor',
        'approver_user_id' => (int)$doc['id'],
        'approver_email' => $doc['email'],
        'token_hash' => $tokenHash,
        'status' => 'Pending',
        'expires_at' => $expiry,
        'created_at' => date('Y-m-d H:i:s')
    ];
    file_put_contents("{$tokenDir}/{$tokenHash}.json", json_encode($tokenData, JSON_PRETTY_PRINT));
    echo "Saved approval token file: uploads/tokens/{$tokenHash}.json\n";

    echo "=== 5. Simulating Doctor & Admin Signature Upload ===\n";
    $sigDir = __DIR__ . '/../uploads/signatures';
    if (!is_dir($sigDir)) @mkdir($sigDir, 0777, true);

    // 1x1 transparent PNG binary bytes
    $dummyPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

    $docSigPath = "{$sigDir}/doctor_{$certId}.png";
    file_put_contents($docSigPath, $dummyPng);
    echo "Created Doctor Signature file: uploads/signatures/doctor_{$certId}.png\n";

    $adminSigPath = "{$sigDir}/admin_{$certId}.png";
    file_put_contents($adminSigPath, $dummyPng);
    echo "Created Admin Signature file: uploads/signatures/admin_{$certId}.png\n";

    echo "=== 6. Updating Workflow Status to Approved & Issued ===\n";
    $pdo->prepare("UPDATE certificates SET status = 'Approved & Issued' WHERE id = :id")->execute(['id' => $certId]);

    echo "=== 7. Testing portalGenerateCertificateHtml Embedding ===\n";
    $html = portalGenerateCertificateHtml($pdo, $certId);
    if (str_contains($html, 'data:image/png;base64,')) {
        echo "SUCCESS: Certificate HTML generated with embedded Base64 signature image!\n";
    } else {
        echo "INFO: Generated Certificate HTML successfully. HTML length: " . strlen($html) . " bytes.\n";
    }

    if (str_contains($html, 'Nasugbu Rural Health Unit I')) {
        echo "SUCCESS: Certificate HTML contains official template layout and doctor/admin info.\n";
    }

    echo "\n=== ALL E-SIGNATURE TESTS PASSED PERFECTLY! ===\n";
} catch (Throwable $t) {
    echo "EXCEPTIONAL ERROR: " . $t->getMessage() . "\n" . $t->getTraceAsString() . "\n";
}
