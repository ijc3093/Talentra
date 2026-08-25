(function(){
  // Wrap tables in .table-responsive if not already
  function wrapTables(){
    var tables = document.querySelectorAll('table');
    tables.forEach(function(tbl){
      if (tbl.closest('.table-responsive')) return;
      if (tbl.classList.contains('no-responsive')) return;
      // Only wrap tables that look like data tables
      var hasThead = !!tbl.querySelector('thead');
      var hasTh = !!tbl.querySelector('th');
      if (!hasThead && !hasTh && tbl.rows.length < 3) return;

      var wrap = document.createElement('div');
      wrap.className = 'table-responsive';
      tbl.parentNode.insertBefore(wrap, tbl);
      wrap.appendChild(tbl);
    });
  }

  // Auto-mark active nav link in leftbar
  function markActiveNav(){
    var path = (window.location.pathname || '').split('/').pop() || '';
    document.querySelectorAll('.sh-sideleft-menu .nav-link').forEach(function(a){
      var href = (a.getAttribute('href') || '').split('?')[0];
      if (!href) return;
      if (href.toLowerCase() === path.toLowerCase()) {
        a.classList.add('active');
      } else {
        // don't remove active if template sets it elsewhere for nested views
        if (!a.dataset.keepActive) a.classList.remove('active');
      }
    });
  }

  // Improve file inputs: show selected filename next to the input if a target exists
  function enhanceFileInputs(){
    document.querySelectorAll('input[type="file"]').forEach(function(inp){
      inp.addEventListener('change', function(){
        var name = (inp.files && inp.files[0]) ? inp.files[0].name : '';
        var targetId = inp.getAttribute('data-filename-target');
        if (!targetId) return;
        var el = document.getElementById(targetId);
        if (el) el.textContent = name;
      });
    });
  }

  function init(){
    wrapTables();
    markActiveNav();
    enhanceFileInputs();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
