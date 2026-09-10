<?php
// /Business_only3/public_user/includes/navleftbar.php
require_once __DIR__ . '/session_user.php';
requireUserLogin();

$__cur = strtolower(basename((string)($_SERVER['PHP_SELF'] ?? '')));
$__isActive = function (string $file) use ($__cur): string {
    return ($__cur === strtolower($file)) ? ' active' : '';
};
?>

<style>
.sh-sideleft-menu {
    overflow-y: auto;
    position: fixed;
    top: 100px;
    left: 0px;
    bottom: 0;
    width: 23.5%;
    display: block !important;
    padding: 12px 18px !important;
    margin: 0 !important;
    letter-spacing: .08em;
    /* height: 565px; */
    /* background-color: #212529; */
    transition: all 0.2s ease-in-out;
    margin-left: auto;
    margin-right: auto;
    border-right: solid;
    border-color: #d9d9d9;
}
.sh-sideleft-menu .nav {
  display: block;
  padding: 0;
  display: block;
  margin-left: 50px;
  margin-right: 50px;
  margin-top: 100px;
}

    @media (max-width: 575.98px){
        .sh-sideleft-menu{ display:none !important; }
    }
</style>

<div class="sh-sideleft-menu">
    <label class="sh-sidebar-label">Navigation</label>
    <ul class="nav">
    <li class="nav-item">
        <a href="feed.php" class="nav-link<?= $__isActive('dashboard.php') ?>">
        <i class="icon ion-ios-home-outline"></i>
        <span>Friends Feed</span>
        </a>
    </li>

    <li class="nav-item">
        <a href="public.php" class="nav-link<?= $__isActive('public.php') ?>">
        <i class="icon ion-ios-world-outline"></i>
        <span>Public</span>
        </a>
    </li>

    <li class="nav-item">
        <a href="dashboard.php" class="nav-link<?= $__isActive('dashboard.php') ?>">
        <i class="icon ion-ios-bookmarks-outline"></i>
        <span>Dashboard</span>
        </a>
    </li>

    <li class="nav-item">
        <a href="contacts.php" class="nav-link<?= $__isActive('contacts.php') ?>">
        <i class="icon ion-person-stalker"></i>
        <span>Friends</span>
        </a>
    </li>

    <li class="nav-item">
        <a href="add_contact.php" class="nav-link<?= $__isActive('add_contact.php') ?>">
        <i class="icon ion-person-add"></i>
        <span>Add Friend</span>
        </a>
    </li>

    <li class="nav-item">
        <a href="messages.php" class="nav-link<?= $__isActive('messages.php') ?>">
        <i class="icon ion-chatbubble"></i>
        <span>Messager</span>
        </a>
    </li>

    <li class="nav-item">
        <a href="contact_requests.php" class="nav-link<?= $__isActive('contact_requests.php') ?>">
        <i class="icon ion-person-add"></i>
        <span>Friend Requests</span>
        </a>
    </li>

    <li class="nav-item">
        <a href="logout.php" class="nav-link">
        <i class="icon ion-power"></i>
        <span>Sign Out</span>
        </a>
    </li>
    </ul>
</div>