<?php
declare(strict_types=1);

$gearLogoutAllOn = 1;
if (isset($profileSettings) && is_array($profileSettings) && array_key_exists('allow_logout_all_devices', $profileSettings) && $profileSettings['allow_logout_all_devices'] !== null && $profileSettings['allow_logout_all_devices'] !== '') {
    $gearLogoutAllOn = (int)$profileSettings['allow_logout_all_devices'];
}
?>
<div class="gear-edit-form">
  <div class="gear-edit-head">
    <div>
      <h2 class="gear-edit-title">Logout all devices</h2>
      <p class="gear-edit-sub">Sign out every other active browser session. This device stays signed in.</p>
    </div>
  </div>

  <section class="gear-edit-card">
    <h3>Confirm</h3>
    <?php if (!$gearLogoutAllOn): ?>
      <p class="gear-edit-save-state is-error">Logout all devices is turned off in Gear. Turn on Allow logout all devices first.</p>
    <?php else: ?>
      <p class="gear-edit-muted">Other browsers and phones signed into this account will be signed out immediately. You stay signed in here.</p>
      <form class="gear-logout-all-form" method="post" action="account_tools.php">
        <input type="hidden" name="ajax" value="logout_all">
        <input type="hidden" name="action" value="logout_all">
        <div class="gear-logout-actions">
          <button class="gear-edit-save" type="submit">Logout all other devices now</button>
        </div>
      </form>
      <p class="gear-edit-save-state" id="gearLogoutAllState" aria-live="polite"></p>
    <?php endif; ?>
  </section>
</div>
