<?php
require_once 'session.php';
require_once 'auth.php';
require_once 'audit.php';
requireRole(['Doctor', 'Admin']);

$user = getCurrentUser();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];

        // Add new medical record
        if ($action === 'add_record') {
            $patient_id = $_POST['patient_id'];
            $diagnosis = $_POST['diagnosis'] ?? '';
            $notes = $_POST['notes'] ?? '';

            $stmt = $conn->prepare("INSERT INTO medical_records (patient_id, doctor_id, diagnosis, notes) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("iiss", $patient_id, $user['id'], $diagnosis, $notes);
            if ($stmt->execute()) {
                log_audit('ADD_RECORD', "Added new medical record for patient ID: $patient_id");
                $message = "New medical record added successfully.";
            } else {
                $error = "Failed to add record.";
            }
        }

        // Update existing record
        if ($action === 'update_record' && isset($_POST['record_id'])) {
            $record_id = $_POST['record_id'];
            $diagnosis = $_POST['diagnosis'] ?? '';
            $notes = $_POST['notes'] ?? '';

            $stmt = $conn->prepare("UPDATE medical_records SET diagnosis = ?, notes = ? WHERE id = ?");
            $stmt->bind_param("ssi", $diagnosis, $notes, $record_id);
            if ($stmt->execute()) {
                log_audit('UPDATE_RECORD', "Updated medical record ID: $record_id");
                $message = "Record updated successfully.";
            } else {
                $error = "Update failed.";
            }
        }

        // Add prescription
        if ($action === 'add_prescription' && isset($_POST['record_id'])) {
            $record_id = $_POST['record_id'];
            $medication = $_POST['medication'] ?? '';
            $dosage = $_POST['dosage'] ?? '';
            $instructions = $_POST['instructions'] ?? '';

            $stmt = $conn->prepare("INSERT INTO prescriptions (record_id, medication, dosage, instructions) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("isss", $record_id, $medication, $dosage, $instructions);
            if ($stmt->execute()) {
                log_audit('ADD_PRESCRIPTION', "Added prescription to record ID: $record_id");
                $message = "Prescription added.";
            } else {
                $error = "Failed to add prescription.";
            }
        }
    }
}

// Search and patient selection
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$patientQuery = "SELECT p.id, u.full_name, u.email FROM patients p JOIN users u ON p.user_id = u.id";
if (!empty($search)) {
    $searchTerm = '%' . $conn->real_escape_string($search) . '%';
    $patientQuery .= " WHERE u.full_name LIKE '$searchTerm' OR u.email LIKE '$searchTerm'";
}
$patientQuery .= " ORDER BY u.full_name";
$patients = $conn->query($patientQuery);

$selected_patient_id = $_GET['patient_id'] ?? null;
$records = [];
$vitals = [];
$patient_profile = null;

if ($selected_patient_id) {
    // Fetch patient profile (allergies & chronic conditions)
    $profile_stmt = $conn->prepare("SELECT allergies, chronic_conditions FROM patients WHERE id = ?");
    $profile_stmt->bind_param("i", $selected_patient_id);
    $profile_stmt->execute();
    $profile_result = $profile_stmt->get_result();
    $patient_profile = $profile_result->fetch_assoc();

    // Fetch medical records
    $stmt = $conn->prepare("SELECT mr.*, u.full_name as doctor_name FROM medical_records mr 
                           JOIN users u ON mr.doctor_id = u.id WHERE mr.patient_id = ? ORDER BY mr.record_date DESC");
    $stmt->bind_param("i", $selected_patient_id);
    $stmt->execute();
    $records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($records as &$record) {
        $pres = $conn->prepare("SELECT * FROM prescriptions WHERE record_id = ?");
        $pres->bind_param("i", $record['id']);
        $pres->execute();
        $record['prescriptions'] = $pres->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    // Fetch vitals
    $vitals_stmt = $conn->prepare("SELECT v.*, u.full_name as nurse_name FROM vitals v 
                                   JOIN users u ON v.nurse_id = u.id WHERE v.patient_id = ? ORDER BY v.recorded_at DESC");
    $vitals_stmt->bind_param("i", $selected_patient_id);
    $vitals_stmt->execute();
    $vitals = $vitals_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doctor Dashboard</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="container">
        <div class="dashboard-header">
            <h1>Doctor Dashboard</h1>
            <div class="user-info">
                <span><?php echo htmlspecialchars($user['full_name']); ?> (Doctor)</span>
                <button class="logout-btn" id="logout-btn">Logout</button>
            </div>
        </div>
        <div class="dashboard-content">
            <?php if (isset($message)): ?><div class="message success"><?php echo $message; ?></div><?php endif; ?>
            <?php if (isset($error)): ?><div class="message error"><?php echo $error; ?></div><?php endif; ?>

            <!-- Patient Search & Selection -->
            <div class="card">
                <h3>Find Patient</h3>
                <form method="GET" action="doctor.php">
                    <div style="display: flex; gap: 10px; margin-bottom: 15px;">
                        <input type="text" name="search" placeholder="Search by name or email" value="<?php echo htmlspecialchars($search); ?>" style="flex: 1; padding: 8px;">
                        <button type="submit" class="btn">Search</button>
                        <?php if (!empty($search)): ?>
                            <a href="doctor.php" class="btn btn-danger">Clear</a>
                        <?php endif; ?>
                    </div>
                </form>

                <form method="GET" action="doctor.php">
                    <?php if (!empty($search)): ?>
                        <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                    <?php endif; ?>
                    <select name="patient_id" onchange="this.form.submit()">
                        <option value="">-- Choose Patient --</option>
                        <?php while ($patient = $patients->fetch_assoc()): ?>
                            <option value="<?php echo $patient['id']; ?>" <?php echo ($selected_patient_id == $patient['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($patient['full_name']); ?> (<?php echo $patient['email']; ?>)
                            </option>
                        <?php endwhile; ?>
                    </select>
                </form>
            </div>

            <?php if ($selected_patient_id): ?>
                <!-- Patient Profile Card -->
                <div class="card">
                    <h3>Patient Profile</h3>
                    <?php if ($patient_profile): ?>
                        <p><strong>Allergies:</strong> <?php echo nl2br(htmlspecialchars($patient_profile['allergies'] ?: 'None recorded')); ?></p>
                        <p><strong>Chronic Conditions:</strong> <?php echo nl2br(htmlspecialchars($patient_profile['chronic_conditions'] ?: 'None recorded')); ?></p>
                    <?php else: ?>
                        <p>No profile information available.</p>
                    <?php endif; ?>
                </div>

                <!-- Vitals Section (Read-Only) -->
                <div class="card">
                    <h3>Patient Vitals (Recorded by Nurses)</h3>
                    <?php if ($vitals): ?>
                        <table>
                            <thead>
                                <tr><th>Date/Time</th><th>Nurse</th><th>BP</th><th>HR</th><th>Temp (°C)</th><th>RR</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($vitals as $v): ?>
                                <tr>
                                    <td><?php echo $v['recorded_at']; ?></td>
                                    <td><?php echo htmlspecialchars($v['nurse_name']); ?></td>
                                    <td><?php echo $v['blood_pressure']; ?></td>
                                    <td><?php echo $v['heart_rate']; ?></td>
                                    <td><?php echo $v['temperature']; ?></td>
                                    <td><?php echo $v['respiratory_rate']; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p>No vitals recorded for this patient.</p>
                    <?php endif; ?>
                </div>

                <!-- Add New Medical Record -->
                <div class="card">
                    <h3>Add New Medical Record</h3>
                    <form method="POST" action="doctor.php?patient_id=<?php echo $selected_patient_id; ?>&search=<?php echo urlencode($search); ?>">
                        <input type="hidden" name="action" value="add_record">
                        <input type="hidden" name="patient_id" value="<?php echo $selected_patient_id; ?>">
                        <div class="form-group">
                            <label>Diagnosis:</label>
                            <textarea name="diagnosis" required></textarea>
                        </div>
                        <div class="form-group">
                            <label>Notes:</label>
                            <textarea name="notes"></textarea>
                        </div>
                        <button type="submit" class="btn">Add Record</button>
                    </form>
                </div>

                <!-- Medical Records & Prescriptions -->
                <?php if ($records): ?>
                    <?php foreach ($records as $record): ?>
                        <div class="card">
                            <h4>Record from <?php echo $record['record_date']; ?> by Dr. <?php echo $record['doctor_name']; ?></h4>
                            <form method="POST" action="doctor.php?patient_id=<?php echo $selected_patient_id; ?>&search=<?php echo urlencode($search); ?>">
                                <input type="hidden" name="action" value="update_record">
                                <input type="hidden" name="record_id" value="<?php echo $record['id']; ?>">
                                <div class="form-group">
                                    <label>Diagnosis:</label>
                                    <textarea name="diagnosis"><?php echo htmlspecialchars($record['diagnosis']); ?></textarea>
                                </div>
                                <div class="form-group">
                                    <label>Notes:</label>
                                    <textarea name="notes"><?php echo htmlspecialchars($record['notes']); ?></textarea>
                                </div>
                                <button type="submit" class="btn">Update Record</button>
                            </form>

                            <h5>Prescriptions</h5>
                            <?php if ($record['prescriptions']): ?>
                                <ul>
                                    <?php foreach ($record['prescriptions'] as $pres): ?>
                                        <li><?php echo $pres['medication']; ?> - <?php echo $pres['dosage']; ?> (<?php echo $pres['instructions']; ?>)</li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <p>No prescriptions.</p>
                            <?php endif; ?>

                            <h5>Add Prescription</h5>
                            <form method="POST" action="doctor.php?patient_id=<?php echo $selected_patient_id; ?>&search=<?php echo urlencode($search); ?>">
                                <input type="hidden" name="action" value="add_prescription">
                                <input type="hidden" name="record_id" value="<?php echo $record['id']; ?>">
                                <div class="form-group">
                                    <label>Medication:</label>
                                    <input type="text" name="medication" required>
                                </div>
                                <div class="form-group">
                                    <label>Dosage:</label>
                                    <input type="text" name="dosage">
                                </div>
                                <div class="form-group">
                                    <label>Instructions:</label>
                                    <textarea name="instructions"></textarea>
                                </div>
                                <button type="submit" class="btn">Add Prescription</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p>No medical records yet. Use the form above to add the first record.</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <script src="assets/app.js"></script>
</body>
</html>