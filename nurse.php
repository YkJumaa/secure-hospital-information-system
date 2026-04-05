<?php
require_once 'session.php';
require_once 'auth.php';
require_once 'audit.php';
requireRole(['Nurse', 'Admin']);

$user = getCurrentUser();

// Handle vitals submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_vitals') {
    $patient_id = $_POST['patient_id'];
    $bp = $_POST['blood_pressure'] ?? '';
    $hr = $_POST['heart_rate'] ? (int)$_POST['heart_rate'] : null;
    $temp = $_POST['temperature'] ? (float)$_POST['temperature'] : null;
    $rr = $_POST['respiratory_rate'] ? (int)$_POST['respiratory_rate'] : null;
    
    $stmt = $conn->prepare("INSERT INTO vitals (patient_id, nurse_id, blood_pressure, heart_rate, temperature, respiratory_rate) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iisidd", $patient_id, $user['id'], $bp, $hr, $temp, $rr);
    if ($stmt->execute()) {
        log_audit('ADD_VITALS', "Added vitals for patient ID: $patient_id");
        $message = "Vitals recorded.";
    } else {
        $error = "Failed to record vitals.";
    }
}

// Search and patient selection
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$patientQuery = "SELECT p.id, u.full_name FROM patients p JOIN users u ON p.user_id = u.id";
if (!empty($search)) {
    $searchTerm = '%' . $conn->real_escape_string($search) . '%';
    $patientQuery .= " WHERE u.full_name LIKE '$searchTerm'";
}
$patientQuery .= " ORDER BY u.full_name";
$patients = $conn->query($patientQuery);

$selected_patient_id = $_GET['patient_id'] ?? null;
$patient_info = null;
$vitals_history = [];
$records = [];
$patient_profile = null;

if ($selected_patient_id) {
    // Fetch patient profile (allergies & chronic conditions)
    $profile_stmt = $conn->prepare("SELECT allergies, chronic_conditions FROM patients WHERE id = ?");
    $profile_stmt->bind_param("i", $selected_patient_id);
    $profile_stmt->execute();
    $profile_result = $profile_stmt->get_result();
    $patient_profile = $profile_result->fetch_assoc();

    // Get patient info (demographics)
    $stmt = $conn->prepare("SELECT u.full_name, u.email, p.date_of_birth, p.gender FROM patients p JOIN users u ON p.user_id = u.id WHERE p.id = ?");
    $stmt->bind_param("i", $selected_patient_id);
    $stmt->execute();
    $patient_info = $stmt->get_result()->fetch_assoc();
    
    // Get vitals history
    $vitals = $conn->prepare("SELECT * FROM vitals WHERE patient_id = ? ORDER BY recorded_at DESC");
    $vitals->bind_param("i", $selected_patient_id);
    $vitals->execute();
    $vitals_history = $vitals->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Get medical records (read-only)
    $rec = $conn->prepare("SELECT mr.*, u.full_name as doctor_name FROM medical_records mr 
                           JOIN users u ON mr.doctor_id = u.id WHERE mr.patient_id = ? ORDER BY mr.record_date DESC");
    $rec->bind_param("i", $selected_patient_id);
    $rec->execute();
    $records = $rec->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nurse Dashboard</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="container">
        <div class="dashboard-header">
            <h1>Nurse Dashboard</h1>
            <div class="user-info">
                <span><?php echo htmlspecialchars($user['full_name']); ?> (Nurse)</span>
                <button class="logout-btn" id="logout-btn">Logout</button>
            </div>
        </div>
        <div class="dashboard-content">
            <?php if (isset($message)): ?><div class="message success"><?php echo $message; ?></div><?php endif; ?>
            <?php if (isset($error)): ?><div class="message error"><?php echo $error; ?></div><?php endif; ?>
            
            <div class="card">
                <h3>Find Patient</h3>
                <form method="GET" action="nurse.php">
                    <div style="display: flex; gap: 10px; margin-bottom: 15px;">
                        <input type="text" name="search" placeholder="Search by patient name" value="<?php echo htmlspecialchars($search); ?>" style="flex: 1; padding: 8px;">
                        <button type="submit" class="btn">Search</button>
                        <?php if (!empty($search)): ?>
                            <a href="nurse.php" class="btn btn-danger">Clear</a>
                        <?php endif; ?>
                    </div>
                </form>

                <form method="GET" action="nurse.php">
                    <?php if (!empty($search)): ?>
                        <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                    <?php endif; ?>
                    <select name="patient_id" onchange="this.form.submit()">
                        <option value="">-- Choose Patient --</option>
                        <?php while ($patient = $patients->fetch_assoc()): ?>
                            <option value="<?php echo $patient['id']; ?>" <?php echo ($selected_patient_id == $patient['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($patient['full_name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </form>
            </div>
            
            <?php if ($selected_patient_id && $patient_info): ?>
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

                <div class="card">
                    <h3>Patient: <?php echo htmlspecialchars($patient_info['full_name']); ?></h3>
                    <p>DOB: <?php echo $patient_info['date_of_birth']; ?>, Gender: <?php echo $patient_info['gender']; ?></p>
                    
                    <h4>Record Vitals</h4>
                    <form method="POST" action="nurse.php?patient_id=<?php echo $selected_patient_id; ?>&search=<?php echo urlencode($search); ?>">
                        <input type="hidden" name="action" value="add_vitals">
                        <input type="hidden" name="patient_id" value="<?php echo $selected_patient_id; ?>">
                        <div class="form-group">
                            <label>Blood Pressure:</label>
                            <input type="text" name="blood_pressure" placeholder="e.g., 120/80">
                        </div>
                        <div class="form-group">
                            <label>Heart Rate (bpm):</label>
                            <input type="number" name="heart_rate">
                        </div>
                        <div class="form-group">
                            <label>Temperature (°C):</label>
                            <input type="number" step="0.1" name="temperature">
                        </div>
                        <div class="form-group">
                            <label>Respiratory Rate:</label>
                            <input type="number" name="respiratory_rate">
                        </div>
                        <button type="submit" class="btn">Record Vitals</button>
                    </form>
                </div>
                
                <div class="card">
                    <h4>Vitals History</h4>
                    <?php if ($vitals_history): ?>
                         <table>
                            <thead>
                                <tr><th>Time</th><th>BP</th><th>HR</th><th>Temp</th><th>RR</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($vitals_history as $v): ?>
                                <tr>
                                    <td><?php echo $v['recorded_at']; ?></td>
                                    <td><?php echo $v['blood_pressure']; ?></td>
                                    <td><?php echo $v['heart_rate']; ?></td>
                                    <td><?php echo $v['temperature']; ?></td>
                                    <td><?php echo $v['respiratory_rate']; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p>No vitals recorded.</p>
                    <?php endif; ?>
                </div>
                
                <div class="card">
                    <h4>Medical Records (Read-Only)</h4>
                    <?php if ($records): ?>
                        <?php foreach ($records as $rec): ?>
                            <div style="border:1px solid #ddd; padding:10px; margin-bottom:5px;">
                                <p><strong><?php echo $rec['record_date']; ?> by Dr. <?php echo $rec['doctor_name']; ?></strong></p>
                                <p>Diagnosis: <?php echo nl2br(htmlspecialchars($rec['diagnosis'])); ?></p>
                                <p>Notes: <?php echo nl2br(htmlspecialchars($rec['notes'])); ?></p>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p>No medical records.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <script src="assets/app.js"></script>
</body>
</html>