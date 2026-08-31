<?php
declare(strict_types=1);

$gaf = is_array($gearAccountForm ?? null) ? $gearAccountForm : [];
$gearAccPhoneReq = !empty($gearAccountPhoneRequired);
$gearAccBio = (string)($gaf['bio'] ?? '');
$gearAccBioLen = function_exists('mb_strlen') ? mb_strlen($gearAccBio) : strlen($gearAccBio);
?>
<form id="gearAccountForm" class="gear-edit-form" action="accounts.php" method="post">
  <input type="hidden" name="ajax" value="save_account">
  <div class="gear-edit-head">
    <div>
      <h2 class="gear-edit-title">Account</h2>
      <p class="gear-edit-sub">Name, username, email, phone, friend code, bio, location, and website.</p>
    </div>
    <button class="gear-edit-save" type="submit">Save changes</button>
  </div>

  <section class="gear-edit-card">
    <h3>Profile information</h3>
    <div class="gear-edit-fields">
      <div class="gear-edit-field">
        <label for="gear-acc-full-name">Full name</label>
        <input id="gear-acc-full-name" name="full_name" type="text" value="<?php echo h((string)($gaf['full_name'] ?? '')); ?>" required maxlength="120" autocomplete="name">
      </div>
      <div class="gear-edit-field">
        <label for="gear-acc-username">Username</label>
        <input id="gear-acc-username" name="username" type="text" value="<?php echo h((string)($gaf['username'] ?? '')); ?>" required maxlength="60" autocomplete="username">
      </div>
      <div class="gear-edit-field">
        <label for="gear-acc-email">Email</label>
        <input id="gear-acc-email" name="email" type="email" value="<?php echo h((string)($gaf['email'] ?? '')); ?>" required maxlength="255" autocomplete="email">
      </div>
      <div class="gear-edit-field">
        <label for="gear-acc-mobile">Phone number</label>
        <input id="gear-acc-mobile" name="mobile" type="tel" value="<?php echo h((string)($gaf['mobile'] ?? '')); ?>" autocomplete="tel" inputmode="tel"<?php echo $gearAccPhoneReq ? ' required' : ''; ?>>
      </div>
      <div class="gear-edit-field">
        <label for="gear-acc-friend-code">Friend code</label>
        <input id="gear-acc-friend-code" name="friend_code" type="text" value="<?php echo h((string)($gaf['friend_code'] ?? '')); ?>" required maxlength="30">
      </div>
      <div class="gear-edit-field">
        <label for="gear-acc-bio">Bio</label>
        <textarea id="gear-acc-bio" name="bio" maxlength="160"><?php echo h($gearAccBio); ?></textarea>
        <span class="gear-edit-muted" id="gearAccBioCount"><?php echo (int)$gearAccBioLen; ?>/160</span>
      </div>
      <div class="gear-edit-field">
        <label for="gear-acc-location">Location</label>
        <input id="gear-acc-location" name="location" type="text" value="<?php echo h((string)($gaf['location'] ?? '')); ?>" placeholder="City, Country" maxlength="150">
      </div>
      <div class="gear-edit-field">
        <label for="gear-acc-website">Website</label>
        <input id="gear-acc-website" name="website" type="text" inputmode="url" value="<?php echo h((string)($gaf['website'] ?? '')); ?>" placeholder="https://">
      </div>
    </div>
    <p class="gear-edit-save-state" id="gearAccountState" aria-live="polite"></p>
  </section>
</form>
