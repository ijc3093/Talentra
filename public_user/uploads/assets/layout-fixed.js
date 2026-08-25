document.addEventListener("DOMContentLoaded", function () {
  // Shamcey menu button selectors
  const menuBtn =
    document.querySelector(".sh-navicon") ||
    document.querySelector(".menu-toggle") ||
    document.querySelector(".btn-menu");

  if (!menuBtn) return;

  menuBtn.addEventListener("click", function (e) {
    // prevent accidental navigation
    if (typeof e.preventDefault === "function") e.preventDefault();
    document.body.classList.toggle("menu-collapsed");
  });
});
