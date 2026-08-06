<?php
declare(strict_types=1);

/**
 * Shared 3-dot post card actions menu (popup) used on feed, public, profile, and news.
 */

function post_card_actions_menu_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function post_card_menu_fries_icon_html(): string
{
    return '<span class="pcm-fries-icon" aria-hidden="true">'
        . '<span class="pcm-fries-bar"></span>'
        . '<span class="pcm-fries-bar pcm-fries-bar--short"></span>'
        . '<span class="pcm-fries-bar"></span>'
        . '<span class="pcm-fries-bar pcm-fries-bar--short"></span>'
        . '</span>';
}

function publisher_media_follow_btn_html(int $publisherId, bool $isFollowing = false, bool $viewerCanFollow = true): string
{
    if ($publisherId <= 0 || $isFollowing || !$viewerCanFollow) {
        return '';
    }

    return '<button type="button" class="publisher-follow-btn mf-publisher-follow mf-publisher-follow-circle mf-media-action-circle mf-media-follow-btn mf-friend-btn primary" data-publisher-id="' . $publisherId . '" aria-label="Follow" title="Follow"><i class="fa fa-plus" aria-hidden="true"></i></button>';
}

function post_card_contact_for_peer(PDO $dbh, int $ownerId, int $peerId): array
{
    static $cache = [];
    if ($ownerId <= 0 || $peerId <= 0 || $ownerId === $peerId) {
        return ['contact_id' => 0, 'display_name' => ''];
    }
    $key = $ownerId . ':' . $peerId;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $row = ['contact_id' => 0, 'display_name' => ''];
    try {
        $st = $dbh->prepare('SELECT id, display_name FROM user_contacts WHERE owner_user_id = ? AND friend_user_id = ? LIMIT 1');
        $st->execute([$ownerId, $peerId]);
        $found = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $row['contact_id'] = (int)($found['id'] ?? 0);
        $row['display_name'] = trim((string)($found['display_name'] ?? ''));
    } catch (Throwable $e) {
        $row = ['contact_id' => 0, 'display_name' => ''];
    }
    $cache[$key] = $row;
    return $row;
}

function post_card_actions_message_url(array $ctx): string
{
    $peerCode = trim((string)($ctx['peer_code'] ?? ''));
    $peerId = (int)($ctx['peer_id'] ?? 0);
    if ($peerCode !== '') {
        return 'messages.php?peer=' . rawurlencode($peerCode);
    }
    if ($peerId > 0) {
        return 'messages.php?peer_id=' . $peerId;
    }
    return 'messages.php';
}

function post_card_actions_post_url(int $postId, string $menuSurface = 'feed'): string
{
    if ($postId <= 0) {
        return '';
    }

    // Canonical deep link — opens exactly this one post (post.php).
    return 'post.php?id=' . $postId;
}

function post_card_actions_button_item(string $class, string $label, string $icon, array $attrs = []): string
{
    $h = 'post_card_actions_menu_h';
    $attrHtml = '';
    foreach ($attrs as $key => $value) {
        $attrHtml .= ' ' . $h((string)$key) . '="' . $h((string)$value) . '"';
    }

    return '<button type="button" class="pcm-item ' . $h($class) . '" role="menuitem"' . $attrHtml . '>'
        . '<i class="' . $h($icon) . '" aria-hidden="true"></i><span>' . $h($label) . '</span></button>';
}

function post_card_actions_owner_menu_items_html(array $ctx): string
{
    $h = 'post_card_actions_menu_h';
    $postId = (int)($ctx['post_id'] ?? 0);
    $staffReadonly = !empty($ctx['staff_readonly']);
    $editUrl = trim((string)($ctx['edit_url'] ?? ''));
    if ($postId <= 0 || $staffReadonly) {
        return '';
    }

    $isArchived = !empty($ctx['is_archived']);
    $items = [];
    if ($editUrl !== '') {
        $items[] = '<a class="pcm-item pcm-edit" href="' . $h($editUrl) . '" data-create-post-modal="1" role="menuitem"><i class="fa fa-edit" aria-hidden="true"></i><span>Edit</span></a>';
    }
    $items[] = post_card_actions_button_item(
        'pcm-archive',
        $isArchived ? 'Unarchive' : 'Archive',
        'fa fa-archive',
        [
            'data-post-id' => (string)$postId,
            'data-archived' => $isArchived ? '1' : '0',
        ]
    );
    $items[] = post_card_actions_button_item('pcm-delete', 'Delete', 'fa fa-trash', [
        'data-post-id' => (string)$postId,
    ]);

    return implode('', $items);
}

function post_card_actions_common_menu_items_html(array $ctx): string
{
    $postId = (int)($ctx['post_id'] ?? 0);
    if ($postId <= 0) {
        return '';
    }

    $isOwner = !empty($ctx['is_owner']);
    $isSaved = !empty($ctx['is_saved']);
    $items = [];

    if (!$isOwner) {
        $items[] = post_card_actions_button_item('pcm-report is-danger', 'Report', 'fa fa-flag', [
            'data-post-id' => (string)$postId,
        ]);
    }

    $items[] = post_card_actions_button_item(
        'pcm-bookmark' . ($isSaved ? ' is-active' : ''),
        'Bookmark',
        $isSaved ? 'fa fa-bookmark' : 'fa fa-bookmark-o',
        [
            'data-post-id' => (string)$postId,
            'data-saved' => $isSaved ? '1' : '0',
        ]
    );
    $items[] = post_card_actions_button_item('pcm-share', 'Share', 'fa fa-share', [
        'data-post-id' => (string)$postId,
    ]);
    $items[] = post_card_actions_button_item('pcm-copy-link', 'Copy link', 'fa fa-link', [
        'data-post-id' => (string)$postId,
    ]);

    return implode('', $items);
}

function post_card_actions_menu_context(
    array $post,
    int $meId,
    PDO $dbh,
    string $profileUrl = '',
    bool $staffReadonly = false,
    string $menuSurface = 'public'
): array {
    $peerId = (int)($post['user_id'] ?? 0);
    $isOwner = $peerId > 0 && $peerId === $meId;
    $friendStatus = (string)($post['friend_status'] ?? 'none');
    $accountKind = strtolower(trim((string)($post['account_kind'] ?? 'personal')));
    $isPublisher = !empty($post['is_publisher']) || $accountKind === 'publisher';
    $isFollowing = !empty($post['is_following']);
    $contact = post_card_contact_for_peer($dbh, $meId, $peerId);
    $postId = (int)($post['id'] ?? 0);

    return [
        'is_owner' => $isOwner,
        'post_id' => $postId,
        'peer_id' => $peerId,
        'peer_code' => (string)($post['friend_code'] ?? ''),
        'profile_url' => $profileUrl,
        'account_kind' => $accountKind,
        'is_publisher' => $isPublisher,
        'is_following' => $isFollowing,
        'friend_status' => $friendStatus,
        'staff_readonly' => $staffReadonly,
        'contact_id' => (int)($contact['contact_id'] ?? 0),
        'contact_name' => (string)($contact['display_name'] ?? ''),
        'author_name' => trim((string)($post['display_name'] ?? $post['username'] ?? '')),
        'edit_url' => 'dashboard.php?modal=1&edit=' . $postId,
        'message_url' => post_card_actions_message_url([
            'peer_code' => (string)($post['friend_code'] ?? ''),
            'peer_id' => $peerId,
        ]),
        'timeline_url' => $peerId > 0 ? ('timeline.php?u=' . $peerId) : '',
        'menu_surface' => strtolower(trim($menuSurface)),
        'post_url' => post_card_actions_post_url($postId, $menuSurface),
        'is_saved' => !empty($post['my_saved']),
        'is_archived' => !empty($post['is_archived']),
    ];
}

function post_card_actions_menu_items_html(array $ctx): string
{
    $h = 'post_card_actions_menu_h';
    $isOwner = !empty($ctx['is_owner']);
    $staffReadonly = !empty($ctx['staff_readonly']);
    $isPublisher = !empty($ctx['is_publisher']);
    $friendStatus = (string)($ctx['friend_status'] ?? 'none');
    $isFollowing = !empty($ctx['is_following']);
    $profileUrl = trim((string)($ctx['profile_url'] ?? ''));
    $messageUrl = trim((string)($ctx['message_url'] ?? ''));
    $timelineUrl = trim((string)($ctx['timeline_url'] ?? ''));
    $editUrl = trim((string)($ctx['edit_url'] ?? ''));
    $postId = (int)($ctx['post_id'] ?? 0);
    $peerId = (int)($ctx['peer_id'] ?? 0);
    $menuSurface = (string)($ctx['menu_surface'] ?? 'public');
    $feedSurface = ($menuSurface === 'feed');
    $canFollowPublishers = !array_key_exists('can_follow_publishers', $ctx) || !empty($ctx['can_follow_publishers']);
    $publisherWorkspaceViewer = !empty($ctx['publisher_workspace_viewer']);
    $commonItems = post_card_actions_common_menu_items_html($ctx);
    $ownerItems = post_card_actions_owner_menu_items_html($ctx);

    $items = [];

    if (($isOwner || ($feedSurface && $friendStatus === 'self')) && !$staffReadonly) {
        if ($ownerItems !== '') {
            $items[] = $ownerItems;
        }
        if ($commonItems !== '') {
            if ($items) {
                $items[] = '<div class="pcm-divider" role="separator"></div>';
            }
            $items[] = $commonItems;
        }
        return implode('', $items);
    }

    // Personal users may always open a publisher profile (Posts / Gallery / Tags).
    // On Friends Feed, View also remains for followed publishers and personal peers.
    if ($profileUrl !== '' && (
        !$feedSurface
        || !$isPublisher
        || $isFollowing
        || $canFollowPublishers
    )) {
        $items[] = '<a class="pcm-item pcm-view" href="' . $h($profileUrl) . '" role="menuitem"><i class="fa fa-user" aria-hidden="true"></i><span>View</span></a>';
    }

    if (!$feedSurface && !$isPublisher && $friendStatus === 'friends') {
        $items[] = '<a class="pcm-item pcm-friends" href="contacts.php" role="menuitem"><i class="fa fa-users" aria-hidden="true"></i><span>Friends</span></a>';
    }

    if ($friendStatus === 'friends' && $messageUrl !== '') {
        $items[] = '<a class="pcm-item pcm-message" href="' . $h($messageUrl) . '" role="menuitem"><i class="fa fa-comments" aria-hidden="true"></i><span>Message</span></a>';
    }

    if (!$feedSurface && !$isPublisher && $peerId > 0 && $friendStatus === 'none' && !$staffReadonly) {
        $items[] = '<button type="button" class="pcm-item pcm-add-friend" data-peer-id="' . $peerId . '" role="menuitem"><i class="fa fa-user-plus" aria-hidden="true"></i><span>Add Friend</span></button>';
    }

    if (!$feedSurface && $isPublisher && !$isFollowing && $peerId > 0 && $canFollowPublishers) {
        $items[] = '<button type="button" class="pcm-item pcm-follow" data-publisher-id="' . $peerId . '" role="menuitem"><i class="fa fa-user-plus" aria-hidden="true"></i><span>Follow</span></button>';
    }

    if ($isPublisher && $isFollowing && $peerId > 0) {
        $items[] = '<button type="button" class="pcm-item pcm-unfollow" data-publisher-id="' . $peerId . '" role="menuitem"><i class="fa fa-user-times" aria-hidden="true"></i><span>Unfollow</span></button>';
    }

    $showTimeline = !$feedSurface && $peerId > 0 && $timelineUrl !== '' && (!$isPublisher || $publisherWorkspaceViewer);
    if ($showTimeline) {
        $items[] = '<a class="pcm-item pcm-timeline" href="' . $h($timelineUrl) . '" role="menuitem"><i class="icon ion-ios-locked" aria-hidden="true"></i><span>Timeline</span></a>';
    }

    if ($commonItems !== '') {
        if ($items) {
            $items[] = '<div class="pcm-divider" role="separator"></div>';
        }
        $items[] = $commonItems;
    }

    return implode('', $items);
}

function post_card_actions_menu_shell_html(array $ctx, string $wrapClass = ''): string
{
    $wrapClass = trim('post-card-menu-wrap mf-menu-wrap ' . $wrapClass);
    $items = post_card_actions_menu_items_html($ctx);
    if ($items === '' && (int)($ctx['post_id'] ?? 0) > 0) {
        $items = post_card_actions_common_menu_items_html($ctx);
    }
    if ($items === '') {
        return '';
    }

    $attrs = [
        'data-post-id="' . (int)($ctx['post_id'] ?? 0) . '"',
        'data-peer-id="' . (int)($ctx['peer_id'] ?? 0) . '"',
        'data-is-owner="' . (!empty($ctx['is_owner']) ? '1' : '0') . '"',
        'data-menu-surface="' . post_card_actions_menu_h((string)($ctx['menu_surface'] ?? 'public')) . '"',
    ];
    if (!empty($ctx['peer_code'])) {
        $attrs[] = 'data-peer-code="' . post_card_actions_menu_h((string)$ctx['peer_code']) . '"';
    }

    $onMedia = (bool)preg_match('/(?:standard-media-topbar|on-media)/i', $wrapClass);

    return '<div class="' . post_card_actions_menu_h($wrapClass) . '" ' . implode(' ', $attrs) . '>'
        . '<button type="button" class="post-card-menu-btn mf-menu-btn" aria-label="Post menu" title="Menu" aria-haspopup="true" aria-expanded="false">'
        . post_card_menu_fries_icon_html()
        . '</button>'
        . '<div class="post-card-menu mf-menu" role="menu">' . $items . '</div>'
        . '</div>';
}

function post_card_actions_menu_render_css(): void
{
    if (defined('MSB_POST_CARD_MENU_CSS')) {
        return;
    }
    define('MSB_POST_CARD_MENU_CSS', true);
    echo '<style id="post-card-actions-menu-css">';
    include __DIR__ . '/post_card_actions_menu.css.php';
    echo '</style>';
}

function post_card_actions_menu_render_modals(): void
{
    if (defined('MSB_POST_CARD_MENU_MODALS')) {
        return;
    }
    define('MSB_POST_CARD_MENU_MODALS', true);
    ?>
<div class="modal fade" id="pcmRenameModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog" role="document" style="max-width:520px;">
    <div class="modal-content" style="border-radius:14px;">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa fa-pencil"></i> Rename Friend</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="pcmRenameId" value="0">
        <label style="font-weight:800;">Display Name</label>
        <input id="pcmRenameInput" class="form-control" placeholder="Enter new name..." autocomplete="off">
        <small class="d-block mt-2" style="opacity:.75;">This only changes how they appear in your Friends list.</small>
        <div id="pcmRenameErr" class="alert alert-danger mt-3" style="display:none;"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="pcmRenameSaveBtn"><i class="fa fa-save"></i> Save</button>
      </div>
    </div>
  </div>
</div>
<dialog class="pcm-delete-dialog" id="pcmDeleteConfirmDialog" aria-labelledby="pcmDeleteConfirmTitle">
  <button type="button" class="pcm-delete-dialog-close" data-pcm-delete-dismiss aria-label="Close">&times;</button>
  <div class="pcm-delete-dialog-icon" aria-hidden="true"><i class="fa fa-trash"></i></div>
  <h2 id="pcmDeleteConfirmTitle">Delete this post?</h2>
  <p>This action cannot be undone. The post will be permanently removed.</p>
  <div class="pcm-delete-dialog-actions">
    <button type="button" class="pcm-delete-dialog-cancel" data-pcm-delete-dismiss>Cancel</button>
    <button type="button" class="pcm-delete-dialog-confirm" id="pcmGenericConfirmDeleteBtn">Delete</button>
  </div>
</dialog>
<dialog class="pcm-delete-dialog pcm-archive-dialog" id="pcmArchiveConfirmDialog" aria-labelledby="pcmArchiveConfirmTitle">
  <button type="button" class="pcm-delete-dialog-close" data-pcm-archive-dismiss aria-label="Close">&times;</button>
  <div class="pcm-delete-dialog-icon pcm-archive-dialog-icon" aria-hidden="true"><i class="fa fa-archive"></i></div>
  <h2 id="pcmArchiveConfirmTitle">Archive this post?</h2>
  <p id="pcmArchiveConfirmBody">It will be hidden from feeds. You can find it later under Posts in Settings → Archived posts.</p>
  <div class="pcm-delete-dialog-actions">
    <button type="button" class="pcm-delete-dialog-cancel" data-pcm-archive-dismiss>Cancel</button>
    <button type="button" class="pcm-delete-dialog-confirm pcm-archive-dialog-confirm" id="pcmGenericConfirmArchiveBtn">Archive</button>
  </div>
</dialog>
<dialog class="pcm-share-dialog" id="pcmShareSheet" aria-labelledby="pcmShareSheetTitle">
  <button type="button" class="pcm-share-close" data-pcm-share-dismiss aria-label="Close">&times;</button>
  <h2 id="pcmShareSheetTitle">Share</h2>
  <p class="pcm-share-sub">Send this post with apps you use.</p>
  <button type="button" class="pcm-share-native" id="pcmShareNativeBtn" hidden>
    <span class="pcm-share-native-ico" aria-hidden="true"><i class="fa fa-share-alt"></i></span>
    <span class="pcm-share-native-txt">
      <strong>Share via…</strong>
      <small>Messages, Instagram, TikTok, and more</small>
    </span>
  </button>
  <div class="pcm-share-grid" role="list">
    <a class="pcm-share-app" data-pcm-share="facebook" role="listitem" href="#" target="_blank" rel="noopener noreferrer"><span class="pcm-share-app-ico pcm-share-fb" aria-hidden="true"><i class="fa fa-facebook"></i></span><span>Facebook</span></a>
    <a class="pcm-share-app" data-pcm-share="instagram" role="listitem" href="#" target="_blank" rel="noopener noreferrer"><span class="pcm-share-app-ico pcm-share-ig" aria-hidden="true"><i class="fa fa-instagram"></i></span><span>Instagram</span></a>
    <a class="pcm-share-app" data-pcm-share="messages" role="listitem" href="#"><span class="pcm-share-app-ico pcm-share-msg" aria-hidden="true"><i class="fa fa-comment"></i></span><span>Messages</span></a>
    <a class="pcm-share-app" data-pcm-share="tiktok" role="listitem" href="#" target="_blank" rel="noopener noreferrer"><span class="pcm-share-app-ico pcm-share-tt" aria-hidden="true"><i class="fa fa-music"></i></span><span>TikTok</span></a>
    <a class="pcm-share-app" data-pcm-share="whatsapp" role="listitem" href="#" target="_blank" rel="noopener noreferrer"><span class="pcm-share-app-ico pcm-share-wa" aria-hidden="true"><i class="fa fa-whatsapp"></i></span><span>WhatsApp</span></a>
    <a class="pcm-share-app" data-pcm-share="x" role="listitem" href="#" target="_blank" rel="noopener noreferrer"><span class="pcm-share-app-ico pcm-share-x" aria-hidden="true"><i class="fa fa-twitter"></i></span><span>X</span></a>
    <a class="pcm-share-app" data-pcm-share="telegram" role="listitem" href="#" target="_blank" rel="noopener noreferrer"><span class="pcm-share-app-ico pcm-share-tg" aria-hidden="true"><i class="fa fa-paper-plane"></i></span><span>Telegram</span></a>
    <a class="pcm-share-app" data-pcm-share="email" role="listitem" href="#"><span class="pcm-share-app-ico pcm-share-em" aria-hidden="true"><i class="fa fa-envelope"></i></span><span>Email</span></a>
  </div>
  <button type="button" class="pcm-share-copy" id="pcmShareCopyBtn" data-pcm-share="copy">
    <i class="fa fa-link" aria-hidden="true"></i><span>Copy link</span>
  </button>
  <button type="button" class="pcm-share-cancel" data-pcm-share-dismiss>Cancel</button>
</dialog>
<style id="pcm-confirm-modal-css">
  html body dialog.pcm-delete-dialog{position:fixed!important;inset:0!important;top:0!important;right:0!important;bottom:0!important;left:0!important;width:min(430px,calc(100vw - 32px))!important;max-width:430px!important;height:max-content!important;min-height:0!important;max-height:calc(100dvh - 32px)!important;margin:auto!important;padding:30px!important;overflow:auto!important;transform:none!important;border:1px solid var(--msb-palette-border,rgba(148,163,184,.28))!important;border-radius:22px!important;background:var(--msb-palette-surface,var(--msb-palette-bg,#fff))!important;color:var(--msb-palette-text,#111827)!important;box-shadow:0 28px 80px rgba(0,0,0,.38)!important;text-align:center!important;box-sizing:border-box!important;z-index:2147483647!important}
  .pcm-delete-dialog::backdrop{background:rgba(15,23,42,.62);backdrop-filter:blur(5px);-webkit-backdrop-filter:blur(5px)}
  html body dialog.pcm-delete-dialog:not([open]){display:none!important}
  html body dialog.pcm-delete-dialog[open]{display:block!important}
  html body .pcm-delete-dialog-close{position:absolute!important;top:12px!important;right:14px!important;width:34px!important;height:34px!important;margin:0!important;padding:0!important;border:0!important;border-radius:50%!important;background:transparent!important;color:var(--msb-palette-text-muted,var(--msb-palette-muted,#64748b))!important;font-size:27px!important;line-height:32px!important;cursor:pointer!important}
  .pcm-delete-dialog-close:hover{background:var(--msb-palette-hover-bg,var(--msb-palette-surface-2,rgba(148,163,184,.14)));color:var(--msb-palette-text,#111827)}
  html body .pcm-delete-dialog-icon{position:static!important;display:grid!important;place-items:center!important;width:58px!important;height:58px!important;margin:0 auto 16px!important;border-radius:50%!important;background:rgba(239,68,68,.12)!important;color:#dc2626!important;font-size:23px!important}
  html body .pcm-archive-dialog-icon{background:rgba(37,99,235,.12)!important;color:#2563eb!important}
  html body .pcm-delete-dialog h2{position:static!important;display:block!important;margin:0 30px 9px!important;padding:0!important;color:inherit!important;font-size:21px!important;font-weight:800!important;line-height:1.25!important}
  html body .pcm-delete-dialog p{position:static!important;display:block!important;margin:0!important;padding:0!important;color:var(--msb-palette-text-muted,var(--msb-palette-muted,#64748b))!important;font-size:14px!important;line-height:1.55!important}
  html body .pcm-delete-dialog-actions{position:static!important;display:flex!important;gap:10px!important;width:100%!important;margin:24px 0 0!important;padding:0!important}
  .pcm-delete-dialog-actions button{flex:1 1 0;height:44px;border-radius:999px;font-size:14px;font-weight:800;cursor:pointer}
  .pcm-delete-dialog-cancel{border:1px solid var(--msb-palette-border,rgba(148,163,184,.38));background:var(--msb-palette-hover-bg,var(--msb-palette-surface-2,transparent));color:var(--msb-palette-text,#111827)}
  .pcm-delete-dialog-confirm{border:1px solid #dc2626;background:#dc2626;color:#fff}
  html body .pcm-archive-dialog-confirm{border:1px solid #2563eb!important;background:#2563eb!important;color:#fff!important}
  @media(max-width:575.98px){html body dialog.pcm-delete-dialog{padding:28px 22px 22px!important}html body .pcm-delete-dialog h2{font-size:19px!important}}

  html body dialog.pcm-share-dialog{
    position:fixed!important;inset:0!important;top:0!important;right:0!important;bottom:0!important;left:0!important;
    width:min(430px,calc(100vw - 32px))!important;max-width:430px!important;height:max-content!important;max-height:min(88dvh,720px)!important;
    margin:auto!important;padding:22px 18px 18px!important;
    overflow:auto!important;transform:none!important;border:1px solid var(--msb-palette-border,rgba(148,163,184,.28))!important;border-radius:22px!important;
    background:var(--msb-palette-surface,var(--msb-palette-bg,#fff))!important;color:var(--msb-palette-text,#111827)!important;
    box-shadow:0 28px 80px rgba(0,0,0,.38)!important;text-align:left!important;box-sizing:border-box!important;z-index:2147483647!important;
  }
  .pcm-share-dialog::backdrop{background:rgba(15,23,42,.58);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px)}
  html body dialog.pcm-share-dialog:not([open]){display:none!important}
  html body dialog.pcm-share-dialog[open]{display:block!important}
  html body .pcm-share-close{
    position:absolute!important;top:12px!important;right:12px!important;width:34px!important;height:34px!important;
    margin:0!important;padding:0!important;border:0!important;border-radius:50%!important;background:transparent!important;
    color:var(--msb-palette-text-muted,#64748b)!important;font-size:26px!important;line-height:32px!important;cursor:pointer!important;
  }
  html body .pcm-share-dialog h2{margin:4px 40px 4px 4px!important;padding:0!important;font-size:20px!important;font-weight:800!important;line-height:1.2!important;color:inherit!important}
  html body .pcm-share-sub{margin:0 4px 16px!important;font-size:13px!important;font-weight:600!important;color:var(--msb-palette-text-muted,#64748b)!important}
  html body .pcm-share-native{
    display:flex!important;align-items:center!important;gap:12px!important;width:100%!important;margin:0 0 14px!important;padding:12px 14px!important;
    border:1px solid var(--msb-palette-border,rgba(148,163,184,.35))!important;border-radius:16px!important;
    background:var(--msb-palette-hover-bg,rgba(148,163,184,.10))!important;color:inherit!important;cursor:pointer!important;text-align:left!important;
  }
  html body .pcm-share-native[hidden]{display:none!important}
  .pcm-share-native-ico{
    width:44px;height:44px;border-radius:14px;display:grid;place-items:center;flex:0 0 auto;
    background:linear-gradient(135deg,#2563eb,#7c3aed);color:#fff;font-size:18px;
  }
  .pcm-share-native-txt{display:flex;flex-direction:column;gap:2px;min-width:0}
  .pcm-share-native-txt strong{font-size:14px;font-weight:800}
  .pcm-share-native-txt small{font-size:12px;font-weight:600;color:var(--msb-palette-text-muted,#64748b)}
  html body .pcm-share-grid{
    display:grid!important;grid-template-columns:repeat(4,minmax(0,1fr))!important;gap:10px 6px!important;margin:0 0 14px!important;
  }
  html body .pcm-share-app{
    display:flex!important;flex-direction:column!important;align-items:center!important;gap:8px!important;
    border:0!important;background:transparent!important;color:inherit!important;cursor:pointer!important;padding:6px 2px!important;font-size:11px!important;font-weight:700!important;
    text-decoration:none!important;-webkit-text-fill-color:inherit!important;
  }
  .pcm-share-app-ico{
    width:52px;height:52px;border-radius:16px;display:grid;place-items:center;font-size:22px;color:#fff;
  }
  .pcm-share-fb{background:#1877f2}
  .pcm-share-ig{background:linear-gradient(45deg,#f58529,#dd2a7b,#8134af)}
  .pcm-share-msg{background:#34c759}
  .pcm-share-tt{background:#111}
  .pcm-share-wa{background:#25d366}
  .pcm-share-x{background:#111}
  .pcm-share-tg{background:#229ed9}
  .pcm-share-em{background:#64748b}
  html body .pcm-share-copy,
  html body .pcm-share-cancel{
    display:flex!important;align-items:center!important;justify-content:center!important;gap:8px!important;width:100%!important;
    height:46px!important;margin:0 0 8px!important;border-radius:999px!important;font-size:14px!important;font-weight:800!important;cursor:pointer!important;
  }
  html body .pcm-share-copy{
    border:1px solid var(--msb-palette-border,rgba(148,163,184,.38))!important;
    background:var(--msb-palette-hover-bg,rgba(148,163,184,.10))!important;color:inherit!important;
  }
  html body .pcm-share-cancel{
    border:0!important;background:transparent!important;color:var(--msb-palette-text-muted,#64748b)!important;margin-bottom:0!important;
  }
  @media(max-width:419.98px){
    html body .pcm-share-grid{grid-template-columns:repeat(4,minmax(0,1fr))!important}
    .pcm-share-app-ico{width:48px;height:48px;border-radius:14px;font-size:20px}
  }
</style>
    <?php
}

function post_card_actions_menu_render_js(array $opts = []): void
{
    if (defined('MSB_POST_CARD_MENU_JS')) {
        return;
    }
    define('MSB_POST_CARD_MENU_JS', true);
    $optsJson = json_encode($opts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    echo '<script>window.MSBPostCardMenuOpts = ' . $optsJson . ';</script>';
    echo '<script>';
    include __DIR__ . '/post_card_actions_menu.js.php';
    echo '</script>';
}
