<?php

function adminDownloadSqlBackup(PDO $pdo): never
{
    header('Content-Type: application/sql; charset=UTF-8');
    header('Content-Disposition: attachment; filename="rhu-backup-' . date('Y-m-d-His') . '.sql"');
    echo "-- RedPulse RHU database backup\nSET FOREIGN_KEY_CHECKS=0;\n";
    $tables = $pdo->query('SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() ORDER BY table_name')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        $quoted = '`' . str_replace('`', '``', $table) . '`';
        $create = $pdo->query("SHOW CREATE TABLE {$quoted}")->fetch(PDO::FETCH_NUM);
        echo "\nDROP TABLE IF EXISTS {$quoted};\n", $create[1], ";\n";
        $rows = $pdo->query("SELECT * FROM {$quoted}");
        while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
            $columns = implode(',', array_map(static fn($c) => '`' . str_replace('`', '``', $c) . '`', array_keys($row)));
            $values = implode(',', array_map(static fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), array_values($row)));
            echo "INSERT INTO {$quoted} ({$columns}) VALUES ({$values});\n";
        }
    }
    echo "SET FOREIGN_KEY_CHECKS=1;\n";
    exit;
}

function adminDownloadCertificatePdf(PDO $pdo, int $certificateId): never
{
    $stmt = $pdo->prepare("SELECT c.id, c.certificate_number, NULL AS generated_html, c.status AS validity_status, c.certificate_type AS certificate_type_name FROM certificates c WHERE c.id=?");
    $stmt->execute([$certificateId]);
    $certificate = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$certificate) {
        http_response_code(404);
        exit('Certificate not found.');
    }
    $certificateHtml = trim((string)($certificate['generated_html'] ?? ''));
    if ($certificateHtml === '' && function_exists('portalGenerateCertificateHtml')) {
        $certificateHtml = portalGenerateCertificateHtml($pdo, $certificateId, true);
    }
    $fileName = 'certificate-' . preg_replace('/[^A-Za-z0-9-]/', '', (string)$certificate['certificate_number']) . '.html';
    $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . htmlspecialchars((string)$certificate['certificate_type_name'], ENT_QUOTES, 'UTF-8') . '</title><style>'
        . '*{box-sizing:border-box}body{margin:0;background:#e5e7eb;color:#111;font-family:Arial,sans-serif}.toolbar{position:sticky;top:0;z-index:5;display:flex;justify-content:center;gap:10px;padding:14px;background:#0f766e}.toolbar button{border:1px solid rgba(255,255,255,.5);border-radius:8px;background:#fff;padding:9px 16px;color:#0f766e;font:700 13px Arial;cursor:pointer}'
        . '.official-certificate-template{position:relative;overflow:hidden;margin:24px auto;width:min(100%,760px);min-height:1040px;background:#fff;padding:58px 68px 44px;color:#050505;font-family:Arial,Helvetica,sans-serif;line-height:1.45;box-shadow:0 18px 50px rgba(15,23,42,.18)}.cert-header{position:relative;z-index:1;display:grid;grid-template-columns:112px 1fr 112px;align-items:center;text-align:center;margin-bottom:10px}.cert-seal{width:96px;height:96px;object-fit:contain;justify-self:center}.cert-watermark{position:absolute;z-index:0;left:50%;top:285px;width:560px;height:560px;transform:translateX(-50%);object-fit:contain;opacity:.1;pointer-events:none}.cert-header-copy{font-size:11px;line-height:1.2}.cert-header-copy p{margin:0}.cert-republic{font-family:Georgia,"Times New Roman",serif;font-style:italic;font-size:13px}.cert-rule{position:relative;z-index:1;border-top:2px solid #111;border-bottom:1px solid #111;height:4px;margin:6px 0 42px}.official-certificate-template h1,.official-certificate-template h2,.official-certificate-template h3{position:relative;z-index:1;margin:5px 0;text-align:center;font-weight:900;text-transform:uppercase}.official-certificate-template h1{font-size:18px}.official-certificate-template h2{font-size:21px;font-style:italic}.official-certificate-template h3{font-size:28px;margin-bottom:2px}.cert-no{position:relative;z-index:1;text-align:center;font-family:"Courier New",monospace;font-size:10px;font-weight:700;color:#334155;margin:0 0 44px}.cert-body{position:relative;z-index:1;margin:0;font-size:12px;line-height:1.65;text-align:justify}.cert-body p{margin:0 0 18px;text-indent:34px}.cert-body .cert-greeting{text-indent:0;margin-bottom:26px;text-align:left}.cert-dates{display:flex;gap:34px;margin-top:6px;font-size:10px;text-align:left}.cert-signatures{position:relative;z-index:1;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:52px;margin-top:118px;text-align:center}.cert-signatures>div{min-height:104px;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;font-size:12px}.cert-signatures strong{border-top:1px solid #111;min-width:250px;padding-top:5px;font-weight:900;text-transform:uppercase}.certificate-signature-image{display:block;width:170px;height:58px;margin:0 auto -5px;object-fit:contain;object-position:center bottom;mix-blend-mode:multiply}.signature-line{height:58px;margin-bottom:-5px;width:170px}.official-certificate-template small,.cert-footer{display:block;color:#111;font-size:10px}.cert-footer{position:absolute;z-index:1;left:68px;right:68px;bottom:40px;display:flex;justify-content:space-between;border-top:1px solid #64748b;padding-top:6px;font-family:"Courier New",monospace;color:#0f172a}@media print{@page{size:A4 portrait;margin:0}body{background:#fff}.toolbar{display:none}.official-certificate-template{width:210mm;min-height:297mm;margin:0;box-shadow:none}}'
        . '</style></head><body><div class="toolbar"><button onclick="window.print()">Save as PDF</button></div>' . $certificateHtml . '</body></html>';
    header('Content-Type: text/html; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . strlen($html));
    echo $html;
    exit;
}

function adminExtendedAction(PDO $pdo, string $action, int $adminId): ?string
{
    $audit = static function (string $message, string $table, int $id = 0) use ($pdo, $adminId): void {
        portalAudit($pdo, $adminId, $message, $table, $id ?: null);
    };

    if ($action === 'save_resident') {
        $id = (int)($_POST['resident_id'] ?? 0);
        $genderValue = trim($_POST['gender'] ?? 'Other');
        $values = [
            trim($_POST['first_name'] ?? ''), trim($_POST['last_name'] ?? ''),
            trim($_POST['middle_name'] ?? ''), $_POST['date_of_birth'] ?? date('Y-m-d'),
            $genderValue, trim($_POST['civil_status'] ?? ''),
            trim($_POST['contact_number'] ?? ''), trim($_POST['email'] ?? ''),
            trim($_POST['address'] ?? ''), 1,
            trim($_POST['philhealth_id'] ?? ''),
        ];
        if ($id) {
            $stmt = $pdo->prepare('UPDATE residents SET first_name=?,last_name=?,middle_name=?,birthdate=?,sex=?,civil_status=?,contact_number=?,email=?,address=?,barangay_id=?,philhealth_number=?,updated_at=NOW() WHERE id=?');
            $stmt->execute([...$values, $id]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO residents (first_name,last_name,middle_name,birthdate,sex,civil_status,contact_number,email,address,barangay_id,philhealth_number) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute($values);
            $id = (int)$pdo->lastInsertId();
        }
        $audit('Saved resident profile', 'residents', $id);
        return 'Resident profile saved.';
    }

    if ($action === 'save_staff_profile') {
        $id = (int)($_POST['staff_id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE health_workers SET position_title=?,license_number=?,contact_number=?,updated_at=NOW() WHERE id=?');
        $stmt->execute([
            trim($_POST['staff_type'] ?? ''), trim($_POST['license_number'] ?? ''),
            trim($_POST['phone_number'] ?? ''), $id,
        ]);
        $audit('Updated staff profile', 'health_workers', $id);
        return 'Staff profile updated.';
    }

    if ($action === 'delete_staff') {
        $id = (int)($_POST['staff_id'] ?? 0);
        $pdo->prepare('DELETE FROM health_workers WHERE id=?')->execute([$id]);
        $audit('Deleted staff account', 'health_workers', $id);
        return 'Staff account deleted.';
    }

    if ($action === 'save_vaccination') {
        $id = (int)($_POST['vaccination_id'] ?? 0);
        $values = [
            (int)($_POST['resident_id'] ?? 0),
            trim($_POST['vaccine_name'] ?? 'General Vaccine'),
            $_POST['vaccination_date'] ?? date('Y-m-d'),
            (int)($_POST['provider_id'] ?? 0) ?: null,
            trim($_POST['batch_number'] ?? ''),
            ($_POST['next_dose_date'] ?? '') ?: null,
        ];
        if ($id) {
            $stmt = $pdo->prepare('UPDATE vaccination_records SET resident_id=?,vaccine_name=?,vaccination_date=?,health_worker_id=?,batch_number=?,next_dose_date=?,updated_at=NOW() WHERE id=?');
            $stmt->execute([...$values, $id]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO vaccination_records (resident_id,vaccine_name,vaccination_date,health_worker_id,batch_number,next_dose_date) VALUES (?,?,?,?,?,?)');
            $stmt->execute($values);
        }
        $audit('Saved vaccination record', 'vaccination_records');
        return 'Vaccination record saved.';
    }

    if ($action === 'delete_vaccination') {
        $id = (int)($_POST['vaccination_id'] ?? 0);
        $pdo->prepare('DELETE FROM vaccination_records WHERE id=?')->execute([$id]);
        $audit('Deleted vaccination record', 'vaccination_records', $id);
        return 'Vaccination record deleted.';
    }

    if ($action === 'create_pregnancy') {
        $residentId = (int)($_POST['resident_id'] ?? 0);
        $femaleCheck = $pdo->prepare("SELECT id FROM residents WHERE id = :id AND sex LIKE 'Female%' LIMIT 1");
        $femaleCheck->execute(['id' => $residentId]);
        if (!$femaleCheck->fetchColumn()) return 'Only female residents can have pregnancy records.';
        $lmpDate = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($_POST['lmp'] ?? ''));
        if (!$lmpDate) return 'Please provide a valid LMP date.';
        $edc = $lmpDate->modify('+280 days')->format('Y-m-d');
        $stmt = $pdo->prepare('INSERT INTO pregnancy_records (resident_id,last_menstrual_period,expected_delivery_date,remarks,risk_level,pregnancy_status) VALUES (?,?,?,?,?,?)');
        $stmt->execute([
            $residentId,
            $lmpDate->format('Y-m-d'),
            $edc,
            trim($_POST['risk_factors'] ?? ''),
            isset($_POST['high_risk']) ? 'High Risk' : 'Normal',
            'Pregnant'
        ]);
        $audit('Created pregnancy record', 'pregnancy_records', (int)$pdo->lastInsertId());
        return 'Pregnancy record created.';
    }

    if ($action === 'create_disease_case') {
        $stmt = $pdo->prepare('INSERT INTO disease_cases (resident_id,disease_type_id,case_date,case_status,outcome,remarks) VALUES (?,?,?,?,?,?)');
        $stmt->execute([
            (int)($_POST['resident_id'] ?? 0),
            (int)($_POST['disease_id'] ?? 1),
            $_POST['case_date'] ?? date('Y-m-d'),
            $_POST['classification'] ?? 'Suspected',
            trim($_POST['outcome'] ?? 'Active'),
            trim($_POST['symptoms'] ?? '')
        ]);
        $audit('Created disease case', 'disease_cases', (int)$pdo->lastInsertId());
        return 'Disease case recorded.';
    }

    if ($action === 'save_medicine') {
        $id = (int)($_POST['medicine_id'] ?? 0);
        $name = trim($_POST['generic_name'] ?? '');
        $qty = (int)($_POST['quantity'] ?? 0);
        $expiry = $_POST['expiry_date'] ?? date('Y-m-d', strtotime('+1 year'));
        $reorder = (int)($_POST['reorder_level'] ?? 30);
        if ($id) {
            $stmt = $pdo->prepare('UPDATE medicine_inventory SET item_name=?,quantity=?,expiration_date=?,reorder_level=?,updated_at=NOW() WHERE id=?');
            $stmt->execute([$name, $qty, $expiry, $reorder, $id]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO medicine_inventory (item_name,item_type,unit,quantity,reorder_level,expiration_date,status) VALUES (?,"Medicine","pcs",?,?,?,"Available")');
            $stmt->execute([$name, $qty, $reorder, $expiry]);
        }
        $audit('Saved medicine item', 'medicine_inventory');
        return 'Medicine item saved.';
    }

    if ($action === 'delete_medicine') {
        $id = (int)($_POST['medicine_id'] ?? 0);
        $pdo->prepare('DELETE FROM medicine_inventory WHERE id=?')->execute([$id]);
        $audit('Deleted medicine item', 'medicine_inventory', $id);
        return 'Medicine item deleted.';
    }

    if ($action === 'save_vital') {
        $stmt = $pdo->prepare('INSERT INTO vital_statistics (resident_id,barangay_id,event_type,event_date,full_name,status,remarks) VALUES (?,1,?,?,?,"Recorded",?)');
        $stmt->execute([
            (int)($_POST['mother_id'] ?? 0) ?: null,
            $_POST['vital_type'] ?? 'birth',
            $_POST['record_date'] ?? date('Y-m-d'),
            trim($_POST['person_name'] ?? ''),
            trim($_POST['cause_of_death'] ?? $_POST['location'] ?? '')
        ]);
        $audit('Saved vital statistics record', 'vital_statistics');
        return 'Vital statistics record created.';
    }

    if ($action === 'generate_doh_report') {
        $type = $_POST['report_type'] ?? 'fhsis';
        $year = (int)($_POST['year'] ?? date('Y'));
        $month = (int)($_POST['month'] ?? date('n'));
        $data = [
            'residents' => (int)$pdo->query('SELECT COUNT(*) FROM residents')->fetchColumn(),
            'consultations' => (int)$pdo->query("SELECT COUNT(*) FROM consultations WHERE YEAR(consultation_date)={$year} AND MONTH(consultation_date)={$month}")->fetchColumn(),
            'vaccinations' => (int)$pdo->query("SELECT COUNT(*) FROM vaccination_records WHERE YEAR(vaccination_date)={$year} AND MONTH(vaccination_date)={$month}")->fetchColumn(),
            'pregnancies' => (int)$pdo->query("SELECT COUNT(*) FROM pregnancy_records WHERE pregnancy_status='Pregnant'")->fetchColumn(),
        ];
        $pdo->prepare("INSERT INTO fhsis_reports (report_type,reporting_period,report_month,report_year,report_data,status,remarks) VALUES (?,?,?,?,?,'Draft',?)")
            ->execute([strtoupper($type), $month . '-' . $year, $month, $year, json_encode($data), trim($_POST['notes'] ?? '')]);
        $audit('Generated DOH report', 'fhsis_reports');
        return strtoupper($type) . ' report generated.';
    }

    if ($action === 'run_database_maintenance') {
        $tables = $pdo->query('SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) $pdo->query('ANALYZE TABLE `' . str_replace('`', '``', $table) . '`');
        $audit('Ran database table analysis', 'system');
        return count($tables) . ' database tables analyzed.';
    }

    return null;
}

function renderAdminExtendedPanel(PDO $pdo, string $tab): void
{
    echo '<style>
        .admin-tool-group { position: relative; z-index: 40; }
        .admin-tool-group > summary::-webkit-details-marker { display: none; }
        .admin-tool-group > summary::marker { display: none; }
        .admin-tool-group .admin-tool-panel {
            position: absolute; left: 0; top: calc(100% + .6rem); width: min(100%, 1200px);
            z-index: 9999; box-shadow: 0 24px 50px rgba(15, 23, 42, .18);
        }
    </style>';

    $residents = $pdo->query("SELECT id, CONCAT(first_name,' ',last_name) name FROM residents ORDER BY first_name LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
    $femaleResidents = $pdo->query("SELECT id, CONCAT(first_name,' ',last_name) name FROM residents WHERE sex LIKE 'Female%' ORDER BY first_name LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
    $staff = $pdo->query("SELECT id, CONCAT(first_name,' ',last_name) name FROM health_workers ORDER BY first_name")->fetchAll(PDO::FETCH_ASSOC);
    $select = static function (string $name, array $rows, string $placeholder): void {
        echo '<select required name="' . htmlspecialchars($name) . '" class="rounded border p-2"><option value="">' . htmlspecialchars($placeholder) . '</option>';
        foreach ($rows as $row) echo '<option value="' . (int)$row['id'] . '">' . htmlspecialchars($row['name']) . '</option>';
        echo '</select>';
    };

    $open = '<div class="admin-tool-panel mb-4 grid gap-4 rounded-2xl border border-emerald-100 bg-white/95 p-4 shadow-sm ring-1 ring-emerald-100">';
    $close = '</div>';

    if ($tab === 'residents') {
        echo $open;
        echo '<form method="post" class="grid gap-2 text-xs sm:grid-cols-4"><input type="hidden" name="action" value="save_resident"><input type="number" name="resident_id" placeholder="Existing ID (blank=new)" class="rounded border p-2"><input required name="first_name" placeholder="First name" class="rounded border p-2"><input required name="last_name" placeholder="Last name" class="rounded border p-2"><input name="middle_name" placeholder="Middle name" class="rounded border p-2"><input required type="date" name="date_of_birth" class="rounded border p-2"><select name="gender" class="rounded border p-2"><option>Female</option><option>Male</option><option>Other</option></select><input name="civil_status" placeholder="Civil status" class="rounded border p-2"><input name="contact_number" placeholder="Contact" class="rounded border p-2"><input type="email" name="email" placeholder="Email" class="rounded border p-2"><input required name="address" placeholder="Address" class="rounded border p-2"><input required name="barangay" placeholder="Barangay" class="rounded border p-2"><input name="philhealth_id" placeholder="PhilHealth ID" class="rounded border p-2"><button class="rounded bg-teal-700 p-2 font-bold text-white">Create / Update Resident</button></form>';
        echo $close;
    } elseif ($tab === 'staff') {
        echo $open;
        echo '<form method="post" class="grid gap-2 text-xs sm:grid-cols-4"><input type="hidden" name="action" value="save_staff_profile">';
        $select('staff_id', $staff, 'Select staff');
        echo '<input required name="staff_type" placeholder="Position" class="rounded border p-2"><input name="license_number" placeholder="License" class="rounded border p-2"><input name="phone_number" placeholder="Phone" class="rounded border p-2"><button class="rounded bg-emerald-700 p-2 font-bold text-white">Update Staff</button></form>';
        echo '<form method="post" onsubmit="return confirm(\'Delete this staff account?\')" class="flex gap-2 text-xs"><input type="hidden" name="action" value="delete_staff">';
        $select('staff_id', $staff, 'Staff to delete');
        echo '<button class="rounded bg-red-700 px-4 font-bold text-white">Delete Staff</button></form>' . $close;
    } elseif ($tab === 'vaccination') {
        $vaccines = $pdo->query("SELECT MIN(id) AS id, vaccine_name AS name FROM vaccination_records GROUP BY vaccine_name ORDER BY vaccine_name")->fetchAll(PDO::FETCH_ASSOC);
        echo $open . '<form method="post" class="grid gap-2 text-xs sm:grid-cols-4"><input type="hidden" name="action" value="save_vaccination"><input type="number" name="vaccination_id" placeholder="Existing ID (blank=new)" class="rounded border p-2">';
        $select('resident_id', $residents, 'Resident');
        echo '<input required name="vaccine_name" placeholder="Vaccine Name" class="rounded border p-2"><input required type="date" name="vaccination_date" class="rounded border p-2">';
        $select('provider_id', $staff, 'Provider');
        echo '<input name="batch_number" placeholder="Batch" class="rounded border p-2"><input type="date" name="next_dose_date" class="rounded border p-2"><button class="rounded bg-emerald-700 p-2 font-bold text-white">Save Vaccination</button></form>' . $close;
    } elseif ($tab === 'maternal') {
        echo $open . '<form method="post" class="grid gap-2 text-xs sm:grid-cols-3"><input type="hidden" name="action" value="create_pregnancy">';
        $select('resident_id', $femaleResidents, 'Mother/resident');
        echo '<label>LMP (start date)<input required type="date" name="lmp" id="admin-maternal-lmp" class="ml-2 rounded border p-2"></label><label>EDC (40 weeks / 9 months)<input required readonly type="date" name="edc" id="admin-maternal-edc" class="ml-2 rounded border bg-gray-50 p-2"><small class="ml-2 text-gray-500">Calculated automatically from LMP</small></label><input name="risk_factors" placeholder="Risk factors" class="rounded border p-2"><label><input type="checkbox" name="high_risk"> High risk</label><button class="rounded bg-pink-700 p-2 font-bold text-white">Create Pregnancy Case</button></form><script>(function(){const l=document.getElementById("admin-maternal-lmp"),e=document.getElementById("admin-maternal-edc");if(!l||!e)return;const update=()=>{if(!l.value){e.value="";return;}const d=new Date(l.value+"T00:00:00");d.setDate(d.getDate()+280);e.value=d.toISOString().slice(0,10);};l.addEventListener("input",update);l.addEventListener("change",update);update();})();</script>' . $close;
    } elseif ($tab === 'disease') {
        $diseases = $pdo->query("SELECT id, name FROM disease_types ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        echo $open . '<form method="post" class="grid gap-2 text-xs sm:grid-cols-4"><input type="hidden" name="action" value="create_disease_case">';
        $select('resident_id', $residents, 'Resident'); $select('disease_id', $diseases, 'Disease');
        echo '<input required type="date" name="case_date" class="rounded border p-2"><select name="classification" class="rounded border p-2"><option>Suspected</option><option>Probable</option><option>Confirmed</option></select><input name="symptoms" placeholder="Symptoms" class="rounded border p-2"><button class="rounded bg-red-700 p-2 font-bold text-white">Create Disease Case</button></form>' . $close;
    } elseif ($tab === 'medicine') {
        echo $open . '<form method="post" class="grid gap-2 text-xs sm:grid-cols-4"><input type="hidden" name="action" value="save_medicine"><input required name="generic_name" placeholder="Item Name" class="rounded border p-2"><input required type="number" name="quantity" placeholder="Quantity" class="rounded border p-2"><input required type="date" name="expiry_date" class="rounded border p-2"><input type="number" name="reorder_level" placeholder="Reorder Level" class="rounded border p-2"><button class="rounded bg-orange-700 p-2 font-bold text-white">Save Item</button></form>' . $close;
    } elseif ($tab === 'vital') {
        echo $open . '<form method="post" class="grid gap-2 text-xs sm:grid-cols-4"><input type="hidden" name="action" value="save_vital"><select name="vital_type" class="rounded border p-2"><option value="Birth">Birth</option><option value="Death">Death</option></select><input required name="person_name" placeholder="Full Name" class="rounded border p-2"><input required type="date" name="record_date" class="rounded border p-2">';
        $select('mother_id', $residents, 'Resident');
        echo '<button class="rounded bg-purple-700 p-2 font-bold text-white">Create Vital Record</button></form>' . $close;
    } elseif ($tab === 'reports') {
        echo $open . '<form method="post" class="grid gap-2 text-xs sm:grid-cols-4"><input type="hidden" name="action" value="generate_doh_report"><select name="report_type" class="rounded border p-2"><option value="FHSIS">FHSIS</option></select><input type="number" min="1" max="12" name="month" value="' . date('n') . '" class="rounded border p-2"><input type="number" name="year" value="' . date('Y') . '" class="rounded border p-2"><button class="rounded bg-purple-700 p-2 font-bold text-white">Generate DOH Report</button></form>' . $close;
    } elseif ($tab === 'system' || $tab === 'settings') {
        echo $open . '<div class="flex flex-wrap items-center justify-between gap-3 text-xs"><div><h4 class="font-bold text-gray-900">Database Maintenance &amp; SQL Backup</h4><p class="text-gray-500">Run table analysis or download full SQL database backup.</p></div><div class="flex items-center gap-2"><form method="post"><input type="hidden" name="action" value="run_database_maintenance"><button class="rounded bg-blue-700 px-4 py-2 font-bold text-white shadow-sm hover:bg-blue-800">Run Table Maintenance</button></form><a href="?export=sql_backup" class="rounded bg-emerald-700 px-4 py-2 font-bold text-white shadow-sm hover:bg-emerald-800">Download SQL Backup</a></div></div>' . $close;
    }
}
