<?php
declare(strict_types=1);

if (!function_exists('org_shell_scripts_emitted')) {
    function org_shell_scripts_emitted(): bool
    {
        return !empty($GLOBALS['__MSB_ORG_SHELL_SCRIPTS_EMITTED']);
    }
}

if (!org_shell_scripts_emitted()):
    $GLOBALS['__MSB_ORG_SHELL_SCRIPTS_EMITTED'] = true;
?>
  <script src="../lib/jquery/jquery.js"></script>
  <script src="../lib/popper.js/popper.js"></script>
  <script src="../lib/bootstrap/bootstrap.js"></script>
  <script src="../lib/perfect-scrollbar/js/perfect-scrollbar.jquery.js"></script>
  <script src="../js/shamcey.js"></script>
<?php endif; ?>
