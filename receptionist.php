<?php
require_once 'session.php';
require_once 'auth.php';
require_once 'audit.php';
requireRole(['Receptionist', 'Admin']);

$user = getCurrentUser();
$message = '';
$error = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // 1. Register new patient
    if ($action === 'register_patient') {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        $full_name = $_POST['full_name'] ?? '';
        $email = $_POST['email'] ?? '';
        $dob = $_POST['dob'] ?? '';
        $gender = $_POST['gender'] ?? '';
        $address = $_POST['address'] ?? '';
        $phone = $_POST['phone'] ?? '';
        $emergency_contact = $_POST['emergency_contact'] ?? '';
        $allergies = $_POST['allergies'] ?? '';
        $chronic_conditions = $_POST['chronic_conditions'] ?? '';

        if (empty($username) || empty($password) || empty($full_name) || empty($email) || empty($dob) || empty($gender)) {
            $error = 'All fields marked * are required';
        } else {
            // Check existing user
            $check = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $check->bind_param("ss", $username, $email);
            $check->execute();
            $check->store_result();
            if ($check->num_rows > 0) {
                $error = 'Username or email already exists';
            } else {
                $conn->begin_transaction();
                try {
                    $role_id = 5; // Patient
                    $password_hash = password_hash($password, PASSWORD_DEFAULT);

                    $stmt = $conn->prepare("INSERT INTO users (username, password_hash, role_id, full_name, email) VALUES (?, ?, ?, ?, ?)");
                    $stmt->bind_param("ssiss", $username, $password_hash, $role_id, $full_name, $email);
                    $stmt->execute();
                    $user_id = $conn->insert_id;

                    $stmt2 = $conn->prepare("INSERT INTO patients (user_id, date_of_birth, gender, address, phone, emergency_contact, allergies, chronic_conditions) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt2->bind_param("isssssss", $user_id, $dob, $gender, $address, $phone, $emergency_contact, $allergies, $chronic_conditions);
                    $stmt2->execute();

                    $conn->commit();
                    log_audit('REGISTER_PATIENT', "Receptionist registered patient: $username");
                    $message = "Patient registered successfully.";
                } catch (Exception $e) {
                    $conn->rollback();
                    $error = "Registration failed: " . $e->getMessage();
                }
            }
        }
    }

    // 2. Schedule new appointment
    elseif ($action === 'schedule_appointment') {
        $patient_id = $_POST['patient_id'];
        $doctor_id = $_POST['doctor_id'];
        $date = $_POST['date'];
        $time = $_POST['time'];

        $stmt = $conn->prepare("INSERT INTO appointments (patient_id, doctor_id, appointment_date, appointment_time, created_by) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("iissi", $patient_id, $doctor_id, $date, $time, $user['id']);
        if ($stmt->execute()) {
            log_audit('SCHEDULE_APPOINTMENT', "Appointment scheduled for patient ID $patient_id with doctor ID $doctor_id");
            $message = "Appointment scheduled.";
        } else {
            $error = "Failed to schedule.";
        }
    }

    // 3. Edit patient demographics
    elseif ($action === 'edit_patient') {
        $patient_id = $_POST['patient_id'];
        $address = $_POST['address'] ?? '';
        $phone = $_POST['phone'] ?? '';
        $emergency_contact = $_POST['emergency_contact'] ?? '';
        $allergies = $_POST['allergies'] ?? '';
        $chronic_conditions = $_POST['chronic_conditions'] ?? '';

        $stmt = $conn->prepare("UPDATE patients SET address = ?, phone = ?, emergency_contact = ?, allergies = ?, chronic_conditions = ? WHERE id = ?");
        $stmt->bind_param("sssssi", $address, $phone, $emergency_contact, $allergies, $chronic_conditions, $patient_id);
        if ($stmt->execute()) {
            log_audit('EDIT_PATIENT_DEMOGRAPHICS', "Updated demographics for patient ID: $patient_id");
            $message = "Patient information updated.";
        } else {
            $error = "Update failed.";
        }
    }

    // 4. Check-in appointment
    elseif ($action === 'checkin') {
        $appointment_id = $_POST['appointment_id'];
        $stmt = $conn->prepare("UPDATE appointments SET status = 'Checked-in' WHERE id = ?");
        $stmt->bind_param("i", $appointment_id);
        if ($stmt->execute()) {
            log_audit('CHECK_IN', "Checked in appointment ID: $appointment_id");
            $message = "Patient checked in.";
        } else {
            $error = "Check-in failed.";
        }
    }

    // 5. Cancel appointment
    elseif ($action === 'cancel') {
        $appointment_id = $_POST['appointment_id'];
        $stmt = $conn->prepare("UPDATE appointments SET status = 'Cancelled' WHERE id = ?");
        $stmt->bind_param("i", $appointment_id);
        if ($stmt->execute()) {
            log_audit('CANCEL_APPOINTMENT', "Cancelled appointment ID: $appointment_id");
            $message = "Appointment cancelled.";
        } else {
            $error = "Cancellation failed.";
        }
    }

    // 6. Reschedule appointment
    elseif ($action === 'reschedule') {
        $appointment_id = $_POST['appointment_id'];
        $new_date = $_POST['new_date'];
        $new_time = $_POST['new_time'];

        $stmt = $conn->prepare("UPDATE appointments SET appointment_date = ?, appointment_time = ?, status = 'Scheduled' WHERE id = ?");
        $stmt->bind_param("ssi", $new_date, $new_time, $appointment_id);
        if ($stmt->execute()) {
            log_audit('RESCHEDULE_APPOINTMENT', "Rescheduled appointment ID: $appointment_id to $new_date $new_time");
            $message = "Appointment rescheduled.";
        } else {
            $error = "Reschedule failed.";
        }
    }
}

// Fetch lists for dropdowns
$patients = $conn->query("SELECT p.id, u.full_name FROM patients p JOIN users u ON p.user_id = u.id ORDER BY u.full_name");
$doctors = $conn->query("SELECT u.id, u.full_name FROM users u WHERE u.role_id = 2 ORDER BY u.full_name"); // role_id 2 = Doctor

// Determine which appointment view to show (today or upcoming)
$appointment_view = $_GET['view'] ?? 'today';
if ($appointment_view === 'today') {
    $appointments = $conn->query("SELECT a.*, p.full_name as patient_name, d.full_name as doctor_name 
                                   FROM appointments a
                                   JOIN patients pat ON a.patient_id = pat.id
                                   JOIN users p ON pat.user_id = p.id
                                   JOIN users d ON a.doctor_id = d.id
                                   WHERE a.appointment_date = CURDATE()
                                   ORDER BY a.appointment_time");
} else {
    // next 7 days
    $appointments = $conn->query("SELECT a.*, p.full_name as patient_name, d.full_name as doctor_name 
                                   FROM appointments a
                                   JOIN patients pat ON a.patient_id = pat.id
                                   JOIN users p ON pat.user_id = p.id
                                   JOIN users d ON a.doctor_id = d.id
                                   WHERE a.appointment_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                                   ORDER BY a.appointment_date, a.appointment_time");
}

// For editing demographics, we need a selected patient
$selected_patient_id = $_GET['edit_patient'] ?? null;
$patient_details = null;
if ($selected_patient_id) {
    $stmt = $conn->prepare("SELECT p.*, u.full_name, u.email FROM patients p JOIN users u ON p.user_id = u.id WHERE p.id = ?");
    $stmt->bind_param("i", $selected_patient_id);
    $stmt->execute();
    $patient_details = $stmt->get_result()->fetch_assoc();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receptionist Dashboard</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .appointment-actions form { display: inline; }
        .appointment-actions button { margin: 2px; }
        .view-toggle { margin-bottom: 15px; }
        .view-toggle a { margin-right: 10px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="dashboard-header">
            <h1>Receptionist Dashboard</h1>
            <div class="user-info">
                <span><?php echo htmlspecialchars($user['full_name']); ?> (Receptionist)</span>
                <button class="logout-btn" id="logout-btn">Logout</button>
            </div>
        </div>
        <div class="dashboard-content">
            <?php if ($message): ?><div class="message success"><?php echo $message; ?></div><?php endif; ?>
            <?php if ($error): ?><div class="message error"><?php echo $error; ?></div><?php endif; ?>

            <div class="card-grid">
                <!-- Register New Patient -->
                <div class="card">
                    <h3>Register New Patient</h3>
                    <form method="POST">
                        <input type="hidden" name="action" value="register_patient">
                        <div class="form-group">
                            <label>Username *</label>
                            <input type="text" name="username" required>
                        </div>
                        <div class="form-group">
                            <label>Password *</label>
                            <input type="password" name="password" required>
                        </div>
                        <div class="form-group">
                            <label>Full Name *</label>
                            <input type="text" name="full_name" required>
                        </div>
                        <div class="form-group">
                            <label>Email *</label>
                            <input type="email" name="email" required>
                        </div>
                        <div class="form-group">
                            <label>Date of Birth *</label>
                            <input type="date" name="dob" required>
                        </div>
                        <div class="form-group">
                            <label>Gender *</label>
                            <select name="gender" required>
                                <option value="">Select</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Address</label>
                            <textarea name="address"></textarea>
                        </div>
                        <div class="form-group">
                            <label>Phone</label>
                            <input type="text" name="phone">
                        </div>
                        <div class="form-group">
                            <label>Emergency Contact</label>
                            <input type="text" name="emergency_contact">
                        </div>
                        <!-- New fields -->
                        <div class="form-group">
                            <label>Allergies (e.g., penicillin, peanuts)</label>
                            <textarea name="allergies" rows="2"></textarea>
                        </div>
                        <div class="form-group">
                            <label>Chronic Conditions (e.g., diabetes, hypertension)</label>
                            <textarea name="chronic_conditions" rows="2"></textarea>
                        </div>
                        <button type="submit" class="btn">Register Patient</button>
                    </form>
                </div>

                <!-- Schedule Appointment -->
                <div class="card">
                    <h3>Schedule Appointment</h3>
                    <form method="POST">
                        <input type="hidden" name="action" value="schedule_appointment">
                        <div class="form-group">
                            <label>Patient:</label>
                            <select name="patient_id" required>
                                <option value="">Select Patient</option>
                                <?php while ($p = $patients->fetch_assoc()): ?>
                                    <option value="<?php echo $p['id']; ?>"><?php echo $p['full_name']; ?></option>
                                <?php endwhile; ?>
                                <?php mysqli_data_seek($patients, 0); // reset pointer ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Doctor:</label>
                            <select name="doctor_id" required>
                                <option value="">Select Doctor</option>
                                <?php while ($d = $doctors->fetch_assoc()): ?>
                                    <option value="<?php echo $d['id']; ?>"><?php echo $d['full_name']; ?></option>
                                <?php endwhile; ?>
                                <?php mysqli_data_seek($doctors, 0); ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Date:</label>
                            <input type="date" name="date" required>
                        </div>
                        <div class="form-group">
                            <label>Time:</label>
                            <input type="time" name="time" required>
                        </div>
                        <button type="submit" class="btn">Schedule</button>
                    </form>
                </div>

                <!-- Edit Patient Demographics -->
                <div class="card">
                    <h3>Edit Patient Demographics</h3>
                    <form method="GET">
                        <div class="form-group">
                            <label>Select Patient:</label>
                            <select name="edit_patient" onchange="this.form.submit()">
                                <option value="">-- Choose Patient --</option>
                                <?php while ($p = $patients->fetch_assoc()): ?>
                                    <option value="<?php echo $p['id']; ?>" <?php echo ($selected_patient_id == $p['id']) ? 'selected' : ''; ?>>
                                        <?php echo $p['full_name']; ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </form>
                    <?php if ($patient_details): ?>
                        <form method="POST">
                            <input type="hidden" name="action" value="edit_patient">
                            <input type="hidden" name="patient_id" value="<?php echo $patient_details['id']; ?>">
                            <div class="form-group">
                                <label>Full Name (read-only)</label>
                                <input type="text" value="<?php echo htmlspecialchars($patient_details['full_name']); ?>" readonly>
                            </div>
                            <div class="form-group">
                                <label>Email (read-only)</label>
                                <input type="email" value="<?php echo htmlspecialchars($patient_details['email']); ?>" readonly>
                            </div>
                            <div class="form-group">
                                <label>Address</label>
                                <textarea name="address"><?php echo htmlspecialchars($patient_details['address']); ?></textarea>
                            </div>
                            <div class="form-group">
                                <label>Phone</label>
                                <input type="text" name="phone" value="<?php echo htmlspecialchars($patient_details['phone']); ?>">
                            </div>
                            <div class="form-group">
                                <label>Emergency Contact</label>
                                <input type="text" name="emergency_contact" value="<?php echo htmlspecialchars($patient_details['emergency_contact']); ?>">
                            </div>
                            <!-- New fields -->
                            <div class="form-group">
                                <label>Allergies</label>
                                <textarea name="allergies"><?php echo htmlspecialchars($patient_details['allergies']); ?></textarea>
                            </div>
                            <div class="form-group">
                                <label>Chronic Conditions</label>
                                <textarea name="chronic_conditions"><?php echo htmlspecialchars($patient_details['chronic_conditions']); ?></textarea>
                            </div>
                            <button type="submit" class="btn">Update Demographics</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Appointments Section -->
            <div class="card">
                <h3>Appointments</h3>
                <div class="view-toggle">
                    <a href="?view=today" class="btn <?php echo $appointment_view == 'today' ? 'active' : ''; ?>">Today</a>
                    <a href="?view=upcoming" class="btn <?php echo $appointment_view == 'upcoming' ? 'active' : ''; ?>">Next 7 Days</a>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Patient</th>
                            <th>Doctor</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($apt = $appointments->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo $apt['appointment_date']; ?></td>
                            <td><?php echo $apt['appointment_time']; ?></td>
                            <td><?php echo $apt['patient_name']; ?></td>
                            <td><?php echo $apt['doctor_name']; ?></td>
                            <td><?php echo $apt['status']; ?></td>
                            <td class="appointment-actions">
                                <?php if ($apt['status'] != 'Checked-in' && $apt['status'] != 'Cancelled' && $apt['status'] != 'Completed'): ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="checkin">
                                        <input type="hidden" name="appointment_id" value="<?php echo $apt['id']; ?>">
                                        <button type="submit" class="btn" title="Check In">✅</button>
                                    </form>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="cancel">
                                        <input type="hidden" name="appointment_id" value="<?php echo $apt['id']; ?>">
                                        <button type="submit" class="btn btn-danger" title="Cancel" onclick="return confirm('Cancel this appointment?');">❌</button>
                                    </form>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="reschedule">
                                        <input type="hidden" name="appointment_id" value="<?php echo $apt['id']; ?>">
                                        <input type="date" name="new_date" value="<?php echo $apt['appointment_date']; ?>" required style="width:110px;">
                                        <input type="time" name="new_time" value="<?php echo $apt['appointment_time']; ?>" required style="width:80px;">
                                        <button type="submit" class="btn" title="Reschedule">🔄</button>
                                    </form>
                                <?php else: ?>
                                    <em>No actions</em>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <script src="assets/app.js"></script>
</body>
</html>