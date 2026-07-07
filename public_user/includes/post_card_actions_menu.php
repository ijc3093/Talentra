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

function post_card_actions_menu_context(
    array $post,
    int $meId,
    PDO $dbh,
    string $profileUrl = '',
    bool $staffReadonly = false
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

    $items = [];

    if (($isOwner || ($feedSurface && $friendStatus === 'self')) && !$staffReadonly) {
        if ($editUrl !== '') {
            $items[] = '<a class="pcm-item pcm-edit" href="' . $h($editUrl) . '" data-create-post-modal="1" role="menuitem"><i class="fa fa-edit" aria-hidden="true"></i><span>Edit</span></a>';
        }
        if ($postId > 0) {
            $items[] = '<div class="pcm-divider" role="separator"></div>';
            $items[] = '<button type="button" class="pcm-item pcm-delete" data-post-id="' . $postId . '" role="menuitem"><i class="fa fa-trash" aria-hidden="true"></i><span>Delete</span></button>';
        }
        return implode('', $items);
    }

    if ((!$feedSurface || ($isPublisher && $isFollowing)) && $profileUrl !== '') {
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

    return implode('', $items);
}

function post_card_actions_menu_shell_html(array $ctx, string $wrapClass = ''): string
{
    $wrapClass = trim('post-card-menu-wrap mf-menu-wrap ' . $wrapClass);
    $items = post_card_actions_menu_items_html($ctx);
    if ($items === '') {
        return '';
    }

    $attrs = [
        'data-post-id="' . (int)($ctx['post_id'] ?? 0) . '"',
        'data-peer-id="' . (int)($ctx['peer_id'] ?? 0) . '"',
        'data-is-owner="' . (!empty($ctx['is_owner']) ? '1' : '0') . '"',
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
