<?php
session_start();
require_once "includes/db.php";

$sql = "
SELECT
  nus.id,
  nus.notification_id,
  nus.user_id,
  nus.is_read,
  nus.read_at,
  nus.is_archived,
  nus.archived_at,
  nus.is_deleted,
  nus.deleted_at,
  nus.delivered_at,
  n.title,
  n.priority,
  n.notification_type,
  u.name,
  u.username
FROM notification_user_status nus
JOIN notifications n ON n.id = nus.notification_id
JOIN users u ON u.id = nus.user_id
ORDER BY nus.id DESC
";

$result = mysqli_query($conn, $sql);

function e($v) {
    return htmlspecialchars($v ?? "", ENT_QUOTES, "UTF-8");
}
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Notification User Status - TEK-C PMC Construction</title>
  <?php include("includes/links.php"); ?>
</head>

<body>
<div id="mobileOverlay" class="d-none position-fixed top-0 start-0 w-100 h-100 bg-dark bg-opacity-50 z-3 d-xl-none"></div>

<div class="min-vh-100 d-flex">
  <?php include("includes/sidebar.php"); ?>

  <main id="main">
    <?php include("includes/nav.php"); ?>

    <section class="page-section p-3 p-lg-3">
      <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-3 mb-3">
        <div>
          <h1 class="h4 fw-bold mb-1">Notification User Status</h1>
          <p class="text-muted-custom mb-0 small">Track read, archived, deleted, and delivered notification status.</p>
        </div>
      </div>

      <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success rounded-4 border-0 fw-semibold">Notification status updated successfully.</div>
      <?php endif; ?>

      <section class="card-ui p-3 p-lg-4">
        <div class="table-responsive thin-scrollbar">
          <table class="project-table w-100">
            <thead>
              <tr>
                <th>User</th>
                <th>Notification</th>
                <th>Read</th>
                <th>Archived</th>
                <th>Deleted</th>
                <th>Delivered</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
            <?php while ($row = mysqli_fetch_assoc($result)): ?>
              <tr>
                <td>
                  <div class="fw-bold"><?= e($row['name']) ?></div>
                  <small class="text-muted-custom"><?= e($row['username']) ?></small>
                </td>
                <td>
                  <div class="fw-bold"><?= e($row['title']) ?></div>
                  <small class="text-muted-custom"><?= e($row['priority']) ?> · <?= e($row['notification_type']) ?></small>
                </td>
                <td>
                  <span class="pill <?= $row['is_read'] ? 'green' : 'amber' ?>">
                    <?= $row['is_read'] ? 'Read' : 'Unread' ?>
                  </span>
                </td>
                <td><?= $row['is_archived'] ? 'Yes' : 'No' ?></td>
                <td><?= $row['is_deleted'] ? 'Yes' : 'No' ?></td>
                <td><?= e($row['delivered_at']) ?></td>
                <td>
                  <a href="api/process-notification-status.php?id=<?= (int)$row['id'] ?>&action=read"
                     class="btn btn-sm btn-outline-primary rounded-4 fw-bold">Mark Read</a>
                  <a href="api/process-notification-status.php?id=<?= (int)$row['id'] ?>&action=archive"
                     class="btn btn-sm btn-outline-secondary rounded-4 fw-bold">Archive</a>
                  <a href="api/process-notification-status.php?id=<?= (int)$row['id'] ?>&action=delete"
                     class="btn btn-sm btn-outline-danger rounded-4 fw-bold"
                     onclick="return confirm('Delete this status?')">Delete</a>
                </td>
              </tr>
            <?php endwhile; ?>
            </tbody>
          </table>
        </div>
      </section>

      <?php include("includes/footer.php"); ?>
    </section>
  </main>

  <div id="settingsOverlay"></div>
  <?php include("includes/rightsidbar.php"); ?>
</div>

<script src="assets/js/script.js"></script>
<script>
window.addEventListener("load", function () {
  if (window.lucide && typeof window.lucide.createIcons === "function") {
    window.lucide.createIcons();
  }
});
</script>
</body>
</html>