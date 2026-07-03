-- ==========================================================
-- gospel.sql (FULL) - Roles + Users/Admin + Chat Channels + Notifications
-- - Supports: user<->Admin shared inbox (user_admin)
-- - Supports: internal chats by username (admin_manager, staff_manager)
-- - Stores avatars optionally in DB (image_blob, image_type)
-- ==========================================================

DROP DATABASE IF EXISTS gospel;
CREATE DATABASE gospel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE gospel;

-- ==========================================================
-- ROLES
-- ==========================================================
CREATE TABLE role (
  idrole INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB;

INSERT INTO role (name) VALUES
('Admin'),
('Manager'),
('Gospel'),
('Staff');

-- ==========================================================
-- ADMIN USERS (Admin/Manager/Gospel/Staff accounts)
-- ==========================================================
CREATE TABLE admin (
  idadmin INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) NOT NULL,
  email VARCHAR(100) NOT NULL,
  password VARCHAR(255) NOT NULL,
  gender VARCHAR(50) NOT NULL,
  mobile VARCHAR(50) NOT NULL,
  designation VARCHAR(50) NOT NULL,
  role INT NOT NULL,

  -- old filename column (keep for compatibility)
  image VARCHAR(100) NOT NULL DEFAULT 'default.jpg',

  -- store avatar in DB (optional)
  image_blob LONGBLOB NULL,
  image_type VARCHAR(100) NULL,

  status TINYINT NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_admin_email (email),
  UNIQUE KEY uq_admin_username (username),
  KEY idx_admin_role (role),

  CONSTRAINT fk_admin_role
    FOREIGN KEY (role) REFERENCES role(idrole)
    ON UPDATE CASCADE
    ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ==========================================================
-- PUBLIC USERS (site users)
-- ==========================================================
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(100) NOT NULL,
  password VARCHAR(255) NOT NULL,
  gender VARCHAR(50) NOT NULL,
  mobile VARCHAR(50) NOT NULL,
  designation VARCHAR(50) NOT NULL,

  -- old filename column (keep for compatibility)
  image VARCHAR(100) NOT NULL DEFAULT 'default.jpg',

  -- store avatar in DB (optional)
  image_blob LONGBLOB NULL,
  image_type VARCHAR(100) NULL,

  status TINYINT NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB;

-- ==========================================================
-- FEEDBACK (CHAT)
-- sender/receiver are strings:
--  - user_admin: receiver='Admin', sender=user email, AND Admin replies sender='Admin', receiver=user email
--  - internal chats: sender=username, receiver=username
-- ==========================================================
CREATE TABLE feedback (
  id INT AUTO_INCREMENT PRIMARY KEY,

  sender   VARCHAR(100) NOT NULL,
  receiver VARCHAR(100) NOT NULL,

  -- ✅ chat channel routing
  channel VARCHAR(30) NOT NULL DEFAULT 'user_admin',

  title VARCHAR(150) NOT NULL,
  feedbackdata TEXT NOT NULL,
  attachment VARCHAR(150) NULL,

  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  is_read TINYINT(1) NOT NULL DEFAULT 0,
  read_at TIMESTAMP NULL DEFAULT NULL,

  -- helpful indexes
  KEY idx_feedback_receiver_created (receiver, created_at),
  KEY idx_feedback_receiver_read (receiver, is_read, created_at),
  KEY idx_feedback_sender_receiver (sender, receiver, created_at),

  KEY idx_feedback_channel_receiver (channel, receiver, created_at),
  KEY idx_feedback_channel_read (channel, receiver, is_read, created_at),
  KEY idx_feedback_channel_sender_receiver (channel, sender, receiver, created_at)
) ENGINE=InnoDB;

-- ==========================================================
-- NOTIFICATIONS
-- notireceiver is per-account:
--  - for USERS: use email
--  - for ADMIN/MANAGER/STAFF: use username
-- ==========================================================
CREATE TABLE notification (
  id INT AUTO_INCREMENT PRIMARY KEY,
  notiuser VARCHAR(100) NOT NULL,
  notireceiver VARCHAR(100) NOT NULL,
  notitype VARCHAR(100) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  is_read TINYINT(1) NOT NULL DEFAULT 0,
  read_at TIMESTAMP NULL DEFAULT NULL,

  KEY idx_notification_receiver_read (notireceiver, is_read),
  KEY idx_notification_receiver_created (notireceiver, created_at),
  KEY idx_notification_created (created_at)
) ENGINE=InnoDB;

-- ==========================================================
-- DELETED USERS
-- ==========================================================
CREATE TABLE deleteduser (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(100) NOT NULL,
  deleted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  KEY idx_deleteduser_email (email),
  KEY idx_deleteduser_deleted_at (deleted_at)
) ENGINE=InnoDB;

-- ==========================================================
-- OPTIONAL: If you are upgrading an existing database that already had feedback table
-- (only run these if your feedback table already exists and did NOT have channel)
-- ==========================================================
-- ALTER TABLE feedback
--   ADD COLUMN channel VARCHAR(30) NOT NULL DEFAULT 'user_admin' AFTER receiver,
--   ADD KEY idx_feedback_channel_receiver (channel, receiver, created_at),
--   ADD KEY idx_feedback_channel_read (channel, receiver, is_read, created_at),
--   ADD KEY idx_feedback_channel_sender_receiver (channel, sender, receiver, created_at);
--
-- UPDATE feedback
-- SET channel='user_admin'
-- WHERE channel='' OR channel IS NULL;
