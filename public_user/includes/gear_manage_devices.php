<?php
declare(strict_types=1);

if (!function_exists('gear_device_short_ua')) {
    function gear_device_short_ua(string $ua): string
    {
        $ua = trim($ua);
        if ($ua === '') {
            return 'Unknown browser/device';
        }
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($ua) > 90 ? mb_substr($ua, 0, 90) . '…' : $ua;
        }
        return strlen($ua) > 90 ? substr($ua, 0, 90) . '…' : $ua;
    }
}

$gearDeviceSid = (string)session_id();
$gearDeviceAllow = 1;
$gearDeviceError = '';
$gearDeviceHasTable = function_exists('userSessionTableExists') && isset($dbh) && $dbh instanceof PDO
    ? userSessionTableExists($dbh)
    : false;
$gearDeviceSessions = [];
$gearDeviceOwner = (int)($meId ?? 0);

if (isset($profileSettings) && is_array($profileSettings) && array_key_exists('allow_logout_all_devices', $profileSettings) && $profileSettings['allow_logout_all_devices'] !== null && $profileSettings['allow_logout_all_devices'] !== '') {
    $gearDeviceAllow = (int)$profileSettings['allow_logout_all_devices'];
} elseif (isset($dbh) && $dbh instanceof PDO && $gearDeviceOwner > 0) {
    try {
        $st = $dbh->prepare('SELECT allow_logout_all_devices FROM user_profile_settings WHERE user_id = :uid LIMIT 1');
        $st->execute([':uid' => $gearDeviceOwner]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        if (array_key_exists('allow_logout_all_devices', $row) && $row['allow_logout_all_devices'] !== null) {
            $gearDeviceAllow = (int)$row['allow_logout_all_devices'];
        }
    } catch (Throwable $e) {
    }
}

if (!$gearDeviceAllow) {
    $gearDeviceError = 'Manage devices is hidden in Gear because Allow logout all devices is turned off.';
}

if ($gearDeviceHasTable && $gearDeviceOwner > 0) {
    try {
        $st = $dbh->prepare('SELECT id, php_session_id, ip_address, user_agent, created_at, last_seen_at, revoked_at FROM user_sessions WHERE user_id = :uid ORDER BY (revoked_at IS NULL) DESC, last_seen_at DESC, created_at DESC LIMIT 50');
        $st->execute([':uid' => $gearDeviceOwner]);
        $gearDeviceSessions = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $gearDeviceError = $gearDeviceError !== '' ? $gearDeviceError : 'Could not load device sessions.';
    }
}

$gearDeviceActive = 0;
foreach ($gearDeviceSessions as $s) {
    if (empty($s['revoked_at'])) {
        $gearDeviceActive++;
    }
}
?>
<div class="gear-edit-form gear-devices-form" id="gearManageDevicesInner">
  <div class="gear-edit-head">
    <div>
      <h2 class="gear-edit-title">Manage devices</h2>
      <p class="gear-edit-sub">Review current and recent sessions, then sign out one device or every other device.</p>
    </div>
  </div>

  <?php if ($gearDeviceError !== ''): ?>
    <p class="gear-edit-save-state is-error"><?php echo h($gearDeviceError); ?></p>
  <?php endif; ?>
  <p class="gear-edit-save-state" id="gearManageDevicesState" aria-live="polite"></p>

  <?php if (!$gearDeviceHasTable): ?>
    <section class="gear-edit-card">
      <p class="gear-edit-muted">The user_sessions table was not found. Run sql_user_sessions.sql first, then reload.</p>
    </section>
  <?php else: ?>
    <section class="gear-edit-card">
      <h3>Overview</h3>
      <div class="gear-safety-stats">
        <div class="gear-safety-stat">
          <span>Current device</span>
          <b>1</b>
          <small>This browser stays signed in unless you log out.</small>
        </div>
        <div class="gear-safety-stat">
          <span>Active sessions</span>
          <b><?php echo (int)$gearDeviceActive; ?></b>
          <small>Sessions with no revoke time.</small>
        </div>
        <div class="gear-safety-stat">
          <span>Tracked sessions</span>
          <b><?php echo count($gearDeviceSessions); ?></b>
          <small>Recent active and revoked sessions.</small>
        </div>
      </div>
      <?php if ($gearDeviceAllow): ?>
        <form class="gear-device-form" method="post" action="manage_devices.php" data-device-action="revoke_others">
          <input type="hidden" name="ajax" value="revoke_others">
          <input type="hidden" name="action" value="revoke_others">
          <button class="gear-edit-save" type="submit">Logout all other devices</button>
        </form>
      <?php endif; ?>
    </section>

    <section class="gear-edit-card">
      <h3>Your device sessions</h3>
      <div class="gear-safety-list">
        <?php if (!$gearDeviceSessions): ?>
          <div class="gear-safety-row">
            <div>
              <strong>No device sessions yet</strong>
              <small>New sign-ins will show up here.</small>
            </div>
          </div>
        <?php endif; ?>
        <?php foreach ($gearDeviceSessions as $row): ?>
          <?php
            $isCurrent = ((string)($row['php_session_id'] ?? '') === $gearDeviceSid);
            $isActive = empty($row['revoked_at']);
          ?>
          <div class="gear-safety-row gear-device-row">
            <div>
              <strong><?php echo h(gear_device_short_ua((string)($row['user_agent'] ?? ''))); ?></strong>
              <small>
                <?php echo $isActive ? 'Active' : 'Revoked'; ?><?php echo $isCurrent ? ' · Current device' : ''; ?>
                · IP <?php echo h((string)($row['ip_address'] ?? '') !== '' ? (string)$row['ip_address'] : 'Unknown'); ?>
                · Last active <?php echo h((string)($row['last_seen_at'] ?? '') !== '' ? (string)$row['last_seen_at'] : '—'); ?>
              </small>
            </div>
            <?php if ($isCurrent): ?>
              <span class="gear-edit-muted">This device</span>
            <?php elseif ($isActive && $gearDeviceAllow): ?>
              <form class="gear-device-form" method="post" action="manage_devices.php" data-device-action="revoke_one">
                <input type="hidden" name="ajax" value="revoke_one">
                <input type="hidden" name="action" value="revoke_one">
                <input type="hidden" name="session_id" value="<?php echo (int)$row['id']; ?>">
                <button class="gear-edit-save" type="submit">Logout</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>
</div>
