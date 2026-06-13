<?php
function client_selected($a, $b)
{
    return (string)$a === (string)$b ? "selected" : "";
}

function client_value($row, $key, $default = "")
{
    return htmlspecialchars($row[$key] ?? $default, ENT_QUOTES, "UTF-8");
}

if (!function_exists("client_table_has_column")) {
    function client_table_has_column($conn, $table, $column)
    {
        $table = mysqli_real_escape_string($conn, $table);
        $column = mysqli_real_escape_string($conn, $column);
        $res = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");
        return $res && mysqli_num_rows($res) > 0;
    }
}

$hasClientTypeId = client_table_has_column($conn, "clients", "client_type_id");
$hasClientTypeText = client_table_has_column($conn, "clients", "client_type");
$hasPassword = client_table_has_column($conn, "clients", "password");

$clientTypesQ = mysqli_query($conn, "SELECT id, client_type_name FROM master_client_types WHERE is_active = 1 ORDER BY client_type_name ASC");

$oldTypeValue = $client["client_type_id"] ?? "";
if (!$oldTypeValue && $hasClientTypeText && !empty($client["client_type"])) {
    $oldTypeValue = $client["client_type"];
}
?>

<div class="row g-3">
    <div class="col-12">
        <p class="fw-bold mb-1">Basic Client Details</p>
        <p class="text-muted-custom small mb-0">Client identity, type and contact information.</p>
    </div>

    <div class="col-md-6">
        <label class="form-label fw-bold small">Client Name <span class="text-danger">*</span></label>
        <input type="text" name="client_name" class="form-control rounded-4" required value="<?= client_value($client, "client_name") ?>">
    </div>

    <div class="col-md-6">
        <label class="form-label fw-bold small">Company Name</label>
        <input type="text" name="company_name" class="form-control rounded-4" value="<?= client_value($client, "company_name") ?>">
    </div>

    <div class="col-md-4">
        <label class="form-label fw-bold small">Client Type</label>
        <?php if ($hasClientTypeId): ?>
            <select name="client_type_id" class="form-select rounded-4">
                <option value="">Select Type</option>
                <?php while ($ct = mysqli_fetch_assoc($clientTypesQ)): ?>
                    <option value="<?= (int)$ct["id"] ?>" <?= client_selected($oldTypeValue, $ct["id"]) ?>>
                        <?= htmlspecialchars($ct["client_type_name"], ENT_QUOTES, "UTF-8") ?>
                    </option>
                <?php endwhile; ?>
            </select>
        <?php else: ?>
            <select name="client_type" class="form-select rounded-4">
                <option value="">Select Type</option>
                <?php foreach (["Individual", "Builder", "Developer", "Government", "Corporate", "Company", "Organization"] as $type): ?>
                    <option value="<?= htmlspecialchars($type, ENT_QUOTES, "UTF-8") ?>" <?= client_selected($oldTypeValue, $type) ?>>
                        <?= htmlspecialchars($type, ENT_QUOTES, "UTF-8") ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
    </div>

    <div class="col-md-4">
        <label class="form-label fw-bold small">Mobile Number <span class="text-danger">*</span></label>
        <input type="text" name="mobile_number" class="form-control rounded-4" required value="<?= client_value($client, "mobile_number") ?>">
    </div>

    <div class="col-md-4">
        <label class="form-label fw-bold small">Email</label>
        <input type="email" name="email" class="form-control rounded-4" value="<?= client_value($client, "email") ?>">
    </div>

    <div class="col-md-4">
        <label class="form-label fw-bold small">State</label>
        <input type="text" name="state" class="form-control rounded-4" value="<?= client_value($client, "state") ?>">
    </div>

    <div class="col-md-4">
        <label class="form-label fw-bold small">PAN Number</label>
        <input type="text" name="pan_number" class="form-control rounded-4" value="<?= client_value($client, "pan_number") ?>">
    </div>

    <div class="col-md-4">
        <label class="form-label fw-bold small">GST Number</label>
        <input type="text" name="gst_number" class="form-control rounded-4" value="<?= client_value($client, "gst_number") ?>">
    </div>

    <div class="col-md-4">
        <label class="form-label fw-bold small">Aadhaar Number</label>
        <input type="text" name="aadhaar_number" class="form-control rounded-4" value="<?= client_value($client, "aadhaar_number") ?>">
    </div>

    <?php if ($hasPassword): ?>
        <div class="col-md-4">
            <label class="form-label fw-bold small">Password <?= empty($client["id"]) ? '<span class="text-danger">*</span>' : '' ?></label>
            <input type="password" name="password" class="form-control rounded-4" <?= empty($client["id"]) ? "required" : "" ?> placeholder="<?= empty($client["id"]) ? "Create password" : "Leave empty to keep old password" ?>">
        </div>
    <?php endif; ?>

    <div class="col-12 pt-2">
        <p class="fw-bold mb-1">Address Details</p>
        <p class="text-muted-custom small mb-0">Office, site, billing and shipping addresses.</p>
    </div>

    <div class="col-md-6">
        <label class="form-label fw-bold small">Office Address</label>
        <textarea name="office_address" class="form-control rounded-4" rows="3"><?= client_value($client, "office_address") ?></textarea>
    </div>

    <div class="col-md-6">
        <label class="form-label fw-bold small">Site Address</label>
        <textarea name="site_address" class="form-control rounded-4" rows="3"><?= client_value($client, "site_address") ?></textarea>
    </div>

    <div class="col-md-6">
        <label class="form-label fw-bold small">Billing Address</label>
        <textarea name="billing_address" class="form-control rounded-4" rows="3"><?= client_value($client, "billing_address") ?></textarea>
    </div>

    <div class="col-md-6">
        <label class="form-label fw-bold small">Shipping Address</label>
        <textarea name="shipping_address" class="form-control rounded-4" rows="3"><?= client_value($client, "shipping_address") ?></textarea>
    </div>
</div>
