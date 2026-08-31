<?php
declare(strict_types=1);

$gearUsernameValue = trim((string)($gearUsernameForm['username'] ?? ''));
?>
<form id="gearEditUsernameForm" class="gear-edit-form" action="accounts.php" method="post">
  <input type="hidden" name="ajax" value="save_username">
  <div class="gear-edit-head">
    <div>
      <h2 class="gear-edit-title">Change username</h2>
      <p class="gear-edit-sub">This username is your @handle. Name, email, and phone stay on Account.</p>
    </div>
    <button class="gear-edit-save" type="submit">Save changes</button>
  </div>

  <section class="gear-edit-card">
    <h3>Username</h3>
    <div class="gear-edit-fields">
      <div class="gear-edit-field">
        <label for="gear-edit-username">Username</label>
        <input id="gear-edit-username" name="username" type="text" value="<?php echo h($gearUsernameValue); ?>" required maxlength="60" autocomplete="username">
      </div>
    </div>
    <p class="gear-edit-save-state" id="gearEditUsernameState" aria-live="polite"></p>
  </section>
</form>
