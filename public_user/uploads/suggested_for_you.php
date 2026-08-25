<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/theme_prefs.php';
require_once __DIR__ . '/includes/publisher_accounts_load.php';
require_once __DIR__ . '/includes/staff_publisher_access.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');

$controller = new Controller();
$dbh = $controller->pdo();

$meId = theme_prefs_viewer_user_id();
$canFollowPublishers = publisher_can_follow_as_viewer($dbh, $meId);
$isPublisherWorkspaceViewer = publisher_workspace_viewer($dbh, $meId);
if (
    $isPublisherWorkspaceViewer
    && strtolower(trim((string)($_GET['tab'] ?? ''))) === 'people'
) {
    $redirParams = ['tab' => 'publishers'];
    $pageSearchQ = trim((string)($_GET['q'] ?? ''));
    if ($pageSearchQ !== '') {
        $redirParams['q'] = $pageSearchQ;
    }
    header('Location: suggested_for_you.php?' . http_build_query($redirParams));
    exit;
}
$staffReadonly = staff_pub_is_readonly();
$feedLeftRailPublicPublishers = staff_pub_menu_for_viewer($dbh, $meId);
$feedAppearanceMode = theme_prefs_appearance_mode($dbh, $meId);

$suggestedForYouMode = 'page';
$suggestedForYouStaffReadonly = $staffReadonly;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title>Suggested for you</title>
  <?php theme_prefs_print_head_bootstrap($dbh, $meId); ?>
  <link rel="stylesheet" href="./css/dark-auto.css">
  <script src="./js/dark-auto.js?v=6" defer></script>
  <link rel="stylesheet" href="css/font-awesome.min.css">
  <link href="./lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="./lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link rel="stylesheet" href="./css/shamcey.css">
  <style>
    html, body { background: var(--msb-palette-bg, #f5f7fb); height: 100%; }
    body.sfy-page .sh-pagebody { padding-top: 12px; height: calc(100vh - 120px); overflow: hidden; box-sizing: border-box; }
    body.sfy-page .feed-left-rail { display: none !important; }
    <?php include __DIR__ . '/includes/feed_page_chrome.css.php'; ?>
  </style>
  <script src="./lib/jquery/jquery.js"></script>
</head>
<body class="sfy-page feed-insta-ui public-page">
  <?php
  $GLOBALS['msb_skip_header_leftbar'] = true;
  $forceFeedRail = true;
  $skipHeaderThemeBootstrap = true;
  include __DIR__ . '/includes/header.php';
  ?>
  <div class="sh-mainpanel">
    <?php include __DIR__ . '/includes/leftbar.php'; ?>
    <div class="sh-pagebody">
      <?php include __DIR__ . '/includes/suggested_for_you.php'; ?>
    </div>
  </div>
  <script>
  (function($){
    function applyStatus(btn, status){
      status = String(status || 'none');
      if(status === 'friends'){
        var row = btn.closest('.sfy-row');
        if(row) row.remove();
        return;
      }
      btn.classList.remove('primary','is-friends','is-pending','is-accept');
      if(status === 'incoming_pending'){
        btn.textContent = 'Accept';
        btn.classList.add('is-accept');
      } else if(status === 'outgoing_pending'){
        btn.textContent = 'Sent';
        btn.disabled = true;
        btn.classList.add('is-pending');
      } else {
        btn.textContent = '+';
        btn.disabled = false;
        btn.classList.add('primary');
      }
      btn.dataset.status = status;
    }

    function applyStatusForPeer(peerId, status){
      peerId = Number(peerId || 0);
      if(!peerId) return;
      document.querySelectorAll('.friend-btn[data-peer-id="'+String(peerId)+'"]').forEach(function(btn){
        applyStatus(btn, status);
      });
    }

    function applyFollowForPublisher(publisherId, following){
      publisherId = Number(publisherId || 0);
      if(!publisherId) return;
      var on = !!following;
      document.querySelectorAll('.publisher-follow-btn[data-publisher-id="'+String(publisherId)+'"]').forEach(function(el){
        el.classList.toggle('is-following', on);
        el.classList.toggle('primary', !on);
        el.textContent = on ? 'Following' : 'Follow';
        if(on){
          var row = el.closest('.sfy-row');
          if(row) row.remove();
        }
      });
    }

    $(document).on('click', '.publisher-follow-btn', function(){
      var btn = this;
      var id = btn.getAttribute('data-publisher-id') || '';
      if(!id) return;
      var fd = new FormData();
      fd.append('target_id', id);
      fetch('publisher_follow_toggle.php', { method:'POST', body: fd, cache:'no-store' })
        .then(function(r){ return r.json(); })
        .then(function(res){
          if(!res || !res.ok) return;
          applyFollowForPublisher(id, !!res.following);
        });
    });

    $(document).on('click', '.friend-btn', function(){
      var $btn = $(this), peerId = Number($btn.data('peer-id') || 0), status = String($btn.data('status') || '');
      if(!peerId) return;
      if(status === 'incoming_pending' || status === 'outgoing_pending'){
        window.location.href = 'contact_requests.php';
        return;
      }
      $btn.prop('disabled', true);
      $.post('ajax/friend_action.php', { action:'send', peer_id: peerId }, function(res){
        if(res && res.status){ applyStatusForPeer(peerId, String(res.status)); }
        $btn.prop('disabled', false);
      }, 'json').fail(function(){
        $btn.prop('disabled', false);
      });
    });

    $('.friend-btn').each(function(){
      var btn = this, peerId = Number(btn.getAttribute('data-peer-id') || '0');
      if(!peerId) return;
      $.getJSON('ajax/friend_status.php', { peer_id: peerId }, function(res){
        if(res && res.status) applyStatus(btn, String(res.status));
      });
    });
  })(jQuery);
  </script>
</body>
</html>
