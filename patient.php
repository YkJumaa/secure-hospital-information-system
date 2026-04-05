<?php
require_once 'session.php';
require_once 'auth.php';
require_once 'audit.php';
requireRole('Patient');

$user = getCurrentUser();
$patient_id = getPatientIdFromUserId($user['id']);

$records = [];
$profile = null;
if ($patient_id) {
    // Fetch patient profile (allergies & chronic conditions)
    $profile_stmt = $conn->prepare("SELECT allergies, chronic_conditions FROM patients WHERE id = ?");
    $profile_stmt->bind_param("i", $patient_id);
    $profile_stmt->execute();
    $profile_result = $profile_stmt->get_result();
    $profile = $profile_result->fetch_assoc();

    // Fetch medical records
    $stmt = $conn->prepare("SELECT mr.*, u.full_name as doctor_name FROM medical_records mr 
                           JOIN users u ON mr.doctor_id = u.id WHERE mr.patient_id = ? ORDER BY mr.record_date DESC");
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    $records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    foreach ($records as &$record) {
        $pres = $conn->prepare("SELECT * FROM prescriptions WHERE record_id = ?");
        $pres->bind_param("i", $record['id']);
        $pres->execute();
        $record['prescriptions'] = $pres->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

$appointments = [];
if ($patient_id) {
    $stmt = $conn->prepare("SELECT a.*, u.full_name as doctor_name FROM appointments a 
                           JOIN users u ON a.doctor_id = u.id WHERE a.patient_id = ? ORDER BY a.appointment_date DESC, a.appointment_time DESC");
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    $appointments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Dashboard</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="container">
        <div class="dashboard-header">
            <h1>Patient Dashboard</h1>
            <div class="user-info">
                <span><?php echo htmlspecialchars($user['full_name']); ?> (Patient)</span>
                <button class="logout-btn" id="logout-btn">Logout</button>
            </div>
        </div>
        <div class="dashboard-content">
            <!-- Health Profile Card -->
            <div class="card">
                <h3>My Health Profile</h3>
                <?php if ($profile): ?>
                    <p><strong>Allergies:</strong> <?php echo nl2br(htmlspecialchars($profile['allergies'] ?: 'None recorded')); ?></p>
                    <p><strong>Chronic Conditions:</strong> <?php echo nl2br(htmlspecialchars($profile['chronic_conditions'] ?: 'None recorded')); ?></p>
                <?php else: ?>
                    <p>No profile information available.</p>
                <?php endif; ?>
            </div>

            <div class="card">
                <h3>My Medical Records</h3>
                <?php if ($records): ?>
                    <?php foreach ($records as $record): ?>
                        <div style="border:1px solid #ddd; padding:10px; margin-bottom:10px;">
                            <p><strong>Date:</strong> <?php echo $record['record_date']; ?></p>
                            <p><strong>Doctor:</strong> <?php echo $record['doctor_name']; ?></p>
                            <p><strong>Diagnosis:</strong> <?php echo nl2br(htmlspecialchars($record['diagnosis'])); ?></p>
                            <p><strong>Notes:</strong> <?php echo nl2br(htmlspecialchars($record['notes'])); ?></p>
                            <?php if ($record['prescriptions']): ?>
                                <h4>Prescriptions</h4>
                                <ul>
                                    <?php foreach ($record['prescriptions'] as $pres): ?>
                                        <li><?php echo $pres['medication']; ?> - <?php echo $pres['dosage']; ?> (<?php echo $pres['instructions']; ?>)</li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p>No medical records found.</p>
                <?php endif; ?>
            </div>
            
            <div class="card">
                <h3>My Appointments</h3>
                <?php if ($appointments): ?>
                    <table>
                        <tr><th>Date</th><th>Time</th><th>Doctor</th><th>Status</th></tr>
                        <?php foreach ($appointments as $apt): ?>
                            <tr>
                                <td><?php echo $apt['appointment_date']; ?></td>
                                <td><?php echo $apt['appointment_time']; ?></td>
                                <td><?php echo $apt['doctor_name']; ?></td>
                                <td><?php echo $apt['status']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php else: ?>
                    <p>No appointments scheduled.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script src="assets/app.js"></script>
</body>
</html>