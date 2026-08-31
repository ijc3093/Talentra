<?php
declare(strict_types=1);

$gearNameValue = trim((string)($gearDisplayNameForm['full_name'] ?? ''));
?>
<form id="gearEditDisplayNameForm" class="gear-edit-form" action="accounts.php" method="post">
  <input type="hidden" name="ajax" value="save_display_name">
  <div class="gear-edit-head">
    <div>
      <h2 class="gear-edit-title">Change display name</h2>
      <p class="gear-edit-sub">This name appears at the top of your profile. Username, email, and phone stay on Account.</p>
    </div>
    <button class="gear-edit-save" type="submit">Save changes</button>
  </div>

  <section class="gear-edit-card">
    <h3>Display name</h3>
    <div class="gear-edit-fields">
      <div class="gear-edit-field">
        <label for="gear-display-full-name">Full name</label>
        <input id="gear-display-full-name" name="full_name" type="text" value="<?php echo h($gearNameValue); ?>" required maxlength="120" autocomplete="name">
      </div>
    </div>
    <p class="gear-edit-save-state" id="gearEditDisplayNameState" aria-live="polite"></p>
  </section>
</form>
