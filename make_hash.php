<?php
echo password_hash("Admin@12345", PASSWORD_DEFAULT);

// After seeing http://localhost:8888/private_project/make_hash.php saying "$2y$10$Z9ixdt1JwMZufq/WdUYoe.pla4k18TV2an43sGLYeOdob771ZTwBW" 
// then replace with "PASTE_HASH_HERE" below "INSERT INTO admin.....

// INSERT INTO admin
// (fullname, username, friend_code, email, password, gender, mobile, designation, roles, status, image, force_password_change)
// VALUES
// ('Super Admin', 'admin', 'ADM-0000-0000', 'admin@example.com', 'PASTE_HASH_HERE', 'N/A', 'N/A', 'Admin', 1, 1, 'default.jpg', 0);
