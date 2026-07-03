<?php
// /Business_only3/organization/posts.php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';

// ✅ DB connection (org_context.php usually sets $dbh; keep safe fallback)
if (!isset($dbh) || !($dbh instanceof PDO)) {
    require_once __DIR__ . '/../admin/controller.php';
    $controller = new Controller();
    $dbh = $controller->pdo();
}

error_reporting(E_ALL);
ini_set('display_errors', '1');
// ✅ Cross-tab live update: notify feed.php in other tab(s) after deletes/edits
$__feedNotify = $_SESSION['feed_notify'] ?? null;
if ($__feedNotify) {
    unset($_SESSION['feed_notify']);
}


if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
function clamp_int($v, int $min, int $max, int $default): int {
    if (!is_numeric($v)) return $default;
    $n = (int)$v;
    if ($n < $min) return $min;
    if ($n > $max) return $max;
    return $n;
}
function is_managerish(string $role): bool { return in_array($role, ['admin','manager'], true); }
function post_label(string $type): string {
    switch ($type) {
        case 'announcement': return 'Announcement';
        case 'direction':    return 'Direction';
        case 'update':       return 'Update';
        case 'weekly_update': return 'Weekly Update';
        case 'recognition':  return 'Recognition';
        default:             return ucfirst($type);
    }
}

// -------------------- Org --------------------
$orgId = (int)($ORG['id'] ?? 0);
if ($orgId <= 0 && function_exists('orgActiveOrgId')) $orgId = (int)orgActiveOrgId();
if ($orgId <= 0) die('Invalid organization context.');

// -------------------- Column checks (soft delete support) --------------------
function table_has_column(PDO $dbh, string $table, string $col): bool {
    try {
        $st = $dbh->prepare("SHOW COLUMNS FROM `{$table}` LIKE :c");
        $st->execute([':c' => $col]);
        return (bool)$st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return false; }
}
$hasPostState = table_has_column($dbh, 'org_posts', 'post_state');
$hasDeletedAt = table_has_column($dbh, 'org_posts', 'deleted_at');

// -------------------- Hard delete helper (fallback + purge) --------------------
function hard_delete_posts(PDO $dbh, int $orgId, array $ids): void {
    $clean = [];
    foreach ($ids as $v) { $n = (int)$v; if ($n > 0) $clean[] = $n; }
    $clean = array_values(array_unique($clean));
    if (!$clean) return;

    // build placeholders
    $in = implode(',', array_fill(0, count($clean), '?'));

    // helpers
    $tableExists = function(string $table) use ($dbh): bool {
        try {
            $st = $dbh->prepare("SHOW TABLES LIKE :t");
            $st->execute([':t' => $table]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) { return false; }
    };

    $safeExec = function(string $sql, array $params) use ($dbh): void {
        try {
            $dbh->prepare($sql)->execute($params);
        } catch (Throwable $e) {
            // ignore missing tables/columns; FK issues will be handled by ordering + final delete check
        }
    };

    // Try to delete everything in a transaction so either it all purges or we can detect failure.
    $startedTx = false;
    try {
        if (!$dbh->inTransaction()) { $dbh->beginTransaction(); $startedTx = true; }

        // Child tables to purge first (order matters for FK constraints)
        // Attachments (org_id + post_id)
        if ($tableExists('org_post_attachments')) {
            $safeExec("DELETE FROM org_post_attachments WHERE org_id = ? AND post_id IN ($in)", array_merge([$orgId], $clean));
        }

        // Acknowledgements (post_id)
        if ($tableExists('org_post_acknowledgements')) {
            $safeExec("DELETE FROM org_post_acknowledgements WHERE post_id IN ($in)", $clean);
        }

        // Flags (post_id) - present in your private_project.sql
        if ($tableExists('org_post_flags')) {
            $safeExec("DELETE FROM org_post_flags WHERE post_id IN ($in)", $clean);
        }

        // Comments / replies (different projects use different names)
        $maybePostIdTables = [
            'org_post_comments',
            'org_post_replies',
            'org_post_reply',
            'org_post_reply_comments',
            'org_post_reply_attachments',
            'org_post_reactions',
            'org_post_likes',
            'org_post_bookmarks'
        ];
        foreach ($maybePostIdTables as $t) {
            if ($tableExists($t)) {
                $safeExec("DELETE FROM {$t} WHERE post_id IN ($in)", $clean);
            }
        }

        // Some schemas store replies referencing post_id as parent_post_id
        $maybeParentPostIdTables = [
            'org_post_replies',
            'org_post_comments'
        ];
        foreach ($maybeParentPostIdTables as $t) {
            if ($tableExists($t)) {
                $safeExec("DELETE FROM {$t} WHERE parent_post_id IN ($in)", $clean);
            }
        }

        // Finally delete the posts
        $safeExec("DELETE FROM org_posts WHERE org_id = ? AND id IN ($in)", array_merge([$orgId], $clean));

        // Verify purge succeeded (if not, surface an error)
        // NOTE: do NOT swallow the "still exists" case — only ignore verification if the SELECT itself fails.
        $left = null;
        try {
            $stChk = $dbh->prepare("SELECT COUNT(*) FROM org_posts WHERE org_id = ? AND id IN ($in)");
            $stChk->execute(array_merge([$orgId], $clean));
            $left = (int)$stChk->fetchColumn();
        } catch (Throwable $e) {
            $left = null; // unable to verify
        }
        if (is_int($left) && $left > 0) {
            throw new RuntimeException("Final delete failed: {$left} post(s) still exist (likely FK constraints from another table).");
        }

        if ($startedTx) { $dbh->commit(); }
    } catch (Throwable $e) {
        if ($startedTx && $dbh->inTransaction()) { $dbh->rollBack(); }
        throw $e; // bubble up so user sees the real failure reason
    }
}



// ✅ Wrapper to surface final-delete errors nicely in UI
function hard_delete_posts_safe(PDO $dbh, int $orgId, array $ids): ?string {
    try {
        hard_delete_posts($dbh, $orgId, $ids);
        return null;
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}


// -------------------- Resolve session account -> org_members row --------------------
$accountType = function_exists('orgAccountType') ? (string)orgAccountType() : '';
$accountId   = function_exists('orgAccountId') ? (int)orgAccountId() : 0;

if ($accountId <= 0) $accountId = (int)($_SESSION['org_account_id'] ?? 0);
if ($accountType !== 'manager' && $accountType !== 'staff') {
    if (function_exists('isOrgManager') && isOrgManager()) $accountType = 'manager';
    else $accountType = 'staff';
}
if ($accountId <= 0) die('Invalid org session.');

// -------------------- Resolve session membership (trusted) --------------------
$meMemberId = function_exists('orgMemberId') ? (int)orgMemberId() : 0;
$myRoleId   = function_exists('orgRoleId')   ? (int)orgRoleId()   : 0;
$myJoinedAt = '';

if ($meMemberId <= 0) {
    $st = $dbh->prepare("
        SELECT id, role_id, joined_at
        FROM org_members
        WHERE org_id = :org
          AND member_type = :mt
          AND member_id = :mid
        LIMIT 1
    ");
    $st->execute([':org'=>$orgId, ':mt'=>$accountType, ':mid'=>$accountId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $meMemberId = (int)($row['id'] ?? 0);
    $myRoleId   = (int)($row['role_id'] ?? 0);
    $myJoinedAt = (string)($row['joined_at'] ?? '');
}

if ($meMemberId <= 0) {
    header("Location: select_org.php");
    exit;
}

// Resolve role name via org_roles
$meRole = ($accountType === 'manager') ? 'manager' : 'staff';
try {
    if ($myRoleId > 0) {
        $stR = $dbh->prepare("SELECT name FROM org_roles WHERE id = :id AND org_id = :org LIMIT 1");
        $stR->execute([':id'=>$myRoleId, ':org'=>$orgId]);
        $roleName = (string)($stR->fetchColumn() ?: '');
        $roleNameLower = strtolower($roleName);
        if ($roleNameLower === 'manager') $meRole = 'manager';
        elseif ($roleNameLower === 'staff') $meRole = 'staff';
        elseif ($roleNameLower === 'admin') $meRole = 'admin';
    }
} catch (Throwable $e) {
    // keep fallback
}

// -------------------- Self-heal organization_users --------------------
try {
    $stChk = $dbh->prepare("SELECT role FROM organization_users WHERE org_id=:o AND user_id=:u LIMIT 1");
    $stChk->execute([':o'=>$orgId, ':u'=>$meMemberId]);
    $have = (string)($stChk->fetchColumn() ?: '');
    if ($have === '') {
        $ins = $dbh->prepare("
            INSERT INTO organization_users (org_id, user_id, role, joined_at)
            VALUES (:o, :u, :r, NOW())
            ON DUPLICATE KEY UPDATE role = VALUES(role)
        ");
        $ins->execute([':o'=>$orgId, ':u'=>$meMemberId, ':r'=>$meRole]);
    } else {
        $meRole = $have;
    }
} catch (Throwable $e) {
    // ignore
}

// -------------------- Resolve my fullname --------------------
$myFullname = 'Member';
try {
    $stN = $dbh->prepare("
        SELECT COALESCE(m.fullname, s.fullname, 'Member') AS fullname
        FROM org_members om
        LEFT JOIN managers m
          ON om.member_type = 'manager' AND m.id = om.member_id
        LEFT JOIN staff_accounts s
          ON om.member_type = 'staff' AND s.id = om.member_id
        WHERE om.org_id = :org AND om.id = :omid
        LIMIT 1
    ");
    $stN->execute([':org'=>$orgId, ':omid'=>$meMemberId]);
    $myFullname = (string)($stN->fetchColumn() ?: 'Member');
} catch (Throwable $e) {
    $myFullname = 'Member';
}


// -------------------- Detect attachments table (optional) --------------------
$hasAttachmentsTable = false;
try {
    $stAT = $dbh->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'org_post_attachments'
    ");
    $stAT->execute();
    $hasAttachmentsTable = ((int)($stAT->fetchColumn() ?: 0) > 0);
} catch (Throwable $e) { $hasAttachmentsTable = false; }



// -------------------- Post lifecycle (draft/published/hidden/archived/deleted) --------------------
$canManagePosts = is_managerish($meRole);

// Detect lifecycle columns; try to auto-migrate (manager/admin only)
$hasPostState = false;
$hasDeletedAt = false;

try {
    $st = $dbh->query("SHOW COLUMNS FROM org_posts LIKE 'post_state'");
    $hasPostState = (bool)($st && $st->fetch(PDO::FETCH_ASSOC));
} catch (Throwable $e) { $hasPostState = false; }

try {
    $st = $dbh->query("SHOW COLUMNS FROM org_posts LIKE 'deleted_at'");
    $hasDeletedAt = (bool)($st && $st->fetch(PDO::FETCH_ASSOC));
} catch (Throwable $e) { $hasDeletedAt = false; }

if ($canManagePosts && (!$hasPostState || !$hasDeletedAt)) {
    try {
        if (!$hasPostState) {
            $dbh->exec("ALTER TABLE org_posts ADD COLUMN post_state VARCHAR(16) NOT NULL DEFAULT 'published'");
            $hasPostState = true;
        }
        if (!$hasDeletedAt) {
            $dbh->exec("ALTER TABLE org_posts ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL");
            $hasDeletedAt = true;
        }
    } catch (Throwable $e) {
        // no permission; lifecycle features disabled
    }
}

// Manager-only: handle lifecycle actions (publish/hide/archive/restore/delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManagePosts && $hasPostState) {
    $action = (string)($_POST['action'] ?? '');
    $pid    = (int)($_POST['post_id'] ?? 0);


    // ✅ Bulk / All actions do NOT have post_id; handle them before single-post actions.
    try {
        // ---- Soft delete selected (move to Deleted bin) ----
        if ($action === 'soft_delete_many') {
            $ids = $_POST['post_ids'] ?? [];
            if (!is_array($ids) || count($ids) === 0) throw new RuntimeException('Select at least one post.');

            $clean = [];
            foreach ($ids as $v) { $n = (int)$v; if ($n > 0) $clean[] = $n; }
            $clean = array_values(array_unique($clean));
            if (!$clean) throw new RuntimeException('Select at least one post.');

            if ($hasPostState || $hasDeletedAt) {
                $sets = [];
                if ($hasPostState) $sets[] = "post_state = 'deleted'";
                if ($hasDeletedAt) $sets[] = "deleted_at = NOW()";
                $in = implode(',', array_fill(0, count($clean), '?'));
                $sql = "UPDATE org_posts SET " . implode(', ', $sets) . " WHERE org_id = ? AND id IN ($in)";
                $dbh->prepare($sql)->execute(array_merge([$orgId], $clean));
            } else {
                $err = hard_delete_posts_safe($dbh, $orgId, $clean);
                if ($err !== null) throw new RuntimeException($err);
            }

            $_SESSION['posts_flash_ok'] = 'Selected posts moved to Deleted.';
            $_SESSION['feed_notify'] = ['org_id'=>$orgId,'action'=>'soft_delete','ids'=>$clean,'ts'=>time()];
            header('Location: posts.php');
            exit;
        }

        // ---- FINAL delete selected (PERMANENT) — only purge already-deleted posts ----
        if ($action === 'final_delete_many') {
            $ids = $_POST['post_ids'] ?? [];
            if (!is_array($ids) || count($ids) === 0) throw new RuntimeException('Select at least one post.');

            $clean = [];
            foreach ($ids as $v) { $n = (int)$v; if ($n > 0) $clean[] = $n; }
            $clean = array_values(array_unique($clean));
            if (!$clean) throw new RuntimeException('Select at least one post.');

            // Safety: only purge posts that are already in Deleted bin.
            $purge = [];
            $in = implode(',', array_fill(0, count($clean), '?'));
            if ($hasPostState) {
                $st = $dbh->prepare("SELECT id FROM org_posts WHERE org_id = ? AND id IN ($in) AND post_state = 'deleted'");
                $st->execute(array_merge([$orgId], $clean));
                while ($r = $st->fetch(PDO::FETCH_ASSOC)) { $purge[] = (int)$r['id']; }
            } elseif ($hasDeletedAt) {
                $st = $dbh->prepare("SELECT id FROM org_posts WHERE org_id = ? AND id IN ($in) AND deleted_at IS NOT NULL");
                $st->execute(array_merge([$orgId], $clean));
                while ($r = $st->fetch(PDO::FETCH_ASSOC)) { $purge[] = (int)$r['id']; }
            } else {
                $purge = $clean;
            }
            $purge = array_values(array_unique(array_filter($purge)));

            if (!$purge) {
                $_SESSION['posts_flash_ok'] = 'Nothing to permanently delete. Go to the Deleted filter first.';
                header('Location: posts.php?state=deleted');
                exit;
            }

            $err = hard_delete_posts_safe($dbh, $orgId, $purge);
            if ($err !== null) throw new RuntimeException($err);

            $_SESSION['posts_flash_ok'] = 'Selected deleted posts permanently deleted.';
            $_SESSION['feed_notify'] = ['org_id'=>$orgId,'action'=>'final_delete','ids'=>$purge,'ts'=>time()];
            header('Location: posts.php?state=deleted');
            exit;
        }

        // ---- Soft delete ALL (move ALL active posts to Deleted bin) ----
        if ($action === 'soft_delete_all') {
            $ids = [];
            $stIds = $dbh->prepare("SELECT id FROM org_posts WHERE org_id = :org");
            $stIds->execute([':org'=>$orgId]);
            while ($r = $stIds->fetch(PDO::FETCH_ASSOC)) { $ids[] = (int)$r['id']; }

            if ($hasPostState || $hasDeletedAt) {
                $sets = [];
                if ($hasPostState) $sets[] = "post_state = 'deleted'";
                if ($hasDeletedAt) $sets[] = "deleted_at = NOW()";
                $where = "org_id = :org";
                if ($hasPostState) $where .= " AND (post_state IS NULL OR post_state <> 'deleted')";
                if ($hasDeletedAt) $where .= " AND deleted_at IS NULL";
                $sql = "UPDATE org_posts SET " . implode(', ', $sets) . " WHERE " . $where;
                $st = $dbh->prepare($sql);
                $st->execute([':org'=>$orgId]);
            } else {
                $err = hard_delete_posts_safe($dbh, $orgId, $ids);
                if ($err !== null) throw new RuntimeException($err);
            }

            $_SESSION['posts_flash_ok'] = 'All posts moved to Deleted.';
            $_SESSION['feed_notify'] = ['org_id'=>$orgId,'action'=>'soft_delete','ids'=>$ids,'ts'=>time()];
            header('Location: posts.php');
            exit;
        }

        // ---- FINAL delete ALL deleted (purge Deleted bin only) ----
        if ($action === 'final_delete_all') {
            $purge = [];
            if ($hasPostState) {
                $st = $dbh->prepare("SELECT id FROM org_posts WHERE org_id = :org AND post_state = 'deleted'");
                $st->execute([':org'=>$orgId]);
                while ($r = $st->fetch(PDO::FETCH_ASSOC)) { $purge[] = (int)$r['id']; }
            } elseif ($hasDeletedAt) {
                $st = $dbh->prepare("SELECT id FROM org_posts WHERE org_id = :org AND deleted_at IS NOT NULL");
                $st->execute([':org'=>$orgId]);
                while ($r = $st->fetch(PDO::FETCH_ASSOC)) { $purge[] = (int)$r['id']; }
            }

            if (!$purge) {
                $_SESSION['posts_flash_ok'] = 'Nothing to permanently delete in Deleted.';
                header('Location: posts.php?state=deleted');
                exit;
            }

            $err = hard_delete_posts_safe($dbh, $orgId, $purge);
            if ($err !== null) throw new RuntimeException($err);

            $_SESSION['posts_flash_ok'] = 'All deleted posts permanently deleted.';
            $_SESSION['feed_notify'] = ['org_id'=>$orgId,'action'=>'final_delete','ids'=>$purge,'ts'=>time()];
            header('Location: posts.php?state=deleted');
            exit;
        }
    } catch (Throwable $e) {
        $_SESSION['posts_flash_err'] = $e->getMessage();
        header('Location: posts.php');
        exit;
    }


    if ($pid > 0) {
        try {
            if ($action === 'set_state') {
                $newState = strtolower(trim((string)($_POST['new_state'] ?? '')));
                $allowed  = ['draft','published','hidden','archived'];
                if (!in_array($newState, $allowed, true)) {
                    throw new RuntimeException('Invalid state.');
                }

                $st = $dbh->prepare("
                    UPDATE org_posts
                       SET post_state = :st,
                           deleted_at = NULL
                     WHERE id = :id AND org_id = :org
                     LIMIT 1
                ");
                $st->execute([':st'=>$newState, ':id'=>$pid, ':org'=>$orgId]);

                $_SESSION['posts_flash_ok'] = 'Post updated.';
                $_SESSION['feed_notify'] = ['org_id'=>$orgId,'action'=>'edit','ids'=>[$pid],'ts'=>time()];
                header('Location: posts.php');
                exit;
            }

            if ($action === 'soft_delete') {
                if ($hasPostState || $hasDeletedAt) {
                    $sets = [];
                    if ($hasPostState) $sets[] = "post_state = 'deleted'";
                    if ($hasDeletedAt) $sets[] = "deleted_at = NOW()";
                    $sql = "UPDATE org_posts SET " . implode(', ', $sets) . " WHERE id = :id AND org_id = :org LIMIT 1";
                    $st = $dbh->prepare($sql);
                    $st->execute([':id'=>$pid, ':org'=>$orgId]);
                } else {
                    // Hard delete fallback if soft-delete columns do not exist
                    $err = hard_delete_posts_safe($dbh, $orgId, [$pid]);
                if ($err !== null) {
                    $_SESSION['posts_flash_err'] = $err;
                    header('Location: posts.php');
                    exit;
                }
                }

                $_SESSION['posts_flash_ok'] = 'Post deleted.';
                $_SESSION['feed_notify'] = ['org_id'=>$orgId,'action'=>'soft_delete','ids'=>[$pid],'ts'=>time()];
                header('Location: posts.php');
                exit;
            }

            // ✅ Final delete (permanent)
            if ($action === 'final_delete') {
                $err = hard_delete_posts_safe($dbh, $orgId, [$pid]);
                if ($err !== null) {
                    $_SESSION['posts_flash_err'] = $err;
                    header('Location: posts.php');
                    exit;
                }

                $_SESSION['posts_flash_ok'] = 'Post permanently deleted.';
                $_SESSION['feed_notify'] = ['org_id'=>$orgId,'action'=>'final_delete','ids'=>[$pid],'ts'=>time()];
                header('Location: posts.php');
                exit;
            }

            if ($action === 'restore') {
                $st = $dbh->prepare("
                    UPDATE org_posts
                      SET post_state = 'published',
                          deleted_at = NULL
                    WHERE id = :id AND org_id = :org
                    LIMIT 1
                ");
                $st->execute([':id'=>$pid, ':org'=>$orgId]);

                $_SESSION['posts_flash_ok'] = 'Post restored.';
                // ✅ notify feed.php so sidebar can refresh
                $_SESSION['feed_notify'] = ['org_id'=>$orgId,'action'=>'restore','ids'=>[$pid],'ts'=>time()];
                header('Location: posts.php?state=deleted'); // (you are using state now)
                exit;
              }

        

            // ✅ Bulk delete selected posts
            if ($action === 'soft_delete_many') {
                $ids = $_POST['post_ids'] ?? [];
                if (!is_array($ids) || count($ids) === 0) {
                    throw new RuntimeException('Select at least one post.');
                }
                $clean = [];
                foreach ($ids as $v) { $n = (int)$v; if ($n > 0) $clean[] = $n; }
                $clean = array_values(array_unique($clean));
                if (!$clean) throw new RuntimeException('Select at least one post.');

                if ($hasPostState || $hasDeletedAt) {
                    $sets = [];
                    if ($hasPostState) $sets[] = "post_state = 'deleted'";
                    if ($hasDeletedAt) $sets[] = "deleted_at = NOW()";
                    $in = implode(',', array_fill(0, count($clean), '?'));
                    $sql = "UPDATE org_posts SET " . implode(', ', $sets) . " WHERE org_id = ? AND id IN ($in)";
                    $dbh->prepare($sql)->execute(array_merge([$orgId], $clean));
                } else {
                    $err = hard_delete_posts_safe($dbh, $orgId, $clean);
                if ($err !== null) {
                    $_SESSION['posts_flash_err'] = $err;
                    header('Location: posts.php');
                    exit;
                }
                }

                $_SESSION['posts_flash_ok'] = 'Selected posts deleted.';
                $_SESSION['feed_notify'] = ['org_id'=>$orgId,'action'=>'soft_delete','ids'=>$clean,'ts'=>time()];
                header('Location: posts.php');
                exit;
            }

            // ✅ Final delete selected posts (permanent)
            if ($action === 'final_delete_many') {
                $ids = $_POST['post_ids'] ?? [];
                if (!is_array($ids) || count($ids) === 0) {
                    throw new RuntimeException('Select at least one post.');
                }
                $clean = [];
                foreach ($ids as $v) { $n = (int)$v; if ($n > 0) $clean[] = $n; }
                $clean = array_values(array_unique($clean));
                if (!$clean) throw new RuntimeException('Select at least one post.');

                $err = hard_delete_posts_safe($dbh, $orgId, $clean);
                if ($err !== null) {
                    $_SESSION['posts_flash_err'] = $err;
                    header('Location: posts.php');
                    exit;
                }

                $_SESSION['posts_flash_ok'] = 'Selected posts permanently deleted.';
                $_SESSION['feed_notify'] = ['org_id'=>$orgId,'action'=>'final_delete','ids'=>$clean,'ts'=>time()];
                header('Location: posts.php');
                exit;
            }

            // ✅ Delete ALL posts in this org (soft delete)
            if ($action === 'soft_delete_all') {
                // collect ids first (for live sidebar removal)
                $ids = [];
                try {
                    $stIds = $dbh->prepare("SELECT id FROM org_posts WHERE org_id = :org");
                    $stIds->execute([':org'=>$orgId]);
                    while ($r = $stIds->fetch(PDO::FETCH_ASSOC)) { $ids[] = (int)$r['id']; }
                } catch (Throwable $e) { $ids = []; }

                // delete all ACTIVE posts (not already deleted)
if ($hasPostState || $hasDeletedAt) {
                    $sets = [];
                    if ($hasPostState) $sets[] = "post_state = 'deleted'";
                    if ($hasDeletedAt) $sets[] = "deleted_at = NOW()";
                    $where = "org_id = :org";
                    if ($hasPostState) $where .= " AND (post_state IS NULL OR post_state <> 'deleted')";
                    if ($hasDeletedAt) $where .= " AND deleted_at IS NULL";
                    $sql = "UPDATE org_posts SET " . implode(', ', $sets) . " WHERE " . $where;
                    $st = $dbh->prepare($sql);
                    $st->execute([':org' => $orgId]);
                } else {
                    // Hard delete all posts for org
                    $ids = [];
                    $st = $dbh->prepare("SELECT id FROM org_posts WHERE org_id = :org");
                    $st->execute([':org'=>$orgId]);
                    while ($r = $st->fetch(PDO::FETCH_ASSOC)) { $ids[] = (int)$r['id']; }
                    $err = hard_delete_posts_safe($dbh, $orgId, $ids);
                    if ($err !== null) {
                        $_SESSION['posts_flash_err'] = $err;
                        header('Location: posts.php');
                        exit;
                    }
                }

                $_SESSION['posts_flash_ok'] = 'All posts deleted.';
                $_SESSION['feed_notify'] = ['org_id'=>$orgId,'action'=>'soft_delete','ids'=>$ids,'ts'=>time()];
                header('Location: posts.php');
                exit;
            }

            // ✅ Final delete ALL posts in this org (permanent)
            if ($action === 'final_delete_all') {
                // collect ALL post ids for this org
                $ids = [];
                $stAll = $dbh->prepare("SELECT id FROM org_posts WHERE org_id = :org");
                $stAll->execute([':org'=>$orgId]);
                while ($r = $stAll->fetch(PDO::FETCH_ASSOC)) { $ids[] = (int)$r['id']; }

                if (!$ids) {
                    $_SESSION['posts_flash_ok'] = 'No posts to delete.';
                    header('Location: posts.php');
                    exit;
                }

                $err = hard_delete_posts_safe($dbh, $orgId, $ids);
                    if ($err !== null) {
                        $_SESSION['posts_flash_err'] = $err;
                        header('Location: posts.php');
                        exit;
                    }

                $_SESSION['posts_flash_ok'] = 'All posts permanently deleted.';
                $_SESSION['feed_notify'] = ['org_id'=>$orgId,'action'=>'final_delete','ids'=>$ids,'ts'=>time()];
                header('Location: posts.php');
                exit;
            }

            // ✅ Edit post: title/subject, description/body, type, and optionally replace attachment
            if ($action === 'edit_post') {
                $newTitle = trim((string)($_POST['title'] ?? ''));
                $newBody  = trim((string)($_POST['body'] ?? ''));
                $newType  = strtolower(trim((string)($_POST['post_type'] ?? '')));

                $allowedTypes = ['announcement','direction','update','weekly_update','recognition'];
                if ($newType === '' || !in_array($newType, $allowedTypes, true)) {
                    // keep existing type if invalid
                    $newType = '';
                }

                // update text fields
                if ($newType !== '') {
                    $st = $dbh->prepare("
                        UPDATE org_posts
                           SET title = :t,
                               body  = :b,
                               post_type = :pt
                         WHERE id = :id AND org_id = :org
                         LIMIT 1
                    ");
                    $st->execute([':t'=>$newTitle, ':b'=>$newBody, ':pt'=>$newType, ':id'=>$pid, ':org'=>$orgId]);
                } else {
                    $st = $dbh->prepare("
                        UPDATE org_posts
                           SET title = :t,
                               body  = :b
                         WHERE id = :id AND org_id = :org
                         LIMIT 1
                    ");
                    $st->execute([':t'=>$newTitle, ':b'=>$newBody, ':id'=>$pid, ':org'=>$orgId]);
                }

                // optional attachment replace
                if (isset($_FILES['edit_attachment']) && is_array($_FILES['edit_attachment']) && (int)($_FILES['edit_attachment']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                    $tmp  = (string)($_FILES['edit_attachment']['tmp_name'] ?? '');
                    $orig = (string)($_FILES['edit_attachment']['name'] ?? '');
                    $size = (int)($_FILES['edit_attachment']['size'] ?? 0);

                    if ($tmp === '' || !is_uploaded_file($tmp)) {
                        throw new RuntimeException('Upload failed.');
                    }
                    if ($size <= 0) {
                        throw new RuntimeException('Empty file.');
                    }
                    if ($size > 50 * 1024 * 1024) { // 50MB
                        throw new RuntimeException('File too large (max 50MB).');
                    }

                    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
                    $allowedExt = ['jpg','jpeg','png','gif','webp','mp4','mov','webm','pdf','doc','docx','ppt','pptx','xls','xlsx','txt'];
                    if ($ext === '' || !in_array($ext, $allowedExt, true)) {
                        throw new RuntimeException('File type not allowed.');
                    }

                    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
                    $mime  = $finfo ? (string)finfo_file($finfo, $tmp) : '';
                    if ($finfo) finfo_close($finfo);

                    $uploadDirRel = 'uploads/org_posts';
                    $uploadDirAbs = __DIR__ . '/' . $uploadDirRel;
                    if (!is_dir($uploadDirAbs)) {
                        @mkdir($uploadDirAbs, 0775, true);
                    }

                    $safeBase = preg_replace('/[^a-zA-Z0-9_\-]+/', '_', pathinfo($orig, PATHINFO_FILENAME));
                    if ($safeBase === '' || $safeBase === '_') $safeBase = 'file';
                    $fname = 'org_' . $orgId . '_post_' . $pid . '_' . date('Ymd_His') . '_' . $safeBase . '.' . $ext;

                    $destAbs = $uploadDirAbs . '/' . $fname;
                    $destRel = $uploadDirRel . '/' . $fname;

                    if (!@move_uploaded_file($tmp, $destAbs)) {
                        throw new RuntimeException('Could not save uploaded file.');
                    }

                    // Replace: delete existing attachment rows for this post (keep DB clean)
                    try {
                        $dbh->prepare("DELETE FROM org_post_attachments WHERE org_id = :org AND post_id = :pid")
                            ->execute([':org'=>$orgId, ':pid'=>$pid]);
                    } catch (Throwable $e) {
                        // if table/cols differ, ignore
                    }

                    
// Insert new attachment row (schema matches your table)
$ok = false;

$newStoredName   = $fname;      // stored filename
$newOriginalName = $orig;       // original upload name
$newWebPath      = $destRel;    // relative web path
$newMime         = ($mime !== '') ? $mime : 'application/octet-stream';
$newExt          = $ext;
$newSize         = $size;

try {
    $sqlAtt = "INSERT INTO org_post_attachments
        (org_id, post_id, file_name, file_path, mime_type, stored_name, original_name, mime, ext, file_size, created_at)
        VALUES
        (:org, :pid, :fn, :path, :mime_type, :stored, :orig, :mime, :ext, :sz, NOW())";
    $stA = $dbh->prepare($sqlAtt);
    $stA->execute([
        ':org'       => $orgId,
        ':pid'       => $pid,
        ':fn'        => $newOriginalName,  // display name
        ':path'      => $newWebPath,
        ':mime_type' => $newMime,
        ':stored'    => $newStoredName,
        ':orig'      => $newOriginalName,
        ':mime'      => $newMime,
        ':ext'       => $newExt,
        ':sz'        => $newSize,
    ]);
    $ok = true;
} catch (Throwable $e) {
    $ok = false;
}

if (!$ok) {
    // Attachment insert failed: keep post edited, but warn
    $_SESSION['posts_flash_err'] = 'Post updated, but attachment could not be saved to database (org_post_attachments insert failed).';
    header('Location: posts.php');
    exit;
}

                }
                $_SESSION['posts_flash_ok'] = 'Post updated.';
                $_SESSION['feed_notify'] = ['org_id'=>$orgId,'action'=>'edit','ids'=>[$pid],'ts'=>time()];
                header('Location: posts.php');
                exit;
            }

} catch (Throwable $e) {
            $_SESSION['posts_flash_err'] = $e->getMessage();
            header('Location: posts.php');
            exit;
        }
    }
}

// -------------------- Filters --------------------
$tab = (string)($_GET['tab'] ?? 'work'); // work/culture
if ($tab !== 'work' && $tab !== 'culture') $tab = 'work';

$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) > 120) $q = mb_substr($q, 0, 120);

$limit  = clamp_int($_GET['limit'] ?? 10, 5, 30, 10);
$page   = clamp_int($_GET['page'] ?? 1, 1, 999999, 1);
$offset = ($page - 1) * $limit;

$whereType = ($tab === 'culture')
  ? " AND p.post_type = 'recognition' "
  : " AND p.post_type IN ('announcement','direction','update','weekly_update') ";


// lifecycle state filter
$state = (string)($_GET['state'] ?? 'published');
$state = strtolower($state);
if (!$canManagePosts) $state = 'published';

$allowedStates = ['published','draft','hidden','archived','deleted'];
if (!in_array($state, $allowedStates, true)) $state = 'published';

$stateSql = '';
$paramsState = [];
if ($hasPostState) {
    if ($state === 'deleted') {
        $stateSql = " AND p.post_state = 'deleted' ";
        if ($hasDeletedAt) $stateSql .= " AND p.deleted_at IS NOT NULL ";
    } else {
        $stateSql = " AND p.post_state = :pst ";
        $paramsState[':pst'] = $state;
        if ($hasDeletedAt) $stateSql .= " AND p.deleted_at IS NULL ";
    }
} else {
    // Backwards compatible: if lifecycle not installed, hide nothing
    if ($hasDeletedAt) $stateSql .= " AND p.deleted_at IS NULL ";
}


// search clause
$searchSql = '';
$paramsSearch = [];
if ($q !== '') {
    $searchSql = " AND (p.title LIKE :q OR p.body LIKE :q) ";
    $paramsSearch[':q'] = '%' . $q . '%';
}

// -------------------- Fetch list --------------------
$posts = [];
$totalRows = 0;

try {
    // count
    $sqlCount = "
        SELECT COUNT(*)
        FROM org_posts p
        WHERE p.org_id = :org_id
        $whereType
        $stateSql
        $searchSql
    ";
    $stCnt = $dbh->prepare($sqlCount);
    $stCnt->execute(array_merge([':org_id'=>$orgId], $paramsState, $paramsSearch));
    $totalRows = (int)($stCnt->fetchColumn() ?: 0);

    // list

    $stateSelect = $hasPostState ? "p.post_state, p.deleted_at," : "'published' AS post_state, NULL AS deleted_at,";
    $sql = "
        SELECT
          p.id, $stateSelect p.post_type, p.title, p.body, p.comments_locked, p.created_at,
          COALESCE(
            m.fullname,
            s.fullname,
            CONCAT(UPPER(LEFT(om.member_type,1)), SUBSTRING(om.member_type,2), ' #', om.member_id)
          ) AS author_name,
          (SELECT COUNT(*) FROM org_post_comments c WHERE c.post_id = p.id) AS comment_count,
          (SELECT COUNT(*) FROM org_post_acknowledgements a WHERE a.post_id = p.id) AS ack_count,
          (CASE WHEN :has_att = 1 THEN (SELECT COUNT(*) FROM org_post_attachments att WHERE att.post_id = p.id) ELSE 0 END) AS att_count,
          EXISTS(
            SELECT 1 FROM org_post_acknowledgements a2
            WHERE a2.post_id = p.id AND a2.user_id = :me_id
          ) AS i_acknowledged
        FROM org_posts p
        LEFT JOIN org_members om
          ON om.org_id = p.org_id AND om.id = p.author_id
        LEFT JOIN managers m
          ON om.member_type = 'manager' AND m.id = om.member_id
        LEFT JOIN staff_accounts s
          ON om.member_type = 'staff' AND s.id = om.member_id
        WHERE p.org_id = :org_id
        $whereType
        $stateSql
        $searchSql
        ORDER BY p.created_at DESC
        LIMIT :limit OFFSET :offset
    ";

    $st = $dbh->prepare($sql);
    $st->bindValue(':org_id', $orgId, PDO::PARAM_INT);
    $st->bindValue(':me_id',  $meMemberId, PDO::PARAM_INT);
    $st->bindValue(':has_att', $hasAttachmentsTable ? 1 : 0, PDO::PARAM_INT);
    if ($hasPostState && $state !== 'deleted') {
        $st->bindValue(':pst', $state, PDO::PARAM_STR);
    }
    if ($q !== '') $st->bindValue(':q', '%' . $q . '%', PDO::PARAM_STR);
    $st->bindValue(':limit',  $limit, PDO::PARAM_INT);
    $st->bindValue(':offset', $offset, PDO::PARAM_INT);
    $st->execute();
    $posts = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $posts = [];
}

// -------------------- Pagination calc --------------------
$totalPages = max(1, (int)ceil($totalRows / max(1, $limit)));
$hasPrev = $page > 1;
$hasNext = $page < $totalPages;

function url_with(array $add): string {
    $cur = $_GET;
    foreach ($add as $k => $v) $cur[$k] = $v;
    $qs = http_build_query($cur);
    return 'posts.php' . ($qs ? ('?' . $qs) : '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/org_theme_head.php'; ?>

  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title><?= h((string)($ORG['name'] ?? 'Organization')) ?> - Posts List</title>

  <link href="../lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="../lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link href="../lib/perfect-scrollbar/css/perfect-scrollbar.css" rel="stylesheet">
  <link href="../lib/bootstrap/bootstrap.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/shamcey.css">
  <?php require_once __DIR__ . '/includes/org_layout.php'; org_layout_head_assets(); ?>

  <style>
    html,body{ height:100%; overflow:hidden; }
    .sh-mainpanel{ height:100vh; display:flex; flex-direction:column; overflow:hidden; }
    .sh-pagetitle{ flex:0 0 auto; }
    .sh-pagebody{
      flex:1 1 auto; overflow:hidden; display:flex; flex-direction:column;
      min-height:0; padding-bottom:0!important;
    }
    .dashboard-card{ flex:1 1 auto; min-height:0; display:flex; flex-direction:column; overflow:hidden; }
    .card-body-fixed{ flex:1 1 auto; min-height:0; overflow:hidden; display:flex; flex-direction:column; }
    .rows-scroll{ flex:1 1 auto; min-height:0; overflow:auto; padding: 20px; }

    .dash-toprow{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
      flex-wrap:wrap;
      margin: 12px 0 10px;
    }
    .dash-toprow .left-actions{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
    .dash-toprow .right-tools{ margin-left:auto; display:flex; gap:10px; flex-wrap:wrap; align-items:center; justify-content:flex-end; }

    .posts-tabs{ display:flex; gap:10px; margin: 8px 0 14px; }
    .posts-tabs a{
      padding:10px 14px;
      border-radius:10px;
      text-decoration:none;
      border:2px solid #d1d5db;
      color:#6b7280;
      font-weight:800;
      background:#fff;
    }
    .posts-tabs a.active{ background:#0b5ed7; color:#fff; border-color:#0b5ed7; }

    .post-card{
      border:2px solid #6b7280;
      border-radius:14px;
      padding:14px 14px 12px;
      margin:12px 0;
      background:#fff;
    }
    .post-top{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:10px;
      flex-wrap:wrap;
    }
    .badge-pill{
      display:inline-flex;
      align-items:center;
      padding:6px 10px;
      border-radius:999px;
      background:#eef2f7;
      color:#374151;
      font-weight:900;
      font-size:13px;
      border:1px solid #e5e7eb;
    }
    .mini-muted{ color:#6b7280; font-size:14px; font-weight:600; }
    .post-title{
      font-weight:900;
      font-size:18px;
      color:#111827;
      margin-top:10px;
    }
    .post-body{
      color:#374151;
      margin-top:8px;
      white-space:pre-wrap;
      font-size:15px;
    }
    .post-foot{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:10px;
      flex-wrap:wrap;
      margin-top:12px;
      padding-top:10px;
      border-top:1px solid #eef0f4;
    }
    .count-line{
      display:flex; gap:14px; flex-wrap:wrap; align-items:center;
      font-weight:800;
      color:#6b7280;
      font-size:14px;
    }
    .locked{
      background:#fff3cd;
      border-color:#ffe69c;
      color:#7a5b00;
    }

    .searchbar{
      display:flex; gap:10px; flex-wrap:wrap; align-items:center;
    }
    .searchbar input{
      min-width:260px;
      max-width:520px;
    }

    .pager{
      display:flex; gap:10px; margin-top:14px; align-items:center; flex-wrap:wrap;
    }
    .pager .mini-muted{ margin-left:6px; }

    /* Dark mode */
    .dark-auto .post-card{ background:#0b1220; border-color:#334155; }
    .dark-auto .post-title{ color:#f3f4f6; }
    .dark-auto .post-body{ color:#e5e7eb; }
    .dark-auto .mini-muted{ color:#cbd5e1; }
    .dark-auto .count-line{ color:#cbd5e1; }
    .dark-auto .badge-pill{ background:#111827; color:#e5e7eb; border-color:#334155; }
    .dark-auto .post-foot{ border-top-color:#334155; }
  
    .bulkbar{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;
      background:#fff;border:1px solid #e5e9f2;border-radius:12px;padding:10px 12px;margin:12px 0;}
    .dark-auto .bulkbar{background:#0f172a;border-color:#1f2a44;}
    .bulkcheck{display:flex;align-items:center;gap:8px;margin:0;}
    .cardcheck input{width:16px;height:16px;cursor:pointer;}
    /* Simple modal */
    .xmodal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.55);display:none;align-items:center;justify-content:center;z-index:9999;padding:18px;}
    .xmodal{background:#fff;border-radius:14px;max-width:780px;width:100%;box-shadow:0 15px 60px rgba(0,0,0,.25);overflow:hidden;}
    .dark-auto .xmodal{background:#0b1220;color:#e6eefc;}
    .xmodal-h{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid #e5e9f2;}
    .dark-auto .xmodal-h{border-bottom-color:#1f2a44;}
    .xmodal-b{padding:16px;}
    .xmodal-f{display:flex;justify-content:flex-end;gap:8px;padding:14px 16px;border-top:1px solid #e5e9f2;}
    .dark-auto .xmodal-f{border-top-color:#1f2a44;}
    .xmodal-backdrop.show{display:flex;}
</style>

<?php if (is_array($__feedNotify) && !empty($__feedNotify['ids'])): ?>
<script>
(function(){
  try {
    var payload = <?= json_encode($__feedNotify, JSON_UNESCAPED_SLASHES) ?>;
    // localStorage event (works across tabs)
    localStorage.setItem('org_feed_update', JSON.stringify(payload));
    // BroadcastChannel (modern browsers)
    if ('BroadcastChannel' in window) {
      try {
        var bc = new BroadcastChannel('org_feed_updates');
        bc.postMessage(payload);
        bc.close();
      } catch(e){}
    }
  } catch(e){}
})();
</script>
<?php endif; ?>

</head>

<body>
<?php include __DIR__ . '/includes/header.php'; ?>
<?php include __DIR__ . '/includes/leftbar.php'; ?>

<div class="sh-mainpanel">

  <div class="sh-pagebody">
    <div class="card bd-0 dashboard-card">
      <div class="card-body card-body-fixed">
        <div class="rows-scroll">

          <!-- <h4 class="tx-gray-800 mg-b-10"><?= h((string)($ORG['name'] ?? 'Organization')) ?></h4> -->
          <p class="tx-gray-600 mg-b-0">
            Welcome, <strong><?= h($myFullname) ?></strong>
            <span class="mini-muted">(<?= h($meRole) ?>)</span>
            · Org Code: <strong><?= h((string)($ORG['org_code'] ?? '')) ?></strong>
          </p>

          <div class="dash-toprow">
            <div class="left-actions">
              <a href="dashboard.php" class="btn btn-outline-secondary">
                <i class="fa fa-arrow-left mg-r-5"></i> Back to Dashboard
              </a>
              <?php if (is_managerish($meRole)): ?>
                <a href="create_staff.php" class="btn btn-outline-secondary">
                  <i class="ion-person-add mg-r-5"></i> Create Staff
                </a>
                <a href="settings.php" class="btn btn-outline-secondary">
                  <i class="ion-ios-gear mg-r-5"></i> Org Settings
                </a>
              <?php endif; ?>
              <a href="messages.php" class="btn btn-outline-secondary">
                <i class="ion-chatboxes mg-r-5"></i> Messages
              </a>
            </div>

            <div class="right-tools">
              <form class="searchbar" method="get" action="posts.php">
                <input type="hidden" name="tab" value="<?= h($tab) ?>">
                <input type="text" name="q" value="<?= h($q) ?>" class="form-control"
                       placeholder="Search posts (title or message)…">
                <select name="limit" class="form-control" style="width:110px;">
                  <?php foreach ([5,10,15,20,30] as $opt): ?>
                    <option value="<?= $opt ?>" <?= $limit===$opt?'selected':'' ?>><?= $opt ?>/page</option>
                  <?php endforeach; ?>
                </select>

                <?php if ($canManagePosts && $hasPostState): ?>
                  <select name="state" class="form-control" style="width:150px;">
                    <?php foreach (['published'=>'Published','draft'=>'Drafts','hidden'=>'Hidden','archived'=>'Archived','deleted'=>'Deleted'] as $k=>$lbl): ?>
                      <option value="<?= h($k) ?>" <?= $state===$k?'selected':'' ?>><?= h($lbl) ?></option>
                    <?php endforeach; ?>
                  </select>
                <?php else: ?>
                  <input type="hidden" name="state" value="published">
                <?php endif; ?>
                <button class="btn btn-primary" type="submit">
                  <i class="fa fa-search mg-r-5"></i> Search
                </button>
                <?php if ($q !== ''): ?>
                  <a class="btn btn-outline-secondary" href="<?= h(url_with(['q'=>'','page'=>1])) ?>">
                    Clear
                  </a>
                <?php endif; ?>
              </form>
            </div>
          </div>

          <!-- Tabs -->
          <div class="posts-tabs">
            <a class="<?= $tab==='work'?'active':'' ?>" href="<?= h(url_with(['tab'=>'work','page'=>1])) ?>">Work Updates</a>
            <a class="<?= $tab==='culture'?'active':'' ?>" href="<?= h(url_with(['tab'=>'culture','page'=>1])) ?>">Culture & Wins</a>
          </div>


          <?php if ($canManagePosts && $hasPostState): ?>
            <div class="bulkbar">
              <label class="bulkcheck">
                <input type="checkbox" id="checkAll"> Select all
              </label>
              <span class="mini-muted" id="selCount">0 selected</span>

              <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <?php if ($state !== 'deleted'): ?>
                  <button type="button" class="btn btn-outline-danger btn-sm" id="btnDeleteSelected">
                    <i class="fa fa-trash mg-r-5"></i> Delete selected
                  </button>
                  <button type="button" class="btn btn-danger btn-sm" id="btnDeleteAll">
                    <i class="fa fa-trash-o mg-r-5"></i> Delete all
                  </button>
                <?php endif; ?>

                <?php if ($state === 'deleted'): ?>
                  <button type="button" class="btn btn-danger btn-sm" id="btnFinalDeleteSelected" title="Permanently delete selected posts">
                    <i class="fa fa-times-circle mg-r-5"></i> Final delete selected
                  </button>
                  <button type="button" class="btn btn-danger btn-sm" id="btnFinalDeleteAll" title="Permanently delete ALL deleted posts">
                    <i class="fa fa-ban mg-r-5"></i> Final delete all
                  </button>
                <?php endif; ?>
              </div>
            </div>

            <!-- Hidden bulk submit form (avoids nested forms) -->
            <form id="bulkForm" method="post" action="posts.php" style="display:none;">
              <input type="hidden" name="action" id="bulkAction" value="">
              <div id="bulkIds"></div>
            </form>
          <?php endif; ?>

          <?php if (!$posts): ?>
            <div class="post-card">
              <div class="mini-muted">
                <?= $q !== '' ? 'No results for your search.' : 'No posts yet.' ?>
              </div>
            </div>
          <?php endif; ?>

          <?php foreach ($posts as $p): ?>
            <?php
              $pid = (int)($p['id'] ?? 0);
              $ptype = (string)($p['post_type'] ?? '');
              $locked = ((int)($p['comments_locked'] ?? 0) === 1);
              $created = (string)($p['created_at'] ?? '');
              $author = (string)($p['author_name'] ?? 'Unknown');

              $titleRaw = trim((string)($p['title'] ?? ''));
              $title = $titleRaw !== '' ? $titleRaw : (post_label($ptype) . ' · ' . date('M j, Y', strtotime($created ?: 'now')));

              $body = trim((string)($p['body'] ?? ''));
              $snippet = $body;
              if (mb_strlen($snippet) > 220) $snippet = mb_substr($snippet, 0, 220) . '…';

              $commentCount = (int)($p['comment_count'] ?? 0);
              $ackCount = (int)($p['ack_count'] ?? 0);
              $attCount = (int)($p['att_count'] ?? 0);
              $iAck = ((int)($p['i_acknowledged'] ?? 0) === 1);
            ?>
            <div class="post-card">
              <div class="post-top">
                <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                  <?php if ($canManagePosts && $hasPostState): ?>
                    <label class="cardcheck" title="Select">
                      <input type="checkbox" class="post-check" value="<?= (int)$pid ?>">
                    </label>
                  <?php endif; ?>
                  <span class="badge-pill"><?= h(post_label($ptype)) ?></span>
                  <?php if ($locked): ?>
                    <span class="badge-pill locked">Comments Closed</span>
                  <?php endif; ?>
                  <?php if ($iAck): ?>
                    <span class="badge-pill" title="You acknowledged this post">
                      <i class="fa fa-check-circle" style="margin-right:6px;"></i> Acknowledged
                    </span>
                  <?php endif; ?>
                </div>

                <a class="btn btn-outline-secondary" href="feed.php?id=<?= $pid ?>">
                  <i class="fa fa-external-link mg-r-5"></i> View
                </a>

                <?php if ($canManagePosts): ?>
                  <button type="button" class="btn btn-outline-info btn-sm btnEditPost"
                          data-pid="<?= (int)$pid ?>"
                          data-type="<?= h((string)($p['post_type'] ?? '')) ?>"
                          data-title="<?= h($titleRaw) ?>"
                          data-body="<?= h($body) ?>">
                    <i class="fa fa-pencil mg-r-5"></i> Edit
                  </button>
                <?php endif; ?>

                <?php if ($canManagePosts && $hasPostState): ?>
                  <form method="post" action="posts.php" style="display:inline-flex;gap:6px;align-items:center;margin-left:8px;">
                    <input type="hidden" name="post_id" value="<?= (int)$pid ?>">
                    <input type="hidden" name="action" value="set_state">
                    <select name="new_state" class="form-control form-control-sm" style="width:140px;">
                      <?php
                        $curState = (string)($p['post_state'] ?? 'published');
                        $opts = ['published'=>'Published','draft'=>'Draft','hidden'=>'Hidden','archived'=>'Archived'];
                      ?>
                      <?php foreach ($opts as $k=>$lbl): ?>
                        <option value="<?= h($k) ?>" <?= $curState===$k?'selected':'' ?>><?= h($lbl) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button class="btn btn-outline-primary btn-sm" type="submit" title="Update state">
                      <i class="fa fa-save"></i>
                    </button>
                  </form>

                  <?php if ((string)($p['post_state'] ?? '') !== 'deleted'): ?>
                    <form method="post" action="posts.php" style="display:inline-block;margin-left:6px;" onsubmit="return confirm('Delete this post?');">
                      <input type="hidden" name="post_id" value="<?= (int)$pid ?>">
                      <input type="hidden" name="action" value="soft_delete">
                      <button class="btn btn-outline-danger btn-sm" type="submit" title="Delete">
                        <i class="fa fa-trash"></i>
                      </button>
                    </form>
                  <?php else: ?>
                    <form method="post" action="posts.php" style="display:inline-block;margin-left:6px;">
                      <input type="hidden" name="post_id" value="<?= (int)$pid ?>">
                      <input type="hidden" name="action" value="restore">
                      <button class="btn btn-outline-success btn-sm" type="submit" title="Restore">
                        <i class="fa fa-undo"></i>
                      </button>
                    </form>
                  <?php endif; ?>
                <?php endif; ?>

              </div>

              <div class="post-title"><?= h($title) ?></div>
              <div class="mini-muted" style="margin-top:6px;">
                Posted <?= h($created) ?> · By <?= h($author) ?>
              </div>

              <div class="post-body"><?= h($snippet) ?></div>

              <div class="post-foot">
                <div class="count-line">
                  <span><i class="fa fa-comments"></i> <?= $commentCount ?> responses</span>
                  <span><i class="fa fa-check-circle"></i> <?= $ackCount ?> acknowledgements</span>
                  <?php if ($attCount > 0): ?>
                    <span><i class="fa fa-paperclip"></i> <?= (int)$attCount ?> attachments</span>
                  <?php endif; ?>
                </div>

                <a class="btn btn-primary btn-sm" href="feed.php?id=<?= $pid ?>">
                  Open &raquo;
                </a>
              </div>
            </div>
          <?php endforeach; ?>

          <!-- Pagination -->
          <div class="pager">
            <?php if ($hasPrev): ?>
              <a class="btn btn-outline-secondary btn-sm" href="<?= h(url_with(['page'=>$page-1])) ?>">&laquo; Prev</a>
            <?php endif; ?>
            <?php if ($hasNext): ?>
              <a class="btn btn-outline-secondary btn-sm" href="<?= h(url_with(['page'=>$page+1])) ?>">Next &raquo;</a>
            <?php endif; ?>
            <span class="mini-muted">
              Page <strong><?= (int)$page ?></strong> of <strong><?= (int)$totalPages ?></strong>
              · Total: <strong><?= (int)$totalRows ?></strong>
            </span>
          </div>

        </div>
      </div>
    </div>
  </div>

  

  <!-- Edit Modal -->
  <div class="xmodal-backdrop" id="editModal">
    <div class="xmodal" role="dialog" aria-modal="true" aria-labelledby="editModalTitle">
      <div class="xmodal-h">
        <div>
          <div id="editModalTitle" style="font-weight:700;font-size:16px;">Edit Post</div>
          <div class="mini-muted" style="margin-top:2px;">Update subject, description, or replace attachment.</div>
        </div>
        <button type="button" class="btn btn-outline-secondary btn-sm" id="btnCloseEdit">
          <i class="fa fa-times"></i>
        </button>
      </div>

      <form method="post" action="posts.php" enctype="multipart/form-data" id="editForm">
        <div class="xmodal-b">
          <input type="hidden" name="action" value="edit_post">
          <input type="hidden" name="post_id" id="edit_post_id" value="">

          <div class="form-group">
            <label>Subject (Title)</label>
            <input type="text" class="form-control" name="title" id="edit_title" maxlength="200" placeholder="Subject / title">
          </div>

          <div class="form-group">
            <label>Description (Body)</label>
            <textarea class="form-control" name="body" id="edit_body" rows="6" placeholder="Write your message..."></textarea>
          </div>

          <div class="form-group">
            <label>Type</label>
            <select class="form-control" name="post_type" id="edit_type">
              <option value="">(keep current)</option>
              <option value="announcement">Announcement</option>
              <option value="direction">Direction</option>
              <option value="update">Update</option>
              <option value="weekly_update">Weekly Update</option>
              <option value="recognition">Recognition</option>
            </select>
          </div>

          <div class="form-group">
            <label>Replace attachment (optional)</label>
            <input type="file" class="form-control" name="edit_attachment" accept=".jpg,.jpeg,.png,.gif,.webp,.mp4,.mov,.webm,.pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt">
            <small class="mini-muted">Allowed: images, gif, video, pdf, docx, pptx, xlsx, txt (max 50MB)</small>
          </div>
        </div>

        <div class="xmodal-f">
          <button type="button" class="btn btn-outline-secondary" id="btnCancelEdit">Cancel</button>
          <button type="submit" class="btn btn-primary">
            <i class="fa fa-save mg-r-5"></i> Save changes
          </button>
        </div>
      </form>
    </div>
  </div>

  <script>
    (function(){
      var modal = document.getElementById('editModal');
      var btnClose = document.getElementById('btnCloseEdit');
      var btnCancel = document.getElementById('btnCancelEdit');

      function openModal(){ if(modal){ modal.classList.add('show'); } }
      function closeModal(){ if(modal){ modal.classList.remove('show'); } }

      if (btnClose) btnClose.addEventListener('click', closeModal);
      if (btnCancel) btnCancel.addEventListener('click', closeModal);
      if (modal) modal.addEventListener('click', function(e){ if(e.target === modal) closeModal(); });

      // Edit buttons
      document.querySelectorAll('.btnEditPost').forEach(function(btn){
        btn.addEventListener('click', function(){
          var pid = btn.getAttribute('data-pid') || '';
          document.getElementById('edit_post_id').value = pid;
          document.getElementById('edit_title').value = btn.getAttribute('data-title') || '';
          document.getElementById('edit_body').value  = btn.getAttribute('data-body') || '';
          document.getElementById('edit_type').value  = ''; // keep current by default
          openModal();
        });
      });

      // Bulk delete (hidden form)
      var chkAll = document.getElementById('checkAll');
      var selCount = document.getElementById('selCount');
      function refreshCount(){
        var n = document.querySelectorAll('.post-check:checked').length;
        if (selCount) selCount.textContent = n + ' selected';
      }
      if (chkAll){
        chkAll.addEventListener('change', function(){
          document.querySelectorAll('.post-check').forEach(function(c){ c.checked = chkAll.checked; });
          refreshCount();
        });
      }
      document.querySelectorAll('.post-check').forEach(function(c){
        c.addEventListener('change', function(){
          if (chkAll && !c.checked) chkAll.checked = false;
          refreshCount();
        });
      });
      refreshCount();

      function submitBulk(action){
        var ids = Array.prototype.slice.call(document.querySelectorAll('.post-check:checked')).map(function(c){ return c.value; });
        if (ids.length === 0) { alert('Select at least one post.'); return; }
        if (action === 'soft_delete_many' && !confirm('Delete selected posts?')) return;
        if (action === 'final_delete_many' && !confirm('FINAL DELETE selected posts permanently? This cannot be undone.')) return;

        var form = document.getElementById('bulkForm');
        var act  = document.getElementById('bulkAction');
        var box  = document.getElementById('bulkIds');
        if (!form || !act || !box) return;

        act.value = action;
        box.innerHTML = '';
        ids.forEach(function(id){
          var inp = document.createElement('input');
          inp.type = 'hidden';
          inp.name = 'post_ids[]';
          inp.value = id;
          box.appendChild(inp);
        });
        form.submit();
      }

      var btnSel = document.getElementById('btnDeleteSelected');
      if (btnSel) btnSel.addEventListener('click', function(){ submitBulk('soft_delete_many'); });

      var btnFinalSel = document.getElementById('btnFinalDeleteSelected');
      if (btnFinalSel) btnFinalSel.addEventListener('click', function(){ submitBulk('final_delete_many'); });

      var btnFinalAll = document.getElementById('btnFinalDeleteAll');
      if (btnFinalAll) btnFinalAll.addEventListener('click', function(){
        if (!confirm('FINAL DELETE ALL DELETED posts permanently? This cannot be undone.')) return;
        var form = document.getElementById('bulkForm');
        var act  = document.getElementById('bulkAction');
        var box  = document.getElementById('bulkIds');
        if (!form || !act || !box) return;
        act.value = 'final_delete_all'; // ✅ correct
        box.innerHTML = '';
        form.submit();
      });

      var btnFinalAll = document.getElementById('btnFinalDeleteAll');
      if (btnFinalAll) btnFinalAll.addEventListener('click', function(){
        if (!confirm('FINAL DELETE ALL DELETED posts permanently? This cannot be undone.')) return;
        var form = document.getElementById('bulkForm');
        var act  = document.getElementById('bulkAction');
        var box  = document.getElementById('bulkIds');
        if (!form || !act || !box) return;
        act.value = 'final_delete_all'; // ✅ correct
        box.innerHTML = '';
        form.submit();
      });


    })();
  </script>

<?php include __DIR__ . '/includes/footer.php'; ?>
</div>

</body>
</html>