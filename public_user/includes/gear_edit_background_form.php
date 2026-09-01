<?php
declare(strict_types=1);

/**
 * Edit-background fields for the Gear fourth column.
 * Expects $gearEditForm, $peopleRelationship, $peopleFamily, $hasBackgroundTable.
 */
if (!isset($gearEditForm) || !is_array($gearEditForm)) {
    return;
}
$gef = $gearEditForm;
?>
<form id="gearEditBackgroundForm" class="gear-edit-form" action="user_edit.php" method="post">
  <input type="hidden" name="ajax" value="save_about">
  <div class="gear-edit-head">
    <div>
      <h2 class="gear-edit-title">Edit background</h2>
      <p class="gear-edit-sub">About details only. Name, username, email, phone, bio, location, and website live on <a href="accounts.php?return=<?php echo rawurlencode('settings.php'); ?>">Account</a>.</p>
    </div>
    <button class="gear-edit-save" type="submit">Save changes</button>
  </div>

  <?php if (empty($hasBackgroundTable)): ?>
    <div class="gear-edit-alert">The user_backgrounds table was not found. About background fields need your background SQL table.</div>
  <?php endif; ?>

  <section class="gear-edit-card">
    <h3>About background</h3>
    <div class="gear-edit-fields">
      <div class="gear-edit-split">
        <div class="gear-edit-field">
          <label for="gear-bg-gender">Gender</label>
          <input id="gear-bg-gender" name="gender" type="text" value="<?php echo h((string)($gef['gender'] ?? '')); ?>">
        </div>
        <div class="gear-edit-field">
          <label for="gear-bg-designation">Work / designation</label>
          <input id="gear-bg-designation" name="designation" type="text" value="<?php echo h((string)($gef['designation'] ?? '')); ?>">
        </div>
      </div>
      <div class="gear-edit-split">
        <div class="gear-edit-field">
          <label for="gear-bg-pronouns">Pronouns</label>
          <input id="gear-bg-pronouns" name="pronouns" type="text" value="<?php echo h((string)($gef['pronouns'] ?? '')); ?>" placeholder="He / Him, She / Her, They / Them">
        </div>
        <div class="gear-edit-field">
          <label for="gear-bg-born-in">When born</label>
          <input id="gear-bg-born-in" name="born_in" type="text" value="<?php echo h((string)($gef['born_in'] ?? '')); ?>" placeholder="Dallas, Texas or 1998">
        </div>
      </div>
      <div class="gear-edit-split">
        <div class="gear-edit-field">
          <label for="gear-bg-birthday">Birthday date</label>
          <input id="gear-bg-birthday" name="birthday" type="text" value="<?php echo h((string)($gef['birthday'] ?? '')); ?>" placeholder="May 14">
        </div>
        <div class="gear-edit-field">
          <label for="gear-bg-languages">Languages</label>
          <input id="gear-bg-languages" name="languages" type="text" value="<?php echo h((string)($gef['languages'] ?? '')); ?>" placeholder="English, French, Spanish">
        </div>
      </div>
      <div class="gear-edit-field">
        <label>Relationship</label>
        <input type="hidden" id="gear-bg-relationship" name="relationship_status" value="<?php echo h((string)($gef['relationship_status'] ?? '')); ?>">
        <div class="gear-edit-value" data-people-value><?php echo !empty($peopleRelationship) ? profile_people_tags_relationship_html($peopleRelationship, (string)($gef['relationship_status'] ?? '')) : h((string)($gef['relationship_status'] ?? '')); ?></div>
        <?php profile_people_tags_render_relationship_editor($peopleRelationship ?? null); ?>
        <div class="gear-edit-muted">Type @username to tag someone. They get a notification.</div>
      </div>
      <div class="gear-edit-field">
        <label>Family</label>
        <input type="hidden" id="gear-bg-family" name="family_details" value="<?php echo h((string)($gef['family_details'] ?? '')); ?>">
        <div class="gear-edit-value" data-people-value><?php echo !empty($peopleFamily) ? profile_people_tags_family_html($peopleFamily, (string)($gef['family_details'] ?? '')) : nl2br(h((string)($gef['family_details'] ?? ''))); ?></div>
        <?php profile_people_tags_render_family_editor($peopleFamily ?? []); ?>
        <div class="gear-edit-muted">Tag father, mother, brother, sister, and others with @username.</div>
      </div>
      <div class="gear-edit-field">
        <label for="gear-bg-education">Education</label>
        <textarea id="gear-bg-education" name="education_history"><?php echo h((string)($gef['education_history'] ?? '')); ?></textarea>
      </div>
      <div class="gear-edit-field">
        <label for="gear-bg-work">Work</label>
        <textarea id="gear-bg-work" name="work_details"><?php echo h((string)($gef['work_details'] ?? '')); ?></textarea>
      </div>
      <div class="gear-edit-field">
        <label for="gear-bg-hobby">Hobby</label>
        <textarea id="gear-bg-hobby" name="hobbies"><?php echo h((string)($gef['hobbies'] ?? '')); ?></textarea>
      </div>
    </div>
    <p class="gear-edit-save-state" id="gearEditBackgroundState" aria-live="polite"></p>
  </section>
</form>
