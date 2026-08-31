<?php
declare(strict_types=1);

include __DIR__ . '/security_center_load.php';
$scs = $securityCenterSettings ?? [];
$scc = $securityCenterCounts ?? ['blocked' => 0, 'hidden' => 0, 'muted' => 0, 'reports' => 0];
$scr = $securityCenterReports ?? [];
?>
<div class="gear-edit-form gear-safety-form">
  <div class="gear-edit-head">
    <div>
      <h2 class="gear-edit-title">Safety Center</h2>
      <p class="gear-edit-sub">Blocked, hidden, muted, report history, and login safety for this account.</p>
    </div>
  </div>

  <section class="gear-edit-card">
    <h3>Current safety</h3>
    <div class="gear-safety-stats">
      <div class="gear-safety-stat">
        <span>Blocked users</span>
        <b><?php echo (int)($scc['blocked'] ?? 0); ?></b>
        <small><?php echo !empty($scs['blocked_users_enabled']) ? 'System enabled' : 'System hidden in Gear'; ?></small>
      </div>
      <div class="gear-safety-stat">
        <span>Hidden users</span>
        <b><?php echo (int)($scc['hidden'] ?? 0); ?></b>
        <small><?php echo !empty($scs['hidden_users_enabled']) ? 'System enabled' : 'System hidden in Gear'; ?></small>
      </div>
      <div class="gear-safety-stat">
        <span>Muted users</span>
        <b><?php echo (int)($scc['muted'] ?? 0); ?></b>
        <small><?php echo !empty($scs['mute_users_enabled']) ? 'System enabled' : 'System hidden in Gear'; ?></small>
      </div>
      <div class="gear-safety-stat">
        <span>Report history</span>
        <b><?php echo (int)($scc['reports'] ?? 0); ?></b>
        <small><?php echo !empty($scs['report_history_enabled']) ? 'System enabled' : 'System hidden in Gear'; ?></small>
      </div>
    </div>
  </section>

  <section class="gear-edit-card">
    <h3>Login / security</h3>
    <div class="gear-safety-list">
      <div class="gear-safety-row">
        <div>
          <strong>Change password</strong>
          <small>Update the password for this account.</small>
        </div>
        <button type="button" class="gear-edit-save" data-gear-open-pane="change_password">Open</button>
      </div>
      <div class="gear-safety-row">
        <div>
          <strong>Manage devices</strong>
          <small>See active sessions and revoke one device at a time.</small>
        </div>
        <button type="button" class="gear-edit-save" data-gear-open-pane="manage_devices">Open</button>
      </div>
      <div class="gear-safety-row">
        <div>
          <strong>Logout now</strong>
          <small>Signs out of this browser session immediately.</small>
        </div>
        <button type="button" class="gear-edit-save" data-gear-open-pane="logout_now">Logout</button>
      </div>
      <div class="gear-safety-row">
        <div>
          <strong>Logout all devices</strong>
          <small>Sign out other browsers. Delete, export, and deactivate stay in Danger Zone.</small>
        </div>
        <button type="button" class="gear-edit-save" data-gear-open-pane="logout_all_devices">Open</button>
      </div>
    </div>
  </section>

  <?php if (!empty($scs['report_history_enabled'])): ?>
  <section class="gear-edit-card">
    <h3>Your report history</h3>
    <div class="gear-safety-list">
      <?php if (!$scr): ?>
        <div class="gear-safety-row">
          <div>
            <strong>No reports yet</strong>
            <small>When you report something, it will show up here.</small>
          </div>
        </div>
      <?php else: foreach ($scr as $r): ?>
        <div class="gear-safety-row">
          <div>
            <strong><?php echo h(ucfirst((string)($r['target_type'] ?? 'item'))); ?> #<?php echo (int)($r['target_id'] ?? 0); ?></strong>
            <small>
              Reason: <?php echo h((string)($r['reason'] ?? 'other')); ?>
              · Status: <?php echo h((string)($r['status'] ?? 'pending')); ?>
              · <?php echo h((string)($r['created_at'] ?? '')); ?>
            </small>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </section>
  <?php endif; ?>
</div>
