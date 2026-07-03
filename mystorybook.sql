-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost:8889
-- Generation Time: Jul 01, 2026 at 02:16 PM
-- Server version: 5.7.44
-- PHP Version: 7.4.33

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `mystorybook`
--

-- --------------------------------------------------------

--
-- Table structure for table `admin`
--

CREATE TABLE `admin` (
  `idadmin` int(11) NOT NULL,
  `fullname` varchar(20) DEFAULT NULL,
  `username` varchar(100) NOT NULL,
  `friend_code` varchar(20) DEFAULT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `gender` varchar(50) DEFAULT NULL,
  `mobile` varchar(50) DEFAULT NULL,
  `designation` varchar(50) DEFAULT NULL,
  `role` int(11) NOT NULL,
  `image` varchar(100) NOT NULL DEFAULT 'default.jpg',
  `image_blob` longblob,
  `image_type` varchar(100) DEFAULT NULL,
  `status` tinyint(4) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `force_password_change` tinyint(1) DEFAULT '1',
  `last_login_at` datetime DEFAULT NULL,
  `failed_login_attempts` int(11) NOT NULL DEFAULT '0',
  `locked_until` datetime DEFAULT NULL,
  `reset_token_hash` varchar(64) DEFAULT NULL,
  `reset_token_expires` datetime DEFAULT NULL,
  `reset_request_count` int(11) NOT NULL DEFAULT '0',
  `reset_request_window_start` datetime DEFAULT NULL,
  `reset_last_requested_at` datetime DEFAULT NULL,
  `linked_personal_user_id` int(11) DEFAULT NULL,
  `linked_publisher_user_id` int(11) DEFAULT NULL,
  `linked_manager_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `admin_contacts`
--

CREATE TABLE `admin_contacts` (
  `id` int(11) NOT NULL,
  `owner_admin_id` int(11) NOT NULL,
  `friend_admin_id` int(11) NOT NULL,
  `display_name` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `admin_portal_handoff`
--

CREATE TABLE `admin_portal_handoff` (
  `token` char(64) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `portal` varchar(20) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `admin_security_log`
--

CREATE TABLE `admin_security_log` (
  `id` int(11) NOT NULL,
  `email` varchar(190) DEFAULT NULL,
  `admin_id` int(11) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `success` tinyint(1) NOT NULL DEFAULT '0',
  `ip` varchar(64) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `meta` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `capsules`
--

CREATE TABLE `capsules` (
  `id` bigint(20) NOT NULL,
  `post_id` bigint(20) NOT NULL,
  `capsule_theme` varchar(120) NOT NULL,
  `unlock_mode` varchar(30) NOT NULL DEFAULT 'date',
  `unlock_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `capsule_contributors`
--

CREATE TABLE `capsule_contributors` (
  `capsule_id` bigint(20) NOT NULL,
  `friend_code` varchar(20) NOT NULL,
  `role` varchar(20) NOT NULL DEFAULT 'viewer'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `capsule_entries`
--

CREATE TABLE `capsule_entries` (
  `id` bigint(20) NOT NULL,
  `capsule_id` bigint(20) NOT NULL,
  `author_friend_code` varchar(20) NOT NULL,
  `entry_text` longtext,
  `media_id` bigint(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `chat_groups`
--

CREATE TABLE `chat_groups` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by_user_id` int(11) NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_group_hidden_messages`
--

CREATE TABLE `chat_group_hidden_messages` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `group_message_id` bigint(20) UNSIGNED NOT NULL,
  `hidden_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_group_members`
--

CREATE TABLE `chat_group_members` (
  `id` int(10) UNSIGNED NOT NULL,
  `group_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `role` enum('owner','admin','member') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'member',
  `added_by_user_id` int(11) DEFAULT NULL,
  `joined_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `left_at` timestamp NULL DEFAULT NULL,
  `blocked_at` timestamp NULL DEFAULT NULL,
  `blocked_by_user_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_group_messages`
--

CREATE TABLE `chat_group_messages` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `group_id` int(10) UNSIGNED NOT NULL,
  `sender_user_id` int(11) NOT NULL,
  `body` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `attachment_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `attachment_type` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `attachment_original` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_group_message_reactions`
--

CREATE TABLE `chat_group_message_reactions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `group_message_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `reaction` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_messages`
--

CREATE TABLE `chat_messages` (
  `id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `receiver_id` int(11) NOT NULL,
  `feedbackdata` text NOT NULL,
  `attachment` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `read_at` datetime DEFAULT NULL,
  `delivered_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `chat_typing`
--

CREATE TABLE `chat_typing` (
  `id` int(11) NOT NULL,
  `sender_code` varchar(20) DEFAULT NULL,
  `receiver_code` varchar(20) DEFAULT NULL,
  `is_typing` tinyint(1) NOT NULL DEFAULT '0',
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `contacts`
--

CREATE TABLE `contacts` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `contact_user_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `contact_requests`
--

CREATE TABLE `contact_requests` (
  `id` int(11) NOT NULL,
  `from_user_id` int(11) NOT NULL,
  `to_user_id` int(11) NOT NULL,
  `status` enum('pending','accepted','declined','blocked') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `conversations`
--

CREATE TABLE `conversations` (
  `id` int(11) NOT NULL,
  `uuid` char(36) NOT NULL,
  `type` enum('user','support') NOT NULL DEFAULT 'user',
  `created_by_user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `conversation_participants`
--

CREATE TABLE `conversation_participants` (
  `conversation_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `joined_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `deleteduser`
--

CREATE TABLE `deleteduser` (
  `id` int(11) NOT NULL,
  `email` varchar(100) NOT NULL,
  `username` varchar(50) NOT NULL DEFAULT '',
  `friend_code` varchar(20) NOT NULL DEFAULT '',
  `display_name` varchar(100) NOT NULL DEFAULT '',
  `mobile` varchar(30) NOT NULL DEFAULT '',
  `account_kind` varchar(20) NOT NULL DEFAULT 'personal',
  `deleted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `feedback`
--

CREATE TABLE `feedback` (
  `id` int(11) NOT NULL,
  `sender` varchar(100) NOT NULL,
  `receiver` varchar(100) NOT NULL,
  `channel` varchar(30) NOT NULL DEFAULT 'user_admin',
  `org_id` bigint(20) DEFAULT NULL,
  `scope` varchar(20) NOT NULL DEFAULT 'public',
  `title` varchar(150) NOT NULL,
  `feedbackdata` longtext,
  `attachment` varchar(255) DEFAULT NULL,
  `attachment_type` varchar(255) DEFAULT NULL,
  `attachment_original` varchar(255) DEFAULT NULL,
  `attachment_url` text,
  `attachment_blob` longblob,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `read_at` timestamp NULL DEFAULT NULL,
  `delivered_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `feedback_admin`
--

CREATE TABLE `feedback_admin` (
  `id_feedback_admin` int(11) NOT NULL,
  `sender` varchar(100) NOT NULL,
  `receiver` varchar(100) NOT NULL,
  `channel` varchar(50) NOT NULL,
  `org_id` bigint(20) DEFAULT NULL,
  `scope` varchar(20) NOT NULL DEFAULT 'public',
  `title` varchar(255) DEFAULT NULL,
  `feedbackdata` mediumtext NOT NULL,
  `attachment` varchar(255) DEFAULT NULL,
  `attachment_type` varchar(50) DEFAULT NULL,
  `attachment_original` varchar(255) DEFAULT NULL,
  `attachment_url` text,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `is_read` tinyint(1) DEFAULT '0',
  `read_at` datetime DEFAULT NULL,
  `delivered_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `group_video_calls`
--

CREATE TABLE `group_video_calls` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `group_id` int(11) NOT NULL,
  `started_by_user_id` int(11) NOT NULL,
  `call_mode` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'video',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'initiated',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `started_at` datetime DEFAULT NULL,
  `ended_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `group_video_call_participants`
--

CREATE TABLE `group_video_call_participants` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `call_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `invite_status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'invited',
  `invited_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `responded_at` datetime DEFAULT NULL,
  `joined_at` datetime DEFAULT NULL,
  `left_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `group_video_call_signals`
--

CREATE TABLE `group_video_call_signals` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `call_id` bigint(20) UNSIGNED NOT NULL,
  `from_user_id` int(11) NOT NULL,
  `to_user_id` int(11) NOT NULL,
  `signal_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `consumed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `live_studio_comments`
--

CREATE TABLE `live_studio_comments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `session_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `comment_text` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `live_studio_presence`
--

CREATE TABLE `live_studio_presence` (
  `session_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `role_name` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'viewer',
  `last_seen_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `live_studio_reactions`
--

CREATE TABLE `live_studio_reactions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `session_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `reaction_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `live_studio_sessions`
--

CREATE TABLE `live_studio_sessions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `host_user_id` int(11) NOT NULL,
  `title` varchar(180) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Live With Friends',
  `visibility` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'friends',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `started_at` datetime DEFAULT NULL,
  `ended_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `live_studio_signals`
--

CREATE TABLE `live_studio_signals` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `session_id` bigint(20) UNSIGNED NOT NULL,
  `live_id` int(10) UNSIGNED NOT NULL DEFAULT '0',
  `from_user_id` int(11) NOT NULL,
  `to_user_id` int(11) NOT NULL,
  `signal_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `consumed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `live_studio_watch_sessions`
--

CREATE TABLE `live_studio_watch_sessions` (
  `id` int(10) UNSIGNED NOT NULL,
  `live_id` int(10) UNSIGNED NOT NULL,
  `host_user_id` int(10) UNSIGNED NOT NULL,
  `viewer_user_id` int(10) UNSIGNED NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'initiated',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ended_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `managers`
--

CREATE TABLE `managers` (
  `id` bigint(20) NOT NULL,
  `friend_code` varchar(20) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `fullname` varchar(100) DEFAULT NULL,
  `status` tinyint(4) NOT NULL DEFAULT '1',
  `force_password_change` tinyint(4) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen` datetime DEFAULT NULL,
  `publisher_user_id` bigint(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `messages`
--

CREATE TABLE `messages` (
  `id` int(11) NOT NULL,
  `conversation_id` int(11) NOT NULL,
  `sender_user_id` int(11) NOT NULL,
  `body` text,
  `attachment` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `message_reads`
--

CREATE TABLE `message_reads` (
  `message_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `read_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `notification`
--

CREATE TABLE `notification` (
  `id` int(11) NOT NULL,
  `notiuser` varchar(100) NOT NULL,
  `notireceiver` varchar(100) NOT NULL,
  `notitype` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `read_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `organizations`
--

CREATE TABLE `organizations` (
  `id` bigint(20) NOT NULL,
  `org_code` varchar(16) NOT NULL,
  `name` varchar(120) NOT NULL,
  `owner_manager_id` bigint(20) NOT NULL,
  `status` tinyint(4) NOT NULL DEFAULT '1',
  `is_publisher_org` tinyint(1) NOT NULL DEFAULT '0',
  `publisher_user_id` bigint(20) DEFAULT NULL,
  `publisher_category` varchar(40) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `organization_users`
--

CREATE TABLE `organization_users` (
  `id` bigint(20) NOT NULL,
  `org_id` bigint(20) NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `role` enum('admin','manager','staff') NOT NULL,
  `display_name` varchar(120) DEFAULT NULL,
  `avatar` varchar(255) DEFAULT NULL,
  `joined_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `org_attachments`
--

CREATE TABLE `org_attachments` (
  `id` bigint(20) NOT NULL,
  `org_id` bigint(20) NOT NULL,
  `uploaded_by_member_id` bigint(20) NOT NULL,
  `original_name` varchar(255) DEFAULT NULL,
  `mime_type` varchar(80) NOT NULL,
  `path` varchar(255) NOT NULL,
  `size_bytes` bigint(20) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `org_feed_reads`
--

CREATE TABLE `org_feed_reads` (
  `org_id` bigint(20) NOT NULL,
  `member_id` bigint(20) NOT NULL,
  `tab` varchar(20) NOT NULL,
  `last_read_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `org_invites`
--

CREATE TABLE `org_invites` (
  `id` bigint(20) NOT NULL,
  `org_id` bigint(20) NOT NULL,
  `created_by_member_id` bigint(20) NOT NULL,
  `invite_code` varchar(32) NOT NULL,
  `role_id` bigint(20) NOT NULL,
  `relationship_label` varchar(40) DEFAULT NULL,
  `invite_email` varchar(120) DEFAULT NULL,
  `invite_phone` varchar(40) DEFAULT NULL,
  `temp_username` varchar(60) DEFAULT NULL,
  `temp_password_hash` varchar(255) DEFAULT NULL,
  `status` tinyint(4) NOT NULL DEFAULT '1',
  `expires_at` datetime DEFAULT NULL,
  `used_by_friend_code` varchar(20) DEFAULT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `org_members`
--

CREATE TABLE `org_members` (
  `id` bigint(20) NOT NULL,
  `org_id` bigint(20) NOT NULL,
  `member_type` varchar(20) NOT NULL,
  `member_id` bigint(20) NOT NULL,
  `role_id` bigint(20) NOT NULL,
  `relationship_label` varchar(40) DEFAULT NULL,
  `status` tinyint(4) NOT NULL DEFAULT '1',
  `joined_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `org_messages`
--

CREATE TABLE `org_messages` (
  `id` bigint(20) NOT NULL,
  `org_id` bigint(20) NOT NULL,
  `thread_id` int(11) DEFAULT NULL,
  `sender_member_id` bigint(20) NOT NULL,
  `receiver_member_id` bigint(20) NOT NULL,
  `msg_type` varchar(20) NOT NULL DEFAULT 'direct',
  `title` varchar(255) DEFAULT NULL,
  `body` longtext,
  `file_name` varchar(255) DEFAULT NULL,
  `file_type` varchar(100) DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `is_delivered` tinyint(4) NOT NULL DEFAULT '0',
  `is_read` tinyint(4) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `attachment_id` bigint(20) DEFAULT NULL,
  `read_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `org_message_threads`
--

CREATE TABLE `org_message_threads` (
  `id` int(11) NOT NULL,
  `org_id` int(11) NOT NULL,
  `member_a_id` int(11) NOT NULL,
  `member_b_id` int(11) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `uid` varchar(80) NOT NULL,
  `created_by_member_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_message_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `org_posts`
--

CREATE TABLE `org_posts` (
  `id` bigint(20) NOT NULL,
  `org_id` bigint(20) NOT NULL,
  `author_id` bigint(20) NOT NULL,
  `author_role` enum('admin','manager') NOT NULL,
  `post_type` enum('announcement','direction','update','recognition') NOT NULL,
  `title` varchar(200) DEFAULT NULL,
  `body` text NOT NULL,
  `visibility` enum('organization','team') DEFAULT 'organization',
  `comments_locked` tinyint(1) DEFAULT '0',
  `locked_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  `auto_close_hours` int(11) DEFAULT '72',
  `is_deleted` tinyint(1) NOT NULL DEFAULT '0',
  `is_visible` tinyint(1) NOT NULL DEFAULT '1',
  `post_state` varchar(16) NOT NULL DEFAULT 'published',
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `org_post_acknowledgements`
--

CREATE TABLE `org_post_acknowledgements` (
  `post_id` bigint(20) NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `acknowledged_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `org_post_attachments`
--

CREATE TABLE `org_post_attachments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `org_id` bigint(20) NOT NULL,
  `post_id` bigint(20) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `mime_type` varchar(120) NOT NULL,
  `stored_name` varchar(255) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `mime` varchar(120) NOT NULL,
  `ext` varchar(20) NOT NULL,
  `file_size` bigint(20) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `org_post_comments`
--

CREATE TABLE `org_post_comments` (
  `id` bigint(20) NOT NULL,
  `post_id` bigint(20) NOT NULL,
  `parent_id` bigint(20) DEFAULT NULL,
  `user_id` bigint(20) NOT NULL,
  `parent_comment_id` bigint(20) DEFAULT NULL,
  `body` varchar(500) NOT NULL,
  `is_question` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `org_post_flags`
--

CREATE TABLE `org_post_flags` (
  `id` bigint(20) NOT NULL,
  `post_id` bigint(20) NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `flag_type` enum('question','blocker') NOT NULL,
  `note` varchar(300) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `org_post_likes`
--

CREATE TABLE `org_post_likes` (
  `post_id` bigint(20) NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `liked_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `org_post_views`
--

CREATE TABLE `org_post_views` (
  `post_id` bigint(20) NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `viewed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `org_roles`
--

CREATE TABLE `org_roles` (
  `id` bigint(20) NOT NULL,
  `org_id` bigint(20) NOT NULL,
  `name` varchar(50) NOT NULL,
  `is_system` tinyint(4) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `org_role_permissions`
--

CREATE TABLE `org_role_permissions` (
  `role_id` bigint(20) NOT NULL,
  `perm_key` varchar(80) NOT NULL,
  `allowed` tinyint(4) NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `org_rooms`
--

CREATE TABLE `org_rooms` (
  `id` bigint(20) NOT NULL,
  `org_id` bigint(20) NOT NULL,
  `name` varchar(80) NOT NULL,
  `room_type` varchar(20) NOT NULL DEFAULT 'group',
  `created_by_member_id` bigint(20) NOT NULL,
  `status` tinyint(4) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `org_room_members`
--

CREATE TABLE `org_room_members` (
  `room_id` bigint(20) NOT NULL,
  `member_id` bigint(20) NOT NULL,
  `role` varchar(20) NOT NULL DEFAULT 'member',
  `status` tinyint(4) NOT NULL DEFAULT '1',
  `joined_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `org_room_messages`
--

CREATE TABLE `org_room_messages` (
  `id` bigint(20) NOT NULL,
  `org_id` bigint(20) NOT NULL,
  `room_id` bigint(20) NOT NULL,
  `sender_member_id` bigint(20) NOT NULL,
  `msg_type` varchar(20) NOT NULL DEFAULT 'text',
  `body` text,
  `attachment_id` bigint(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `org_settings`
--

CREATE TABLE `org_settings` (
  `org_id` bigint(20) NOT NULL,
  `logo_type` varchar(20) NOT NULL DEFAULT 'text',
  `logo_text` varchar(40) DEFAULT NULL,
  `logo_image_path` varchar(255) DEFAULT NULL,
  `theme_json` json DEFAULT NULL,
  `a11y_json` json DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `logo_icon` varchar(50) DEFAULT NULL,
  `logo_color` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `password_reset_tokens`
--

CREATE TABLE `password_reset_tokens` (
  `id` int(11) NOT NULL,
  `account_type` enum('user','admin') NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `admin_id` int(11) DEFAULT NULL,
  `username` varchar(100) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `ip` varchar(64) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Triggers `password_reset_tokens`
--
DELIMITER $$
CREATE TRIGGER `trg_password_reset_tokens_guard_bi` BEFORE INSERT ON `password_reset_tokens` FOR EACH ROW BEGIN
  IF NEW.`account_type` = 'user' THEN
    IF NEW.`user_id` IS NULL OR NEW.`admin_id` IS NOT NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'password_reset_tokens for users must set user_id only';
    END IF;
  ELSEIF NEW.`account_type` = 'admin' THEN
    IF NEW.`admin_id` IS NULL OR NEW.`user_id` IS NOT NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'password_reset_tokens for admins must set admin_id only';
    END IF;
  END IF;

  IF NEW.`expires_at` <= COALESCE(NEW.`created_at`, CURRENT_TIMESTAMP) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'password_reset_tokens.expires_at must be in the future';
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_password_reset_tokens_guard_bu` BEFORE UPDATE ON `password_reset_tokens` FOR EACH ROW BEGIN
  IF NEW.`account_type` = 'user' THEN
    IF NEW.`user_id` IS NULL OR NEW.`admin_id` IS NOT NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'password_reset_tokens for users must set user_id only';
    END IF;
  ELSEIF NEW.`account_type` = 'admin' THEN
    IF NEW.`admin_id` IS NULL OR NEW.`user_id` IS NOT NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'password_reset_tokens for admins must set admin_id only';
    END IF;
  END IF;

  IF NEW.`used_at` IS NOT NULL AND NEW.`used_at` < COALESCE(OLD.`created_at`, NEW.`created_at`, CURRENT_TIMESTAMP) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'password_reset_tokens.used_at cannot be earlier than created_at';
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `posts`
--

CREATE TABLE `posts` (
  `id` bigint(20) NOT NULL,
  `scope` varchar(20) NOT NULL,
  `org_id` bigint(20) DEFAULT NULL,
  `author_friend_code` varchar(20) NOT NULL,
  `author_member_id` bigint(20) DEFAULT NULL,
  `post_type` varchar(20) NOT NULL DEFAULT 'note',
  `caption` longtext,
  `status` tinyint(4) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `post_audience`
--

CREATE TABLE `post_audience` (
  `id` bigint(20) NOT NULL,
  `post_id` bigint(20) NOT NULL,
  `audience_type` varchar(30) NOT NULL,
  `org_role_id` bigint(20) DEFAULT NULL,
  `target_friend_code` varchar(20) DEFAULT NULL,
  `relationship_key` varchar(40) DEFAULT NULL,
  `requires_approval` tinyint(4) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `post_media`
--

CREATE TABLE `post_media` (
  `id` bigint(20) NOT NULL,
  `post_id` bigint(20) NOT NULL,
  `media_type` varchar(40) NOT NULL,
  `original_name` varchar(255) DEFAULT NULL,
  `path` varchar(255) NOT NULL,
  `size_bytes` bigint(20) NOT NULL DEFAULT '0',
  `duration_sec` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `public_comment_likes`
--

CREATE TABLE `public_comment_likes` (
  `comment_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `public_follows`
--

CREATE TABLE `public_follows` (
  `id` int(11) NOT NULL,
  `follower_id` int(11) NOT NULL,
  `following_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Triggers `public_follows`
--
DELIMITER $$
CREATE TRIGGER `trg_public_follows_guard_bi` BEFORE INSERT ON `public_follows` FOR EACH ROW BEGIN
  IF NEW.`follower_id` = NEW.`following_id` THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'public_follows cannot contain self-follows';
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_public_follows_guard_bu` BEFORE UPDATE ON `public_follows` FOR EACH ROW BEGIN
  IF NEW.`follower_id` = NEW.`following_id` THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'public_follows cannot contain self-follows';
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `public_posts`
--

CREATE TABLE `public_posts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(120) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `body` mediumtext,
  `visibility` enum('public','friends') NOT NULL DEFAULT 'public',
  `device_label` varchar(120) NOT NULL DEFAULT '',
  `device_viewport` varchar(32) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `is_deleted` tinyint(1) NOT NULL DEFAULT '0',
  `views_count` int(11) NOT NULL DEFAULT '0',
  `category_id` int(10) UNSIGNED DEFAULT NULL,
  `activity_at` datetime GENERATED ALWAYS AS (coalesce(`updated_at`,`created_at`)) STORED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Triggers `public_posts`
--
DELIMITER $$
CREATE TRIGGER `trg_public_posts_category_owner_bi` BEFORE INSERT ON `public_posts` FOR EACH ROW BEGIN
  DECLARE v_category_owner_id int;

  IF NEW.`category_id` IS NOT NULL THEN
    SELECT `user_id`
      INTO v_category_owner_id
    FROM `user_post_categories`
    WHERE `id` = NEW.`category_id`
    LIMIT 1;

    IF v_category_owner_id IS NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'public_posts.category_id must reference an existing category';
    END IF;

    IF v_category_owner_id <> NEW.`user_id` THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'public_posts.category_id must belong to the same user as the post';
    END IF;
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_public_posts_category_owner_bu` BEFORE UPDATE ON `public_posts` FOR EACH ROW BEGIN
  DECLARE v_category_owner_id int;

  IF NEW.`category_id` IS NOT NULL THEN
    SELECT `user_id`
      INTO v_category_owner_id
    FROM `user_post_categories`
    WHERE `id` = NEW.`category_id`
    LIMIT 1;

    IF v_category_owner_id IS NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'public_posts.category_id must reference an existing category';
    END IF;

    IF v_category_owner_id <> NEW.`user_id` THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'public_posts.category_id must belong to the same user as the post';
    END IF;
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `public_post_attachments`
--

CREATE TABLE `public_post_attachments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `post_id` bigint(20) UNSIGNED NOT NULL,
  `type` enum('image','video','pdf','file') NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `thumb_path` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `public_post_comments`
--

CREATE TABLE `public_post_comments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `post_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `parent_id` bigint(20) UNSIGNED DEFAULT NULL,
  `comment_text` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_deleted` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Triggers `public_post_comments`
--
DELIMITER $$
CREATE TRIGGER `trg_public_post_comments_guard_bi` BEFORE INSERT ON `public_post_comments` FOR EACH ROW BEGIN
  DECLARE v_parent_post_id bigint UNSIGNED;

  IF CHAR_LENGTH(TRIM(COALESCE(NEW.`comment_text`, ''))) = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'public_post_comments.comment_text cannot be blank';
  END IF;

  IF NEW.`parent_id` IS NOT NULL THEN
    IF NEW.`id` IS NOT NULL AND NEW.`id` > 0 AND NEW.`parent_id` = NEW.`id` THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'public_post_comments.parent_id cannot reference the same comment';
    END IF;

    SELECT `post_id`
      INTO v_parent_post_id
    FROM `public_post_comments`
    WHERE `id` = NEW.`parent_id`
    LIMIT 1;

    IF v_parent_post_id IS NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'public_post_comments.parent_id must reference an existing comment';
    END IF;

    IF v_parent_post_id <> NEW.`post_id` THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'public_post_comments.parent_id must point to a comment on the same post';
    END IF;
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_public_post_comments_guard_bu` BEFORE UPDATE ON `public_post_comments` FOR EACH ROW BEGIN
  DECLARE v_parent_post_id bigint UNSIGNED;

  IF CHAR_LENGTH(TRIM(COALESCE(NEW.`comment_text`, ''))) = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'public_post_comments.comment_text cannot be blank';
  END IF;

  IF NEW.`parent_id` IS NOT NULL THEN
    IF NEW.`parent_id` = NEW.`id` THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'public_post_comments.parent_id cannot reference the same comment';
    END IF;

    SELECT `post_id`
      INTO v_parent_post_id
    FROM `public_post_comments`
    WHERE `id` = NEW.`parent_id`
    LIMIT 1;

    IF v_parent_post_id IS NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'public_post_comments.parent_id must reference an existing comment';
    END IF;

    IF v_parent_post_id <> NEW.`post_id` THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'public_post_comments.parent_id must point to a comment on the same post';
    END IF;
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `public_post_reactions`
--

CREATE TABLE `public_post_reactions` (
  `post_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `reaction` enum('like','love') NOT NULL,
  `reacted_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `public_post_reads`
--

CREATE TABLE `public_post_reads` (
  `post_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `last_seen_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `public_post_saves`
--

CREATE TABLE `public_post_saves` (
  `post_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `saved_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `public_post_shares`
--

CREATE TABLE `public_post_shares` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `post_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `shared_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `public_post_tags`
--

CREATE TABLE `public_post_tags` (
  `id` int(11) NOT NULL,
  `post_id` bigint(20) UNSIGNED NOT NULL,
  `tagged_user_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `public_post_views`
--

CREATE TABLE `public_post_views` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `post_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `viewed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Triggers `public_post_views`
--
DELIMITER $$
CREATE TRIGGER `trg_public_post_views_rollup_ad` AFTER DELETE ON `public_post_views` FOR EACH ROW BEGIN
  UPDATE `public_post_view_daily`
  SET `views` = GREATEST(`views` - 1, 0)
  WHERE `post_id` = OLD.`post_id`
    AND `view_date` = DATE(OLD.`viewed_at`);

  DELETE FROM `public_post_view_daily`
  WHERE `post_id` = OLD.`post_id`
    AND `view_date` = DATE(OLD.`viewed_at`)
    AND `views` <= 0;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_public_post_views_rollup_ai` AFTER INSERT ON `public_post_views` FOR EACH ROW BEGIN
  INSERT INTO `public_post_view_daily` (`post_id`, `view_date`, `views`)
  VALUES (NEW.`post_id`, DATE(NEW.`viewed_at`), 1)
  ON DUPLICATE KEY UPDATE `views` = `views` + 1;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `public_post_view_daily`
--

CREATE TABLE `public_post_view_daily` (
  `post_id` bigint(20) UNSIGNED NOT NULL,
  `view_date` date NOT NULL,
  `views` int(11) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `public_profile_access`
--

CREATE TABLE `public_profile_access` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `owner_id` int(11) NOT NULL,
  `viewer_id` int(11) NOT NULL,
  `status` enum('pending','approved','denied') NOT NULL DEFAULT 'pending',
  `message` varchar(300) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `responded_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Triggers `public_profile_access`
--
DELIMITER $$
CREATE TRIGGER `trg_public_profile_access_guard_bi` BEFORE INSERT ON `public_profile_access` FOR EACH ROW BEGIN
  IF NEW.`owner_id` = NEW.`viewer_id` THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'public_profile_access cannot grant access to the owner themselves';
  END IF;

  IF NEW.`status` = 'pending' THEN
    SET NEW.`responded_at` = NULL;
  ELSEIF NEW.`responded_at` IS NULL THEN
    SET NEW.`responded_at` = CURRENT_TIMESTAMP;
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_public_profile_access_guard_bu` BEFORE UPDATE ON `public_profile_access` FOR EACH ROW BEGIN
  IF NEW.`owner_id` = NEW.`viewer_id` THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'public_profile_access cannot grant access to the owner themselves';
  END IF;

  IF NEW.`status` = 'pending' THEN
    SET NEW.`responded_at` = NULL;
  ELSEIF NEW.`responded_at` IS NULL THEN
    SET NEW.`responded_at` = CURRENT_TIMESTAMP;
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `public_saved_posts`
--

CREATE TABLE `public_saved_posts` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `post_id` bigint(20) UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `publisher_name_authority`
--

CREATE TABLE `publisher_name_authority` (
  `id` int(10) UNSIGNED NOT NULL,
  `publisher_name_option_id` int(10) UNSIGNED DEFAULT NULL,
  `publisher_name` varchar(120) NOT NULL,
  `publisher_category` varchar(40) NOT NULL DEFAULT 'news',
  `entity_type` varchar(40) NOT NULL DEFAULT 'business',
  `legal_entity_name` varchar(200) NOT NULL DEFAULT '',
  `registration_id` varchar(40) NOT NULL DEFAULT '',
  `registration_country` varchar(80) NOT NULL DEFAULT 'US',
  `authorized_contact_name` varchar(120) NOT NULL DEFAULT '',
  `authorized_contact_email` varchar(120) NOT NULL DEFAULT '',
  `request_note` varchar(500) NOT NULL DEFAULT '',
  `authority_confirmed` tinyint(1) NOT NULL DEFAULT '0',
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `reviewed_by_admin_id` int(10) UNSIGNED DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `review_note` varchar(255) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `publisher_name_options`
--

CREATE TABLE `publisher_name_options` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(120) NOT NULL,
  `category` varchar(40) NOT NULL DEFAULT 'news',
  `registered_user_id` int(10) UNSIGNED DEFAULT NULL,
  `org_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `role`
--

CREATE TABLE `role` (
  `idrole` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `inherits_from` int(11) DEFAULT NULL,
  `status` tinyint(4) NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `role`
--

INSERT INTO `role` (`idrole`, `name`, `inherits_from`, `status`) VALUES
(1, 'Admin', NULL, 1),
(2, 'Manager', NULL, 1),
(3, 'Gospel', NULL, 1),
(4, 'Staff', NULL, 1),
(8, 'Teacher', 2, 1),
(9, 'Student', 4, 1),
(10, 'Coach', 2, 1),
(11, 'Player', 4, 1);

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `inherits_from` int(11) DEFAULT NULL,
  `status` tinyint(4) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `role_chat_matrix`
--

CREATE TABLE `role_chat_matrix` (
  `from_role` int(11) NOT NULL,
  `to_role` int(11) NOT NULL,
  `allowed` tinyint(4) NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `role_permissions`
--

CREATE TABLE `role_permissions` (
  `role_id` int(11) NOT NULL,
  `perm` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `staff_accounts`
--

CREATE TABLE `staff_accounts` (
  `id` bigint(20) NOT NULL,
  `org_id` bigint(20) NOT NULL,
  `friend_code` varchar(20) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `fullname` varchar(100) DEFAULT NULL,
  `status` tinyint(4) NOT NULL DEFAULT '1',
  `force_password_change` tinyint(4) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `timeline_access_requests`
--

CREATE TABLE `timeline_access_requests` (
  `id` bigint(20) NOT NULL,
  `owner_friend_code` varchar(20) NOT NULL,
  `requester_friend_code` varchar(20) NOT NULL,
  `scope` varchar(20) NOT NULL,
  `org_id` bigint(20) DEFAULT NULL,
  `status` tinyint(4) NOT NULL DEFAULT '0',
  `note` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `decided_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `timeline_profiles`
--

CREATE TABLE `timeline_profiles` (
  `user_id` int(11) NOT NULL,
  `cover_photo_path` varchar(255) DEFAULT NULL,
  `headline` varchar(180) DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `birth_place` varchar(180) DEFAULT NULL,
  `education` text,
  `work` text,
  `family` text,
  `bio` text,
  `settings_json` json DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `username` varchar(50) DEFAULT NULL,
  `friend_code` varchar(20) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `gender` varchar(50) NOT NULL,
  `mobile` varchar(50) NOT NULL,
  `age` varchar(255) NOT NULL DEFAULT '',
  `designation` varchar(255) NOT NULL DEFAULT '',
  `role` int(11) NOT NULL DEFAULT '4',
  `account_kind` enum('personal','publisher') NOT NULL DEFAULT 'personal',
  `publisher_category` varchar(40) NOT NULL DEFAULT '',
  `publisher_tagline` varchar(255) NOT NULL DEFAULT '',
  `image` varchar(100) NOT NULL DEFAULT 'default.jpg',
  `image_blob` longblob,
  `image_type` varchar(100) DEFAULT NULL,
  `status` tinyint(4) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen` timestamp NULL DEFAULT NULL,
  `birthday` date DEFAULT NULL,
  `policy_agreed` tinyint(1) NOT NULL DEFAULT '0',
  `policy_agreed_at` datetime DEFAULT NULL,
  `age_confirmed` tinyint(1) NOT NULL DEFAULT '0',
  `age_confirmed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `user_backgrounds`
--

CREATE TABLE `user_backgrounds` (
  `id` bigint(20) NOT NULL,
  `user_id` int(11) NOT NULL,
  `pronouns` varchar(50) DEFAULT NULL,
  `born_in` varchar(150) DEFAULT NULL,
  `lives_in` varchar(150) DEFAULT NULL,
  `birthday` varchar(50) DEFAULT NULL,
  `relationship_status` varchar(60) DEFAULT NULL,
  `languages` varchar(255) DEFAULT NULL,
  `family_details` text,
  `education_history` text,
  `work_details` text,
  `hobbies` text,
  `social_facebook` varchar(255) DEFAULT NULL,
  `social_instagram` varchar(255) DEFAULT NULL,
  `social_x` varchar(255) DEFAULT NULL,
  `social_linkedin` varchar(255) DEFAULT NULL,
  `about_text` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `user_chat_hidden_messages`
--

CREATE TABLE `user_chat_hidden_messages` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `feedback_id` int(11) NOT NULL,
  `hidden_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_contacts`
--

CREATE TABLE `user_contacts` (
  `id` int(11) NOT NULL,
  `owner_user_id` int(11) NOT NULL,
  `friend_user_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `display_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_user_id` int(11) DEFAULT NULL,
  `contact_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `user_contact_name_history`
--

CREATE TABLE `user_contact_name_history` (
  `id` int(11) NOT NULL,
  `owner_user_id` int(11) NOT NULL,
  `friend_user_id` int(11) NOT NULL,
  `old_name` varchar(255) NOT NULL,
  `new_name` varchar(255) NOT NULL,
  `action` varchar(20) NOT NULL DEFAULT 'rename',
  `changed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `user_message_reactions`
--

CREATE TABLE `user_message_reactions` (
  `id` int(11) NOT NULL,
  `feedback_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `reaction` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_post_categories`
--

CREATE TABLE `user_post_categories` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(140) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category_type` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'topic',
  `is_system` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_profile_settings`
--

CREATE TABLE `user_profile_settings` (
  `user_id` int(11) NOT NULL,
  `theme_color` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cover_image_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `avatar_image_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `profile_visibility` enum('public','friends','only_me','approved_visitors') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'public',
  `about_visibility` enum('public','friends','only_me','approved_visitors') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'friends',
  `gallery_visibility` enum('public','friends','only_me','approved_visitors') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'friends',
  `comment_permission` enum('public','friends','only_me','approved_visitors') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'friends',
  `friend_request_permission` enum('public','friends','only_me','approved_visitors') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'public',
  `message_permission` enum('public','friends','only_me','approved_visitors') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'friends',
  `timeline_visit_approval` tinyint(1) NOT NULL DEFAULT '1',
  `auto_show_timeline` tinyint(1) NOT NULL DEFAULT '1',
  `resurface_old_memories` tinyint(1) NOT NULL DEFAULT '1',
  `show_timeline_reactions` tinyint(1) NOT NULL DEFAULT '1',
  `show_timeline_comments` tinyint(1) NOT NULL DEFAULT '1',
  `archive_memory_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `pin_memory_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `email_notifications` tinyint(1) NOT NULL DEFAULT '1',
  `friend_request_notifications` tinyint(1) NOT NULL DEFAULT '1',
  `comment_notifications` tinyint(1) NOT NULL DEFAULT '1',
  `reaction_notifications` tinyint(1) NOT NULL DEFAULT '1',
  `share_notifications` tinyint(1) NOT NULL DEFAULT '1',
  `blocked_users_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `hidden_users_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `mute_users_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `report_history_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `appearance_mode` varchar(48) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'system',
  `gallery_grid_size` enum('small','medium','large') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'medium',
  `autoplay_videos` tinyint(1) NOT NULL DEFAULT '1',
  `sound_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `app_language` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT 'English',
  `date_format` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT 'F j, Y',
  `allow_download_data` tinyint(1) NOT NULL DEFAULT '1',
  `allow_deactivate_account` tinyint(1) NOT NULL DEFAULT '1',
  `allow_delete_account` tinyint(1) NOT NULL DEFAULT '1',
  `allow_logout_all_devices` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `publisher_workspace_json` longtext COLLATE utf8mb4_unicode_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_sessions`
--

CREATE TABLE `user_sessions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `php_session_id` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `session_token` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `revoked_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_video_calls`
--

CREATE TABLE `user_video_calls` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `call_mode` enum('video','voice') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'video',
  `caller_user_id` int(11) NOT NULL,
  `caller_code` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `callee_user_id` int(11) NOT NULL,
  `callee_code` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('initiated','ringing','active','ended','declined','missed','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'initiated',
  `started_at` datetime DEFAULT NULL,
  `ended_at` datetime DEFAULT NULL,
  `ended_by_user_id` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_video_call_signals`
--

CREATE TABLE `user_video_call_signals` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `call_id` bigint(20) UNSIGNED NOT NULL,
  `from_user_id` int(11) NOT NULL,
  `to_user_id` int(11) NOT NULL,
  `signal_type` enum('offer','answer','ice','decline','end') COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci,
  `consumed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_video_lives`
--

CREATE TABLE `user_video_lives` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `friend_code` varchar(32) CHARACTER SET utf8mb4 NOT NULL DEFAULT '',
  `title` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `stream_key` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `playback_url` varchar(2048) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('draft','scheduled','live','ended','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `visibility` enum('public','friends','private') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'public',
  `device_label` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `device_viewport` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `scheduled_for` datetime DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `ended_at` datetime DEFAULT NULL,
  `viewer_count` int(10) UNSIGNED NOT NULL DEFAULT '0',
  `share_count` int(10) UNSIGNED NOT NULL DEFAULT '0',
  `peak_viewers` int(10) UNSIGNED NOT NULL DEFAULT '0',
  `thumbnail_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Triggers `user_video_lives`
--
DELIMITER $$
CREATE TRIGGER `trg_user_video_lives_guard_bi` BEFORE INSERT ON `user_video_lives` FOR EACH ROW BEGIN
  DECLARE v_friend_code varchar(20);

  SELECT `friend_code`
    INTO v_friend_code
  FROM `users`
  WHERE `id` = NEW.`user_id`
  LIMIT 1;

  IF v_friend_code IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'user_video_lives.user_id must reference an existing user';
  END IF;

  IF NEW.`friend_code` IS NULL OR NEW.`friend_code` = '' THEN
    SET NEW.`friend_code` = v_friend_code;
  ELSEIF NEW.`friend_code` <> v_friend_code THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'user_video_lives.friend_code must match the owner user';
  END IF;

  IF NEW.`ended_at` IS NOT NULL AND NEW.`started_at` IS NOT NULL AND NEW.`ended_at` < NEW.`started_at` THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'user_video_lives.ended_at cannot be earlier than started_at';
  END IF;

  IF NEW.`peak_viewers` < NEW.`viewer_count` THEN
    SET NEW.`peak_viewers` = NEW.`viewer_count`;
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_user_video_lives_guard_bu` BEFORE UPDATE ON `user_video_lives` FOR EACH ROW BEGIN
  DECLARE v_friend_code varchar(20);

  SELECT `friend_code`
    INTO v_friend_code
  FROM `users`
  WHERE `id` = NEW.`user_id`
  LIMIT 1;

  IF v_friend_code IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'user_video_lives.user_id must reference an existing user';
  END IF;

  IF NEW.`friend_code` IS NULL OR NEW.`friend_code` = '' THEN
    SET NEW.`friend_code` = v_friend_code;
  ELSEIF NEW.`friend_code` <> v_friend_code THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'user_video_lives.friend_code must match the owner user';
  END IF;

  IF NEW.`ended_at` IS NOT NULL AND NEW.`started_at` IS NOT NULL AND NEW.`ended_at` < NEW.`started_at` THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'user_video_lives.ended_at cannot be earlier than started_at';
  END IF;

  IF NEW.`peak_viewers` < NEW.`viewer_count` THEN
    SET NEW.`peak_viewers` = NEW.`viewer_count`;
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `user_video_live_comments`
--

CREATE TABLE `user_video_live_comments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `live_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `body` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_video_live_comment_likes`
--

CREATE TABLE `user_video_live_comment_likes` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `comment_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_video_live_guest_requests`
--

CREATE TABLE `user_video_live_guest_requests` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `live_id` bigint(20) UNSIGNED NOT NULL,
  `requester_user_id` int(11) NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'requested',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_video_live_join_requests`
--

CREATE TABLE `user_video_live_join_requests` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `live_id` bigint(20) UNSIGNED NOT NULL,
  `call_id` bigint(20) UNSIGNED DEFAULT NULL,
  `host_user_id` int(11) NOT NULL,
  `viewer_user_id` int(11) NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `responded_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_video_live_reactions`
--

CREATE TABLE `user_video_live_reactions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `live_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `reaction` enum('love','like','fire','wow','clap') COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_video_live_recordings`
--

CREATE TABLE `user_video_live_recordings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `live_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `original_name` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `mime_type` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `file_size` bigint(20) NOT NULL DEFAULT '0',
  `duration_seconds` decimal(10,2) NOT NULL DEFAULT '0.00',
  `recording_blob` longblob,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_video_live_signals`
--

CREATE TABLE `user_video_live_signals` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `live_id` bigint(20) UNSIGNED NOT NULL,
  `sender_user_id` int(11) NOT NULL,
  `receiver_user_id` int(11) NOT NULL,
  `peer_key` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `signal_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload_json` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `consumed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_video_live_usage`
--

CREATE TABLE `user_video_live_usage` (
  `user_id` int(11) NOT NULL,
  `total_sessions` int(11) NOT NULL DEFAULT '0',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_video_live_viewers`
--

CREATE TABLE `user_video_live_viewers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `live_id` bigint(20) UNSIGNED NOT NULL,
  `viewer_user_id` int(11) NOT NULL,
  `joined_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Triggers `user_video_live_viewers`
--
DELIMITER $$
CREATE TRIGGER `trg_user_video_live_viewers_counter_ad` AFTER DELETE ON `user_video_live_viewers` FOR EACH ROW BEGIN
  UPDATE `user_video_lives` `l`
  LEFT JOIN (
    SELECT `live_id`, COUNT(*) AS `active_viewers`
    FROM `user_video_live_viewers`
    WHERE `live_id` = OLD.`live_id`
    GROUP BY `live_id`
  ) `v` ON `v`.`live_id` = `l`.`id`
  SET `l`.`viewer_count` = COALESCE(`v`.`active_viewers`, 0),
      `l`.`peak_viewers` = GREATEST(COALESCE(`l`.`peak_viewers`, 0), COALESCE(`v`.`active_viewers`, 0))
  WHERE `l`.`id` = OLD.`live_id`;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_user_video_live_viewers_counter_ai` AFTER INSERT ON `user_video_live_viewers` FOR EACH ROW BEGIN
  UPDATE `user_video_lives` `l`
  LEFT JOIN (
    SELECT `live_id`, COUNT(*) AS `active_viewers`
    FROM `user_video_live_viewers`
    WHERE `live_id` = NEW.`live_id`
    GROUP BY `live_id`
  ) `v` ON `v`.`live_id` = `l`.`id`
  SET `l`.`viewer_count` = COALESCE(`v`.`active_viewers`, 0),
      `l`.`peak_viewers` = GREATEST(COALESCE(`l`.`peak_viewers`, 0), COALESCE(`v`.`active_viewers`, 0))
  WHERE `l`.`id` = NEW.`live_id`;
END
$$
DELIMITER ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin`
--
ALTER TABLE `admin`
  ADD PRIMARY KEY (`idadmin`),
  ADD UNIQUE KEY `uq_admin_email` (`email`),
  ADD UNIQUE KEY `uq_admin_username` (`username`),
  ADD UNIQUE KEY `uq_admin_friend_code` (`friend_code`),
  ADD KEY `idx_admin_role` (`role`);

--
-- Indexes for table `admin_contacts`
--
ALTER TABLE `admin_contacts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_owner_friend` (`owner_admin_id`,`friend_admin_id`),
  ADD KEY `idx_owner` (`owner_admin_id`),
  ADD KEY `idx_friend` (`friend_admin_id`);

--
-- Indexes for table `admin_portal_handoff`
--
ALTER TABLE `admin_portal_handoff`
  ADD PRIMARY KEY (`token`),
  ADD KEY `idx_admin_portal_handoff_expires` (`expires_at`);

--
-- Indexes for table `admin_security_log`
--
ALTER TABLE `admin_security_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_asl_email` (`email`),
  ADD KEY `idx_asl_admin` (`admin_id`),
  ADD KEY `idx_asl_action` (`action`),
  ADD KEY `idx_asl_created` (`created_at`);

--
-- Indexes for table `capsules`
--
ALTER TABLE `capsules`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_capsule_post` (`post_id`),
  ADD KEY `idx_capsule_unlock` (`unlock_mode`,`unlock_at`);

--
-- Indexes for table `capsule_contributors`
--
ALTER TABLE `capsule_contributors`
  ADD PRIMARY KEY (`capsule_id`,`friend_code`),
  ADD KEY `idx_cc_role` (`role`);

--
-- Indexes for table `capsule_entries`
--
ALTER TABLE `capsule_entries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ce_capsule` (`capsule_id`),
  ADD KEY `idx_ce_author_created` (`author_friend_code`,`created_at`);

--
-- Indexes for table `chat_groups`
--
ALTER TABLE `chat_groups`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_chat_groups_creator` (`created_by_user_id`),
  ADD KEY `idx_chat_groups_status` (`status`);

--
-- Indexes for table `chat_group_hidden_messages`
--
ALTER TABLE `chat_group_hidden_messages`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_chat_group_hidden_message` (`user_id`,`group_message_id`),
  ADD KEY `idx_chat_group_hidden_user` (`user_id`);

--
-- Indexes for table `chat_group_members`
--
ALTER TABLE `chat_group_members`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_chat_group_member` (`group_id`,`user_id`),
  ADD KEY `idx_chat_group_members_user` (`user_id`),
  ADD KEY `idx_chat_group_members_role` (`role`),
  ADD KEY `idx_chat_group_members_left` (`left_at`);

--
-- Indexes for table `chat_group_messages`
--
ALTER TABLE `chat_group_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_chat_group_messages_group` (`group_id`,`created_at`),
  ADD KEY `idx_chat_group_messages_sender` (`sender_user_id`);

--
-- Indexes for table `chat_group_message_reactions`
--
ALTER TABLE `chat_group_message_reactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_group_message_user` (`group_message_id`,`user_id`),
  ADD KEY `idx_group_message_updated` (`group_message_id`,`updated_at`);

--
-- Indexes for table `chat_messages`
--
ALTER TABLE `chat_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pair_time` (`sender_id`,`receiver_id`,`created_at`),
  ADD KEY `idx_receiver_read` (`receiver_id`,`is_read`,`created_at`),
  ADD KEY `idx_delivered_at` (`delivered_at`);

--
-- Indexes for table `chat_typing`
--
ALTER TABLE `chat_typing`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_typing` (`sender_code`,`receiver_code`),
  ADD UNIQUE KEY `uniq_sender_receiver` (`sender_code`,`receiver_code`);

--
-- Indexes for table `contacts`
--
ALTER TABLE `contacts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_contacts_pair` (`user_id`,`contact_user_id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_contact` (`contact_user_id`);

--
-- Indexes for table `contact_requests`
--
ALTER TABLE `contact_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_request_pair` (`from_user_id`,`to_user_id`),
  ADD KEY `idx_to_status` (`to_user_id`,`status`);

--
-- Indexes for table `conversations`
--
ALTER TABLE `conversations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_conv_uuid` (`uuid`),
  ADD KEY `idx_type` (`type`),
  ADD KEY `idx_creator` (`created_by_user_id`);

--
-- Indexes for table `conversation_participants`
--
ALTER TABLE `conversation_participants`
  ADD PRIMARY KEY (`conversation_id`,`user_id`),
  ADD KEY `idx_cp_user` (`user_id`);

--
-- Indexes for table `deleteduser`
--
ALTER TABLE `deleteduser`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_deleteduser_email` (`email`),
  ADD KEY `idx_deleteduser_deleted_at` (`deleted_at`),
  ADD KEY `idx_deleteduser_username` (`username`),
  ADD KEY `idx_deleteduser_friend_code` (`friend_code`);

--
-- Indexes for table `feedback`
--
ALTER TABLE `feedback`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_feedback_receiver_created` (`receiver`,`created_at`),
  ADD KEY `idx_feedback_receiver_read` (`receiver`,`is_read`,`created_at`),
  ADD KEY `idx_feedback_sender_receiver` (`sender`,`receiver`,`created_at`),
  ADD KEY `idx_feedback_channel_receiver` (`channel`,`receiver`,`created_at`),
  ADD KEY `idx_feedback_channel_read` (`channel`,`receiver`,`is_read`,`created_at`),
  ADD KEY `idx_feedback_channel_sender_receiver` (`channel`,`sender`,`receiver`,`created_at`),
  ADD KEY `idx_delivered_at` (`delivered_at`),
  ADD KEY `idx_feedback_chat` (`sender`,`receiver`,`id`),
  ADD KEY `idx_feedback_channel_org_created` (`channel`,`org_id`,`created_at`),
  ADD KEY `idx_feedback_org_sender_receiver_created` (`org_id`,`sender`,`receiver`,`created_at`),
  ADD KEY `idx_feedback_scope_created` (`scope`,`created_at`);

--
-- Indexes for table `feedback_admin`
--
ALTER TABLE `feedback_admin`
  ADD PRIMARY KEY (`id_feedback_admin`),
  ADD KEY `idx_fa_channel_org_created` (`channel`,`org_id`,`created_at`),
  ADD KEY `idx_fa_org_sender_receiver_created` (`org_id`,`sender`,`receiver`,`created_at`),
  ADD KEY `idx_fa_scope_created` (`scope`,`created_at`);

--
-- Indexes for table `group_video_calls`
--
ALTER TABLE `group_video_calls`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_group_video_calls_group` (`group_id`,`status`,`id`),
  ADD KEY `idx_group_video_calls_starter` (`started_by_user_id`,`id`);

--
-- Indexes for table `group_video_call_participants`
--
ALTER TABLE `group_video_call_participants`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_group_video_call_participant` (`call_id`,`user_id`),
  ADD KEY `idx_group_video_call_participant_user` (`user_id`,`invite_status`),
  ADD KEY `idx_group_video_call_participant_call` (`call_id`,`invite_status`);

--
-- Indexes for table `group_video_call_signals`
--
ALTER TABLE `group_video_call_signals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_group_video_call_signals_target` (`to_user_id`,`call_id`,`id`),
  ADD KEY `idx_group_video_call_signals_call` (`call_id`,`id`);

--
-- Indexes for table `live_studio_comments`
--
ALTER TABLE `live_studio_comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_session_comment` (`session_id`,`id`),
  ADD KEY `idx_user_comment` (`user_id`,`id`);

--
-- Indexes for table `live_studio_presence`
--
ALTER TABLE `live_studio_presence`
  ADD PRIMARY KEY (`session_id`,`user_id`),
  ADD KEY `idx_presence_seen` (`session_id`,`last_seen_at`);

--
-- Indexes for table `live_studio_reactions`
--
ALTER TABLE `live_studio_reactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_session_reaction` (`session_id`,`id`),
  ADD KEY `idx_type_reaction` (`session_id`,`reaction_type`,`id`);

--
-- Indexes for table `live_studio_sessions`
--
ALTER TABLE `live_studio_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_host_status` (`host_user_id`,`status`),
  ADD KEY `idx_status_updated` (`status`,`updated_at`);

--
-- Indexes for table `live_studio_signals`
--
ALTER TABLE `live_studio_signals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_signal_to` (`session_id`,`to_user_id`,`id`),
  ADD KEY `idx_signal_from` (`session_id`,`from_user_id`,`id`);

--
-- Indexes for table `live_studio_watch_sessions`
--
ALTER TABLE `live_studio_watch_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_live_host_status` (`live_id`,`host_user_id`,`status`),
  ADD KEY `idx_viewer_status` (`viewer_user_id`,`status`),
  ADD KEY `idx_updated_at` (`updated_at`);

--
-- Indexes for table `managers`
--
ALTER TABLE `managers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_mgr_friend_code` (`friend_code`),
  ADD UNIQUE KEY `uq_mgr_username` (`username`),
  ADD UNIQUE KEY `uq_mgr_email` (`email`),
  ADD KEY `idx_mgr_status_created` (`status`,`created_at`);

--
-- Indexes for table `messages`
--
ALTER TABLE `messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_conv_time` (`conversation_id`,`created_at`),
  ADD KEY `idx_sender` (`sender_user_id`);

--
-- Indexes for table `message_reads`
--
ALTER TABLE `message_reads`
  ADD PRIMARY KEY (`message_id`,`user_id`),
  ADD KEY `idx_mr_user` (`user_id`);

--
-- Indexes for table `notification`
--
ALTER TABLE `notification`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_notification_receiver_read` (`notireceiver`,`is_read`),
  ADD KEY `idx_notification_receiver_created` (`notireceiver`,`created_at`),
  ADD KEY `idx_notification_created` (`created_at`);

--
-- Indexes for table `organizations`
--
ALTER TABLE `organizations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_org_code` (`org_code`),
  ADD KEY `idx_org_owner` (`owner_manager_id`),
  ADD KEY `idx_org_status_created` (`status`,`created_at`);

--
-- Indexes for table `organization_users`
--
ALTER TABLE `organization_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_org_user` (`org_id`,`user_id`);

--
-- Indexes for table `org_attachments`
--
ALTER TABLE `org_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `org_id` (`org_id`,`created_at`),
  ADD KEY `uploaded_by_member_id` (`uploaded_by_member_id`,`created_at`);

--
-- Indexes for table `org_feed_reads`
--
ALTER TABLE `org_feed_reads`
  ADD PRIMARY KEY (`org_id`,`member_id`,`tab`),
  ADD KEY `idx_member` (`member_id`),
  ADD KEY `idx_org` (`org_id`);

--
-- Indexes for table `org_invites`
--
ALTER TABLE `org_invites`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_invite_code` (`invite_code`),
  ADD KEY `idx_invites_org_status` (`org_id`,`status`),
  ADD KEY `idx_invites_expires` (`expires_at`);

--
-- Indexes for table `org_members`
--
ALTER TABLE `org_members`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_org_member_link` (`org_id`,`member_type`,`member_id`),
  ADD KEY `idx_org_members_org_status` (`org_id`,`status`),
  ADD KEY `idx_org_members_role` (`role_id`);

--
-- Indexes for table `org_messages`
--
ALTER TABLE `org_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_orgmsg_org_created` (`org_id`,`created_at`),
  ADD KEY `idx_orgmsg_thread` (`org_id`,`sender_member_id`,`receiver_member_id`,`created_at`),
  ADD KEY `idx_orgmsg_receiver_unread` (`org_id`,`receiver_member_id`,`is_read`,`created_at`),
  ADD KEY `idx_org_receiver_read` (`org_id`,`receiver_member_id`,`read_at`),
  ADD KEY `idx_org_sender_receiver` (`org_id`,`sender_member_id`,`receiver_member_id`),
  ADD KEY `idx_org_messages_thread` (`org_id`,`thread_id`,`created_at`);

--
-- Indexes for table `org_message_threads`
--
ALTER TABLE `org_message_threads`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_org_message_threads_uid` (`uid`),
  ADD KEY `idx_org_pair` (`org_id`,`member_a_id`,`member_b_id`),
  ADD KEY `idx_last_message_at` (`org_id`,`last_message_at`);

--
-- Indexes for table `org_posts`
--
ALTER TABLE `org_posts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_org_posts` (`org_id`,`created_at`),
  ADD KEY `idx_org_posts_type` (`org_id`,`post_type`,`created_at`),
  ADD KEY `idx_org_posts_feed_state` (`org_id`,`is_deleted`,`is_visible`,`post_state`,`created_at`,`id`);

--
-- Indexes for table `org_post_acknowledgements`
--
ALTER TABLE `org_post_acknowledgements`
  ADD PRIMARY KEY (`post_id`,`user_id`),
  ADD KEY `idx_ack_user` (`user_id`,`acknowledged_at`);

--
-- Indexes for table `org_post_attachments`
--
ALTER TABLE `org_post_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_org_post` (`org_id`,`post_id`);

--
-- Indexes for table `org_post_comments`
--
ALTER TABLE `org_post_comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_post_comments` (`post_id`,`created_at`),
  ADD KEY `idx_comment_user` (`user_id`,`created_at`),
  ADD KEY `idx_post_parent` (`post_id`,`parent_id`),
  ADD KEY `idx_opc_parent` (`parent_comment_id`);

--
-- Indexes for table `org_post_flags`
--
ALTER TABLE `org_post_flags`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_flags_post` (`post_id`,`created_at`);

--
-- Indexes for table `org_post_likes`
--
ALTER TABLE `org_post_likes`
  ADD PRIMARY KEY (`post_id`,`user_id`),
  ADD KEY `idx_like_user` (`user_id`),
  ADD KEY `idx_like_post` (`post_id`);

--
-- Indexes for table `org_post_views`
--
ALTER TABLE `org_post_views`
  ADD PRIMARY KEY (`post_id`,`user_id`),
  ADD KEY `idx_view_user` (`user_id`),
  ADD KEY `idx_view_post` (`post_id`);

--
-- Indexes for table `org_roles`
--
ALTER TABLE `org_roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_org_role_name` (`org_id`,`name`),
  ADD KEY `idx_org_roles_org` (`org_id`);

--
-- Indexes for table `org_role_permissions`
--
ALTER TABLE `org_role_permissions`
  ADD PRIMARY KEY (`role_id`,`perm_key`),
  ADD KEY `idx_orp_perm` (`perm_key`);

--
-- Indexes for table `org_rooms`
--
ALTER TABLE `org_rooms`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_org_room_name` (`org_id`,`name`),
  ADD KEY `org_id` (`org_id`,`status`);

--
-- Indexes for table `org_room_members`
--
ALTER TABLE `org_room_members`
  ADD PRIMARY KEY (`room_id`,`member_id`),
  ADD KEY `member_id` (`member_id`),
  ADD KEY `room_id` (`room_id`,`status`);

--
-- Indexes for table `org_room_messages`
--
ALTER TABLE `org_room_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `org_id` (`org_id`,`room_id`,`created_at`),
  ADD KEY `room_id` (`room_id`,`created_at`),
  ADD KEY `sender_member_id` (`sender_member_id`,`created_at`);

--
-- Indexes for table `org_settings`
--
ALTER TABLE `org_settings`
  ADD PRIMARY KEY (`org_id`);

--
-- Indexes for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_password_reset_token_hash` (`token_hash`),
  ADD KEY `idx_username` (`username`),
  ADD KEY `idx_expires` (`expires_at`),
  ADD KEY `idx_account` (`account_type`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_admin_id` (`admin_id`),
  ADD KEY `idx_password_reset_lookup_active` (`account_type`,`username`,`used_at`,`expires_at`);

--
-- Indexes for table `posts`
--
ALTER TABLE `posts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_posts_scope_org_created` (`scope`,`org_id`,`created_at`),
  ADD KEY `idx_posts_author_created` (`author_friend_code`,`created_at`),
  ADD KEY `idx_posts_status_created` (`status`,`created_at`);

--
-- Indexes for table `post_audience`
--
ALTER TABLE `post_audience`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pa_post` (`post_id`),
  ADD KEY `idx_pa_type_role` (`audience_type`,`org_role_id`),
  ADD KEY `idx_pa_type_target` (`audience_type`,`target_friend_code`),
  ADD KEY `idx_pa_relationship` (`relationship_key`);

--
-- Indexes for table `post_media`
--
ALTER TABLE `post_media`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_post_media_post` (`post_id`),
  ADD KEY `idx_post_media_type` (`media_type`);

--
-- Indexes for table `public_comment_likes`
--
ALTER TABLE `public_comment_likes`
  ADD PRIMARY KEY (`comment_id`,`user_id`),
  ADD KEY `idx_user` (`user_id`);

--
-- Indexes for table `public_follows`
--
ALTER TABLE `public_follows`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_follow` (`follower_id`,`following_id`),
  ADD KEY `idx_following` (`following_id`),
  ADD KEY `idx_follower` (`follower_id`);

--
-- Indexes for table `public_posts`
--
ALTER TABLE `public_posts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_created` (`created_at`),
  ADD KEY `idx_public_posts_views_count` (`views_count`),
  ADD KEY `idx_posts_views` (`views_count`),
  ADD KEY `idx_category_id` (`category_id`),
  ADD KEY `idx_public_posts_visibility_activity` (`is_deleted`,`visibility`,`activity_at`,`id`),
  ADD KEY `idx_public_posts_user_activity` (`user_id`,`is_deleted`,`activity_at`,`id`);

--
-- Indexes for table `public_post_attachments`
--
ALTER TABLE `public_post_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_post` (`post_id`);

--
-- Indexes for table `public_post_comments`
--
ALTER TABLE `public_post_comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_post` (`post_id`),
  ADD KEY `idx_parent` (`parent_id`),
  ADD KEY `idx_public_post_comments_post_deleted_created` (`post_id`,`is_deleted`,`created_at`,`id`),
  ADD KEY `fk_pub_comment_user` (`user_id`);

--
-- Indexes for table `public_post_reactions`
--
ALTER TABLE `public_post_reactions`
  ADD PRIMARY KEY (`post_id`,`user_id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_public_post_reactions_post_reaction` (`post_id`,`reaction`);

--
-- Indexes for table `public_post_reads`
--
ALTER TABLE `public_post_reads`
  ADD PRIMARY KEY (`post_id`,`user_id`),
  ADD KEY `idx_public_post_reads_user_post` (`user_id`,`post_id`);

--
-- Indexes for table `public_post_saves`
--
ALTER TABLE `public_post_saves`
  ADD PRIMARY KEY (`post_id`,`user_id`),
  ADD KEY `idx_save_user` (`user_id`),
  ADD KEY `idx_save_post` (`post_id`);

--
-- Indexes for table `public_post_shares`
--
ALTER TABLE `public_post_shares`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_share_post_user` (`post_id`,`user_id`),
  ADD KEY `idx_share_post` (`post_id`),
  ADD KEY `idx_share_user` (`user_id`),
  ADD KEY `idx_share_post_user` (`post_id`,`user_id`);

--
-- Indexes for table `public_post_tags`
--
ALTER TABLE `public_post_tags`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_post` (`post_id`),
  ADD KEY `idx_tagged` (`tagged_user_id`);

--
-- Indexes for table `public_post_views`
--
ALTER TABLE `public_post_views`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_post_user` (`post_id`,`user_id`),
  ADD KEY `idx_post` (`post_id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_views_post` (`post_id`),
  ADD KEY `idx_public_post_views_post_viewed` (`post_id`,`viewed_at`,`user_id`);

--
-- Indexes for table `public_post_view_daily`
--
ALTER TABLE `public_post_view_daily`
  ADD PRIMARY KEY (`post_id`,`view_date`);

--
-- Indexes for table `public_profile_access`
--
ALTER TABLE `public_profile_access`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_owner_viewer` (`owner_id`,`viewer_id`),
  ADD KEY `idx_owner_status` (`owner_id`,`status`),
  ADD KEY `idx_viewer_status` (`viewer_id`,`status`);

--
-- Indexes for table `public_saved_posts`
--
ALTER TABLE `public_saved_posts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_saved` (`user_id`,`post_id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_post` (`post_id`);

--
-- Indexes for table `publisher_name_authority`
--
ALTER TABLE `publisher_name_authority`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_publisher_name_authority_name` (`publisher_name`),
  ADD KEY `idx_publisher_name_authority_option` (`publisher_name_option_id`);

--
-- Indexes for table `publisher_name_options`
--
ALTER TABLE `publisher_name_options`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_publisher_name_option` (`name`);

--
-- Indexes for table `role`
--
ALTER TABLE `role`
  ADD PRIMARY KEY (`idrole`),
  ADD UNIQUE KEY `uq_role_name` (`name`),
  ADD KEY `idx_role_inherits` (`inherits_from`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_roles_name` (`name`),
  ADD KEY `idx_roles_inherits` (`inherits_from`);

--
-- Indexes for table `role_chat_matrix`
--
ALTER TABLE `role_chat_matrix`
  ADD PRIMARY KEY (`from_role`,`to_role`),
  ADD KEY `fk_rcm_to` (`to_role`);

--
-- Indexes for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD PRIMARY KEY (`role_id`,`perm`);

--
-- Indexes for table `staff_accounts`
--
ALTER TABLE `staff_accounts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_staff_friend_code` (`friend_code`),
  ADD UNIQUE KEY `uq_staff_username` (`username`),
  ADD KEY `idx_staff_org_status` (`org_id`,`status`),
  ADD KEY `idx_staff_created` (`created_at`);

--
-- Indexes for table `timeline_access_requests`
--
ALTER TABLE `timeline_access_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_timeline_req` (`owner_friend_code`,`requester_friend_code`,`scope`,`org_id`),
  ADD KEY `idx_timeline_owner_status` (`owner_friend_code`,`status`),
  ADD KEY `idx_timeline_requester_status` (`requester_friend_code`,`status`);

--
-- Indexes for table `timeline_profiles`
--
ALTER TABLE `timeline_profiles`
  ADD PRIMARY KEY (`user_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_users_email` (`email`),
  ADD UNIQUE KEY `uq_users_friend_code` (`friend_code`),
  ADD UNIQUE KEY `uq_users_username` (`username`),
  ADD KEY `idx_users_role` (`role`),
  ADD KEY `idx_last_seen` (`last_seen`);

--
-- Indexes for table `user_backgrounds`
--
ALTER TABLE `user_backgrounds`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_user_backgrounds_user` (`user_id`),
  ADD KEY `idx_user_backgrounds_user` (`user_id`);

--
-- Indexes for table `user_chat_hidden_messages`
--
ALTER TABLE `user_chat_hidden_messages`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_user_chat_hidden_message` (`user_id`,`feedback_id`),
  ADD KEY `idx_user_chat_hidden_user` (`user_id`);

--
-- Indexes for table `user_contacts`
--
ALTER TABLE `user_contacts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_owner_contact_user` (`owner_user_id`,`contact_user_id`),
  ADD UNIQUE KEY `uq_owner_contact_email` (`owner_user_id`,`contact_email`),
  ADD KEY `idx_owner` (`owner_user_id`),
  ADD KEY `idx_contact_user` (`contact_user_id`),
  ADD KEY `idx_contact_email` (`contact_email`);

--
-- Indexes for table `user_contact_name_history`
--
ALTER TABLE `user_contact_name_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_owner_friend` (`owner_user_id`,`friend_user_id`),
  ADD KEY `idx_changed_at` (`changed_at`);

--
-- Indexes for table `user_message_reactions`
--
ALTER TABLE `user_message_reactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_feedback_user` (`feedback_id`,`user_id`),
  ADD KEY `idx_feedback_updated` (`feedback_id`,`updated_at`);

--
-- Indexes for table `user_post_categories`
--
ALTER TABLE `user_post_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_user_slug` (`user_id`,`slug`),
  ADD KEY `idx_user_type` (`user_id`,`category_type`);

--
-- Indexes for table `user_profile_settings`
--
ALTER TABLE `user_profile_settings`
  ADD PRIMARY KEY (`user_id`);

--
-- Indexes for table `user_sessions`
--
ALTER TABLE `user_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_php_session_id` (`php_session_id`),
  ADD UNIQUE KEY `uniq_session_token` (`session_token`),
  ADD KEY `idx_user_sessions_user_active` (`user_id`,`revoked_at`),
  ADD KEY `idx_user_sessions_last_seen` (`last_seen_at`);

--
-- Indexes for table `user_video_calls`
--
ALTER TABLE `user_video_calls`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_video_calls_caller` (`caller_user_id`,`created_at`),
  ADD KEY `idx_video_calls_callee` (`callee_user_id`,`created_at`),
  ADD KEY `idx_video_calls_pair` (`caller_code`,`callee_code`,`status`),
  ADD KEY `idx_video_calls_status` (`status`,`updated_at`);

--
-- Indexes for table `user_video_call_signals`
--
ALTER TABLE `user_video_call_signals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_video_signal_call` (`call_id`,`id`),
  ADD KEY `idx_video_signal_target` (`to_user_id`,`consumed_at`,`id`);

--
-- Indexes for table `user_video_lives`
--
ALTER TABLE `user_video_lives`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_user_video_lives_stream_key` (`stream_key`),
  ADD KEY `idx_user_video_lives_owner` (`user_id`,`status`,`created_at`),
  ADD KEY `idx_user_video_lives_status` (`status`,`visibility`,`started_at`),
  ADD KEY `idx_user_video_lives_schedule` (`scheduled_for`);

--
-- Indexes for table `user_video_live_comments`
--
ALTER TABLE `user_video_live_comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_video_live_comments_live` (`live_id`,`created_at`),
  ADD KEY `idx_user_video_live_comments_user` (`user_id`,`created_at`);

--
-- Indexes for table `user_video_live_comment_likes`
--
ALTER TABLE `user_video_live_comment_likes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_comment_user` (`comment_id`,`user_id`),
  ADD KEY `idx_comment` (`comment_id`),
  ADD KEY `idx_user` (`user_id`);

--
-- Indexes for table `user_video_live_guest_requests`
--
ALTER TABLE `user_video_live_guest_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_live_requester` (`live_id`,`requester_user_id`),
  ADD KEY `idx_live_status` (`live_id`,`status`),
  ADD KEY `idx_requester_status` (`requester_user_id`,`status`);

--
-- Indexes for table `user_video_live_join_requests`
--
ALTER TABLE `user_video_live_join_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_live_viewer` (`live_id`,`viewer_user_id`),
  ADD KEY `idx_live_host_status` (`live_id`,`host_user_id`,`status`),
  ADD KEY `idx_live_viewer_status` (`live_id`,`viewer_user_id`,`status`),
  ADD KEY `fk_user_video_live_join_requests_call` (`call_id`),
  ADD KEY `fk_user_video_live_join_requests_host` (`host_user_id`),
  ADD KEY `fk_user_video_live_join_requests_viewer` (`viewer_user_id`);

--
-- Indexes for table `user_video_live_reactions`
--
ALTER TABLE `user_video_live_reactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_user_video_live_reactions_live_user` (`live_id`,`user_id`),
  ADD KEY `idx_user_video_live_reactions_live` (`live_id`,`reaction`),
  ADD KEY `idx_live_user_created` (`live_id`,`user_id`,`created_at`),
  ADD KEY `fk_user_video_live_reactions_user` (`user_id`);

--
-- Indexes for table `user_video_live_recordings`
--
ALTER TABLE `user_video_live_recordings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_live_id` (`live_id`),
  ADD KEY `idx_user_created` (`user_id`,`created_at`);

--
-- Indexes for table `user_video_live_signals`
--
ALTER TABLE `user_video_live_signals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_receiver_live` (`receiver_user_id`,`live_id`,`consumed_at`,`id`),
  ADD KEY `idx_peer_live` (`peer_key`,`live_id`,`id`);

--
-- Indexes for table `user_video_live_usage`
--
ALTER TABLE `user_video_live_usage`
  ADD PRIMARY KEY (`user_id`);

--
-- Indexes for table `user_video_live_viewers`
--
ALTER TABLE `user_video_live_viewers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_live_viewer` (`live_id`,`viewer_user_id`),
  ADD KEY `idx_live_last_seen` (`live_id`,`last_seen_at`),
  ADD KEY `idx_viewer_last_seen` (`viewer_user_id`,`last_seen_at`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admin`
--
ALTER TABLE `admin`
  MODIFY `idadmin` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `admin_contacts`
--
ALTER TABLE `admin_contacts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `admin_security_log`
--
ALTER TABLE `admin_security_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `capsules`
--
ALTER TABLE `capsules`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `capsule_entries`
--
ALTER TABLE `capsule_entries`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chat_groups`
--
ALTER TABLE `chat_groups`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `chat_group_hidden_messages`
--
ALTER TABLE `chat_group_hidden_messages`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `chat_group_members`
--
ALTER TABLE `chat_group_members`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `chat_group_messages`
--
ALTER TABLE `chat_group_messages`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=69;

--
-- AUTO_INCREMENT for table `chat_group_message_reactions`
--
ALTER TABLE `chat_group_message_reactions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chat_messages`
--
ALTER TABLE `chat_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chat_typing`
--
ALTER TABLE `chat_typing`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=243;

--
-- AUTO_INCREMENT for table `contacts`
--
ALTER TABLE `contacts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `contact_requests`
--
ALTER TABLE `contact_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `conversations`
--
ALTER TABLE `conversations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `deleteduser`
--
ALTER TABLE `deleteduser`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `feedback`
--
ALTER TABLE `feedback`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=256;

--
-- AUTO_INCREMENT for table `feedback_admin`
--
ALTER TABLE `feedback_admin`
  MODIFY `id_feedback_admin` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=234;

--
-- AUTO_INCREMENT for table `group_video_calls`
--
ALTER TABLE `group_video_calls`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=81;

--
-- AUTO_INCREMENT for table `group_video_call_participants`
--
ALTER TABLE `group_video_call_participants`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=534;

--
-- AUTO_INCREMENT for table `group_video_call_signals`
--
ALTER TABLE `group_video_call_signals`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `live_studio_comments`
--
ALTER TABLE `live_studio_comments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `live_studio_reactions`
--
ALTER TABLE `live_studio_reactions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `live_studio_sessions`
--
ALTER TABLE `live_studio_sessions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `live_studio_signals`
--
ALTER TABLE `live_studio_signals`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `live_studio_watch_sessions`
--
ALTER TABLE `live_studio_watch_sessions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `managers`
--
ALTER TABLE `managers`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `messages`
--
ALTER TABLE `messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notification`
--
ALTER TABLE `notification`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1601;

--
-- AUTO_INCREMENT for table `organizations`
--
ALTER TABLE `organizations`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `organization_users`
--
ALTER TABLE `organization_users`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=130;

--
-- AUTO_INCREMENT for table `org_attachments`
--
ALTER TABLE `org_attachments`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `org_invites`
--
ALTER TABLE `org_invites`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `org_members`
--
ALTER TABLE `org_members`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=98;

--
-- AUTO_INCREMENT for table `org_messages`
--
ALTER TABLE `org_messages`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `org_message_threads`
--
ALTER TABLE `org_message_threads`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `org_posts`
--
ALTER TABLE `org_posts`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `org_post_attachments`
--
ALTER TABLE `org_post_attachments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `org_post_comments`
--
ALTER TABLE `org_post_comments`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `org_post_flags`
--
ALTER TABLE `org_post_flags`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `org_roles`
--
ALTER TABLE `org_roles`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=45;

--
-- AUTO_INCREMENT for table `org_rooms`
--
ALTER TABLE `org_rooms`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `org_room_messages`
--
ALTER TABLE `org_room_messages`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `posts`
--
ALTER TABLE `posts`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `post_audience`
--
ALTER TABLE `post_audience`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `post_media`
--
ALTER TABLE `post_media`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `public_follows`
--
ALTER TABLE `public_follows`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- AUTO_INCREMENT for table `public_posts`
--
ALTER TABLE `public_posts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=421;

--
-- AUTO_INCREMENT for table `public_post_attachments`
--
ALTER TABLE `public_post_attachments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=361;

--
-- AUTO_INCREMENT for table `public_post_comments`
--
ALTER TABLE `public_post_comments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `public_post_shares`
--
ALTER TABLE `public_post_shares`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `public_post_tags`
--
ALTER TABLE `public_post_tags`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `public_post_views`
--
ALTER TABLE `public_post_views`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `public_profile_access`
--
ALTER TABLE `public_profile_access`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `public_saved_posts`
--
ALTER TABLE `public_saved_posts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `publisher_name_authority`
--
ALTER TABLE `publisher_name_authority`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `publisher_name_options`
--
ALTER TABLE `publisher_name_options`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `role`
--
ALTER TABLE `role`
  MODIFY `idrole` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `staff_accounts`
--
ALTER TABLE `staff_accounts`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `timeline_access_requests`
--
ALTER TABLE `timeline_access_requests`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=66;

--
-- AUTO_INCREMENT for table `user_backgrounds`
--
ALTER TABLE `user_backgrounds`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `user_chat_hidden_messages`
--
ALTER TABLE `user_chat_hidden_messages`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_contacts`
--
ALTER TABLE `user_contacts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=57;

--
-- AUTO_INCREMENT for table `user_contact_name_history`
--
ALTER TABLE `user_contact_name_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_message_reactions`
--
ALTER TABLE `user_message_reactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_post_categories`
--
ALTER TABLE `user_post_categories`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3337;

--
-- AUTO_INCREMENT for table `user_sessions`
--
ALTER TABLE `user_sessions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9735;

--
-- AUTO_INCREMENT for table `user_video_calls`
--
ALTER TABLE `user_video_calls`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1380;

--
-- AUTO_INCREMENT for table `user_video_call_signals`
--
ALTER TABLE `user_video_call_signals`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=852;

--
-- AUTO_INCREMENT for table `user_video_lives`
--
ALTER TABLE `user_video_lives`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=37;

--
-- AUTO_INCREMENT for table `user_video_live_comments`
--
ALTER TABLE `user_video_live_comments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_video_live_comment_likes`
--
ALTER TABLE `user_video_live_comment_likes`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_video_live_guest_requests`
--
ALTER TABLE `user_video_live_guest_requests`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_video_live_join_requests`
--
ALTER TABLE `user_video_live_join_requests`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_video_live_reactions`
--
ALTER TABLE `user_video_live_reactions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_video_live_recordings`
--
ALTER TABLE `user_video_live_recordings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_video_live_signals`
--
ALTER TABLE `user_video_live_signals`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=55;

--
-- AUTO_INCREMENT for table `user_video_live_viewers`
--
ALTER TABLE `user_video_live_viewers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `admin`
--
ALTER TABLE `admin`
  ADD CONSTRAINT `fk_admin_role` FOREIGN KEY (`role`) REFERENCES `role` (`idrole`) ON UPDATE CASCADE;

--
-- Constraints for table `admin_contacts`
--
ALTER TABLE `admin_contacts`
  ADD CONSTRAINT `fk_ac_friend` FOREIGN KEY (`friend_admin_id`) REFERENCES `admin` (`idadmin`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_ac_owner` FOREIGN KEY (`owner_admin_id`) REFERENCES `admin` (`idadmin`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `chat_messages`
--
ALTER TABLE `chat_messages`
  ADD CONSTRAINT `fk_chat_receiver` FOREIGN KEY (`receiver_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_chat_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `contacts`
--
ALTER TABLE `contacts`
  ADD CONSTRAINT `fk_c_contact` FOREIGN KEY (`contact_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_c_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `contact_requests`
--
ALTER TABLE `contact_requests`
  ADD CONSTRAINT `fk_cr_from` FOREIGN KEY (`from_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_cr_to` FOREIGN KEY (`to_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `conversations`
--
ALTER TABLE `conversations`
  ADD CONSTRAINT `fk_conv_creator` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `conversation_participants`
--
ALTER TABLE `conversation_participants`
  ADD CONSTRAINT `fk_cp_conv` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_cp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `messages`
--
ALTER TABLE `messages`
  ADD CONSTRAINT `fk_msg_conv` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_msg_sender` FOREIGN KEY (`sender_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `message_reads`
--
ALTER TABLE `message_reads`
  ADD CONSTRAINT `fk_mr_msg` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_mr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `organization_users`
--
ALTER TABLE `organization_users`
  ADD CONSTRAINT `fk_org_users_org` FOREIGN KEY (`org_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `org_posts`
--
ALTER TABLE `org_posts`
  ADD CONSTRAINT `fk_org_posts_org` FOREIGN KEY (`org_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `org_post_acknowledgements`
--
ALTER TABLE `org_post_acknowledgements`
  ADD CONSTRAINT `fk_org_ack_post` FOREIGN KEY (`post_id`) REFERENCES `org_posts` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `org_post_comments`
--
ALTER TABLE `org_post_comments`
  ADD CONSTRAINT `fk_org_comments_post` FOREIGN KEY (`post_id`) REFERENCES `org_posts` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `org_post_flags`
--
ALTER TABLE `org_post_flags`
  ADD CONSTRAINT `fk_org_flags_post` FOREIGN KEY (`post_id`) REFERENCES `org_posts` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  ADD CONSTRAINT `fk_password_reset_tokens_admin` FOREIGN KEY (`admin_id`) REFERENCES `admin` (`idadmin`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_password_reset_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `public_comment_likes`
--
ALTER TABLE `public_comment_likes`
  ADD CONSTRAINT `fk_pub_comment_like_comment` FOREIGN KEY (`comment_id`) REFERENCES `public_post_comments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_pub_comment_like_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `public_follows`
--
ALTER TABLE `public_follows`
  ADD CONSTRAINT `fk_public_follows_follower` FOREIGN KEY (`follower_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_public_follows_following` FOREIGN KEY (`following_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `public_posts`
--
ALTER TABLE `public_posts`
  ADD CONSTRAINT `fk_public_posts_category` FOREIGN KEY (`category_id`) REFERENCES `user_post_categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_public_posts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `public_post_attachments`
--
ALTER TABLE `public_post_attachments`
  ADD CONSTRAINT `fk_pub_attach_post` FOREIGN KEY (`post_id`) REFERENCES `public_posts` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `public_post_comments`
--
ALTER TABLE `public_post_comments`
  ADD CONSTRAINT `fk_pub_comment_parent` FOREIGN KEY (`parent_id`) REFERENCES `public_post_comments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_pub_comment_post` FOREIGN KEY (`post_id`) REFERENCES `public_posts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_pub_comment_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `public_post_reactions`
--
ALTER TABLE `public_post_reactions`
  ADD CONSTRAINT `fk_pub_react_post` FOREIGN KEY (`post_id`) REFERENCES `public_posts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_pub_react_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `public_post_reads`
--
ALTER TABLE `public_post_reads`
  ADD CONSTRAINT `fk_pub_reads_post` FOREIGN KEY (`post_id`) REFERENCES `public_posts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_pub_reads_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `public_post_saves`
--
ALTER TABLE `public_post_saves`
  ADD CONSTRAINT `fk_pub_saves_post` FOREIGN KEY (`post_id`) REFERENCES `public_posts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_pub_saves_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `public_post_shares`
--
ALTER TABLE `public_post_shares`
  ADD CONSTRAINT `fk_pub_shares_post` FOREIGN KEY (`post_id`) REFERENCES `public_posts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_pub_shares_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `public_post_tags`
--
ALTER TABLE `public_post_tags`
  ADD CONSTRAINT `fk_pub_tags_post` FOREIGN KEY (`post_id`) REFERENCES `public_posts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_pub_tags_user` FOREIGN KEY (`tagged_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `public_post_views`
--
ALTER TABLE `public_post_views`
  ADD CONSTRAINT `fk_pub_views_post` FOREIGN KEY (`post_id`) REFERENCES `public_posts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_pub_views_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `public_post_view_daily`
--
ALTER TABLE `public_post_view_daily`
  ADD CONSTRAINT `fk_pub_view_daily_post` FOREIGN KEY (`post_id`) REFERENCES `public_posts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `public_profile_access`
--
ALTER TABLE `public_profile_access`
  ADD CONSTRAINT `fk_public_profile_access_owner` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_public_profile_access_viewer` FOREIGN KEY (`viewer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `public_saved_posts`
--
ALTER TABLE `public_saved_posts`
  ADD CONSTRAINT `fk_public_saved_posts_post` FOREIGN KEY (`post_id`) REFERENCES `public_posts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_public_saved_posts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `role`
--
ALTER TABLE `role`
  ADD CONSTRAINT `fk_role_inherits` FOREIGN KEY (`inherits_from`) REFERENCES `role` (`idrole`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `role_chat_matrix`
--
ALTER TABLE `role_chat_matrix`
  ADD CONSTRAINT `fk_rcm_from` FOREIGN KEY (`from_role`) REFERENCES `role` (`idrole`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_rcm_to` FOREIGN KEY (`to_role`) REFERENCES `role` (`idrole`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `role` (`idrole`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `timeline_profiles`
--
ALTER TABLE `timeline_profiles`
  ADD CONSTRAINT `fk_timeline_profiles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_role` FOREIGN KEY (`role`) REFERENCES `role` (`idrole`) ON UPDATE CASCADE;

--
-- Constraints for table `user_backgrounds`
--
ALTER TABLE `user_backgrounds`
  ADD CONSTRAINT `fk_user_backgrounds_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `user_post_categories`
--
ALTER TABLE `user_post_categories`
  ADD CONSTRAINT `fk_user_post_categories_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `user_sessions`
--
ALTER TABLE `user_sessions`
  ADD CONSTRAINT `fk_user_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_video_call_signals`
--
ALTER TABLE `user_video_call_signals`
  ADD CONSTRAINT `fk_video_signal_call` FOREIGN KEY (`call_id`) REFERENCES `user_video_calls` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_video_lives`
--
ALTER TABLE `user_video_lives`
  ADD CONSTRAINT `fk_user_video_lives_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `user_video_live_comments`
--
ALTER TABLE `user_video_live_comments`
  ADD CONSTRAINT `fk_user_video_live_comments_live` FOREIGN KEY (`live_id`) REFERENCES `user_video_lives` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_user_video_live_comments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `user_video_live_comment_likes`
--
ALTER TABLE `user_video_live_comment_likes`
  ADD CONSTRAINT `fk_user_video_live_comment_likes_comment` FOREIGN KEY (`comment_id`) REFERENCES `user_video_live_comments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_user_video_live_comment_likes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `user_video_live_guest_requests`
--
ALTER TABLE `user_video_live_guest_requests`
  ADD CONSTRAINT `fk_user_video_live_guest_requests_live` FOREIGN KEY (`live_id`) REFERENCES `user_video_lives` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_user_video_live_guest_requests_user` FOREIGN KEY (`requester_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `user_video_live_join_requests`
--
ALTER TABLE `user_video_live_join_requests`
  ADD CONSTRAINT `fk_user_video_live_join_requests_call` FOREIGN KEY (`call_id`) REFERENCES `user_video_calls` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_user_video_live_join_requests_host` FOREIGN KEY (`host_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_user_video_live_join_requests_live` FOREIGN KEY (`live_id`) REFERENCES `user_video_lives` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_user_video_live_join_requests_viewer` FOREIGN KEY (`viewer_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `user_video_live_reactions`
--
ALTER TABLE `user_video_live_reactions`
  ADD CONSTRAINT `fk_user_video_live_reactions_live` FOREIGN KEY (`live_id`) REFERENCES `user_video_lives` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_user_video_live_reactions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `user_video_live_recordings`
--
ALTER TABLE `user_video_live_recordings`
  ADD CONSTRAINT `fk_user_video_live_recordings_live` FOREIGN KEY (`live_id`) REFERENCES `user_video_lives` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_user_video_live_recordings_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `user_video_live_usage`
--
ALTER TABLE `user_video_live_usage`
  ADD CONSTRAINT `fk_user_video_live_usage_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `user_video_live_viewers`
--
ALTER TABLE `user_video_live_viewers`
  ADD CONSTRAINT `fk_user_video_live_viewers_live` FOREIGN KEY (`live_id`) REFERENCES `user_video_lives` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_user_video_live_viewers_user` FOREIGN KEY (`viewer_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
