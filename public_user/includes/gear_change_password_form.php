<?php
declare(strict_types=1);
?>
<form id="gearChangePasswordForm" class="gear-edit-form" action="change-password.php" method="post" autocomplete="off">
  <input type="hidden" name="ajax" value="save_password">
  <div class="gear-edit-head">
    <div>
      <h2 class="gear-edit-title">Change password</h2>
      <p class="gear-edit-sub">Update your account password. Use at least 6 characters and keep it private.</p>
    </div>
    <button class="gear-edit-save" type="submit">Save changes</button>
  </div>

  <section class="gear-edit-card">
    <div class="gear-edit-fields">
      <div class="gear-edit-field">
        <label for="gear-pwd-current">Current password</label>
        <input id="gear-pwd-current" name="password" type="password" autocomplete="current-password" required>
      </div>
      <div class="gear-edit-field">
        <label for="gear-pwd-new">New password</label>
        <input id="gear-pwd-new" name="newpassword" type="password" autocomplete="new-password" minlength="6" required>
      </div>
      <div class="gear-edit-field">
        <label for="gear-pwd-confirm">Confirm new password</label>
        <input id="gear-pwd-confirm" name="confirmpassword" type="password" autocomplete="new-password" minlength="6" required>
      </div>
    </div>
    <p class="gear-edit-muted">After saving, your new password is used the next time you sign in on any device.</p>
    <p class="gear-edit-save-state" id="gearChangePasswordState" aria-live="polite"></p>
  </section>
</form>
