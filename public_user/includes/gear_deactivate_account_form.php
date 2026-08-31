<?php
declare(strict_types=1);

$gearDeactivateOn = 1;
if (isset($profileSettings) && is_array($profileSettings) && array_key_exists('allow_deactivate_account', $profileSettings) && $profileSettings['allow_deactivate_account'] !== null && $profileSettings['allow_deactivate_account'] !== '') {
    $gearDeactivateOn = (int)$profileSettings['allow_deactivate_account'];
}
?>
<form id="gearDeactivateAccountForm" class="gear-edit-form" action="account_tools.php" method="post">
  <input type="hidden" name="ajax" value="deactivate">
  <input type="hidden" name="action" value="deactivate">
  <div class="gear-edit-head">
    <div>
      <h2 class="gear-edit-title">Deactivate account</h2>
      <p class="gear-edit-sub">Temporarily close this account. You will be signed out. Posts and data are kept.</p>
    </div>
    <?php if ($gearDeactivateOn): ?>
      <button class="gear-edit-save" type="submit">Deactivate now</button>
    <?php endif; ?>
  </div>

  <section class="gear-edit-card">
    <h3>Confirm</h3>
    <?php if (!$gearDeactivateOn): ?>
      <p class="gear-edit-save-state is-error">Deactivate account is turned off in Gear. Turn on Allow deactivate account first.</p>
    <?php else: ?>
      <div class="gear-edit-fields">
        <div class="gear-edit-field">
          <label for="gear-deactivate-confirm">Type DEACTIVATE</label>
          <input id="gear-deactivate-confirm" name="confirm_text" type="text" placeholder="DEACTIVATE" autocomplete="off" required>
        </div>
      </div>
      <p class="gear-edit-muted">The profile is paused and hidden from normal use. You can return after support reactivates you, or by signing in if status is restored.</p>
      <p class="gear-edit-save-state" id="gearDeactivateAccountState" aria-live="polite"></p>
    <?php endif; ?>
  </section>
</form>
