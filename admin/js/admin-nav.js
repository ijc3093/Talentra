/**
 * Admin SPA nav disabled.
 * Full page loads keep the PHP session cookie reliable across refresh/navigation.
 * (AJAX fetch + history.pushState was contributing to apparent instant sign-outs.)
 */
(function () {
  'use strict';
})();
