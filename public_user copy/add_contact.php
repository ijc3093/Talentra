<?php
// /Business_only3/add_contact.php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/user_identity.php';
require_once __DIR__ . '/includes/friend_system.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');

$controller = new Controller();
$dbh = $controller->pdo();

$meId    = (int)userId();
$meEmail = trim((string)userEmail());

$msg = '';
$error = '';

// Prefill friend code when coming from messages sidebar
$prefillFriend = trim((string)($_GET['friend'] ?? ''));
$returnTo = trim((string)($_GET['return'] ?? ''));

// ===============================
// MODE DETECTION (EDIT VS ADD)
// ===============================
$isEdit    = isset($_GET['edit'], $_GET['id']) && (string)$_GET['edit'] === '1';
$contactId = $isEdit ? (int)$_GET['id'] : 0;

// Prefill display name for edit
$prefillDisplay = '';

if ($meId <= 0) {
    clearUserSession();
    header("Location: index.php?session=reset");
    exit;
}

function normalizeFriendCodeInput(string $value): string {
    $value = strtoupper(trim($value));
    $value = preg_replace('/\s+/', '', $value) ?? $value;

    // Friend codes are USR-..., but this catches the common URS typo.
    if (strpos($value, 'URS-') === 0) {
        $value = 'USR-' . substr($value, 4);
    }

    return $value;
}

/**
 * Find user by friend_code only.
 * Returns: id, name, username, email, friend_code, status
 */
function findUserByFriendCode(PDO $dbh, string $value): ?array {
    $code = normalizeFriendCodeInput($value);
    if ($code === '') return null;

    $st = $dbh->prepare("SELECT id, name, username, email, friend_code, status FROM users WHERE UPPER(friend_code) = ? LIMIT 1");
    $st->execute([$code]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function ensureContactVisibleForOwner(PDO $dbh, int $ownerId, array $friend, string $display = ''): array {
    $friendId = (int)($friend['id'] ?? 0);
    if ($ownerId <= 0 || $friendId <= 0 || $ownerId === $friendId) {
        return ['ok' => false, 'contact_id' => 0, 'display_name' => ''];
    }

    $display = trim($display);
    if ($display === '') {
        $display = trim((string)($friend['name'] ?? ''));
    }
    if ($display === '') {
        $display = trim((string)($friend['username'] ?? ''));
    }
    if ($display === '') {
        $display = trim((string)($friend['email'] ?? ''));
    }
    if ($display === '') {
        $display = trim((string)($friend['friend_code'] ?? ''));
    }
    if ($display === '') {
        $display = 'User ' . $friendId;
    }

    $existing = $dbh->prepare("SELECT id, display_name FROM user_contacts WHERE owner_user_id = ? AND friend_user_id = ? LIMIT 1");
    $existing->execute([$ownerId, $friendId]);
    $row = $existing->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $contactId = (int)($row['id'] ?? 0);
        $currentDisplay = trim((string)($row['display_name'] ?? ''));
        if ($contactId > 0 && ($currentDisplay === '' || trim((string)($_POST['display_name'] ?? '')) !== '')) {
            $up = $dbh->prepare("UPDATE user_contacts SET display_name = ? WHERE id = ? AND owner_user_id = ? LIMIT 1");
            $up->execute([$display, $contactId, $ownerId]);
        }
        return ['ok' => true, 'contact_id' => $contactId, 'display_name' => $display];
    }

    $ins = $dbh->prepare("INSERT INTO user_contacts (owner_user_id, friend_user_id, display_name, created_at) VALUES (?, ?, ?, NOW())");
    $ins->execute([$ownerId, $friendId, $display]);
    return ['ok' => true, 'contact_id' => (int)$dbh->lastInsertId(), 'display_name' => $display];
}

function jsonFriendPayload(array $friend, string $display, array $contact = []): array {
    return [
        'id' => (int)($friend['id'] ?? 0),
        'name' => trim((string)($friend['name'] ?? '')),
        'username' => trim((string)($friend['username'] ?? '')),
        'email' => trim((string)($friend['email'] ?? '')),
        'friend_code' => trim((string)($friend['friend_code'] ?? '')),
        'display_name' => trim((string)($contact['display_name'] ?? $display)),
        'contact_id' => (int)($contact['contact_id'] ?? 0),
    ];
}

if (isset($_POST['ajax']) && (string)$_POST['ajax'] === 'add') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $friendInput = trim((string)($_POST['friend'] ?? ''));
    $display = trim((string)($_POST['display_name'] ?? ''));

    if ($friendInput === '') {
        echo json_encode(['ok' => false, 'error' => 'Enter a friend code.']);
        exit;
    }

    $friend = findUserByFriendCode($dbh, $friendInput);
    if (!$friend) {
        echo json_encode(['ok' => false, 'error' => 'User not found. Check friend code.']);
        exit;
    }
    if ((int)($friend['status'] ?? 0) !== 1) {
        echo json_encode(['ok' => false, 'error' => 'This user account is inactive.']);
        exit;
    }
    if ((int)($friend['id'] ?? 0) === $meId) {
        echo json_encode(['ok' => false, 'error' => 'You cannot add yourself.']);
        exit;
    }

    if (fs_friend_status($dbh, $meId, (int)$friend['id']) === 'friends') {
        $contact = ensureContactVisibleForOwner($dbh, $meId, $friend, $display);
        echo json_encode([
            'ok' => true,
            'message' => 'Friend added to your contacts.',
            'friend' => jsonFriendPayload($friend, $display, $contact),
        ]);
        exit;
    }

    $result = fs_send_friend_request($dbh, $meId, (int)$friend['id']);
    if (!$result['ok']) {
        if ((string)$result['message'] === 'This user is already your friend.') {
            $contact = ensureContactVisibleForOwner($dbh, $meId, $friend, $display);
            echo json_encode([
                'ok' => true,
                'message' => 'Friend added to your contacts.',
                'friend' => jsonFriendPayload($friend, $display, $contact),
            ]);
            exit;
        }
        echo json_encode(['ok' => false, 'error' => (string)$result['message']]);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'message' => (string)$result['message'],
        'friend' => jsonFriendPayload($friend, $display),
    ]);
    exit;
}

// ===============================
// LOAD CONTACT WHEN EDITING
// ===============================
if ($isEdit) {
    if ($contactId <= 0) {
        $error = "Invalid contact id.";
        $isEdit = false;
    } else {
        // Join to users so we can show friend_code in the input (readonly)
        $st = $dbh->prepare("
            SELECT uc.id,
                   uc.friend_user_id,
                   uc.display_name,
                   u.friend_code
            FROM user_contacts uc
            JOIN users u ON u.id = uc.friend_user_id
            WHERE uc.id = ? AND uc.owner_user_id = ?
            LIMIT 1
        ");
        $st->execute([$contactId, $meId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $error = "Contact not found.";
            $isEdit = false;
        } else {
            $prefillFriend  = trim((string)($row['friend_code'] ?? $prefillFriend));
            $prefillDisplay = trim((string)($row['display_name'] ?? ''));
        }
    }
}

// ===============================
// SAVE: EDIT MODE (UPDATE NAME)
// ===============================
if ($isEdit && isset($_POST['save_contact'])) {
    $display        = trim((string)($_POST['display_name'] ?? ''));
    $returnTo       = trim((string)($_POST['return_to'] ?? $returnTo));
    $contactIdPost  = (int)($_POST['contact_id'] ?? 0);

    if ($contactIdPost <= 0) {
        $error = "Invalid contact id.";
    } elseif ($display === '') {
        $error = "Please enter a name before saving.";
    } else {
        $up = $dbh->prepare("
            UPDATE user_contacts
            SET display_name = ?
            WHERE id = ? AND owner_user_id = ?
            LIMIT 1
        ");
        $up->execute([$display, $contactIdPost, $meId]);

        // After editing, go back to contacts or messages
        if ($returnTo === 'messages') {
            header('Location: messages.php?peer=' . urlencode($prefillFriend));
            exit;
        }

        header('Location: contacts.php?updated=1');
        exit;
    }

    // keep prefill if error
    $prefillDisplay = $display;
}

// ===============================
// SAVE: ADD MODE (SEND FRIEND REQUEST)
// ===============================
if (!$isEdit && isset($_POST['add_contact'])) {
    $friendInput = trim((string)($_POST['friend'] ?? ''));
    $display     = trim((string)($_POST['display_name'] ?? ''));
    $returnTo    = trim((string)($_POST['return_to'] ?? $returnTo));

    if ($friendInput === '') {
        $error = "Enter a friend code.";
    } else {
        $friend = findUserByFriendCode($dbh, $friendInput);

        if (!$friend) {
            $error = "User not found. Check friend code.";
        } elseif ((int)($friend['status'] ?? 0) !== 1) {
            $error = "This user account is inactive.";
        } elseif ((int)($friend['id'] ?? 0) === $meId) {
            $error = "You cannot add yourself.";
        } else {
            if (fs_friend_status($dbh, $meId, (int)$friend['id']) === 'friends') {
                ensureContactVisibleForOwner($dbh, $meId, $friend, $display);
                $msg = "Friend added to your contacts.";
                $prefillFriend = '';
                $prefillDisplay = $display;
            } else {
                $result = fs_send_friend_request($dbh, $meId, (int)$friend['id']);
                if (!$result['ok']) {
                    if ((string)$result['message'] === 'This user is already your friend.') {
                        ensureContactVisibleForOwner($dbh, $meId, $friend, $display);
                        $msg = "Friend added to your contacts.";
                        $prefillFriend = '';
                        $prefillDisplay = $display;
                    } else {
                        $error = (string)$result['message'];
                    }
                } else {
                    $msg = (string)$result['message'];
                    if ($returnTo === 'messages') {
                        $peerCode = trim((string)($friend['friend_code'] ?? ''));
                        header('Location: messages.php?peer=' . urlencode($peerCode) . '&friend_request=sent');
                        exit;
                    }
                    $prefillFriend = '';
                    $prefillDisplay = $display;
                }
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">

    <meta name="description" content="Premium Quality and Responsive UI for Dashboard.">
    <meta name="author" content="ThemePixels">

    <title>Add Friend</title>
    <?php
    require_once __DIR__ . '/includes/theme_prefs.php';
    theme_prefs_print_head_bootstrap($dbh, $meId);
    ?>

    <link href="./lib/font-awesome/css/font-awesome.css" rel="stylesheet">
    <link href="./lib/Ionicons/css/ionicons.css" rel="stylesheet">
    <link href="./lib/perfect-scrollbar/css/perfect-scrollbar.css" rel="stylesheet">
    <link href="./lib/select2/css/select2.min.css" rel="stylesheet">

    <!-- css -->
    <link rel="stylesheet" href="./css/shamcey.css">
    <link rel="stylesheet" href="assets/ui_best.css">

    <!-- script -->
    <script src="assets/ui_best.js" defer></script>
    <script src="./lib/jquery/jquery.js"></script>
    <script src="./lib/popper.js/popper.js"></script>
    <script src="./lib/bootstrap/bootstrap.js"></script>
    <script src="./lib/perfect-scrollbar/js/perfect-scrollbar.jquery.js"></script>
    <script src="./lib/select2/js/select2.min.js"></script>
    <script src="./lib/parsleyjs/parsley.js"></script>
    <script src="./js/shamcey.js"></script>

    <style>
      /* ✅ FIXED PAGE LIKE settings.php */
      html, body { height: 100%; overflow: hidden; }

      .sh-mainpanel{
        height: 100vh;
        display: flex;
        flex-direction: column;
        overflow: hidden;
      }

      .sh-pagetitle{ flex: 0 0 auto; }
      .sh-pagetitle{ position:sticky; top:100px; z-index:1100; background:#fff; border-bottom:1px solid rgba(0,0,0,.08); box-shadow:0 2px 10px rgba(0,0,0,.05); flex:0 0 auto;}

      .sh-pagebody{
        flex: 1 1 auto;
        min-height: 0;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        padding-bottom: 0 !important;
      }

      /* ✅ Only inner content scrolls */
      .page-shell{
        flex: 1 1 auto;
        min-height: 0;
        overflow: auto;
        padding: 12px;
      }

      /* Keep card spacing nice in fixed layout */
      .card.bd-primary{ margin-top: 20px !important; }
    
/* ===== Mobile fixes (iPhone) ===== */
@media (max-width: 575.98px){
  html, body { height: auto !important; overflow: auto !important; }
  body { overflow: auto !important; }

  .sh-mainpanel{ height:auto !important; overflow: visible !important; }
  .sh-mainpanel{ margin-left:0 !important; width:100% !important; }
  .sh-pagetitle{ position: sticky !important; top: 0 !important; }
  .sh-pagebody{ overflow: visible !important; }
  .page-shell{ padding: 10px !important; }

  /* Make cards/forms fit */
  .card, .sh-card, .card-body{ width: 100% !important; }
  .form-group, .form-control, input, select, textarea{ max-width:100% !important; }
}

/* ===== Tablet fixes (iPad / Android tablets) ===== */
@media (min-width: 576px) and (max-width: 991.98px){
  html, body { height: auto !important; overflow: auto !important; }
  body { overflow: auto !important; }

  /* Remove left gap on tablet (sidebar collapses/overlays) */
  .sh-mainpanel{ height:auto !important; overflow: visible !important; margin-left:0 !important; width:100% !important; }

  .sh-pagetitle{ position: sticky !important; top: 0 !important; }
  .sh-pagebody{ overflow: visible !important; }
  .page-shell{ padding: 12px !important; }

  /* Make card/form fit nicely */
  .card, .sh-card, .card-body{ width: 100% !important; }
  .form-group, .form-control, input, select, textarea{ max-width:100% !important; }
}

/* ===== Desktop/Laptop: keep layout tidy & centered ===== */
@media (min-width: 992px){
  .page-shell{ padding: 16px 20px !important; }
  .page-shell .card.bd-primary{
    max-width: 980px;
    margin-left: auto !important;
    margin-right: auto !important;
  }
}

</style>
  </head>

  <body>
    <?php $forceFeedRail = true; $skipHeaderThemeBootstrap = true; include __DIR__ . '/includes/header.php'; ?>
    <!-- <div class="sh-pagetitle">
        <div class="input-group">
          <input type="search" class="form-control" placeholder="Search">
          <span class="input-group-btn">
            <button class="btn"><i class="fa fa-search"></i></button>
          </span>
        </div>
        <div class="sh-pagetitle-left">
          <div class="sh-pagetitle-icon"><i class="icon ion-person-add"></i></div>
          <div class="sh-pagetitle-title">
            <span>Form Styles</span>
            <h2><?= $isEdit ? 'Edit Friend' : 'Add Friend Here' ?></h2>
          </div>
        </div>
      </div> -->
    <div class="sh-mainpanel">

      <div class="sh-pagebody">
        <div class="page-shell">

          <?php if ($error): ?>
            <div class="p-3 mb-3 text-sm text-red-600 bg-red-50 rounded"><?php echo h($error); ?></div>
          <?php endif; ?>
          <?php if ($msg): ?>
            <div class="p-3 mb-3 text-sm text-green-700 bg-green-50 rounded"><?php echo h($msg); ?></div>
          <?php endif; ?>

          <div class="card bd-primary mg-t-20">
            <div class="card-header bg-primary tx-white">Required Input Validation</div>
            <div class="card-body pd-sm-30">
              <p class="mg-b-20 mg-sm-b-30">
                <?= $isEdit ? 'Update the display name for this friend.' : 'Send a friend request using a friend code.' ?>
              </p>

              <form method="post" autocomplete="off">
                <div class="wd-300">
                  <div class="d-md-flex mg-b-30">
                    <div class="form-group mg-b-0">
                      <label>Friend Code: <span class="tx-danger">*</span></label>

                      <input
                        type="text"
                        name="friend"
                        class="form-control wd-200 wd-sm-250"
                        placeholder="e.g. USR-XXXX-YYYY"
                        value="<?php echo h($prefillFriend); ?>"
                        <?php echo $isEdit ? 'readonly' : 'required'; ?>
                      >

                      <input type="hidden" name="return_to" value="<?php echo h($returnTo); ?>">

                      <?php if ($isEdit): ?>
                        <input type="hidden" name="contact_id" value="<?php echo (int)$contactId; ?>">
                      <?php endif; ?>

                      <div class="hint" style="margin-top:8px;">
                        <?php if ($isEdit): ?>
                          You can only change the <b>Display Name</b>. Friend code cannot be changed here.
                        <?php else: ?>
                          Use their <b>friend code</b>.
                        <?php endif; ?>
                      </div>
                      <p>Use their friend code.</p>
                    </div>

                    <div class="form-group mg-b-0 mg-md-l-20 mg-t-20 mg-md-t-0">
                      <label>Display Name (optional):</label>
                      <input
                        type="text"
                        name="display_name"
                        class="form-control wd-200 wd-sm-250"
                        placeholder="e.g. John (Church friend)"
                        value="<?php echo h($isEdit ? $prefillDisplay : $prefillDisplay); ?>"
                        >

                      <p>Optional nickname for your own reference after friendship is accepted.</p>
                    </div>
                  </div>

                  <?php if ($isEdit): ?>
                    <button type="submit" name="save_contact" class="btn btn-success">Save Friend Name</button>
                    <a class="btn btn-secondary" href="contacts.php" style="margin-left:8px;">Cancel</a>
                  <?php else: ?>
                    <button type="submit" name="add_contact" class="btn btn-success">Send Friend Request</button>
                    <a class="btn btn-secondary" href="contacts.php" style="margin-left:8px;">Cancel</a>
                  <?php endif; ?>
                </div>
              </form>
            </div>
          </div>
        </div><!-- /page-shell -->
      </div><!-- /sh-pagebody -->

      <!-- <div class="sh-footer">
        <div>Copyright &copy; <?= date('Y') ?>. All Rights Reserved. Talentra</div>
        <div class="mg-t-10 mg-md-t-0">Designed by: <a href="http://themepixels.me">ThemePixels</a></div>
      </div> -->
    </div>

    
    <script>
      $(function(){
        'use strict';
        $('.select2').select2({ minimumResultsForSearch: Infinity });
        $('#selectForm').parsley();
      });
    </script>
    <!-- <?php include __DIR__ . '/includes/footer.php'; ?> -->
  </body>
</html>
