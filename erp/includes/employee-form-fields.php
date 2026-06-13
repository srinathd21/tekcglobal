<?php
$departmentsQ = mysqli_query($conn, "SELECT id, department_name FROM master_departments WHERE is_active = 1 ORDER BY department_name ASC");
$rolesQ = mysqli_query($conn, "SELECT id, role_name FROM roles WHERE is_active = 1 AND is_designation = 1 ORDER BY role_name ASC");
$managersQ = mysqli_query($conn, "SELECT id, full_name, employee_code FROM employees WHERE employee_status = 'active' ORDER BY full_name ASC");
$officeLocationsQ = mysqli_query($conn, "
    SELECT id, location_name, location_code, address, city, state, pincode, is_head_office
    FROM master_office_locations
    WHERE is_active = 1
    ORDER BY is_head_office DESC, sort_order ASC, location_name ASC
");

function selected($a, $b)
{
    return (string)$a === (string)$b ? "selected" : "";
}

function value($row, $key, $default = "")
{
    return htmlspecialchars($row[$key] ?? $default, ENT_QUOTES, "UTF-8");
}
?>

<div class="row g-3">
    <div class="col-12">
        <p class="fw-bold mb-1">Basic Details</p>
        <p class="text-muted-custom small mb-0">Employee identity and contact information.</p>
    </div>

    <div class="col-md-6">
        <label class="form-label fw-bold small">Full Name <span class="text-danger">*</span></label>
        <input type="text" name="full_name" class="form-control rounded-4" required value="<?= value($employee, "full_name") ?>">
    </div>

    <div class="col-md-6">
        <label class="form-label fw-bold small">Employee Code <span class="text-danger">*</span></label>
        <input type="text" name="employee_code" class="form-control rounded-4" required value="<?= value($employee, "employee_code") ?>">
    </div>

    <div class="col-md-4">
        <label class="form-label fw-bold small">Date of Birth</label>
        <input type="date" name="date_of_birth" class="form-control rounded-4" value="<?= value($employee, "date_of_birth") ?>">
    </div>

    <div class="col-md-4">
        <label class="form-label fw-bold small">Gender</label>
        <select name="gender" class="form-select rounded-4">
            <option value="">Select</option>
            <option value="Male" <?= selected($employee["gender"] ?? "", "Male") ?>>Male</option>
            <option value="Female" <?= selected($employee["gender"] ?? "", "Female") ?>>Female</option>
            <option value="Other" <?= selected($employee["gender"] ?? "", "Other") ?>>Other</option>
        </select>
    </div>

    <div class="col-md-4">
        <label class="form-label fw-bold small">Blood Group</label>
        <input type="text" name="blood_group" class="form-control rounded-4" value="<?= value($employee, "blood_group") ?>" placeholder="B+">
    </div>

    <div class="col-md-6">
        <label class="form-label fw-bold small">Mobile Number</label>
        <input type="text" name="mobile_number" class="form-control rounded-4" value="<?= value($employee, "mobile_number") ?>">
    </div>

    <div class="col-md-6">
        <label class="form-label fw-bold small">Email</label>
        <input type="email" name="email" class="form-control rounded-4" value="<?= value($employee, "email") ?>">
    </div>

    <div class="col-12">
        <label class="form-label fw-bold small">Current Address</label>
        <textarea name="current_address" class="form-control rounded-4" rows="3"><?= value($employee, "current_address") ?></textarea>
    </div>

    <div class="col-12 pt-2">
        <p class="fw-bold mb-1">Work Details</p>
        <p class="text-muted-custom small mb-0">Department, designation, reporting details and office work location.</p>
    </div>

    <div class="col-md-4">
        <label class="form-label fw-bold small">Date of Joining</label>
        <input type="date" name="date_of_joining" class="form-control rounded-4" value="<?= value($employee, "date_of_joining") ?>">
    </div>

    <div class="col-md-4">
        <label class="form-label fw-bold small">Department</label>
        <select name="department_id" class="form-select rounded-4">
            <option value="">Select Department</option>
            <?php if ($departmentsQ): ?>
                <?php while ($d = mysqli_fetch_assoc($departmentsQ)): ?>
                    <option value="<?= (int)$d["id"] ?>" <?= selected($employee["department_id"] ?? "", $d["id"]) ?>>
                        <?= htmlspecialchars($d["department_name"], ENT_QUOTES, "UTF-8") ?>
                    </option>
                <?php endwhile; ?>
            <?php endif; ?>
        </select>
    </div>

    <div class="col-md-4">
        <label class="form-label fw-bold small">Designation / Role</label>
        <select name="role_id" class="form-select rounded-4">
            <option value="">Select Designation</option>
            <?php if ($rolesQ): ?>
                <?php while ($r = mysqli_fetch_assoc($rolesQ)): ?>
                    <option value="<?= (int)$r["id"] ?>" <?= selected($employee["role_id"] ?? "", $r["id"]) ?>>
                        <?= htmlspecialchars($r["role_name"], ENT_QUOTES, "UTF-8") ?>
                    </option>
                <?php endwhile; ?>
            <?php endif; ?>
        </select>
    </div>

    <div class="col-md-4">
        <label class="form-label fw-bold small">Reporting To</label>
        <select name="reporting_to" class="form-select rounded-4">
            <option value="">Select Manager</option>
            <?php if ($managersQ): ?>
                <?php while ($m = mysqli_fetch_assoc($managersQ)): ?>
                    <?php if (!empty($employee["id"]) && (int)$employee["id"] === (int)$m["id"]) continue; ?>
                    <option value="<?= (int)$m["id"] ?>" <?= selected($employee["reporting_to"] ?? "", $m["id"]) ?>>
                        <?= htmlspecialchars($m["full_name"] . " (" . $m["employee_code"] . ")", ENT_QUOTES, "UTF-8") ?>
                    </option>
                <?php endwhile; ?>
            <?php endif; ?>
        </select>
    </div>

    <div class="col-md-4">
        <label class="form-label fw-bold small">Work Location <span class="text-danger">*</span></label>
        <select name="office_location_id" id="office_location_id" class="form-select rounded-4" required>
            <option value="">Select Work Location</option>
            <?php if ($officeLocationsQ): ?>
                <?php while ($ol = mysqli_fetch_assoc($officeLocationsQ)): ?>
                    <?php
                    $locationTitle = $ol["location_name"];
                    $locationMeta = trim(($ol["city"] ?? "") . (($ol["state"] ?? "") ? ", " . $ol["state"] : ""));
                    ?>
                    <option
                        value="<?= (int)$ol["id"] ?>"
                        data-location-name="<?= htmlspecialchars($locationTitle, ENT_QUOTES, "UTF-8") ?>"
                        <?= selected($employee["office_location_id"] ?? "", $ol["id"]) ?>>
                        <?= htmlspecialchars($locationTitle, ENT_QUOTES, "UTF-8") ?>
                        <?= !empty($ol["location_code"]) ? "(" . htmlspecialchars($ol["location_code"], ENT_QUOTES, "UTF-8") . ")" : "" ?>
                        <?= !empty($ol["is_head_office"]) ? "- Head Office" : "" ?>
                        <?= $locationMeta !== "" ? "- " . htmlspecialchars($locationMeta, ENT_QUOTES, "UTF-8") : "" ?>
                    </option>
                <?php endwhile; ?>
            <?php endif; ?>
        </select>
        <input type="hidden" name="work_location" id="work_location" value="<?= value($employee, "work_location") ?>">
        <small class="text-muted-custom fw-semibold">Work location is selected from Office Locations master.</small>
    </div>

    <div class="col-md-4">
        <label class="form-label fw-bold small">Site Name</label>
        <input type="text" name="site_name" class="form-control rounded-4" value="<?= value($employee, "site_name") ?>">
    </div>

    <div class="col-md-4">
        <label class="form-label fw-bold small">Status</label>
        <select name="employee_status" class="form-select rounded-4">
            <option value="active" <?= selected($employee["employee_status"] ?? "active", "active") ?>>Active</option>
            <option value="inactive" <?= selected($employee["employee_status"] ?? "", "inactive") ?>>Inactive</option>
            <option value="resigned" <?= selected($employee["employee_status"] ?? "", "resigned") ?>>Resigned</option>
        </select>
    </div>

    <div class="col-12 pt-2">
        <p class="fw-bold mb-1">Emergency & KYC Details</p>
        <p class="text-muted-custom small mb-0">Emergency contact, identity and bank details.</p>
    </div>

    <div class="col-md-6">
        <label class="form-label fw-bold small">Emergency Contact Name</label>
        <input type="text" name="emergency_contact_name" class="form-control rounded-4" value="<?= value($employee, "emergency_contact_name") ?>">
    </div>

    <div class="col-md-6">
        <label class="form-label fw-bold small">Emergency Contact Phone</label>
        <input type="text" name="emergency_contact_phone" class="form-control rounded-4" value="<?= value($employee, "emergency_contact_phone") ?>">
    </div>

    <div class="col-md-4">
        <label class="form-label fw-bold small">Aadhar Number</label>
        <input type="text" name="aadhar_card_number" class="form-control rounded-4" value="<?= value($employee, "aadhar_card_number") ?>">
    </div>

    <div class="col-md-4">
        <label class="form-label fw-bold small">PAN Number</label>
        <input type="text" name="pancard_number" class="form-control rounded-4" value="<?= value($employee, "pancard_number") ?>">
    </div>

    <div class="col-md-4">
        <label class="form-label fw-bold small">Bank Account Number</label>
        <input type="text" name="bank_account_number" class="form-control rounded-4" value="<?= value($employee, "bank_account_number") ?>">
    </div>

    <div class="col-md-4">
        <label class="form-label fw-bold small">IFSC Code</label>
        <input type="text" name="ifsc_code" class="form-control rounded-4" value="<?= value($employee, "ifsc_code") ?>">
    </div>

    <div class="col-md-4">
        <label class="form-label fw-bold small">Photo</label>
        <input type="file" name="photo" class="form-control rounded-4" accept="image/*">
        <input type="hidden" name="old_photo" value="<?= value($employee, "photo") ?>">
        <?php if (!empty($employee["photo"])): ?>
            <small class="text-muted-custom">Current: <?= value($employee, "photo") ?></small>
        <?php endif; ?>
    </div>

    <div class="col-md-4">
        <label class="form-label fw-bold small">Passbook Photo</label>
        <input type="file" name="passbook_photo" class="form-control rounded-4" accept="image/*,.pdf">
        <input type="hidden" name="old_passbook_photo" value="<?= value($employee, "passbook_photo") ?>">
        <?php if (!empty($employee["passbook_photo"])): ?>
            <small class="text-muted-custom">Current: <?= value($employee, "passbook_photo") ?></small>
        <?php endif; ?>
    </div>
</div>
