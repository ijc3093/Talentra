<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();
require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/friend_system.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function watchFmt(?string $raw): string
{
    $raw = trim((string)$raw);
    if ($raw === '') {
        return '';
    }
    $ts = strtotime($raw);
    return $ts ? date('M d, Y h:i A', $ts) : $raw;
}

$controller = new Controller();
$dbh = $controller->pdo();
$meId = (int)($_SESSION['user_id'] ?? 0);
$liveId = (int)($_GET['live'] ?? 0);
$live = null;
$canView = false;
$errorMessage = '';

if ($liveId > 0) {
    try {
        $st = $dbh->prepare("
            SELECT l.*, u.name, u.username
            FROM user_video_lives l
            JOIN users u ON u.id = l.user_id
            WHERE l.id = :id
            LIMIT 1
        ");
        $st->execute([':id' => $liveId]);
        $live = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $live = null;
    }
}

if (!$live) {
    $errorMessage = 'Live room not found.';
} else {
    $ownerId = (int)($live['user_id'] ?? 0);
    $visibility = (string)($live['visibility'] ?? 'private');
    $canView = ($ownerId === $meId)
        || $visibility === 'public'
        || ($visibility === 'friends' && fs_are_friends($dbh, $meId, $ownerId));
    if (!$canView) {
        $errorMessage = 'You do not have access to this live room.';
    }
}

$title = trim((string)($live['title'] ?? 'Live room'));
$ownerName = trim((string)(($live['name'] ?? '') ?: ($live['username'] ?? '') ?: 'Host'));
$status = strtolower(trim((string)($live['status'] ?? 'draft')));
$visibility = strtolower(trim((string)($live['visibility'] ?? 'private')));
$embedMode = isset($_GET['embed']) && (string)$_GET['embed'] === '1';
$headerModalMode = isset($_GET['header_modal']) && (string)$_GET['header_modal'] === '1';
$snapshotEmbedMode = isset($_GET['snapshot_embed']) && (string)$_GET['snapshot_embed'] === '1';
$ownerInitials = 'H';
if ($ownerName !== '') {
    $parts = preg_split('/\s+/', $ownerName) ?: [];
    $parts = array_values(array_filter($parts, static function ($part) {
        return trim((string)$part) !== '';
    }));
    if ($parts) {
        $ownerInitials = strtoupper(substr((string)$parts[0], 0, 1));
        if (count($parts) > 1) {
            $ownerInitials .= strtoupper(substr((string)$parts[count($parts) - 1], 0, 1));
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Watch Live</title>
  <link href="./lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <style>
    :root {
      --bg: #eff3fb;
      --panel: #ffffff;
      --text: #152033;
      --muted: #677489;
      --line: #d8e0ef;
      --blue: #2b89f0;
      --red: #c3505f;
      --stage-fill: #5c88dc;
      --stage-fill-dark: #4e77ca;
      --stage-divider: rgba(27, 52, 105, 0.45);
      --shadow: 0 24px 60px rgba(71, 94, 135, 0.16);
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: ui-sans-serif, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      background: #0e1420;
      color: var(--text);
    }
    .watch-shell {
      min-height: 100vh;
      padding: 0;
    }
    .watch-frame {
      width: 100vw;
      min-height: 100vh;
      margin: 0 auto;
      background: #11131a;
      box-shadow: 0 30px 110px rgba(0,0,0,.48);
      overflow: hidden;
      display: grid;
      grid-template-rows: 60px minmax(0, 1fr) 66px;
    }
    .watch-head {
      padding: 0 12px;
      background: #171822;
      border-bottom: 1px solid rgba(255,255,255,.06);
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto;
      align-items: center;
      gap: 18px;
      color: #fff;
    }
    .watch-head-left {
      display: flex;
      align-items: center;
      gap: 12px;
      min-width: 0;
    }
    .watch-title {
      min-width: 0;
      display: grid;
      gap: 2px;
    }
    .watch-title-row {
      display: flex;
      align-items: center;
      gap: 10px;
      min-width: 0;
    }
    .watch-title h1 {
      margin: 0;
      font-size: 27px;
      font-weight: 800;
      letter-spacing: -.03em;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .watch-title p {
      display: block;
      margin: 0;
      font-size: 13px;
      color: rgba(255,255,255,.7);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .watch-head-right {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 10px;
    }
    .watch-top-meta {
      font-size: 12px;
      color: rgba(255,255,255,.72);
      font-weight: 700;
      white-space: nowrap;
    }
    .chip {
      padding: 0 20px;
      min-height: 40px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 999px;
      border: 1px solid #55617a;
      background: rgba(64, 72, 86, .55);
      color: rgba(255,255,255,.88);
      font-size: 12px;
      font-weight: 900;
      text-transform: uppercase;
      letter-spacing: .08em;
    }
    .chip.live { background: #ff5c3d; color: #fff; border-color: transparent; }
    .chip.public { background: #e7f7ee; color: #24714a; }
    .chip.friends { background: #fff2d8; color: #8b6500; }
    .chip.private { background: #eef2f7; color: #5b6474; }
    .watch-speaker-btn {
      display: inline-grid;
      place-items: center;
      width: 42px;
      height: 42px;
      padding: 0;
      border: 0;
      border-radius: 10px;
      background: rgba(255,255,255,.07);
      color: #fff;
      font-size: 16px;
      cursor: pointer;
      box-shadow: inset 0 1px 0 rgba(255,255,255,.05);
    }
    .watch-top-end {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 42px;
      padding: 0 18px;
      border: 0;
      border-radius: 12px;
      background: #6f3c2f;
      color: #fff;
      font-size: 14px;
      font-weight: 800;
      cursor: pointer;
    }
    .watch-grid {
      display: grid;
      grid-template-columns: minmax(0, 1fr) 0;
      gap: 0;
      min-height: 0;
      background: #0e1118;
      transition: grid-template-columns .22s ease;
      position: relative;
      overflow: hidden;
    }
    .watch-frame.has-chat .watch-grid {
      grid-template-columns: minmax(0, 1fr) 360px;
    }
    .watch-stage {
      padding: 0;
      min-width: 0;
      min-height: 0;
    }
    .watch-sidebar {
      min-width: 0;
      background: #050505;
      border-left: 1px solid rgba(255,255,255,.14);
      color: #f5f5f5;
      display: none;
      grid-template-rows: auto minmax(0, 1fr) auto;
      min-height: 0;
      height: 100%;
      padding: 0;
      overflow: hidden;
    }
    .watch-frame.has-chat .watch-sidebar {
      display: grid;
    }
    .watch-frame.sidebar-mode-reactions #watchChatPanel,
    .watch-frame.sidebar-mode-reactions #watchCompose {
      display: none;
    }
    .watch-frame.sidebar-mode-reactions #watchReactionPanel {
      display: flex;
    }
    .watch-frame.sidebar-mode-chat #watchReactionPanel,
    .watch-frame.sidebar-mode-chat #watchDescriptionPanel {
      display: none;
    }
    .watch-frame.sidebar-mode-description #watchChatPanel,
    .watch-frame.sidebar-mode-description #watchReactionPanel,
    .watch-frame.sidebar-mode-description #watchSettingsPanel,
    .watch-frame.sidebar-mode-description #watchCompose {
      display: none;
    }
    .watch-frame.sidebar-mode-description #watchDescriptionPanel {
      display: flex;
    }
    .watch-frame.sidebar-mode-settings #watchChatPanel,
    .watch-frame.sidebar-mode-settings #watchReactionPanel,
    .watch-frame.sidebar-mode-settings #watchDescriptionPanel,
    .watch-frame.sidebar-mode-settings #watchCompose {
      display: none;
    }
    .watch-frame.sidebar-mode-settings #watchSettingsPanel {
      display: block;
    }
    .watch-frame.sidebar-mode-description .watch-side-stats {
      display: none;
    }
    .watch-frame.sidebar-mode-settings .watch-side-stats {
      display: none;
    }
    .watch-frame.sidebar-mode-description .watch-side-scroll {
      padding: 0;
      background: #252a30;
    }
    .watch-frame.sidebar-mode-settings .watch-side-scroll {
      padding: 0;
      background: #252a30;
      overflow-y: auto;
    }
    .watch-frame.sidebar-mode-description .watch-side-head {
      min-height: 0;
      padding: 0;
      border-bottom: 0;
      background: transparent;
      position: relative;
    }
    .watch-frame.sidebar-mode-description .watch-side-title {
      display: none;
    }
    .watch-frame.sidebar-mode-settings .watch-side-head {
      min-height: 0;
      padding: 20px 24px 14px;
      border-bottom: 0;
      background: #252a30;
      position: relative;
    }
    .watch-frame.sidebar-mode-settings .watch-side-title {
      display: flex;
    }
    .watch-frame.sidebar-mode-description #watchSidebarClose {
      position: absolute;
      top: 36px;
      right: 24px;
      z-index: 3;
      width: 56px !important;
      height: 56px !important;
      border-radius: 50% !important;
      background: rgba(255,255,255,.12) !important;
      color: #f8fafc !important;
      box-shadow: none !important;
    }
    .watch-frame.sidebar-mode-chat #watchReactionPanel,
    .watch-frame.sidebar-mode-description #watchReactionPanel {
      display: none;
    }
    .stage-screen {
      min-height: 100%;
      border-radius: 0;
      background: linear-gradient(180deg, var(--stage-fill) 0%, var(--stage-fill-dark) 100%);
      color: #fff;
      position: relative;
      overflow: hidden;
      padding: 0;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
    }
    .stage-screen::after {
      content: "";
      position: absolute;
      inset: 0;
      pointer-events: none;
      opacity: 0;
      transition: opacity .18s ease;
    }
    .stage-image {
      position: absolute;
      inset: 0;
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: none;
      background: var(--stage-fill);
    }
    .stage-video {
      position: absolute;
      inset: 0;
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: none;
      background: var(--stage-fill);
    }
    .watch-stage-reactions {
      position: absolute;
      inset: 0;
      z-index: 4;
      pointer-events: none;
      overflow: hidden;
    }
    .watch-stage-reaction {
      position: absolute;
      right: 34px;
      bottom: 80px;
      width: 68px;
      height: 68px;
      border-radius: 50%;
      display: grid;
      place-items: center;
      font-size: 42px;
      line-height: 1;
      background: radial-gradient(circle at 35% 30%, rgba(255,255,255,.96), rgba(255,255,255,.72));
      box-shadow: 0 14px 28px rgba(0,0,0,.18);
      animation: watchStageReactionFloat 5s ease-out forwards;
      filter: drop-shadow(0 12px 18px rgba(255,255,255,.15));
      opacity: 0;
    }
    .watch-stage-reaction.is-love {
      color: #ec4899;
    }
    @keyframes watchStageReactionFloat {
      0% { opacity: 0; transform: translate3d(0, 18px, 0) scale(.78); filter: blur(5px); }
      12% { opacity: 1; transform: translate3d(0, 0, 0) scale(1); filter: blur(0); }
      82% { opacity: 1; transform: translate3d(-10px, -126px, 0) scale(1.04); filter: blur(0); }
      100% { opacity: 0; transform: translate3d(-18px, -168px, 0) scale(1.08); filter: blur(8px); }
    }
    .guest-self-tile {
      position: absolute;
      right: 18px;
      bottom: 18px;
      width: 180px;
      z-index: 3;
      display: none;
      border-radius: 0;
      overflow: hidden;
      border: 1px solid rgba(255,255,255,0.18);
      background: rgba(10, 18, 34, 0.92);
      box-shadow: 0 18px 34px rgba(0,0,0,0.28);
    }
    .guest-self-tile.is-active {
      display: block;
    }
    .guest-self-video {
      width: 100%;
      height: 126px;
      display: block;
      object-fit: cover;
      background: #000;
    }
    .guest-self-meta {
      padding: 10px 12px;
      color: #fff;
      font-size: 12px;
      font-weight: 800;
    }
    .guest-audience-layer {
      position: absolute;
      right: 18px;
      bottom: 18px;
      display: grid;
      gap: 12px;
      z-index: 3;
      justify-items: end;
    }
    .guest-audience-tile {
      width: 180px;
      border-radius: 0;
      overflow: hidden;
      border: 1px solid rgba(255,255,255,0.18);
      background: rgba(10, 18, 34, 0.92);
      box-shadow: 0 18px 34px rgba(0,0,0,0.28);
    }
    .guest-audience-image {
      width: 100%;
      height: 126px;
      display: block;
      object-fit: cover;
      background: #000;
    }
    .guest-audience-video {
      width: 100%;
      height: 126px;
      display: none;
      object-fit: cover;
      background: #000;
    }
    .guest-audience-placeholder {
      width: 100%;
      height: 126px;
      display: flex;
      align-items: center;
      justify-content: center;
      background:
        radial-gradient(160px 110px at 50% 18%, rgba(255,255,255,.16), transparent 62%),
        linear-gradient(180deg, #232a37 0%, #121720 100%);
      color: #fff;
    }
    .guest-audience-placeholder-badge {
      width: 56px;
      height: 56px;
      border-radius: 999px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 19px;
      font-weight: 900;
      letter-spacing: .04em;
      background: rgba(255,255,255,.12);
      border: 1px solid rgba(255,255,255,.16);
      box-shadow: 0 10px 24px rgba(0,0,0,.22);
    }
    .guest-audience-camera-off {
      position: absolute;
      inset: 0 0 auto 0;
      height: 126px;
      z-index: 2;
      display: none;
      align-items: center;
      justify-content: center;
      background: #000;
      pointer-events: none;
    }
    .guest-audience-camera-off::before {
      content: '';
      position: absolute;
      left: 50%;
      top: 50%;
      width: min(78%, 240px);
      height: 3px;
      border-radius: 999px;
      background: #ff4d3b;
      transform: translate(-50%, -50%) rotate(-28deg);
      box-shadow: 0 0 0 1px rgba(255, 77, 59, 0.12);
    }
    .guest-audience-camera-off-icon {
      position: relative;
      width: 68px;
      height: 52px;
      color: #f2f5fb;
      display: grid;
      place-items: center;
      filter: drop-shadow(0 6px 14px rgba(0, 0, 0, 0.42));
    }
    .guest-audience-camera-off-icon .fa {
      font-size: 40px;
      line-height: 1;
    }
    .guest-audience-tile.has-webrtc .guest-audience-video {
      display: block;
    }
    .guest-audience-tile.has-webrtc .guest-audience-image {
      display: none;
    }
    .guest-audience-tile.has-webrtc .guest-audience-placeholder {
      display: none;
    }
    .guest-audience-tile.snapshot-ready .guest-audience-placeholder {
      display: none;
    }
    .guest-audience-tile.is-camera-off .guest-audience-video,
    .guest-audience-tile.is-camera-off .guest-audience-image,
    .guest-audience-tile.is-camera-off .guest-audience-placeholder {
      display: none !important;
    }
    .guest-audience-tile.is-camera-off .guest-audience-camera-off {
      display: flex;
    }
    .guest-audience-meta {
      padding: 10px 12px;
      color: #fff;
      font-size: 12px;
      font-weight: 800;
    }
    .stage-screen.has-dual-stage .stage-image,
    .stage-screen.has-dual-stage .stage-video,
    .stage-screen.has-three-stage .stage-image,
    .stage-screen.has-three-stage .stage-video,
    .stage-screen.has-four-stage .stage-image,
    .stage-screen.has-four-stage .stage-video,
    .stage-screen.has-four-stage-self .stage-image,
    .stage-screen.has-four-stage-self .stage-video,
    .stage-screen.has-five-stage .stage-image,
    .stage-screen.has-five-stage .stage-video,
    .stage-screen.has-five-stage-self .stage-image,
    .stage-screen.has-five-stage-self .stage-video,
    .stage-screen.has-six-stage .stage-image,
    .stage-screen.has-six-stage .stage-video,
    .stage-screen.has-six-stage-self .stage-image,
    .stage-screen.has-six-stage-self .stage-video,
    .stage-screen.has-seven-stage .stage-image,
    .stage-screen.has-seven-stage .stage-video,
    .stage-screen.has-seven-stage-self .stage-image,
    .stage-screen.has-seven-stage-self .stage-video,
    .stage-screen.has-eight-stage .stage-image,
    .stage-screen.has-eight-stage .stage-video,
    .stage-screen.has-eight-stage-self .stage-image,
    .stage-screen.has-eight-stage-self .stage-video,
    .stage-screen.has-nine-stage .stage-image,
    .stage-screen.has-nine-stage .stage-video,
    .stage-screen.has-nine-stage-self .stage-image,
    .stage-screen.has-nine-stage-self .stage-video,
    .stage-screen.has-ten-stage .stage-image,
    .stage-screen.has-ten-stage .stage-video,
    .stage-screen.has-ten-stage-self .stage-image,
    .stage-screen.has-ten-stage-self .stage-video,
    .stage-screen.has-eleven-stage .stage-image,
    .stage-screen.has-eleven-stage .stage-video,
    .stage-screen.has-eleven-stage-self .stage-image,
    .stage-screen.has-eleven-stage-self .stage-video,
    .stage-screen.has-twelve-stage .stage-image,
    .stage-screen.has-twelve-stage .stage-video,
    .stage-screen.has-twelve-stage-self .stage-image,
    .stage-screen.has-twelve-stage-self .stage-video,
    .stage-screen.has-thirteen-stage .stage-image,
    .stage-screen.has-thirteen-stage .stage-video,
    .stage-screen.has-thirteen-stage-self .stage-image,
    .stage-screen.has-thirteen-stage-self .stage-video,
    .stage-screen.has-gallery-stage .stage-image,
    .stage-screen.has-gallery-stage .stage-video {
      inset: 14px auto 14px 14px;
      width: calc(50% - 21px);
      height: calc(100% - 28px);
      border-radius: 0;
      border: 1px solid rgba(255,255,255,0.12);
    }
    .stage-screen.has-dual-stage .guest-self-tile,
    .stage-screen.has-dual-stage .guest-audience-layer,
    .stage-screen.has-three-stage .guest-self-tile,
    .stage-screen.has-three-stage .guest-audience-layer,
    .stage-screen.has-four-stage .guest-self-tile,
    .stage-screen.has-four-stage .guest-audience-layer,
    .stage-screen.has-four-stage-self .guest-self-tile,
    .stage-screen.has-four-stage-self .guest-audience-layer,
    .stage-screen.has-five-stage .guest-self-tile,
    .stage-screen.has-five-stage .guest-audience-layer,
    .stage-screen.has-five-stage-self .guest-self-tile,
    .stage-screen.has-five-stage-self .guest-audience-layer,
    .stage-screen.has-six-stage .guest-self-tile,
    .stage-screen.has-six-stage .guest-audience-layer,
    .stage-screen.has-six-stage-self .guest-self-tile,
    .stage-screen.has-six-stage-self .guest-audience-layer,
    .stage-screen.has-seven-stage .guest-self-tile,
    .stage-screen.has-seven-stage .guest-audience-layer,
    .stage-screen.has-seven-stage-self .guest-self-tile,
    .stage-screen.has-seven-stage-self .guest-audience-layer,
    .stage-screen.has-eight-stage .guest-self-tile,
    .stage-screen.has-eight-stage .guest-audience-layer,
    .stage-screen.has-eight-stage-self .guest-self-tile,
    .stage-screen.has-eight-stage-self .guest-audience-layer,
    .stage-screen.has-nine-stage .guest-self-tile,
    .stage-screen.has-nine-stage .guest-audience-layer,
    .stage-screen.has-nine-stage-self .guest-self-tile,
    .stage-screen.has-nine-stage-self .guest-audience-layer,
    .stage-screen.has-ten-stage .guest-self-tile,
    .stage-screen.has-ten-stage .guest-audience-layer,
    .stage-screen.has-ten-stage-self .guest-self-tile,
    .stage-screen.has-ten-stage-self .guest-audience-layer,
    .stage-screen.has-eleven-stage .guest-self-tile,
    .stage-screen.has-eleven-stage .guest-audience-layer,
    .stage-screen.has-eleven-stage-self .guest-self-tile,
    .stage-screen.has-eleven-stage-self .guest-audience-layer,
    .stage-screen.has-twelve-stage .guest-self-tile,
    .stage-screen.has-twelve-stage .guest-audience-layer,
    .stage-screen.has-twelve-stage-self .guest-self-tile,
    .stage-screen.has-twelve-stage-self .guest-audience-layer,
    .stage-screen.has-thirteen-stage .guest-self-tile,
    .stage-screen.has-thirteen-stage .guest-audience-layer,
    .stage-screen.has-thirteen-stage-self .guest-self-tile,
    .stage-screen.has-thirteen-stage-self .guest-audience-layer,
    .stage-screen.has-gallery-stage .guest-self-tile,
    .stage-screen.has-gallery-stage .guest-audience-layer {
      top: 14px;
      right: 14px;
      bottom: 14px;
      width: calc(50% - 21px);
    }
    .stage-screen.has-dual-stage .guest-self-video,
    .stage-screen.has-three-stage .guest-self-video,
    .stage-screen.has-four-stage .guest-self-video,
    .stage-screen.has-four-stage-self .guest-self-video,
    .stage-screen.has-five-stage .guest-self-video,
    .stage-screen.has-five-stage-self .guest-self-video,
    .stage-screen.has-six-stage .guest-self-video,
    .stage-screen.has-six-stage-self .guest-self-video,
    .stage-screen.has-seven-stage .guest-self-video,
    .stage-screen.has-seven-stage-self .guest-self-video,
    .stage-screen.has-eight-stage .guest-self-video,
    .stage-screen.has-eight-stage-self .guest-self-video,
    .stage-screen.has-nine-stage .guest-self-video,
    .stage-screen.has-nine-stage-self .guest-self-video,
    .stage-screen.has-ten-stage .guest-self-video,
    .stage-screen.has-ten-stage-self .guest-self-video,
    .stage-screen.has-eleven-stage .guest-self-video,
    .stage-screen.has-eleven-stage-self .guest-self-video,
    .stage-screen.has-twelve-stage .guest-self-video,
    .stage-screen.has-twelve-stage-self .guest-self-video,
    .stage-screen.has-thirteen-stage .guest-self-video,
    .stage-screen.has-thirteen-stage-self .guest-self-video,
    .stage-screen.has-gallery-stage .guest-self-video {
      height: calc(100% - 40px);
      min-height: 240px;
    }
    .stage-screen.has-dual-stage .stage-image,
    .stage-screen.has-dual-stage .stage-video {
      inset: 0 auto 0 0;
      width: calc(50% - 1px);
      height: 100%;
      border: 0;
      border-right: 0;
    }
    .stage-screen.has-dual-stage::after {
      left: calc(50% - 1px);
      right: auto;
      width: 2px;
      background: var(--stage-divider);
      opacity: 1;
    }
    .stage-screen.has-dual-stage .guest-self-tile,
    .stage-screen.has-dual-stage .guest-audience-layer {
      top: 0;
      right: 0;
      bottom: 0;
      width: calc(50% - 1px);
    }
    .stage-screen.has-dual-stage .guest-self-tile {
      border: 0;
      box-shadow: none;
      background: var(--stage-fill);
    }
    .stage-screen.has-dual-stage .guest-self-video {
      height: 100%;
      min-height: 100%;
    }
    .stage-screen.has-dual-stage .guest-self-meta {
      display: none;
    }
    .stage-screen.has-dual-stage .guest-audience-layer {
      display: flex;
      align-items: stretch;
      justify-content: stretch;
    }
    .stage-screen.has-dual-stage .guest-audience-tile {
      width: 100%;
      height: 100%;
      border: 0;
      box-shadow: none;
      background: var(--stage-fill);
      position: relative;
    }
    .stage-screen.has-dual-stage .guest-audience-image,
    .stage-screen.has-dual-stage .guest-audience-video {
      height: 100%;
      min-height: 100%;
    }
    .stage-screen.has-dual-stage .guest-audience-camera-off {
      inset: 0;
      height: 100%;
    }
    .stage-screen.has-dual-stage .guest-audience-meta {
      display: none;
    }
    .stage-screen.has-three-stage .stage-image,
    .stage-screen.has-three-stage .stage-video,
    .stage-screen.has-three-stage-self .stage-image,
    .stage-screen.has-three-stage-self .stage-video {
      inset: 0 auto 0 0;
      width: calc(50% - 1px);
      height: 100%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
    }
    .stage-screen.has-three-stage .guest-audience-layer {
      top: 0;
      right: 0;
      bottom: 0;
      width: calc(50% - 1px);
      display: grid;
      grid-template-columns: 1fr;
      grid-template-rows: repeat(2, minmax(0, 1fr));
      gap: 0;
      align-items: stretch;
    }
    .stage-screen.has-three-stage .guest-audience-tile {
      width: 100%;
      height: 100%;
      min-height: 0;
      border: 0;
      box-shadow: none;
      background: var(--stage-fill);
      position: relative;
    }
    .stage-screen.has-three-stage .guest-audience-tile:first-child {
      border-bottom: 2px solid rgba(8, 12, 20, .8);
    }
    .stage-screen.has-three-stage .guest-audience-image,
    .stage-screen.has-three-stage .guest-audience-video {
      height: 100%;
      min-height: 100%;
    }
    .stage-screen.has-three-stage .guest-audience-camera-off {
      inset: 0;
      height: 100%;
    }
    .stage-screen.has-three-stage-self .guest-audience-camera-off {
      inset: 0;
      height: 100%;
    }
    .stage-screen.has-three-stage .guest-audience-meta {
      position: absolute;
      top: 16px;
      left: 18px;
      right: auto;
      bottom: auto;
      padding: 0;
      background: transparent;
      text-shadow: 0 1px 3px rgba(0,0,0,.48);
      z-index: 2;
    }
    .stage-screen.has-three-stage-self .guest-self-tile,
    .stage-screen.has-three-stage-self .guest-audience-layer {
      top: 0;
      bottom: 0;
      width: calc(50% - 1px);
    }
    .stage-screen.has-three-stage-self .guest-self-tile {
      right: 0;
      left: auto;
      top: 0;
      bottom: auto;
      height: 50%;
      border: 0;
      border-bottom: 2px solid rgba(8, 12, 20, .8);
      box-shadow: none;
      background: var(--stage-fill);
    }
    .stage-screen.has-three-stage-self .guest-self-video {
      height: 100%;
      min-height: 100%;
    }
    .stage-screen.has-four-stage .guest-audience-camera-off,
    .stage-screen.has-four-stage-self .guest-audience-camera-off,
    .stage-screen.has-five-stage .guest-audience-camera-off,
    .stage-screen.has-five-stage-self .guest-audience-camera-off,
    .stage-screen.has-six-stage .guest-audience-camera-off,
    .stage-screen.has-six-stage-self .guest-audience-camera-off,
    .stage-screen.has-seven-stage .guest-audience-camera-off,
    .stage-screen.has-seven-stage-self .guest-audience-camera-off,
    .stage-screen.has-eight-stage .guest-audience-camera-off,
    .stage-screen.has-eight-stage-self .guest-audience-camera-off,
    .stage-screen.has-nine-stage .guest-audience-camera-off,
    .stage-screen.has-nine-stage-self .guest-audience-camera-off,
    .stage-screen.has-ten-stage .guest-audience-camera-off,
    .stage-screen.has-ten-stage-self .guest-audience-camera-off,
    .stage-screen.has-eleven-stage .guest-audience-camera-off,
    .stage-screen.has-eleven-stage-self .guest-audience-camera-off,
    .stage-screen.has-twelve-stage .guest-audience-camera-off,
    .stage-screen.has-twelve-stage-self .guest-audience-camera-off,
    .stage-screen.has-thirteen-stage .guest-audience-camera-off,
    .stage-screen.has-thirteen-stage-self .guest-audience-camera-off,
    .stage-screen.has-fourteen-stage .guest-audience-camera-off,
    .stage-screen.has-fourteen-stage-self .guest-audience-camera-off,
    .stage-screen.has-fifteen-stage .guest-audience-camera-off,
    .stage-screen.has-fifteen-stage-self .guest-audience-camera-off,
    .stage-screen.has-sixteen-stage .guest-audience-camera-off,
    .stage-screen.has-sixteen-stage-self .guest-audience-camera-off,
    .stage-screen.has-seventeen-stage .guest-audience-camera-off,
    .stage-screen.has-seventeen-stage-self .guest-audience-camera-off,
    .stage-screen.has-eighteen-stage .guest-audience-camera-off,
    .stage-screen.has-eighteen-stage-self .guest-audience-camera-off,
    .stage-screen.has-nineteen-stage .guest-audience-camera-off,
    .stage-screen.has-nineteen-stage-self .guest-audience-camera-off,
    .stage-screen.has-twenty-stage .guest-audience-camera-off,
    .stage-screen.has-twenty-stage-self .guest-audience-camera-off,
    .stage-screen.has-twentyone-stage .guest-audience-camera-off,
    .stage-screen.has-twentyone-stage-self .guest-audience-camera-off,
    .stage-screen.has-twentytwo-stage .guest-audience-camera-off,
    .stage-screen.has-twentytwo-stage-self .guest-audience-camera-off,
    .stage-screen.has-twentythree-stage .guest-audience-camera-off,
    .stage-screen.has-twentythree-stage-self .guest-audience-camera-off,
    .stage-screen.has-twentyfour-stage .guest-audience-camera-off,
    .stage-screen.has-twentyfour-stage-self .guest-audience-camera-off,
    .stage-screen.has-twentyfive-stage .guest-audience-camera-off,
    .stage-screen.has-twentyfive-stage-self .guest-audience-camera-off,
    .stage-screen.has-gallery-stage .guest-audience-camera-off,
    .stage-screen.has-host-layout .guest-audience-camera-off,
    .stage-screen.has-host-layout-self .guest-audience-camera-off {
      inset: 0;
      height: 100%;
    }
    .stage-screen.has-three-stage-self .guest-self-meta {
      position: absolute;
      top: 16px;
      left: 18px;
      right: auto;
      bottom: auto;
      padding: 0;
      background: transparent;
      text-shadow: 0 1px 3px rgba(0,0,0,.48);
      z-index: 2;
    }
    .stage-screen.has-three-stage-self .guest-audience-layer {
      right: 0;
      top: 50%;
      display: flex;
      align-items: stretch;
      justify-content: stretch;
    }
    .stage-screen.has-three-stage-self .guest-audience-tile {
      width: 100%;
      height: 100%;
      border: 0;
      box-shadow: none;
      background: var(--stage-fill);
      position: relative;
    }
    .stage-screen.has-three-stage-self .guest-audience-image,
    .stage-screen.has-three-stage-self .guest-audience-video {
      height: 100%;
      min-height: 100%;
    }
    .stage-screen.has-three-stage-self .guest-audience-meta {
      position: absolute;
      top: 16px;
      left: 18px;
      right: auto;
      bottom: auto;
      padding: 0;
      background: transparent;
      text-shadow: 0 1px 3px rgba(0,0,0,.48);
      z-index: 2;
    }
    .stage-screen.has-four-stage .stage-image,
    .stage-screen.has-four-stage .stage-video,
    .stage-screen.has-four-stage-self .stage-image,
    .stage-screen.has-four-stage-self .stage-video {
      inset: 0 auto 0 0;
      width: 50%;
      height: 100%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
    }
    .stage-screen.has-four-stage .guest-audience-layer {
      top: 0;
      right: 0;
      bottom: 0;
      width: 50%;
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      grid-template-rows: repeat(2, minmax(0, 1fr));
      gap: 0;
      align-items: stretch;
      justify-items: stretch;
    }
    .stage-screen.has-four-stage .guest-audience-tile {
      width: 100%;
      height: 100%;
      min-height: 0;
      border: 0;
      box-shadow: none;
      background: var(--stage-fill);
      position: relative;
    }
    .stage-screen.has-four-stage .guest-audience-tile:nth-child(1) {
      grid-column: 1 / span 2;
      grid-row: 1;
      border-bottom: 2px solid rgba(8, 12, 20, .8);
    }
    .stage-screen.has-four-stage .guest-audience-tile:nth-child(2) {
      grid-column: 1;
      grid-row: 2;
      border-right: 2px solid rgba(8, 12, 20, .8);
    }
    .stage-screen.has-four-stage .guest-audience-tile:nth-child(3) {
      grid-column: 2;
      grid-row: 2;
    }
    .stage-screen.has-four-stage .guest-audience-image,
    .stage-screen.has-four-stage .guest-audience-video {
      height: 100%;
      min-height: 100%;
    }
    .stage-screen.has-four-stage .guest-audience-meta {
      position: absolute;
      top: 16px;
      left: 18px;
      right: auto;
      bottom: auto;
      padding: 0;
      background: transparent;
      text-shadow: 0 1px 3px rgba(0,0,0,.48);
      z-index: 2;
    }
    .stage-screen.has-four-stage-self .guest-self-tile {
      top: 0;
      right: 0;
      bottom: auto;
      left: auto;
      width: 50%;
      height: 50%;
      border: 0;
      border-bottom: 2px solid rgba(8, 12, 20, .8);
      box-shadow: none;
      background: var(--stage-fill);
    }
    .stage-screen.has-four-stage-self .guest-self-video {
      height: 100%;
      min-height: 100%;
    }
    .stage-screen.has-four-stage-self .guest-self-meta {
      position: absolute;
      top: 16px;
      left: 18px;
      right: auto;
      bottom: auto;
      padding: 0;
      background: transparent;
      text-shadow: 0 1px 3px rgba(0,0,0,.48);
      z-index: 2;
    }
    .stage-screen.has-four-stage-self .guest-audience-layer {
      left: auto;
      right: 0;
      top: 50%;
      bottom: 0;
      width: 50%;
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 0;
      align-items: stretch;
      justify-items: stretch;
    }
    .stage-screen.has-four-stage-self .guest-audience-tile {
      width: 100%;
      height: 100%;
      border: 0;
      box-shadow: none;
      background: var(--stage-fill);
      position: relative;
    }
    .stage-screen.has-four-stage-self .guest-audience-tile:first-child {
      border-right: 2px solid rgba(8, 12, 20, .8);
    }
    .stage-screen.has-four-stage-self .guest-audience-image,
    .stage-screen.has-four-stage-self .guest-audience-video {
      height: 100%;
      min-height: 100%;
    }
    .stage-screen.has-four-stage-self .guest-audience-meta {
      position: absolute;
      top: 16px;
      left: 18px;
      right: auto;
      bottom: auto;
      padding: 0;
      background: transparent;
      text-shadow: 0 1px 3px rgba(0,0,0,.48);
      z-index: 2;
    }

    .stage-screen.has-five-stage .stage-image,
    .stage-screen.has-five-stage .stage-video,
    .stage-screen.has-five-stage-self .stage-image,
    .stage-screen.has-five-stage-self .stage-video {
      inset: 0 auto 0 0;
      width: 50%;
      height: 100%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
    }

    .stage-screen.has-five-stage .guest-audience-layer,
    .stage-screen.has-five-stage-self .guest-audience-layer {
      top: 0;
      right: 0;
      bottom: 0;
      width: 50%;
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      grid-template-rows: repeat(2, minmax(0, 1fr));
      gap: 0;
      align-items: stretch;
      justify-items: stretch;
    }

    .stage-screen.has-five-stage .guest-audience-tile,
    .stage-screen.has-five-stage-self .guest-audience-tile {
      width: 100%;
      height: 100%;
      min-height: 0;
      border: 0;
      box-shadow: none;
      background: var(--stage-fill);
      position: relative;
    }

    .stage-screen.has-five-stage .guest-audience-tile:nth-child(-n+2),
    .stage-screen.has-five-stage-self .guest-audience-tile:nth-child(-n+2) {
      border-bottom: 2px solid rgba(8, 12, 20, .8);
    }

    .stage-screen.has-five-stage .guest-audience-tile:nth-child(odd),
    .stage-screen.has-five-stage-self .guest-audience-tile:nth-child(odd) {
      border-right: 2px solid rgba(8, 12, 20, .8);
    }

    .stage-screen.has-five-stage .guest-audience-image,
    .stage-screen.has-five-stage .guest-audience-video,
    .stage-screen.has-five-stage-self .guest-audience-image,
    .stage-screen.has-five-stage-self .guest-audience-video {
      height: 100%;
      min-height: 100%;
    }

    .stage-screen.has-five-stage .guest-audience-meta,
    .stage-screen.has-five-stage-self .guest-audience-meta {
      position: absolute;
      top: 16px;
      left: 18px;
      right: auto;
      bottom: auto;
      padding: 0;
      background: transparent;
      text-shadow: 0 1px 3px rgba(0,0,0,.48);
      z-index: 2;
    }

    .stage-screen.has-five-stage-self .guest-self-tile {
      top: 0;
      left: 50%;
      right: auto;
      bottom: auto;
      width: 25%;
      height: 50%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 2px solid rgba(8, 12, 20, .8);
      box-shadow: none;
      background: var(--stage-fill);
      border-radius: 0;
    }

    .stage-screen.has-five-stage-self .guest-self-video {
      height: 100%;
      min-height: 100%;
    }

    .stage-screen.has-five-stage-self .guest-self-meta {
      position: absolute;
      top: 16px;
      left: 18px;
      right: auto;
      bottom: auto;
      padding: 0;
      background: transparent;
      text-shadow: 0 1px 3px rgba(0,0,0,.48);
      z-index: 2;
    }

    .stage-screen.has-five-stage-self .guest-audience-layer {
      left: auto;
      right: 0;
      top: 0;
      bottom: 0;
      width: 50%;
    }

    .stage-screen.has-five-stage-self .guest-audience-tile {
      border-radius: 0;
    }

    .stage-screen.has-five-stage-self .guest-audience-tile:nth-child(1) {
      grid-column: 2;
      grid-row: 1;
    }

    .stage-screen.has-five-stage-self .guest-audience-tile:nth-child(2) {
      grid-column: 1;
      grid-row: 2;
    }

    .stage-screen.has-five-stage-self .guest-audience-tile:nth-child(3) {
      grid-column: 2;
      grid-row: 2;
    }

    .stage-screen.has-six-stage .stage-image,
    .stage-screen.has-six-stage .stage-video,
    .stage-screen.has-six-stage-self .stage-image,
    .stage-screen.has-six-stage-self .stage-video {
      inset: 0 auto 0 0;
      width: 50%;
      height: 100%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
    }

    .stage-screen.has-six-stage .guest-audience-layer,
    .stage-screen.has-six-stage-self .guest-audience-layer {
      top: 0;
      right: 0;
      bottom: 0;
      width: 50%;
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      grid-template-rows: repeat(6, minmax(0, 1fr));
      gap: 0;
      align-items: stretch;
      justify-items: stretch;
    }

    .stage-screen.has-six-stage .guest-audience-tile,
    .stage-screen.has-six-stage-self .guest-audience-tile {
      width: 100%;
      height: 100%;
      min-height: 0;
      border: 0;
      box-shadow: none;
      background: var(--stage-fill);
      position: relative;
      border-radius: 0;
    }

    .stage-screen.has-six-stage .guest-audience-tile:nth-child(1),
    .stage-screen.has-six-stage-self .guest-audience-tile:nth-child(1) {
      grid-column: 1;
      grid-row: 1 / span 3;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 2px solid rgba(8, 12, 20, .8);
    }

    .stage-screen.has-six-stage .guest-audience-tile:nth-child(2),
    .stage-screen.has-six-stage-self .guest-audience-tile:nth-child(2) {
      grid-column: 1;
      grid-row: 4 / span 3;
      border-right: 2px solid rgba(8, 12, 20, .8);
    }

    .stage-screen.has-six-stage .guest-audience-tile:nth-child(3),
    .stage-screen.has-six-stage-self .guest-audience-tile:nth-child(3) {
      grid-column: 2;
      grid-row: 1 / span 2;
      border-bottom: 2px solid rgba(8, 12, 20, .8);
    }

    .stage-screen.has-six-stage .guest-audience-tile:nth-child(4),
    .stage-screen.has-six-stage-self .guest-audience-tile:nth-child(4) {
      grid-column: 2;
      grid-row: 3 / span 2;
      border-bottom: 2px solid rgba(8, 12, 20, .8);
    }

    .stage-screen.has-six-stage .guest-audience-tile:nth-child(5),
    .stage-screen.has-six-stage-self .guest-audience-tile:nth-child(5) {
      grid-column: 2;
      grid-row: 5 / span 2;
    }

    .stage-screen.has-six-stage .guest-audience-image,
    .stage-screen.has-six-stage .guest-audience-video,
    .stage-screen.has-six-stage-self .guest-audience-image,
    .stage-screen.has-six-stage-self .guest-audience-video {
      height: 100%;
      min-height: 100%;
    }

    .stage-screen.has-six-stage .guest-audience-meta,
    .stage-screen.has-six-stage-self .guest-audience-meta {
      position: absolute;
      top: 16px;
      left: 18px;
      right: auto;
      bottom: auto;
      padding: 0;
      background: transparent;
      text-shadow: 0 1px 3px rgba(0,0,0,.48);
      z-index: 2;
    }

    .stage-screen.has-six-stage-self .guest-self-tile {
      top: 0;
      left: 50%;
      right: auto;
      bottom: auto;
      width: 25%;
      height: 50%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 2px solid rgba(8, 12, 20, .8);
      box-shadow: none;
      background: var(--stage-fill);
      border-radius: 0;
    }

    .stage-screen.has-six-stage-self .guest-self-video {
      height: 100%;
      min-height: 100%;
    }

    .stage-screen.has-six-stage-self .guest-self-meta {
      position: absolute;
      top: 16px;
      left: 18px;
      right: auto;
      bottom: auto;
      padding: 0;
      background: transparent;
      text-shadow: 0 1px 3px rgba(0,0,0,.48);
      z-index: 2;
    }

    .stage-screen.has-six-stage-self .guest-audience-tile:nth-child(1) {
      grid-column: 1;
      grid-row: 4 / span 3;
    }

    .stage-screen.has-six-stage-self .guest-audience-tile:nth-child(2) {
      grid-column: 2;
      grid-row: 1 / span 2;
      border-right: 0;
    }

    .stage-screen.has-six-stage-self .guest-audience-tile:nth-child(3) {
      grid-column: 2;
      grid-row: 3 / span 2;
    }

    .stage-screen.has-six-stage-self .guest-audience-tile:nth-child(4) {
      grid-column: 2;
      grid-row: 5 / span 2;
      border-bottom: 0;
    }
    .stage-screen.has-gallery-stage .guest-self-tile,
    .stage-screen.has-gallery-stage .guest-audience-layer {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      grid-auto-rows: minmax(148px, 1fr);
      gap: 12px;
      align-items: stretch;
      justify-items: stretch;
      overflow: auto;
    }
    .stage-screen.has-gallery-stage .guest-audience-tile {
      width: 100%;
      height: auto;
      min-height: 0;
    }
    .stage-screen.has-gallery-stage .guest-audience-image,
    .stage-screen.has-gallery-stage .guest-audience-video,
    .stage-screen.has-gallery-stage .guest-self-video {
      height: calc(100% - 40px);
      min-height: 148px;
    }

    .stage-screen.has-six-stage .stage-image,
    .stage-screen.has-six-stage .stage-video,
    .stage-screen.has-six-stage-self .stage-image,
    .stage-screen.has-six-stage-self .stage-video {
      inset: 0 auto 0 0;
      width: 50%;
      height: 100%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 0;
      background: var(--stage-fill);
    }

    .stage-screen.has-six-stage .guest-audience-layer,
    .stage-screen.has-six-stage-self .guest-audience-layer {
      top: 0;
      right: 0;
      bottom: 0;
      left: auto;
      width: 50%;
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      grid-template-rows: repeat(6, minmax(0, 1fr));
      gap: 2px;
      padding: 0;
      background: rgba(8, 12, 20, .8);
      align-items: stretch;
      justify-items: stretch;
    }

    .stage-screen.has-six-stage .guest-audience-tile,
    .stage-screen.has-six-stage-self .guest-audience-tile {
      background: var(--stage-fill);
      border: 0;
      box-shadow: none;
      border-radius: 0;
    }

    .stage-screen.has-six-stage .guest-audience-image,
    .stage-screen.has-six-stage .guest-audience-video,
    .stage-screen.has-six-stage-self .guest-audience-image,
    .stage-screen.has-six-stage-self .guest-audience-video,
    .stage-screen.has-six-stage-self .guest-self-video {
      height: 100%;
      min-height: 100%;
      object-fit: cover;
      object-position: center center;
      background: var(--stage-fill);
    }

    .stage-screen.has-six-stage .guest-audience-tile:nth-child(1) { grid-column: 1; grid-row: 1 / span 3; }
    .stage-screen.has-six-stage .guest-audience-tile:nth-child(2) { grid-column: 1; grid-row: 4 / span 3; }
    .stage-screen.has-six-stage .guest-audience-tile:nth-child(3) { grid-column: 2; grid-row: 1 / span 2; }
    .stage-screen.has-six-stage .guest-audience-tile:nth-child(4) { grid-column: 2; grid-row: 3 / span 2; }
    .stage-screen.has-six-stage .guest-audience-tile:nth-child(5) { grid-column: 2; grid-row: 5 / span 2; }

    .stage-screen.has-six-stage-self .guest-self-tile {
      top: 0;
      left: 50%;
      right: auto;
      bottom: auto;
      width: 25%;
      height: 50%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 2px solid rgba(8, 12, 20, .8);
      box-shadow: none;
      background: var(--stage-fill);
      border-radius: 0;
    }

    .stage-screen.has-six-stage-self .guest-audience-tile:nth-child(1) { grid-column: 1; grid-row: 4 / span 3; }
    .stage-screen.has-six-stage-self .guest-audience-tile:nth-child(2) { grid-column: 2; grid-row: 1 / span 2; }
    .stage-screen.has-six-stage-self .guest-audience-tile:nth-child(3) { grid-column: 2; grid-row: 3 / span 2; }
    .stage-screen.has-six-stage-self .guest-audience-tile:nth-child(4) { grid-column: 2; grid-row: 5 / span 2; }

    .stage-screen.has-seven-stage .stage-image,
    .stage-screen.has-seven-stage .stage-video,
    .stage-screen.has-seven-stage-self .stage-image,
    .stage-screen.has-seven-stage-self .stage-video {
      inset: 0 auto 0 0;
      width: 50%;
      height: 100%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
    }
    .stage-screen.has-seven-stage .guest-audience-layer,
    .stage-screen.has-seven-stage-self .guest-audience-layer {
      top: 0;
      right: 0;
      bottom: 0;
      width: 50%;
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      grid-template-rows: repeat(3, minmax(0, 1fr));
      gap: 0;
      align-items: stretch;
      justify-items: stretch;
    }
    .stage-screen.has-seven-stage .guest-audience-tile,
    .stage-screen.has-seven-stage-self .guest-audience-tile {
      width: 100%;
      height: 100%;
      min-height: 0;
      border: 0;
      box-shadow: none;
      background: var(--stage-fill);
      position: relative;
      border-radius: 0;
    }
    .stage-screen.has-seven-stage .guest-audience-image,
    .stage-screen.has-seven-stage .guest-audience-video,
    .stage-screen.has-seven-stage-self .guest-audience-image,
    .stage-screen.has-seven-stage-self .guest-audience-video {
      height: 100%;
      min-height: 100%;
    }
    .stage-screen.has-seven-stage .guest-audience-meta,
    .stage-screen.has-seven-stage-self .guest-audience-meta {
      position: absolute;
      top: 12px;
      left: 14px;
      right: auto;
      bottom: auto;
      padding: 0;
      background: transparent;
      text-shadow: 0 1px 3px rgba(0,0,0,.48);
      z-index: 2;
    }

    .stage-screen.has-seven-stage-self .guest-self-tile {
      top: 0;
      left: 50%;
      right: auto;
      bottom: auto;
      width: 25%;
      height: 33.333333%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 2px solid rgba(8, 12, 20, .8);
      box-shadow: none;
      background: var(--stage-fill);
      border-radius: 0;
    }

    .stage-screen.has-seven-stage-self .guest-self-video {
      height: 100%;
      min-height: 100%;
    }

    .stage-screen.has-seven-stage-self .guest-self-meta {
      position: absolute;
      top: 12px;
      left: 14px;
      right: auto;
      bottom: auto;
      padding: 0;
      background: transparent;
      text-shadow: 0 1px 3px rgba(0,0,0,.48);
      z-index: 2;
    }

    .stage-screen.has-seven-stage .guest-audience-image,
    .stage-screen.has-seven-stage .guest-audience-video,
    .stage-screen.has-seven-stage-self .guest-audience-image,
    .stage-screen.has-seven-stage-self .guest-audience-video,
    .stage-screen.has-seven-stage-self .guest-self-video,
    .stage-screen.has-eight-stage .guest-audience-image,
    .stage-screen.has-eight-stage .guest-audience-video,
    .stage-screen.has-eight-stage-self .guest-audience-image,
    .stage-screen.has-eight-stage-self .guest-audience-video,
    .stage-screen.has-eight-stage-self .guest-self-video,
    .stage-screen.has-nine-stage .guest-audience-image,
    .stage-screen.has-nine-stage .guest-audience-video,
    .stage-screen.has-nine-stage-self .guest-audience-image,
    .stage-screen.has-nine-stage-self .guest-audience-video,
    .stage-screen.has-nine-stage-self .guest-self-video,
    .stage-screen.has-ten-stage .guest-audience-image,
    .stage-screen.has-ten-stage .guest-audience-video,
    .stage-screen.has-ten-stage-self .guest-audience-image,
    .stage-screen.has-ten-stage-self .guest-audience-video,
    .stage-screen.has-ten-stage-self .guest-self-video,
    .stage-screen.has-eleven-stage .guest-audience-image,
    .stage-screen.has-eleven-stage .guest-audience-video,
    .stage-screen.has-eleven-stage-self .guest-audience-image,
    .stage-screen.has-eleven-stage-self .guest-audience-video,
    .stage-screen.has-eleven-stage-self .guest-self-video,
    .stage-screen.has-twelve-stage .guest-audience-image,
    .stage-screen.has-twelve-stage .guest-audience-video,
    .stage-screen.has-twelve-stage-self .guest-audience-image,
    .stage-screen.has-twelve-stage-self .guest-audience-video,
    .stage-screen.has-twelve-stage-self .guest-self-video {
      object-fit: contain;
      object-position: center top;
      background: var(--stage-fill);
    }

    .stage-screen.has-seven-stage-self .guest-audience-tile:nth-child(1) {
      grid-column: 2;
      grid-row: 1;
      border-right: 0;
    }

    .stage-screen.has-seven-stage-self .guest-audience-tile:nth-child(2) {
      grid-column: 1;
      grid-row: 2;
    }

    .stage-screen.has-seven-stage-self .guest-audience-tile:nth-child(3) {
      grid-column: 2;
      grid-row: 2;
      border-right: 0;
    }

    .stage-screen.has-seven-stage-self .guest-audience-tile:nth-child(4) {
      grid-column: 1;
      grid-row: 3;
      border-bottom: 0;
    }

    .stage-screen.has-seven-stage-self .guest-audience-tile:nth-child(5) {
      grid-column: 2;
      grid-row: 3;
      border-right: 0;
      border-bottom: 0;
    }
    .stage-screen.has-seven-stage .guest-audience-tile:nth-child(-n+4),
    .stage-screen.has-seven-stage-self .guest-audience-tile:nth-child(-n+4) {
      border-bottom: 2px solid rgba(8, 12, 20, .8);
    }
    .stage-screen.has-seven-stage .guest-audience-tile:nth-child(odd),
    .stage-screen.has-seven-stage-self .guest-audience-tile:nth-child(odd) {
      border-right: 2px solid rgba(8, 12, 20, .8);
    }
    .stage-screen.has-eight-stage,
    .stage-screen.has-eight-stage-self,
    .stage-screen.has-nine-stage,
    .stage-screen.has-nine-stage-self,
    .stage-screen.has-ten-stage,
    .stage-screen.has-ten-stage-self,
    .stage-screen.has-eleven-stage,
    .stage-screen.has-eleven-stage-self,
    .stage-screen.has-twelve-stage,
    .stage-screen.has-twelve-stage-self {
      --stage-main-width: 40%;
      --stage-guest-width: 60%;
      --stage-grid-columns: 4;
      --stage-grid-rows: 2;
    }

    .stage-screen.has-eight-stage,
    .stage-screen.has-eight-stage-self {
      --stage-main-width: 50%;
      --stage-guest-width: 50%;
      --stage-grid-columns: 2;
      --stage-grid-rows: 12;
    }

    .stage-screen.has-nine-stage,
    .stage-screen.has-nine-stage-self {
      --stage-main-width: 50%;
      --stage-guest-width: 50%;
      --stage-grid-columns: 2;
      --stage-grid-rows: 4;
    }
    .stage-screen.has-ten-stage,
    .stage-screen.has-ten-stage-self {
      --stage-main-width: 50%;
      --stage-guest-width: 50%;
      --stage-grid-columns: 6;
      --stage-grid-rows: 12;
    }
    .stage-screen.has-eleven-stage,
    .stage-screen.has-eleven-stage-self,
    .stage-screen.has-twelve-stage,
    .stage-screen.has-twelve-stage-self,
    .stage-screen.has-thirteen-stage,
    .stage-screen.has-thirteen-stage-self {
      --stage-main-width: 36%;
      --stage-guest-width: 64%;
      --stage-grid-columns: 4;
      --stage-grid-rows: 3;
    }

    .stage-screen.has-eleven-stage,
    .stage-screen.has-eleven-stage-self {
      --stage-main-width: 50%;
      --stage-guest-width: 50%;
      --stage-grid-columns: 2;
      --stage-grid-rows: 5;
    }

    .stage-screen.has-twelve-stage,
    .stage-screen.has-twelve-stage-self {
      --stage-main-width: 50%;
      --stage-guest-width: 50%;
      --stage-grid-columns: 6;
      --stage-grid-rows: 15;
    }

    .stage-screen.has-thirteen-stage,
    .stage-screen.has-thirteen-stage-self {
      --stage-main-width: 50%;
      --stage-guest-width: 50%;
      --stage-grid-columns: 2;
      --stage-grid-rows: 6;
    }

    .stage-screen.has-fourteen-stage,
    .stage-screen.has-fourteen-stage-self,
    .stage-screen.has-fifteen-stage,
    .stage-screen.has-fifteen-stage-self,
    .stage-screen.has-sixteen-stage,
    .stage-screen.has-sixteen-stage-self,
    .stage-screen.has-seventeen-stage,
    .stage-screen.has-seventeen-stage-self,
    .stage-screen.has-eighteen-stage,
    .stage-screen.has-eighteen-stage-self,
    .stage-screen.has-nineteen-stage,
    .stage-screen.has-nineteen-stage-self,
    .stage-screen.has-twenty-stage,
    .stage-screen.has-twenty-stage-self,
    .stage-screen.has-twentyone-stage,
    .stage-screen.has-twentyone-stage-self,
    .stage-screen.has-twentytwo-stage,
    .stage-screen.has-twentytwo-stage-self,
    .stage-screen.has-twentythree-stage,
    .stage-screen.has-twentythree-stage-self,
    .stage-screen.has-twentyfour-stage,
    .stage-screen.has-twentyfour-stage-self,
    .stage-screen.has-twentyfive-stage,
    .stage-screen.has-twentyfive-stage-self {
      --stage-main-width: 50%;
      --stage-guest-width: 50%;
      --stage-grid-columns: 4;
      --stage-grid-rows: 6;
    }
    .stage-screen.has-eight-stage .stage-image,
    .stage-screen.has-eight-stage .stage-video,
    .stage-screen.has-eight-stage-self .stage-image,
    .stage-screen.has-eight-stage-self .stage-video,
    .stage-screen.has-nine-stage .stage-image,
    .stage-screen.has-nine-stage .stage-video,
    .stage-screen.has-nine-stage-self .stage-image,
    .stage-screen.has-nine-stage-self .stage-video,
    .stage-screen.has-ten-stage .stage-image,
    .stage-screen.has-ten-stage .stage-video,
    .stage-screen.has-ten-stage-self .stage-image,
    .stage-screen.has-ten-stage-self .stage-video,
    .stage-screen.has-eleven-stage .stage-image,
    .stage-screen.has-eleven-stage .stage-video,
    .stage-screen.has-eleven-stage-self .stage-image,
    .stage-screen.has-eleven-stage-self .stage-video,
    .stage-screen.has-twelve-stage .stage-image,
    .stage-screen.has-twelve-stage .stage-video,
    .stage-screen.has-twelve-stage-self .stage-image,
    .stage-screen.has-twelve-stage-self .stage-video,
    .stage-screen.has-thirteen-stage .stage-image,
    .stage-screen.has-thirteen-stage .stage-video,
    .stage-screen.has-thirteen-stage-self .stage-image,
    .stage-screen.has-thirteen-stage-self .stage-video,
    .stage-screen.has-fourteen-stage .stage-image,
    .stage-screen.has-fourteen-stage .stage-video,
    .stage-screen.has-fourteen-stage-self .stage-image,
    .stage-screen.has-fourteen-stage-self .stage-video,
    .stage-screen.has-fifteen-stage .stage-image,
    .stage-screen.has-fifteen-stage .stage-video,
    .stage-screen.has-fifteen-stage-self .stage-image,
    .stage-screen.has-fifteen-stage-self .stage-video,
    .stage-screen.has-sixteen-stage .stage-image,
    .stage-screen.has-sixteen-stage .stage-video,
    .stage-screen.has-sixteen-stage-self .stage-image,
    .stage-screen.has-sixteen-stage-self .stage-video,
    .stage-screen.has-seventeen-stage .stage-image,
    .stage-screen.has-seventeen-stage .stage-video,
    .stage-screen.has-seventeen-stage-self .stage-image,
    .stage-screen.has-seventeen-stage-self .stage-video,
    .stage-screen.has-eighteen-stage .stage-image,
    .stage-screen.has-eighteen-stage .stage-video,
    .stage-screen.has-eighteen-stage-self .stage-image,
    .stage-screen.has-eighteen-stage-self .stage-video,
    .stage-screen.has-nineteen-stage .stage-image,
    .stage-screen.has-nineteen-stage .stage-video,
    .stage-screen.has-nineteen-stage-self .stage-image,
    .stage-screen.has-nineteen-stage-self .stage-video,
    .stage-screen.has-twenty-stage .stage-image,
    .stage-screen.has-twenty-stage .stage-video,
    .stage-screen.has-twenty-stage-self .stage-image,
    .stage-screen.has-twenty-stage-self .stage-video,
    .stage-screen.has-twentyone-stage .stage-image,
    .stage-screen.has-twentyone-stage .stage-video,
    .stage-screen.has-twentyone-stage-self .stage-image,
    .stage-screen.has-twentyone-stage-self .stage-video,
    .stage-screen.has-twentytwo-stage .stage-image,
    .stage-screen.has-twentytwo-stage .stage-video,
    .stage-screen.has-twentytwo-stage-self .stage-image,
    .stage-screen.has-twentytwo-stage-self .stage-video,
    .stage-screen.has-twentythree-stage .stage-image,
    .stage-screen.has-twentythree-stage .stage-video,
    .stage-screen.has-twentythree-stage-self .stage-image,
    .stage-screen.has-twentythree-stage-self .stage-video,
    .stage-screen.has-twentyfour-stage .stage-image,
    .stage-screen.has-twentyfour-stage .stage-video,
    .stage-screen.has-twentyfour-stage-self .stage-image,
    .stage-screen.has-twentyfour-stage-self .stage-video,
    .stage-screen.has-twentyfive-stage .stage-image,
    .stage-screen.has-twentyfive-stage .stage-video,
    .stage-screen.has-twentyfive-stage-self .stage-image,
    .stage-screen.has-twentyfive-stage-self .stage-video {
      inset: 0 auto 0 0;
      width: var(--stage-main-width);
      height: 100%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
    }
    .stage-screen.has-eight-stage .guest-audience-layer,
    .stage-screen.has-eight-stage-self .guest-audience-layer,
    .stage-screen.has-nine-stage .guest-audience-layer,
    .stage-screen.has-nine-stage-self .guest-audience-layer,
    .stage-screen.has-ten-stage .guest-audience-layer,
    .stage-screen.has-ten-stage-self .guest-audience-layer,
    .stage-screen.has-eleven-stage .guest-audience-layer,
    .stage-screen.has-eleven-stage-self .guest-audience-layer,
    .stage-screen.has-twelve-stage .guest-audience-layer,
    .stage-screen.has-twelve-stage-self .guest-audience-layer,
    .stage-screen.has-thirteen-stage .guest-audience-layer,
    .stage-screen.has-thirteen-stage-self .guest-audience-layer,
    .stage-screen.has-fourteen-stage .guest-audience-layer,
    .stage-screen.has-fourteen-stage-self .guest-audience-layer,
    .stage-screen.has-fifteen-stage .guest-audience-layer,
    .stage-screen.has-fifteen-stage-self .guest-audience-layer,
    .stage-screen.has-sixteen-stage .guest-audience-layer,
    .stage-screen.has-sixteen-stage-self .guest-audience-layer,
    .stage-screen.has-seventeen-stage .guest-audience-layer,
    .stage-screen.has-seventeen-stage-self .guest-audience-layer,
    .stage-screen.has-eighteen-stage .guest-audience-layer,
    .stage-screen.has-eighteen-stage-self .guest-audience-layer,
    .stage-screen.has-nineteen-stage .guest-audience-layer,
    .stage-screen.has-nineteen-stage-self .guest-audience-layer,
    .stage-screen.has-twenty-stage .guest-audience-layer,
    .stage-screen.has-twenty-stage-self .guest-audience-layer,
    .stage-screen.has-twentyone-stage .guest-audience-layer,
    .stage-screen.has-twentyone-stage-self .guest-audience-layer,
    .stage-screen.has-twentytwo-stage .guest-audience-layer,
    .stage-screen.has-twentytwo-stage-self .guest-audience-layer,
    .stage-screen.has-twentythree-stage .guest-audience-layer,
    .stage-screen.has-twentythree-stage-self .guest-audience-layer,
    .stage-screen.has-twentyfour-stage .guest-audience-layer,
    .stage-screen.has-twentyfour-stage-self .guest-audience-layer,
    .stage-screen.has-twentyfive-stage .guest-audience-layer,
    .stage-screen.has-twentyfive-stage-self .guest-audience-layer {
      top: 0;
      right: 0;
      bottom: 0;
      width: var(--stage-guest-width);
      display: grid;
      grid-template-columns: repeat(var(--stage-grid-columns), minmax(0, 1fr));
      grid-template-rows: repeat(var(--stage-grid-rows), minmax(0, 1fr));
      gap: 2px;
      padding: 0;
      background: rgba(8, 12, 20, .8);
      align-items: stretch;
      justify-items: stretch;
    }
    .stage-screen.has-eight-stage .guest-audience-tile,
    .stage-screen.has-eight-stage-self .guest-audience-tile,
    .stage-screen.has-nine-stage .guest-audience-tile,
    .stage-screen.has-nine-stage-self .guest-audience-tile,
    .stage-screen.has-ten-stage .guest-audience-tile,
    .stage-screen.has-ten-stage-self .guest-audience-tile,
    .stage-screen.has-eleven-stage .guest-audience-tile,
    .stage-screen.has-eleven-stage-self .guest-audience-tile,
    .stage-screen.has-twelve-stage .guest-audience-tile,
    .stage-screen.has-twelve-stage-self .guest-audience-tile,
    .stage-screen.has-thirteen-stage .guest-audience-tile,
    .stage-screen.has-thirteen-stage-self .guest-audience-tile,
    .stage-screen.has-fourteen-stage .guest-audience-tile,
    .stage-screen.has-fourteen-stage-self .guest-audience-tile,
    .stage-screen.has-fifteen-stage .guest-audience-tile,
    .stage-screen.has-fifteen-stage-self .guest-audience-tile,
    .stage-screen.has-sixteen-stage .guest-audience-tile,
    .stage-screen.has-sixteen-stage-self .guest-audience-tile,
    .stage-screen.has-seventeen-stage .guest-audience-tile,
    .stage-screen.has-seventeen-stage-self .guest-audience-tile,
    .stage-screen.has-eighteen-stage .guest-audience-tile,
    .stage-screen.has-eighteen-stage-self .guest-audience-tile,
    .stage-screen.has-nineteen-stage .guest-audience-tile,
    .stage-screen.has-nineteen-stage-self .guest-audience-tile,
    .stage-screen.has-twenty-stage .guest-audience-tile,
    .stage-screen.has-twenty-stage-self .guest-audience-tile,
    .stage-screen.has-twentyone-stage .guest-audience-tile,
    .stage-screen.has-twentyone-stage-self .guest-audience-tile,
    .stage-screen.has-twentytwo-stage .guest-audience-tile,
    .stage-screen.has-twentytwo-stage-self .guest-audience-tile,
    .stage-screen.has-twentythree-stage .guest-audience-tile,
    .stage-screen.has-twentythree-stage-self .guest-audience-tile,
    .stage-screen.has-twentyfour-stage .guest-audience-tile,
    .stage-screen.has-twentyfour-stage-self .guest-audience-tile,
    .stage-screen.has-twentyfive-stage .guest-audience-tile,
    .stage-screen.has-twentyfive-stage-self .guest-audience-tile {
      width: 100%;
      height: 100%;
      min-height: 0;
      border: 0;
      box-shadow: none;
      background: var(--stage-fill);
      border-radius: 0;
      position: relative;
    }
    .stage-screen.has-eight-stage .guest-audience-image,
    .stage-screen.has-eight-stage .guest-audience-video,
    .stage-screen.has-eight-stage-self .guest-audience-image,
    .stage-screen.has-eight-stage-self .guest-audience-video,
    .stage-screen.has-nine-stage .guest-audience-image,
    .stage-screen.has-nine-stage .guest-audience-video,
    .stage-screen.has-nine-stage-self .guest-audience-image,
    .stage-screen.has-nine-stage-self .guest-audience-video,
    .stage-screen.has-ten-stage .guest-audience-image,
    .stage-screen.has-ten-stage .guest-audience-video,
    .stage-screen.has-ten-stage-self .guest-audience-image,
    .stage-screen.has-ten-stage-self .guest-audience-video,
    .stage-screen.has-eleven-stage .guest-audience-image,
    .stage-screen.has-eleven-stage .guest-audience-video,
    .stage-screen.has-eleven-stage-self .guest-audience-image,
    .stage-screen.has-eleven-stage-self .guest-audience-video,
    .stage-screen.has-twelve-stage .guest-audience-image,
    .stage-screen.has-twelve-stage .guest-audience-video,
    .stage-screen.has-twelve-stage-self .guest-audience-image,
    .stage-screen.has-twelve-stage-self .guest-audience-video,
    .stage-screen.has-thirteen-stage .guest-audience-image,
    .stage-screen.has-thirteen-stage .guest-audience-video,
    .stage-screen.has-thirteen-stage-self .guest-audience-image,
    .stage-screen.has-thirteen-stage-self .guest-audience-video,
    .stage-screen.has-fourteen-stage .guest-audience-image,
    .stage-screen.has-fourteen-stage .guest-audience-video,
    .stage-screen.has-fourteen-stage-self .guest-audience-image,
    .stage-screen.has-fourteen-stage-self .guest-audience-video,
    .stage-screen.has-fifteen-stage .guest-audience-image,
    .stage-screen.has-fifteen-stage .guest-audience-video,
    .stage-screen.has-fifteen-stage-self .guest-audience-image,
    .stage-screen.has-fifteen-stage-self .guest-audience-video,
    .stage-screen.has-sixteen-stage .guest-audience-image,
    .stage-screen.has-sixteen-stage .guest-audience-video,
    .stage-screen.has-sixteen-stage-self .guest-audience-image,
    .stage-screen.has-sixteen-stage-self .guest-audience-video,
    .stage-screen.has-seventeen-stage .guest-audience-image,
    .stage-screen.has-seventeen-stage .guest-audience-video,
    .stage-screen.has-seventeen-stage-self .guest-audience-image,
    .stage-screen.has-seventeen-stage-self .guest-audience-video,
    .stage-screen.has-eighteen-stage .guest-audience-image,
    .stage-screen.has-eighteen-stage .guest-audience-video,
    .stage-screen.has-eighteen-stage-self .guest-audience-image,
    .stage-screen.has-eighteen-stage-self .guest-audience-video,
    .stage-screen.has-nineteen-stage .guest-audience-image,
    .stage-screen.has-nineteen-stage .guest-audience-video,
    .stage-screen.has-nineteen-stage-self .guest-audience-image,
    .stage-screen.has-nineteen-stage-self .guest-audience-video,
    .stage-screen.has-twenty-stage .guest-audience-image,
    .stage-screen.has-twenty-stage .guest-audience-video,
    .stage-screen.has-twenty-stage-self .guest-audience-image,
    .stage-screen.has-twenty-stage-self .guest-audience-video,
    .stage-screen.has-twentyone-stage .guest-audience-image,
    .stage-screen.has-twentyone-stage .guest-audience-video,
    .stage-screen.has-twentyone-stage-self .guest-audience-image,
    .stage-screen.has-twentyone-stage-self .guest-audience-video,
    .stage-screen.has-twentytwo-stage .guest-audience-image,
    .stage-screen.has-twentytwo-stage .guest-audience-video,
    .stage-screen.has-twentytwo-stage-self .guest-audience-image,
    .stage-screen.has-twentytwo-stage-self .guest-audience-video,
    .stage-screen.has-twentythree-stage .guest-audience-image,
    .stage-screen.has-twentythree-stage .guest-audience-video,
    .stage-screen.has-twentythree-stage-self .guest-audience-image,
    .stage-screen.has-twentythree-stage-self .guest-audience-video,
    .stage-screen.has-twentyfour-stage .guest-audience-image,
    .stage-screen.has-twentyfour-stage .guest-audience-video,
    .stage-screen.has-twentyfour-stage-self .guest-audience-image,
    .stage-screen.has-twentyfour-stage-self .guest-audience-video,
    .stage-screen.has-twentyfive-stage .guest-audience-image,
    .stage-screen.has-twentyfive-stage .guest-audience-video,
    .stage-screen.has-twentyfive-stage-self .guest-audience-image,
    .stage-screen.has-twentyfive-stage-self .guest-audience-video {
      height: 100%;
      min-height: 100%;
    }
    .stage-screen.has-eight-stage .guest-audience-meta,
    .stage-screen.has-eight-stage-self .guest-audience-meta,
    .stage-screen.has-nine-stage .guest-audience-meta,
    .stage-screen.has-nine-stage-self .guest-audience-meta,
    .stage-screen.has-ten-stage .guest-audience-meta,
    .stage-screen.has-ten-stage-self .guest-audience-meta,
    .stage-screen.has-eleven-stage .guest-audience-meta,
    .stage-screen.has-eleven-stage-self .guest-audience-meta,
    .stage-screen.has-twelve-stage .guest-audience-meta,
    .stage-screen.has-twelve-stage-self .guest-audience-meta,
    .stage-screen.has-thirteen-stage .guest-audience-meta,
    .stage-screen.has-thirteen-stage-self .guest-audience-meta,
    .stage-screen.has-fourteen-stage .guest-audience-meta,
    .stage-screen.has-fourteen-stage-self .guest-audience-meta,
    .stage-screen.has-fifteen-stage .guest-audience-meta,
    .stage-screen.has-fifteen-stage-self .guest-audience-meta,
    .stage-screen.has-sixteen-stage .guest-audience-meta,
    .stage-screen.has-sixteen-stage-self .guest-audience-meta,
    .stage-screen.has-seventeen-stage .guest-audience-meta,
    .stage-screen.has-seventeen-stage-self .guest-audience-meta,
    .stage-screen.has-eighteen-stage .guest-audience-meta,
    .stage-screen.has-eighteen-stage-self .guest-audience-meta,
    .stage-screen.has-nineteen-stage .guest-audience-meta,
    .stage-screen.has-nineteen-stage-self .guest-audience-meta,
    .stage-screen.has-twenty-stage .guest-audience-meta,
    .stage-screen.has-twenty-stage-self .guest-audience-meta,
    .stage-screen.has-twentyone-stage .guest-audience-meta,
    .stage-screen.has-twentyone-stage-self .guest-audience-meta,
    .stage-screen.has-twentytwo-stage .guest-audience-meta,
    .stage-screen.has-twentytwo-stage-self .guest-audience-meta,
    .stage-screen.has-twentythree-stage .guest-audience-meta,
    .stage-screen.has-twentythree-stage-self .guest-audience-meta,
    .stage-screen.has-twentyfour-stage .guest-audience-meta,
    .stage-screen.has-twentyfour-stage-self .guest-audience-meta,
    .stage-screen.has-twentyfive-stage .guest-audience-meta,
    .stage-screen.has-twentyfive-stage-self .guest-audience-meta {
      position: absolute;
      top: 10px;
      left: 12px;
      right: auto;
      bottom: auto;
      padding: 0;
      background: transparent;
      text-shadow: 0 1px 3px rgba(0,0,0,.48);
      z-index: 2;
    }

    .stage-screen.has-eight-stage .guest-audience-tile,
    .stage-screen.has-eight-stage-self .guest-audience-tile {
      background: var(--stage-fill);
    }

    .stage-screen.has-nine-stage .guest-audience-tile,
    .stage-screen.has-nine-stage-self .guest-audience-tile {
      background: var(--stage-fill);
    }

    .stage-screen.has-ten-stage .guest-audience-tile,
    .stage-screen.has-ten-stage-self .guest-audience-tile {
      background: var(--stage-fill);
    }

    .stage-screen.has-eleven-stage .guest-audience-tile,
    .stage-screen.has-eleven-stage-self .guest-audience-tile {
      background: var(--stage-fill);
    }

    .stage-screen.has-twelve-stage .guest-audience-tile,
    .stage-screen.has-twelve-stage-self .guest-audience-tile {
      background: var(--stage-fill);
    }

    .stage-screen.has-eight-stage .guest-audience-tile:nth-child(1),
    .stage-screen.has-eight-stage-self .guest-audience-tile:nth-child(1) {
      grid-column: 1;
      grid-row: 1 / span 4;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 2px solid rgba(8, 12, 20, .8);
    }

    .stage-screen.has-eight-stage .guest-audience-tile:nth-child(2),
    .stage-screen.has-eight-stage-self .guest-audience-tile:nth-child(2) {
      grid-column: 1;
      grid-row: 5 / span 4;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 2px solid rgba(8, 12, 20, .8);
    }

    .stage-screen.has-eight-stage .guest-audience-tile:nth-child(3),
    .stage-screen.has-eight-stage-self .guest-audience-tile:nth-child(3) {
      grid-column: 1;
      grid-row: 9 / span 4;
      border-right: 2px solid rgba(8, 12, 20, .8);
    }

    .stage-screen.has-eight-stage .guest-audience-tile:nth-child(4),
    .stage-screen.has-eight-stage-self .guest-audience-tile:nth-child(4) {
      grid-column: 2;
      grid-row: 1 / span 3;
      border-bottom: 2px solid rgba(8, 12, 20, .8);
    }

    .stage-screen.has-eight-stage .guest-audience-tile:nth-child(5),
    .stage-screen.has-eight-stage-self .guest-audience-tile:nth-child(5) {
      grid-column: 2;
      grid-row: 4 / span 3;
      border-bottom: 2px solid rgba(8, 12, 20, .8);
    }

    .stage-screen.has-eight-stage .guest-audience-tile:nth-child(6),
    .stage-screen.has-eight-stage-self .guest-audience-tile:nth-child(6) {
      grid-column: 2;
      grid-row: 7 / span 3;
      border-bottom: 2px solid rgba(8, 12, 20, .8);
    }

    .stage-screen.has-eight-stage .guest-audience-tile:nth-child(7),
    .stage-screen.has-eight-stage-self .guest-audience-tile:nth-child(7) {
      grid-column: 2;
      grid-row: 10 / span 3;
    }

    .stage-screen.has-eight-stage-self .guest-self-tile {
      top: 0;
      left: 50%;
      right: auto;
      bottom: auto;
      width: 25%;
      height: 33.333333%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 2px solid rgba(8, 12, 20, .8);
      box-shadow: none;
      background: var(--stage-fill);
      border-radius: 0;
    }

    .stage-screen.has-eight-stage-self .guest-self-video {
      height: 100%;
      min-height: 100%;
    }

    .stage-screen.has-eight-stage-self .guest-self-meta {
      position: absolute;
      top: 10px;
      left: 12px;
      right: auto;
      bottom: auto;
      padding: 0;
      background: transparent;
      text-shadow: 0 1px 3px rgba(0,0,0,.48);
      z-index: 2;
    }

    .stage-screen.has-eight-stage-self .guest-audience-tile:nth-child(1) {
      grid-column: 1;
      grid-row: 5 / span 4;
    }

    .stage-screen.has-eight-stage-self .guest-audience-tile:nth-child(2) {
      grid-column: 1;
      grid-row: 9 / span 4;
      border-bottom: 0;
    }

    .stage-screen.has-eight-stage-self .guest-audience-tile:nth-child(3) {
      grid-column: 2;
      grid-row: 1 / span 3;
      border-right: 0;
    }

    .stage-screen.has-eight-stage-self .guest-audience-tile:nth-child(4) {
      grid-column: 2;
      grid-row: 4 / span 3;
      border-right: 0;
    }

    .stage-screen.has-eight-stage-self .guest-audience-tile:nth-child(5) {
      grid-column: 2;
      grid-row: 7 / span 3;
      border-right: 0;
    }

    .stage-screen.has-eight-stage-self .guest-audience-tile:nth-child(6) {
      grid-column: 2;
      grid-row: 10 / span 3;
      border-right: 0;
      border-bottom: 0;
    }

    .stage-screen.has-nine-stage .guest-audience-tile:nth-child(-n+6),
    .stage-screen.has-nine-stage-self .guest-audience-tile:nth-child(-n+6) {
      border-bottom: 2px solid rgba(8, 12, 20, .8);
    }

    .stage-screen.has-nine-stage .guest-audience-tile:nth-child(odd),
    .stage-screen.has-nine-stage-self .guest-audience-tile:nth-child(odd) {
      border-right: 2px solid rgba(8, 12, 20, .8);
    }

    .stage-screen.has-nine-stage-self .guest-self-tile {
      top: 0;
      left: 50%;
      right: auto;
      bottom: auto;
      width: 25%;
      height: 25%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 2px solid rgba(8, 12, 20, .8);
      box-shadow: none;
      background: var(--stage-fill);
      border-radius: 0;
    }

    .stage-screen.has-nine-stage-self .guest-self-video {
      height: 100%;
      min-height: 100%;
    }

    .stage-screen.has-nine-stage-self .guest-self-meta {
      position: absolute;
      top: 10px;
      left: 12px;
      right: auto;
      bottom: auto;
      padding: 0;
      background: transparent;
      text-shadow: 0 1px 3px rgba(0,0,0,.48);
      z-index: 2;
    }

    .stage-screen.has-nine-stage-self .guest-audience-tile:nth-child(1) {
      grid-column: 1;
      grid-row: 2;
    }

    .stage-screen.has-nine-stage-self .guest-audience-tile:nth-child(2) {
      grid-column: 1;
      grid-row: 3;
    }

    .stage-screen.has-nine-stage-self .guest-audience-tile:nth-child(3) {
      grid-column: 1;
      grid-row: 4;
      border-bottom: 0;
    }

    .stage-screen.has-nine-stage-self .guest-audience-tile:nth-child(4) {
      grid-column: 2;
      grid-row: 1;
      border-right: 0;
    }

    .stage-screen.has-nine-stage-self .guest-audience-tile:nth-child(5) {
      grid-column: 2;
      grid-row: 2;
      border-right: 0;
    }

    .stage-screen.has-nine-stage-self .guest-audience-tile:nth-child(6) {
      grid-column: 2;
      grid-row: 3;
      border-right: 0;
    }

    .stage-screen.has-nine-stage-self .guest-audience-tile:nth-child(7) {
      grid-column: 2;
      grid-row: 4;
      border-right: 0;
      border-bottom: 0;
    }

    .stage-screen.has-ten-stage .guest-audience-tile:nth-child(1),
    .stage-screen.has-ten-stage-self .guest-audience-tile:nth-child(1) {
      grid-column: 1 / span 3;
      grid-row: 1 / span 3;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 2px solid rgba(8, 12, 20, .8);
    }

    .stage-screen.has-ten-stage .guest-audience-tile:nth-child(2),
    .stage-screen.has-ten-stage-self .guest-audience-tile:nth-child(2) {
      grid-column: 4 / span 3;
      grid-row: 1 / span 3;
      border-bottom: 2px solid rgba(8, 12, 20, .8);
    }

    .stage-screen.has-ten-stage .guest-audience-tile:nth-child(3),
    .stage-screen.has-ten-stage-self .guest-audience-tile:nth-child(3) {
      grid-column: 1 / span 3;
      grid-row: 4 / span 4;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 2px solid rgba(8, 12, 20, .8);
    }

    .stage-screen.has-ten-stage .guest-audience-tile:nth-child(4),
    .stage-screen.has-ten-stage-self .guest-audience-tile:nth-child(4) {
      grid-column: 4 / span 3;
      grid-row: 4 / span 4;
      border-bottom: 2px solid rgba(8, 12, 20, .8);
    }

    .stage-screen.has-ten-stage .guest-audience-tile:nth-child(5),
    .stage-screen.has-ten-stage-self .guest-audience-tile:nth-child(5) {
      grid-column: 1 / span 2;
      grid-row: 8 / span 3;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 2px solid rgba(8, 12, 20, .8);
    }

    .stage-screen.has-ten-stage .guest-audience-tile:nth-child(6),
    .stage-screen.has-ten-stage-self .guest-audience-tile:nth-child(6) {
      grid-column: 3 / span 2;
      grid-row: 8 / span 3;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 2px solid rgba(8, 12, 20, .8);
    }

    .stage-screen.has-ten-stage .guest-audience-tile:nth-child(7),
    .stage-screen.has-ten-stage-self .guest-audience-tile:nth-child(7) {
      grid-column: 5 / span 2;
      grid-row: 8 / span 3;
      border-bottom: 2px solid rgba(8, 12, 20, .8);
    }

    .stage-screen.has-ten-stage .guest-audience-tile:nth-child(8),
    .stage-screen.has-ten-stage-self .guest-audience-tile:nth-child(8) {
      grid-column: 1 / span 3;
      grid-row: 11 / span 2;
      border-right: 2px solid rgba(8, 12, 20, .8);
    }

    .stage-screen.has-ten-stage .guest-audience-tile:nth-child(9),
    .stage-screen.has-ten-stage-self .guest-audience-tile:nth-child(9) {
      grid-column: 4 / span 3;
      grid-row: 11 / span 2;
    }

    .stage-screen.has-ten-stage-self .guest-self-tile {
      top: 0;
      left: 50%;
      right: auto;
      bottom: auto;
      width: 25%;
      height: 25%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 2px solid rgba(8, 12, 20, .8);
      box-shadow: none;
      background: var(--stage-fill);
      border-radius: 0;
    }

    .stage-screen.has-ten-stage-self .guest-self-video {
      height: 100%;
      min-height: 100%;
    }

    .stage-screen.has-ten-stage-self .guest-self-meta {
      position: absolute;
      top: 10px;
      left: 12px;
      right: auto;
      bottom: auto;
      padding: 0;
      background: transparent;
      text-shadow: 0 1px 3px rgba(0,0,0,.48);
      z-index: 2;
    }

    .stage-screen.has-ten-stage-self .guest-audience-tile:nth-child(1) {
      grid-column: 4 / span 3;
      grid-row: 1 / span 3;
    }

    .stage-screen.has-ten-stage-self .guest-audience-tile:nth-child(2) {
      grid-column: 1 / span 3;
      grid-row: 4 / span 4;
    }

    .stage-screen.has-ten-stage-self .guest-audience-tile:nth-child(3) {
      grid-column: 4 / span 3;
      grid-row: 4 / span 4;
      border-right: 0;
    }

    .stage-screen.has-ten-stage-self .guest-audience-tile:nth-child(4) {
      grid-column: 1 / span 2;
      grid-row: 8 / span 3;
    }

    .stage-screen.has-ten-stage-self .guest-audience-tile:nth-child(5) {
      grid-column: 3 / span 2;
      grid-row: 8 / span 3;
    }

    .stage-screen.has-ten-stage-self .guest-audience-tile:nth-child(6) {
      grid-column: 5 / span 2;
      grid-row: 8 / span 3;
      border-right: 0;
    }

    .stage-screen.has-ten-stage-self .guest-audience-tile:nth-child(7) {
      grid-column: 1 / span 3;
      grid-row: 11 / span 2;
      border-bottom: 0;
    }

    .stage-screen.has-ten-stage-self .guest-audience-tile:nth-child(8) {
      grid-column: 4 / span 3;
      grid-row: 11 / span 2;
      border-right: 0;
      border-bottom: 0;
    }

    .stage-screen.has-eleven-stage .guest-audience-tile:nth-child(-n+8),
    .stage-screen.has-eleven-stage-self .guest-audience-tile:nth-child(-n+8) {
      border-bottom: 2px solid rgba(8, 12, 20, .8);
    }

    .stage-screen.has-eleven-stage .guest-audience-tile:nth-child(odd),
    .stage-screen.has-eleven-stage-self .guest-audience-tile:nth-child(odd) {
      border-right: 2px solid rgba(8, 12, 20, .8);
    }

    .stage-screen.has-eleven-stage-self .guest-self-tile {
      top: 0;
      left: 50%;
      right: auto;
      bottom: auto;
      width: 25%;
      height: 20%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 2px solid rgba(8, 12, 20, .8);
      box-shadow: none;
      background: var(--stage-fill);
      border-radius: 0;
    }

    .stage-screen.has-eleven-stage-self .guest-self-video {
      height: 100%;
      min-height: 100%;
    }

    .stage-screen.has-eleven-stage-self .guest-self-meta {
      position: absolute;
      top: 10px;
      left: 12px;
      right: auto;
      bottom: auto;
      padding: 0;
      background: transparent;
      text-shadow: 0 1px 3px rgba(0,0,0,.48);
      z-index: 2;
    }

    .stage-screen.has-eleven-stage-self .guest-audience-tile:nth-child(1) {
      grid-column: 1;
      grid-row: 2;
    }

    .stage-screen.has-eleven-stage-self .guest-audience-tile:nth-child(2) {
      grid-column: 1;
      grid-row: 3;
    }

    .stage-screen.has-eleven-stage-self .guest-audience-tile:nth-child(3) {
      grid-column: 1;
      grid-row: 4;
    }

    .stage-screen.has-eleven-stage-self .guest-audience-tile:nth-child(4) {
      grid-column: 1;
      grid-row: 5;
      border-bottom: 0;
    }

    .stage-screen.has-eleven-stage-self .guest-audience-tile:nth-child(5) {
      grid-column: 2;
      grid-row: 1;
      border-right: 0;
    }

    .stage-screen.has-eleven-stage-self .guest-audience-tile:nth-child(6) {
      grid-column: 2;
      grid-row: 2;
      border-right: 0;
    }

    .stage-screen.has-eleven-stage-self .guest-audience-tile:nth-child(7) {
      grid-column: 2;
      grid-row: 3;
      border-right: 0;
    }

    .stage-screen.has-eleven-stage-self .guest-audience-tile:nth-child(8) {
      grid-column: 2;
      grid-row: 4;
      border-right: 0;
    }

    .stage-screen.has-eleven-stage-self .guest-audience-tile:nth-child(9) {
      grid-column: 2;
      grid-row: 5;
      border-right: 0;
      border-bottom: 0;
    }

    .stage-screen.has-twelve-stage .guest-audience-tile:nth-child(1),
    .stage-screen.has-twelve-stage-self .guest-audience-tile:nth-child(1) {
      grid-column: 1 / span 3;
      grid-row: 1 / span 3;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 2px solid rgba(8, 12, 20, .8);
    }

    .stage-screen.has-twelve-stage .guest-audience-tile:nth-child(2),
    .stage-screen.has-twelve-stage-self .guest-audience-tile:nth-child(2) {
      grid-column: 4 / span 3;
      grid-row: 1 / span 3;
      border-bottom: 2px solid rgba(8, 12, 20, .8);
    }

    .stage-screen.has-twelve-stage .guest-audience-tile:nth-child(3),
    .stage-screen.has-twelve-stage-self .guest-audience-tile:nth-child(3) {
      grid-column: 1 / span 3;
      grid-row: 4 / span 3;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 2px solid rgba(8, 12, 20, .8);
    }

    .stage-screen.has-twelve-stage .guest-audience-tile:nth-child(4),
    .stage-screen.has-twelve-stage-self .guest-audience-tile:nth-child(4) {
      grid-column: 4 / span 3;
      grid-row: 4 / span 3;
      border-bottom: 2px solid rgba(8, 12, 20, .8);
    }

    .stage-screen.has-twelve-stage .guest-audience-tile:nth-child(5),
    .stage-screen.has-twelve-stage-self .guest-audience-tile:nth-child(5) {
      grid-column: 1 / span 2;
      grid-row: 7 / span 3;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 2px solid rgba(8, 12, 20, .8);
    }

    .stage-screen.has-twelve-stage .guest-audience-tile:nth-child(6),
    .stage-screen.has-twelve-stage-self .guest-audience-tile:nth-child(6) {
      grid-column: 3 / span 2;
      grid-row: 7 / span 3;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 2px solid rgba(8, 12, 20, .8);
    }

    .stage-screen.has-twelve-stage .guest-audience-tile:nth-child(7),
    .stage-screen.has-twelve-stage-self .guest-audience-tile:nth-child(7) {
      grid-column: 5 / span 2;
      grid-row: 7 / span 3;
      border-bottom: 2px solid rgba(8, 12, 20, .8);
    }

    .stage-screen.has-twelve-stage .guest-audience-tile:nth-child(8),
    .stage-screen.has-twelve-stage-self .guest-audience-tile:nth-child(8) {
      grid-column: 1 / span 3;
      grid-row: 10 / span 3;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 2px solid rgba(8, 12, 20, .8);
    }

    .stage-screen.has-twelve-stage .guest-audience-tile:nth-child(9),
    .stage-screen.has-twelve-stage-self .guest-audience-tile:nth-child(9) {
      grid-column: 4 / span 3;
      grid-row: 10 / span 3;
      border-bottom: 2px solid rgba(8, 12, 20, .8);
    }

    .stage-screen.has-twelve-stage .guest-audience-tile:nth-child(10),
    .stage-screen.has-twelve-stage-self .guest-audience-tile:nth-child(10) {
      grid-column: 1 / span 3;
      grid-row: 13 / span 3;
      border-right: 2px solid rgba(8, 12, 20, .8);
    }

    .stage-screen.has-twelve-stage .guest-audience-tile:nth-child(11),
    .stage-screen.has-twelve-stage-self .guest-audience-tile:nth-child(11) {
      grid-column: 4 / span 3;
      grid-row: 13 / span 3;
    }

    .stage-screen.has-twelve-stage-self .guest-self-tile {
      top: 0;
      left: 50%;
      right: auto;
      bottom: auto;
      width: 25%;
      height: 20%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 2px solid rgba(8, 12, 20, .8);
      box-shadow: none;
      background: var(--stage-fill);
      border-radius: 0;
    }

    .stage-screen.has-twelve-stage-self .guest-self-video {
      height: 100%;
      min-height: 100%;
    }

    .stage-screen.has-twelve-stage-self .guest-self-meta {
      position: absolute;
      top: 10px;
      left: 12px;
      right: auto;
      bottom: auto;
      padding: 0;
      background: transparent;
      text-shadow: 0 1px 3px rgba(0,0,0,.48);
      z-index: 2;
    }

    .stage-screen.has-twelve-stage-self .guest-audience-tile:nth-child(1) {
      grid-column: 4 / span 3;
      grid-row: 1 / span 3;
    }

    .stage-screen.has-twelve-stage-self .guest-audience-tile:nth-child(2) {
      grid-column: 1 / span 3;
      grid-row: 4 / span 3;
    }

    .stage-screen.has-twelve-stage-self .guest-audience-tile:nth-child(3) {
      grid-column: 4 / span 3;
      grid-row: 4 / span 3;
      border-right: 0;
    }

    .stage-screen.has-twelve-stage-self .guest-audience-tile:nth-child(4) {
      grid-column: 1 / span 2;
      grid-row: 7 / span 3;
    }

    .stage-screen.has-twelve-stage-self .guest-audience-tile:nth-child(5) {
      grid-column: 3 / span 2;
      grid-row: 7 / span 3;
    }

    .stage-screen.has-twelve-stage-self .guest-audience-tile:nth-child(6) {
      grid-column: 5 / span 2;
      grid-row: 7 / span 3;
      border-right: 0;
    }

    .stage-screen.has-twelve-stage-self .guest-audience-tile:nth-child(7) {
      grid-column: 1 / span 3;
      grid-row: 10 / span 3;
    }

    .stage-screen.has-twelve-stage-self .guest-audience-tile:nth-child(8) {
      grid-column: 4 / span 3;
      grid-row: 10 / span 3;
      border-right: 0;
    }

    .stage-screen.has-twelve-stage-self .guest-audience-tile:nth-child(9) {
      grid-column: 1 / span 3;
      grid-row: 13 / span 3;
      border-bottom: 0;
    }

    .stage-screen.has-twelve-stage-self .guest-audience-tile:nth-child(10) {
      grid-column: 4 / span 3;
      grid-row: 13 / span 3;
      border-right: 0;
      border-bottom: 0;
    }

    .stage-screen.has-thirteen-stage .guest-audience-tile:nth-child(-n+10),
    .stage-screen.has-thirteen-stage-self .guest-audience-tile:nth-child(-n+10) {
      border-bottom: 2px solid rgba(8, 12, 20, .8);
    }

    .stage-screen.has-thirteen-stage .guest-audience-tile:nth-child(odd),
    .stage-screen.has-thirteen-stage-self .guest-audience-tile:nth-child(odd) {
      border-right: 2px solid rgba(8, 12, 20, .8);
    }

    .stage-screen.has-thirteen-stage-self .guest-self-tile {
      top: 0;
      left: 50%;
      right: auto;
      bottom: auto;
      width: 25%;
      height: 16.666667%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 2px solid rgba(8, 12, 20, .8);
      box-shadow: none;
      background: var(--stage-fill);
      border-radius: 0;
    }

    .stage-screen.has-thirteen-stage-self .guest-self-video {
      height: 100%;
      min-height: 100%;
    }

    .stage-screen.has-thirteen-stage-self .guest-self-meta {
      position: absolute;
      top: 10px;
      left: 12px;
      right: auto;
      bottom: auto;
      padding: 0;
      background: transparent;
      text-shadow: 0 1px 3px rgba(0,0,0,.48);
      z-index: 2;
    }

    .stage-screen.has-thirteen-stage-self .guest-audience-tile:nth-child(1) {
      grid-column: 2;
      grid-row: 1;
      border-right: 0;
    }

    .stage-screen.has-thirteen-stage-self .guest-audience-tile:nth-child(2) {
      grid-column: 1;
      grid-row: 2;
    }

    .stage-screen.has-thirteen-stage-self .guest-audience-tile:nth-child(3) {
      grid-column: 2;
      grid-row: 2;
      border-right: 0;
    }

    .stage-screen.has-thirteen-stage-self .guest-audience-tile:nth-child(4) {
      grid-column: 1;
      grid-row: 3;
    }

    .stage-screen.has-thirteen-stage-self .guest-audience-tile:nth-child(5) {
      grid-column: 2;
      grid-row: 3;
      border-right: 0;
    }

    .stage-screen.has-thirteen-stage-self .guest-audience-tile:nth-child(6) {
      grid-column: 1;
      grid-row: 4;
    }

    .stage-screen.has-thirteen-stage-self .guest-audience-tile:nth-child(7) {
      grid-column: 2;
      grid-row: 4;
      border-right: 0;
    }

    .stage-screen.has-thirteen-stage-self .guest-audience-tile:nth-child(8) {
      grid-column: 1;
      grid-row: 5;
    }

    .stage-screen.has-thirteen-stage-self .guest-audience-tile:nth-child(9) {
      grid-column: 2;
      grid-row: 5;
      border-right: 0;
    }

    .stage-screen.has-thirteen-stage-self .guest-audience-tile:nth-child(10) {
      grid-column: 1;
      grid-row: 6;
      border-bottom: 0;
    }

    .stage-screen.has-thirteen-stage-self .guest-audience-tile:nth-child(11) {
      grid-column: 2;
      grid-row: 6;
      border-right: 0;
      border-bottom: 0;
    }

    .stage-screen.has-fourteen-stage-self .guest-self-tile,
    .stage-screen.has-fifteen-stage-self .guest-self-tile,
    .stage-screen.has-sixteen-stage-self .guest-self-tile,
    .stage-screen.has-seventeen-stage-self .guest-self-tile,
    .stage-screen.has-eighteen-stage-self .guest-self-tile,
    .stage-screen.has-nineteen-stage-self .guest-self-tile,
    .stage-screen.has-twenty-stage-self .guest-self-tile,
    .stage-screen.has-twentyone-stage-self .guest-self-tile,
    .stage-screen.has-twentytwo-stage-self .guest-self-tile,
    .stage-screen.has-twentythree-stage-self .guest-self-tile,
    .stage-screen.has-twentyfour-stage-self .guest-self-tile,
    .stage-screen.has-twentyfive-stage-self .guest-self-tile {
      top: 0;
      left: 50%;
      right: auto;
      bottom: auto;
      width: 12.5%;
      height: 16.666667%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 2px solid rgba(8, 12, 20, .8);
      box-shadow: none;
      background: var(--stage-fill);
      border-radius: 0;
    }

    .stage-screen.has-fourteen-stage-self .guest-self-video,
    .stage-screen.has-fifteen-stage-self .guest-self-video,
    .stage-screen.has-sixteen-stage-self .guest-self-video,
    .stage-screen.has-seventeen-stage-self .guest-self-video,
    .stage-screen.has-eighteen-stage-self .guest-self-video,
    .stage-screen.has-nineteen-stage-self .guest-self-video,
    .stage-screen.has-twenty-stage-self .guest-self-video,
    .stage-screen.has-twentyone-stage-self .guest-self-video,
    .stage-screen.has-twentytwo-stage-self .guest-self-video,
    .stage-screen.has-twentythree-stage-self .guest-self-video,
    .stage-screen.has-twentyfour-stage-self .guest-self-video,
    .stage-screen.has-twentyfive-stage-self .guest-self-video {
      height: 100%;
      min-height: 100%;
    }

    .stage-screen.has-fourteen-stage-self .guest-self-meta,
    .stage-screen.has-fifteen-stage-self .guest-self-meta,
    .stage-screen.has-sixteen-stage-self .guest-self-meta,
    .stage-screen.has-seventeen-stage-self .guest-self-meta,
    .stage-screen.has-eighteen-stage-self .guest-self-meta,
    .stage-screen.has-nineteen-stage-self .guest-self-meta,
    .stage-screen.has-twenty-stage-self .guest-self-meta,
    .stage-screen.has-twentyone-stage-self .guest-self-meta,
    .stage-screen.has-twentytwo-stage-self .guest-self-meta,
    .stage-screen.has-twentythree-stage-self .guest-self-meta,
    .stage-screen.has-twentyfour-stage-self .guest-self-meta,
    .stage-screen.has-twentyfive-stage-self .guest-self-meta {
      position: absolute;
      top: 10px;
      left: 12px;
      right: auto;
      bottom: auto;
      padding: 0;
      background: transparent;
      text-shadow: 0 1px 3px rgba(0,0,0,.48);
      z-index: 2;
    }

    .stage-screen.has-fourteen-stage-self .guest-audience-tile:nth-child(1),
    .stage-screen.has-fifteen-stage-self .guest-audience-tile:nth-child(1),
    .stage-screen.has-sixteen-stage-self .guest-audience-tile:nth-child(1),
    .stage-screen.has-seventeen-stage-self .guest-audience-tile:nth-child(1),
    .stage-screen.has-eighteen-stage-self .guest-audience-tile:nth-child(1),
    .stage-screen.has-nineteen-stage-self .guest-audience-tile:nth-child(1),
    .stage-screen.has-twenty-stage-self .guest-audience-tile:nth-child(1),
    .stage-screen.has-twentyone-stage-self .guest-audience-tile:nth-child(1),
    .stage-screen.has-twentytwo-stage-self .guest-audience-tile:nth-child(1),
    .stage-screen.has-twentythree-stage-self .guest-audience-tile:nth-child(1),
    .stage-screen.has-twentyfour-stage-self .guest-audience-tile:nth-child(1),
    .stage-screen.has-twentyfive-stage-self .guest-audience-tile:nth-child(1) { grid-column: 2; grid-row: 1; }

    .stage-screen.has-fourteen-stage-self .guest-audience-tile:nth-child(2),
    .stage-screen.has-fifteen-stage-self .guest-audience-tile:nth-child(2),
    .stage-screen.has-sixteen-stage-self .guest-audience-tile:nth-child(2),
    .stage-screen.has-seventeen-stage-self .guest-audience-tile:nth-child(2),
    .stage-screen.has-eighteen-stage-self .guest-audience-tile:nth-child(2),
    .stage-screen.has-nineteen-stage-self .guest-audience-tile:nth-child(2),
    .stage-screen.has-twenty-stage-self .guest-audience-tile:nth-child(2),
    .stage-screen.has-twentyone-stage-self .guest-audience-tile:nth-child(2),
    .stage-screen.has-twentytwo-stage-self .guest-audience-tile:nth-child(2),
    .stage-screen.has-twentythree-stage-self .guest-audience-tile:nth-child(2),
    .stage-screen.has-twentyfour-stage-self .guest-audience-tile:nth-child(2),
    .stage-screen.has-twentyfive-stage-self .guest-audience-tile:nth-child(2) { grid-column: 3; grid-row: 1; }

    .stage-screen.has-fourteen-stage-self .guest-audience-tile:nth-child(3),
    .stage-screen.has-fifteen-stage-self .guest-audience-tile:nth-child(3),
    .stage-screen.has-sixteen-stage-self .guest-audience-tile:nth-child(3),
    .stage-screen.has-seventeen-stage-self .guest-audience-tile:nth-child(3),
    .stage-screen.has-eighteen-stage-self .guest-audience-tile:nth-child(3),
    .stage-screen.has-nineteen-stage-self .guest-audience-tile:nth-child(3),
    .stage-screen.has-twenty-stage-self .guest-audience-tile:nth-child(3),
    .stage-screen.has-twentyone-stage-self .guest-audience-tile:nth-child(3),
    .stage-screen.has-twentytwo-stage-self .guest-audience-tile:nth-child(3),
    .stage-screen.has-twentythree-stage-self .guest-audience-tile:nth-child(3),
    .stage-screen.has-twentyfour-stage-self .guest-audience-tile:nth-child(3),
    .stage-screen.has-twentyfive-stage-self .guest-audience-tile:nth-child(3) { grid-column: 4; grid-row: 1; }

    .stage-screen.has-fourteen-stage-self .guest-audience-tile:nth-child(4),
    .stage-screen.has-fifteen-stage-self .guest-audience-tile:nth-child(4),
    .stage-screen.has-sixteen-stage-self .guest-audience-tile:nth-child(4),
    .stage-screen.has-seventeen-stage-self .guest-audience-tile:nth-child(4),
    .stage-screen.has-eighteen-stage-self .guest-audience-tile:nth-child(4),
    .stage-screen.has-nineteen-stage-self .guest-audience-tile:nth-child(4),
    .stage-screen.has-twenty-stage-self .guest-audience-tile:nth-child(4),
    .stage-screen.has-twentyone-stage-self .guest-audience-tile:nth-child(4),
    .stage-screen.has-twentytwo-stage-self .guest-audience-tile:nth-child(4),
    .stage-screen.has-twentythree-stage-self .guest-audience-tile:nth-child(4),
    .stage-screen.has-twentyfour-stage-self .guest-audience-tile:nth-child(4),
    .stage-screen.has-twentyfive-stage-self .guest-audience-tile:nth-child(4) { grid-column: 1; grid-row: 2; }

    .stage-screen.has-fourteen-stage-self .guest-audience-tile:nth-child(5),
    .stage-screen.has-fifteen-stage-self .guest-audience-tile:nth-child(5),
    .stage-screen.has-sixteen-stage-self .guest-audience-tile:nth-child(5),
    .stage-screen.has-seventeen-stage-self .guest-audience-tile:nth-child(5),
    .stage-screen.has-eighteen-stage-self .guest-audience-tile:nth-child(5),
    .stage-screen.has-nineteen-stage-self .guest-audience-tile:nth-child(5),
    .stage-screen.has-twenty-stage-self .guest-audience-tile:nth-child(5),
    .stage-screen.has-twentyone-stage-self .guest-audience-tile:nth-child(5),
    .stage-screen.has-twentytwo-stage-self .guest-audience-tile:nth-child(5),
    .stage-screen.has-twentythree-stage-self .guest-audience-tile:nth-child(5),
    .stage-screen.has-twentyfour-stage-self .guest-audience-tile:nth-child(5),
    .stage-screen.has-twentyfive-stage-self .guest-audience-tile:nth-child(5) { grid-column: 2; grid-row: 2; }

    .stage-screen.has-fourteen-stage-self .guest-audience-tile:nth-child(6),
    .stage-screen.has-fifteen-stage-self .guest-audience-tile:nth-child(6),
    .stage-screen.has-sixteen-stage-self .guest-audience-tile:nth-child(6),
    .stage-screen.has-seventeen-stage-self .guest-audience-tile:nth-child(6),
    .stage-screen.has-eighteen-stage-self .guest-audience-tile:nth-child(6),
    .stage-screen.has-nineteen-stage-self .guest-audience-tile:nth-child(6),
    .stage-screen.has-twenty-stage-self .guest-audience-tile:nth-child(6),
    .stage-screen.has-twentyone-stage-self .guest-audience-tile:nth-child(6),
    .stage-screen.has-twentytwo-stage-self .guest-audience-tile:nth-child(6),
    .stage-screen.has-twentythree-stage-self .guest-audience-tile:nth-child(6),
    .stage-screen.has-twentyfour-stage-self .guest-audience-tile:nth-child(6),
    .stage-screen.has-twentyfive-stage-self .guest-audience-tile:nth-child(6) { grid-column: 3; grid-row: 2; }

    .stage-screen.has-fourteen-stage-self .guest-audience-tile:nth-child(7),
    .stage-screen.has-fifteen-stage-self .guest-audience-tile:nth-child(7),
    .stage-screen.has-sixteen-stage-self .guest-audience-tile:nth-child(7),
    .stage-screen.has-seventeen-stage-self .guest-audience-tile:nth-child(7),
    .stage-screen.has-eighteen-stage-self .guest-audience-tile:nth-child(7),
    .stage-screen.has-nineteen-stage-self .guest-audience-tile:nth-child(7),
    .stage-screen.has-twenty-stage-self .guest-audience-tile:nth-child(7),
    .stage-screen.has-twentyone-stage-self .guest-audience-tile:nth-child(7),
    .stage-screen.has-twentytwo-stage-self .guest-audience-tile:nth-child(7),
    .stage-screen.has-twentythree-stage-self .guest-audience-tile:nth-child(7),
    .stage-screen.has-twentyfour-stage-self .guest-audience-tile:nth-child(7),
    .stage-screen.has-twentyfive-stage-self .guest-audience-tile:nth-child(7) { grid-column: 4; grid-row: 2; }

    .stage-screen.has-fourteen-stage-self .guest-audience-tile:nth-child(8),
    .stage-screen.has-fifteen-stage-self .guest-audience-tile:nth-child(8),
    .stage-screen.has-sixteen-stage-self .guest-audience-tile:nth-child(8),
    .stage-screen.has-seventeen-stage-self .guest-audience-tile:nth-child(8),
    .stage-screen.has-eighteen-stage-self .guest-audience-tile:nth-child(8),
    .stage-screen.has-nineteen-stage-self .guest-audience-tile:nth-child(8),
    .stage-screen.has-twenty-stage-self .guest-audience-tile:nth-child(8),
    .stage-screen.has-twentyone-stage-self .guest-audience-tile:nth-child(8),
    .stage-screen.has-twentytwo-stage-self .guest-audience-tile:nth-child(8),
    .stage-screen.has-twentythree-stage-self .guest-audience-tile:nth-child(8),
    .stage-screen.has-twentyfour-stage-self .guest-audience-tile:nth-child(8),
    .stage-screen.has-twentyfive-stage-self .guest-audience-tile:nth-child(8) { grid-column: 1; grid-row: 3; }

    .stage-screen.has-fourteen-stage-self .guest-audience-tile:nth-child(9),
    .stage-screen.has-fifteen-stage-self .guest-audience-tile:nth-child(9),
    .stage-screen.has-sixteen-stage-self .guest-audience-tile:nth-child(9),
    .stage-screen.has-seventeen-stage-self .guest-audience-tile:nth-child(9),
    .stage-screen.has-eighteen-stage-self .guest-audience-tile:nth-child(9),
    .stage-screen.has-nineteen-stage-self .guest-audience-tile:nth-child(9),
    .stage-screen.has-twenty-stage-self .guest-audience-tile:nth-child(9),
    .stage-screen.has-twentyone-stage-self .guest-audience-tile:nth-child(9),
    .stage-screen.has-twentytwo-stage-self .guest-audience-tile:nth-child(9),
    .stage-screen.has-twentythree-stage-self .guest-audience-tile:nth-child(9),
    .stage-screen.has-twentyfour-stage-self .guest-audience-tile:nth-child(9),
    .stage-screen.has-twentyfive-stage-self .guest-audience-tile:nth-child(9) { grid-column: 2; grid-row: 3; }

    .stage-screen.has-fourteen-stage-self .guest-audience-tile:nth-child(10),
    .stage-screen.has-fifteen-stage-self .guest-audience-tile:nth-child(10),
    .stage-screen.has-sixteen-stage-self .guest-audience-tile:nth-child(10),
    .stage-screen.has-seventeen-stage-self .guest-audience-tile:nth-child(10),
    .stage-screen.has-eighteen-stage-self .guest-audience-tile:nth-child(10),
    .stage-screen.has-nineteen-stage-self .guest-audience-tile:nth-child(10),
    .stage-screen.has-twenty-stage-self .guest-audience-tile:nth-child(10),
    .stage-screen.has-twentyone-stage-self .guest-audience-tile:nth-child(10),
    .stage-screen.has-twentytwo-stage-self .guest-audience-tile:nth-child(10),
    .stage-screen.has-twentythree-stage-self .guest-audience-tile:nth-child(10),
    .stage-screen.has-twentyfour-stage-self .guest-audience-tile:nth-child(10),
    .stage-screen.has-twentyfive-stage-self .guest-audience-tile:nth-child(10) { grid-column: 3; grid-row: 3; }

    .stage-screen.has-fourteen-stage-self .guest-audience-tile:nth-child(11),
    .stage-screen.has-fifteen-stage-self .guest-audience-tile:nth-child(11),
    .stage-screen.has-sixteen-stage-self .guest-audience-tile:nth-child(11),
    .stage-screen.has-seventeen-stage-self .guest-audience-tile:nth-child(11),
    .stage-screen.has-eighteen-stage-self .guest-audience-tile:nth-child(11),
    .stage-screen.has-nineteen-stage-self .guest-audience-tile:nth-child(11),
    .stage-screen.has-twenty-stage-self .guest-audience-tile:nth-child(11),
    .stage-screen.has-twentyone-stage-self .guest-audience-tile:nth-child(11),
    .stage-screen.has-twentytwo-stage-self .guest-audience-tile:nth-child(11),
    .stage-screen.has-twentythree-stage-self .guest-audience-tile:nth-child(11),
    .stage-screen.has-twentyfour-stage-self .guest-audience-tile:nth-child(11),
    .stage-screen.has-twentyfive-stage-self .guest-audience-tile:nth-child(11) { grid-column: 4; grid-row: 3; }

    .stage-screen.has-fourteen-stage-self .guest-audience-tile:nth-child(12),
    .stage-screen.has-fifteen-stage-self .guest-audience-tile:nth-child(12),
    .stage-screen.has-sixteen-stage-self .guest-audience-tile:nth-child(12),
    .stage-screen.has-seventeen-stage-self .guest-audience-tile:nth-child(12),
    .stage-screen.has-eighteen-stage-self .guest-audience-tile:nth-child(12),
    .stage-screen.has-nineteen-stage-self .guest-audience-tile:nth-child(12),
    .stage-screen.has-twenty-stage-self .guest-audience-tile:nth-child(12),
    .stage-screen.has-twentyone-stage-self .guest-audience-tile:nth-child(12),
    .stage-screen.has-twentytwo-stage-self .guest-audience-tile:nth-child(12),
    .stage-screen.has-twentythree-stage-self .guest-audience-tile:nth-child(12),
    .stage-screen.has-twentyfour-stage-self .guest-audience-tile:nth-child(12),
    .stage-screen.has-twentyfive-stage-self .guest-audience-tile:nth-child(12) { grid-column: 1; grid-row: 4; }

    .stage-screen.has-fourteen-stage-self .guest-audience-tile:nth-child(13),
    .stage-screen.has-fifteen-stage-self .guest-audience-tile:nth-child(13),
    .stage-screen.has-sixteen-stage-self .guest-audience-tile:nth-child(13),
    .stage-screen.has-seventeen-stage-self .guest-audience-tile:nth-child(13),
    .stage-screen.has-eighteen-stage-self .guest-audience-tile:nth-child(13),
    .stage-screen.has-nineteen-stage-self .guest-audience-tile:nth-child(13),
    .stage-screen.has-twenty-stage-self .guest-audience-tile:nth-child(13),
    .stage-screen.has-twentyone-stage-self .guest-audience-tile:nth-child(13),
    .stage-screen.has-twentytwo-stage-self .guest-audience-tile:nth-child(13),
    .stage-screen.has-twentythree-stage-self .guest-audience-tile:nth-child(13),
    .stage-screen.has-twentyfour-stage-self .guest-audience-tile:nth-child(13),
    .stage-screen.has-twentyfive-stage-self .guest-audience-tile:nth-child(13) { grid-column: 2; grid-row: 4; }

    .stage-screen.has-fourteen-stage-self .guest-audience-tile:nth-child(14),
    .stage-screen.has-fifteen-stage-self .guest-audience-tile:nth-child(14),
    .stage-screen.has-sixteen-stage-self .guest-audience-tile:nth-child(14),
    .stage-screen.has-seventeen-stage-self .guest-audience-tile:nth-child(14),
    .stage-screen.has-eighteen-stage-self .guest-audience-tile:nth-child(14),
    .stage-screen.has-nineteen-stage-self .guest-audience-tile:nth-child(14),
    .stage-screen.has-twenty-stage-self .guest-audience-tile:nth-child(14),
    .stage-screen.has-twentyone-stage-self .guest-audience-tile:nth-child(14),
    .stage-screen.has-twentytwo-stage-self .guest-audience-tile:nth-child(14),
    .stage-screen.has-twentythree-stage-self .guest-audience-tile:nth-child(14),
    .stage-screen.has-twentyfour-stage-self .guest-audience-tile:nth-child(14),
    .stage-screen.has-twentyfive-stage-self .guest-audience-tile:nth-child(14) { grid-column: 3; grid-row: 4; }

    .stage-screen.has-fourteen-stage-self .guest-audience-tile:nth-child(15),
    .stage-screen.has-fifteen-stage-self .guest-audience-tile:nth-child(15),
    .stage-screen.has-sixteen-stage-self .guest-audience-tile:nth-child(15),
    .stage-screen.has-seventeen-stage-self .guest-audience-tile:nth-child(15),
    .stage-screen.has-eighteen-stage-self .guest-audience-tile:nth-child(15),
    .stage-screen.has-nineteen-stage-self .guest-audience-tile:nth-child(15),
    .stage-screen.has-twenty-stage-self .guest-audience-tile:nth-child(15),
    .stage-screen.has-twentyone-stage-self .guest-audience-tile:nth-child(15),
    .stage-screen.has-twentytwo-stage-self .guest-audience-tile:nth-child(15),
    .stage-screen.has-twentythree-stage-self .guest-audience-tile:nth-child(15),
    .stage-screen.has-twentyfour-stage-self .guest-audience-tile:nth-child(15),
    .stage-screen.has-twentyfive-stage-self .guest-audience-tile:nth-child(15) { grid-column: 4; grid-row: 4; }

    .stage-screen.has-fourteen-stage-self .guest-audience-tile:nth-child(16),
    .stage-screen.has-fifteen-stage-self .guest-audience-tile:nth-child(16),
    .stage-screen.has-sixteen-stage-self .guest-audience-tile:nth-child(16),
    .stage-screen.has-seventeen-stage-self .guest-audience-tile:nth-child(16),
    .stage-screen.has-eighteen-stage-self .guest-audience-tile:nth-child(16),
    .stage-screen.has-nineteen-stage-self .guest-audience-tile:nth-child(16),
    .stage-screen.has-twenty-stage-self .guest-audience-tile:nth-child(16),
    .stage-screen.has-twentyone-stage-self .guest-audience-tile:nth-child(16),
    .stage-screen.has-twentytwo-stage-self .guest-audience-tile:nth-child(16),
    .stage-screen.has-twentythree-stage-self .guest-audience-tile:nth-child(16),
    .stage-screen.has-twentyfour-stage-self .guest-audience-tile:nth-child(16),
    .stage-screen.has-twentyfive-stage-self .guest-audience-tile:nth-child(16) { grid-column: 1; grid-row: 5; }

    .stage-screen.has-fourteen-stage-self .guest-audience-tile:nth-child(17),
    .stage-screen.has-fifteen-stage-self .guest-audience-tile:nth-child(17),
    .stage-screen.has-sixteen-stage-self .guest-audience-tile:nth-child(17),
    .stage-screen.has-seventeen-stage-self .guest-audience-tile:nth-child(17),
    .stage-screen.has-eighteen-stage-self .guest-audience-tile:nth-child(17),
    .stage-screen.has-nineteen-stage-self .guest-audience-tile:nth-child(17),
    .stage-screen.has-twenty-stage-self .guest-audience-tile:nth-child(17),
    .stage-screen.has-twentyone-stage-self .guest-audience-tile:nth-child(17),
    .stage-screen.has-twentytwo-stage-self .guest-audience-tile:nth-child(17),
    .stage-screen.has-twentythree-stage-self .guest-audience-tile:nth-child(17),
    .stage-screen.has-twentyfour-stage-self .guest-audience-tile:nth-child(17),
    .stage-screen.has-twentyfive-stage-self .guest-audience-tile:nth-child(17) { grid-column: 2; grid-row: 5; }

    .stage-screen.has-fourteen-stage-self .guest-audience-tile:nth-child(18),
    .stage-screen.has-fifteen-stage-self .guest-audience-tile:nth-child(18),
    .stage-screen.has-sixteen-stage-self .guest-audience-tile:nth-child(18),
    .stage-screen.has-seventeen-stage-self .guest-audience-tile:nth-child(18),
    .stage-screen.has-eighteen-stage-self .guest-audience-tile:nth-child(18),
    .stage-screen.has-nineteen-stage-self .guest-audience-tile:nth-child(18),
    .stage-screen.has-twenty-stage-self .guest-audience-tile:nth-child(18),
    .stage-screen.has-twentyone-stage-self .guest-audience-tile:nth-child(18),
    .stage-screen.has-twentytwo-stage-self .guest-audience-tile:nth-child(18),
    .stage-screen.has-twentythree-stage-self .guest-audience-tile:nth-child(18),
    .stage-screen.has-twentyfour-stage-self .guest-audience-tile:nth-child(18),
    .stage-screen.has-twentyfive-stage-self .guest-audience-tile:nth-child(18) { grid-column: 3; grid-row: 5; }

    .stage-screen.has-fourteen-stage-self .guest-audience-tile:nth-child(19),
    .stage-screen.has-fifteen-stage-self .guest-audience-tile:nth-child(19),
    .stage-screen.has-sixteen-stage-self .guest-audience-tile:nth-child(19),
    .stage-screen.has-seventeen-stage-self .guest-audience-tile:nth-child(19),
    .stage-screen.has-eighteen-stage-self .guest-audience-tile:nth-child(19),
    .stage-screen.has-nineteen-stage-self .guest-audience-tile:nth-child(19),
    .stage-screen.has-twenty-stage-self .guest-audience-tile:nth-child(19),
    .stage-screen.has-twentyone-stage-self .guest-audience-tile:nth-child(19),
    .stage-screen.has-twentytwo-stage-self .guest-audience-tile:nth-child(19),
    .stage-screen.has-twentythree-stage-self .guest-audience-tile:nth-child(19),
    .stage-screen.has-twentyfour-stage-self .guest-audience-tile:nth-child(19),
    .stage-screen.has-twentyfive-stage-self .guest-audience-tile:nth-child(19) { grid-column: 4; grid-row: 5; }

    .stage-screen.has-fourteen-stage-self .guest-audience-tile:nth-child(20),
    .stage-screen.has-fifteen-stage-self .guest-audience-tile:nth-child(20),
    .stage-screen.has-sixteen-stage-self .guest-audience-tile:nth-child(20),
    .stage-screen.has-seventeen-stage-self .guest-audience-tile:nth-child(20),
    .stage-screen.has-eighteen-stage-self .guest-audience-tile:nth-child(20),
    .stage-screen.has-nineteen-stage-self .guest-audience-tile:nth-child(20),
    .stage-screen.has-twenty-stage-self .guest-audience-tile:nth-child(20),
    .stage-screen.has-twentyone-stage-self .guest-audience-tile:nth-child(20),
    .stage-screen.has-twentytwo-stage-self .guest-audience-tile:nth-child(20),
    .stage-screen.has-twentythree-stage-self .guest-audience-tile:nth-child(20),
    .stage-screen.has-twentyfour-stage-self .guest-audience-tile:nth-child(20),
    .stage-screen.has-twentyfive-stage-self .guest-audience-tile:nth-child(20) { grid-column: 1; grid-row: 6; }

    .stage-screen.has-fourteen-stage-self .guest-audience-tile:nth-child(21),
    .stage-screen.has-fifteen-stage-self .guest-audience-tile:nth-child(21),
    .stage-screen.has-sixteen-stage-self .guest-audience-tile:nth-child(21),
    .stage-screen.has-seventeen-stage-self .guest-audience-tile:nth-child(21),
    .stage-screen.has-eighteen-stage-self .guest-audience-tile:nth-child(21),
    .stage-screen.has-nineteen-stage-self .guest-audience-tile:nth-child(21),
    .stage-screen.has-twenty-stage-self .guest-audience-tile:nth-child(21),
    .stage-screen.has-twentyone-stage-self .guest-audience-tile:nth-child(21),
    .stage-screen.has-twentytwo-stage-self .guest-audience-tile:nth-child(21),
    .stage-screen.has-twentythree-stage-self .guest-audience-tile:nth-child(21),
    .stage-screen.has-twentyfour-stage-self .guest-audience-tile:nth-child(21),
    .stage-screen.has-twentyfive-stage-self .guest-audience-tile:nth-child(21) { grid-column: 2; grid-row: 6; }

    .stage-screen.has-fourteen-stage-self .guest-audience-tile:nth-child(22),
    .stage-screen.has-fifteen-stage-self .guest-audience-tile:nth-child(22),
    .stage-screen.has-sixteen-stage-self .guest-audience-tile:nth-child(22),
    .stage-screen.has-seventeen-stage-self .guest-audience-tile:nth-child(22),
    .stage-screen.has-eighteen-stage-self .guest-audience-tile:nth-child(22),
    .stage-screen.has-nineteen-stage-self .guest-audience-tile:nth-child(22),
    .stage-screen.has-twenty-stage-self .guest-audience-tile:nth-child(22),
    .stage-screen.has-twentyone-stage-self .guest-audience-tile:nth-child(22),
    .stage-screen.has-twentytwo-stage-self .guest-audience-tile:nth-child(22),
    .stage-screen.has-twentythree-stage-self .guest-audience-tile:nth-child(22),
    .stage-screen.has-twentyfour-stage-self .guest-audience-tile:nth-child(22),
    .stage-screen.has-twentyfive-stage-self .guest-audience-tile:nth-child(22) { grid-column: 3; grid-row: 6; }

    .stage-screen.has-fourteen-stage-self .guest-audience-tile:nth-child(23),
    .stage-screen.has-fifteen-stage-self .guest-audience-tile:nth-child(23),
    .stage-screen.has-sixteen-stage-self .guest-audience-tile:nth-child(23),
    .stage-screen.has-seventeen-stage-self .guest-audience-tile:nth-child(23),
    .stage-screen.has-eighteen-stage-self .guest-audience-tile:nth-child(23),
    .stage-screen.has-nineteen-stage-self .guest-audience-tile:nth-child(23),
    .stage-screen.has-twenty-stage-self .guest-audience-tile:nth-child(23),
    .stage-screen.has-twentyone-stage-self .guest-audience-tile:nth-child(23),
    .stage-screen.has-twentytwo-stage-self .guest-audience-tile:nth-child(23),
    .stage-screen.has-twentythree-stage-self .guest-audience-tile:nth-child(23),
    .stage-screen.has-twentyfour-stage-self .guest-audience-tile:nth-child(23),
    .stage-screen.has-twentyfive-stage-self .guest-audience-tile:nth-child(23) { grid-column: 4; grid-row: 6; }

    .stage-screen.has-twentyplus-stage .stage-image,
    .stage-screen.has-twentyplus-stage .stage-video,
    .stage-screen.has-twentyplus-stage-self .stage-image,
    .stage-screen.has-twentyplus-stage-self .stage-video {
      inset: 0 auto auto 0;
      width: 20%;
      height: 16.666667%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 2px solid rgba(8, 12, 20, .8);
    }

    .stage-screen.has-twentyplus-stage .guest-audience-layer,
    .stage-screen.has-twentyplus-stage-self .guest-audience-layer {
      top: 0;
      right: 0;
      bottom: 0;
      left: 0;
      width: 100%;
      display: grid;
      grid-template-columns: repeat(5, minmax(0, 1fr));
      grid-template-rows: repeat(6, minmax(0, 1fr));
      gap: 2px;
      padding: 0;
      background: rgba(8, 12, 20, .8);
      align-items: stretch;
      justify-items: stretch;
    }

    .stage-screen.has-twentyplus-stage .guest-audience-tile,
    .stage-screen.has-twentyplus-stage-self .guest-audience-tile {
      width: 100%;
      height: 100%;
      min-height: 0;
      border: 0;
      box-shadow: none;
      background: var(--stage-fill);
      border-radius: 0;
      position: relative;
    }

    .stage-screen.has-twentyplus-stage .guest-audience-image,
    .stage-screen.has-twentyplus-stage .guest-audience-video,
    .stage-screen.has-twentyplus-stage-self .guest-audience-image,
    .stage-screen.has-twentyplus-stage-self .guest-audience-video {
      height: 100%;
      min-height: 100%;
    }

    .stage-screen.has-twentyplus-stage .guest-audience-meta,
    .stage-screen.has-twentyplus-stage-self .guest-audience-meta {
      position: absolute;
      top: 10px;
      left: 12px;
      right: auto;
      bottom: auto;
      padding: 0;
      background: transparent;
      text-shadow: 0 1px 3px rgba(0,0,0,.48);
      z-index: 2;
    }

    .stage-screen.has-twentyplus-stage .guest-audience-tile:nth-child(1) { grid-column: 2; grid-row: 1; }
    .stage-screen.has-twentyplus-stage .guest-audience-tile:nth-child(2) { grid-column: 3; grid-row: 1; }
    .stage-screen.has-twentyplus-stage .guest-audience-tile:nth-child(3) { grid-column: 4; grid-row: 1; }
    .stage-screen.has-twentyplus-stage .guest-audience-tile:nth-child(4) { grid-column: 5; grid-row: 1; }
    .stage-screen.has-twentyplus-stage .guest-audience-tile:nth-child(5) { grid-column: 1; grid-row: 2; }
    .stage-screen.has-twentyplus-stage .guest-audience-tile:nth-child(6) { grid-column: 2; grid-row: 2; }
    .stage-screen.has-twentyplus-stage .guest-audience-tile:nth-child(7) { grid-column: 3; grid-row: 2; }
    .stage-screen.has-twentyplus-stage .guest-audience-tile:nth-child(8) { grid-column: 4; grid-row: 2; }
    .stage-screen.has-twentyplus-stage .guest-audience-tile:nth-child(9) { grid-column: 5; grid-row: 2; }
    .stage-screen.has-twentyplus-stage .guest-audience-tile:nth-child(10) { grid-column: 1; grid-row: 3; }
    .stage-screen.has-twentyplus-stage .guest-audience-tile:nth-child(11) { grid-column: 2; grid-row: 3; }
    .stage-screen.has-twentyplus-stage .guest-audience-tile:nth-child(12) { grid-column: 3; grid-row: 3; }
    .stage-screen.has-twentyplus-stage .guest-audience-tile:nth-child(13) { grid-column: 4; grid-row: 3; }
    .stage-screen.has-twentyplus-stage .guest-audience-tile:nth-child(14) { grid-column: 5; grid-row: 3; }
    .stage-screen.has-twentyplus-stage .guest-audience-tile:nth-child(15) { grid-column: 1; grid-row: 4; }
    .stage-screen.has-twentyplus-stage .guest-audience-tile:nth-child(16) { grid-column: 2; grid-row: 4; }
    .stage-screen.has-twentyplus-stage .guest-audience-tile:nth-child(17) { grid-column: 3; grid-row: 4; }
    .stage-screen.has-twentyplus-stage .guest-audience-tile:nth-child(18) { grid-column: 4; grid-row: 4; }
    .stage-screen.has-twentyplus-stage .guest-audience-tile:nth-child(19) { grid-column: 5; grid-row: 4; }
    .stage-screen.has-twentyplus-stage .guest-audience-tile:nth-child(20) { grid-column: 1; grid-row: 5; }
    .stage-screen.has-twentyplus-stage .guest-audience-tile:nth-child(21) { grid-column: 2; grid-row: 5; }
    .stage-screen.has-twentyplus-stage .guest-audience-tile:nth-child(22) { grid-column: 3; grid-row: 5; }
    .stage-screen.has-twentyplus-stage .guest-audience-tile:nth-child(23) { grid-column: 4; grid-row: 5; }
    .stage-screen.has-twentyplus-stage .guest-audience-tile:nth-child(24) { grid-column: 5; grid-row: 5; }
    .stage-screen.has-twentyplus-stage .guest-audience-tile:nth-child(25) { grid-column: 1; grid-row: 6; }
    .stage-screen.has-twentyplus-stage .guest-audience-tile:nth-child(26) { grid-column: 2; grid-row: 6; }
    .stage-screen.has-twentyplus-stage .guest-audience-tile:nth-child(27) { grid-column: 3; grid-row: 6; }
    .stage-screen.has-twentyplus-stage .guest-audience-tile:nth-child(28) { grid-column: 4; grid-row: 6; }
    .stage-screen.has-twentyplus-stage .guest-audience-tile:nth-child(29) { grid-column: 5; grid-row: 6; }

    .stage-screen.has-twentyplus-stage-self .guest-self-tile {
      top: 0;
      left: 20%;
      right: auto;
      bottom: auto;
      width: 20%;
      height: 16.666667%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 2px solid rgba(8, 12, 20, .8);
      box-shadow: none;
      background: var(--stage-fill);
      border-radius: 0;
    }

    .stage-screen.has-twentyplus-stage-self .guest-self-video {
      height: 100%;
      min-height: 100%;
    }

    .stage-screen.has-twentyplus-stage-self .guest-self-meta {
      position: absolute;
      top: 10px;
      left: 12px;
      right: auto;
      bottom: auto;
      padding: 0;
      background: transparent;
      text-shadow: 0 1px 3px rgba(0,0,0,.48);
      z-index: 2;
    }

    .stage-screen.has-twentyplus-stage-self .guest-audience-tile:nth-child(1) { grid-column: 3; grid-row: 1; }
    .stage-screen.has-twentyplus-stage-self .guest-audience-tile:nth-child(2) { grid-column: 4; grid-row: 1; }
    .stage-screen.has-twentyplus-stage-self .guest-audience-tile:nth-child(3) { grid-column: 5; grid-row: 1; }
    .stage-screen.has-twentyplus-stage-self .guest-audience-tile:nth-child(4) { grid-column: 1; grid-row: 2; }
    .stage-screen.has-twentyplus-stage-self .guest-audience-tile:nth-child(5) { grid-column: 2; grid-row: 2; }
    .stage-screen.has-twentyplus-stage-self .guest-audience-tile:nth-child(6) { grid-column: 3; grid-row: 2; }
    .stage-screen.has-twentyplus-stage-self .guest-audience-tile:nth-child(7) { grid-column: 4; grid-row: 2; }
    .stage-screen.has-twentyplus-stage-self .guest-audience-tile:nth-child(8) { grid-column: 5; grid-row: 2; }
    .stage-screen.has-twentyplus-stage-self .guest-audience-tile:nth-child(9) { grid-column: 1; grid-row: 3; }
    .stage-screen.has-twentyplus-stage-self .guest-audience-tile:nth-child(10) { grid-column: 2; grid-row: 3; }
    .stage-screen.has-twentyplus-stage-self .guest-audience-tile:nth-child(11) { grid-column: 3; grid-row: 3; }
    .stage-screen.has-twentyplus-stage-self .guest-audience-tile:nth-child(12) { grid-column: 4; grid-row: 3; }
    .stage-screen.has-twentyplus-stage-self .guest-audience-tile:nth-child(13) { grid-column: 5; grid-row: 3; }
    .stage-screen.has-twentyplus-stage-self .guest-audience-tile:nth-child(14) { grid-column: 1; grid-row: 4; }
    .stage-screen.has-twentyplus-stage-self .guest-audience-tile:nth-child(15) { grid-column: 2; grid-row: 4; }
    .stage-screen.has-twentyplus-stage-self .guest-audience-tile:nth-child(16) { grid-column: 3; grid-row: 4; }
    .stage-screen.has-twentyplus-stage-self .guest-audience-tile:nth-child(17) { grid-column: 4; grid-row: 4; }
    .stage-screen.has-twentyplus-stage-self .guest-audience-tile:nth-child(18) { grid-column: 5; grid-row: 4; }
    .stage-screen.has-twentyplus-stage-self .guest-audience-tile:nth-child(19) { grid-column: 1; grid-row: 5; }
    .stage-screen.has-twentyplus-stage-self .guest-audience-tile:nth-child(20) { grid-column: 2; grid-row: 5; }
    .stage-screen.has-twentyplus-stage-self .guest-audience-tile:nth-child(21) { grid-column: 3; grid-row: 5; }
    .stage-screen.has-twentyplus-stage-self .guest-audience-tile:nth-child(22) { grid-column: 4; grid-row: 5; }
    .stage-screen.has-twentyplus-stage-self .guest-audience-tile:nth-child(23) { grid-column: 5; grid-row: 5; }
    .stage-screen.has-twentyplus-stage-self .guest-audience-tile:nth-child(24) { grid-column: 1; grid-row: 6; }
    .stage-screen.has-twentyplus-stage-self .guest-audience-tile:nth-child(25) { grid-column: 2; grid-row: 6; }
    .stage-screen.has-twentyplus-stage-self .guest-audience-tile:nth-child(26) { grid-column: 3; grid-row: 6; }
    .stage-screen.has-twentyplus-stage-self .guest-audience-tile:nth-child(27) { grid-column: 4; grid-row: 6; }
    .stage-screen.has-twentyplus-stage-self .guest-audience-tile:nth-child(28) { grid-column: 5; grid-row: 6; }

    .stage-screen.has-seven-stage .guest-audience-layer,
    .stage-screen.has-seven-stage-self .guest-audience-layer,
    .stage-screen.has-eight-stage .guest-audience-layer,
    .stage-screen.has-eight-stage-self .guest-audience-layer,
    .stage-screen.has-nine-stage .guest-audience-layer,
    .stage-screen.has-nine-stage-self .guest-audience-layer,
    .stage-screen.has-ten-stage .guest-audience-layer,
    .stage-screen.has-ten-stage-self .guest-audience-layer,
    .stage-screen.has-eleven-stage .guest-audience-layer,
    .stage-screen.has-eleven-stage-self .guest-audience-layer,
    .stage-screen.has-twelve-stage .guest-audience-layer,
    .stage-screen.has-twelve-stage-self .guest-audience-layer {
      inset: 0;
      width: 100%;
      gap: 2px;
      padding: 0;
      background: #0b1018;
      align-items: stretch;
      justify-items: stretch;
    }

    .stage-screen.has-seven-stage .guest-audience-tile,
    .stage-screen.has-seven-stage-self .guest-audience-tile,
    .stage-screen.has-eight-stage .guest-audience-tile,
    .stage-screen.has-eight-stage-self .guest-audience-tile,
    .stage-screen.has-nine-stage .guest-audience-tile,
    .stage-screen.has-nine-stage-self .guest-audience-tile,
    .stage-screen.has-ten-stage .guest-audience-tile,
    .stage-screen.has-ten-stage-self .guest-audience-tile,
    .stage-screen.has-eleven-stage .guest-audience-tile,
    .stage-screen.has-eleven-stage-self .guest-audience-tile,
    .stage-screen.has-twelve-stage .guest-audience-tile,
    .stage-screen.has-twelve-stage-self .guest-audience-tile {
      border: 0;
      box-shadow: none;
      background: #0f1622;
    }

    .stage-screen.has-seven-stage .stage-image,
    .stage-screen.has-seven-stage .stage-video,
    .stage-screen.has-seven-stage-self .stage-image,
    .stage-screen.has-seven-stage-self .stage-video,
    .stage-screen.has-eight-stage .stage-image,
    .stage-screen.has-eight-stage .stage-video,
    .stage-screen.has-eight-stage-self .stage-image,
    .stage-screen.has-eight-stage-self .stage-video {
      inset: 0 auto auto 0;
      width: 25%;
      height: 50%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .9);
      border-bottom: 2px solid rgba(8, 12, 20, .9);
      background: #0f1622;
    }

    .stage-screen.has-nine-stage .stage-image,
    .stage-screen.has-nine-stage .stage-video,
    .stage-screen.has-nine-stage-self .stage-image,
    .stage-screen.has-nine-stage-self .stage-video {
      inset: 0 auto auto 0;
      width: 33.333333%;
      height: 33.333333%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .9);
      border-bottom: 2px solid rgba(8, 12, 20, .9);
      background: #0f1622;
    }

    .stage-screen.has-ten-stage .stage-image,
    .stage-screen.has-ten-stage .stage-video,
    .stage-screen.has-ten-stage-self .stage-image,
    .stage-screen.has-ten-stage-self .stage-video {
      inset: 0 auto auto 0;
      width: 20%;
      height: 50%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .9);
      border-bottom: 2px solid rgba(8, 12, 20, .9);
      background: #0f1622;
    }

    .stage-screen.has-eleven-stage .stage-image,
    .stage-screen.has-eleven-stage .stage-video,
    .stage-screen.has-eleven-stage-self .stage-image,
    .stage-screen.has-eleven-stage-self .stage-video,
    .stage-screen.has-twelve-stage .stage-image,
    .stage-screen.has-twelve-stage .stage-video,
    .stage-screen.has-twelve-stage-self .stage-image,
    .stage-screen.has-twelve-stage-self .stage-video {
      inset: 0 auto auto 0;
      width: 25%;
      height: 33.333333%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .9);
      border-bottom: 2px solid rgba(8, 12, 20, .9);
      background: #0f1622;
    }

    .stage-screen.has-seven-stage .guest-audience-layer,
    .stage-screen.has-seven-stage-self .guest-audience-layer,
    .stage-screen.has-eight-stage .guest-audience-layer,
    .stage-screen.has-eight-stage-self .guest-audience-layer {
      grid-template-columns: repeat(4, minmax(0, 1fr));
      grid-template-rows: repeat(2, minmax(0, 1fr));
    }

    .stage-screen.has-nine-stage .guest-audience-layer,
    .stage-screen.has-nine-stage-self .guest-audience-layer {
      grid-template-columns: repeat(3, minmax(0, 1fr));
      grid-template-rows: repeat(3, minmax(0, 1fr));
    }

    .stage-screen.has-ten-stage .guest-audience-layer,
    .stage-screen.has-ten-stage-self .guest-audience-layer {
      grid-template-columns: repeat(5, minmax(0, 1fr));
      grid-template-rows: repeat(2, minmax(0, 1fr));
    }

    .stage-screen.has-eleven-stage .guest-audience-layer,
    .stage-screen.has-eleven-stage-self .guest-audience-layer,
    .stage-screen.has-twelve-stage .guest-audience-layer,
    .stage-screen.has-twelve-stage-self .guest-audience-layer {
      grid-template-columns: repeat(4, minmax(0, 1fr));
      grid-template-rows: repeat(3, minmax(0, 1fr));
    }

    .stage-screen.has-seven-stage-self .guest-self-tile {
      top: 0;
      left: 25%;
      right: auto;
      bottom: auto;
      width: 25%;
      height: 50%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .9);
      border-bottom: 2px solid rgba(8, 12, 20, .9);
      box-shadow: none;
      background: #0f1622;
    }

    .stage-screen.has-eight-stage-self .guest-self-tile {
      top: 57.142857%;
      left: 0;
      right: auto;
      bottom: auto;
      width: 50%;
      height: 42.857143%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .9);
      border-bottom: 0;
      box-shadow: none;
      background: #0f1622;
    }

    .stage-screen.has-nine-stage-self .guest-self-tile {
      top: 0;
      left: 33.333333%;
      right: auto;
      bottom: auto;
      width: 33.333333%;
      height: 33.333333%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .9);
      border-bottom: 2px solid rgba(8, 12, 20, .9);
      box-shadow: none;
      background: #0f1622;
    }

    .stage-screen.has-ten-stage-self .guest-self-tile {
      top: 0;
      left: 20%;
      right: auto;
      bottom: auto;
      width: 20%;
      height: 50%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .9);
      border-bottom: 2px solid rgba(8, 12, 20, .9);
      box-shadow: none;
      background: #0f1622;
    }

    .stage-screen.has-eleven-stage-self .guest-self-tile,
    .stage-screen.has-twelve-stage-self .guest-self-tile {
      top: 0;
      left: 25%;
      right: auto;
      bottom: auto;
      width: 25%;
      height: 33.333333%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .9);
      border-bottom: 2px solid rgba(8, 12, 20, .9);
      box-shadow: none;
      background: #0f1622;
    }

    .stage-screen.has-seven-stage .guest-audience-tile:nth-child(1),
    .stage-screen.has-nine-stage .guest-audience-tile:nth-child(1),
    .stage-screen.has-ten-stage .guest-audience-tile:nth-child(1),
    .stage-screen.has-eleven-stage .guest-audience-tile:nth-child(1),
    .stage-screen.has-twelve-stage .guest-audience-tile:nth-child(1) { grid-column: 2; grid-row: 1; }

    .stage-screen.has-seven-stage .guest-audience-tile:nth-child(2) { grid-column: 3; grid-row: 1; }
    .stage-screen.has-seven-stage .guest-audience-tile:nth-child(3) { grid-column: 4; grid-row: 1; }
    .stage-screen.has-seven-stage .guest-audience-tile:nth-child(4) { grid-column: 1; grid-row: 2; }
    .stage-screen.has-seven-stage .guest-audience-tile:nth-child(5) { grid-column: 2; grid-row: 2; }
    .stage-screen.has-seven-stage .guest-audience-tile:nth-child(6) { grid-column: 3; grid-row: 2; }

    .stage-screen.has-nine-stage .guest-audience-tile:nth-child(2),
    .stage-screen.has-ten-stage .guest-audience-tile:nth-child(2),
    .stage-screen.has-eleven-stage .guest-audience-tile:nth-child(2),
    .stage-screen.has-twelve-stage .guest-audience-tile:nth-child(2) { grid-column: 3; grid-row: 1; }
    .stage-screen.has-nine-stage .guest-audience-tile:nth-child(3) { grid-column: 1; grid-row: 2; }
    .stage-screen.has-nine-stage .guest-audience-tile:nth-child(4) { grid-column: 2; grid-row: 2; }
    .stage-screen.has-nine-stage .guest-audience-tile:nth-child(5) { grid-column: 3; grid-row: 2; }
    .stage-screen.has-nine-stage .guest-audience-tile:nth-child(6) { grid-column: 1; grid-row: 3; }
    .stage-screen.has-nine-stage .guest-audience-tile:nth-child(7) { grid-column: 2; grid-row: 3; }
    .stage-screen.has-nine-stage .guest-audience-tile:nth-child(8) { grid-column: 3; grid-row: 3; }

    .stage-screen.has-ten-stage .guest-audience-tile:nth-child(3) { grid-column: 4; grid-row: 1; }
    .stage-screen.has-ten-stage .guest-audience-tile:nth-child(4) { grid-column: 5; grid-row: 1; }
    .stage-screen.has-ten-stage .guest-audience-tile:nth-child(5) { grid-column: 1; grid-row: 2; }
    .stage-screen.has-ten-stage .guest-audience-tile:nth-child(6) { grid-column: 2; grid-row: 2; }
    .stage-screen.has-ten-stage .guest-audience-tile:nth-child(7) { grid-column: 3; grid-row: 2; }
    .stage-screen.has-ten-stage .guest-audience-tile:nth-child(8) { grid-column: 4; grid-row: 2; }
    .stage-screen.has-ten-stage .guest-audience-tile:nth-child(9) { grid-column: 5; grid-row: 2; }

    .stage-screen.has-eleven-stage .guest-audience-tile:nth-child(3),
    .stage-screen.has-twelve-stage .guest-audience-tile:nth-child(3) { grid-column: 4; grid-row: 1; }
    .stage-screen.has-eleven-stage .guest-audience-tile:nth-child(4),
    .stage-screen.has-twelve-stage .guest-audience-tile:nth-child(4) { grid-column: 1; grid-row: 2; }
    .stage-screen.has-eleven-stage .guest-audience-tile:nth-child(5),
    .stage-screen.has-twelve-stage .guest-audience-tile:nth-child(5) { grid-column: 2; grid-row: 2; }
    .stage-screen.has-eleven-stage .guest-audience-tile:nth-child(6),
    .stage-screen.has-twelve-stage .guest-audience-tile:nth-child(6) { grid-column: 3; grid-row: 2; }
    .stage-screen.has-eleven-stage .guest-audience-tile:nth-child(7),
    .stage-screen.has-twelve-stage .guest-audience-tile:nth-child(7) { grid-column: 4; grid-row: 2; }
    .stage-screen.has-eleven-stage .guest-audience-tile:nth-child(8),
    .stage-screen.has-twelve-stage .guest-audience-tile:nth-child(8) { grid-column: 1; grid-row: 3; }
    .stage-screen.has-eleven-stage .guest-audience-tile:nth-child(9),
    .stage-screen.has-twelve-stage .guest-audience-tile:nth-child(9) { grid-column: 2; grid-row: 3; }
    .stage-screen.has-eleven-stage .guest-audience-tile:nth-child(10),
    .stage-screen.has-twelve-stage .guest-audience-tile:nth-child(10) { grid-column: 3; grid-row: 3; }
    .stage-screen.has-twelve-stage .guest-audience-tile:nth-child(11) { grid-column: 4; grid-row: 3; }

    .stage-screen.has-seven-stage-self .guest-audience-tile:nth-child(1) { grid-column: 3; grid-row: 1; }
    .stage-screen.has-seven-stage-self .guest-audience-tile:nth-child(2) { grid-column: 4; grid-row: 1; }
    .stage-screen.has-seven-stage-self .guest-audience-tile:nth-child(3) { grid-column: 1; grid-row: 2; }
    .stage-screen.has-seven-stage-self .guest-audience-tile:nth-child(4) { grid-column: 2; grid-row: 2; }
    .stage-screen.has-seven-stage-self .guest-audience-tile:nth-child(5) { grid-column: 3; grid-row: 2; }

    .stage-screen.has-nine-stage-self .guest-audience-tile:nth-child(1),
    .stage-screen.has-ten-stage-self .guest-audience-tile:nth-child(1),
    .stage-screen.has-eleven-stage-self .guest-audience-tile:nth-child(1),
    .stage-screen.has-twelve-stage-self .guest-audience-tile:nth-child(1) { grid-column: 3; grid-row: 1; }
    .stage-screen.has-nine-stage-self .guest-audience-tile:nth-child(2) { grid-column: 1; grid-row: 2; }
    .stage-screen.has-nine-stage-self .guest-audience-tile:nth-child(3) { grid-column: 2; grid-row: 2; }
    .stage-screen.has-nine-stage-self .guest-audience-tile:nth-child(4) { grid-column: 3; grid-row: 2; }
    .stage-screen.has-nine-stage-self .guest-audience-tile:nth-child(5) { grid-column: 1; grid-row: 3; }
    .stage-screen.has-nine-stage-self .guest-audience-tile:nth-child(6) { grid-column: 2; grid-row: 3; }
    .stage-screen.has-nine-stage-self .guest-audience-tile:nth-child(7) { grid-column: 3; grid-row: 3; }

    .stage-screen.has-ten-stage-self .guest-audience-tile:nth-child(2) { grid-column: 4; grid-row: 1; }
    .stage-screen.has-ten-stage-self .guest-audience-tile:nth-child(3) { grid-column: 5; grid-row: 1; }
    .stage-screen.has-ten-stage-self .guest-audience-tile:nth-child(4) { grid-column: 1; grid-row: 2; }
    .stage-screen.has-ten-stage-self .guest-audience-tile:nth-child(5) { grid-column: 2; grid-row: 2; }
    .stage-screen.has-ten-stage-self .guest-audience-tile:nth-child(6) { grid-column: 3; grid-row: 2; }
    .stage-screen.has-ten-stage-self .guest-audience-tile:nth-child(7) { grid-column: 4; grid-row: 2; }
    .stage-screen.has-ten-stage-self .guest-audience-tile:nth-child(8) { grid-column: 5; grid-row: 2; }

    .stage-screen.has-eleven-stage-self .guest-audience-tile:nth-child(2),
    .stage-screen.has-twelve-stage-self .guest-audience-tile:nth-child(2) { grid-column: 4; grid-row: 1; }
    .stage-screen.has-eleven-stage-self .guest-audience-tile:nth-child(3),
    .stage-screen.has-twelve-stage-self .guest-audience-tile:nth-child(3) { grid-column: 1; grid-row: 2; }
    .stage-screen.has-eleven-stage-self .guest-audience-tile:nth-child(4),
    .stage-screen.has-twelve-stage-self .guest-audience-tile:nth-child(4) { grid-column: 2; grid-row: 2; }
    .stage-screen.has-eleven-stage-self .guest-audience-tile:nth-child(5),
    .stage-screen.has-twelve-stage-self .guest-audience-tile:nth-child(5) { grid-column: 3; grid-row: 2; }
    .stage-screen.has-eleven-stage-self .guest-audience-tile:nth-child(6),
    .stage-screen.has-twelve-stage-self .guest-audience-tile:nth-child(6) { grid-column: 4; grid-row: 2; }
    .stage-screen.has-eleven-stage-self .guest-audience-tile:nth-child(7),
    .stage-screen.has-twelve-stage-self .guest-audience-tile:nth-child(7) { grid-column: 1; grid-row: 3; }
    .stage-screen.has-eleven-stage-self .guest-audience-tile:nth-child(8),
    .stage-screen.has-twelve-stage-self .guest-audience-tile:nth-child(8) { grid-column: 2; grid-row: 3; }
    .stage-screen.has-eleven-stage-self .guest-audience-tile:nth-child(9),
    .stage-screen.has-twelve-stage-self .guest-audience-tile:nth-child(9) { grid-column: 3; grid-row: 3; }
    .stage-screen.has-twelve-stage-self .guest-audience-tile:nth-child(10) { grid-column: 4; grid-row: 3; }

    .stage-screen.has-seven-stage .stage-image,
    .stage-screen.has-seven-stage .stage-video,
    .stage-screen.has-seven-stage-self .stage-image,
    .stage-screen.has-seven-stage-self .stage-video {
      inset: 0 auto 0 0;
      width: 50%;
      height: 100%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 0;
      background: var(--stage-fill);
    }

    .stage-screen.has-seven-stage .guest-audience-layer,
    .stage-screen.has-seven-stage-self .guest-audience-layer {
      top: 0;
      right: 0;
      bottom: 0;
      left: auto;
      width: 50%;
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      grid-template-rows: repeat(3, minmax(0, 1fr));
      gap: 2px;
      padding: 0;
      background: rgba(8, 12, 20, .8);
      align-items: stretch;
      justify-items: stretch;
    }

    .stage-screen.has-seven-stage .guest-audience-tile,
    .stage-screen.has-seven-stage-self .guest-audience-tile {
      background: var(--stage-fill);
    }

    .stage-screen.has-seven-stage .guest-audience-image,
    .stage-screen.has-seven-stage .guest-audience-video,
    .stage-screen.has-seven-stage-self .guest-audience-image,
    .stage-screen.has-seven-stage-self .guest-audience-video,
    .stage-screen.has-seven-stage-self .guest-self-video {
      object-fit: cover;
      object-position: center center;
      background: var(--stage-fill);
    }

    .stage-screen.has-seven-stage .guest-audience-tile:nth-child(1) { grid-column: 1; grid-row: 1; }
    .stage-screen.has-seven-stage .guest-audience-tile:nth-child(2) { grid-column: 2; grid-row: 1; }
    .stage-screen.has-seven-stage .guest-audience-tile:nth-child(3) { grid-column: 1; grid-row: 2; }
    .stage-screen.has-seven-stage .guest-audience-tile:nth-child(4) { grid-column: 2; grid-row: 2; }
    .stage-screen.has-seven-stage .guest-audience-tile:nth-child(5) { grid-column: 1; grid-row: 3; }
    .stage-screen.has-seven-stage .guest-audience-tile:nth-child(6) { grid-column: 2; grid-row: 3; }

    .stage-screen.has-seven-stage-self .guest-self-tile {
      top: 0;
      left: 50%;
      right: auto;
      bottom: auto;
      width: 25%;
      height: 33.333333%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 2px solid rgba(8, 12, 20, .8);
      box-shadow: none;
      background: var(--stage-fill);
    }

    .stage-screen.has-seven-stage-self .guest-audience-tile:nth-child(1) { grid-column: 2; grid-row: 1; }
    .stage-screen.has-seven-stage-self .guest-audience-tile:nth-child(2) { grid-column: 1; grid-row: 2; }
    .stage-screen.has-seven-stage-self .guest-audience-tile:nth-child(3) { grid-column: 2; grid-row: 2; }
    .stage-screen.has-seven-stage-self .guest-audience-tile:nth-child(4) { grid-column: 1; grid-row: 3; }
    .stage-screen.has-seven-stage-self .guest-audience-tile:nth-child(5) { grid-column: 2; grid-row: 3; }

    .stage-screen.has-eight-stage .stage-image,
    .stage-screen.has-eight-stage .stage-video,
    .stage-screen.has-eight-stage-self .stage-image,
    .stage-screen.has-eight-stage-self .stage-video {
      inset: 0 auto auto 0;
      width: 50%;
      height: 57.142857%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 2px solid rgba(8, 12, 20, .8);
      background: var(--stage-fill);
    }

    .stage-screen.has-eight-stage .guest-audience-layer,
    .stage-screen.has-eight-stage-self .guest-audience-layer {
      top: 0;
      right: 0;
      bottom: 0;
      left: auto;
      width: 50%;
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      grid-template-rows: repeat(3, minmax(0, 1fr));
      gap: 2px;
      padding: 0;
      background: rgba(8, 12, 20, .8);
      align-items: stretch;
      justify-items: stretch;
    }

    .stage-screen.has-eight-stage .guest-audience-tile,
    .stage-screen.has-eight-stage-self .guest-audience-tile {
      background: var(--stage-fill);
    }

    .stage-screen.has-eight-stage .guest-audience-image,
    .stage-screen.has-eight-stage .guest-audience-video,
    .stage-screen.has-eight-stage-self .guest-audience-image,
    .stage-screen.has-eight-stage-self .guest-audience-video,
    .stage-screen.has-eight-stage-self .guest-self-video {
      object-fit: cover;
      object-position: center center;
      background: var(--stage-fill);
    }

    .stage-screen.has-eight-stage .guest-audience-tile:nth-child(1),
    .stage-screen.has-eight-stage-self .guest-self-tile {
      position: absolute;
      top: 57.142857%;
      left: 0;
      width: 50%;
      height: 42.857143%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 0;
      box-shadow: none;
      background: var(--stage-fill);
    }

    .stage-screen.has-eight-stage .guest-audience-tile:nth-child(2),
    .stage-screen.has-eight-stage-self .guest-audience-tile:nth-child(1) { grid-column: 1; grid-row: 1; }
    .stage-screen.has-eight-stage .guest-audience-tile:nth-child(3),
    .stage-screen.has-eight-stage-self .guest-audience-tile:nth-child(2) { grid-column: 2; grid-row: 1; }
    .stage-screen.has-eight-stage .guest-audience-tile:nth-child(4),
    .stage-screen.has-eight-stage-self .guest-audience-tile:nth-child(3) { grid-column: 1; grid-row: 2; }
    .stage-screen.has-eight-stage .guest-audience-tile:nth-child(5),
    .stage-screen.has-eight-stage-self .guest-audience-tile:nth-child(4) { grid-column: 2; grid-row: 2; }
    .stage-screen.has-eight-stage .guest-audience-tile:nth-child(6),
    .stage-screen.has-eight-stage-self .guest-audience-tile:nth-child(5) { grid-column: 1; grid-row: 3; }
    .stage-screen.has-eight-stage .guest-audience-tile:nth-child(7),
    .stage-screen.has-eight-stage-self .guest-audience-tile:nth-child(6) { grid-column: 2; grid-row: 3; }

    .stage-screen.has-nine-stage .stage-image,
    .stage-screen.has-nine-stage .stage-video,
    .stage-screen.has-nine-stage-self .stage-image,
    .stage-screen.has-nine-stage-self .stage-video {
      inset: 0 auto 0 0;
      width: 50%;
      height: 100%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 0;
      background: var(--stage-fill);
    }

    .stage-screen.has-nine-stage .guest-audience-layer,
    .stage-screen.has-nine-stage-self .guest-audience-layer {
      top: 0;
      right: 0;
      bottom: 0;
      left: auto;
      width: 50%;
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      grid-template-rows: repeat(4, minmax(0, 1fr));
      gap: 2px;
      padding: 0;
      background: rgba(8, 12, 20, .8);
      align-items: stretch;
      justify-items: stretch;
    }

    .stage-screen.has-nine-stage .guest-audience-tile,
    .stage-screen.has-nine-stage-self .guest-audience-tile {
      background: var(--stage-fill);
    }

    .stage-screen.has-nine-stage .guest-audience-image,
    .stage-screen.has-nine-stage .guest-audience-video,
    .stage-screen.has-nine-stage-self .guest-audience-image,
    .stage-screen.has-nine-stage-self .guest-audience-video,
    .stage-screen.has-nine-stage-self .guest-self-video {
      width: 100%;
      height: 100%;
      min-height: 100%;
      object-fit: cover;
      object-position: center center;
      background: var(--stage-fill);
    }

    .stage-screen.has-nine-stage-self .guest-self-tile {
      top: 0;
      left: 50%;
      right: auto;
      bottom: auto;
      width: 25%;
      height: 25%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 2px solid rgba(8, 12, 20, .8);
      box-shadow: none;
      background: var(--stage-fill);
    }

    .stage-screen.has-nine-stage .guest-audience-tile:nth-child(1),
    .stage-screen.has-nine-stage-self .guest-audience-tile:nth-child(1) { grid-column: 1; grid-row: 1; }
    .stage-screen.has-nine-stage .guest-audience-tile:nth-child(2),
    .stage-screen.has-nine-stage-self .guest-audience-tile:nth-child(2) { grid-column: 2; grid-row: 1; }
    .stage-screen.has-nine-stage .guest-audience-tile:nth-child(3),
    .stage-screen.has-nine-stage-self .guest-audience-tile:nth-child(3) { grid-column: 1; grid-row: 2; }
    .stage-screen.has-nine-stage .guest-audience-tile:nth-child(4),
    .stage-screen.has-nine-stage-self .guest-audience-tile:nth-child(4) { grid-column: 2; grid-row: 2; }
    .stage-screen.has-nine-stage .guest-audience-tile:nth-child(5),
    .stage-screen.has-nine-stage-self .guest-audience-tile:nth-child(5) { grid-column: 1; grid-row: 3; }
    .stage-screen.has-nine-stage .guest-audience-tile:nth-child(6),
    .stage-screen.has-nine-stage-self .guest-audience-tile:nth-child(6) { grid-column: 2; grid-row: 3; }
    .stage-screen.has-nine-stage .guest-audience-tile:nth-child(7),
    .stage-screen.has-nine-stage-self .guest-audience-tile:nth-child(7) { grid-column: 1; grid-row: 4; }
    .stage-screen.has-nine-stage .guest-audience-tile:nth-child(8),
    .stage-screen.has-nine-stage-self .guest-audience-tile:nth-child(8) { grid-column: 2; grid-row: 4; }

    .stage-screen.has-ten-stage .stage-image,
    .stage-screen.has-ten-stage .stage-video,
    .stage-screen.has-ten-stage-self .stage-image,
    .stage-screen.has-ten-stage-self .stage-video {
      inset: 0 auto auto 0;
      width: 50%;
      height: 50%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 2px solid rgba(8, 12, 20, .8);
      background: var(--stage-fill);
    }

    .stage-screen.has-ten-stage .guest-audience-layer {
      inset: 0;
      top: 0;
      right: 0;
      bottom: 0;
      left: 0;
      width: 100%;
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      grid-template-rows: repeat(8, minmax(0, 1fr));
      gap: 2px;
      padding: 0;
      background: rgba(8, 12, 20, .8);
      align-items: stretch;
      justify-items: stretch;
    }

    .stage-screen.has-ten-stage .guest-audience-tile,
    .stage-screen.has-ten-stage-self .guest-audience-tile {
      background: var(--stage-fill);
    }

    .stage-screen.has-ten-stage .guest-audience-image,
    .stage-screen.has-ten-stage .guest-audience-video,
    .stage-screen.has-ten-stage-self .guest-audience-image,
    .stage-screen.has-ten-stage-self .guest-audience-video,
    .stage-screen.has-ten-stage-self .guest-self-video {
      width: 100%;
      height: 100%;
      min-height: 100%;
      object-fit: cover;
      object-position: center center;
      background: var(--stage-fill);
    }

    .stage-screen.has-ten-stage .guest-audience-tile:nth-child(1) { grid-column: 1 / span 2; grid-row: 5 / span 4; }
    .stage-screen.has-ten-stage .guest-audience-tile:nth-child(2) { grid-column: 3; grid-row: 1 / span 2; }
    .stage-screen.has-ten-stage .guest-audience-tile:nth-child(3) { grid-column: 4; grid-row: 1 / span 2; }
    .stage-screen.has-ten-stage .guest-audience-tile:nth-child(4) { grid-column: 3; grid-row: 3 / span 2; }
    .stage-screen.has-ten-stage .guest-audience-tile:nth-child(5) { grid-column: 4; grid-row: 3 / span 2; }
    .stage-screen.has-ten-stage .guest-audience-tile:nth-child(6) { grid-column: 3; grid-row: 5 / span 2; }
    .stage-screen.has-ten-stage .guest-audience-tile:nth-child(7) { grid-column: 4; grid-row: 5 / span 2; }
    .stage-screen.has-ten-stage .guest-audience-tile:nth-child(8) { grid-column: 3; grid-row: 7 / span 2; }
    .stage-screen.has-ten-stage .guest-audience-tile:nth-child(9) { grid-column: 4; grid-row: 7 / span 2; }

    .stage-screen.has-ten-stage-self .stage-image,
    .stage-screen.has-ten-stage-self .stage-video {
      inset: 0 auto auto 0;
      width: 50%;
      height: 50%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 2px solid rgba(8, 12, 20, .8);
      background: var(--stage-fill);
    }

    .stage-screen.has-ten-stage-self .guest-audience-layer {
      inset: 0;
      top: 0;
      right: 0;
      bottom: 0;
      left: 0;
      width: 100%;
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      grid-template-rows: repeat(8, minmax(0, 1fr));
      gap: 2px;
      padding: 0;
      background: rgba(8, 12, 20, .8);
      align-items: stretch;
      justify-items: stretch;
    }

    .stage-screen.has-ten-stage-self .guest-self-tile {
      position: absolute;
      top: 50%;
      left: 0;
      right: auto;
      bottom: auto;
      width: 50%;
      height: 50%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 0;
      box-shadow: none;
      background: var(--stage-fill);
      z-index: 2;
    }

    .stage-screen.has-ten-stage-self .guest-audience-image,
    .stage-screen.has-ten-stage-self .guest-audience-video,
    .stage-screen.has-ten-stage-self .guest-self-video {
      width: 100%;
      height: 100%;
      min-height: 100%;
      object-fit: cover;
      object-position: center center;
      background: var(--stage-fill);
    }

    .stage-screen.has-ten-stage-self .guest-audience-tile:nth-child(1) { grid-column: 3; grid-row: 1 / span 2; }
    .stage-screen.has-ten-stage-self .guest-audience-tile:nth-child(2) { grid-column: 4; grid-row: 1 / span 2; }
    .stage-screen.has-ten-stage-self .guest-audience-tile:nth-child(3) { grid-column: 3; grid-row: 3 / span 2; }
    .stage-screen.has-ten-stage-self .guest-audience-tile:nth-child(4) { grid-column: 4; grid-row: 3 / span 2; }
    .stage-screen.has-ten-stage-self .guest-audience-tile:nth-child(5) { grid-column: 3; grid-row: 5 / span 2; }
    .stage-screen.has-ten-stage-self .guest-audience-tile:nth-child(6) { grid-column: 4; grid-row: 5 / span 2; }
    .stage-screen.has-ten-stage-self .guest-audience-tile:nth-child(7) { grid-column: 3; grid-row: 7 / span 2; }
    .stage-screen.has-ten-stage-self .guest-audience-tile:nth-child(8) { grid-column: 4; grid-row: 7 / span 2; }

    .stage-screen.has-eleven-stage .stage-image,
    .stage-screen.has-eleven-stage .stage-video,
    .stage-screen.has-eleven-stage-self .stage-image,
    .stage-screen.has-eleven-stage-self .stage-video {
      inset: 0 auto auto 0;
      width: 50%;
      height: 60%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 2px solid rgba(8, 12, 20, .8);
      background: var(--stage-fill);
    }

    .stage-screen.has-eleven-stage .guest-audience-layer {
      inset: 0;
      top: 0;
      right: 0;
      bottom: 0;
      left: 0;
      width: 100%;
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      grid-template-rows: repeat(8, minmax(0, 1fr));
      gap: 2px;
      padding: 0;
      background: rgba(8, 12, 20, .8);
      align-items: stretch;
      justify-items: stretch;
    }

    .stage-screen.has-eleven-stage .guest-audience-tile,
    .stage-screen.has-eleven-stage-self .guest-audience-tile {
      background: var(--stage-fill);
    }

    .stage-screen.has-eleven-stage .guest-audience-image,
    .stage-screen.has-eleven-stage .guest-audience-video,
    .stage-screen.has-eleven-stage-self .guest-audience-image,
    .stage-screen.has-eleven-stage-self .guest-audience-video,
    .stage-screen.has-eleven-stage-self .guest-self-video {
      width: 100%;
      height: 100%;
      min-height: 100%;
      object-fit: cover;
      object-position: center center;
      background: var(--stage-fill);
    }

    .stage-screen.has-eleven-stage .guest-audience-tile:nth-child(1) { grid-column: 1; grid-row: 6 / span 3; }
    .stage-screen.has-eleven-stage .guest-audience-tile:nth-child(2) { grid-column: 2; grid-row: 6 / span 3; }
    .stage-screen.has-eleven-stage .guest-audience-tile:nth-child(3) { grid-column: 3; grid-row: 1 / span 2; }
    .stage-screen.has-eleven-stage .guest-audience-tile:nth-child(4) { grid-column: 4; grid-row: 1 / span 2; }
    .stage-screen.has-eleven-stage .guest-audience-tile:nth-child(5) { grid-column: 3; grid-row: 3 / span 2; }
    .stage-screen.has-eleven-stage .guest-audience-tile:nth-child(6) { grid-column: 4; grid-row: 3 / span 2; }
    .stage-screen.has-eleven-stage .guest-audience-tile:nth-child(7) { grid-column: 3; grid-row: 5 / span 2; }
    .stage-screen.has-eleven-stage .guest-audience-tile:nth-child(8) { grid-column: 4; grid-row: 5 / span 2; }
    .stage-screen.has-eleven-stage .guest-audience-tile:nth-child(9) { grid-column: 3; grid-row: 7 / span 2; }
    .stage-screen.has-eleven-stage .guest-audience-tile:nth-child(10) { grid-column: 4; grid-row: 7 / span 2; }

    .stage-screen.has-eleven-stage-self .guest-audience-layer {
      inset: 0;
      top: 0;
      right: 0;
      bottom: 0;
      left: 0;
      width: 100%;
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      grid-template-rows: repeat(8, minmax(0, 1fr));
      gap: 2px;
      padding: 0;
      background: rgba(8, 12, 20, .8);
      align-items: stretch;
      justify-items: stretch;
    }

    .stage-screen.has-eleven-stage-self .guest-self-tile {
      position: absolute;
      top: 60%;
      left: 0;
      right: auto;
      bottom: auto;
      width: 25%;
      height: 40%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 0;
      box-shadow: none;
      background: var(--stage-fill);
      z-index: 2;
    }

    .stage-screen.has-eleven-stage-self .guest-audience-tile:nth-child(1) { grid-column: 2; grid-row: 6 / span 3; }
    .stage-screen.has-eleven-stage-self .guest-audience-tile:nth-child(2) { grid-column: 3; grid-row: 1 / span 2; }
    .stage-screen.has-eleven-stage-self .guest-audience-tile:nth-child(3) { grid-column: 4; grid-row: 1 / span 2; }
    .stage-screen.has-eleven-stage-self .guest-audience-tile:nth-child(4) { grid-column: 3; grid-row: 3 / span 2; }
    .stage-screen.has-eleven-stage-self .guest-audience-tile:nth-child(5) { grid-column: 4; grid-row: 3 / span 2; }
    .stage-screen.has-eleven-stage-self .guest-audience-tile:nth-child(6) { grid-column: 3; grid-row: 5 / span 2; }
    .stage-screen.has-eleven-stage-self .guest-audience-tile:nth-child(7) { grid-column: 4; grid-row: 5 / span 2; }
    .stage-screen.has-eleven-stage-self .guest-audience-tile:nth-child(8) { grid-column: 3; grid-row: 7 / span 2; }
    .stage-screen.has-eleven-stage-self .guest-audience-tile:nth-child(9) { grid-column: 4; grid-row: 7 / span 2; }

    .stage-screen.has-twelve-stage .stage-image,
    .stage-screen.has-twelve-stage .stage-video,
    .stage-screen.has-twelve-stage-self .stage-image,
    .stage-screen.has-twelve-stage-self .stage-video {
      inset: 0 auto auto 0;
      width: 50%;
      height: 50%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 2px solid rgba(8, 12, 20, .8);
      background: var(--stage-fill);
      z-index: 2;
    }

    .stage-screen.has-twelve-stage .guest-audience-layer,
    .stage-screen.has-twelve-stage-self .guest-audience-layer {
      inset: 0;
      width: 100%;
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      grid-template-rows: repeat(4, minmax(0, 1fr));
      gap: 2px;
      padding: 0;
      background: rgba(8, 12, 20, .8);
      align-items: stretch;
      justify-items: stretch;
    }

    .stage-screen.has-twelve-stage .guest-audience-tile,
    .stage-screen.has-twelve-stage-self .guest-audience-tile {
      background: var(--stage-fill);
    }

    .stage-screen.has-twelve-stage .guest-audience-image,
    .stage-screen.has-twelve-stage .guest-audience-video,
    .stage-screen.has-twelve-stage-self .guest-audience-image,
    .stage-screen.has-twelve-stage-self .guest-audience-video,
    .stage-screen.has-twelve-stage-self .guest-self-video {
      object-fit: cover;
      object-position: center center;
      background: var(--stage-fill);
    }

    .stage-screen.has-twelve-stage-self .guest-self-tile {
      position: absolute;
      top: 50%;
      left: 0;
      right: auto;
      bottom: auto;
      width: 25%;
      height: 50%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 0;
      box-shadow: none;
      background: var(--stage-fill);
      z-index: 1;
    }

    .stage-screen.has-twelve-stage .guest-audience-tile:nth-child(1) { grid-column: 3; grid-row: 1; }
    .stage-screen.has-twelve-stage .guest-audience-tile:nth-child(2) { grid-column: 4; grid-row: 1; }
    .stage-screen.has-twelve-stage .guest-audience-tile:nth-child(3) { grid-column: 3; grid-row: 2; }
    .stage-screen.has-twelve-stage .guest-audience-tile:nth-child(4) { grid-column: 4; grid-row: 2; }
    .stage-screen.has-twelve-stage .guest-audience-tile:nth-child(5) { grid-column: 1; grid-row: 3 / span 2; }
    .stage-screen.has-twelve-stage .guest-audience-tile:nth-child(6) { grid-column: 2; grid-row: 3; }
    .stage-screen.has-twelve-stage .guest-audience-tile:nth-child(7) { grid-column: 3; grid-row: 3; }
    .stage-screen.has-twelve-stage .guest-audience-tile:nth-child(8) { grid-column: 4; grid-row: 3; }
    .stage-screen.has-twelve-stage .guest-audience-tile:nth-child(9) { grid-column: 2; grid-row: 4; }
    .stage-screen.has-twelve-stage .guest-audience-tile:nth-child(10) { grid-column: 3; grid-row: 4; }
    .stage-screen.has-twelve-stage .guest-audience-tile:nth-child(11) { grid-column: 4; grid-row: 4; }

    .stage-screen.has-twelve-stage-self .guest-audience-tile:nth-child(1) { grid-column: 3; grid-row: 1; }
    .stage-screen.has-twelve-stage-self .guest-audience-tile:nth-child(2) { grid-column: 4; grid-row: 1; }
    .stage-screen.has-twelve-stage-self .guest-audience-tile:nth-child(3) { grid-column: 3; grid-row: 2; }
    .stage-screen.has-twelve-stage-self .guest-audience-tile:nth-child(4) { grid-column: 4; grid-row: 2; }
    .stage-screen.has-twelve-stage-self .guest-audience-tile:nth-child(5) { grid-column: 2; grid-row: 3; }
    .stage-screen.has-twelve-stage-self .guest-audience-tile:nth-child(6) { grid-column: 3; grid-row: 3; }
    .stage-screen.has-twelve-stage-self .guest-audience-tile:nth-child(7) { grid-column: 4; grid-row: 3; }
    .stage-screen.has-twelve-stage-self .guest-audience-tile:nth-child(8) { grid-column: 2; grid-row: 4; }
    .stage-screen.has-twelve-stage-self .guest-audience-tile:nth-child(9) { grid-column: 3; grid-row: 4; }
    .stage-screen.has-twelve-stage-self .guest-audience-tile:nth-child(10) { grid-column: 4; grid-row: 4; }

    .stage-screen.has-host-layout .stage-image,
    .stage-screen.has-host-layout .stage-video {
      inset: 0;
      width: 100%;
      height: 100%;
      border: 0;
    }
    .stage-screen.has-host-layout .guest-self-tile,
    .stage-screen.has-host-layout .guest-audience-layer {
      top: auto;
      right: auto;
      bottom: 28px;
      width: auto;
      display: flex;
      gap: 14px;
      align-items: flex-end;
      justify-items: initial;
      overflow-x: auto;
      overflow-y: hidden;
      padding: 0;
    }
    .stage-screen.has-host-layout .guest-self-tile {
      left: 24px;
      width: 188px;
      border-radius: 18px;
      border: 1px solid rgba(255,255,255,.18);
      background: rgba(8,10,14,.92);
      box-shadow: 0 18px 34px rgba(0,0,0,.28);
    }
    .stage-screen.has-host-layout .guest-audience-layer {
      left: 24px;
      max-width: calc(100% - 48px);
    }
    .stage-screen.has-host-layout.has-host-layout-self .guest-audience-layer {
      left: 226px;
      max-width: calc(100% - 250px);
    }
    .stage-screen.has-host-layout .guest-audience-tile {
      width: 188px;
      min-width: 188px;
      height: auto;
      border-radius: 18px;
    }
    .stage-screen.has-host-layout .guest-audience-image,
    .stage-screen.has-host-layout .guest-audience-video,
    .stage-screen.has-host-layout .guest-self-video,
    .stage-screen.has-host-layout .guest-audience-placeholder {
      height: 132px;
      min-height: 132px;
    }
    .stage-screen.has-host-layout .guest-self-meta,
    .stage-screen.has-host-layout .guest-audience-meta {
      padding: 10px 12px;
      position: static;
      background: #151922;
      text-shadow: none;
    }
    .stage-top,
    .stage-center,
    .stage-note,
    .stage-screen.has-host-layout .stage-center,
    .stage-screen.has-host-layout .stage-note {
      display: none;
    }
    .stage-screen.has-dual-stage .stage-top,
    .stage-screen.has-dual-stage .stage-center,
    .stage-screen.has-dual-stage .stage-note,
    .stage-screen.has-three-stage .stage-top,
    .stage-screen.has-three-stage .stage-center,
    .stage-screen.has-three-stage .stage-note,
    .stage-screen.has-three-stage-self .stage-top,
    .stage-screen.has-three-stage-self .stage-center,
    .stage-screen.has-three-stage-self .stage-note,
    .stage-screen.has-four-stage .stage-top,
    .stage-screen.has-four-stage .stage-center,
    .stage-screen.has-four-stage .stage-note,
    .stage-screen.has-four-stage-self .stage-top,
    .stage-screen.has-four-stage-self .stage-center,
    .stage-screen.has-four-stage-self .stage-note,
    .stage-screen.has-five-stage .stage-top,
    .stage-screen.has-five-stage .stage-center,
    .stage-screen.has-five-stage .stage-note,
    .stage-screen.has-five-stage-self .stage-top,
    .stage-screen.has-five-stage-self .stage-center,
    .stage-screen.has-five-stage-self .stage-note,
    .stage-screen.has-six-stage .stage-top,
    .stage-screen.has-six-stage .stage-center,
    .stage-screen.has-six-stage .stage-note,
    .stage-screen.has-six-stage-self .stage-top,
    .stage-screen.has-six-stage-self .stage-center,
    .stage-screen.has-six-stage-self .stage-note,
    .stage-screen.has-seven-stage .stage-top,
    .stage-screen.has-seven-stage .stage-center,
    .stage-screen.has-seven-stage .stage-note,
    .stage-screen.has-seven-stage-self .stage-top,
    .stage-screen.has-seven-stage-self .stage-center,
    .stage-screen.has-seven-stage-self .stage-note,
    .stage-screen.has-eight-stage .stage-top,
    .stage-screen.has-eight-stage .stage-center,
    .stage-screen.has-eight-stage .stage-note,
    .stage-screen.has-eight-stage-self .stage-top,
    .stage-screen.has-eight-stage-self .stage-center,
    .stage-screen.has-eight-stage-self .stage-note,
    .stage-screen.has-nine-stage .stage-top,
    .stage-screen.has-nine-stage .stage-center,
    .stage-screen.has-nine-stage .stage-note,
    .stage-screen.has-nine-stage-self .stage-top,
    .stage-screen.has-nine-stage-self .stage-center,
    .stage-screen.has-nine-stage-self .stage-note,
    .stage-screen.has-ten-stage .stage-top,
    .stage-screen.has-ten-stage .stage-center,
    .stage-screen.has-ten-stage .stage-note,
    .stage-screen.has-ten-stage-self .stage-top,
    .stage-screen.has-ten-stage-self .stage-center,
    .stage-screen.has-ten-stage-self .stage-note,
    .stage-screen.has-eleven-stage .stage-top,
    .stage-screen.has-eleven-stage .stage-center,
    .stage-screen.has-eleven-stage .stage-note,
    .stage-screen.has-eleven-stage-self .stage-top,
    .stage-screen.has-eleven-stage-self .stage-center,
    .stage-screen.has-eleven-stage-self .stage-note,
    .stage-screen.has-twelve-stage .stage-top,
    .stage-screen.has-twelve-stage .stage-center,
    .stage-screen.has-twelve-stage .stage-note,
    .stage-screen.has-twelve-stage-self .stage-top,
    .stage-screen.has-twelve-stage-self .stage-center,
    .stage-screen.has-twelve-stage-self .stage-note,
    .stage-screen.has-thirteen-stage .stage-image,
    .stage-screen.has-thirteen-stage .stage-video,
    .stage-screen.has-thirteen-stage-self .stage-image,
    .stage-screen.has-thirteen-stage-self .stage-video {
      inset: 0 auto auto 0;
      width: 50%;
      height: 50%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 2px solid rgba(8, 12, 20, .8);
      background: var(--stage-fill);
      z-index: 2;
    }

    .stage-screen.has-thirteen-stage .guest-audience-layer,
    .stage-screen.has-thirteen-stage-self .guest-audience-layer {
      inset: 0;
      width: 100%;
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      grid-template-rows: repeat(4, minmax(0, 1fr));
      gap: 2px;
      padding: 0;
      background: rgba(8, 12, 20, .8);
      align-items: stretch;
      justify-items: stretch;
    }

    .stage-screen.has-thirteen-stage .guest-audience-tile,
    .stage-screen.has-thirteen-stage-self .guest-audience-tile {
      background: var(--stage-fill);
    }

    .stage-screen.has-thirteen-stage .guest-audience-image,
    .stage-screen.has-thirteen-stage .guest-audience-video,
    .stage-screen.has-thirteen-stage-self .guest-audience-image,
    .stage-screen.has-thirteen-stage-self .guest-audience-video,
    .stage-screen.has-thirteen-stage-self .guest-self-video {
      object-fit: cover;
      object-position: center center;
      background: var(--stage-fill);
    }

    .stage-screen.has-thirteen-stage-self .guest-self-tile {
      position: absolute;
      top: 0;
      left: 50%;
      right: auto;
      bottom: auto;
      width: 25%;
      height: 25%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 2px solid rgba(8, 12, 20, .8);
      box-shadow: none;
      background: var(--stage-fill);
      z-index: 1;
    }

    .stage-screen.has-thirteen-stage .guest-audience-tile:nth-child(1) { grid-column: 3; grid-row: 1; }
    .stage-screen.has-thirteen-stage .guest-audience-tile:nth-child(2) { grid-column: 4; grid-row: 1; }
    .stage-screen.has-thirteen-stage .guest-audience-tile:nth-child(3) { grid-column: 3; grid-row: 2; }
    .stage-screen.has-thirteen-stage .guest-audience-tile:nth-child(4) { grid-column: 4; grid-row: 2; }
    .stage-screen.has-thirteen-stage .guest-audience-tile:nth-child(5) { grid-column: 1; grid-row: 3; }
    .stage-screen.has-thirteen-stage .guest-audience-tile:nth-child(6) { grid-column: 2; grid-row: 3; }
    .stage-screen.has-thirteen-stage .guest-audience-tile:nth-child(7) { grid-column: 3; grid-row: 3; }
    .stage-screen.has-thirteen-stage .guest-audience-tile:nth-child(8) { grid-column: 4; grid-row: 3; }
    .stage-screen.has-thirteen-stage .guest-audience-tile:nth-child(9) { grid-column: 1; grid-row: 4; }
    .stage-screen.has-thirteen-stage .guest-audience-tile:nth-child(10) { grid-column: 2; grid-row: 4; }
    .stage-screen.has-thirteen-stage .guest-audience-tile:nth-child(11) { grid-column: 3; grid-row: 4; }
    .stage-screen.has-thirteen-stage .guest-audience-tile:nth-child(12) { grid-column: 4; grid-row: 4; }

    .stage-screen.has-thirteen-stage-self .guest-audience-tile:nth-child(1) { grid-column: 4; grid-row: 1; }
    .stage-screen.has-thirteen-stage-self .guest-audience-tile:nth-child(2) { grid-column: 3; grid-row: 2; }
    .stage-screen.has-thirteen-stage-self .guest-audience-tile:nth-child(3) { grid-column: 4; grid-row: 2; }
    .stage-screen.has-thirteen-stage-self .guest-audience-tile:nth-child(4) { grid-column: 1; grid-row: 3; }
    .stage-screen.has-thirteen-stage-self .guest-audience-tile:nth-child(5) { grid-column: 2; grid-row: 3; }
    .stage-screen.has-thirteen-stage-self .guest-audience-tile:nth-child(6) { grid-column: 3; grid-row: 3; }
    .stage-screen.has-thirteen-stage-self .guest-audience-tile:nth-child(7) { grid-column: 4; grid-row: 3; }
    .stage-screen.has-thirteen-stage-self .guest-audience-tile:nth-child(8) { grid-column: 1; grid-row: 4; }
    .stage-screen.has-thirteen-stage-self .guest-audience-tile:nth-child(9) { grid-column: 2; grid-row: 4; }
    .stage-screen.has-thirteen-stage-self .guest-audience-tile:nth-child(10) { grid-column: 3; grid-row: 4; }
    .stage-screen.has-thirteen-stage-self .guest-audience-tile:nth-child(11) { grid-column: 4; grid-row: 4; }

    .stage-screen.has-thirteen-stage .stage-top,
    .stage-screen.has-thirteen-stage .stage-center,
    .stage-screen.has-thirteen-stage .stage-note,
    .stage-screen.has-thirteen-stage-self .stage-top,
    .stage-screen.has-thirteen-stage-self .stage-center,
    .stage-screen.has-thirteen-stage-self .stage-note,
    .stage-screen.has-fourteen-stage .stage-top,
    .stage-screen.has-fourteen-stage .stage-center,
    .stage-screen.has-fourteen-stage .stage-note,
    .stage-screen.has-fourteen-stage-self .stage-top,
    .stage-screen.has-fourteen-stage-self .stage-center,
    .stage-screen.has-fourteen-stage-self .stage-note,
    .stage-screen.has-fifteen-stage .stage-top,
    .stage-screen.has-fifteen-stage .stage-center,
    .stage-screen.has-fifteen-stage .stage-note,
    .stage-screen.has-fifteen-stage-self .stage-top,
    .stage-screen.has-fifteen-stage-self .stage-center,
    .stage-screen.has-fifteen-stage-self .stage-note,
    .stage-screen.has-sixteen-stage .stage-top,
    .stage-screen.has-sixteen-stage .stage-center,
    .stage-screen.has-sixteen-stage .stage-note,
    .stage-screen.has-sixteen-stage-self .stage-top,
    .stage-screen.has-sixteen-stage-self .stage-center,
    .stage-screen.has-sixteen-stage-self .stage-note,
    .stage-screen.has-seventeen-stage .stage-top,
    .stage-screen.has-seventeen-stage .stage-center,
    .stage-screen.has-seventeen-stage .stage-note,
    .stage-screen.has-seventeen-stage-self .stage-top,
    .stage-screen.has-seventeen-stage-self .stage-center,
    .stage-screen.has-seventeen-stage-self .stage-note,
    .stage-screen.has-eighteen-stage .stage-top,
    .stage-screen.has-eighteen-stage .stage-center,
    .stage-screen.has-eighteen-stage .stage-note,
    .stage-screen.has-eighteen-stage-self .stage-top,
    .stage-screen.has-eighteen-stage-self .stage-center,
    .stage-screen.has-eighteen-stage-self .stage-note,
    .stage-screen.has-nineteen-stage .stage-top,
    .stage-screen.has-nineteen-stage .stage-center,
    .stage-screen.has-nineteen-stage .stage-note,
    .stage-screen.has-nineteen-stage-self .stage-top,
    .stage-screen.has-nineteen-stage-self .stage-center,
    .stage-screen.has-nineteen-stage-self .stage-note,
    .stage-screen.has-twenty-stage .stage-top,
    .stage-screen.has-twenty-stage .stage-center,
    .stage-screen.has-twenty-stage .stage-note,
    .stage-screen.has-twenty-stage-self .stage-top,
    .stage-screen.has-twenty-stage-self .stage-center,
    .stage-screen.has-twenty-stage-self .stage-note,
    .stage-screen.has-twentyone-stage .stage-top,
    .stage-screen.has-twentyone-stage .stage-center,
    .stage-screen.has-twentyone-stage .stage-note,
    .stage-screen.has-twentyone-stage-self .stage-top,
    .stage-screen.has-twentyone-stage-self .stage-center,
    .stage-screen.has-twentyone-stage-self .stage-note,
    .stage-screen.has-twentytwo-stage .stage-top,
    .stage-screen.has-twentytwo-stage .stage-center,
    .stage-screen.has-twentytwo-stage .stage-note,
    .stage-screen.has-twentytwo-stage-self .stage-top,
    .stage-screen.has-twentytwo-stage-self .stage-center,
    .stage-screen.has-twentytwo-stage-self .stage-note,
    .stage-screen.has-twentythree-stage .stage-top,
    .stage-screen.has-twentythree-stage .stage-center,
    .stage-screen.has-twentythree-stage .stage-note,
    .stage-screen.has-twentythree-stage-self .stage-top,
    .stage-screen.has-twentythree-stage-self .stage-center,
    .stage-screen.has-twentythree-stage-self .stage-note,
    .stage-screen.has-twentyfour-stage .stage-top,
    .stage-screen.has-twentyfour-stage .stage-center,
    .stage-screen.has-twentyfour-stage .stage-note,
    .stage-screen.has-twentyfour-stage-self .stage-top,
    .stage-screen.has-twentyfour-stage-self .stage-center,
    .stage-screen.has-twentyfour-stage-self .stage-note,
    .stage-screen.has-twentyfive-stage .stage-top,
    .stage-screen.has-twentyfive-stage .stage-center,
    .stage-screen.has-twentyfive-stage .stage-note,
    .stage-screen.has-twentyfive-stage-self .stage-top,
    .stage-screen.has-twentyfive-stage-self .stage-center,
    .stage-screen.has-twentyfive-stage-self .stage-note,
    .stage-screen.has-twentyplus-stage .stage-top,
    .stage-screen.has-twentyplus-stage .stage-center,
    .stage-screen.has-twentyplus-stage .stage-note,
    .stage-screen.has-twentyplus-stage-self .stage-top,
    .stage-screen.has-twentyplus-stage-self .stage-center,
    .stage-screen.has-twentyplus-stage-self .stage-note,
    .stage-screen.has-gallery-stage .stage-top,
    .stage-screen.has-gallery-stage .stage-center,
    .stage-screen.has-gallery-stage .stage-note,
    .stage-screen.has-host-layout .stage-top {
      display: none;
    }
    .stage-screen.has-snapshot .stage-image {
      display: block;
    }
    .stage-screen.has-webrtc .stage-video {
      display: block;
    }
    .stage-screen.has-webrtc .stage-image {
      display: none;
    }
    .stage-screen.has-snapshot .stage-top,
    .stage-screen.has-snapshot .stage-center,
    .stage-screen.has-snapshot .stage-note,
    .stage-screen.has-webrtc .stage-top,
    .stage-screen.has-webrtc .stage-center,
    .stage-screen.has-webrtc .stage-note {
      position: relative;
      z-index: 2;
    }
    .stage-screen.has-snapshot::after {
      content: "";
      position: absolute;
      inset: 0;
      background: linear-gradient(180deg, rgba(5, 8, 16, 0.22) 0%, rgba(5, 8, 16, 0.10) 34%, rgba(5, 8, 16, 0.42) 100%);
      z-index: 1;
    }
    .stage-top {
      position: absolute;
      top: 18px;
      left: 50%;
      z-index: 5;
      transform: translateX(-50%);
      display: inline-flex;
      align-items: center;
      gap: 10px;
      padding: 7px 14px 7px 8px;
      border-radius: 999px;
      background: rgba(18, 20, 27, .54);
      backdrop-filter: blur(10px);
      box-shadow: 0 12px 30px rgba(0,0,0,.18);
    }
    .stage-pill {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 0;
      border-radius: 0;
      background: transparent;
      border: 0;
      font-weight: 700;
    }
    .stage-pill .dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: #ff7f67;
    }
    .stage-center,
    .stage-note {
      display: none;
    }
    .watch-side-head {
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto;
      align-items: start;
      gap: 14px;
      padding: 26px 28px 18px;
      border-bottom: 1px solid rgba(255,255,255,.08);
      background: #050505;
    }
    .watch-side-title {
      min-width: 0;
      display:flex;
      align-items:flex-end;
      gap:10px;
      font-size: 19px;
      font-weight: 900;
      line-height: 1.1;
      color: #f8fafc;
    }
    .watch-side-title strong {
      display: block;
      color: #f8fafc;
      font-size: 22px;
      font-weight: 900;
      letter-spacing: -.02em;
    }
    .watch-side-title span {
      color: rgba(255,255,255,.38);
      font-size: 18px;
      font-weight: 800;
    }
    .watch-reaction-panel {
      display: none;
      flex-direction: column;
      min-height: 0;
      flex: 1;
      padding: 4px 10px 18px 0;
    }
    .watch-description-panel {
      display: none;
      flex-direction: column;
      min-height: 0;
      flex: 1;
      background: #252a30;
    }
    .watch-description-card {
      min-height: 0;
      height: 100%;
      display: grid;
      grid-template-rows: auto auto minmax(0, 1fr);
      background: #252a30;
    }
    .watch-description-head {
      display: grid;
      grid-template-columns: auto minmax(0, 1fr);
      align-items: center;
      gap: 16px;
      padding: 20px 88px 18px 18px;
    }
    .watch-description-avatar {
      width: 58px;
      height: 58px;
      border-radius: 50%;
      display: grid;
      place-items: center;
      font-size: 22px;
      font-weight: 900;
      color: #f8fafc;
      background: radial-gradient(circle at 30% 30%, #44d1c3 0%, #1e8b98 45%, #103848 100%);
    }
    .watch-description-meta {
      min-width: 0;
    }
    .watch-description-title {
      margin: 0;
      font-size: 27px;
      line-height: 1.08;
      font-weight: 900;
      color: #f8fafc;
      letter-spacing: -.03em;
      word-break: break-word;
    }
    .watch-description-sub {
      margin-top: 6px;
      font-size: 14px;
      line-height: 1.35;
      color: rgba(255,255,255,.38);
      word-break: break-word;
    }
    .watch-description-divider {
      height: 1px;
      background: rgba(255,255,255,.06);
    }
    .watch-description-scroll {
      min-height: 0;
      overflow-y: auto;
      padding: 18px 18px 28px;
    }
    .watch-description-body {
      margin: 0;
      font-size: 17px;
      line-height: 1.62;
      color: rgba(255,255,255,.92);
      white-space: pre-wrap;
      word-break: break-word;
    }
    .watch-reaction-tabs {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      padding: 4px 0 16px;
      border-bottom: 1px solid rgba(255,255,255,.08);
      margin-bottom: 16px;
    }
    .watch-reaction-tab {
      border: 0;
      background: transparent;
      color: rgba(255,255,255,.7);
      font-size: 14px;
      font-weight: 800;
      cursor: pointer;
      padding: 0 0 8px;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      border-bottom: 3px solid transparent;
    }
    .watch-reaction-tab.is-active {
      color: #3b82f6;
      border-bottom-color: #3b82f6;
    }
    .watch-reaction-list {
      min-height: 0;
      overflow-y: auto;
      display: grid;
      gap: 16px;
      padding-right: 4px;
    }
    .watch-reaction-item {
      display: grid;
      grid-template-columns: 56px minmax(0,1fr) auto;
      gap: 14px;
      align-items: center;
    }
    .watch-reaction-avatar {
      width: 56px;
      height: 56px;
      border-radius: 999px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #fff;
      font-size: 20px;
      font-weight: 900;
      position: relative;
      box-shadow: 0 10px 30px rgba(0,0,0,.35);
    }
    .watch-reaction-badge {
      position: absolute;
      right: -2px;
      bottom: -2px;
      width: 24px;
      height: 24px;
      border-radius: 999px;
      background: #11131a;
      display: grid;
      place-items: center;
      font-size: 16px;
    }
    .watch-reaction-main {
      min-width: 0;
    }
    .watch-reaction-name {
      color: #f8fafc;
      font-size: 17px;
      font-weight: 800;
      line-height: 1.2;
      word-break: break-word;
    }
    .watch-reaction-time {
      margin-top: 4px;
      color: rgba(255,255,255,.46);
      font-size: 13px;
      font-weight: 700;
    }
    .watch-reaction-time .watch-reaction-type {
      color: rgba(255,255,255,.74);
      margin-right: 8px;
    }
    .watch-reaction-action {
      border: 0;
      border-radius: 14px;
      min-width: 144px;
      height: 54px;
      padding: 0 18px;
      background: rgba(255,255,255,.14);
      color: #f3f4f6;
      font-size: 15px;
      font-weight: 800;
      cursor: pointer;
    }
    .watch-reaction-action[disabled] {
      opacity: .72;
      cursor: default;
    }
    .watch-reaction-action.is-static {
      background: rgba(255,255,255,.08);
      color: rgba(255,255,255,.76);
    }
    .watch-reaction-empty {
      color: rgba(255,255,255,.56);
      font-size: 14px;
      font-weight: 700;
      padding: 8px 0;
    }
    .watch-side-stats {
      display: none;
    }
    .watch-side-stat {
      padding: 16px 8px 14px;
      text-align: center;
      font-size: 13px;
      font-weight: 800;
      color: rgba(218, 224, 235, .8);
    }
    .watch-side-stat strong {
      color: rgba(255,255,255,.92);
      font-size: 15px;
      margin-right: 4px;
    }
    .watch-side-scroll {
      flex: 1 1 auto;
      min-height: 0;
      padding: 14px 18px 0 28px;
      display: flex;
      flex-direction: column;
      background: #050505;
      overflow: hidden;
    }
    .panel {
      margin: 0 0 14px;
      border-radius: 18px;
      padding: 16px;
      border: 1px solid #edf1f6;
      background: #fff;
      min-height: 0;
    }
    .panel h3 {
      margin: 0 0 8px;
      font-size: 16px;
      color: #111827;
    }
    .panel p {
      margin: 0;
      color: #667085;
      line-height: 1.6;
    }
    .panel.light {
      background: rgba(255,255,255,.96);
      border-color: #d9e2f1;
      color: #1a2130;
      box-shadow: 0 12px 28px rgba(0,0,0,.12);
    }
    .panel.light h3 {
      color: #1a2130;
    }
    .panel.light p,
    .panel.light .join-request-copy {
      color: #67758f;
    }
    .meta-stack {
      display: grid;
      gap: 0;
      margin-bottom: 0;
      min-height: 0;
      flex: 1;
      overflow: hidden;
    }
    .stat-row {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin: 12px 0 0;
    }
    .stat {
      padding: 6px 10px;
      border-radius: 999px;
      background: rgba(255,255,255,.08);
      color: rgba(255,255,255,.9);
      font-size: 12px;
      font-weight: 700;
    }
    .reaction-row {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin-top: 14px;
    }
    .reaction-btn {
      border: 1px solid #dce5f2;
      background: #f8fafc;
      border-radius: 999px;
      padding: 7px 10px;
      font-size: 11px;
      font-weight: 700;
      color: #344054;
      cursor: pointer;
    }
    .reaction-btn .reaction-love-heart {
      color:#ec4899;
      margin-right:4px;
      line-height:1;
    }
    .reaction-btn.active {
      background: #254d89;
      border-color: #254d89;
      color: #fff;
    }
    .reaction-btn.active .reaction-love-heart {
      color:#ff8fd0;
    }
    .chat-panel {
      display: flex;
      flex-direction: column;
      min-height: 0;
      flex: 1;
      margin-bottom: 0;
      overflow: hidden;
      border: 0;
      padding: 0;
      background: transparent;
    }
    .meta-stack > .panel:not(.light):not(.chat-panel) {
      display: none;
    }
    .comment-list {
      display: grid;
      gap: 30px;
      min-height: 0;
      flex: 1;
      overflow-y: auto;
      margin-top: 0;
      padding: 4px 14px 24px 0;
    }
    .comment {
      width: 100%;
      justify-self: stretch;
      border: 0;
      border-radius: 0;
      padding: 0;
      background: transparent;
      display:grid;
      grid-template-columns: 52px minmax(0, 1fr);
      gap: 14px;
      align-items: start;
    }
    .comment-avatar {
      width: 52px;
      height: 52px;
      border-radius: 999px;
      display:flex;
      align-items:center;
      justify-content:center;
      background: linear-gradient(135deg, rgba(236,72,153,.92), rgba(249,115,22,.92));
      color:#fff;
      font-size: 18px;
      font-weight: 900;
      box-shadow: 0 10px 30px rgba(0,0,0,.35);
    }
    .comment-main {
      min-width: 0;
    }
    .comment-author {
      display:flex;
      align-items:center;
      gap:8px;
      font-size: 15px;
      font-weight: 800;
      color: rgba(255,255,255,.62);
      margin-bottom: 8px;
    }
    .comment-author .is-self {
      color: #60a5fa;
      font-size: 12px;
      font-weight: 800;
    }
    .comment-body {
      font-size: 21px;
      color: #f8fafc;
      line-height: 1.42;
      letter-spacing: -.02em;
      word-break: break-word;
    }
    .comment-meta {
      display:flex;
      align-items:center;
      gap:18px;
      margin-top: 12px;
      color: rgba(255,255,255,.46);
      font-size: 14px;
      font-weight: 700;
    }
    .comment-reply {
      border:0;
      background:transparent;
      color: rgba(255,255,255,.64);
      padding:0;
      font: inherit;
      font-weight: 800;
      cursor:pointer;
    }
    .comment-like {
      margin-left:auto;
      display:inline-flex;
      align-items:center;
      gap:8px;
      border:0;
      background:transparent;
      padding:0;
      font:inherit;
      appearance:none;
      color: rgba(255,255,255,.64);
      cursor:pointer;
    }
    .comment-like i {
      font-size: 18px;
    }
    .comment-like-count {
      font-size: 13px;
      font-weight: 800;
      line-height: 1;
    }
    .comment-like.is-liked {
      color:#ec4899;
    }
    .comment-like.is-liked i::before {
      content:"\f004";
    }
    .comment-likes {
      margin-top: 8px;
      color: rgba(255,255,255,.46);
      font-size: 13px;
      font-weight: 700;
      line-height: 1.4;
    }
    .comment-form {
      margin-top: 10px;
      display: grid;
      gap: 6px;
      grid-template-rows: auto auto;
    }
    .comment-form textarea {
      width: 100%;
      min-height: 40px;
      max-height: 96px;
      border: 0;
      border-radius: 0;
      padding: 0;
      resize: none;
      font-size: 14px;
      background: transparent;
      color: #111827;
    }
    .btn-row {
      display: flex;
      justify-content: space-between;
      gap: 8px;
      flex-wrap: wrap;
      align-items: center;
    }
    .comment-form .btn-row {
      flex-wrap: nowrap;
    }
    .comment-form .feedback {
      flex: 1 1 auto;
      min-width: 0;
    }
    .comment-form .btn {
      flex: 0 0 auto;
    }
    .btn {
      border: 0;
      border-radius: 12px;
      padding: 12px 24px;
      cursor: pointer;
      font-weight: 900;
      color: #fff;
      background: linear-gradient(180deg, #2e7be7 0%, #2b6fd0 100%);
      min-width: 118px;
    }
    .feedback {
      min-height: 18px;
      font-size: 12px;
      color: #7b8190;
      margin-top: 0;
    }
    .feedback.error { color: #c14643; }
    .feedback.success { color: #2f7a4f; }
    .watch-compose {
      padding: 12px 20px 22px;
      border-top: 1px solid rgba(255,255,255,.08);
      background: #050505;
      position: relative;
      z-index: 2;
      flex-shrink: 0;
      margin-bottom: 0;
    }
    .watch-compose h4,
    .watch-chat-tabs {
      display: none;
    }
    .watch-compose-shell {
      display:grid;
      grid-template-columns: minmax(0, 1fr) 52px;
      gap:12px;
      align-items:end;
    }
    .watch-send-btn {
      width:52px;
      height:52px;
      border-radius:999px;
      border:0;
      display:flex;
      align-items:center;
      justify-content:center;
      cursor:pointer;
      font-size:28px;
      box-shadow: 0 10px 28px rgba(0,0,0,.32);
    }
    .watch-send-btn {
      background: linear-gradient(180deg, #7f1d1d 0%, #991b1b 100%);
      color:#fda4af;
    }
    .watch-compose-inputwrap {
      display:flex;
      align-items:center;
      gap:12px;
      min-height:52px;
      padding: 0 14px 0 18px;
      border-radius:999px;
      background: #202020;
      border: 1px solid rgba(255,255,255,.06);
    }
    .watch-compose textarea {
      flex:1;
      min-width:0;
      min-height: 32px;
      max-height: 96px;
      padding: 4px 0 0;
      border:0;
      background:transparent;
      color:#f8fafc;
      resize:none;
      font-size: 18px;
      line-height:24px;
      align-self:center;
    }
    .watch-compose textarea::placeholder {
      color: rgba(255,255,255,.34);
    }
    .watch-compose-tool {
      border:0;
      background:transparent;
      color: rgba(255,255,255,.88);
      width:32px;
      height:32px;
      display:grid;
      place-items:center;
      font-size:20px;
      cursor:pointer;
      padding:0;
    }
    .watch-compose .feedback {
      margin-top:10px;
      min-height: 18px;
      color: rgba(255,255,255,.54);
    }
    .join-request-copy .accent {
      color: #dc5c53;
    }
    .join-request-status {
      margin-top: 12px;
      font-size: 13px;
      line-height: 1.6;
    }
    .watch-bottom {
      position: relative;
      z-index: 6;
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      gap: 10px 12px;
      padding: 0 16px;
      background: #171822;
      border-top: 1px solid rgba(255,255,255,.08);
      color: #fff;
      box-shadow: 0 -10px 24px rgba(0,0,0,.18);
    }
    .watch-controls,
    .watch-controls-right {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
    }
    .watch-control {
      border: 0;
      background: rgba(255,255,255,.04);
      color: #d9deea;
      display: grid;
      justify-items: center;
      gap: 3px;
      font-weight: 700;
      cursor: pointer;
      min-width: 62px;
      height: 48px;
      padding: 6px 10px;
      border-radius: 10px;
    }
    .watch-control.is-active {
      background: rgba(255,255,255,.12);
      color: #fff;
    }
    .watch-control i {
      position: relative;
      display: inline-block;
      font-size: 16px;
    }
    .watch-control i.has-off-slash::after {
      content: '';
      position: absolute;
      top: -2px;
      left: 50%;
      width: 3px;
      height: 22px;
      border-radius: 999px;
      background: #ff5c5c;
      transform: translateX(-50%) rotate(42deg);
      box-shadow: 0 0 0 1px rgba(12, 16, 24, 0.2);
    }
    .watch-control-label {
      font-size: 11px;
      font-weight: 700;
      line-height: 1.1;
      text-align: center;
    }
    .watch-control-count {
      display: inline;
      margin-top: 0;
      font-size: 11px;
      color: rgba(255,255,255,.82);
    }
    .stage-screen.is-local-video-off .stage-video {
      opacity: 0;
      visibility: hidden;
      pointer-events: none;
    }
    .stage-screen.is-local-video-off .stage-image,
    .stage-screen.is-local-video-off .guest-audience-layer,
    .stage-screen.is-local-video-off .stage-top,
    .stage-screen.is-local-video-off .stage-center,
    .stage-screen.is-local-video-off .stage-note,
    .stage-screen.is-local-video-off .watch-stage-reactions {
      opacity: 0;
      visibility: hidden;
      pointer-events: none;
    }
    .watch-camera-off-stage {
      position: absolute;
      inset: 0;
      z-index: 8;
      display: none;
      align-items: center;
      justify-content: center;
      background: #000;
      pointer-events: none;
      overflow: hidden;
    }
    .stage-screen.is-local-video-off .watch-camera-off-stage {
      display: flex;
    }
    .stage-screen.is-host-camera-off .watch-camera-off-stage {
      display: flex;
      z-index: 2;
    }
    .stage-screen.is-host-camera-off .stage-video,
    .stage-screen.is-host-camera-off .stage-image,
    .stage-screen.is-host-camera-off .stage-top,
    .stage-screen.is-host-camera-off .stage-center,
    .stage-screen.is-host-camera-off .stage-note,
    .stage-screen.is-host-camera-off .watch-stage-reactions {
      opacity: 0;
      visibility: hidden;
      pointer-events: none;
    }
    .watch-camera-off-stage::before {
      content: '';
      position: absolute;
      left: 50%;
      top: 50%;
      width: min(78%, 240px);
      height: 3px;
      border-radius: 999px;
      background: #ff4d3b;
      transform: translate(-50%, -50%) rotate(-28deg);
      box-shadow: 0 0 0 1px rgba(255, 77, 59, 0.12);
    }
    .watch-camera-off-icon {
      position: relative;
      width: 68px;
      height: 52px;
      color: #f2f5fb;
      display: grid;
      place-items: center;
      filter: drop-shadow(0 6px 14px rgba(0, 0, 0, 0.42));
    }
    .watch-camera-off-icon .fa {
      font-size: 40px;
      line-height: 1;
    }

    .stage-screen.is-host-camera-off .watch-camera-off-stage,
    .stage-screen.is-local-video-off .watch-camera-off-stage {
      border-radius: 0;
      border: 1px solid rgba(255,255,255,.12);
      box-shadow: none;
    }

    .stage-screen.has-dual-stage .watch-camera-off-stage,
    .stage-screen.has-three-stage .watch-camera-off-stage,
    .stage-screen.has-four-stage .watch-camera-off-stage,
    .stage-screen.has-four-stage-self .watch-camera-off-stage,
    .stage-screen.has-five-stage .watch-camera-off-stage,
    .stage-screen.has-five-stage-self .watch-camera-off-stage,
    .stage-screen.has-six-stage .watch-camera-off-stage,
    .stage-screen.has-six-stage-self .watch-camera-off-stage,
    .stage-screen.has-seven-stage .watch-camera-off-stage,
    .stage-screen.has-seven-stage-self .watch-camera-off-stage,
    .stage-screen.has-eight-stage .watch-camera-off-stage,
    .stage-screen.has-eight-stage-self .watch-camera-off-stage,
    .stage-screen.has-nine-stage .watch-camera-off-stage,
    .stage-screen.has-nine-stage-self .watch-camera-off-stage,
    .stage-screen.has-ten-stage .watch-camera-off-stage,
    .stage-screen.has-ten-stage-self .watch-camera-off-stage,
    .stage-screen.has-eleven-stage .watch-camera-off-stage,
    .stage-screen.has-eleven-stage-self .watch-camera-off-stage,
    .stage-screen.has-twelve-stage .watch-camera-off-stage,
    .stage-screen.has-twelve-stage-self .watch-camera-off-stage,
    .stage-screen.has-thirteen-stage .watch-camera-off-stage,
    .stage-screen.has-thirteen-stage-self .watch-camera-off-stage,
    .stage-screen.has-fourteen-stage .watch-camera-off-stage,
    .stage-screen.has-fourteen-stage-self .watch-camera-off-stage,
    .stage-screen.has-fifteen-stage .watch-camera-off-stage,
    .stage-screen.has-fifteen-stage-self .watch-camera-off-stage,
    .stage-screen.has-sixteen-stage .watch-camera-off-stage,
    .stage-screen.has-sixteen-stage-self .watch-camera-off-stage,
    .stage-screen.has-seventeen-stage .watch-camera-off-stage,
    .stage-screen.has-seventeen-stage-self .watch-camera-off-stage,
    .stage-screen.has-eighteen-stage .watch-camera-off-stage,
    .stage-screen.has-eighteen-stage-self .watch-camera-off-stage,
    .stage-screen.has-nineteen-stage .watch-camera-off-stage,
    .stage-screen.has-nineteen-stage-self .watch-camera-off-stage,
    .stage-screen.has-twenty-stage .watch-camera-off-stage,
    .stage-screen.has-twenty-stage-self .watch-camera-off-stage,
    .stage-screen.has-twentyone-stage .watch-camera-off-stage,
    .stage-screen.has-twentyone-stage-self .watch-camera-off-stage,
    .stage-screen.has-twentytwo-stage .watch-camera-off-stage,
    .stage-screen.has-twentytwo-stage-self .watch-camera-off-stage,
    .stage-screen.has-twentythree-stage .watch-camera-off-stage,
    .stage-screen.has-twentythree-stage-self .watch-camera-off-stage,
    .stage-screen.has-twentyfour-stage .watch-camera-off-stage,
    .stage-screen.has-twentyfour-stage-self .watch-camera-off-stage,
    .stage-screen.has-twentyfive-stage .watch-camera-off-stage,
    .stage-screen.has-twentyfive-stage-self .watch-camera-off-stage,
    .stage-screen.has-gallery-stage .watch-camera-off-stage {
      inset: 14px auto 14px 14px;
      width: calc(50% - 21px);
      height: calc(100% - 28px);
    }

    .stage-screen.has-dual-stage .watch-camera-off-stage,
    .stage-screen.has-three-stage .watch-camera-off-stage,
    .stage-screen.has-four-stage .watch-camera-off-stage,
    .stage-screen.has-four-stage-self .watch-camera-off-stage,
    .stage-screen.has-five-stage .watch-camera-off-stage,
    .stage-screen.has-five-stage-self .watch-camera-off-stage,
    .stage-screen.has-six-stage .watch-camera-off-stage,
    .stage-screen.has-six-stage-self .watch-camera-off-stage,
    .stage-screen.has-seven-stage .watch-camera-off-stage,
    .stage-screen.has-seven-stage-self .watch-camera-off-stage {
      inset: 0 auto 0 0;
      width: calc(50% - 1px);
      height: 100%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
    }
    .guest-self-tile.is-local-video-off .guest-self-video {
      opacity: 0;
      visibility: hidden;
      pointer-events: none;
    }
    .guest-self-camera-off-stage {
      position: absolute;
      inset: 0;
      z-index: 2;
      display: none;
      align-items: center;
      justify-content: center;
      background: #000;
      pointer-events: none;
    }
    .stage-screen.has-three-stage-self .guest-self-camera-off-stage,
    .stage-screen.has-four-stage-self .guest-self-camera-off-stage,
    .stage-screen.has-five-stage-self .guest-self-camera-off-stage,
    .stage-screen.has-six-stage-self .guest-self-camera-off-stage,
    .stage-screen.has-seven-stage-self .guest-self-camera-off-stage,
    .stage-screen.has-eight-stage-self .guest-self-camera-off-stage,
    .stage-screen.has-nine-stage-self .guest-self-camera-off-stage,
    .stage-screen.has-ten-stage-self .guest-self-camera-off-stage,
    .stage-screen.has-eleven-stage-self .guest-self-camera-off-stage,
    .stage-screen.has-twelve-stage-self .guest-self-camera-off-stage,
    .stage-screen.has-thirteen-stage-self .guest-self-camera-off-stage,
    .stage-screen.has-fourteen-stage-self .guest-self-camera-off-stage,
    .stage-screen.has-fifteen-stage-self .guest-self-camera-off-stage,
    .stage-screen.has-sixteen-stage-self .guest-self-camera-off-stage,
    .stage-screen.has-seventeen-stage-self .guest-self-camera-off-stage,
    .stage-screen.has-eighteen-stage-self .guest-self-camera-off-stage,
    .stage-screen.has-nineteen-stage-self .guest-self-camera-off-stage,
    .stage-screen.has-twenty-stage-self .guest-self-camera-off-stage,
    .stage-screen.has-twentyone-stage-self .guest-self-camera-off-stage,
    .stage-screen.has-twentytwo-stage-self .guest-self-camera-off-stage,
    .stage-screen.has-twentythree-stage-self .guest-self-camera-off-stage,
    .stage-screen.has-twentyfour-stage-self .guest-self-camera-off-stage,
    .stage-screen.has-twentyfive-stage-self .guest-self-camera-off-stage,
    .stage-screen.has-host-layout-self .guest-self-camera-off-stage {
      inset: 0;
      height: 100%;
    }
    .guest-self-tile.is-local-video-off .guest-self-camera-off-stage {
      display: flex;
    }
    .guest-self-camera-off-stage::before {
      content: '';
      position: absolute;
      left: 50%;
      top: 50%;
      width: min(78%, 240px);
      height: 3px;
      border-radius: 999px;
      background: #ff4d3b;
      transform: translate(-50%, -50%) rotate(-28deg);
      box-shadow: 0 0 0 1px rgba(255, 77, 59, 0.12);
    }
    .guest-self-camera-off-icon {
      position: relative;
      width: 68px;
      height: 52px;
      color: #f2f5fb;
      display: grid;
      place-items: center;
      filter: drop-shadow(0 6px 14px rgba(0, 0, 0, 0.42));
    }
    .guest-self-camera-off-icon .fa {
      font-size: 40px;
      line-height: 1;
    }
    .watch-end {
      color: #fff;
      min-width: 132px;
      height: 44px;
      padding: 0 18px;
      border-radius: 12px;
      background: #6f3c2f;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
    }
    .watch-settings-panel {
      display: none;
      min-height: 100%;
      background: #252a30;
    }
    .watch-settings-body {
      display: grid;
      gap: 14px;
      padding: 16px 18px 18px;
    }
    .watch-settings-item {
      display: grid;
      gap: 6px;
    }
    .watch-settings-item label,
    .watch-settings-toggle {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      color: #f8fafc;
      font-size: 14px;
      font-weight: 700;
    }
    .watch-settings-item span.note {
      color: rgba(217, 222, 234, .78);
      font-size: 12px;
      line-height: 1.45;
    }
    .watch-settings-item select {
      width: 100%;
      border: 1px solid rgba(255,255,255,.12);
      border-radius: 12px;
      background: rgba(255,255,255,.06);
      color: #fff;
      padding: 10px 12px;
      font-size: 14px;
      font-weight: 700;
      outline: none;
    }
    .watch-settings-item input[type="checkbox"] {
      width: 18px;
      height: 18px;
      accent-color: #4c95ff;
      flex: 0 0 auto;
    }
    .stage-video.is-mirrored,
    .guest-self-video.is-mirrored {
      transform: scaleX(-1);
    }
    .watch-end .watch-control-label {
      color: #fff;
    }
    .error-box {
      padding: 60px 24px;
      text-align: center;
    }
    @media (max-width: 980px) {
      .watch-frame {
        border-radius: 0;
        border: 0;
      }
      .watch-grid,
      .watch-frame.has-chat .watch-grid { grid-template-columns: 1fr; }
      .watch-sidebar { border-left: 0; border-top: 1px solid #4b5564; }
      .stage-screen { min-height: 380px; }
      .watch-bottom {
        padding: 18px 20px 20px;
        flex-direction: column;
        align-items: stretch;
        gap: 18px;
      }
      .watch-side-head { padding: 20px 18px 14px; }
      .watch-side-scroll { padding: 10px 12px 0 18px; }
      .comment { grid-template-columns: 42px minmax(0, 1fr); gap: 12px; }
      .comment-avatar { width:42px; height:42px; font-size:14px; }
      .comment-body { font-size: 17px; }
      .watch-compose { margin-bottom: 0; padding: 10px 12px 16px; }
      .watch-compose-shell { grid-template-columns: minmax(0, 1fr) 46px; gap:10px; }
      .watch-send-btn { width:46px; height:46px; font-size:24px; }
      .watch-compose-inputwrap { min-height:46px; }
      .watch-compose textarea { min-height:46px; font-size:16px; }
      .watch-controls,
      .watch-controls-right {
        justify-content: space-between;
        gap: 16px;
        flex-wrap: wrap;
      }
      .watch-sidebar,
      .watch-frame.has-chat .watch-sidebar { display: grid; }
    }

    body.embed-mode {
      background: #000;
    }

    body.embed-mode .watch-shell {
      min-height: 100vh;
      padding: 0;
    }

    body.embed-mode .watch-frame {
      max-width: none;
      min-height: 100vh;
      height: 100vh;
      border: 0;
      border-radius: 0;
      box-shadow: none;
      background: #000;
      display: block;
    }

    body.embed-mode .watch-head,
    body.embed-mode .watch-sidebar,
    body.embed-mode .watch-bottom,
    body.embed-mode .stage-top,
    body.embed-mode .stage-center,
    body.embed-mode .stage-note {
      display: none;
    }

    body.embed-mode .watch-grid {
      display: block;
      height: 100vh;
      min-height: 100vh;
    }

    body.embed-mode .watch-stage {
      padding: 0;
      height: 100vh;
      min-height: 100vh;
    }

    body.embed-mode .stage-screen {
      min-height: 100vh;
      height: 100vh;
      border-radius: 0;
      padding: 0;
      background: #000;
    }

    @media (max-aspect-ratio: 16/9) {
      body.embed-mode .stage-image,
      body.embed-mode .stage-video {
        object-fit: contain;
        background: #000;
      }
    }

    body.embed-mode .stage-pill,
    body.embed-mode .guest-self-meta,
    body.embed-mode .guest-audience-meta {
      display: none;
    }

    body.header-modal-mode.embed-mode .stage-screen.has-three-stage.is-host-camera-off .watch-camera-off-stage,
    body.header-modal-mode.embed-mode .stage-screen.has-three-stage-self.is-host-camera-off .watch-camera-off-stage,
    body.header-modal-mode.embed-mode .stage-screen.has-four-stage.is-host-camera-off .watch-camera-off-stage,
    body.header-modal-mode.embed-mode .stage-screen.has-four-stage-self.is-host-camera-off .watch-camera-off-stage,
    body.header-modal-mode.embed-mode .stage-screen.has-five-stage.is-host-camera-off .watch-camera-off-stage,
    body.header-modal-mode.embed-mode .stage-screen.has-five-stage-self.is-host-camera-off .watch-camera-off-stage,
    body.header-modal-mode.embed-mode .stage-screen.has-six-stage.is-host-camera-off .watch-camera-off-stage,
    body.header-modal-mode.embed-mode .stage-screen.has-six-stage-self.is-host-camera-off .watch-camera-off-stage,
    body.header-modal-mode.embed-mode .stage-screen.has-seven-stage.is-host-camera-off .watch-camera-off-stage,
    body.header-modal-mode.embed-mode .stage-screen.has-seven-stage-self.is-host-camera-off .watch-camera-off-stage,
    body.header-modal-mode.embed-mode .stage-screen.has-eight-stage.is-host-camera-off .watch-camera-off-stage,
    body.header-modal-mode.embed-mode .stage-screen.has-eight-stage-self.is-host-camera-off .watch-camera-off-stage,
    body.header-modal-mode.embed-mode .stage-screen.has-nine-stage.is-host-camera-off .watch-camera-off-stage,
    body.header-modal-mode.embed-mode .stage-screen.has-nine-stage-self.is-host-camera-off .watch-camera-off-stage,
    body.header-modal-mode.embed-mode .stage-screen.has-ten-stage.is-host-camera-off .watch-camera-off-stage,
    body.header-modal-mode.embed-mode .stage-screen.has-ten-stage-self.is-host-camera-off .watch-camera-off-stage,
    body.header-modal-mode.embed-mode .stage-screen.has-eleven-stage.is-host-camera-off .watch-camera-off-stage,
    body.header-modal-mode.embed-mode .stage-screen.has-eleven-stage-self.is-host-camera-off .watch-camera-off-stage,
    body.header-modal-mode.embed-mode .stage-screen.has-twelve-stage.is-host-camera-off .watch-camera-off-stage,
    body.header-modal-mode.embed-mode .stage-screen.has-twelve-stage-self.is-host-camera-off .watch-camera-off-stage,
    body.header-modal-mode.embed-mode .stage-screen.has-thirteen-stage.is-host-camera-off .watch-camera-off-stage,
    body.header-modal-mode.embed-mode .stage-screen.has-thirteen-stage-self.is-host-camera-off .watch-camera-off-stage {
      inset: 0 auto 0 0;
      width: calc(50% - 1px);
      height: 100%;
      border: 0;
      border-right: 2px solid rgba(63, 94, 155, 0.72);
      background: #000;
      box-shadow: none;
    }

    body.header-modal-mode.embed-mode .stage-screen.has-three-stage.is-host-camera-off .watch-camera-off-stage::before,
    body.header-modal-mode.embed-mode .stage-screen.has-three-stage-self.is-host-camera-off .watch-camera-off-stage::before,
    body.header-modal-mode.embed-mode .stage-screen.has-four-stage.is-host-camera-off .watch-camera-off-stage::before,
    body.header-modal-mode.embed-mode .stage-screen.has-four-stage-self.is-host-camera-off .watch-camera-off-stage::before,
    body.header-modal-mode.embed-mode .stage-screen.has-five-stage.is-host-camera-off .watch-camera-off-stage::before,
    body.header-modal-mode.embed-mode .stage-screen.has-five-stage-self.is-host-camera-off .watch-camera-off-stage::before,
    body.header-modal-mode.embed-mode .stage-screen.has-six-stage.is-host-camera-off .watch-camera-off-stage::before,
    body.header-modal-mode.embed-mode .stage-screen.has-six-stage-self.is-host-camera-off .watch-camera-off-stage::before,
    body.header-modal-mode.embed-mode .stage-screen.has-seven-stage.is-host-camera-off .watch-camera-off-stage::before,
    body.header-modal-mode.embed-mode .stage-screen.has-seven-stage-self.is-host-camera-off .watch-camera-off-stage::before,
    body.header-modal-mode.embed-mode .stage-screen.has-eight-stage.is-host-camera-off .watch-camera-off-stage::before,
    body.header-modal-mode.embed-mode .stage-screen.has-eight-stage-self.is-host-camera-off .watch-camera-off-stage::before,
    body.header-modal-mode.embed-mode .stage-screen.has-nine-stage.is-host-camera-off .watch-camera-off-stage::before,
    body.header-modal-mode.embed-mode .stage-screen.has-nine-stage-self.is-host-camera-off .watch-camera-off-stage::before,
    body.header-modal-mode.embed-mode .stage-screen.has-ten-stage.is-host-camera-off .watch-camera-off-stage::before,
    body.header-modal-mode.embed-mode .stage-screen.has-ten-stage-self.is-host-camera-off .watch-camera-off-stage::before,
    body.header-modal-mode.embed-mode .stage-screen.has-eleven-stage.is-host-camera-off .watch-camera-off-stage::before,
    body.header-modal-mode.embed-mode .stage-screen.has-eleven-stage-self.is-host-camera-off .watch-camera-off-stage::before,
    body.header-modal-mode.embed-mode .stage-screen.has-twelve-stage.is-host-camera-off .watch-camera-off-stage::before,
    body.header-modal-mode.embed-mode .stage-screen.has-twelve-stage-self.is-host-camera-off .watch-camera-off-stage::before,
    body.header-modal-mode.embed-mode .stage-screen.has-thirteen-stage.is-host-camera-off .watch-camera-off-stage::before,
    body.header-modal-mode.embed-mode .stage-screen.has-thirteen-stage-self.is-host-camera-off .watch-camera-off-stage::before {
      width: min(44%, 420px);
      height: 4px;
      background: #ff4d3b;
      transform: translate(-50%, -50%) rotate(-29deg);
      box-shadow: none;
    }

    body.header-modal-mode.embed-mode .stage-screen.has-three-stage.is-host-camera-off .watch-camera-off-icon,
    body.header-modal-mode.embed-mode .stage-screen.has-three-stage-self.is-host-camera-off .watch-camera-off-icon,
    body.header-modal-mode.embed-mode .stage-screen.has-four-stage.is-host-camera-off .watch-camera-off-icon,
    body.header-modal-mode.embed-mode .stage-screen.has-four-stage-self.is-host-camera-off .watch-camera-off-icon,
    body.header-modal-mode.embed-mode .stage-screen.has-five-stage.is-host-camera-off .watch-camera-off-icon,
    body.header-modal-mode.embed-mode .stage-screen.has-five-stage-self.is-host-camera-off .watch-camera-off-icon,
    body.header-modal-mode.embed-mode .stage-screen.has-six-stage.is-host-camera-off .watch-camera-off-icon,
    body.header-modal-mode.embed-mode .stage-screen.has-six-stage-self.is-host-camera-off .watch-camera-off-icon,
    body.header-modal-mode.embed-mode .stage-screen.has-seven-stage.is-host-camera-off .watch-camera-off-icon,
    body.header-modal-mode.embed-mode .stage-screen.has-seven-stage-self.is-host-camera-off .watch-camera-off-icon,
    body.header-modal-mode.embed-mode .stage-screen.has-eight-stage.is-host-camera-off .watch-camera-off-icon,
    body.header-modal-mode.embed-mode .stage-screen.has-eight-stage-self.is-host-camera-off .watch-camera-off-icon,
    body.header-modal-mode.embed-mode .stage-screen.has-nine-stage.is-host-camera-off .watch-camera-off-icon,
    body.header-modal-mode.embed-mode .stage-screen.has-nine-stage-self.is-host-camera-off .watch-camera-off-icon,
    body.header-modal-mode.embed-mode .stage-screen.has-ten-stage.is-host-camera-off .watch-camera-off-icon,
    body.header-modal-mode.embed-mode .stage-screen.has-ten-stage-self.is-host-camera-off .watch-camera-off-icon,
    body.header-modal-mode.embed-mode .stage-screen.has-eleven-stage.is-host-camera-off .watch-camera-off-icon,
    body.header-modal-mode.embed-mode .stage-screen.has-eleven-stage-self.is-host-camera-off .watch-camera-off-icon,
    body.header-modal-mode.embed-mode .stage-screen.has-twelve-stage.is-host-camera-off .watch-camera-off-icon,
    body.header-modal-mode.embed-mode .stage-screen.has-twelve-stage-self.is-host-camera-off .watch-camera-off-icon,
    body.header-modal-mode.embed-mode .stage-screen.has-thirteen-stage.is-host-camera-off .watch-camera-off-icon,
    body.header-modal-mode.embed-mode .stage-screen.has-thirteen-stage-self.is-host-camera-off .watch-camera-off-icon {
      width: 74px;
      height: 58px;
      color: #f3f6fc;
      filter: drop-shadow(0 8px 18px rgba(0, 0, 0, 0.48));
    }

    body.header-modal-mode.embed-mode .stage-screen.has-three-stage.is-host-camera-off .watch-camera-off-icon .fa,
    body.header-modal-mode.embed-mode .stage-screen.has-three-stage-self.is-host-camera-off .watch-camera-off-icon .fa,
    body.header-modal-mode.embed-mode .stage-screen.has-four-stage.is-host-camera-off .watch-camera-off-icon .fa,
    body.header-modal-mode.embed-mode .stage-screen.has-four-stage-self.is-host-camera-off .watch-camera-off-icon .fa,
    body.header-modal-mode.embed-mode .stage-screen.has-five-stage.is-host-camera-off .watch-camera-off-icon .fa,
    body.header-modal-mode.embed-mode .stage-screen.has-five-stage-self.is-host-camera-off .watch-camera-off-icon .fa,
    body.header-modal-mode.embed-mode .stage-screen.has-six-stage.is-host-camera-off .watch-camera-off-icon .fa,
    body.header-modal-mode.embed-mode .stage-screen.has-six-stage-self.is-host-camera-off .watch-camera-off-icon .fa,
    body.header-modal-mode.embed-mode .stage-screen.has-seven-stage.is-host-camera-off .watch-camera-off-icon .fa,
    body.header-modal-mode.embed-mode .stage-screen.has-seven-stage-self.is-host-camera-off .watch-camera-off-icon .fa,
    body.header-modal-mode.embed-mode .stage-screen.has-eight-stage.is-host-camera-off .watch-camera-off-icon .fa,
    body.header-modal-mode.embed-mode .stage-screen.has-eight-stage-self.is-host-camera-off .watch-camera-off-icon .fa,
    body.header-modal-mode.embed-mode .stage-screen.has-nine-stage.is-host-camera-off .watch-camera-off-icon .fa,
    body.header-modal-mode.embed-mode .stage-screen.has-nine-stage-self.is-host-camera-off .watch-camera-off-icon .fa,
    body.header-modal-mode.embed-mode .stage-screen.has-ten-stage.is-host-camera-off .watch-camera-off-icon .fa,
    body.header-modal-mode.embed-mode .stage-screen.has-ten-stage-self.is-host-camera-off .watch-camera-off-icon .fa,
    body.header-modal-mode.embed-mode .stage-screen.has-eleven-stage.is-host-camera-off .watch-camera-off-icon .fa,
    body.header-modal-mode.embed-mode .stage-screen.has-eleven-stage-self.is-host-camera-off .watch-camera-off-icon .fa,
    body.header-modal-mode.embed-mode .stage-screen.has-twelve-stage.is-host-camera-off .watch-camera-off-icon .fa,
    body.header-modal-mode.embed-mode .stage-screen.has-twelve-stage-self.is-host-camera-off .watch-camera-off-icon .fa,
    body.header-modal-mode.embed-mode .stage-screen.has-thirteen-stage.is-host-camera-off .watch-camera-off-icon .fa,
    body.header-modal-mode.embed-mode .stage-screen.has-thirteen-stage-self.is-host-camera-off .watch-camera-off-icon .fa {
      font-size: 44px;
    }
  </style>
</head>
<body class="<?php echo trim(($embedMode ? 'embed-mode ' : '') . ($headerModalMode ? 'header-modal-mode' : '')); ?>">
  <div class="watch-shell">
    <div class="watch-frame" id="watchFrame">
      <?php if ($errorMessage !== ''): ?>
        <div class="error-box">
          <h1>Live Room</h1>
          <p><?php echo h($errorMessage); ?></p>
        </div>
      <?php else: ?>
        <div class="watch-head">
          <div class="watch-head-left">
            <div class="watch-title">
              <div class="watch-title-row">
                <h1 id="watchModalTitle"><?php echo h($title !== '' ? $title : 'Live room'); ?></h1>
                <div class="chip live">Live</div>
              </div>
              <p>Started now • <i class="fa fa-eye" aria-hidden="true"></i> <span id="watchTopViewerCount"><?php echo (int)($live['viewer_count'] ?? 0); ?></span> watching</p>
            </div>
          </div>
          <div class="watch-head-right">
            <div class="watch-top-meta">Watching live now</div>
            <button type="button" class="watch-speaker-btn" id="watchSpeakerButton" aria-label="Speaker view">
              <i class="fa fa-th-large" aria-hidden="true"></i>
            </button>
            <button type="button" class="watch-top-end" id="watchEndButtonTop" aria-label="Leave live">Leave</button>
          </div>
        </div>

        <div class="watch-grid">
          <section class="watch-stage">
            <div class="stage-screen">
              <img class="stage-image" id="watchStageImage" alt="Live camera preview">
              <video class="stage-video" id="watchStageVideo" autoplay playsinline muted></video>
              <div class="watch-camera-off-stage" id="watchCameraOffStage" aria-hidden="true">
                <div class="watch-camera-off-icon" aria-hidden="true">
                  <i class="fa fa-video-camera"></i>
                </div>
              </div>
              <?php if (!$embedMode): ?>
              <div class="watch-stage-reactions" id="watchStageReactions" aria-hidden="true"></div>
              <?php endif; ?>
              <div class="guest-audience-layer" id="watchGuestAudienceLayer"></div>
              <div class="guest-self-tile" id="watchGuestSelfTile">
                <video class="guest-self-video" id="watchGuestSelfVideo" autoplay playsinline muted></video>
                <div class="guest-self-camera-off-stage" aria-hidden="true">
                  <div class="guest-self-camera-off-icon" aria-hidden="true">
                    <i class="fa fa-video-camera"></i>
                  </div>
                </div>
                <div class="guest-self-meta">You joined live</div>
              </div>
              <div class="stage-top">
                <div class="stage-pill"><?php echo h($ownerName); ?></div>
              </div>
              <div class="stage-center">
                <h2 id="watchStageTitle"><?php echo h($title !== '' ? $title : 'Live room'); ?></h2>
                <p id="watchStageText"><?php echo h((string)($live['description'] ?? 'Join the room, follow the comments, and react in real time as this live session runs.')); ?></p>
              </div>
              <div class="stage-note">
                <strong id="watchStageStatus"><?php echo $status === 'live' ? 'LIVE NOW' : strtoupper($status ?: 'draft'); ?></strong>
                <span id="watchStageMeta"><?php echo h((string)($live['started_at_label'] ?? watchFmt((string)($live['started_at'] ?? $live['scheduled_for'] ?? '')))); ?></span>
              </div>
            </div>
          </section>

          <aside class="watch-sidebar" id="watchSidebar">
            <div class="watch-side-head">
              <div class="watch-side-title">
                <strong id="watchSidebarTitleText">Comments</strong>
                <span id="watchSidebarTitleCount">0</span>
              </div>
              <button type="button" id="watchSidebarClose" class="watch-speaker-btn" aria-label="Close chat" style="width:56px;height:56px;border-radius:50%;background:rgba(255,255,255,.12);color:#f8fafc;box-shadow:none;">&times;</button>
            </div>
            <div class="watch-side-stats">
              <div class="watch-side-stat"><strong id="reactionTotal">0</strong> Reactions</div>
              <div class="watch-side-stat"><strong id="commentCount">0</strong> Comments</div>
              <div class="watch-side-stat"><i class="fa fa-eye" aria-hidden="true"></i> <strong id="viewerCount"><?php echo (int)($live['viewer_count'] ?? 0); ?></strong> Watching</div>
            </div>
            <div class="watch-side-scroll">
              <div class="meta-stack" id="watchChatPanel">
                <?php if (!$embedMode && $meId !== (int)($live['user_id'] ?? 0) && $visibility === 'friends'): ?>
                <div class="panel light" id="joinRequestPanel">
                  <h3>Join the host live</h3>
                  <p class="join-request-copy">Ask the host to bring you into the live video. Wait for <span class="accent">Confirm</span> before your camera connection starts.</p>
                  <div class="btn-row" style="margin-top:14px;">
                    <button class="btn" type="button" id="joinRequestButton">Request</button>
                  </div>
                  <div class="join-request-status" id="joinRequestStatus">Tap Request when you want to join this live video with the host.</div>
                </div>
                <?php endif; ?>
                <div class="panel">
                  <h3>Reactions</h3>
                  <div class="reaction-row">
                    <button class="reaction-btn" type="button" data-reaction="love"><span class="reaction-love-heart" aria-hidden="true">&#10084;</span><span class="reaction-label">Love</span> <span data-reaction-count="love">0</span></button>
                    <button class="reaction-btn" type="button" data-reaction="like">Like <span data-reaction-count="like">0</span></button>
                    <button class="reaction-btn" type="button" data-reaction="fire">Fire <span data-reaction-count="fire">0</span></button>
                    <button class="reaction-btn" type="button" data-reaction="wow">Wow <span data-reaction-count="wow">0</span></button>
                    <button class="reaction-btn" type="button" data-reaction="clap">Clap <span data-reaction-count="clap">0</span></button>
                  </div>
                </div>
                <div class="panel chat-panel">
                  <div class="comment-list" id="commentList">
                    <div class="comment"><div class="comment-avatar">LR</div><div class="comment-main"><div class="comment-author">Live Room</div><div class="comment-body">Comments will appear here when the host or viewers post to this room.</div></div></div>
                  </div>
                </div>
              </div>
              <div class="watch-reaction-panel" id="watchReactionPanel">
                <div class="watch-reaction-tabs" id="watchReactionTabs"></div>
                <div class="watch-reaction-list" id="watchReactionList"></div>
              </div>
              <div class="watch-description-panel" id="watchDescriptionPanel">
                <div class="watch-description-card">
                  <div class="watch-description-head">
                    <div class="watch-description-avatar" id="watchDescriptionAvatar"><?php echo h($ownerInitials); ?></div>
                    <div class="watch-description-meta">
                      <h3 class="watch-description-title" id="watchDescriptionTitle"><?php echo h($title !== '' ? $title : 'Live room'); ?></h3>
                      <div class="watch-description-sub" id="watchDescriptionSub"><?php echo h($ownerName . ' • ' . ((string)($live['started_at_label'] ?? watchFmt((string)($live['started_at'] ?? $live['scheduled_for'] ?? 'Now'))))); ?></div>
                    </div>
                  </div>
                  <div class="watch-description-divider"></div>
              <div class="watch-description-scroll">
                <p class="watch-description-body" id="watchDescriptionBody"><?php echo h((string)($live['description'] ?? 'Join the room, follow the comments, and react in real time as this live session runs.')); ?></p>
              </div>
            </div>
          </div>
          <div class="watch-settings-panel" id="watchSettingsPanel" aria-hidden="true">
            <div class="watch-settings-body">
              <div class="watch-settings-item">
                <label for="watchSettingsCameraDevice">Camera device</label>
                <select id="watchSettingsCameraDevice">
                  <option value="">System default camera</option>
                </select>
                <span class="note">Used for your local host preview or when you join this live on camera.</span>
              </div>
              <div class="watch-settings-item">
                <label for="watchSettingsMicDevice">Microphone device</label>
                <select id="watchSettingsMicDevice">
                  <option value="">System default microphone</option>
                </select>
                <span class="note">Used for your local host preview or when you join this live on camera.</span>
              </div>
              <div class="watch-settings-item">
                <label for="watchSettingsSpeakerDevice">Speaker / output</label>
                <select id="watchSettingsSpeakerDevice">
                  <option value="">System default output</option>
                </select>
                <span class="note">Changes the output for live playback when the browser supports it.</span>
              </div>
              <div class="watch-settings-item">
                <label class="watch-settings-toggle" for="watchSettingsAudio">
                  <span>Microphone on</span>
                  <input type="checkbox" id="watchSettingsAudio">
                </label>
                <span class="note">Mute or unmute your local microphone or the live audio playback.</span>
              </div>
              <div class="watch-settings-item">
                <label class="watch-settings-toggle" for="watchSettingsCameraEnabled">
                  <span>Camera on</span>
                  <input type="checkbox" id="watchSettingsCameraEnabled">
                </label>
                <span class="note">Hide or show your local camera, or pause the live video locally.</span>
              </div>
              <div class="watch-settings-item">
                <label class="watch-settings-toggle" for="watchSettingsMirror">
                  <span>Mirror camera</span>
                  <input type="checkbox" id="watchSettingsMirror">
                </label>
                <span class="note">Only affects your own preview when you are the host or a guest on stage.</span>
              </div>
              <div class="watch-settings-item">
                <label for="watchSettingsQuality">Video quality</label>
                <select id="watchSettingsQuality">
                  <option value="auto">Auto</option>
                  <option value="720p">720p</option>
                  <option value="1080p">1080p</option>
                </select>
                <span class="note">Used for your local host preview or when you join this live on camera.</span>
              </div>
              <div class="watch-settings-item">
                <label for="watchSettingsFrameRate">Frame rate</label>
                <select id="watchSettingsFrameRate">
                  <option value="24">24 fps</option>
                  <option value="30">30 fps</option>
                </select>
                <span class="note">Use 24 fps for stability or 30 fps for smoother motion.</span>
              </div>
            </div>
          </div>
        </div>
        <div class="watch-compose" id="watchCompose">
              <div class="watch-compose-shell">
                <div class="watch-compose-inputwrap">
                  <textarea id="commentInput" placeholder="Add comment..."></textarea>
                  <button type="button" class="watch-compose-tool" aria-label="Mention">@</button>
                  <button type="button" class="watch-compose-tool" aria-label="Emoji"><i class="fa fa-smile-o" aria-hidden="true"></i></button>
                </div>
                <button class="watch-send-btn" type="button" id="sendCommentButton" aria-label="Send comment">
                  <i class="fa fa-arrow-up" aria-hidden="true"></i>
                </button>
              </div>
              <div class="feedback" id="feedback"></div>
            </div>
          </aside>
        </div>
        <div class="watch-bottom">
          <div class="watch-controls">
            <button type="button" class="watch-control" id="watchMicToggle" aria-label="Turn microphone on">
              <i class="fa fa-microphone has-off-slash" aria-hidden="true"></i>
              <span class="watch-control-label">Microphone</span>
            </button>
            <button type="button" class="watch-control" id="watchCameraToggle" aria-label="Turn camera off">
              <i class="fa fa-video-camera" aria-hidden="true"></i>
              <span class="watch-control-label">Camera</span>
            </button>
            <button type="button" class="watch-control" id="watchShareButton" aria-label="Share live">
              <i class="fa fa-desktop" aria-hidden="true"></i>
              <span class="watch-control-label">Share</span>
            </button>
            <button type="button" class="watch-control" id="watchSettingsToggle" aria-label="Settings">
              <i class="fa fa-cog" aria-hidden="true"></i>
              <span class="watch-control-label">Settings</span>
            </button>
            <button type="button" class="watch-control" id="watchReactionToggle" aria-label="React">
              <i class="fa fa-smile-o" aria-hidden="true"></i>
              <span class="watch-control-label">React</span>
            </button>
            <button type="button" class="watch-control" aria-label="Fullscreen">
              <i class="fa fa-arrows-alt" aria-hidden="true"></i>
              <span class="watch-control-label">Fullscreen</span>
            </button>
            <button type="button" class="watch-control" aria-label="Leave">
              <i class="fa fa-sign-out" aria-hidden="true"></i>
              <span class="watch-control-label">Leave</span>
            </button>
          </div>
          <div class="watch-controls-right">
            <button type="button" class="watch-control" aria-label="Apps">
              <i class="fa fa-ellipsis-h" aria-hidden="true"></i>
              <span class="watch-control-label">Apps</span>
            </button>
            <button type="button" class="watch-control" aria-label="People">
              <i class="fa fa-eye" aria-hidden="true"></i>
              <span class="watch-control-label">Watching <span class="watch-control-count" id="watchDockViewerCount"><?php echo (int)($live['viewer_count'] ?? 0); ?></span></span>
            </button>
            <button type="button" class="watch-control" aria-label="Polls">
              <i class="fa fa-pie-chart" aria-hidden="true"></i>
              <span class="watch-control-label">Polls</span>
            </button>
            <button type="button" class="watch-control" aria-label="Questions">
              <i class="fa fa-question-circle-o" aria-hidden="true"></i>
              <span class="watch-control-label">Questions</span>
            </button>
            <button type="button" class="watch-control" id="watchChatToggle" aria-label="Chat">
              <i class="fa fa-comment" aria-hidden="true"></i>
              <span class="watch-control-label">Chat <span class="watch-control-count" id="watchBottomCommentCount">0</span></span>
            </button>
            <button type="button" class="watch-control" id="watchDescriptionToggle" aria-label="Description">
              <i class="fa fa-book" aria-hidden="true"></i>
              <span class="watch-control-label">Description</span>
            </button>
          </div>
          <div class="watch-controls-right">
            <button type="button" class="watch-control watch-end" id="watchEndButton" aria-label="End meeting">
              <i class="fa fa-phone" aria-hidden="true"></i>
              <span class="watch-control-label">Leave</span>
            </button>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($errorMessage === ''): ?>
  <script>
    const liveId = <?php echo (int)$liveId; ?>;
    const watchFrame = document.getElementById('watchFrame');
    const commentList = document.getElementById('commentList');
    const commentInput = document.getElementById('commentInput');
    const sendCommentButton = document.getElementById('sendCommentButton');
    const feedback = document.getElementById('feedback');
    const viewerCount = document.getElementById('viewerCount');
    const watchTopViewerCount = document.getElementById('watchTopViewerCount');
    const commentCount = document.getElementById('commentCount');
    const watchSidebarCommentTotal = document.getElementById('watchSidebarCommentTotal');
    const reactionTotal = document.getElementById('reactionTotal');
    const watchBottomCommentCount = document.getElementById('watchBottomCommentCount');
    const watchBottomReactionCount = document.getElementById('watchBottomReactionCount');
    const watchDockViewerCount = document.getElementById('watchDockViewerCount');
    const watchSidebarTitleText = document.getElementById('watchSidebarTitleText');
    const watchSidebarTitleCount = document.getElementById('watchSidebarTitleCount');
    const joinRequestButton = document.getElementById('joinRequestButton');
    const joinRequestStatusNode = document.getElementById('joinRequestStatus');
    const watchStageImage = document.getElementById('watchStageImage');
    const watchStageVideo = document.getElementById('watchStageVideo');
    const watchStageReactions = document.getElementById('watchStageReactions');
    const watchStageScreen = document.querySelector('.stage-screen');
    const watchGuestAudienceLayer = document.getElementById('watchGuestAudienceLayer');
    const watchGuestSelfTile = document.getElementById('watchGuestSelfTile');
    const watchGuestSelfVideo = document.getElementById('watchGuestSelfVideo');
    const watchMicToggle = document.getElementById('watchMicToggle');
    const watchCameraToggle = document.getElementById('watchCameraToggle');
    const watchSettingsToggle = document.getElementById('watchSettingsToggle');
    const watchSettingsPanel = document.getElementById('watchSettingsPanel');
    const watchSettingsCameraDevice = document.getElementById('watchSettingsCameraDevice');
    const watchSettingsMicDevice = document.getElementById('watchSettingsMicDevice');
    const watchSettingsSpeakerDevice = document.getElementById('watchSettingsSpeakerDevice');
    const watchSettingsAudio = document.getElementById('watchSettingsAudio');
    const watchSettingsCameraEnabled = document.getElementById('watchSettingsCameraEnabled');
    const watchSettingsMirror = document.getElementById('watchSettingsMirror');
    const watchSettingsQuality = document.getElementById('watchSettingsQuality');
    const watchSettingsFrameRate = document.getElementById('watchSettingsFrameRate');
    const watchChatToggle = document.getElementById('watchChatToggle');
    const watchSpeakerButton = document.getElementById('watchSpeakerButton');
    const watchEndButton = document.getElementById('watchEndButton');
    const watchEndButtonTop = document.getElementById('watchEndButtonTop');
    const watchSidebarClose = document.getElementById('watchSidebarClose');
    const watchReactionToggle = document.getElementById('watchReactionToggle');
    const watchDescriptionToggle = document.getElementById('watchDescriptionToggle');
    const watchShareButton = document.getElementById('watchShareButton');
    const watchReactionTabs = document.getElementById('watchReactionTabs');
    const watchReactionList = document.getElementById('watchReactionList');
    const watchDescriptionAvatar = document.getElementById('watchDescriptionAvatar');
    const watchDescriptionTitle = document.getElementById('watchDescriptionTitle');
    const watchDescriptionSub = document.getElementById('watchDescriptionSub');
    const watchDescriptionBody = document.getElementById('watchDescriptionBody');
    const reactionButtons = Array.from(document.querySelectorAll('[data-reaction]'));
    let pollTimer = null;
    let snapshotPollTimer = null;
    let snapshotVersion = <?php echo json_encode((string)($live['snapshot_version'] ?? '')); ?>;
    let isLiveActive = <?php echo $status === 'live' ? 'true' : 'false'; ?>;
    const viewerId = <?php echo (int)$meId; ?>;
    const ownerId = <?php echo (int)($live['user_id'] ?? 0); ?>;
    const maxActiveGuests = 29;
    const isOwnerView = ownerId === viewerId;
    const isEmbedMode = <?php echo $embedMode ? 'true' : 'false'; ?>;
    const preferSnapshotViewer = <?php echo ($embedMode && $snapshotEmbedMode) ? 'true' : 'false'; ?>;
    let peerKeyNonce = 0;
    let peerKey = '';
    const rtcConfig = { iceServers: [{ urls: 'stun:stun.l.google.com:19302' }] };
    let remotePc = null;
    let remoteStream = null;
    let remoteVideoTransceiver = null;
    let remoteAudioTransceiver = null;
    let signalPollTimer = null;
    let guestSignalPollTimer = null;
    let webRtcReady = false;
    let offerInFlight = false;
    let guestOfferInFlight = false;
    let localOwnerStream = null;
    let localGuestStream = null;
    let guestSnapshotTimer = null;
    let guestAudienceRefreshTimer = null;
    let guestSnapshotBusy = false;
    let joinRequestStatus = <?php echo json_encode(''); ?>;
    let approvedGuests = [];
    let approvedGuestSnapshotVersions = {};
    let approvedGuestSnapshotUrls = {};
    let guestAudiencePeers = {};
    let guestAudienceStreams = {};
    let guestAudienceOfferInFlight = {};
    let guestAudiencePeerMeta = {};
    let guestAudienceHealthTimer = null;
    let guestPublishPc = null;
    let guestPeerKeyNonce = 0;
    let guestPeerKey = '';
    let remoteDisconnectTimer = null;
    let guestPublishDisconnectTimer = null;
    let guestAudienceDisconnectTimers = {};
    let stageVideoHealthTimer = null;
    let stageVideoRecoveryBusy = false;
    let stageVideoLastCurrentTime = 0;
    let stageVideoLastAdvanceAt = 0;
    let stageSnapshotLoadToken = 0;
    const peerDisconnectGraceMs = 8000;
    const guestAudienceRetryGraceMs = 5000;
    const guestAudienceFreezeGraceMs = 7000;
    const stageVideoFreezeGraceMs = 6500;
    let watchSidebarMode = 'chat';
    let watchMicEnabled = false;
    let watchCameraEnabled = true;
    let watchMirrorSelfView = false;
    let watchVideoQuality = 'auto';
    let watchFrameRatePreference = 24;
    let watchSelectedCameraDeviceId = '';
    let watchSelectedMicDeviceId = '';
    let watchSelectedSpeakerDeviceId = '';
    let watchAvailableCameraDevices = [];
    let watchAvailableMicDevices = [];
    let watchAvailableSpeakerDevices = [];
    let watchReactionFilter = 'all';
    let watchReactionUsers = [];
    let watchReactionCounts = {};
    let watchStageInitialized = false;
    let watchHostCameraEnabled = true;
    let watchLastReactionCounts = { love: 0, like: 0, fire: 0, wow: 0, clap: 0 };
    const initialWatchOwnerName = <?php echo json_encode($ownerName); ?>;
    const initialWatchOwnerInitials = <?php echo json_encode($ownerInitials); ?>;
    const initialWatchStartedLabel = <?php echo json_encode((string)($live['started_at_label'] ?? watchFmt((string)($live['started_at'] ?? $live['scheduled_for'] ?? 'Now')))); ?>;
    const viewerInstanceToken = (function() {
      const timePart = Date.now().toString(36).slice(-4);
      const randomPart = Math.random().toString(36).slice(2, 6);
      return (timePart + randomPart).slice(0, 8) || 'watcher1';
    })();

    function setWatchSidebarMode(mode) {
      if (!watchFrame || isEmbedMode) return;
      const nextMode = mode === 'reactions'
        ? 'reactions'
        : (mode === 'description' ? 'description' : (mode === 'settings' ? 'settings' : (mode === 'chat' ? 'chat' : '')));
      watchSidebarMode = nextMode;
      watchFrame.classList.toggle('has-chat', nextMode !== '');
      watchFrame.classList.toggle('sidebar-mode-chat', nextMode === 'chat');
      watchFrame.classList.toggle('sidebar-mode-reactions', nextMode === 'reactions');
      watchFrame.classList.toggle('sidebar-mode-description', nextMode === 'description');
      watchFrame.classList.toggle('sidebar-mode-settings', nextMode === 'settings');
      if (watchSettingsPanel) {
        watchSettingsPanel.setAttribute('aria-hidden', nextMode === 'settings' ? 'false' : 'true');
      }
      if (watchSettingsToggle) {
        watchSettingsToggle.classList.toggle('is-active', nextMode === 'settings');
        watchSettingsToggle.setAttribute('aria-pressed', nextMode === 'settings' ? 'true' : 'false');
      }
      if (watchChatToggle) {
        watchChatToggle.classList.toggle('is-active', nextMode === 'chat');
        watchChatToggle.setAttribute('aria-pressed', nextMode === 'chat' ? 'true' : 'false');
      }
      if (watchReactionToggle) {
        watchReactionToggle.classList.toggle('is-active', nextMode === 'reactions');
        watchReactionToggle.setAttribute('aria-pressed', nextMode === 'reactions' ? 'true' : 'false');
      }
      if (watchDescriptionToggle) {
        watchDescriptionToggle.classList.toggle('is-active', nextMode === 'description');
        watchDescriptionToggle.setAttribute('aria-pressed', nextMode === 'description' ? 'true' : 'false');
      }
      if (nextMode === 'settings') {
        renderWatchDeviceControls();
      }
      if (nextMode === 'description') {
        syncWatchDescriptionPanel();
      }
      syncWatchSidebarHeader();
    }

    function setWatchSidebarOpen(isOpen) {
      setWatchSidebarMode(isOpen ? 'chat' : '');
    }

    function currentPublishedStream() {
      if (localOwnerStream) {
        return localOwnerStream;
      }
      if (localGuestStream) {
        return localGuestStream;
      }
      return null;
    }

    function hasLiveAudioTrack(stream) {
      return !!(stream && typeof stream.getAudioTracks === 'function' && stream.getAudioTracks().some(function(track) {
        return track.readyState === 'live';
      }));
    }

    function hasLiveVideoTrack(stream) {
      return !!(stream && typeof stream.getVideoTracks === 'function' && stream.getVideoTracks().some(function(track) {
        return track.readyState === 'live';
      }));
    }

    function supportsWatchSinkSelection(mediaNode) {
      return !!(mediaNode && typeof mediaNode.setSinkId === 'function');
    }

    function currentWatchCaptureProfile() {
      const frameRate = Number(watchFrameRatePreference || 24) >= 30 ? 30 : 24;
      if (watchVideoQuality === '720p') {
        return { width: 1280, height: 720, frameRate: frameRate };
      }
      if (watchVideoQuality === '1080p') {
        return { width: 1920, height: 1080, frameRate: frameRate };
      }
      if (isOwnerView) {
        return { width: 1280, height: 720, frameRate: Math.min(frameRate, 24) };
      }
      const activeGuestCount = Math.min(maxActiveGuests, approvedGuests.length || 0);
      if (activeGuestCount >= 6) {
        return { width: 240, height: 135, frameRate: Math.min(frameRate, 8) };
      }
      if (activeGuestCount >= 3) {
        return { width: 480, height: 270, frameRate: Math.min(frameRate, 12) };
      }
      return { width: 640, height: 360, frameRate: Math.min(frameRate, 15) };
    }

    async function refreshWatchMediaDevices() {
      if (!navigator.mediaDevices || typeof navigator.mediaDevices.enumerateDevices !== 'function') {
        return;
      }
      try {
        const devices = await navigator.mediaDevices.enumerateDevices();
        watchAvailableCameraDevices = devices.filter(function(device) { return device.kind === 'videoinput'; }).map(function(device, index) {
          return { deviceId: String(device.deviceId || ''), label: String(device.label || ('Camera ' + String(index + 1))) };
        });
        watchAvailableMicDevices = devices.filter(function(device) { return device.kind === 'audioinput'; }).map(function(device, index) {
          return { deviceId: String(device.deviceId || ''), label: String(device.label || ('Microphone ' + String(index + 1))) };
        });
        watchAvailableSpeakerDevices = devices.filter(function(device) { return device.kind === 'audiooutput'; }).map(function(device, index) {
          return { deviceId: String(device.deviceId || ''), label: String(device.label || ('Output ' + String(index + 1))) };
        });
        renderWatchDeviceControls();
      } catch (error) {}
    }

    async function applyWatchOutputDevice(deviceId) {
      watchSelectedSpeakerDeviceId = String(deviceId || '');
      const targets = [watchStageVideo, watchGuestSelfVideo].filter(Boolean).filter(function(node) {
        return supportsWatchSinkSelection(node);
      });
      if (!targets.length) {
        return false;
      }
      try {
        await Promise.all(targets.map(function(node) {
          return node.setSinkId(watchSelectedSpeakerDeviceId || '');
        }));
        return true;
      } catch (error) {
        return false;
      }
    }

    async function restartWatchLocalCaptureFromSettings() {
      if (localOwnerStream) {
        localOwnerStream.getTracks().forEach(function(track) {
          try { track.stop(); } catch (error) {}
        });
        localOwnerStream = null;
        await startOwnerLocalPreview();
        return true;
      }
      if (localGuestStream && joinRequestStatus === 'approved') {
        stopGuestPublishing();
        await startGuestPublishing();
        return true;
      }
      return false;
    }

    function applyWatchMirrorState() {
      if (watchStageVideo) {
        watchStageVideo.classList.toggle('is-mirrored', !!(watchMirrorSelfView && isOwnerView && localOwnerStream));
      }
      if (watchGuestSelfVideo) {
        watchGuestSelfVideo.classList.toggle('is-mirrored', !!(watchMirrorSelfView && localGuestStream));
      }
    }

    function applyWatchMicrophoneState() {
      const publishStream = currentPublishedStream();
      const publishTracks = publishStream && typeof publishStream.getAudioTracks === 'function'
        ? publishStream.getAudioTracks().filter(function(track) { return track.readyState === 'live'; })
        : [];
      if (publishTracks.length) {
        publishTracks.forEach(function(track) {
          track.enabled = watchMicEnabled;
        });
      } else if (watchStageVideo) {
        watchStageVideo.muted = !watchMicEnabled;
        watchStageVideo.defaultMuted = !watchMicEnabled;
        if (watchMicEnabled) {
          watchStageVideo.removeAttribute('muted');
          watchStageVideo.play().catch(function() {});
        } else {
          watchStageVideo.setAttribute('muted', 'muted');
        }
      }
    }

    function applyWatchCameraState() {
      const publishStream = currentPublishedStream();
      const publishTracks = publishStream && typeof publishStream.getVideoTracks === 'function'
        ? publishStream.getVideoTracks().filter(function(track) { return track.readyState === 'live'; })
        : [];
      const shouldHideHostStage = !!(!watchCameraEnabled && isOwnerView && !!localOwnerStream);
      const shouldHideGuestSelf = !!(!watchCameraEnabled && !isOwnerView && !!localGuestStream);
      if (watchStageScreen) {
        watchStageScreen.classList.toggle('is-local-video-off', shouldHideHostStage);
      }
      if (watchGuestSelfTile) {
        watchGuestSelfTile.classList.toggle('is-local-video-off', shouldHideGuestSelf);
      }
      if (publishTracks.length) {
        publishTracks.forEach(function(track) {
          track.enabled = watchCameraEnabled;
        });
      }
      if (watchStageVideo) {
        if (shouldHideHostStage) {
          watchStageVideo.pause();
        } else if (watchCameraEnabled || !isOwnerView) {
          watchStageVideo.play().catch(function() {});
        }
      }
      if (watchGuestSelfVideo) {
        if (shouldHideGuestSelf) {
          watchGuestSelfVideo.pause();
        } else if (localGuestStream) {
          watchGuestSelfVideo.play().catch(function() {});
        }
      }
    }

    function syncWatchHostStageVisibility() {
      if (!watchStageScreen) {
        return;
      }
      const shouldHideHostStage = !!(!watchHostCameraEnabled && !isOwnerView);
      watchStageScreen.classList.toggle('is-host-camera-off', shouldHideHostStage);
      if (watchStageVideo && !shouldHideHostStage && !watchStageScreen.classList.contains('is-local-video-off')) {
        watchStageVideo.play().catch(function() {});
      }
    }

    function renderWatchDeviceControls() {
      const publishStream = currentPublishedStream();
      const hasPublishedAudio = hasLiveAudioTrack(publishStream);
      const hasPublishedVideo = hasLiveVideoTrack(publishStream);
      if (watchMicToggle) {
        const micIcon = watchMicToggle.querySelector('i');
        watchMicToggle.classList.toggle('is-active', !!watchMicEnabled);
        watchMicToggle.setAttribute('aria-pressed', watchMicEnabled ? 'true' : 'false');
        watchMicToggle.setAttribute('aria-label', watchMicEnabled ? 'Turn microphone off' : 'Turn microphone on');
        if (micIcon) {
          micIcon.className = watchMicEnabled ? 'fa fa-microphone' : 'fa fa-microphone has-off-slash';
        }
        watchMicToggle.title = hasPublishedAudio ? 'Control your live microphone' : 'Control live audio playback';
      }
      if (watchCameraToggle) {
        const cameraIcon = watchCameraToggle.querySelector('i');
        watchCameraToggle.classList.toggle('is-active', !!watchCameraEnabled);
        watchCameraToggle.setAttribute('aria-pressed', watchCameraEnabled ? 'true' : 'false');
        watchCameraToggle.setAttribute('aria-label', watchCameraEnabled ? 'Turn camera off' : 'Turn camera on');
        if (cameraIcon) {
          cameraIcon.className = watchCameraEnabled ? 'fa fa-video-camera' : 'fa fa-video-camera has-off-slash';
        }
        watchCameraToggle.title = hasPublishedVideo ? 'Control your live camera' : 'Control live video playback';
      }
      if (watchSettingsAudio) {
        watchSettingsAudio.checked = !!watchMicEnabled;
      }
      if (watchSettingsCameraEnabled) {
        watchSettingsCameraEnabled.checked = !!watchCameraEnabled;
      }
      if (watchSettingsMirror) {
        watchSettingsMirror.checked = !!watchMirrorSelfView;
      }
      if (watchSettingsQuality) {
        watchSettingsQuality.value = watchVideoQuality;
      }
      if (watchSettingsFrameRate) {
        watchSettingsFrameRate.value = String(Number(watchFrameRatePreference || 24));
      }
      if (watchSettingsCameraDevice) {
        watchSettingsCameraDevice.innerHTML = '<option value="">System default camera</option>' + watchAvailableCameraDevices.map(function(device) {
          const selected = device.deviceId === watchSelectedCameraDeviceId ? ' selected' : '';
          return '<option value="' + escHtml(device.deviceId) + '"' + selected + '>' + escHtml(device.label) + '</option>';
        }).join('');
      }
      if (watchSettingsMicDevice) {
        watchSettingsMicDevice.innerHTML = '<option value="">System default microphone</option>' + watchAvailableMicDevices.map(function(device) {
          const selected = device.deviceId === watchSelectedMicDeviceId ? ' selected' : '';
          return '<option value="' + escHtml(device.deviceId) + '"' + selected + '>' + escHtml(device.label) + '</option>';
        }).join('');
      }
      if (watchSettingsSpeakerDevice) {
        watchSettingsSpeakerDevice.innerHTML = '<option value="">System default output</option>' + watchAvailableSpeakerDevices.map(function(device) {
          const selected = device.deviceId === watchSelectedSpeakerDeviceId ? ' selected' : '';
          return '<option value="' + escHtml(device.deviceId) + '"' + selected + '>' + escHtml(device.label) + '</option>';
        }).join('');
        watchSettingsSpeakerDevice.disabled = !(supportsWatchSinkSelection(watchStageVideo) || supportsWatchSinkSelection(watchGuestSelfVideo));
      }
    }

    function syncWatchDeviceControls() {
      applyWatchMicrophoneState();
      applyWatchCameraState();
      applyWatchMirrorState();
      renderWatchDeviceControls();
    }

    function getWatchDeviceControlState() {
      const publishStream = currentPublishedStream();
      return {
        isOwnerView: !!isOwnerView,
        hostCameraEnabled: !!watchHostCameraEnabled,
        micEnabled: !!watchMicEnabled,
        cameraEnabled: !!watchCameraEnabled,
        mirrorSelfView: !!watchMirrorSelfView,
        videoQuality: watchVideoQuality,
        frameRatePreference: Number(watchFrameRatePreference || 24),
        selectedCameraDeviceId: watchSelectedCameraDeviceId,
        selectedMicDeviceId: watchSelectedMicDeviceId,
        selectedSpeakerDeviceId: watchSelectedSpeakerDeviceId,
        cameraDevices: watchAvailableCameraDevices.slice(),
        micDevices: watchAvailableMicDevices.slice(),
        speakerDevices: watchAvailableSpeakerDevices.slice(),
        hasPublishedAudio: hasLiveAudioTrack(publishStream),
        hasPublishedVideo: hasLiveVideoTrack(publishStream)
      };
    }

    function toggleWatchMicrophone(forceValue) {
      const nextValue = typeof forceValue === 'boolean' ? forceValue : !watchMicEnabled;
      watchMicEnabled = !!nextValue;
      syncWatchDeviceControls();
      return getWatchDeviceControlState();
    }

    function toggleWatchCamera(forceValue) {
      const nextValue = typeof forceValue === 'boolean' ? forceValue : !watchCameraEnabled;
      watchCameraEnabled = !!nextValue;
      syncWatchDeviceControls();
      persistGuestCameraEnabled();
      return getWatchDeviceControlState();
    }

    function setWatchMirrorSelfView(forceValue) {
      watchMirrorSelfView = !!forceValue;
      syncWatchDeviceControls();
      return getWatchDeviceControlState();
    }

    function setWatchVideoQuality(nextQuality) {
      const allowed = ['auto', '720p', '1080p'];
      watchVideoQuality = allowed.indexOf(String(nextQuality || 'auto')) !== -1 ? String(nextQuality) : 'auto';
      syncGuestMediaProfile();
      syncWatchDeviceControls();
      return getWatchDeviceControlState();
    }

    function setWatchFrameRatePreference(nextFrameRate) {
      watchFrameRatePreference = Number(nextFrameRate || 24) >= 30 ? 30 : 24;
      syncGuestMediaProfile();
      syncWatchDeviceControls();
      return getWatchDeviceControlState();
    }

    function applyWatchSettings(nextSettings) {
      const settings = nextSettings || {};
      if (Object.prototype.hasOwnProperty.call(settings, 'audioEnabled')) {
        watchMicEnabled = !!settings.audioEnabled;
      }
      if (Object.prototype.hasOwnProperty.call(settings, 'cameraEnabled')) {
        watchCameraEnabled = !!settings.cameraEnabled;
      }
      if (Object.prototype.hasOwnProperty.call(settings, 'mirrorSelfView')) {
        watchMirrorSelfView = !!settings.mirrorSelfView;
      }
      if (Object.prototype.hasOwnProperty.call(settings, 'videoQuality')) {
        watchVideoQuality = String(settings.videoQuality || 'auto');
      }
      if (Object.prototype.hasOwnProperty.call(settings, 'frameRatePreference')) {
        watchFrameRatePreference = Number(settings.frameRatePreference || 24) >= 30 ? 30 : 24;
      }
      if (Object.prototype.hasOwnProperty.call(settings, 'selectedCameraDeviceId')) {
        watchSelectedCameraDeviceId = String(settings.selectedCameraDeviceId || '');
      }
      if (Object.prototype.hasOwnProperty.call(settings, 'selectedMicDeviceId')) {
        watchSelectedMicDeviceId = String(settings.selectedMicDeviceId || '');
      }
      if (Object.prototype.hasOwnProperty.call(settings, 'selectedSpeakerDeviceId')) {
        watchSelectedSpeakerDeviceId = String(settings.selectedSpeakerDeviceId || '');
      }
      syncWatchDeviceControls();
      persistGuestCameraEnabled();
      return getWatchDeviceControlState();
    }

    async function persistGuestCameraEnabled() {
      if (isOwnerView || joinRequestStatus !== 'approved' || !(liveId > 0)) {
        return;
      }
      const formData = new FormData();
      formData.append('action', 'set_camera_enabled');
      formData.append('live_id', String(liveId));
      formData.append('enabled', watchCameraEnabled && !!localGuestStream ? '1' : '0');
      try {
        await fetch('ajax/live_watch_room.php', {
          method: 'POST',
          body: formData,
          credentials: 'same-origin'
        });
      } catch (error) {
        // keep guest camera visibility sync silent
      }
    }

    window.MSBLiveWatchControls = {
      toggleMicrophone: toggleWatchMicrophone,
      toggleCamera: toggleWatchCamera,
      setMirrorSelfView: setWatchMirrorSelfView,
      setVideoQuality: setWatchVideoQuality,
      setFrameRatePreference: setWatchFrameRatePreference,
      refreshDevices: refreshWatchMediaDevices,
      applySettings: applyWatchSettings,
      applyOutputDevice: applyWatchOutputDevice,
      restartLocalCapture: restartWatchLocalCaptureFromSettings,
      sync: syncWatchDeviceControls,
      getState: getWatchDeviceControlState
    };

    function escHtml(value) {
      return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }

    function initialsForName(value) {
      const parts = String(value || '').trim().split(/\s+/).filter(Boolean);
      if (!parts.length) return 'U';
      const first = parts[0].charAt(0) || '';
      const last = parts.length > 1 ? parts[parts.length - 1].charAt(0) : '';
      return (first + last).toUpperCase() || 'U';
    }

    function reactionEmoji(value) {
      const key = String(value || '').toLowerCase();
      if (key === 'like') return '&#128077;';
      if (key === 'love') return '<span class="reaction-love-heart" aria-hidden="true">&#10084;</span>';
      if (key === 'clap') return '&#129392;';
      if (key === 'wow') return '&#128558;';
      if (key === 'fire') return '&#128545;';
      return '&#128077;';
    }

    function reactionLabel(value) {
      const key = String(value || '').toLowerCase();
      if (key === 'like') return 'Like';
      if (key === 'love') return 'Love';
      if (key === 'clap') return 'Care';
      if (key === 'wow') return 'Wow';
      if (key === 'fire') return 'Angry';
      return 'Reaction';
    }

    function reactionBurstEmoji(value) {
      const key = String(value || '').toLowerCase();
      if (key === 'love') return '❤️';
      if (key === 'like') return '👍';
      if (key === 'clap') return '🥰';
      if (key === 'wow') return '😮';
      if (key === 'fire') return '😡';
      return '👍';
    }

    function spawnWatchStageReaction(reaction, index) {
      if (isEmbedMode || !watchStageReactions) return;
      const bubble = document.createElement('div');
      bubble.className = 'watch-stage-reaction';
      if (String(reaction || '').toLowerCase() === 'love') {
        bubble.classList.add('is-love');
      }
      bubble.textContent = reactionBurstEmoji(reaction);
      const horizontal = [0, 20, -18, 38, -30][Number(index || 0) % 5];
      const vertical = [78, 62, 48, 34, 18][Number(index || 0) % 5];
      bubble.style.right = Math.max(12, 34 + horizontal) + 'px';
      bubble.style.bottom = (60 + vertical) + 'px';
      watchStageReactions.appendChild(bubble);
      window.setTimeout(function() {
        bubble.remove();
      }, 5200);
    }

    function reactionTone(name) {
      const raw = String(name || '');
      let total = 0;
      for (let i = 0; i < raw.length; i += 1) total += raw.charCodeAt(i);
      return total % 360;
    }

    function friendActionMeta(status) {
      const value = String(status || 'none');
      if (value === 'friends' || value === 'self') return { label: '', disabled: true, className: 'is-static' };
      if (value === 'outgoing_pending') return { label: 'Request sent', disabled: true, className: 'is-static' };
      if (value === 'incoming_pending') return { label: 'Accept request', disabled: true, className: 'is-static' };
      return { label: 'Add friend', disabled: false, className: '' };
    }

    function syncWatchDescriptionPanel(live) {
      const currentLive = live || {};
      const nextTitle = String(currentLive.title || document.getElementById('watchStageTitle').textContent || 'Live room');
      const nextDescription = String(currentLive.description || document.getElementById('watchStageText').textContent || 'Join the room, follow the comments, and react in real time as this live session runs.');
      const nextMeta = String(currentLive.started_at_label || initialWatchStartedLabel || 'Now');
      if (watchDescriptionAvatar) watchDescriptionAvatar.textContent = initialWatchOwnerInitials || 'H';
      if (watchDescriptionTitle) watchDescriptionTitle.textContent = nextTitle;
      if (watchDescriptionSub) watchDescriptionSub.textContent = initialWatchOwnerName + ' • ' + nextMeta;
      if (watchDescriptionBody) watchDescriptionBody.textContent = nextDescription;
    }

    function syncWatchSidebarHeader() {
      if (watchSidebarMode === 'reactions') {
        if (watchSidebarTitleText) watchSidebarTitleText.textContent = 'Reactions';
        if (watchSidebarTitleCount) {
          const total = ['love', 'like', 'fire', 'wow', 'clap'].reduce(function(sum, key) {
            return sum + Number(watchReactionCounts[key] || 0);
          }, 0);
          watchSidebarTitleCount.textContent = String(total);
        }
        return;
      }
      if (watchSidebarMode === 'description') {
        if (watchSidebarTitleText) watchSidebarTitleText.textContent = 'Description';
        if (watchSidebarTitleCount) watchSidebarTitleCount.textContent = '';
        return;
      }
      if (watchSidebarMode === 'settings') {
        if (watchSidebarTitleText) watchSidebarTitleText.textContent = 'Settings';
        if (watchSidebarTitleCount) watchSidebarTitleCount.textContent = '';
        return;
      }
      if (watchSidebarTitleText) watchSidebarTitleText.textContent = 'Comments';
      if (watchSidebarTitleCount && commentCount) watchSidebarTitleCount.textContent = commentCount.textContent || '0';
    }

    function renderWatchReactionPanel() {
      if (!watchReactionTabs || !watchReactionList) return;
      const counts = watchReactionCounts || {};
      const tabOrder = ['all', 'like', 'love', 'wow', 'clap', 'fire'];
      const total = ['love', 'like', 'fire', 'wow', 'clap'].reduce(function(sum, key) {
        return sum + Number(counts[key] || 0);
      }, 0);
      watchReactionTabs.innerHTML = tabOrder.map(function(key) {
        const count = key === 'all' ? total : Number(counts[key] || 0);
        const label = key === 'all' ? 'All' : reactionEmoji(key) + ' ' + escHtml(String(count));
        return '<button type="button" class="watch-reaction-tab' + (watchReactionFilter === key ? ' is-active' : '') + '" data-reaction-filter="' + key + '">' + label + (key === 'all' ? (' <span>' + escHtml(String(count)) + '</span>') : '') + '</button>';
      }).join('');
      const filtered = watchReactionUsers.filter(function(item) {
        return watchReactionFilter === 'all' ? true : String(item.reaction || '') === watchReactionFilter;
      });
      if (!filtered.length) {
        watchReactionList.innerHTML = '<div class="watch-reaction-empty">No reactions yet.</div>';
        syncWatchSidebarHeader();
        return;
      }
      watchReactionList.innerHTML = filtered.map(function(item) {
        const name = escHtml(item.name || 'User');
        const initials = escHtml(initialsForName(item.name || 'User'));
        const tone = reactionTone(item.name || 'User');
        const action = friendActionMeta(item.friend_status);
        const actionHtml = action.label
          ? '<button type="button" class="watch-reaction-action ' + action.className + '" data-reactor-action="friend" data-user-id="' + Number(item.user_id || 0) + '"' + (action.disabled ? ' disabled' : '') + '><i class="fa fa-user-plus" aria-hidden="true"></i> ' + escHtml(action.label) + '</button>'
          : '';
        return '<div class="watch-reaction-item" data-reaction-user="' + Number(item.user_id || 0) + '">'
          + '<div class="watch-reaction-avatar" style="background:linear-gradient(135deg, hsl(' + tone + ' 80% 62%), hsl(' + ((tone + 38) % 360) + ' 78% 54%));">' + initials + '<span class="watch-reaction-badge">' + reactionEmoji(item.reaction) + '</span></div>'
          + '<div class="watch-reaction-main"><div class="watch-reaction-name">' + name + '</div><div class="watch-reaction-time"><span class="watch-reaction-type">' + escHtml(reactionLabel(item.reaction)) + '</span>' + escHtml(item.created_at_label || 'Now') + '</div></div>'
          + actionHtml
          + '</div>';
      }).join('');
      syncWatchSidebarHeader();
    }

    async function sendWatchFriendRequest(peerId) {
      const formData = new FormData();
      formData.append('peer_id', String(peerId));
      const response = await fetch('ajax/friend_action.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
      });
      const data = await response.json();
      if (!data || !data.ok) {
        throw new Error(data && data.message ? data.message : 'Unable to send friend request.');
      }
      watchReactionUsers = watchReactionUsers.map(function(item) {
        if (Number(item.user_id || 0) !== Number(peerId || 0)) return item;
        return Object.assign({}, item, { friend_status: String(data.status || 'outgoing_pending') });
      });
      renderWatchReactionPanel();
      return data;
    }

    function viewerJoinRequestMessage(status) {
      if (status === 'requested') {
        return 'Request sent. Waiting for the host to confirm or deny.';
      }
      if (status === 'approved' || status === 'initiated' || status === 'ringing' || status === 'active') {
        return 'The host confirmed your request. Connecting you to the live video.';
      }
      if (status === 'declined') {
        return 'The host denied your last request. You can send another request.';
      }
      return 'Tap Request when you want to join this live video with the host.';
    }

    function syncJoinRequestUi() {
      if (joinRequestStatusNode) {
        joinRequestStatusNode.textContent = viewerJoinRequestMessage(joinRequestStatus);
      }
      if (joinRequestButton) {
        const disabled = joinRequestStatus === 'requested' || joinRequestStatus === 'approved' || joinRequestStatus === 'initiated' || joinRequestStatus === 'ringing' || joinRequestStatus === 'active';
        joinRequestButton.disabled = disabled;
        joinRequestButton.textContent = joinRequestStatus === 'requested'
          ? 'Requested'
          : ((joinRequestStatus === 'approved' || joinRequestStatus === 'initiated' || joinRequestStatus === 'ringing' || joinRequestStatus === 'active') ? 'Connecting' : 'Request');
      }
    }

    function setImageSourceWhenReady(image, url, options) {
      if (!image || !url) return;
      const settings = options || {};
      const token = String(settings.token || Date.now());
      image.dataset.loadToken = token;
      const loader = new Image();
      loader.onload = function() {
        if (image.dataset.loadToken !== token) return;
        image.src = url;
        if (typeof settings.onLoaded === 'function') {
          settings.onLoaded();
        }
      };
      loader.onerror = function() {
        if (image.dataset.loadToken !== token) return;
        if (typeof settings.onError === 'function') {
          settings.onError();
        }
      };
      loader.src = url;
    }

    function bindGuestAudienceVideoHealth(guestId, video) {
      const id = Number(guestId || 0);
      if (!id || !video) return;
      const meta = guestAudiencePeerMeta[id] || (guestAudiencePeerMeta[id] = { createdAt: Date.now(), hasTrack: false });
      if (meta.boundVideo === video) {
        return;
      }
      meta.boundVideo = video;
      meta.lastCurrentTime = Number(video.currentTime || 0);
      meta.lastAdvanceAt = Date.now();
      const markProgress = function() {
        const currentTime = Number(video.currentTime || 0);
        if (currentTime > (meta.lastCurrentTime || 0) || video.readyState >= 2) {
          meta.lastCurrentTime = currentTime;
          meta.lastAdvanceAt = Date.now();
        }
      };
      video.addEventListener('playing', markProgress);
      video.addEventListener('timeupdate', markProgress);
      video.addEventListener('loadeddata', markProgress);
    }

    function stopGuestAudienceHealthLoop() {
      if (guestAudienceHealthTimer) {
        clearInterval(guestAudienceHealthTimer);
        guestAudienceHealthTimer = null;
      }
    }

    function syncGuestAudienceHealthLoop() {
      const hasTiles = !!(watchGuestAudienceLayer && watchGuestAudienceLayer.querySelector('[data-guest-audience]'));
      if (!isLiveActive || !hasTiles) {
        stopGuestAudienceHealthLoop();
        return;
      }
      if (guestAudienceHealthTimer) {
        return;
      }
      guestAudienceHealthTimer = window.setInterval(function() {
        normalizedAudienceGuests().forEach(function(item) {
          const guestId = Number(item.user_id || 0);
          const stream = guestAudienceStreams[guestId];
          const meta = guestAudiencePeerMeta[guestId];
          const video = watchGuestAudienceLayer
            ? watchGuestAudienceLayer.querySelector('[data-guest-audience="' + guestId + '"] .guest-audience-video')
            : null;
          if (!stream || !meta || !video) {
            return;
          }
          const age = Date.now() - Number(meta.lastAdvanceAt || meta.createdAt || 0);
          if (video.readyState >= 2 && age > guestAudienceFreezeGraceMs) {
            resetGuestAudiencePeer(guestId);
            renderApprovedGuestTiles();
            startGuestAudienceRtc(guestId);
          }
        });
      }, 3000);
    }

    function tuneOutgoingSender(sender, kind) {
      if (!sender || !sender.track || typeof sender.getParameters !== 'function' || typeof sender.setParameters !== 'function') {
        return;
      }
      const params = sender.getParameters() || {};
      if (!params.encodings || !params.encodings.length) {
        params.encodings = [{}];
      }
      const encoding = params.encodings[0];
      const activeGuestCount = Math.min(maxActiveGuests, approvedGuests.length || 0);
      if (kind === 'guest-publish') {
        encoding.maxBitrate = activeGuestCount >= 6 ? 160000 : 350000;
        encoding.maxFramerate = activeGuestCount >= 6 ? 8 : 12;
        encoding.scaleResolutionDownBy = activeGuestCount >= 6 ? 2.5 : 1.5;
      } else if (kind === 'viewer-return') {
        encoding.maxBitrate = activeGuestCount >= 6 ? 120000 : 250000;
        encoding.maxFramerate = activeGuestCount >= 6 ? 7 : 10;
        encoding.scaleResolutionDownBy = activeGuestCount >= 6 ? 3 : 2;
      }
      sender.setParameters(params).catch(function() {});
    }

    function guestPublishProfile() {
      return currentWatchCaptureProfile();
    }

    function syncGuestMediaProfile() {
      if (localGuestStream && localGuestStream.getVideoTracks) {
        const videoTrack = localGuestStream.getVideoTracks()[0];
        if (videoTrack && typeof videoTrack.applyConstraints === 'function') {
          const profile = guestPublishProfile();
          videoTrack.contentHint = 'motion';
          videoTrack.applyConstraints({
            width: { ideal: profile.width },
            height: { ideal: profile.height },
            frameRate: { ideal: profile.frameRate, max: profile.frameRate }
          }).catch(function() {});
        }
      }
      if (remotePc && typeof remotePc.getSenders === 'function') {
        remotePc.getSenders().forEach(function(sender) {
          tuneOutgoingSender(sender, 'viewer-return');
        });
      }
      if (guestPublishPc && typeof guestPublishPc.getSenders === 'function') {
        guestPublishPc.getSenders().forEach(function(sender) {
          tuneOutgoingSender(sender, 'guest-publish');
        });
      }
    }

    function markWebRtcReady(isReady) {
      webRtcReady = !!isReady;
      watchStageScreen.classList.toggle('has-webrtc', !!isReady);
      if (isReady) {
        watchStageScreen.classList.remove('has-snapshot');
        if (watchStageVideo && watchStageVideo.srcObject) {
          watchStageVideo.play().catch(function() {});
        }
      }
      syncSnapshotPolling();
    }

    function markStageVideoProgress() {
      if (!watchStageVideo || isOwnerView) {
        return;
      }
      const currentTime = Number(watchStageVideo.currentTime || 0);
      if (currentTime > stageVideoLastCurrentTime || watchStageVideo.readyState >= 2) {
        stageVideoLastCurrentTime = currentTime;
        stageVideoLastAdvanceAt = Date.now();
      }
    }

    function stopStageVideoHealthLoop() {
      if (stageVideoHealthTimer) {
        clearInterval(stageVideoHealthTimer);
        stageVideoHealthTimer = null;
      }
      stageVideoRecoveryBusy = false;
    }

    function recoverStageVideoIfFrozen() {
      if (stageVideoRecoveryBusy || isOwnerView || !isLiveActive || usesSnapshotOnlyGuestStage()) {
        return;
      }
      stageVideoRecoveryBusy = true;
      markWebRtcReady(false);
      resetRemotePeer();
      window.setTimeout(function() {
        stageVideoRecoveryBusy = false;
        startViewerRtc().catch(function() {});
      }, 120);
    }

    function syncStageVideoHealthLoop() {
      const hasRemoteTrack = !!(remoteStream && typeof remoteStream.getVideoTracks === 'function' && remoteStream.getVideoTracks().some(function(track) {
        return track.readyState === 'live';
      }));
      if (isOwnerView || usesSnapshotOnlyGuestStage() || !isLiveActive || !hasRemoteTrack || !watchStageVideo) {
        stopStageVideoHealthLoop();
        return;
      }
      if (stageVideoHealthTimer) {
        return;
      }
      markStageVideoProgress();
      stageVideoHealthTimer = window.setInterval(function() {
        if (!watchStageVideo || document.visibilityState === 'hidden') {
          return;
        }
        const hasLiveTrack = !!(remoteStream && typeof remoteStream.getVideoTracks === 'function' && remoteStream.getVideoTracks().some(function(track) {
          return track.readyState === 'live';
        }));
        if (!hasLiveTrack) {
          return;
        }
        if (watchStageVideo.srcObject && watchStageVideo.paused && !watchStageVideo.ended) {
          watchStageVideo.play().catch(function() {});
        }
        const age = Date.now() - Number(stageVideoLastAdvanceAt || 0);
        if (age > stageVideoFreezeGraceMs && (webRtcReady || hasLiveTrack)) {
          recoverStageVideoIfFrozen();
        }
      }, 2500);
    }

    function usesSnapshotOnlyGuestStage() {
      return false;
    }

    function rtcGuestIds() {
      const ids = approvedGuests
        .map(function(item) {
          return Number(item.user_id || 0);
        })
        .filter(Boolean);
      const liveLimit = ids.length > 4 ? 3 : 4;
      if (ids.length <= liveLimit) {
        return ids;
      }
      return ids.slice(0, liveLimit);
    }

    function normalizedAudienceGuests() {
      const seenGuestIds = new Set();
      return approvedGuests.filter(function(item) {
        const guestId = Number(item.user_id || 0);
        if (!guestId || guestId === viewerId || guestId === ownerId || seenGuestIds.has(guestId)) {
          return false;
        }
        seenGuestIds.add(guestId);
        return true;
      });
    }

    function usesSnapshotAudienceGrid() {
      return normalizedAudienceGuests()
        .map(function(item) {
          return Number(item.user_id || 0);
        }).length >= 8;
    }

    function liveAudienceGuestIds() {
      if (usesSnapshotAudienceGrid()) {
        return [];
      }
      return rtcGuestIds().filter(function(id) {
        return id > 0 && id !== viewerId && id !== ownerId;
      });
    }

    function viewerShouldPublishRtc() {
      return rtcGuestIds().includes(viewerId);
    }

    function rebuildPeerKey() {
      peerKeyNonce += 1;
      peerKey = 'live-' + String(liveId) + '-viewer-' + String(viewerId) + '-peer-' + String(peerKeyNonce) + '-inst-' + viewerInstanceToken;
    }

    function rebuildGuestPeerKey() {
      guestPeerKeyNonce += 1;
      guestPeerKey = 'live-' + String(liveId) + '-guest-' + String(viewerId) + '-peer-' + String(guestPeerKeyNonce) + '-inst-' + viewerInstanceToken;
    }

    rebuildPeerKey();
    rebuildGuestPeerKey();

    if (watchStageVideo) {
      watchStageVideo.addEventListener('playing', function() {
        if (!isOwnerView) {
          markStageVideoProgress();
          markWebRtcReady(true);
        }
      });
      watchStageVideo.addEventListener('loadeddata', function() {
        if (!isOwnerView && watchStageVideo.readyState >= 2 && watchStageVideo.videoWidth > 0) {
          markStageVideoProgress();
          markWebRtcReady(true);
        }
      });
      watchStageVideo.addEventListener('timeupdate', markStageVideoProgress);
      watchStageVideo.addEventListener('canplay', markStageVideoProgress);
      watchStageVideo.addEventListener('waiting', function() {
        if (!isOwnerView && watchStageVideo.srcObject) {
          watchStageVideo.play().catch(function() {});
        }
      });
      watchStageVideo.addEventListener('stalled', function() {
        if (!isOwnerView) {
          recoverStageVideoIfFrozen();
        }
      });
      watchStageVideo.addEventListener('emptied', function() {
        if (!isOwnerView) {
          markWebRtcReady(false);
        }
      });
    }

    function esc(value) {
      return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }

    function setFeedback(message, kind) {
      feedback.textContent = message || '';
      feedback.className = 'feedback' + (kind ? ' ' + kind : '');
    }

    function commentTone(name) {
      const raw = String(name || '');
      let total = 0;
      for (let i = 0; i < raw.length; i += 1) total += raw.charCodeAt(i);
      return total % 360;
    }

    function renderPayload(data) {
      const live = data.live || {};
      const comments = Array.isArray(data.comments) ? data.comments : [];
      const counts = data.reaction_counts || {};
      let total = 0;

      document.getElementById('watchStageTitle').textContent = live.title || 'Live room';
      const watchModalTitle = document.getElementById('watchModalTitle');
      if (watchModalTitle) {
        watchModalTitle.textContent = live.title || 'Live room';
      }
      document.getElementById('watchStageText').textContent = live.description || 'Join the room, follow the comments, and react in real time as this live session runs.';
      syncWatchDescriptionPanel(live);
      document.getElementById('watchStageStatus').textContent = String(live.status || 'draft').toUpperCase();
      document.getElementById('watchStageMeta').textContent = live.started_at_label || '';
      viewerCount.textContent = String(live.viewer_count || 0);
      if (watchTopViewerCount) {
        watchTopViewerCount.textContent = String(live.viewer_count || 0);
      }
      if (watchDockViewerCount) {
        watchDockViewerCount.textContent = String(live.viewer_count || 0);
      }
      isLiveActive = String(live.status || '').toLowerCase() === 'live';
      watchHostCameraEnabled = live.camera_enabled !== false;
      joinRequestStatus = String(data.join_request_status || '');
      approvedGuests = Array.isArray(data.approved_guests) ? data.approved_guests.slice(0, maxActiveGuests) : [];
      syncWatchHostStageVisibility();
      syncGuestMediaProfile();
      if (usesSnapshotOnlyGuestStage()) {
        resetRemotePeer(false);
      }
      refreshSnapshot(live);
      renderApprovedGuestTiles();
      syncGuestAudienceRefreshLoop();
      syncSnapshotPolling();
      syncViewerRtcLoop();
      syncStageVideoHealthLoop();
      const liveGuestIds = liveAudienceGuestIds();
      const snapshotAudienceGrid = usesSnapshotAudienceGrid();
      normalizedAudienceGuests().forEach(function(item) {
        const guestId = Number(item.user_id || 0);
        if (snapshotAudienceGrid) {
          if (guestAudiencePeers[guestId] || guestAudienceStreams[guestId]) {
            resetGuestAudiencePeer(guestId);
          }
          return;
        }
        if (!liveGuestIds.includes(guestId)) {
          if (guestAudiencePeers[guestId] || guestAudienceStreams[guestId]) {
            resetGuestAudiencePeer(guestId);
          }
          return;
        }
        if (guestAudiencePeerNeedsRestart(guestId)) {
          resetGuestAudiencePeer(guestId);
        }
        if (!guestAudienceStreams[guestId] && !guestAudiencePeers[guestId]) {
          startGuestAudienceRtc(guestId);
        }
      });
      syncGuestJoinFlow();

      ['love', 'like', 'fire', 'wow', 'clap'].forEach((key) => {
        const count = Number(counts[key] || 0);
        total += count;
        const countNode = document.querySelector('[data-reaction-count="' + key + '"]');
        const btn = document.querySelector('[data-reaction="' + key + '"]');
        if (countNode) countNode.textContent = String(count);
        if (btn) btn.classList.toggle('active', String(data.my_reaction || '') === key);
      });

      ['love', 'like', 'fire', 'wow', 'clap'].forEach(function(key) {
        const nextCount = Number(counts[key] || 0);
        const prevCount = Number(watchLastReactionCounts[key] || 0);
        if (!isEmbedMode && watchStageInitialized && nextCount > prevCount) {
          const burst = Math.min(nextCount - prevCount, 3);
          for (let i = 0; i < burst; i += 1) {
            window.setTimeout(function() {
              spawnWatchStageReaction(key, i);
            }, i * 220);
          }
        }
        watchLastReactionCounts[key] = nextCount;
      });
      watchStageInitialized = true;

      reactionTotal.textContent = String(total);
      commentCount.textContent = String(comments.length);
      const watchShareCount = document.getElementById('shareCount');
      if (watchShareCount) {
        watchShareCount.textContent = String(live.share_count || 0);
      }
      if (watchSidebarTitleCount && watchSidebarMode !== 'reactions' && watchSidebarMode !== 'description') {
        watchSidebarTitleCount.textContent = String(comments.length);
      }
      if (watchBottomReactionCount) {
        watchBottomReactionCount.textContent = String(total);
      }
      if (watchBottomCommentCount) {
        watchBottomCommentCount.textContent = String(comments.length);
      }
      watchReactionCounts = counts;
      watchReactionUsers = Array.isArray(data.reaction_users) ? data.reaction_users : [];
      renderWatchReactionPanel();
      syncJoinRequestUi();

      const shouldStickToBottom = (commentList.scrollHeight - commentList.scrollTop - commentList.clientHeight) <= 48;
      if (!comments.length) {
        commentList.innerHTML = '<div class="comment"><div class="comment-avatar">LR</div><div class="comment-main"><div class="comment-author">Live Room</div><div class="comment-body">Comments will appear here when the host or viewers post to this room.</div></div></div>';
      } else {
        commentList.innerHTML = comments.map(function(item) {
          const isSelf = Number(item.user_id || 0) === viewerId;
          const author = esc(item.author);
          const body = esc(item.body);
          const initials = escHtml(initialsForName(item.author || 'User'));
          const meta = esc(item.created_at_label || 'Now');
          const tone = commentTone(item.author || 'User');
          const likeCount = Number(item.like_count || 0);
          const likedByLabel = esc(item.liked_by_label || '');
          return '<div class="comment' + (isSelf ? ' is-self' : '') + '" data-comment-id="' + Number(item.id || 0) + '" data-comment-author="' + author + '">'
            + '<div class="comment-avatar" style="background:linear-gradient(135deg, hsl(' + tone + ' 80% 62%), hsl(' + ((tone + 38) % 360) + ' 78% 54%));">' + initials + '</div>'
            + '<div class="comment-main">'
            + '<div class="comment-author">' + author + (isSelf ? ' <span class="is-self">You</span>' : '') + '</div>'
            + '<div class="comment-body">' + body + '</div>'
            + '<div class="comment-meta"><span>' + meta + '</span><button type="button" class="comment-reply">Reply</button><button type="button" class="comment-like' + (item.liked_by_me ? ' is-liked' : '') + '" aria-label="Like comment" title="' + likedByLabel + '"><i class="fa fa-heart-o" aria-hidden="true"></i>' + (likeCount > 0 ? ('<span class="comment-like-count">' + likeCount + '</span>') : '') + '</button></div>'
            + (likedByLabel ? ('<div class="comment-likes">' + likedByLabel + '</div>') : '')
            + '</div></div>';
        }).join('');
      }
      if (shouldStickToBottom) {
        commentList.scrollTop = commentList.scrollHeight;
      }
    }

    function refreshSnapshot(live) {
      if (usesSnapshotOnlyGuestStage()) {
        watchStageScreen.classList.remove('has-snapshot');
        watchStageImage.removeAttribute('src');
        delete watchStageImage.dataset.loadToken;
        return;
      }
      if (webRtcReady || (isOwnerView && !isEmbedMode)) {
        watchStageScreen.classList.remove('has-snapshot');
        return;
      }
      const nextVersion = String((live && live.snapshot_version) || '');
      const isLive = String((live && live.status) || '').toLowerCase() === 'live';

      if (!isLive || nextVersion === '') {
        snapshotVersion = '';
        watchStageScreen.classList.remove('has-snapshot');
        watchStageImage.removeAttribute('src');
        return;
      }

      if (nextVersion === snapshotVersion && watchStageImage.getAttribute('src')) {
        watchStageScreen.classList.add('has-snapshot');
        return;
      }

      snapshotVersion = nextVersion;
      stageSnapshotLoadToken += 1;
      setImageSourceWhenReady(
        watchStageImage,
        'ajax/live_snapshot.php?live=' + encodeURIComponent(String(liveId)) + '&t=' + encodeURIComponent(String(snapshotVersion)),
        {
          token: 'stage-' + String(stageSnapshotLoadToken),
          onLoaded: function() {
            watchStageScreen.classList.add('has-snapshot');
          },
          onError: function() {
            watchStageScreen.classList.remove('has-snapshot');
          }
        }
      );
    }

    function renderApprovedGuestTiles() {
      if (!watchGuestAudienceLayer) return;
      const liveGuestIds = liveAudienceGuestIds();
      const visibleGuests = sortApprovedGuestsForStage(normalizedAudienceGuests(), liveGuestIds);
      const visibleIds = visibleGuests.map(function(item) {
        return Number(item.user_id || 0);
      });

      Array.from(watchGuestAudienceLayer.querySelectorAll('[data-guest-audience]')).forEach(function(tile) {
        const tileId = Number(tile.getAttribute('data-guest-audience') || 0);
        if (!visibleIds.includes(tileId)) {
          delete approvedGuestSnapshotVersions[tileId];
          delete approvedGuestSnapshotUrls[tileId];
          resetGuestAudiencePeer(tileId);
          tile.remove();
        }
      });

      visibleGuests.forEach(function(item) {
        const guestId = Number(item.user_id || 0);
        const version = String(item.snapshot_version || '');
        const name = String(item.name || 'Guest');
        const cameraEnabled = item.camera_enabled !== false;
        const preferSnapshots = !liveGuestIds.includes(guestId);
        const useSnapshots = cameraEnabled && preferSnapshots;
        const snapshotUrl = version !== ''
          ? 'ajax/live_snapshot.php?live=' + encodeURIComponent(String(liveId))
            + '&guest_user_id=' + encodeURIComponent(String(guestId))
            + '&t=' + encodeURIComponent(String(version))
            + (useSnapshots ? '&r=' + encodeURIComponent(String(Date.now())) : '')
          : '';
        let tile = watchGuestAudienceLayer.querySelector('[data-guest-audience="' + guestId + '"]');
        if (!tile) {
          tile = document.createElement('div');
          tile.className = 'guest-audience-tile';
          tile.setAttribute('data-guest-audience', String(guestId));
          tile.innerHTML = '<video class="guest-audience-video" autoplay playsinline muted></video>'
            + '<img class="guest-audience-image" alt="">'
            + '<div class="guest-audience-placeholder"><div class="guest-audience-placeholder-badge"></div></div>'
            + '<div class="guest-audience-camera-off" aria-hidden="true"><div class="guest-audience-camera-off-icon" aria-hidden="true"><i class="fa fa-video-camera"></i></div></div>'
            + '<div class="guest-audience-meta"></div>';
          watchGuestAudienceLayer.appendChild(tile);
        }

        const video = tile.querySelector('.guest-audience-video');
        const image = tile.querySelector('.guest-audience-image');
        const meta = tile.querySelector('.guest-audience-meta');
        const placeholderBadge = tile.querySelector('.guest-audience-placeholder-badge');
        const stream = (!cameraEnabled || preferSnapshots) ? null : (guestAudienceStreams[guestId] || null);
        tile.classList.toggle('is-camera-off', !cameraEnabled);
        tile.classList.toggle('has-webrtc', !!stream && !preferSnapshots && cameraEnabled);
        if (placeholderBadge) {
          placeholderBadge.textContent = initialsForName(name);
        }
        if (video) {
          video.muted = true;
          video.defaultMuted = true;
          video.setAttribute('muted', 'muted');
          bindGuestAudienceVideoHealth(guestId, video);
          if (stream && video.srcObject !== stream) {
            video.srcObject = stream;
          } else if (!stream && video.srcObject) {
            video.pause();
            video.srcObject = null;
          }
          if (stream) {
            video.play().catch(function() {});
          }
        }
        if (image) {
          image.alt = name;
          if (cameraEnabled && version !== '' && snapshotUrl !== '' && (approvedGuestSnapshotVersions[guestId] !== version || approvedGuestSnapshotUrls[guestId] !== snapshotUrl)) {
            setImageSourceWhenReady(image, snapshotUrl, {
              token: 'guest-' + String(guestId) + '-' + String(Date.now()),
              onLoaded: function() {
                tile.classList.add('snapshot-ready');
                approvedGuestSnapshotVersions[guestId] = version;
                approvedGuestSnapshotUrls[guestId] = snapshotUrl;
              },
              onError: function() {
                tile.classList.remove('snapshot-ready');
              }
            });
          } else if (version === '' || !cameraEnabled) {
            delete approvedGuestSnapshotVersions[guestId];
            delete approvedGuestSnapshotUrls[guestId];
            image.removeAttribute('src');
            delete image.dataset.loadToken;
            tile.classList.remove('snapshot-ready');
          }
        }
        if (meta) {
          meta.textContent = !cameraEnabled
            ? (name + ' camera off')
            : (stream ? (name + ' joined live') : (preferSnapshots ? (name + ' live') : (name + ' connecting...')));
        }
      });
      syncDualStageLayout();
      syncGuestAudienceHealthLoop();
    }

    function sortApprovedGuestsForStage(guests, liveGuestIds) {
      const liveIds = Array.isArray(liveGuestIds) ? liveGuestIds : [];
      return (Array.isArray(guests) ? guests : [])
        .map(function(item, index) {
          const guestId = Number(item.user_id || 0);
          const hasLiveStream = liveIds.includes(guestId) && !!guestAudienceStreams[guestId];
          const hasSnapshot = String(item.snapshot_version || '') !== '';
          return {
            item: item,
            index: index,
            priority: hasLiveStream ? 0 : (hasSnapshot ? 1 : 2)
          };
        })
        .sort(function(a, b) {
          if (a.priority !== b.priority) {
            return a.priority - b.priority;
          }
          return a.index - b.index;
        })
        .map(function(entry) {
          return entry.item;
        });
    }

    function refreshGuestAudienceFrames() {
      if (!watchGuestAudienceLayer || !isLiveActive) {
        return;
      }
      Array.from(watchGuestAudienceLayer.querySelectorAll('[data-guest-audience]')).forEach(function(tile) {
        if (tile.classList.contains('has-webrtc')) {
          return;
        }
        const guestId = Number(tile.getAttribute('data-guest-audience') || 0);
        const version = approvedGuestSnapshotVersions[guestId] || '';
        if (!guestId || !version) {
          return;
        }
        const image = tile.querySelector('.guest-audience-image');
        if (!image) {
          return;
        }
        const refreshUrl = 'ajax/live_snapshot.php?live=' + encodeURIComponent(String(liveId))
          + '&guest_user_id=' + encodeURIComponent(String(guestId))
          + '&t=' + encodeURIComponent(String(version))
          + '&r=' + encodeURIComponent(String(Date.now()));
        setImageSourceWhenReady(image, refreshUrl, {
          token: 'guest-refresh-' + String(guestId) + '-' + String(Date.now()),
          onLoaded: function() {
            tile.classList.add('snapshot-ready');
            approvedGuestSnapshotUrls[guestId] = refreshUrl;
          },
          onError: function() {
            tile.classList.remove('snapshot-ready');
          }
        });
      });
    }

    function stopGuestAudienceRefreshLoop() {
      if (guestAudienceRefreshTimer) {
        clearInterval(guestAudienceRefreshTimer);
        guestAudienceRefreshTimer = null;
      }
    }

    function guestAudienceRefreshInterval() {
      const tileCount = watchGuestAudienceLayer ? watchGuestAudienceLayer.children.length : 0;
      if (tileCount >= 3) return 1800;
      if (tileCount === 2) return 1200;
      return 700;
    }

    function syncGuestAudienceRefreshLoop() {
      const needsSnapshotRefresh = !!(watchGuestAudienceLayer && Array.from(watchGuestAudienceLayer.children).some(function(tile) {
        return !tile.classList.contains('has-webrtc');
      }));
      if (!isLiveActive || !watchGuestAudienceLayer || !needsSnapshotRefresh) {
        stopGuestAudienceRefreshLoop();
        return;
      }
      if (guestAudienceRefreshTimer) {
        return;
      }
      refreshGuestAudienceFrames();
      guestAudienceRefreshTimer = window.setInterval(refreshGuestAudienceFrames, guestAudienceRefreshInterval());
    }

    function syncDualStageLayout() {
      if (!watchStageScreen) return;
      const audienceCount = watchGuestAudienceLayer ? watchGuestAudienceLayer.children.length : 0;
      const selfCount = (watchGuestSelfTile && watchGuestSelfTile.classList.contains('is-active')) ? 1 : 0;
      const guestCount = audienceCount + selfCount;
      const stageClasses = ['has-dual-stage', 'has-three-stage', 'has-three-stage-self', 'has-four-stage', 'has-four-stage-self', 'has-five-stage', 'has-five-stage-self', 'has-six-stage', 'has-six-stage-self', 'has-seven-stage', 'has-seven-stage-self', 'has-eight-stage', 'has-eight-stage-self', 'has-nine-stage', 'has-nine-stage-self', 'has-ten-stage', 'has-ten-stage-self', 'has-eleven-stage', 'has-eleven-stage-self', 'has-twelve-stage', 'has-twelve-stage-self', 'has-thirteen-stage', 'has-thirteen-stage-self', 'has-fourteen-stage', 'has-fourteen-stage-self', 'has-fifteen-stage', 'has-fifteen-stage-self', 'has-sixteen-stage', 'has-sixteen-stage-self', 'has-seventeen-stage', 'has-seventeen-stage-self', 'has-eighteen-stage', 'has-eighteen-stage-self', 'has-nineteen-stage', 'has-nineteen-stage-self', 'has-twenty-stage', 'has-twenty-stage-self', 'has-twentyone-stage', 'has-twentyone-stage-self', 'has-twentytwo-stage', 'has-twentytwo-stage-self', 'has-twentythree-stage', 'has-twentythree-stage-self', 'has-twentyfour-stage', 'has-twentyfour-stage-self', 'has-twentyfive-stage', 'has-twentyfive-stage-self', 'has-twentyplus-stage', 'has-twentyplus-stage-self', 'has-gallery-stage', 'has-host-layout', 'has-host-layout-self'];
      const normalStageLayoutByGuestCount = {
        1: 'has-dual-stage',
        2: 'has-three-stage',
        3: 'has-four-stage',
        4: 'has-five-stage',
        5: 'has-six-stage',
        6: 'has-seven-stage',
        7: 'has-eight-stage',
        8: 'has-nine-stage',
        9: 'has-ten-stage',
        10: 'has-eleven-stage',
        11: 'has-twelve-stage',
        12: 'has-thirteen-stage',
        13: 'has-fourteen-stage',
        14: 'has-fifteen-stage',
        15: 'has-sixteen-stage',
        16: 'has-seventeen-stage',
        17: 'has-eighteen-stage',
        18: 'has-nineteen-stage',
        19: 'has-twenty-stage',
        20: 'has-twentyone-stage',
        21: 'has-twentytwo-stage',
        22: 'has-twentythree-stage',
        23: 'has-twentyfour-stage',
        24: 'has-twentyplus-stage',
        25: 'has-twentyplus-stage',
        26: 'has-twentyplus-stage',
        27: 'has-twentyplus-stage',
        28: 'has-twentyplus-stage',
        29: 'has-twentyplus-stage'
      };
      const selfStageLayoutByGuestCount = {
        1: 'has-dual-stage',
        2: 'has-three-stage-self',
        3: 'has-four-stage-self',
        4: 'has-five-stage-self',
        5: 'has-six-stage-self',
        6: 'has-seven-stage-self',
        7: 'has-eight-stage-self',
        8: 'has-nine-stage-self',
        9: 'has-ten-stage-self',
        10: 'has-eleven-stage-self',
        11: 'has-twelve-stage-self',
        12: 'has-thirteen-stage-self',
        13: 'has-fourteen-stage-self',
        14: 'has-fifteen-stage-self',
        15: 'has-sixteen-stage-self',
        16: 'has-seventeen-stage-self',
        17: 'has-eighteen-stage-self',
        18: 'has-nineteen-stage-self',
        19: 'has-twenty-stage-self',
        20: 'has-twentyone-stage-self',
        21: 'has-twentytwo-stage-self',
        22: 'has-twentythree-stage-self',
        23: 'has-twentyfour-stage-self',
        24: 'has-twentyplus-stage-self',
        25: 'has-twentyplus-stage-self',
        26: 'has-twentyplus-stage-self',
        27: 'has-twentyplus-stage-self',
        28: 'has-twentyplus-stage-self',
        29: 'has-twentyplus-stage-self'
      };
      watchStageScreen.classList.remove(...stageClasses);
      if (guestCount <= 0) return;
      const layoutClass = selfCount === 1 ? selfStageLayoutByGuestCount[guestCount] : normalStageLayoutByGuestCount[guestCount];
      if (layoutClass) {
        watchStageScreen.classList.add(layoutClass);
        return;
      }
      watchStageScreen.classList.add('has-host-layout');
      if (selfCount === 1) {
        watchStageScreen.classList.add('has-host-layout-self');
      }
    }

    function pullSnapshotFrame() {
      if (!isLiveActive || webRtcReady || (isOwnerView && !isEmbedMode)) {
        return;
      }
      stageSnapshotLoadToken += 1;
      setImageSourceWhenReady(
        watchStageImage,
        'ajax/live_snapshot.php?live=' + encodeURIComponent(String(liveId)) + '&t=' + encodeURIComponent(String(Date.now())),
        {
          token: 'stage-refresh-' + String(stageSnapshotLoadToken),
          onLoaded: function() {
            watchStageScreen.classList.add('has-snapshot');
          }
        }
      );
    }

    function syncSnapshotPolling() {
      if (!isLiveActive || webRtcReady || (isOwnerView && !isEmbedMode)) {
        if (snapshotPollTimer) {
          clearInterval(snapshotPollTimer);
          snapshotPollTimer = null;
        }
        return;
      }

      if (snapshotPollTimer) {
        return;
      }

      pullSnapshotFrame();
      snapshotPollTimer = window.setInterval(pullSnapshotFrame, usesSnapshotOnlyGuestStage() ? 1400 : 850);
    }

    async function startOwnerLocalPreview() {
      if (!isOwnerView || isEmbedMode || !isLiveActive || localOwnerStream || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        return;
      }
      try {
        const profile = currentWatchCaptureProfile();
        const videoConstraints = {
          facingMode: 'user',
          width: { ideal: profile.width },
          height: { ideal: profile.height },
          frameRate: { ideal: profile.frameRate, max: Math.max(profile.frameRate, 30) }
        };
        if (watchSelectedCameraDeviceId) {
          videoConstraints.deviceId = { exact: watchSelectedCameraDeviceId };
          delete videoConstraints.facingMode;
        }
        const audioConstraints = {
          echoCancellation: true,
          noiseSuppression: true,
          autoGainControl: true
        };
        if (watchSelectedMicDeviceId) {
          audioConstraints.deviceId = { exact: watchSelectedMicDeviceId };
        }
        localOwnerStream = await navigator.mediaDevices.getUserMedia({
          video: videoConstraints,
          audio: audioConstraints
        });
        watchStageVideo.muted = true;
        watchStageVideo.defaultMuted = true;
        watchStageVideo.setAttribute('muted', 'muted');
        watchStageVideo.srcObject = localOwnerStream;
        applyWatchOutputDevice(watchSelectedSpeakerDeviceId).catch(function() {});
        await watchStageVideo.play().catch(() => {});
        watchStageScreen.classList.add('has-webrtc');
        watchStageScreen.classList.remove('has-snapshot');
        webRtcReady = true;
        syncWatchDeviceControls();
      } catch (error) {
        webRtcReady = false;
      }
    }

    function resetRemotePeer(rebuildKey = true) {
      if (remoteDisconnectTimer) {
        clearTimeout(remoteDisconnectTimer);
        remoteDisconnectTimer = null;
      }
      if (remotePc) {
        try { remotePc.close(); } catch (error) {}
        remotePc = null;
      }
      remoteStream = null;
      remoteVideoTransceiver = null;
      remoteAudioTransceiver = null;
      webRtcReady = false;
      offerInFlight = false;
      stageVideoLastCurrentTime = 0;
      stageVideoLastAdvanceAt = 0;
      watchStageScreen.classList.remove('has-webrtc');
      if (watchStageVideo) {
        watchStageVideo.srcObject = null;
      }
      if (rebuildKey) {
        rebuildPeerKey();
      }
    }

    async function startGuestPublishing() {
      if (isOwnerView || !isLiveActive || joinRequestStatus !== 'approved' || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        return;
      }
      if (localGuestStream && watchGuestSelfTile && watchGuestSelfTile.classList.contains('is-active')) {
        if (watchGuestSelfVideo && watchGuestSelfVideo.srcObject !== localGuestStream) {
          watchGuestSelfVideo.srcObject = localGuestStream;
          await watchGuestSelfVideo.play().catch(() => {});
        }
        syncDualStageLayout();
        syncGuestSnapshotLoop();
        syncGuestPublishRtcLoop();
        persistGuestCameraEnabled();
        return;
      }
      if (!localGuestStream) {
        const profile = currentWatchCaptureProfile();
        const videoConstraints = {
          facingMode: 'user',
          width: { ideal: profile.width },
          height: { ideal: profile.height },
          frameRate: { ideal: profile.frameRate, max: Math.max(profile.frameRate, 30) }
        };
        if (watchSelectedCameraDeviceId) {
          videoConstraints.deviceId = { exact: watchSelectedCameraDeviceId };
          delete videoConstraints.facingMode;
        }
        const audioConstraints = {
          echoCancellation: true,
          noiseSuppression: true,
          autoGainControl: true
        };
        if (watchSelectedMicDeviceId) {
          audioConstraints.deviceId = { exact: watchSelectedMicDeviceId };
        }
        localGuestStream = await navigator.mediaDevices.getUserMedia({
          video: videoConstraints,
          audio: audioConstraints
        });
      }
      if (watchGuestSelfVideo) {
        if (watchGuestSelfVideo.srcObject !== localGuestStream) {
          watchGuestSelfVideo.muted = true;
          watchGuestSelfVideo.setAttribute('muted', 'muted');
          watchGuestSelfVideo.srcObject = localGuestStream;
        }
        applyWatchOutputDevice(watchSelectedSpeakerDeviceId).catch(function() {});
        await watchGuestSelfVideo.play().catch(() => {});
      }
      if (watchGuestSelfTile) {
        watchGuestSelfTile.classList.add('is-active');
      }
      syncDualStageLayout();
      syncGuestSnapshotLoop();
      syncWatchDeviceControls();
      persistGuestCameraEnabled();
      if (usesSnapshotOnlyGuestStage()) {
        stopGuestPublishRtcLoop();
        resetGuestPublishPeer();
      } else {
        syncGuestPublishRtcLoop();
      }
    }

    function stopGuestPublishing() {
      if (guestPublishPc && ownerId > 0) {
        sendGuestSignal('bye', {}).catch(function() {});
      }
      stopGuestPublishRtcLoop();
      resetGuestPublishPeer();
      if (localGuestStream) {
        localGuestStream.getTracks().forEach(function(track) {
          track.stop();
        });
        localGuestStream = null;
      }
      if (watchGuestSelfVideo) {
        watchGuestSelfVideo.pause();
        watchGuestSelfVideo.srcObject = null;
      }
      if (watchGuestSelfTile) {
        watchGuestSelfTile.classList.remove('is-active');
      }
      syncDualStageLayout();
      stopGuestSnapshotLoop();
      syncWatchDeviceControls();
      persistGuestCameraEnabled();
    }

    async function uploadGuestSnapshotFrame() {
      if (guestSnapshotBusy) return;
      if (!isLiveActive || joinRequestStatus !== 'approved' || !localGuestStream || !watchGuestSelfVideo) {
        return;
      }
      if (watchGuestSelfVideo.readyState < 2 || !watchGuestSelfVideo.videoWidth || !watchGuestSelfVideo.videoHeight) {
        return;
      }

      guestSnapshotBusy = true;
      try {
        const maxWidth = 720;
        const scale = watchGuestSelfVideo.videoWidth > maxWidth ? (maxWidth / watchGuestSelfVideo.videoWidth) : 1;
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(watchGuestSelfVideo.videoWidth * scale));
        canvas.height = Math.max(1, Math.round(watchGuestSelfVideo.videoHeight * scale));
        const ctx = canvas.getContext('2d');
        if (!ctx) {
          return;
        }
        ctx.drawImage(watchGuestSelfVideo, 0, 0, canvas.width, canvas.height);
        const blob = await new Promise(function(resolve) {
          canvas.toBlob(resolve, 'image/jpeg', 0.88);
        });
        if (!blob) {
          return;
        }

        const formData = new FormData();
        formData.append('live_id', String(liveId));
        formData.append('guest_user_id', String(viewerId));
        formData.append('frame', blob, 'guest-frame.jpg');

        await fetch('ajax/live_snapshot.php', {
          method: 'POST',
          body: formData,
          credentials: 'same-origin'
        });
      } catch (error) {
        // keep guest snapshot upload silent
      } finally {
        guestSnapshotBusy = false;
      }
    }

    function stopGuestSnapshotLoop() {
      if (guestSnapshotTimer) {
        clearInterval(guestSnapshotTimer);
        guestSnapshotTimer = null;
      }
      guestSnapshotBusy = false;
    }

    function syncGuestSnapshotLoop() {
      if (!isLiveActive || joinRequestStatus !== 'approved' || !localGuestStream || viewerShouldPublishRtc()) {
        stopGuestSnapshotLoop();
        return;
      }
      if (guestSnapshotTimer) {
        return;
      }
      uploadGuestSnapshotFrame();
      guestSnapshotTimer = window.setInterval(uploadGuestSnapshotFrame, 1000);
    }

    async function syncGuestJoinFlow() {
      if (isOwnerView) {
        return;
      }
      if (joinRequestStatus === 'approved' && isLiveActive) {
        try {
          await startGuestPublishing();
          if (!viewerShouldPublishRtc()) {
            stopGuestPublishRtcLoop();
            resetGuestPublishPeer();
            syncGuestSnapshotLoop();
          }
        } catch (error) {
          setFeedback(error.message || 'Unable to join live with camera', 'error');
        }
        return;
      }

      if (localGuestStream) {
        stopGuestPublishing();
      }
    }

    async function sendSignal(signalType, payload) {
      return sendSignalForPeer(peerKey, signalType, payload);
    }

    async function sendSignalForPeer(targetPeerKey, signalType, payload) {
      const formData = new FormData();
      formData.append('live_id', String(liveId));
      formData.append('receiver_id', String(ownerId));
      formData.append('peer_key', targetPeerKey);
      formData.append('signal_type', signalType);
      formData.append('payload', JSON.stringify(payload || {}));
      const response = await fetch('ajax/live_signal.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
      });
      const data = await response.json();
      if (!data || !data.ok) {
        throw new Error(data && data.error ? data.error : 'Signal send failed');
      }
    }

    async function sendGuestSignal(signalType, payload) {
      const formData = new FormData();
      formData.append('live_id', String(liveId));
      formData.append('receiver_id', String(ownerId));
      formData.append('peer_key', guestPeerKey);
      formData.append('signal_type', signalType);
      formData.append('payload', JSON.stringify(payload || {}));
      const response = await fetch('ajax/live_signal.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
      });
      const data = await response.json();
      if (!data || !data.ok) {
        throw new Error(data && data.error ? data.error : 'Signal send failed');
      }
    }

    function ensureRemotePeer() {
      if (remotePc) {
        return remotePc;
      }

      remotePc = new RTCPeerConnection(rtcConfig);
      remoteStream = new MediaStream();
      watchStageVideo.muted = !watchMicEnabled;
      watchStageVideo.defaultMuted = !watchMicEnabled;
      if (watchMicEnabled) {
        watchStageVideo.removeAttribute('muted');
      } else {
        watchStageVideo.setAttribute('muted', 'muted');
      }
      watchStageVideo.srcObject = remoteStream;
      remoteVideoTransceiver = remotePc.addTransceiver('video', { direction: 'recvonly' });
      remoteAudioTransceiver = remotePc.addTransceiver('audio', { direction: 'recvonly' });

      if (localGuestStream) {
        localGuestStream.getTracks().forEach(function(track) {
          const sender = remotePc.addTrack(track, localGuestStream);
          tuneOutgoingSender(sender, 'viewer-return');
        });
      }

      remotePc.ontrack = function(event) {
        event.streams[0].getTracks().forEach(function(track) {
          const exists = remoteStream.getTracks().some(function(existing) {
            return existing.id === track.id;
          });
          if (!exists) {
            remoteStream.addTrack(track);
          }
          if (watchStageVideo) {
            watchStageVideo.play().catch(function() {});
          }
          markStageVideoProgress();
          markWebRtcReady(true);
          syncStageVideoHealthLoop();
          track.onmute = function() {
            if (!isOwnerView) {
              markWebRtcReady(false);
            }
          };
          track.onended = function() {
            if (!isOwnerView) {
              markWebRtcReady(false);
            }
          };
          track.onunmute = function() {
            if (!isOwnerView) {
              markStageVideoProgress();
              markWebRtcReady(true);
              syncStageVideoHealthLoop();
            }
          };
        });
      };

      remotePc.onicecandidate = function(event) {
        if (!event.candidate) {
          return;
        }
        sendSignal('candidate', event.candidate.toJSON()).catch(function() {});
      };

      remotePc.onconnectionstatechange = function() {
        const connectionState = String(remotePc.connectionState || '');
        if (connectionState === 'connected') {
          if (remoteDisconnectTimer) {
            clearTimeout(remoteDisconnectTimer);
            remoteDisconnectTimer = null;
          }
          return;
        }
        if (connectionState === 'disconnected') {
          if (remoteDisconnectTimer) {
            return;
          }
          remoteDisconnectTimer = window.setTimeout(function() {
            remoteDisconnectTimer = null;
            if (remotePc && String(remotePc.connectionState || '') === 'disconnected') {
              markWebRtcReady(false);
            }
          }, peerDisconnectGraceMs);
          return;
        }
        if (remoteDisconnectTimer) {
          clearTimeout(remoteDisconnectTimer);
          remoteDisconnectTimer = null;
        }
        if (connectionState === 'failed' || connectionState === 'closed') {
          markWebRtcReady(false);
        }
      };

      return remotePc;
    }

    function resetGuestPublishPeer(rebuildKey = true) {
      if (guestPublishDisconnectTimer) {
        clearTimeout(guestPublishDisconnectTimer);
        guestPublishDisconnectTimer = null;
      }
      if (guestPublishPc) {
        try { guestPublishPc.close(); } catch (error) {}
        guestPublishPc = null;
      }
      guestOfferInFlight = false;
      if (rebuildKey) {
        rebuildGuestPeerKey();
      }
    }

    function ensureGuestPublishPeer() {
      if (guestPublishPc) {
        return guestPublishPc;
      }
      guestPublishPc = new RTCPeerConnection(rtcConfig);
      if (localGuestStream) {
        localGuestStream.getTracks().forEach(function(track) {
          const sender = guestPublishPc.addTrack(track, localGuestStream);
          tuneOutgoingSender(sender, 'guest-publish');
        });
      }
      guestPublishPc.onicecandidate = function(event) {
        if (!event.candidate) {
          return;
        }
        sendGuestSignal('candidate', event.candidate.toJSON()).catch(function() {});
      };
      guestPublishPc.onconnectionstatechange = function() {
        const connectionState = String(guestPublishPc.connectionState || '');
        if (connectionState === 'connected') {
          if (guestPublishDisconnectTimer) {
            clearTimeout(guestPublishDisconnectTimer);
            guestPublishDisconnectTimer = null;
          }
          return;
        }
        if (connectionState === 'disconnected') {
          if (guestPublishDisconnectTimer) {
            return;
          }
          guestPublishDisconnectTimer = window.setTimeout(function() {
            guestPublishDisconnectTimer = null;
            if (guestPublishPc && String(guestPublishPc.connectionState || '') === 'disconnected') {
              guestOfferInFlight = false;
            }
          }, peerDisconnectGraceMs);
          return;
        }
        if (guestPublishDisconnectTimer) {
          clearTimeout(guestPublishDisconnectTimer);
          guestPublishDisconnectTimer = null;
        }
        if (connectionState === 'failed' || connectionState === 'closed') {
          guestOfferInFlight = false;
        }
      };
      return guestPublishPc;
    }

    function guestAudiencePeerKey(guestId) {
      return 'live-' + String(liveId) + '-viewer-' + String(viewerId) + '-guest-view-' + String(guestId) + '-peer-1-inst-' + viewerInstanceToken;
    }

    function activeSignalPeerKeys() {
      const keys = [peerKey];
      const guestIds = new Set();

      liveAudienceGuestIds().forEach(function(id) {
        const guestId = Number(id || 0);
        if (guestId > 0 && guestId !== viewerId) {
          guestIds.add(guestId);
        }
      });

      Object.keys(guestAudiencePeers).forEach(function(id) {
        const guestId = Number(id || 0);
        if (guestId > 0 && guestId !== viewerId) {
          guestIds.add(guestId);
        }
      });

      Object.keys(guestAudienceOfferInFlight).forEach(function(id) {
        const guestId = Number(id || 0);
        if (guestId > 0 && guestId !== viewerId && guestAudienceOfferInFlight[id]) {
          guestIds.add(guestId);
        }
      });

      Object.keys(guestAudienceStreams).forEach(function(id) {
        const guestId = Number(id || 0);
        if (guestId > 0 && guestId !== viewerId) {
          guestIds.add(guestId);
        }
      });

      guestIds.forEach(function(guestId) {
        keys.push(guestAudiencePeerKey(guestId));
      });

      return keys;
    }

    function guestAudiencePeerNeedsRestart(guestId) {
      const id = Number(guestId || 0);
      if (!id) return false;
      const pc = guestAudiencePeers[id];
      if (!pc) return true;
      const connectionState = String(pc.connectionState || '');
      if (connectionState === 'failed' || connectionState === 'closed') {
        return true;
      }
      if (guestAudienceStreams[id]) {
        return false;
      }
      const meta = guestAudiencePeerMeta[id];
      if (!meta || !meta.createdAt) {
        return false;
      }
      return (Date.now() - meta.createdAt) >= guestAudienceRetryGraceMs;
    }

    function resetGuestAudiencePeer(guestId) {
      const id = Number(guestId || 0);
      if (guestAudienceDisconnectTimers[id]) {
        clearTimeout(guestAudienceDisconnectTimers[id]);
        delete guestAudienceDisconnectTimers[id];
      }
      const pc = guestAudiencePeers[id];
      if (pc) {
        try { pc.close(); } catch (error) {}
      }
      delete guestAudiencePeers[id];
      delete guestAudienceStreams[id];
      delete guestAudienceOfferInFlight[id];
      delete guestAudiencePeerMeta[id];
    }

    function ensureGuestAudiencePeer(guestId) {
      const id = Number(guestId || 0);
      if (!id) return null;
      if (guestAudiencePeers[id]) {
        return guestAudiencePeers[id];
      }
      const pc = new RTCPeerConnection(rtcConfig);
      guestAudiencePeers[id] = pc;
      guestAudiencePeerMeta[id] = {
        createdAt: Date.now(),
        hasTrack: false
      };
      pc.addTransceiver('video', { direction: 'recvonly' });
      pc.ontrack = function(event) {
        const stream = event.streams && event.streams[0] ? event.streams[0] : null;
        if (!stream) return;
        guestAudienceStreams[id] = stream;
        if (guestAudiencePeerMeta[id]) {
          guestAudiencePeerMeta[id].hasTrack = true;
          guestAudiencePeerMeta[id].receivedAt = Date.now();
        }
        renderApprovedGuestTiles();
      };
      pc.onicecandidate = function(event) {
        if (!event.candidate) {
          return;
        }
        sendSignalForPeer(guestAudiencePeerKey(id), 'candidate', event.candidate.toJSON()).catch(function() {});
      };
      pc.onconnectionstatechange = function() {
        const value = String(pc.connectionState || '');
        if (value === 'connected') {
          if (guestAudienceDisconnectTimers[id]) {
            clearTimeout(guestAudienceDisconnectTimers[id]);
            delete guestAudienceDisconnectTimers[id];
          }
          return;
        }
        if (value === 'disconnected') {
          if (guestAudienceDisconnectTimers[id]) {
            return;
          }
          guestAudienceDisconnectTimers[id] = window.setTimeout(function() {
            delete guestAudienceDisconnectTimers[id];
            if (guestAudiencePeers[id] && String(guestAudiencePeers[id].connectionState || '') === 'disconnected') {
              resetGuestAudiencePeer(id);
              renderApprovedGuestTiles();
            }
          }, peerDisconnectGraceMs);
          return;
        }
        if (guestAudienceDisconnectTimers[id]) {
          clearTimeout(guestAudienceDisconnectTimers[id]);
          delete guestAudienceDisconnectTimers[id];
        }
        if (value === 'failed' || value === 'closed') {
          resetGuestAudiencePeer(id);
          renderApprovedGuestTiles();
        }
      };
      return pc;
    }

    async function handleSignal(signal) {
      const type = String(signal.signal_type || '');
      const payload = signal.payload || {};
      const signalPeerKey = String(signal.peer_key || '');
      if (signalPeerKey === guestPeerKey || (signalPeerKey.indexOf('-guest-') !== -1 && signalPeerKey.indexOf('-guest-view-') === -1)) {
        await handleGuestSignal(signal);
        return;
      }
      const guestViewMatch = signalPeerKey.match(/-guest-view-(\d+)-/);
      if (guestViewMatch) {
        const guestId = Number(guestViewMatch[1] || 0);
        const guestPc = ensureGuestAudiencePeer(guestId);
        if (!guestPc) return;
        if (type === 'answer') {
          if (!payload.sdp) return;
          await guestPc.setRemoteDescription(new RTCSessionDescription(payload));
          guestAudienceOfferInFlight[guestId] = false;
          return;
        }
        if (type === 'candidate') {
          if (!payload.candidate) return;
          try {
            await guestPc.addIceCandidate(new RTCIceCandidate(payload));
          } catch (error) {}
          return;
        }
        if (type === 'bye') {
          resetGuestAudiencePeer(guestId);
          renderApprovedGuestTiles();
        }
        return;
      }
      const pc = ensureRemotePeer();

      if (type === 'answer') {
        if (!payload.sdp) return;
        await pc.setRemoteDescription(new RTCSessionDescription(payload));
        offerInFlight = false;
        return;
      }

      if (type === 'candidate') {
        if (!payload.candidate) return;
        try {
          await pc.addIceCandidate(new RTCIceCandidate(payload));
        } catch (error) {
          // ignore late candidates
        }
        return;
      }

      if (type === 'bye') {
        if (remotePc) {
          remotePc.close();
          remotePc = null;
        }
        markWebRtcReady(false);
      }
    }

    async function handleGuestSignal(signal) {
      const type = String(signal.signal_type || '');
      const payload = signal.payload || {};
      const pc = ensureGuestPublishPeer();

      if (type === 'answer') {
        if (!payload.sdp) return;
        await pc.setRemoteDescription(new RTCSessionDescription(payload));
        guestOfferInFlight = false;
        return;
      }

      if (type === 'candidate') {
        if (!payload.candidate) return;
        try {
          await pc.addIceCandidate(new RTCIceCandidate(payload));
        } catch (error) {
          // ignore late candidates
        }
        return;
      }

      if (type === 'bye') {
        resetGuestPublishPeer(false);
      }
    }

    async function pollSignalsForPeer(targetPeerKey) {
      if (!targetPeerKey) {
        return;
      }
      const response = await fetch('ajax/live_signal.php?live_id=' + encodeURIComponent(String(liveId)) + '&peer_key=' + encodeURIComponent(String(targetPeerKey)), {
        credentials: 'same-origin',
        cache: 'no-store'
      });
      const data = await response.json();
      if (!data || !data.ok || !Array.isArray(data.signals)) {
        return;
      }
      for (const signal of data.signals) {
        await handleSignal(signal);
      }
    }

    async function pollSignals() {
      if (!isLiveActive) {
        return;
      }
      try {
        const keys = activeSignalPeerKeys();
        for (const key of keys) {
          await pollSignalsForPeer(key);
        }
      } catch (error) {
        // silent signaling poll
      }
    }

    async function pollGuestSignals() {
      if (!isLiveActive || !localGuestStream || joinRequestStatus !== 'approved') {
        return;
      }
      try {
        const response = await fetch('ajax/live_signal.php?live_id=' + encodeURIComponent(String(liveId)) + '&peer_key=' + encodeURIComponent(guestPeerKey), {
          credentials: 'same-origin',
          cache: 'no-store'
        });
        const data = await response.json();
        if (!data || !data.ok || !Array.isArray(data.signals)) {
          return;
        }
        for (const signal of data.signals) {
          await handleGuestSignal(signal);
        }
      } catch (error) {
        // silent signaling poll
      }
    }

    async function startViewerRtc() {
      if (!isLiveActive || offerInFlight || webRtcReady) {
        return;
      }
      if (!window.RTCPeerConnection) {
        return;
      }
      offerInFlight = true;
      try {
        const pc = ensureRemotePeer();
        if (!remoteVideoTransceiver) {
          remoteVideoTransceiver = pc.addTransceiver('video', { direction: 'recvonly' });
        }
        const offer = await pc.createOffer();
        await pc.setLocalDescription(offer);
        await sendSignal('offer', {
          type: offer.type,
          sdp: offer.sdp
        });
      } catch (error) {
        offerInFlight = false;
      }
    }

    async function startGuestPublishRtc() {
      if (!isLiveActive || joinRequestStatus !== 'approved' || !localGuestStream || guestOfferInFlight) {
        return;
      }
      if (!window.RTCPeerConnection) {
        return;
      }
      guestOfferInFlight = true;
      try {
        const pc = ensureGuestPublishPeer();
        const offer = await pc.createOffer();
        await pc.setLocalDescription(offer);
        await sendGuestSignal('offer', {
          type: offer.type,
          sdp: offer.sdp
        });
      } catch (error) {
        guestOfferInFlight = false;
      }
    }

    async function startGuestAudienceRtc(guestId) {
      const id = Number(guestId || 0);
      if (!id || !isLiveActive || guestAudienceOfferInFlight[id]) {
        return;
      }
      if (!window.RTCPeerConnection) {
        return;
      }
      guestAudienceOfferInFlight[id] = true;
      try {
        const pc = ensureGuestAudiencePeer(id);
        if (!pc) {
          guestAudienceOfferInFlight[id] = false;
          return;
        }
        const offer = await pc.createOffer();
        await pc.setLocalDescription(offer);
        await sendSignalForPeer(guestAudiencePeerKey(id), 'offer', {
          type: offer.type,
          sdp: offer.sdp
        });
      } catch (error) {
        guestAudienceOfferInFlight[id] = false;
      }
    }

    function stopViewerRtcLoop() {
      if (signalPollTimer) {
        clearInterval(signalPollTimer);
        signalPollTimer = null;
      }
      offerInFlight = false;
      webRtcReady = false;
      stopStageVideoHealthLoop();
    }

    function stopGuestPublishRtcLoop() {
      if (guestSignalPollTimer) {
        clearInterval(guestSignalPollTimer);
        guestSignalPollTimer = null;
      }
      guestOfferInFlight = false;
    }

    function syncViewerRtcLoop() {
      if (isOwnerView) {
        stopViewerRtcLoop();
        if (isEmbedMode) {
          syncSnapshotPolling();
        } else {
          startOwnerLocalPreview();
        }
        stopStageVideoHealthLoop();
        return;
      }
      if (preferSnapshotViewer) {
        stopViewerRtcLoop();
        resetRemotePeer(false);
        syncSnapshotPolling();
        stopStageVideoHealthLoop();
        return;
      }
      if (usesSnapshotOnlyGuestStage()) {
        stopViewerRtcLoop();
        resetRemotePeer(false);
        syncSnapshotPolling();
        stopStageVideoHealthLoop();
        return;
      }
      if (!window.RTCPeerConnection || !isLiveActive) {
        stopViewerRtcLoop();
        return;
      }
      if (signalPollTimer) {
        return;
      }
      startViewerRtc();
      pollSignals();
      syncStageVideoHealthLoop();
      signalPollTimer = window.setInterval(function() {
        pollSignals();
        if (!webRtcReady) {
          startViewerRtc();
        }
      }, 1000);
    }

    function syncGuestPublishRtcLoop() {
      if (isOwnerView || !viewerShouldPublishRtc() || usesSnapshotOnlyGuestStage() || !window.RTCPeerConnection || !isLiveActive || joinRequestStatus !== 'approved' || !localGuestStream) {
        stopGuestPublishRtcLoop();
        resetGuestPublishPeer(false);
        return;
      }
      if (guestSignalPollTimer) {
        return;
      }
      startGuestPublishRtc();
      pollGuestSignals();
      guestSignalPollTimer = window.setInterval(function() {
        pollGuestSignals();
        if (localGuestStream && !guestPublishPc) {
          startGuestPublishRtc();
        }
      }, 1000);
    }

    async function pollRoom() {
      try {
        const response = await fetch('ajax/live_watch_room.php?live=' + encodeURIComponent(String(liveId)), {
          credentials: 'same-origin',
          cache: 'no-store'
        });
        const data = await response.json();
        if (data && data.ok) {
          renderPayload(data);
        }
      } catch (error) {
        // silent polling
      }
    }

    async function postRoom(action, extra) {
      const formData = new FormData();
      formData.append('action', action);
      formData.append('live_id', String(liveId));
      Object.keys(extra || {}).forEach(function(key) {
        formData.append(key, extra[key]);
      });
      const response = await fetch('ajax/live_watch_room.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
      });
      const data = await response.json();
      if (!data || !data.ok) {
        throw new Error(data && data.error ? data.error : 'Request failed');
      }
      renderPayload(data);
      return data;
    }

    async function toggleCommentLike(commentId) {
      return postRoom('toggle_comment_like', { comment_id: String(commentId) });
    }

    function leaveLivePresence() {
      if (navigator.sendBeacon) {
        const formData = new FormData();
        formData.append('action', 'leave_view');
        formData.append('live_id', String(liveId));
        navigator.sendBeacon('ajax/live_watch_room.php', formData);
        return;
      }
      fetch('ajax/live_watch_room.php', {
        method: 'POST',
        credentials: 'same-origin',
        keepalive: true,
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'Accept': 'application/json'
        },
        body: 'action=leave_view&live_id=' + encodeURIComponent(String(liveId))
      }).catch(function() {});
    }

    sendCommentButton.addEventListener('click', async function() {
      const body = commentInput.value.trim();
      if (!body) {
        setFeedback('Type a comment before sending.', 'error');
        return;
      }
      try {
        await postRoom('send_comment', { comment_body: body });
        commentInput.value = '';
        setFeedback('');
      } catch (error) {
        setFeedback(error.message || 'Unable to send comment.', 'error');
      }
    });

    if (commentInput) {
      commentInput.addEventListener('keydown', function(event) {
        if (event.key === 'Enter' && !event.shiftKey) {
          event.preventDefault();
          sendCommentButton.click();
        }
      });
    }

    if (commentList) {
      commentList.addEventListener('click', function(event) {
        const replyButton = event.target.closest('.comment-reply');
        if (replyButton) {
          const comment = replyButton.closest('[data-comment-author]');
          const author = comment ? String(comment.getAttribute('data-comment-author') || '').trim() : '';
          if (commentInput) {
            const prefix = author ? ('@' + author + ' ') : '';
            commentInput.value = prefix;
            commentInput.focus();
            try {
              commentInput.setSelectionRange(commentInput.value.length, commentInput.value.length);
            } catch (error) {}
          }
          return;
        }

        const likeButton = event.target.closest('.comment-like');
        if (likeButton) {
          const comment = likeButton.closest('[data-comment-id]');
          const commentId = Number(comment ? comment.getAttribute('data-comment-id') || 0 : 0);
          if (commentId > 0) {
            toggleCommentLike(commentId).catch(function(error) {
              setFeedback(error.message || 'Unable to update comment like.', 'error');
            });
          }
        }
      });
    }

    if (joinRequestButton) {
      joinRequestButton.addEventListener('click', async function() {
        try {
          await postRoom('request_join', {});
          setFeedback('Join request sent.', 'success');
        } catch (error) {
          setFeedback(error.message || 'Unable to send request.', 'error');
        }
      });
    }

    if (watchChatToggle) {
      watchChatToggle.addEventListener('click', function() {
        if (!watchFrame) return;
        const isChatOpen = watchFrame.classList.contains('has-chat') && watchSidebarMode === 'chat';
        setWatchSidebarMode(isChatOpen ? '' : 'chat');
      });
    }

    if (watchMicToggle) {
      watchMicToggle.addEventListener('click', function() {
        toggleWatchMicrophone();
      });
    }

    if (watchCameraToggle) {
      watchCameraToggle.addEventListener('click', function() {
        toggleWatchCamera();
      });
    }

    if (watchSettingsToggle) {
      watchSettingsToggle.addEventListener('click', function() {
        if (!watchFrame) return;
        const isSettingsOpen = watchFrame.classList.contains('has-chat') && watchSidebarMode === 'settings';
        setWatchSidebarMode(isSettingsOpen ? '' : 'settings');
        if (!isSettingsOpen) {
          refreshWatchMediaDevices().catch(function() {});
        }
      });
    }

    if (watchSettingsCameraDevice) {
      watchSettingsCameraDevice.addEventListener('change', async function() {
        watchSelectedCameraDeviceId = String(watchSettingsCameraDevice.value || '');
        await restartWatchLocalCaptureFromSettings().catch(function() {});
        syncWatchDeviceControls();
      });
    }

    if (watchSettingsMicDevice) {
      watchSettingsMicDevice.addEventListener('change', async function() {
        watchSelectedMicDeviceId = String(watchSettingsMicDevice.value || '');
        await restartWatchLocalCaptureFromSettings().catch(function() {});
        syncWatchDeviceControls();
      });
    }

    if (watchSettingsSpeakerDevice) {
      watchSettingsSpeakerDevice.addEventListener('change', async function() {
        await applyWatchOutputDevice(String(watchSettingsSpeakerDevice.value || '')).catch(function() {});
        syncWatchDeviceControls();
      });
    }

    if (watchSettingsAudio) {
      watchSettingsAudio.addEventListener('change', function() {
        toggleWatchMicrophone(!!watchSettingsAudio.checked);
      });
    }

    if (watchSettingsCameraEnabled) {
      watchSettingsCameraEnabled.addEventListener('change', function() {
        toggleWatchCamera(!!watchSettingsCameraEnabled.checked);
      });
    }

    if (watchSettingsMirror) {
      watchSettingsMirror.addEventListener('change', function() {
        setWatchMirrorSelfView(!!watchSettingsMirror.checked);
      });
    }

    if (watchSettingsQuality) {
      watchSettingsQuality.addEventListener('change', function() {
        setWatchVideoQuality(watchSettingsQuality.value);
      });
    }

    if (watchSettingsFrameRate) {
      watchSettingsFrameRate.addEventListener('change', function() {
        setWatchFrameRatePreference(watchSettingsFrameRate.value);
      });
    }

    if (watchReactionToggle) {
      watchReactionToggle.addEventListener('click', function() {
        if (!watchFrame) return;
        const isReactionOpen = watchFrame.classList.contains('has-chat') && watchSidebarMode === 'reactions';
        setWatchSidebarMode(isReactionOpen ? '' : 'reactions');
      });
    }

    if (watchDescriptionToggle) {
      watchDescriptionToggle.addEventListener('click', function() {
        if (!watchFrame) return;
        const isDescriptionOpen = watchFrame.classList.contains('has-chat') && watchSidebarMode === 'description';
        setWatchSidebarMode(isDescriptionOpen ? '' : 'description');
      });
    }

    if (watchSpeakerButton) {
      watchSpeakerButton.addEventListener('click', function() {
        setWatchSidebarOpen(false);
      });
    }

    if (watchEndButton) {
      watchEndButton.addEventListener('click', function() {
        leaveLivePresence();
        if (window.top && window.top !== window) {
          window.location.href = 'about:blank';
          return;
        }
        if (document.referrer) {
          window.history.back();
          return;
        }
        window.location.href = 'feed.php';
      });
    }

    if (watchEndButtonTop) {
      watchEndButtonTop.addEventListener('click', function() {
        if (watchEndButton) {
          watchEndButton.click();
        }
      });
    }

    syncWatchDeviceControls();
    refreshWatchMediaDevices().catch(function() {});

    if (watchSidebarClose) {
      watchSidebarClose.addEventListener('click', function() {
        setWatchSidebarMode('');
      });
    }

    if (watchReactionTabs) {
      watchReactionTabs.addEventListener('click', function(event) {
        const tab = event.target.closest('[data-reaction-filter]');
        if (!tab) return;
        watchReactionFilter = String(tab.getAttribute('data-reaction-filter') || 'all');
        renderWatchReactionPanel();
      });
    }

    if (watchReactionList) {
      watchReactionList.addEventListener('click', function(event) {
        const actionButton = event.target.closest('[data-reactor-action="friend"][data-user-id]');
        if (!actionButton || actionButton.disabled) return;
        const peerId = Number(actionButton.getAttribute('data-user-id') || 0);
        if (peerId <= 0) return;
        sendWatchFriendRequest(peerId).catch(function(error) {
          setFeedback(error.message || 'Unable to send friend request.', 'error');
        });
      });
    }

    reactionButtons.forEach(function(button) {
      button.addEventListener('click', async function() {
        try {
          await postRoom('react_live', { reaction: button.getAttribute('data-reaction') || '' });
          setFeedback('Reaction updated.', 'success');
        } catch (error) {
          setFeedback(error.message || 'Unable to update reaction.', 'error');
        }
      });
    });

    if (watchShareButton) {
      watchShareButton.addEventListener('click', async function() {
        try {
          await postRoom('share_live', {});
          setFeedback('Live shared.', 'success');
        } catch (error) {
          setFeedback(error.message || 'Unable to share live.', 'error');
        }
      });
    }

    syncWatchDescriptionPanel({
      title: <?php echo json_encode($title !== '' ? $title : 'Live room'); ?>,
      description: <?php echo json_encode((string)($live['description'] ?? 'Join the room, follow the comments, and react in real time as this live session runs.')); ?>,
      started_at_label: initialWatchStartedLabel
    });
    pollRoom();
    setWatchSidebarMode('chat');
    syncJoinRequestUi();
    syncSnapshotPolling();
    pollTimer = window.setInterval(pollRoom, 2500);
    syncViewerRtcLoop();
    window.addEventListener('beforeunload', function() {
      if (pollTimer) clearInterval(pollTimer);
      if (snapshotPollTimer) clearInterval(snapshotPollTimer);
      if (signalPollTimer) clearInterval(signalPollTimer);
      if (stageVideoHealthTimer) clearInterval(stageVideoHealthTimer);
      if (guestSignalPollTimer) clearInterval(guestSignalPollTimer);
      if (guestSnapshotTimer) clearInterval(guestSnapshotTimer);
      if (guestAudienceRefreshTimer) clearInterval(guestAudienceRefreshTimer);
      if (guestAudienceHealthTimer) clearInterval(guestAudienceHealthTimer);
      leaveLivePresence();
      if (!isOwnerView && ownerId > 0) {
        navigator.sendBeacon('ajax/live_signal.php', (function() {
          const formData = new FormData();
          formData.append('live_id', String(liveId));
          formData.append('receiver_id', String(ownerId));
          formData.append('peer_key', peerKey);
          formData.append('signal_type', 'bye');
          formData.append('payload', '{}');
          return formData;
        })());
        if (localGuestStream) {
          navigator.sendBeacon('ajax/live_signal.php', (function() {
            const formData = new FormData();
            formData.append('live_id', String(liveId));
            formData.append('receiver_id', String(ownerId));
            formData.append('peer_key', guestPeerKey);
            formData.append('signal_type', 'bye');
            formData.append('payload', '{}');
            return formData;
          })());
        }
      }
      if (remotePc) {
        remotePc.close();
      }
      Object.keys(guestAudiencePeers).forEach(function(key) {
        resetGuestAudiencePeer(Number(key));
      });
      if (guestPublishPc) {
        guestPublishPc.close();
      }
      if (localOwnerStream) {
        localOwnerStream.getTracks().forEach(function(track) {
          track.stop();
        });
      }
      if (localGuestStream) {
        localGuestStream.getTracks().forEach(function(track) {
          track.stop();
        });
      }
    });
    window.addEventListener('pagehide', function() {
      leaveLivePresence();
    });
  </script>
  <?php endif; ?>
</body>
</html>
