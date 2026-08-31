<?php
if (!empty($GLOBALS['msb_leftbar_door_anim_js'])) {
  return;
}
$GLOBALS['msb_leftbar_door_anim_js'] = true;
?>
<script>
window.MSBLeftbarDoorAnim = window.MSBLeftbarDoorAnim || {
  ms: function(){
    try {
      if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return 0;
    } catch (e) {}
    return 620;
  },
  hold: function(name, stillOpen, release){
    this._t = this._t || {};
    if (this._t[name]) clearTimeout(this._t[name]);
    var self = this;
    this._t[name] = setTimeout(function(){
      self._t[name] = null;
      try { if (stillOpen()) return; } catch (e) {}
      release();
    }, this.ms());
  },
  cancel: function(name){
    this._t = this._t || {};
    if (this._t[name]) {
      clearTimeout(this._t[name]);
      this._t[name] = null;
    }
  }
};
</script>
