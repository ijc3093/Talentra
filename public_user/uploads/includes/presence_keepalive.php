<script>
(function(){
  setInterval(() => {
    fetch('ajax/user_presence_ping.php', {
      cache: 'no-store',
      credentials: 'include'
    }).catch(()=>{});
  }, 20000);
})();
</script>
