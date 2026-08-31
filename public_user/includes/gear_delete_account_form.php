<?php
declare(strict_types=1);

$gearDeleteOn = 1;
if (isset($profileSettings) && is_array($profileSettings) && array_key_exists('allow_delete_account', $profileSettings) && $profileSettings['allow_delete_account'] !== null && $profileSettings['allow_delete_account'] !== '') {
    $gearDeleteOn = (int)$profileSettings['allow_delete_account'];
}
?>
<form id="gearDeleteAccountForm" class="gear-edit-form" action="account_tools.php" method="post">
  <input type="hidden" name="ajax" value="delete">
  <input type="hidden" name="action" value="delete">
  <div class="gear-edit-head">
    <div>
      <h2 class="gear-edit-title">Delete account</h2>
      <p class="gear-edit-sub">Permanently remove this account and its data. This cannot be undone.</p>
    </div>
    <?php if ($gearDeleteOn): ?>
      <button class="gear-edit-save" type="submit">Delete account now</button>
    <?php endif; ?>
  </div>

  <section class="gear-edit-card">
    <h3>Confirm</h3>
    <?php if (!$gearDeleteOn): ?>
      <p class="gear-edit-save-state is-error">Delete account is turned off in Gear. Turn on Allow delete account first.</p>
    <?php else: ?>
      <div class="gear-edit-fields">
        <div class="gear-edit-field">
          <label for="gear-delete-confirm">Type DELETE</label>
          <input id="gear-delete-confirm" name="confirm_text" type="text" placeholder="DELETE" autocomplete="off" required>
        </div>
      </div>
      <p class="gear-edit-muted">This will try to remove your profile, About Me, settings, posts, comments, friends, and messages. Shop orders and publisher workspace links are included if you have them.</p>
      <p class="gear-edit-save-state" id="gearDeleteAccountState" aria-live="polite"></p>
    <?php endif; ?>
  </section>
</form>
