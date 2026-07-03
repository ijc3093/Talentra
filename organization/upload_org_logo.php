<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors','1');

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';

if (!isOrgManager()) { header("Location: dashboard.php"); exit; }

$err = '';
$ok  = '';

$orgId = orgActiveOrgId();

$uploadDir = __DIR__ . '/uploads/org_logos';
$webDir    = 'uploads/org_logos';

if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
        $err = "Upload failed.";
    } else {
        $tmp  = $_FILES['logo']['tmp_name'];
        $size = (int)($_FILES['logo']['size'] ?? 0);

        if ($size <= 0 || $size > 3_000_000) {
            $err = "Logo must be under 3MB.";
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = (string)$finfo->file($tmp);

            $allowed = [
                'image/png'  => 'png',
                'image/jpeg' => 'jpg',
                'image/webp' => 'webp',
            ];

            if (!isset($allowed[$mime])) {
                $err = "Only PNG, JPG, or WEBP allowed.";
            } else {
                $ext  = $allowed[$mime];
                $file = 'org_'.$orgId.'_logo_'.date('Ymd_His').'.'.$ext;

                $dest = $uploadDir . '/' . $file;
                if (!move_uploaded_file($tmp, $dest)) {
                    $err = "Could not save file.";
                } else {
                    $path = $webDir . '/' . $file; // relative browser path

                    $st = $dbh->prepare("
                    UPDATE org_settings
                    SET logo_type = 'image',
                        logo_image_path = :p,
                        updated_at = NOW()
                    WHERE org_id = :oid
                    LIMIT 1
                    ");
                    $st->execute([':p' => $path, ':oid' => $orgId]);

                    $ok = "Logo updated.";
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/org_theme_head.php'; ?>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title><?= h($ORG['name']) ?> - Upload Logo</title>

  <link href="../lib/bootstrap/bootstrap.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/shamcey.css">
</head>
<body>

<?php include __DIR__ . '/includes/header.php'; ?>
<?php include __DIR__ . '/includes/leftbar.php'; ?>

<div class="sh-mainpanel">
  <div class="sh-pagebody">
    <div class="card bd-0">
      <div class="card-header bg-transparent pd-y-15">
        <h6 class="card-title mg-b-0">Upload Organization Logo</h6>
      </div>
      <div class="card-body">
        <?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
        <?php if ($ok): ?><div class="alert alert-success"><?= h($ok) ?></div><?php endif; ?>

        <form method="post" enctype="multipart/form-data">
          <div class="form-group">
            <label>Select image (PNG/JPG/WEBP, max 3MB)</label>
            <input type="file" name="logo" class="form-control" accept="image/png,image/jpeg,image/webp" required>
          </div>
          <button class="btn btn-primary">Upload</button>
          <a class="btn btn-secondary" href="settings.php">Back</a>
        </form>

      </div>
    </div>
  </div>

  <?php include __DIR__ . '/includes/footer.php'; ?>
</div>

</body>
</html>
