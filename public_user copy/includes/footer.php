<?php
// /public_user/includes/footer.php
?>
<footer class="app-footer">

  <nav class="footer-nav">
      <a href="feed.php">
          <i class="icon ion-ios-home"></i>
          <span>Friends Feed</span>
      </a>

      <a href="public.php">
          <i class="icon ion-ios-world"></i>
          <span>Public</span>
      </a>

      <a href="live_studio.php">
          <i class="icon ion-ios-videocam"></i>
          <span>Live Studio</span>
      </a>

      <a href="dashboard.php">
          <i class="icon ion-ios-plus"></i>
          <span>New Thoughts </span>
      </a>

      <a href="contacts.php">
          <i class="icon ion-folder"></i>
          <span>Friends</span>
      </a>

      <a href="contact_requests.php">
          <i class="icon ion-person-add"></i>
          <span>Friend Requests</span>
      </a>

      <a href="timeline.php">
          <i class="icon ion-ios-locked"></i>
          <span>Timeline</span>
      </a>
  </nav>
      <!-- <div class="sh-footer">
        <div>Copyright &copy; 2017. All Rights Reserved. Talentra</div>
        <div class="mg-t-10 mg-md-t-0">Designed by: <a href="http://themepixels.me">ThemePixels</a></div>
      </div> -->
</footer>

<style>
body { background: #f5f7fb; }

.app-main {
    max-width: 1100px;
    margin: 0 auto;
    padding: 20px;
    width: 100%;
}

.app-footer {
    position: fixed;
    bottom: 0;
    left: 0;
    width: 100%;
    background: #ffffff;
    /* border-top: 1px solid #e5e7eb; */
    z-index: 999;
    /* margin-left: 7%;
    margin-right: 7%; */
    border-top: solid;
}

.footer-nav {
    max-width: 1100px;
    margin: 0 auto;
    display: flex;
    justify-content: space-around;
    align-items: center;
    height: 64px;
}

.footer-nav a {
    text-decoration: none;
    color: #6b7280;
    font-size: 12px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 3px;
    transition: 0.2s;
}

.footer-nav a i { font-size: 20px; }

.footer-nav a:hover { color: #0861bc; }

/* ✅ Mobile/Tablet: show icons only (hide text labels) */
@media (max-width: 992px) {
  .footer-nav a span {
    display: none !important;
  }
  .footer-nav a {
    gap: 0;
  }
  .footer-nav a i {
    font-size: 22px;
  }
}

body { padding-bottom: 80px; }
</style>
