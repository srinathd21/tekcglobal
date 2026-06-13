<?php
// includes/page-message.php
// Use on every page after <body> / mobileOverlay.
// Set $pageMessageType and $pageMessageText before including this file.

if (!isset($pageMessageType)) {
    $pageMessageType = "";
}

if (!isset($pageMessageText)) {
    $pageMessageText = "";
}

if (!function_exists("e")) {
    function e($v) {
        return htmlspecialchars($v ?? "", ENT_QUOTES, "UTF-8");
    }
}
?>

<?php if ($pageMessageText !== ""): ?>
    <div class="page-toast-wrap" id="pageToastWrap">
        <div class="page-toast <?= $pageMessageType === "error" ? "error" : "" ?>">
            <div class="page-toast-icon">
                <i data-lucide="<?= $pageMessageType === "error" ? "x-circle" : "check-circle-2" ?>" style="width:19px;height:19px;"></i>
            </div>
            <div>
                <p class="page-toast-title"><?= $pageMessageType === "error" ? "Error" : "Success" ?></p>
                <p class="page-toast-text"><?= e($pageMessageText) ?></p>
            </div>
            <button type="button" class="page-toast-close" onclick="hidePageToast()" aria-label="Close">
                <i data-lucide="x" style="width:18px;height:18px;"></i>
            </button>
        </div>
    </div>

    <div class="modal fade" id="pageMessageModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content message-modal-content">
                <div class="message-modal-icon <?= $pageMessageType === "error" ? "error" : "" ?>">
                    <i data-lucide="<?= $pageMessageType === "error" ? "x-circle" : "check-circle-2" ?>" style="width:30px;height:30px;"></i>
                </div>
                <h5 class="fw-bold mb-2"><?= $pageMessageType === "error" ? "Error" : "Success" ?></h5>
                <p class="text-muted-custom small fw-semibold mb-3"><?= e($pageMessageText) ?></p>
                <button type="button" class="btn brand-gradient text-white rounded-4 fw-bold px-4" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
<?php endif; ?>
