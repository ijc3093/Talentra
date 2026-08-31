<?php
declare(strict_types=1);
?>
<form id="gearResetSettingsForm" class="gear-edit-form" action="account_tools.php" method="post">
  <input type="hidden" name="ajax" value="reset_settings">
  <input type="hidden" name="action" value="reset_settings">
  <div class="gear-edit-head">
    <div>
      <h2 class="gear-edit-title">Reset account settings</h2>
      <p class="gear-edit-sub">Restores Gear privacy, notifications, and appearance to defaults. Posts, friends, and media are not deleted.</p>
    </div>
    <button class="gear-edit-save" type="submit">Reset settings now</button>
  </div>

  <section class="gear-edit-card">
    <h3>Confirm</h3>
    <div class="gear-edit-fields">
      <div class="gear-edit-field">
        <label for="gear-reset-confirm">Type RESET</label>
        <input id="gear-reset-confirm" name="confirm_text" type="text" placeholder="RESET" autocomplete="off" required>
      </div>
    </div>
    <p class="gear-edit-muted">You will need to set Appearance and privacy again after this.</p>
    <p class="gear-edit-save-state" id="gearResetSettingsState" aria-live="polite"></p>
  </section>
</form>
