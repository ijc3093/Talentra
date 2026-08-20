<?php
declare(strict_types=1);

/**
 * Admin — User profile dashboard (viewport-fit, no page scroll).
 * Edit mode matches User Profile mockup; create/update POST behavior preserved.
 */
require_once __DIR__ . '/includes/user_admin_helpers_load.php';
require_once __DIR__ . '/includes/msb_moderation_activity.php';
user_admin_require();

error_reporting(E_ALL);
ini_set('display_errors', '1');

$dbh = user_admin_db();
$userId = (int)($_GET['user_id'] ?? $_GET['id'] ?? 0);
$isEdit = $userId > 0;
$user = $isEdit ? user_admin_get_user_full($dbh, $userId) : null;

if ($isEdit && !$user) {
    header('Location: userlist.php');
    exit;
}

$roles = user_admin_roles($dbh);
$genders = user_admin_genders();
$pubCategories = user_admin_publisher_categories();
$error = '';

$defaults = [
    'name' => '',
    'username' => '',
    'email' => '',
    'password' => '',
    'gender' => '',
    'mobile' => '',
    'designation' => '',
    'role' => 4,
    'account_kind' => 'personal',
    'publisher_category' => 'news',
    'publisher_tagline' => '',
    'status' => 1,
    'birthday' => '',
];

$form = $isEdit ? array_merge($defaults, $user) : $defaults;
if (!$isEdit) {
    $prefillKind = strtolower(trim((string)($_GET['account_kind'] ?? '')));
    if (in_array($prefillKind, ['personal', 'publisher'], true)) {
        $form['account_kind'] = $prefillKind;
    }
    $prefillCat = strtolower(trim((string)($_GET['publisher_category'] ?? '')));
    if ($prefillCat !== '' && isset($pubCategories[$prefillCat])) {
        $form['publisher_category'] = $prefillCat;
        if ($prefillCat === 'commerce') {
            $form['account_kind'] = 'publisher';
        }
    }
}
$form['password'] = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_user'])) {
    $input = user_admin_normalize_input($_POST);
    if ($isEdit) {
        $result = user_admin_update($dbh, $userId, $input);
        if (!empty($result['ok'])) {
            header('Location: user_form.php?user_id=' . $userId . '&msg=saved');
            exit;
        }
        $error = (string)($result['error'] ?? 'Update failed.');
        $form = array_merge($form, $input);
        $form['password'] = '';
    } else {
        $result = user_admin_create($dbh, $input);
        if (!empty($result['ok'])) {
            $q = 'msg=' . rawurlencode('User created successfully.');
            if (!empty($result['friend_code'])) {
                $q .= '&fc=' . rawurlencode((string)$result['friend_code']);
            }
            header('Location: userlist.php?' . $q);
            exit;
        }
        $error = (string)($result['error'] ?? 'Create failed.');
        $form = array_merge($form, $input);
        $form['password'] = '';
    }
}

$msg = (($_GET['msg'] ?? '') === 'saved') ? 'Changes saved.' : '';
$openEdit = $error !== '' && $isEdit;

$activity = [];
$behavior = ['tier' => 'normal', 'label' => 'Normal', 'score' => 0, 'flags' => []];
$modStatus = null;
$postsTotal = 0;
$followers = 0;
$following = 0;
$reportsRecv = 0;
$warnings = 0;
$suspensions = 0;
$recentPosts = [];
$timeline = [];
$reportStatusCounts = ['pending' => 0, 'reviewed' => 0, 'resolved' => 0, 'dismissed' => 0];
$reportReasonCounts = [];
$postEngagement = [];
$roleName = '';
foreach ($roles as $r) {
    if ((int)$r['idrole'] === (int)($form['role'] ?? 4)) {
        $roleName = (string)$r['name'];
        break;
    }
}

$pctBadge = static function (int $value, int $baseline): array {
    if ($value <= 0) {
        return [0, 'flat'];
    }
    if ($baseline <= 0) {
        return [100, 'up'];
    }
    $pct = (int)round((($value - $baseline) / max(1, $baseline)) * 100);
    if ($pct > 0) {
        return [$pct, 'up'];
    }
    if ($pct < 0) {
        return [abs($pct), 'down'];
    }
    return [0, 'flat'];
};

if ($isEdit && $userId > 0) {
    $activity = msb_mod_user_activity_summary($dbh, $userId);
    $behavior = msb_mod_behavior_indicators($activity);
    $modStatus = msb_mod_status_get($dbh, $userId);
    $postsTotal = (int)($activity['posts_total'] ?? 0);
    $reportsRecv = (int)($activity['reports_about_total'] ?? 0);
    if (msb_mod_table_exists($dbh, 'public_follows')) {
        $followers = msb_mod_count_safe($dbh, 'SELECT COUNT(*) FROM public_follows WHERE following_id = :uid', [':uid' => $userId]);
        $following = msb_mod_count_safe($dbh, 'SELECT COUNT(*) FROM public_follows WHERE follower_id = :uid', [':uid' => $userId]);
    }
    $tier = (string)(($modStatus['status'] ?? '') !== '' ? $modStatus['status'] : ($behavior['tier'] ?? 'normal'));
    $warnings = ($tier === 'review' || $tier === 'high_risk') ? max(1, (int)($activity['reports_about_total'] ?? 0) > 0 ? 2 : 1) : 0;
    $suspensions = ((int)($form['status'] ?? 1) !== 1) ? 1 : 0;
    $recentPosts = msb_mod_user_recent_posts_full($dbh, $userId, 3, false);
    $timeline = msb_mod_user_timeline($dbh, $userId, 6);

    $postIds = array_values(array_filter(array_map(static fn($p) => (int)($p['id'] ?? 0), $recentPosts)));
    if ($postIds) {
        $in = implode(',', array_map('intval', $postIds));
        if (msb_mod_table_exists($dbh, 'public_post_reactions')) {
            try {
                foreach ($dbh->query("SELECT post_id, COUNT(*) c FROM public_post_reactions WHERE post_id IN ($in) GROUP BY post_id") as $row) {
                    $pid = (int)$row['post_id'];
                    $postEngagement[$pid]['likes'] = (int)$row['c'];
                }
            } catch (Throwable $e) {
            }
        }
        if (msb_mod_table_exists($dbh, 'public_post_comments')) {
            try {
                foreach ($dbh->query("SELECT post_id, COUNT(*) c FROM public_post_comments WHERE post_id IN ($in) AND (is_deleted = 0 OR is_deleted IS NULL) GROUP BY post_id") as $row) {
                    $pid = (int)$row['post_id'];
                    $postEngagement[$pid]['comments'] = (int)$row['c'];
                }
            } catch (Throwable $e) {
            }
        }
        if (msb_mod_table_exists($dbh, 'public_user_reports')) {
            try {
                foreach ($dbh->query("SELECT target_id, COUNT(*) c FROM public_user_reports WHERE target_type = 'post' AND target_id IN ($in) GROUP BY target_id") as $row) {
                    $pid = (int)$row['target_id'];
                    $postEngagement[$pid]['reports'] = (int)$row['c'];
                }
            } catch (Throwable $e) {
            }
        }
    }

    if (msb_mod_table_exists($dbh, 'public_user_reports')) {
        try {
            $st = $dbh->prepare('
                SELECT reason, status
                FROM public_user_reports
                WHERE target_user_id = :uid
                ORDER BY id DESC
                LIMIT 80
            ');
            $st->execute([':uid' => $userId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $rep) {
                $stt = strtolower((string)($rep['status'] ?? 'pending'));
                if (!isset($reportStatusCounts[$stt])) {
                    $reportStatusCounts[$stt] = 0;
                }
                $reportStatusCounts[$stt]++;
                $reason = strtolower(trim((string)($rep['reason'] ?? 'other')));
                if ($reason === '') {
                    $reason = 'other';
                }
                if (str_contains($reason, 'spam')) {
                    $reason = 'spam';
                } elseif (str_contains($reason, 'harass')) {
                    $reason = 'harassment';
                } elseif (str_contains($reason, 'hate')) {
                    $reason = 'hate';
                }
                $reportReasonCounts[$reason] = ($reportReasonCounts[$reason] ?? 0) + 1;
            }
        } catch (Throwable $e) {
        }
    }
}

$username = trim((string)($form['username'] ?? ''));
$displayName = trim((string)($form['name'] ?? ''));
$email = trim((string)($form['email'] ?? ''));
$mobile = trim((string)($form['mobile'] ?? ''));
$friendCode = trim((string)(($user['friend_code'] ?? '') ?: ''));
$isActive = (int)($form['status'] ?? 1) === 1;
$accountKind = strtolower(trim((string)($form['account_kind'] ?? 'personal')));
$createdAt = (string)(($user['created_at'] ?? '') ?: '');
$iniSrc = $displayName !== '' ? $displayName : ($username !== '' ? $username : 'U');
$parts = preg_split('/\s+/', trim(str_replace(['_', '.', '-', '@'], ' ', $iniSrc))) ?: [];
$ini = strtoupper(mb_substr((string)($parts[0] ?? 'U'), 0, 1) . mb_substr((string)($parts[count($parts) > 1 ? count($parts) - 1 : 0] ?? ''), $parts && count($parts) > 1 ? 0 : 1, 1));
$hash = crc32(strtolower($email !== '' ? $email : $iniSrc));
$palette = ['#2563eb','#7c3aed','#db2777','#ea580c','#16a34a','#0f766e','#0891b2','#475569'];
$avBg = $palette[$hash % count($palette)];

$tier = (string)(($modStatus['status'] ?? '') !== '' ? $modStatus['status'] : ($behavior['tier'] ?? 'normal'));
$tierLabel = $tier === 'high_risk' ? 'High Risk' : ($tier === 'review' ? 'Review' : 'Normal');
$riskScore = (int)($behavior['score'] ?? 0);
$riskPct = (int)min(100, max(8, $riskScore * 12 + ($tier === 'normal' ? 12 : 0)));
$riskDeg = (int)round(($riskPct / 100) * 180);

$mediaUrl = static function (string $path): string {
    $path = trim($path);
    if ($path === '') {
        return '';
    }
    if (preg_match('~^https?://~i', $path)) {
        return $path;
    }
    if ($path[0] === '/') {
        return '..' . $path;
    }
    return '../public_user/' . ltrim($path, '/');
};

$actRows = [
    ['Posts', (int)($activity['posts_7d'] ?? 0), max(1, (int)($activity['posts_24h'] ?? 0))],
    ['Likes', (int)($activity['likes_given_7d'] ?? 0), max(1, (int)round(((int)($activity['likes_given_7d'] ?? 0)) / 2))],
    ['Comments', (int)($activity['comments_given_7d'] ?? 0), max(1, (int)round(((int)($activity['comments_given_7d'] ?? 0)) / 2))],
    ['Follows', (int)($activity['follows_out_7d'] ?? 0), max(1, (int)round(((int)($activity['follows_out_7d'] ?? 0)) / 2))],
    ['Unfollows', max(0, (int)round(((int)($activity['follows_out_7d'] ?? 0)) * 0.35)), 1],
];

$reasonColors = ['spam' => '#ef4444', 'harassment' => '#f97316', 'hate' => '#eab308', 'violence' => '#dc2626', 'other' => '#94a3b8'];
$donutParts = [];
$reasonTotal = array_sum($reportReasonCounts) ?: 1;
$cum = 0;
foreach ($reportReasonCounts as $rk => $rc) {
    $pct = ($rc / $reasonTotal) * 100;
    $donutParts[] = [
        'label' => ucwords(str_replace('_', ' ', (string)$rk)),
        'count' => $rc,
        'color' => $reasonColors[$rk] ?? '#64748b',
        'start' => $cum,
        'end' => $cum + $pct,
    ];
    $cum += $pct;
}
if (!$donutParts) {
    $donutParts[] = ['label' => 'None', 'count' => 0, 'color' => '#e2e8f0', 'start' => 0, 'end' => 100];
}
$donutCss = [];
foreach ($donutParts as $p) {
    $donutCss[] = $p['color'] . ' ' . round($p['start'], 1) . '% ' . round($p['end'], 1) . '%';
}
$donutBg = 'conic-gradient(from -90deg, ' . implode(', ', $donutCss) . ')';

$pageTitle = $isEdit ? ('User · @' . ($username !== '' ? $username : (string)$userId)) : 'New User';
org_admin_render_head($pageTitle);
require_once __DIR__ . '/includes/admin_chrome.php';
admin_chrome_open('Users');

/** Shared account form fields (create + edit modal). */
$renderAccountFields = static function (array $form, array $roles, array $genders, array $pubCategories, string $accountKind, bool $isActive, bool $isEdit): void {
    ?>
    <input type="hidden" name="save_user" value="1">
    <div class="uf-row">
      <div class="uf-field">
        <label>Account type</label>
        <select name="account_kind" id="accountKind">
          <option value="personal" <?= $accountKind === 'personal' ? 'selected' : '' ?>>Personal</option>
          <option value="publisher" <?= $accountKind === 'publisher' ? 'selected' : '' ?>>Publisher</option>
        </select>
      </div>
      <div class="uf-field">
        <label>Status</label>
        <select name="status">
          <option value="1" <?= $isActive ? 'selected' : '' ?>>Confirmed / active</option>
          <option value="0" <?= !$isActive ? 'selected' : '' ?>>Unconfirmed / disabled</option>
        </select>
      </div>
    </div>
    <div class="uf-field">
      <label>Full name <span class="req">*</span></label>
      <input type="text" name="name" required maxlength="100" value="<?= org_admin_h($form['name'] ?? '') ?>">
    </div>
    <div class="uf-row">
      <div class="uf-field">
        <label>Username <span class="req">*</span></label>
        <input type="text" name="username" required maxlength="50" value="<?= org_admin_h($form['username'] ?? '') ?>">
      </div>
      <div class="uf-field">
        <label>Email <span class="req">*</span></label>
        <input type="email" name="email" required maxlength="100" value="<?= org_admin_h($form['email'] ?? '') ?>">
      </div>
    </div>
    <div class="uf-field">
      <label>Password <?php if ($isEdit): ?><span class="hint">(blank = keep)</span><?php else: ?><span class="req">*</span><?php endif; ?></label>
      <input type="password" name="password" <?= $isEdit ? '' : 'required' ?> minlength="6" autocomplete="new-password" placeholder="<?= $isEdit ? 'Unchanged if empty' : 'Min 6 characters' ?>">
    </div>
    <div class="uf-row personal-only">
      <div class="uf-field">
        <label>Gender <span class="req personal-req">*</span></label>
        <select name="gender" id="genderField">
          <option value="">— Select —</option>
          <?php foreach ($genders as $g): ?>
            <option value="<?= org_admin_h($g) ?>" <?= ($form['gender'] ?? '') === $g ? 'selected' : '' ?>><?= org_admin_h($g) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="uf-field">
        <label>Phone <span class="req personal-req">*</span></label>
        <input type="text" name="mobile" maxlength="50" value="<?= org_admin_h($form['mobile'] ?? '') ?>">
      </div>
    </div>
    <div class="uf-row">
      <div class="uf-field">
        <label>Designation</label>
        <input type="text" name="designation" maxlength="255" value="<?= org_admin_h($form['designation'] ?? '') ?>">
      </div>
      <div class="uf-field personal-only">
        <label>Birthday</label>
        <input type="date" name="birthday" value="<?= org_admin_h($form['birthday'] ?? '') ?>">
      </div>
    </div>
    <div class="uf-field">
      <label>Role</label>
      <select name="role">
        <?php foreach ($roles as $r): ?>
          <option value="<?= (int)$r['idrole'] ?>" <?= (int)($form['role'] ?? 4) === (int)$r['idrole'] ? 'selected' : '' ?>><?= org_admin_h($r['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="publisher-only">
      <div class="uf-row">
        <div class="uf-field">
          <label>Publisher category <span class="req">*</span></label>
          <select name="publisher_category">
            <?php foreach ($pubCategories as $key => $label): ?>
              <option value="<?= org_admin_h($key) ?>" <?= ($form['publisher_category'] ?? 'news') === $key ? 'selected' : '' ?>><?= org_admin_h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="uf-field">
          <label>Publisher tagline</label>
          <input type="text" name="publisher_tagline" maxlength="255" value="<?= org_admin_h($form['publisher_tagline'] ?? '') ?>">
        </div>
      </div>
    </div>
    <?php
};
?>

<style>
  .sh-mainpanel > .sh-pagebody{
    overflow:hidden !important;display:flex !important;flex-direction:column !important;min-height:0 !important;
    padding-top:4px !important;padding-bottom:4px !important;
  }
  .uf-wrap{
    flex:1 1 auto;min-height:0;height:100%;width:100%;max-width:100%;
    display:flex;flex-direction:column;gap:5px;overflow:hidden;padding:0 2px;box-sizing:border-box;
  }
  .uf-btn{
    height:24px;padding:0 8px;border-radius:6px;border:1px solid #e2e8f0;background:#fff;
    font-size:10px;font-weight:700;color:#334155;display:inline-flex;align-items:center;gap:4px;
    text-decoration:none;cursor:pointer;white-space:nowrap;
  }
  .uf-btn:hover{background:#f8fafc;text-decoration:none;color:#0f172a;}
  .uf-btn.primary{background:#2563eb;border-color:#2563eb;color:#fff;}
  .uf-btn.primary:hover{background:#1d4ed8;color:#fff;}
  .uf-btn.sm{height:20px;padding:0 6px;font-size:9px;}
  .uf-btn.is-disabled{opacity:.45;pointer-events:none;}

  .uf-hero{
    flex:0 0 auto;background:#fff;border:1px solid #eef2f7;border-radius:8px;padding:6px 10px;
    display:flex;align-items:flex-start;justify-content:space-between;gap:8px;min-width:0;
  }
  .uf-hero-left{display:flex;gap:8px;min-width:0;align-items:flex-start;flex:1 1 auto;}
  .uf-av{width:40px;height:40px;border-radius:999px;color:#fff;font-weight:800;font-size:13px;display:flex;align-items:center;justify-content:center;flex:0 0 40px;}
  .uf-hero h1{margin:0;font-size:14px;font-weight:800;color:#0f172a;line-height:1.15;display:inline-flex;align-items:center;gap:5px;}
  .uf-hero .name{font-size:11px;color:#64748b;font-weight:600;margin-top:1px;display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
  .uf-meta{margin-top:3px;display:grid;grid-template-columns:1fr 1fr;gap:1px 12px;}
  .uf-meta-row{display:flex;align-items:center;gap:5px;font-size:10px;color:#475569;font-weight:600;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .uf-meta-row i{width:12px;color:#94a3b8;text-align:center;font-size:10px;flex:0 0 auto;}
  .uf-hero-actions{display:flex;gap:5px;flex-wrap:wrap;align-items:center;justify-content:flex-end;}

  .uf-badge{display:inline-flex;align-items:center;gap:3px;padding:1px 6px;border-radius:999px;font-size:9px;font-weight:800;}
  .uf-badge.ok,.uf-badge.green{background:#dcfce7;color:#15803d;}
  .uf-badge.bad{background:#fee2e2;color:#b91c1c;}
  .uf-badge.warn{background:#ffedd5;color:#c2410c;}
  .uf-badge.blue{background:#dbeafe;color:#1d4ed8;}
  .uf-badge.gray{background:#f1f5f9;color:#475569;}

  .uf-metrics{flex:0 0 auto;display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:5px;min-width:0;}
  .uf-metric{background:#fff;border:1px solid #eef2f7;border-radius:8px;padding:5px 8px;min-width:0;overflow:hidden;}
  .uf-metric-top{display:flex;align-items:center;justify-content:space-between;gap:4px;margin-bottom:1px;}
  .uf-metric .lab{font-size:9px;font-weight:700;color:#64748b;}
  .uf-metric .val{font-size:14px;font-weight:800;color:#0f172a;line-height:1;}
  .uf-mico{width:16px;height:16px;border-radius:999px;display:flex;align-items:center;justify-content:center;font-size:8px;flex:0 0 auto;}
  .uf-mico.purple{background:#f5f3ff;color:#7c3aed;}
  .uf-mico.blue{background:#dbeafe;color:#2563eb;}
  .uf-mico.green{background:#dcfce7;color:#16a34a;}
  .uf-mico.orange{background:#ffedd5;color:#ea580c;}
  .uf-mico.yellow{background:#fef9c3;color:#ca8a04;}
  .uf-mico.red{background:#fee2e2;color:#dc2626;}

  .uf-summary{
    flex:0 0 auto;background:#fff;border:1px solid #eef2f7;border-radius:8px;padding:5px 10px;
    display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:6px;min-width:0;
  }
  .uf-sum-item{min-width:0;overflow:hidden;}
  .uf-sum-item .k{font-size:8px;font-weight:800;color:#94a3b8;text-transform:uppercase;display:flex;justify-content:space-between;gap:4px;}
  .uf-sum-item .k a{color:#2563eb;font-weight:700;text-transform:none;text-decoration:none;}
  .uf-sum-item .v{font-size:10px;font-weight:700;color:#0f172a;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}

  .uf-tabs{
    flex:0 0 auto;display:flex;gap:0;background:#fff;border:1px solid #eef2f7;border-radius:8px;
    padding:0 4px;overflow:hidden;min-width:0;
  }
  .uf-tabs a{
    flex:0 0 auto;padding:5px 8px;font-size:10px;font-weight:700;color:#64748b;text-decoration:none;
    border-bottom:2px solid transparent;white-space:nowrap;
  }
  .uf-tabs a.is-active{color:#2563eb;border-bottom-color:#2563eb;}
  .uf-tabs a:hover{color:#0f172a;text-decoration:none;}

  .uf-board{
    flex:1 1 auto;min-height:0;min-width:0;
    display:grid;grid-template-columns:minmax(0,1.2fr) minmax(0,.85fr) minmax(0,.9fr);
    gap:5px;overflow:hidden;
  }
  .uf-col{min-height:0;min-width:0;display:flex;flex-direction:column;gap:5px;overflow:hidden;}
  .uf-card{
    background:#fff;border:1px solid #eef2f7;border-radius:8px;overflow:hidden;min-width:0;min-height:0;
    display:flex;flex-direction:column;
  }
  .uf-card.flex{flex:1 1 auto;}
  .uf-card-hd{
    flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;gap:6px;
    padding:4px 8px;border-bottom:1px solid #f1f5f9;
  }
  .uf-card-hd h2{margin:0;font-size:11px;font-weight:800;color:#0f172a;}
  .uf-card-bd{flex:1 1 auto;min-height:0;padding:6px 8px;overflow:hidden;}
  .uf-card-bd.scroll{overflow:auto;overscroll-behavior:contain;}

  .uf-act-row{display:flex;align-items:center;justify-content:space-between;gap:6px;padding:2px 0;border-bottom:1px solid #f8fafc;font-size:10px;}
  .uf-act-row:last-child{border-bottom:0;}
  .uf-act-row .lab{color:#64748b;font-weight:700;}
  .uf-act-row .val{font-weight:800;color:#0f172a;display:inline-flex;align-items:center;gap:5px;}
  .uf-chg{font-size:8px;font-weight:800;padding:1px 4px;border-radius:999px;}
  .uf-chg.up{background:#fee2e2;color:#b91c1c;}
  .uf-chg.down{background:#dcfce7;color:#15803d;}
  .uf-chg.flat{background:#f1f5f9;color:#64748b;}
  .uf-spark{width:34px;height:11px;display:inline-block;vertical-align:middle;}

  .uf-mini{width:100%;border-collapse:collapse;font-size:9px;table-layout:fixed;}
  .uf-mini th{text-align:left;font-size:8px;text-transform:uppercase;color:#94a3b8;padding:0 0 3px;border-bottom:1px solid #f1f5f9;}
  .uf-mini td{padding:3px 3px 3px 0;border-bottom:1px solid #f8fafc;vertical-align:middle;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#334155;}
  .uf-post{display:flex;align-items:center;gap:5px;min-width:0;}
  .uf-thumb{width:20px;height:20px;border-radius:4px;object-fit:cover;background:#e2e8f0;flex:0 0 20px;}
  .uf-thumb.ph{display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:8px;}
  .uf-eng{display:inline-flex;gap:5px;font-size:8px;font-weight:700;color:#64748b;}
  .uf-eng i{margin-right:2px;}

  .uf-tl{position:relative;padding-left:12px;}
  .uf-tl::before{content:'';position:absolute;left:3px;top:2px;bottom:2px;width:2px;background:#e2e8f0;}
  .uf-tl-item{position:relative;padding:0 0 6px 7px;}
  .uf-tl-item:last-child{padding-bottom:0;}
  .uf-tl-dot{
    position:absolute;left:-12px;top:1px;width:10px;height:10px;border-radius:999px;background:#fff;
    border:2px solid #a78bfa;display:flex;align-items:center;justify-content:center;font-size:5px;color:#7c3aed;
  }
  .uf-tl-dot.pink{border-color:#f9a8d4;color:#db2777;}
  .uf-tl-dot.blue{border-color:#93c5fd;color:#2563eb;}
  .uf-tl-dot.orange{border-color:#fdba74;color:#ea580c;}
  .uf-tl-when{font-size:8px;color:#94a3b8;font-weight:700;}
  .uf-tl-text{font-size:10px;font-weight:700;color:#0f172a;}

  .uf-note{
    background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:5px 7px;margin-bottom:5px;
    font-size:10px;color:#78350f;line-height:1.3;
  }
  .uf-note:last-child{margin-bottom:0;}
  .uf-note .meta{font-size:8px;font-weight:700;color:#a16207;margin-bottom:2px;}

  .uf-risk-top{display:flex;align-items:center;justify-content:space-between;gap:6px;margin-bottom:4px;}
  .uf-risk-top .lab{font-size:9px;font-weight:700;color:#64748b;}
  .uf-gauge{
    width:84px;height:42px;margin:0 auto 4px;position:relative;
    background:conic-gradient(from 180deg, #22c55e 0deg, #eab308 90deg, #ef4444 180deg, transparent 180deg);
    border-radius:84px 84px 0 0;overflow:hidden;
  }
  .uf-gauge::after{
    content:'';position:absolute;left:9px;right:9px;top:9px;bottom:0;background:#fff;border-radius:70px 70px 0 0;
  }
  .uf-gauge-needle{
    position:absolute;left:50%;bottom:0;width:2px;height:34px;background:#0f172a;transform-origin:bottom center;
    transform:translateX(-50%) rotate(var(--needle, -90deg));z-index:2;border-radius:2px;
  }
  .uf-gauge-score{text-align:center;font-size:11px;font-weight:800;color:#0f172a;margin-top:-2px;}
  .uf-flags{display:flex;flex-direction:column;gap:2px;max-height:52px;overflow:hidden;}
  .uf-flag{display:flex;align-items:center;gap:5px;font-size:9px;font-weight:700;color:#334155;}
  .uf-flag .dot{width:6px;height:6px;border-radius:999px;flex:0 0 auto;}
  .uf-flag .dot.bad{background:#dc2626;}
  .uf-flag .dot.ok{background:#16a34a;}
  .uf-flag .dot.warn{background:#ea580c;}

  .uf-rep-grid{display:grid;grid-template-columns:1fr 64px;gap:6px;align-items:center;}
  .uf-rep-row{display:flex;justify-content:space-between;font-size:9px;font-weight:700;padding:1px 0;color:#475569;}
  .uf-donut{
    width:64px;height:64px;border-radius:999px;background:var(--donut,#e2e8f0);
    position:relative;flex:0 0 64px;
  }
  .uf-donut::after{content:'';position:absolute;inset:14px;border-radius:999px;background:#fff;}
  .uf-legend{display:flex;flex-wrap:wrap;gap:3px 7px;margin-top:3px;}
  .uf-legend span{font-size:8px;font-weight:700;color:#64748b;display:inline-flex;align-items:center;gap:3px;}
  .uf-legend i{width:6px;height:6px;border-radius:999px;display:inline-block;}

  .uf-quick{display:grid;grid-template-columns:1fr 1fr;gap:4px;}
  .uf-qbtn{
    border:1px solid #e2e8f0;border-radius:6px;padding:7px 4px;background:#fff;text-align:center;
    font-size:9px;font-weight:800;color:#334155;text-decoration:none;display:flex;flex-direction:column;align-items:center;gap:2px;
  }
  .uf-qbtn i{font-size:11px;}
  .uf-qbtn:hover{text-decoration:none;background:#f8fafc;}
  .uf-qbtn.green{border-color:#bbf7d0;background:#f0fdf4;color:#166534;}
  .uf-qbtn.orange{border-color:#fed7aa;background:#fff7ed;color:#c2410c;}
  .uf-qbtn.red{border-color:#fecaca;background:#fef2f2;color:#b91c1c;}
  .uf-qbtn.purple{border-color:#ddd6fe;background:#f5f3ff;color:#6d28d9;}

  .uf-form{display:flex;flex-direction:column;gap:6px;min-height:0;}
  .uf-row{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:6px;}
  .uf-field label{display:block;font-size:9px;font-weight:800;color:#64748b;margin:0 0 2px;}
  .uf-field .req{color:#dc2626;}
  .uf-field .hint{font-weight:600;color:#94a3b8;}
  .uf-field input,.uf-field select{
    width:100%;max-width:100%;height:28px;border:1px solid #e2e8f0;border-radius:6px;padding:0 7px;
    font-size:11px;color:#0f172a;background:#fff;box-sizing:border-box;
  }
  .uf-actions{display:flex;justify-content:flex-end;gap:5px;margin-top:8px;}
  .publisher-only{display:none;}
  body.is-publisher .publisher-only{display:block;}
  body.is-publisher .personal-only .personal-req{display:none;}
  .uf-alert{flex:0 0 auto;padding:4px 8px;border-radius:6px;font-size:11px;font-weight:700;}
  .uf-alert.bad{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
  .uf-alert.ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
  .uf-drop{position:relative;}
  .uf-drop-menu{
    display:none;position:absolute;right:0;top:calc(100% + 4px);z-index:30;min-width:150px;
    background:#fff;border:1px solid #e2e8f0;border-radius:8px;box-shadow:0 8px 20px rgba(15,23,42,.12);padding:4px;
  }
  .uf-drop.open .uf-drop-menu{display:block;}
  .uf-drop-menu a,.uf-drop-menu button{
    display:block;width:100%;text-align:left;padding:6px 8px;border-radius:6px;font-size:11px;font-weight:700;
    color:#334155;text-decoration:none;border:0;background:transparent;cursor:pointer;
  }
  .uf-drop-menu a:hover,.uf-drop-menu button:hover{background:#f8fafc;}
  .uf-empty{padding:6px 4px;text-align:center;color:#64748b;font-size:10px;}

  .uf-modal{
    display:none;position:fixed;inset:0;z-index:80;background:rgba(15,23,42,.4);
    align-items:center;justify-content:center;padding:16px;
  }
  .uf-modal.open{display:flex;}
  .uf-modal-panel{
    width:min(560px,100%);max-height:min(86vh,640px);background:#fff;border-radius:10px;
    border:1px solid #e2e8f0;box-shadow:0 20px 40px rgba(15,23,42,.2);
    display:flex;flex-direction:column;overflow:hidden;
  }
  .uf-modal-hd{flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;padding:10px 12px;border-bottom:1px solid #f1f5f9;}
  .uf-modal-hd h3{margin:0;font-size:13px;font-weight:800;}
  .uf-modal-bd{flex:1 1 auto;overflow:auto;padding:12px;min-height:0;}

  @media (max-width:1100px){
    .uf-wrap{overflow:auto;}
    .uf-board,.uf-metrics,.uf-summary,.uf-meta,.uf-row,.uf-quick,.uf-rep-grid{grid-template-columns:1fr;}
    .uf-col,.uf-card{overflow:visible;max-height:none;}
  }
</style>

<div class="sh-mainpanel">
  <div class="sh-pagebody">
    <div class="uf-wrap">
      <?php if ($error !== ''): ?><div class="uf-alert bad"><?= org_admin_h($error) ?></div><?php endif; ?>
      <?php if ($msg !== ''): ?><div class="uf-alert ok"><?= org_admin_h($msg) ?></div><?php endif; ?>

      <section class="uf-hero">
        <div class="uf-hero-left">
          <div class="uf-av" style="background:<?= org_admin_h($avBg) ?>;"><?= org_admin_h($ini) ?></div>
          <div style="min-width:0;">
            <h1>
              <?php if ($isEdit && $username !== ''): ?>@<?= org_admin_h($username) ?><?php elseif ($isEdit): ?>User #<?= (int)$userId ?><?php else: ?>New User<?php endif; ?>
              <?php if ($isEdit): ?><i class="fa fa-check-circle" style="color:#2563eb;font-size:12px;" title="Account"></i><?php endif; ?>
            </h1>
            <div class="name">
              <?= org_admin_h($displayName !== '' ? $displayName : ($isEdit ? '—' : 'Create public account')) ?>
              <?php if ($isEdit): ?><span class="uf-badge <?= $isActive ? 'ok' : 'bad' ?>"><?= $isActive ? 'Active' : 'Blocked' ?></span><?php endif; ?>
            </div>
            <div class="uf-meta">
              <?php if ($isEdit): ?>
                <div class="uf-meta-row"><i class="fa fa-calendar"></i> Member since <?= $createdAt !== '' ? org_admin_h(org_admin_fmt_dt($createdAt)) : '—' ?></div>
                <div class="uf-meta-row"><i class="fa fa-map-marker"></i> —</div>
                <div class="uf-meta-row"><i class="fa fa-envelope"></i> <?= org_admin_h($email !== '' ? $email : '—') ?> <?php if ($email !== ''): ?><span class="uf-badge green">Verified</span><?php endif; ?></div>
                <div class="uf-meta-row"><i class="fa fa-hashtag"></i> User ID #<?= (int)$userId ?><?= $friendCode !== '' ? ' · ' . org_admin_h($friendCode) : '' ?></div>
              <?php else: ?>
                <div class="uf-meta-row"><i class="fa fa-info-circle"></i> Fill the form below — no page scroll on desktop</div>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <div class="uf-hero-actions">
          <?php if ($isEdit): ?>
            <div class="uf-drop" id="ufActionsDrop">
              <button type="button" class="uf-btn" onclick="document.getElementById('ufActionsDrop').classList.toggle('open')"><i class="fa fa-ellipsis-v"></i> Actions</button>
              <div class="uf-drop-menu">
                <button type="button" onclick="ufOpenEdit()">Edit account fields</button>
                <a href="user_activity.php?user_id=<?= (int)$userId ?>">User Activity</a>
                <a href="reports.php?q=<?= rawurlencode((string)$userId) ?>">Reports</a>
              </div>
            </div>
            <a class="uf-btn is-disabled" href="#" title="Coming soon"><i class="fa fa-envelope"></i> Message User</a>
            <a class="uf-btn" href="<?= org_admin_h(user_admin_public_profile_href($userId)) ?>" target="_blank" rel="noopener"><i class="fa fa-external-link"></i> View Public Profile</a>
          <?php endif; ?>
          <a class="uf-btn primary" href="userlist.php"><i class="fa fa-angle-left"></i> Back to Users</a>
        </div>
      </section>

      <div class="uf-metrics">
        <div class="uf-metric"><div class="uf-metric-top"><span class="lab">Posts</span><span class="uf-mico purple"><i class="fa fa-file-text-o"></i></span></div><div class="val"><?= number_format($postsTotal) ?></div></div>
        <div class="uf-metric"><div class="uf-metric-top"><span class="lab">Followers</span><span class="uf-mico blue"><i class="fa fa-users"></i></span></div><div class="val"><?= number_format($followers) ?></div></div>
        <div class="uf-metric"><div class="uf-metric-top"><span class="lab">Following</span><span class="uf-mico green"><i class="fa fa-user-plus"></i></span></div><div class="val"><?= number_format($following) ?></div></div>
        <div class="uf-metric"><div class="uf-metric-top"><span class="lab">Reports</span><span class="uf-mico orange"><i class="fa fa-flag"></i></span></div><div class="val"><?= number_format($reportsRecv) ?></div></div>
        <div class="uf-metric"><div class="uf-metric-top"><span class="lab">Warnings</span><span class="uf-mico yellow"><i class="fa fa-exclamation-triangle"></i></span></div><div class="val"><?= number_format($warnings) ?></div></div>
        <div class="uf-metric"><div class="uf-metric-top"><span class="lab">Suspensions</span><span class="uf-mico red"><i class="fa fa-ban"></i></span></div><div class="val"><?= number_format($suspensions) ?></div></div>
      </div>

      <div class="uf-summary">
        <div class="uf-sum-item"><div class="k">Status</div><div class="v"><span class="uf-badge <?= $isActive ? 'ok' : 'bad' ?>"><?= $isActive ? 'Active' : 'Blocked' ?></span></div></div>
        <div class="uf-sum-item"><div class="k">Role</div><div class="v"><?= org_admin_h($roleName !== '' ? $roleName : '—') ?></div></div>
        <div class="uf-sum-item"><div class="k">Account Type</div><div class="v"><?= org_admin_h(ucfirst($accountKind)) ?></div></div>
        <div class="uf-sum-item"><div class="k">Email <?php if ($isEdit): ?><a href="#" onclick="ufOpenEdit();return false;">View All</a><?php endif; ?></div><div class="v"><?= org_admin_h($email !== '' ? $email : '—') ?><?php if ($email !== ''): ?> <span class="uf-badge green">Verified</span><?php endif; ?></div></div>
        <div class="uf-sum-item"><div class="k">Phone</div><div class="v"><?= org_admin_h($mobile !== '' ? $mobile : '—') ?></div></div>
        <div class="uf-sum-item"><div class="k">Last Login</div><div class="v"><?= org_admin_h(!empty($activity['last_login_at']) ? org_admin_fmt_dt((string)$activity['last_login_at']) : '—') ?></div></div>
        <div class="uf-sum-item"><div class="k">Login Locations</div><div class="v"><?= org_admin_h(trim((string)($activity['last_login_ip'] ?? '')) !== '' ? (string)$activity['last_login_ip'] : '—') ?></div></div>
      </div>

      <nav class="uf-tabs">
        <?php if ($isEdit): ?>
          <a href="#" class="is-active">Overview</a>
          <a href="user_activity.php?user_id=<?= (int)$userId ?>">Activity</a>
          <a href="user_activity.php?user_id=<?= (int)$userId ?>">Posts</a>
          <a href="reports.php?q=<?= rawurlencode((string)$userId) ?>">Reports (<?= (int)$reportsRecv ?>)</a>
          <a href="user_activity.php?user_id=<?= (int)$userId ?>">Login &amp; Devices</a>
          <a href="user_activity.php?user_id=<?= (int)$userId ?>">Moderation History</a>
          <a href="#ufNotes">Notes</a>
        <?php else: ?>
          <a href="#ufCreateCard" class="is-active">Create Account</a>
        <?php endif; ?>
      </nav>

      <?php if (!$isEdit): ?>
        <div class="uf-board" style="grid-template-columns:minmax(0,1fr);">
          <section class="uf-card flex" id="ufCreateCard">
            <div class="uf-card-hd"><h2>Create Account</h2></div>
            <div class="uf-card-bd scroll">
              <form method="post" autocomplete="off" class="uf-form">
                <?php $renderAccountFields($form, $roles, $genders, $pubCategories, $accountKind, $isActive, false); ?>
                <div class="uf-actions">
                  <a class="uf-btn" href="userlist.php">Cancel</a>
                  <button type="submit" class="uf-btn primary">Create user</button>
                </div>
              </form>
            </div>
          </section>
        </div>
      <?php else: ?>
      <div class="uf-board">
        <div class="uf-col">
          <section class="uf-card" style="flex:0 0 auto;">
            <div class="uf-card-hd"><h2>Activity Summary</h2></div>
            <div class="uf-card-bd">
              <?php foreach ($actRows as [$lab, $val, $base]):
                [$pct, $dir] = $pctBadge((int)$val, (int)$base);
              ?>
                <div class="uf-act-row">
                  <span class="lab"><?= org_admin_h($lab) ?></span>
                  <span class="val">
                    <svg class="uf-spark" viewBox="0 0 36 12" aria-hidden="true"><polyline fill="none" stroke="<?= $dir === 'down' ? '#16a34a' : ($dir === 'up' ? '#dc2626' : '#94a3b8') ?>" stroke-width="1.5" points="0,8 8,6 14,9 20,4 28,5 36,2"/></svg>
                    <?= (int)$val ?>
                    <?php if ($dir !== 'flat'): ?>
                      <span class="uf-chg <?= org_admin_h($dir) ?>"><?= $dir === 'down' ? '↓' : '↑' ?> <?= (int)$pct ?>%</span>
                    <?php endif; ?>
                  </span>
                </div>
              <?php endforeach; ?>
            </div>
          </section>

          <section class="uf-card flex">
            <div class="uf-card-hd">
              <h2>Recent Posts</h2>
              <a class="uf-btn sm" href="user_activity.php?user_id=<?= (int)$userId ?>">View All</a>
            </div>
            <div class="uf-card-bd scroll">
              <?php if (!$recentPosts): ?>
                <div class="uf-empty">No recent posts.</div>
              <?php else: ?>
                <table class="uf-mini">
                  <thead>
                    <tr>
                      <th style="width:28%;">Post</th>
                      <th style="width:14%;">Type</th>
                      <th style="width:14%;">Visibility</th>
                      <th style="width:18%;">Created</th>
                      <th style="width:18%;">Engage</th>
                      <th style="width:8%;"></th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php foreach ($recentPosts as $p):
                    $pid = (int)($p['id'] ?? 0);
                    $pt = trim((string)($p['text_preview'] ?? ''));
                    $vis = ucfirst(strtolower(trim((string)($p['visibility'] ?? 'public'))));
                    $thumb = '';
                    $ptype = 'Text';
                    foreach (($p['attachments'] ?? []) as $a) {
                      $t = strtolower((string)($a['type'] ?? ''));
                      $fp = (string)($a['file_path'] ?? '');
                      $tp = (string)($a['thumb_path'] ?? '');
                      if ($t === 'video' || stripos($fp, '.mp4') !== false) {
                        $ptype = 'Video';
                        $thumb = $mediaUrl($tp !== '' ? $tp : $fp);
                        break;
                      }
                      if ($t === 'image' || preg_match('~\.(jpe?g|png|gif|webp)$~i', $fp) || preg_match('~\.(jpe?g|png|gif|webp)$~i', $tp)) {
                        $ptype = 'Image';
                        $thumb = $mediaUrl($tp !== '' ? $tp : $fp);
                        break;
                      }
                    }
                    $likes = (int)($postEngagement[$pid]['likes'] ?? 0);
                    $comments = (int)($postEngagement[$pid]['comments'] ?? 0);
                    $preports = (int)($postEngagement[$pid]['reports'] ?? 0);
                  ?>
                    <tr>
                      <td>
                        <div class="uf-post">
                          <?php if ($thumb !== ''): ?><img class="uf-thumb" src="<?= org_admin_h($thumb) ?>" alt=""><?php else: ?><div class="uf-thumb ph"><i class="fa fa-align-left"></i></div><?php endif; ?>
                          <span>#<?= $pid ?> <?= org_admin_h(msb_mod_short_text($pt !== '' ? $pt : '(no text)', 18)) ?></span>
                        </div>
                      </td>
                      <td><?= org_admin_h($ptype) ?></td>
                      <td><?= org_admin_h($vis) ?></td>
                      <td><?= org_admin_h(org_admin_fmt_dt($p['created_at'] ?? '')) ?></td>
                      <td>
                        <span class="uf-eng">
                          <span><i class="fa fa-heart"></i><?= $likes ?></span>
                          <span><i class="fa fa-comment"></i><?= $comments ?></span>
                          <?php if ($preports > 0): ?><span><i class="fa fa-flag"></i><?= $preports ?></span><?php endif; ?>
                        </span>
                      </td>
                      <td><a class="uf-btn sm" href="user_activity.php?user_id=<?= (int)$userId ?>&amp;post_id=<?= $pid ?>">View</a></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              <?php endif; ?>
            </div>
          </section>

          <section class="uf-card" style="flex:0 0 auto;">
            <div class="uf-card-hd"><h2>Previous Moderation Actions</h2></div>
            <div class="uf-card-bd">
              <?php if (!$modStatus): ?>
                <div class="uf-empty">No prior moderation actions.</div>
              <?php else:
                $modLabel = (string)($modStatus['status'] ?? 'normal');
                $modBadge = $modLabel === 'high_risk' ? 'bad' : ($modLabel === 'review' ? 'warn' : 'ok');
                $modText = $modLabel === 'high_risk' ? 'High Risk' : ($modLabel === 'review' ? 'Warning' : 'Cleared');
              ?>
                <table class="uf-mini">
                  <thead><tr><th>Date</th><th>Action</th><th>Reason</th><th>By</th></tr></thead>
                  <tbody>
                    <tr>
                      <td><?= org_admin_h(org_admin_fmt_dt($modStatus['updated_at'] ?? '')) ?></td>
                      <td><span class="uf-badge <?= org_admin_h($modBadge) ?>"><?= org_admin_h($modText) ?></span></td>
                      <td><?= org_admin_h((string)($modStatus['note'] ?? '') !== '' ? msb_mod_short_text((string)$modStatus['note'], 40) : '—') ?></td>
                      <td>#<?= (int)($modStatus['updated_by'] ?? 0) ?></td>
                    </tr>
                  </tbody>
                </table>
              <?php endif; ?>
            </div>
          </section>
        </div>

        <div class="uf-col">
          <section class="uf-card flex">
            <div class="uf-card-hd"><h2>Recent Activity</h2></div>
            <div class="uf-card-bd scroll">
              <?php if (!$timeline): ?>
                <div class="uf-empty">No recent activity.</div>
              <?php else: ?>
                <div class="uf-tl">
                  <?php foreach ($timeline as $ev):
                    $tone = (string)($ev['tone'] ?? 'purple');
                    $dotClass = $tone === 'pink' ? 'pink' : ($tone === 'orange' ? 'orange' : ($tone === 'blue' ? 'blue' : ''));
                  ?>
                    <div class="uf-tl-item">
                      <div class="uf-tl-dot <?= org_admin_h($dotClass) ?>"><i class="fa <?= org_admin_h((string)($ev['icon'] ?? 'fa-circle')) ?>"></i></div>
                      <div class="uf-tl-when"><?= org_admin_h(org_admin_fmt_dt($ev['when_raw'] ?? $ev['when'] ?? '')) ?></div>
                      <div class="uf-tl-text"><?= org_admin_h((string)($ev['text'] ?? '')) ?></div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </section>

          <section class="uf-card flex" id="ufNotes">
            <div class="uf-card-hd">
              <h2>Moderator Notes</h2>
              <a class="uf-btn sm" href="user_activity.php?user_id=<?= (int)$userId ?>">Add Note</a>
            </div>
            <div class="uf-card-bd scroll">
              <?php if (!$modStatus || trim((string)($modStatus['note'] ?? '')) === ''): ?>
                <div class="uf-empty">No moderator notes yet.</div>
              <?php else: ?>
                <div class="uf-note">
                  <div class="meta"><?= org_admin_h(org_admin_fmt_dt($modStatus['updated_at'] ?? '')) ?> · Admin #<?= (int)($modStatus['updated_by'] ?? 0) ?></div>
                  <?= org_admin_h((string)$modStatus['note']) ?>
                </div>
              <?php endif; ?>
            </div>
          </section>
        </div>

        <div class="uf-col">
          <section class="uf-card" style="flex:0 0 auto;">
            <div class="uf-card-hd"><h2>Behavior Indicators</h2></div>
            <div class="uf-card-bd">
              <div class="uf-risk-top">
                <span class="lab">Risk Level</span>
                <span class="uf-badge <?= $tier === 'high_risk' ? 'bad' : ($tier === 'review' ? 'warn' : 'ok') ?>"><?= org_admin_h($tierLabel) ?></span>
              </div>
              <div class="uf-gauge">
                <div class="uf-gauge-needle" style="--needle: <?= (int)($riskDeg - 90) ?>deg;"></div>
              </div>
              <div class="uf-gauge-score"><?= (int)$riskPct ?>/100</div>
              <div class="uf-flags">
                <?php
                  $flags = is_array($behavior['flags'] ?? null) ? $behavior['flags'] : [];
                  foreach (array_slice($flags, 0, 4) as $f):
                    $fl = is_array($f) ? (string)($f['label'] ?? $f['code'] ?? '') : (string)$f;
                    $lv = is_array($f) ? (string)($f['level'] ?? 'warn') : 'warn';
                ?>
                  <div class="uf-flag"><span class="dot <?= $lv === 'ok' ? 'ok' : ($lv === 'bad' || $lv === 'high' ? 'bad' : 'warn') ?>"></span> <?= org_admin_h(msb_mod_short_text($fl, 42)) ?></div>
                <?php endforeach; ?>
              </div>
            </div>
          </section>

          <section class="uf-card" style="flex:0 0 auto;">
            <div class="uf-card-hd"><h2>Reports Summary</h2></div>
            <div class="uf-card-bd">
              <div class="uf-rep-grid">
                <div>
                  <div class="uf-rep-row"><span>Pending</span><span><?= (int)$reportStatusCounts['pending'] ?></span></div>
                  <div class="uf-rep-row"><span>In Progress</span><span><?= (int)$reportStatusCounts['reviewed'] ?></span></div>
                  <div class="uf-rep-row"><span>Resolved</span><span><?= (int)$reportStatusCounts['resolved'] ?></span></div>
                  <div class="uf-rep-row"><span>Dismissed</span><span><?= (int)$reportStatusCounts['dismissed'] ?></span></div>
                </div>
                <div class="uf-donut" style="--donut: <?= org_admin_h($donutBg) ?>;"></div>
              </div>
              <div class="uf-legend">
                <?php foreach ($donutParts as $p): if (($p['label'] ?? '') === 'None') continue; ?>
                  <span><i style="background:<?= org_admin_h((string)$p['color']) ?>;"></i><?= org_admin_h((string)$p['label']) ?> (<?= (int)$p['count'] ?>)</span>
                <?php endforeach; ?>
              </div>
            </div>
          </section>

          <section class="uf-card flex">
            <div class="uf-card-hd"><h2>Moderator Actions</h2></div>
            <div class="uf-card-bd">
              <div class="uf-quick">
                <a class="uf-qbtn green" href="user_activity.php?user_id=<?= (int)$userId ?>"><i class="fa fa-check"></i> No Action</a>
                <a class="uf-qbtn orange" href="user_activity.php?user_id=<?= (int)$userId ?>"><i class="fa fa-exclamation-triangle"></i> Send Warning</a>
                <a class="uf-qbtn red" href="user_activity.php?user_id=<?= (int)$userId ?>"><i class="fa fa-trash"></i> Remove Content</a>
                <a class="uf-qbtn purple" href="userlist.php"><i class="fa fa-lock"></i> Restrict Account</a>
                <a class="uf-qbtn red" href="userlist.php"><i class="fa fa-ban"></i> Suspend Account</a>
                <a class="uf-qbtn" href="reports.php?q=<?= rawurlencode((string)$userId) ?>"><i class="fa fa-ellipsis-h"></i> More Actions</a>
              </div>
            </div>
          </section>
        </div>
      </div>

      <div class="uf-modal<?= $openEdit ? ' open' : '' ?>" id="ufEditModal" role="dialog" aria-modal="true">
        <div class="uf-modal-panel">
          <div class="uf-modal-hd">
            <h3>Edit Account</h3>
            <button type="button" class="uf-btn" onclick="ufCloseEdit()">Close</button>
          </div>
          <div class="uf-modal-bd">
            <form method="post" autocomplete="off" class="uf-form" id="ufSaveForm">
              <?php $renderAccountFields($form, $roles, $genders, $pubCategories, $accountKind, $isActive, true); ?>
              <div class="uf-actions">
                <button type="button" class="uf-btn" onclick="ufCloseEdit()">Cancel</button>
                <button type="submit" class="uf-btn primary">Save changes</button>
              </div>
            </form>
          </div>
        </div>
      </div>
      <?php endif; ?>

    </div>
  </div>
</div>

<script>
(function(){
  var kind = document.getElementById('accountKind');
  function sync(){
    document.body.classList.toggle('is-publisher', kind && kind.value === 'publisher');
  }
  if (kind) {
    kind.addEventListener('change', sync);
    sync();
  }
  document.addEventListener('click', function(e){
    var drop = document.getElementById('ufActionsDrop');
    if (!drop) return;
    if (!drop.contains(e.target)) drop.classList.remove('open');
  });
  window.ufOpenEdit = function(){
    var m = document.getElementById('ufEditModal');
    if (m) m.classList.add('open');
    var d = document.getElementById('ufActionsDrop');
    if (d) d.classList.remove('open');
  };
  window.ufCloseEdit = function(){
    var m = document.getElementById('ufEditModal');
    if (m) m.classList.remove('open');
  };
  var modal = document.getElementById('ufEditModal');
  if (modal) {
    modal.addEventListener('click', function(e){
      if (e.target === modal) ufCloseEdit();
    });
  }
})();
</script>
<?php org_admin_render_foot(); ?>
