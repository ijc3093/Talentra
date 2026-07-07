-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost:8889
-- Generation Time: Jul 06, 2026 at 04:00 AM
-- Server version: 5.7.44
-- PHP Version: 7.4.33

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

SET FOREIGN_KEY_CHECKS = 0;

--
-- Database: `talentra`
--

-- --------------------------------------------------------

--
-- Table structure for table `admin`
--

DROP TABLE IF EXISTS `admin`;
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

--
-- Dumping data for table `admin`
--

INSERT INTO `admin` (`idadmin`, `fullname`, `username`, `friend_code`, `email`, `password`, `gender`, `mobile`, `designation`, `role`, `image`, `image_blob`, `image_type`, `status`, `created_at`, `force_password_change`, `last_login_at`, `failed_login_attempts`, `locked_until`, `reset_token_hash`, `reset_token_expires`, `reset_request_count`, `reset_request_window_start`, `reset_last_requested_at`, `linked_personal_user_id`, `linked_publisher_user_id`, `linked_manager_id`) VALUES
(9, 'Super Admin', 'admin', 'ADM-0000-0000', 'admin@example.com', '$2y$10$lxKZ5.IuLW1KRkXhqMRxEODu8sH4Vt9kCN1syexRwmtnso3OZwB4m', 'N/A', 'N/A', 'Admin', 1, 'default.jpg', NULL, NULL, 1, '2026-07-01 22:49:15', 0, '2026-07-01 18:24:45', 0, NULL, NULL, NULL, 0, NULL, NULL, 67, 68, 20),
(10, 'Akin Teniola', 'akin', 'ADM-WOEQ-Z19T', 'akin@gmail.com', '$2y$10$H2sxPyAd/abHwt4F07Vm/.rLKfOeoH7qmtkDIabZIkc38ksLxsKlS', 'N/A', 'N/A', 'Internal', 2, 'default.jpg', NULL, NULL, 1, '2026-07-01 23:00:38', 0, '2026-07-01 18:00:58', 0, NULL, NULL, NULL, 0, NULL, NULL, 69, 70, 22),
(11, 'Brenda Renee', 'brenda', 'ADM-1YY3-K5WN', 'brenda@gmail.com', '$2y$10$1eYHT27NTfpoCuhzNL/BluAfU6ka2Jzv/SEaLGyADRgE3PV5x9SBm', 'N/A', 'N/A', 'Internal', 12, 'default.jpg', NULL, NULL, 1, '2026-07-01 23:13:49', 0, NULL, 0, NULL, NULL, NULL, 0, NULL, NULL, 71, 72, 23);

-- --------------------------------------------------------

--
-- Table structure for table `admin_contacts`
--

DROP TABLE IF EXISTS `admin_contacts`;
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

DROP TABLE IF EXISTS `admin_portal_handoff`;
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

DROP TABLE IF EXISTS `admin_security_log`;
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

DROP TABLE IF EXISTS `capsules`;
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

DROP TABLE IF EXISTS `capsule_contributors`;
CREATE TABLE `capsule_contributors` (
  `capsule_id` bigint(20) NOT NULL,
  `friend_code` varchar(20) NOT NULL,
  `role` varchar(20) NOT NULL DEFAULT 'viewer'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `capsule_entries`
--

DROP TABLE IF EXISTS `capsule_entries`;
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

DROP TABLE IF EXISTS `chat_groups`;
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

DROP TABLE IF EXISTS `chat_group_hidden_messages`;
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

DROP TABLE IF EXISTS `chat_group_members`;
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

DROP TABLE IF EXISTS `chat_group_messages`;
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

DROP TABLE IF EXISTS `chat_group_message_reactions`;
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

DROP TABLE IF EXISTS `chat_messages`;
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

DROP TABLE IF EXISTS `chat_typing`;
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

DROP TABLE IF EXISTS `contacts`;
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

DROP TABLE IF EXISTS `contact_requests`;
CREATE TABLE `contact_requests` (
  `id` int(11) NOT NULL,
  `from_user_id` int(11) NOT NULL,
  `to_user_id` int(11) NOT NULL,
  `status` enum('pending','accepted','declined','blocked') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `contact_requests`
--

INSERT INTO `contact_requests` (`id`, `from_user_id`, `to_user_id`, `status`, `created_at`, `updated_at`) VALUES
(31, 74, 66, 'accepted', '2026-07-03 01:40:52', '2026-07-03 01:41:16'),
(32, 75, 74, 'pending', '2026-07-05 23:00:21', NULL),
(33, 75, 69, 'pending', '2026-07-05 23:08:46', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `conversations`
--

DROP TABLE IF EXISTS `conversations`;
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

DROP TABLE IF EXISTS `conversation_participants`;
CREATE TABLE `conversation_participants` (
  `conversation_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `joined_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `deleteduser`
--

DROP TABLE IF EXISTS `deleteduser`;
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

DROP TABLE IF EXISTS `feedback`;
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

DROP TABLE IF EXISTS `feedback_admin`;
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

DROP TABLE IF EXISTS `group_video_calls`;
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

DROP TABLE IF EXISTS `group_video_call_participants`;
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

DROP TABLE IF EXISTS `group_video_call_signals`;
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

DROP TABLE IF EXISTS `live_studio_comments`;
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

DROP TABLE IF EXISTS `live_studio_presence`;
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

DROP TABLE IF EXISTS `live_studio_reactions`;
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

DROP TABLE IF EXISTS `live_studio_sessions`;
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

DROP TABLE IF EXISTS `live_studio_signals`;
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

DROP TABLE IF EXISTS `live_studio_watch_sessions`;
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

DROP TABLE IF EXISTS `managers`;
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

--
-- Dumping data for table `managers`
--

INSERT INTO `managers` (`id`, `friend_code`, `username`, `email`, `password`, `fullname`, `status`, `force_password_change`, `created_at`, `last_seen`, `publisher_user_id`) VALUES
(20, 'MGR-4E61-2156', 'admin_pub', 'admin+pub.9@example.com', '$2y$10$lxKZ5.IuLW1KRkXhqMRxEODu8sH4Vt9kCN1syexRwmtnso3OZwB4m', 'Super Admin Admin Publisher', 1, 0, '2026-07-01 17:53:00', NULL, 68),
(21, 'MGR-PUB-2782', 'publisher_orgs', 'publisher-orgs@talentra.internal', '$2y$10$XTXKeKJSoJsqmZdjnCkAcOpRj12WRuBktIaX6arzyfN.vXpm31Sji', 'Publisher Organizations', 1, 0, '2026-07-01 17:53:00', NULL, NULL),
(22, 'MGR-63E7-2238', 'akin_pub', 'akin+pub.10@gmail.com', '$2y$10$TbwJmt1dHFn1bPjmsbm15.IT7XRRB/hUadgNuh5KDX0fDblH.tgoi', 'Akin Teniola Admin Publisher', 1, 0, '2026-07-01 18:00:38', NULL, 70),
(23, 'MGR-096F-4052', 'brenda_pub', 'brenda+pub.11@gmail.com', '$2y$10$Er78pelLRajUEW6.JjFHSOYAJ4YAOd3XEiFccmS7IQxk.KsF/Ozzq', 'Brenda Renee Admin Publisher', 1, 0, '2026-07-01 18:13:49', NULL, 72),
(24, 'MGR-1DFD-5640', 'cnn', 'cnn@gmail.com', '$2y$10$bxU5b35u/MtZ4oPRZ2WciODRRNkWBS6XOMPezGPkFPKSo/lzW./GW', 'CNN', 1, 0, '2026-07-01 18:46:51', '2026-07-05 22:12:46', 73);

-- --------------------------------------------------------

--
-- Table structure for table `messages`
--

DROP TABLE IF EXISTS `messages`;
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

DROP TABLE IF EXISTS `message_reads`;
CREATE TABLE `message_reads` (
  `message_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `read_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `notification`
--

DROP TABLE IF EXISTS `notification`;
CREATE TABLE `notification` (
  `id` int(11) NOT NULL,
  `notiuser` varchar(100) NOT NULL,
  `notireceiver` varchar(100) NOT NULL,
  `notitype` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `read_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `notification`
--

INSERT INTO `notification` (`id`, `notiuser`, `notireceiver`, `notitype`, `created_at`, `is_read`, `read_at`) VALUES
(1601, 'isaaccuma3093@gmail.com', 'Admin', 'Create Account', '2026-07-01 19:46:01', 0, NULL),
(1602, 'cnn@gmail.com', 'Admin', 'Create Publisher Account', '2026-07-01 23:46:51', 0, NULL),
(1603, 'john_k@gmail.com', 'Admin', 'Create Account', '2026-07-03 01:40:02', 0, NULL),
(1604, 'maka@gmail.com', 'Admin', 'Create Account', '2026-07-05 16:39:20', 0, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `organizations`
--

DROP TABLE IF EXISTS `organizations`;
CREATE TABLE `organizations` (
  `id` bigint(20) NOT NULL,
  `org_code` varchar(16) NOT NULL,
  `name` varchar(120) NOT NULL,
  `owner_manager_id` bigint(20) NOT NULL,
  `status` tinyint(4) NOT NULL DEFAULT '1',
  `is_publisher_org` tinyint(1) NOT NULL DEFAULT '0',
  `publisher_user_id` bigint(20) DEFAULT NULL,
  `publisher_category` varchar(40) NOT NULL DEFAULT '',
  `org_kind` enum('community','shop') NOT NULL DEFAULT 'community',
  `business_model` enum('retail','wholesale','manufacturing','services','subscription','licensing','advertising') NOT NULL DEFAULT 'retail',
  `platform_plan_id` bigint(20) DEFAULT NULL,
  `rent_status` enum('trial','active','overdue','suspended') NOT NULL DEFAULT 'trial',
  `rent_paid_until` datetime DEFAULT NULL,
  `rent_trial_ends_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `organizations`
--

INSERT INTO `organizations` (`id`, `org_code`, `name`, `owner_manager_id`, `status`, `is_publisher_org`, `publisher_user_id`, `publisher_category`, `org_kind`, `business_model`, `platform_plan_id`, `rent_status`, `rent_paid_until`, `rent_trial_ends_at`, `created_at`, `updated_at`) VALUES
(23, 'ORG-3896-29E6', 'Super Admin Admin Publisher', 20, 1, 1, 68, 'news', 'shop', 'advertising', 1, 'trial', NULL, '2026-07-31 17:53:00', '2026-07-01 17:53:00', '2026-07-01 21:32:14'),
(24, 'ORG-5C85-D4E8', 'Akin Teniola Admin Publisher', 22, 1, 1, 70, 'news', 'shop', 'retail', 1, 'trial', NULL, '2026-07-31 18:00:38', '2026-07-01 18:00:38', '2026-07-01 18:52:32'),
(25, 'ORG-9314-20FD', 'Brenda Renee Admin Publisher', 23, 1, 1, 72, 'news', 'shop', 'retail', 1, 'trial', NULL, '2026-07-31 18:13:49', '2026-07-01 18:13:49', '2026-07-01 21:27:27'),
(26, 'ORG-5454-C58E', 'CNN', 24, 1, 1, 73, 'news', 'shop', 'advertising', 1, 'trial', NULL, '2026-07-31 18:46:19', '2026-07-01 18:46:19', '2026-07-05 22:11:13');

-- --------------------------------------------------------

--
-- Table structure for table `organization_users`
--

DROP TABLE IF EXISTS `organization_users`;
CREATE TABLE `organization_users` (
  `id` bigint(20) NOT NULL,
  `org_id` bigint(20) NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `role` enum('admin','manager','staff') NOT NULL,
  `display_name` varchar(120) DEFAULT NULL,
  `avatar` varchar(255) DEFAULT NULL,
  `joined_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `organization_users`
--

INSERT INTO `organization_users` (`id`, `org_id`, `user_id`, `role`, `display_name`, `avatar`, `joined_at`) VALUES
(130, 23, 98, 'manager', NULL, NULL, '2026-07-01 17:53:00'),
(131, 23, 99, 'manager', NULL, NULL, '2026-07-01 17:53:00'),
(136, 24, 102, 'manager', NULL, NULL, '2026-07-01 18:00:38'),
(137, 24, 103, 'manager', NULL, NULL, '2026-07-01 18:00:38'),
(140, 25, 105, 'manager', NULL, NULL, '2026-07-01 18:13:49'),
(141, 25, 106, 'manager', NULL, NULL, '2026-07-01 18:13:49'),
(142, 26, 107, 'manager', NULL, NULL, '2026-07-01 18:46:19'),
(143, 26, 108, 'manager', NULL, NULL, '2026-07-01 18:46:51'),
(147, 26, 111, 'staff', NULL, NULL, '2026-07-01 19:21:11');

-- --------------------------------------------------------

--
-- Table structure for table `org_attachments`
--

DROP TABLE IF EXISTS `org_attachments`;
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

DROP TABLE IF EXISTS `org_feed_reads`;
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

DROP TABLE IF EXISTS `org_invites`;
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

DROP TABLE IF EXISTS `org_members`;
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

--
-- Dumping data for table `org_members`
--

INSERT INTO `org_members` (`id`, `org_id`, `member_type`, `member_id`, `role_id`, `relationship_label`, `status`, `joined_at`, `created_at`) VALUES
(98, 23, 'manager', 21, 45, NULL, 1, '2026-07-01 17:53:00', '2026-07-01 17:53:00'),
(99, 23, 'manager', 20, 45, NULL, 1, '2026-07-01 17:53:00', '2026-07-01 17:53:00'),
(102, 24, 'manager', 21, 47, NULL, 1, '2026-07-01 18:00:38', '2026-07-01 18:00:38'),
(103, 24, 'manager', 22, 47, NULL, 1, '2026-07-01 18:00:38', '2026-07-01 18:00:38'),
(105, 25, 'manager', 21, 49, NULL, 1, '2026-07-01 18:13:49', '2026-07-01 18:13:49'),
(106, 25, 'manager', 23, 49, NULL, 1, '2026-07-01 18:13:49', '2026-07-01 18:13:49'),
(107, 26, 'manager', 21, 51, NULL, 1, '2026-07-01 18:46:19', '2026-07-01 18:46:19'),
(108, 26, 'manager', 24, 51, NULL, 1, '2026-07-01 18:46:51', '2026-07-01 18:46:51'),
(111, 26, 'staff', 2, 52, 'Coordination', 1, '2026-07-01 19:21:11', '2026-07-01 19:21:11');

-- --------------------------------------------------------

--
-- Table structure for table `org_messages`
--

DROP TABLE IF EXISTS `org_messages`;
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

DROP TABLE IF EXISTS `org_message_threads`;
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

--
-- Dumping data for table `org_message_threads`
--

INSERT INTO `org_message_threads` (`id`, `org_id`, `member_a_id`, `member_b_id`, `subject`, `uid`, `created_by_member_id`, `created_at`, `last_message_at`) VALUES
(5, 26, 108, 111, 'General', 'THR-26-108-111-20260702002304-1762eaa2', 111, '2026-07-01 19:23:04', '2026-07-01 19:23:04'),
(6, 26, 107, 108, 'General', 'THR-26-107-108-20260706031136-844b791e', 108, '2026-07-05 22:11:36', '2026-07-05 22:11:36');

-- --------------------------------------------------------

--
-- Table structure for table `org_orders`
--

DROP TABLE IF EXISTS `org_orders`;
CREATE TABLE `org_orders` (
  `id` bigint(20) NOT NULL,
  `org_id` bigint(20) NOT NULL,
  `order_code` varchar(24) NOT NULL,
  `buyer_user_id` bigint(20) DEFAULT NULL,
  `buyer_name` varchar(120) DEFAULT NULL,
  `buyer_phone` varchar(40) DEFAULT NULL,
  `buyer_email` varchar(120) DEFAULT NULL,
  `product_id` bigint(20) NOT NULL,
  `product_title` varchar(200) NOT NULL,
  `unit_price_cents` int(11) NOT NULL DEFAULT '0',
  `currency` varchar(3) NOT NULL DEFAULT 'USD',
  `quantity` int(11) NOT NULL DEFAULT '1',
  `total_cents` int(11) NOT NULL DEFAULT '0',
  `status` enum('pending','confirmed','paid','shipped','delivered','cancelled') NOT NULL DEFAULT 'pending',
  `order_type` enum('purchase','wholesale','service','subscription','license','affiliate') NOT NULL DEFAULT 'purchase',
  `subscription_id` bigint(20) DEFAULT NULL,
  `buyer_notes` text,
  `seller_notes` text,
  `delivery_address` text,
  `stripe_checkout_session_id` varchar(255) DEFAULT NULL,
  `stripe_payment_intent_id` varchar(255) DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `assigned_member_id` bigint(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `org_order_receipts`
--

DROP TABLE IF EXISTS `org_order_receipts`;
CREATE TABLE `org_order_receipts` (
  `id` bigint(20) NOT NULL,
  `org_id` bigint(20) NOT NULL,
  `order_id` bigint(20) NOT NULL,
  `receipt_code` varchar(28) NOT NULL,
  `buyer_user_id` bigint(20) DEFAULT NULL,
  `buyer_name` varchar(120) DEFAULT NULL,
  `buyer_email` varchar(120) DEFAULT NULL,
  `buyer_phone` varchar(40) DEFAULT NULL,
  `seller_name` varchar(120) DEFAULT NULL,
  `product_title` varchar(200) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT '1',
  `unit_price_cents` int(11) NOT NULL DEFAULT '0',
  `tax_cents` int(11) NOT NULL DEFAULT '0',
  `total_cents` int(11) NOT NULL DEFAULT '0',
  `currency` varchar(3) NOT NULL DEFAULT 'USD',
  `payment_method` varchar(40) DEFAULT NULL,
  `payment_reference` varchar(120) DEFAULT NULL,
  `status` enum('issued','void') NOT NULL DEFAULT 'issued',
  `notes` text,
  `file_path` varchar(500) DEFAULT NULL,
  `issued_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `voided_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `org_product_images`
--

DROP TABLE IF EXISTS `org_product_images`;
CREATE TABLE `org_product_images` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `org_id` bigint(20) NOT NULL,
  `product_id` bigint(20) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `org_product_price_tiers`
--

DROP TABLE IF EXISTS `org_product_price_tiers`;
CREATE TABLE `org_product_price_tiers` (
  `id` bigint(20) NOT NULL,
  `org_id` bigint(20) NOT NULL,
  `product_id` bigint(20) NOT NULL,
  `min_qty` int(11) NOT NULL DEFAULT '1',
  `unit_price_cents` int(11) NOT NULL DEFAULT '0',
  `label` varchar(80) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `org_products`
--

DROP TABLE IF EXISTS `org_products`;
CREATE TABLE `org_products` (
  `id` bigint(20) NOT NULL,
  `org_id` bigint(20) NOT NULL,
  `sku` varchar(64) DEFAULT NULL,
  `title` varchar(200) NOT NULL,
  `description` text,
  `offer_type` enum('physical','digital','service','subscription','license') NOT NULL DEFAULT 'physical',
  `pricing_model` enum('one_time','recurring','quote','free','wholesale_tier') NOT NULL DEFAULT 'one_time',
  `price_cents` int(11) NOT NULL DEFAULT '0',
  `cost_cents` int(11) DEFAULT NULL,
  `wholesale_price_cents` int(11) DEFAULT NULL,
  `min_order_qty` int(11) NOT NULL DEFAULT '1',
  `billing_interval` enum('none','weekly','monthly','yearly') NOT NULL DEFAULT 'none',
  `currency` varchar(3) NOT NULL DEFAULT 'USD',
  `stock_qty` int(11) DEFAULT NULL,
  `category` varchar(80) DEFAULT NULL,
  `status` enum('draft','active','sold_out','archived') NOT NULL DEFAULT 'draft',
  `cover_image_path` varchar(500) DEFAULT NULL,
  `download_file_path` varchar(500) DEFAULT NULL,
  `license_terms` text,
  `affiliate_url` varchar(500) DEFAULT NULL,
  `public_post_id` bigint(20) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT '0',
  `created_by_member_id` bigint(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `is_deleted` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `org_subscriptions`
--

DROP TABLE IF EXISTS `org_subscriptions`;
CREATE TABLE `org_subscriptions` (
  `id` bigint(20) NOT NULL,
  `org_id` bigint(20) NOT NULL,
  `product_id` bigint(20) NOT NULL,
  `buyer_user_id` bigint(20) NOT NULL,
  `status` enum('active','paused','cancelled','expired') NOT NULL DEFAULT 'active',
  `price_cents` int(11) NOT NULL DEFAULT '0',
  `currency` varchar(3) NOT NULL DEFAULT 'USD',
  `billing_interval` enum('weekly','monthly','yearly') NOT NULL DEFAULT 'monthly',
  `started_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `next_billing_at` datetime DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `org_posts`
--

DROP TABLE IF EXISTS `org_posts`;
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
  `deleted_at` datetime DEFAULT NULL,
  `public_post_id` bigint(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `org_post_acknowledgements`
--

DROP TABLE IF EXISTS `org_post_acknowledgements`;
CREATE TABLE `org_post_acknowledgements` (
  `post_id` bigint(20) NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `acknowledged_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `org_post_attachments`
--

DROP TABLE IF EXISTS `org_post_attachments`;
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

DROP TABLE IF EXISTS `org_post_comments`;
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

DROP TABLE IF EXISTS `org_post_flags`;
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

DROP TABLE IF EXISTS `org_post_likes`;
CREATE TABLE `org_post_likes` (
  `post_id` bigint(20) NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `liked_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `org_post_views`
--

DROP TABLE IF EXISTS `org_post_views`;
CREATE TABLE `org_post_views` (
  `post_id` bigint(20) NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `viewed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `org_roles`
--

DROP TABLE IF EXISTS `org_roles`;
CREATE TABLE `org_roles` (
  `id` bigint(20) NOT NULL,
  `org_id` bigint(20) NOT NULL,
  `name` varchar(50) NOT NULL,
  `is_system` tinyint(4) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `org_roles`
--

INSERT INTO `org_roles` (`id`, `org_id`, `name`, `is_system`, `created_at`) VALUES
(45, 23, 'Manager', 1, '2026-07-01 17:53:00'),
(46, 23, 'Staff', 1, '2026-07-01 17:53:00'),
(47, 24, 'Manager', 1, '2026-07-01 18:00:38'),
(48, 24, 'Staff', 1, '2026-07-01 18:00:38'),
(49, 25, 'Manager', 1, '2026-07-01 18:13:49'),
(50, 25, 'Staff', 1, '2026-07-01 18:13:49'),
(51, 26, 'Manager', 1, '2026-07-01 18:46:19'),
(52, 26, 'Staff', 1, '2026-07-01 18:46:19');

-- --------------------------------------------------------

--
-- Table structure for table `org_role_permissions`
--

DROP TABLE IF EXISTS `org_role_permissions`;
CREATE TABLE `org_role_permissions` (
  `role_id` bigint(20) NOT NULL,
  `perm_key` varchar(80) NOT NULL,
  `allowed` tinyint(4) NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `org_rooms`
--

DROP TABLE IF EXISTS `org_rooms`;
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

DROP TABLE IF EXISTS `org_room_members`;
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

DROP TABLE IF EXISTS `org_room_messages`;
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

DROP TABLE IF EXISTS `org_settings`;
CREATE TABLE `org_settings` (
  `org_id` bigint(20) NOT NULL,
  `logo_type` varchar(20) NOT NULL DEFAULT 'text',
  `logo_text` varchar(40) DEFAULT NULL,
  `logo_image_path` varchar(255) DEFAULT NULL,
  `theme_json` json DEFAULT NULL,
  `a11y_json` json DEFAULT NULL,
  `shop_json` json DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `logo_icon` varchar(50) DEFAULT NULL,
  `logo_color` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `org_settings`
--

INSERT INTO `org_settings` (`org_id`, `logo_type`, `logo_text`, `logo_image_path`, `theme_json`, `a11y_json`, `shop_json`, `updated_at`, `logo_icon`, `logo_color`) VALUES
(23, 'text', 'Super Admin Admin Publisher', NULL, NULL, NULL, NULL, '2026-07-01 17:53:00', NULL, NULL),
(24, 'text', 'Akin Teniola Admin Publisher', NULL, NULL, NULL, NULL, '2026-07-01 18:00:38', NULL, NULL),
(25, 'text', 'Brenda Renee Admin Publisher', NULL, NULL, NULL, NULL, '2026-07-01 18:13:49', NULL, NULL),
(26, 'text', 'CNN', NULL, NULL, NULL, NULL, '2026-07-05 22:11:13', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `password_reset_tokens`
--

DROP TABLE IF EXISTS `password_reset_tokens`;
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

DROP TABLE IF EXISTS `posts`;
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

DROP TABLE IF EXISTS `post_audience`;
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

DROP TABLE IF EXISTS `post_media`;
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

DROP TABLE IF EXISTS `public_comment_likes`;
CREATE TABLE `public_comment_likes` (
  `comment_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `public_follows`
--

DROP TABLE IF EXISTS `public_follows`;
CREATE TABLE `public_follows` (
  `id` int(11) NOT NULL,
  `follower_id` int(11) NOT NULL,
  `following_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `public_follows`
--

INSERT INTO `public_follows` (`id`, `follower_id`, `following_id`, `created_at`) VALUES
(29, 75, 73, '2026-07-05 17:59:48');

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

DROP TABLE IF EXISTS `public_posts`;
CREATE TABLE `public_posts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(120) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `body` mediumtext,
  `visibility` enum('public','friends') NOT NULL DEFAULT 'public',
  `device_label` varchar(120) NOT NULL DEFAULT '',
  `device_viewport` varchar(32) NOT NULL DEFAULT '',
  `music_title` varchar(120) NOT NULL DEFAULT '',
  `music_artist` varchar(120) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `is_deleted` tinyint(1) NOT NULL DEFAULT '0',
  `views_count` int(11) NOT NULL DEFAULT '0',
  `category_id` int(10) UNSIGNED DEFAULT NULL,
  `activity_at` datetime GENERATED ALWAYS AS (coalesce(`updated_at`,`created_at`)) STORED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `public_posts`
--

INSERT INTO `public_posts` (`id`, `user_id`, `title`, `description`, `body`, `visibility`, `device_label`, `device_viewport`, `music_title`, `music_artist`, `created_at`, `updated_at`, `is_deleted`, `views_count`, `category_id`) VALUES
(421, 66, NULL, NULL, NULL, 'friends', 'Tablet / Laptop', '605x1180', '', '', '2026-07-02 20:34:53', '2026-07-02 20:41:35', 0, 0, 3337),
(422, 74, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:54]] live_watch.php?live=54', 'friends', 'Tablet / Laptop', '367x626', '', '', '2026-07-02 22:55:16', '2026-07-02 22:56:38', 1, 0, NULL),
(423, 66, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:55]] live_watch.php?live=55', 'friends', 'Tablet / Laptop', '367x642', '', '', '2026-07-02 23:04:12', '2026-07-02 23:07:02', 1, 0, NULL),
(424, 66, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:56]] live_watch.php?live=56', 'friends', 'Tablet / Laptop', '367x642', '', '', '2026-07-02 23:16:01', '2026-07-02 23:30:38', 1, 0, NULL),
(425, 66, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:57]] live_watch.php?live=57', 'friends', 'Tablet / Laptop', '367x642', '', '', '2026-07-02 23:45:30', '2026-07-02 23:49:55', 1, 0, NULL),
(426, 66, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:58]] live_watch.php?live=58', 'friends', 'Tablet / Laptop', '367x642', '', '', '2026-07-02 23:59:53', '2026-07-03 00:07:52', 1, 0, NULL),
(427, 66, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:59]] live_watch.php?live=59', 'friends', 'Tablet / Laptop', '367x642', '', '', '2026-07-03 00:08:25', '2026-07-03 00:12:12', 1, 0, NULL),
(428, 66, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:60]] live_watch.php?live=60', 'friends', 'Tablet / Laptop', '367x642', '', '', '2026-07-03 00:16:00', '2026-07-03 00:23:59', 1, 0, NULL),
(429, 66, NULL, NULL, NULL, 'friends', 'Tablet / Laptop', '568x1180', '', '', '2026-07-03 06:58:41', '2026-07-03 06:58:41', 0, 0, 3337),
(430, 66, NULL, NULL, 'Hi Guys,\r\n\r\nLorem ipsum dolor sit amet, consectetur adipisicing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.\r\n\r\nSed ut perspiciatis unde omnis iste natus error sit voluptatem accusantium doloremque laudantium, totam rem aperiam, eaque ipsa quae ab illo inventore veritatis et quasi architecto beatae vitae dicta sunt explicabo. Nemo enim ipsam voluptatem quia voluptas\r\n\r\nBest regards,\r\nKevin Douglas', 'friends', 'Tablet / Laptop', '568x1180', '', '', '2026-07-03 07:02:05', '2026-07-03 19:28:56', 0, 1, 3339),
(431, 66, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:61]] live_watch.php?live=61', 'friends', 'Tablet / Laptop', '367x642', '', '', '2026-07-03 07:04:37', '2026-07-03 07:11:52', 1, 0, NULL),
(432, 66, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:62]] live_watch.php?live=62', 'friends', 'Tablet / Laptop', '367x642', '', '', '2026-07-03 07:51:42', '2026-07-03 07:53:31', 1, 0, NULL),
(433, 66, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:63]] live_watch.php?live=63', 'friends', 'Tablet / Laptop', '367x642', '', '', '2026-07-03 08:13:26', '2026-07-03 08:31:03', 1, 0, NULL),
(434, 66, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:64]] live_watch.php?live=64', 'friends', 'Tablet / Laptop', '367x642', '', '', '2026-07-03 08:38:44', '2026-07-03 08:40:20', 1, 0, NULL),
(435, 74, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:65]] live_watch.php?live=65', 'friends', 'Tablet / Laptop', '782x1470', '', '', '2026-07-03 08:40:51', '2026-07-03 08:53:39', 1, 0, NULL),
(436, 66, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:66]] live_watch.php?live=66', 'friends', 'Tablet / Laptop', '367x642', '', '', '2026-07-03 08:59:23', '2026-07-03 09:15:10', 1, 0, NULL),
(437, 66, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:67]] live_watch.php?live=67', 'friends', 'Tablet / Laptop', '367x642', '', '', '2026-07-03 09:18:04', '2026-07-03 09:22:28', 1, 0, NULL),
(438, 66, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:68]] live_watch.php?live=68', 'friends', 'Tablet / Laptop', '367x642', '', '', '2026-07-03 09:27:18', '2026-07-03 09:39:05', 1, 0, NULL),
(439, 66, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:69]] live_watch.php?live=69', 'friends', 'Tablet / Laptop', '367x642', '', '', '2026-07-03 09:39:37', '2026-07-03 10:37:28', 1, 0, NULL),
(440, 66, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:70]] live_watch.php?live=70', 'friends', 'Tablet / Laptop', '367x642', '', '', '2026-07-03 10:37:54', '2026-07-03 10:41:05', 1, 0, NULL),
(441, 66, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:71]] live_watch.php?live=71', 'friends', 'Tablet / Laptop', '367x642', '', '', '2026-07-03 10:49:47', '2026-07-03 10:54:35', 1, 0, NULL),
(442, 66, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:72]] live_watch.php?live=72', 'friends', 'Tablet / Laptop', '367x642', '', '', '2026-07-03 10:55:07', '2026-07-03 11:01:09', 1, 0, NULL),
(443, 66, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:73]] live_watch.php?live=73', 'friends', 'Tablet / Laptop', '367x642', '', '', '2026-07-03 11:01:25', '2026-07-03 11:02:41', 1, 0, NULL),
(444, 66, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:74]] live_watch.php?live=74', 'friends', 'Tablet / Laptop', '367x642', '', '', '2026-07-03 11:03:26', '2026-07-03 11:09:11', 1, 0, NULL),
(445, 66, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:75]] live_watch.php?live=75', 'friends', 'Tablet / Laptop', '367x642', '', '', '2026-07-03 11:09:57', '2026-07-03 11:17:31', 1, 0, NULL),
(446, 66, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:76]] live_watch.php?live=76', 'friends', 'Tablet / Laptop', '739x1468', '', '', '2026-07-03 11:17:57', '2026-07-03 11:21:57', 1, 0, NULL),
(447, 66, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:77]] live_watch.php?live=77', 'friends', 'Tablet / Laptop', '367x642', '', '', '2026-07-03 11:51:08', '2026-07-03 11:52:30', 1, 0, NULL),
(448, 66, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:78]] live_watch.php?live=78', 'friends', 'Tablet / Laptop', '367x642', '', '', '2026-07-03 11:56:09', '2026-07-03 12:36:22', 1, 0, NULL),
(449, 66, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:79]] live_watch.php?live=79', 'friends', 'Tablet / Laptop', '367x663', '', '', '2026-07-03 12:40:45', '2026-07-03 12:54:55', 1, 0, NULL),
(450, 66, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:80]] live_watch.php?live=80', 'public', 'Tablet / Laptop', '367x663', '', '', '2026-07-03 15:53:45', '2026-07-03 16:00:59', 1, 0, NULL),
(451, 66, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:81]] live_watch.php?live=81', 'public', 'Tablet / Laptop', '367x663', '', '', '2026-07-03 16:03:25', '2026-07-03 16:42:24', 1, 0, NULL),
(452, 66, 'Please stop by saying HI', 'Hi Guys,\r\n\r\nLorem ipsum dolor sit amet, consectetur adipisicing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute ', '[[live_post:83]] live_watch.php?live=83', 'friends', 'Tablet / Laptop', '367x663', '', '', '2026-07-03 16:51:34', '2026-07-03 16:57:16', 1, 0, NULL),
(453, 66, 'Please stop by saying HI', 'Hi Guys,\r\n\r\nLorem ipsum dolor sit amet, consectetur adipisicing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute ', '[[live_post:84]] live_watch.php?live=84', 'friends', 'Tablet / Laptop', '367x663', '', '', '2026-07-03 17:01:29', '2026-07-03 17:41:10', 1, 0, NULL),
(454, 66, 'God is great!', 'Hi Guys,\r\n\r\nLorem ipsum dolor sit amet, consectetur adipisicing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute ', '[[live_post:85]] live_watch.php?live=85', 'friends', 'Tablet / Laptop', '367x663', '', '', '2026-07-03 17:41:59', '2026-07-03 18:26:45', 1, 0, NULL),
(455, 66, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:86]] live_watch.php?live=86', 'friends', 'Tablet / Laptop', '367x643', '', '', '2026-07-03 18:31:49', '2026-07-03 18:58:34', 1, 0, NULL),
(456, 66, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:87]] live_watch.php?live=87', 'friends', 'Tablet / Laptop', '367x643', '', '', '2026-07-03 19:04:51', '2026-07-03 19:05:44', 1, 0, NULL),
(457, 66, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:88]] live_watch.php?live=88', 'friends', 'Tablet / Laptop', '367x643', '', '', '2026-07-03 19:21:51', '2026-07-03 19:24:03', 1, 0, NULL),
(458, 66, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:89]] live_watch.php?live=89', 'friends', 'Tablet / Laptop', '367x663', '', '', '2026-07-03 19:25:52', '2026-07-03 19:29:12', 1, 0, NULL),
(459, 66, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:90]] live_watch.php?live=90', 'friends', 'Tablet / Laptop', '367x663', '', '', '2026-07-03 19:42:34', '2026-07-03 19:53:03', 1, 0, NULL),
(460, 66, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:91]] live_watch.php?live=91', 'friends', 'Tablet / Laptop', '367x663', '', '', '2026-07-03 19:54:01', '2026-07-03 20:29:27', 1, 0, NULL),
(461, 74, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:92]] live_watch.php?live=92', 'friends', 'Tablet / Laptop', '368x706', '', '', '2026-07-03 20:28:25', '2026-07-03 20:28:58', 1, 0, NULL),
(462, 74, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:93]] live_watch.php?live=93', 'friends', 'Tablet / Laptop', '368x706', '', '', '2026-07-03 20:30:04', '2026-07-03 20:30:41', 1, 0, NULL),
(463, 66, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:94]] live_watch.php?live=94', 'friends', 'Tablet / Laptop', '367x663', '', '', '2026-07-03 20:45:17', '2026-07-03 21:00:39', 1, 0, NULL),
(464, 74, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:95]] live_watch.php?live=95', 'friends', 'Tablet / Laptop', '368x706', '', '', '2026-07-03 21:19:25', '2026-07-03 21:23:07', 1, 0, NULL),
(465, 66, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:96]] live_watch.php?live=96', 'friends', 'Tablet / Laptop', '367x663', '', '', '2026-07-03 22:41:35', '2026-07-03 22:45:03', 1, 0, NULL),
(466, 66, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:97]] live_watch.php?live=97', 'friends', 'Tablet / Laptop', '367x663', '', '', '2026-07-03 22:54:55', '2026-07-03 23:19:37', 1, 0, NULL),
(467, 66, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:98]] live_watch.php?live=98', 'friends', 'Tablet / Laptop', '367x663', '', '', '2026-07-03 23:31:45', '2026-07-03 23:53:43', 1, 0, NULL),
(468, 66, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:99]] live_watch.php?live=99', 'friends', 'Tablet / Laptop', '367x663', '', '', '2026-07-03 23:54:56', '2026-07-04 00:10:05', 1, 0, NULL),
(469, 66, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:100]] live_watch.php?live=100', 'friends', 'Tablet / Laptop', '367x663', '', '', '2026-07-04 00:11:46', '2026-07-04 00:14:22', 1, 0, NULL),
(470, 66, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:101]] live_watch.php?live=101', 'friends', 'Tablet / Laptop', '367x663', '', '', '2026-07-04 07:04:11', '2026-07-04 07:08:24', 1, 0, NULL),
(471, 74, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:102]] live_watch.php?live=102', 'friends', 'Tablet / Laptop', '368x706', '', '', '2026-07-04 07:05:41', '2026-07-04 07:08:09', 1, 0, NULL),
(472, 66, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:103]] live_watch.php?live=103', 'friends', 'Tablet / Laptop', '367x663', '', '', '2026-07-04 07:35:48', '2026-07-04 07:36:34', 1, 0, NULL),
(473, 66, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:104]] live_watch.php?live=104', 'friends', 'Tablet / Laptop', '367x663', '', '', '2026-07-04 07:37:10', '2026-07-04 07:43:47', 1, 0, NULL),
(474, 66, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:105]] live_watch.php?live=105', 'friends', 'Tablet / Laptop', '367x663', '', '', '2026-07-04 07:44:27', '2026-07-04 07:52:06', 1, 0, NULL),
(475, 66, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:106]] live_watch.php?live=106', 'friends', 'Tablet / Laptop', '367x663', '', '', '2026-07-04 07:45:00', '2026-07-04 07:50:18', 1, 0, NULL),
(476, 66, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:107]] live_watch.php?live=107', 'friends', 'Tablet / Laptop', '367x663', '', '', '2026-07-04 08:07:24', '2026-07-04 08:36:08', 1, 0, NULL),
(477, 66, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:108]] live_watch.php?live=108', 'friends', 'Tablet / Laptop', '367x663', '', '', '2026-07-04 08:07:51', '2026-07-04 08:17:42', 1, 0, NULL),
(478, 66, NULL, NULL, NULL, 'friends', 'Tablet / Laptop', '568x1180', '', '', '2026-07-04 12:01:42', '2026-07-04 12:54:14', 0, 0, 3337),
(479, 66, NULL, NULL, NULL, 'public', 'Tablet / Laptop', '568x1180', '', '', '2026-07-04 12:56:12', '2026-07-04 12:56:12', 0, 0, 3337),
(480, 66, NULL, NULL, NULL, 'friends', 'Tablet / Laptop', '568x1180', '', '', '2026-07-04 13:41:43', '2026-07-04 13:41:43', 0, 0, 3337),
(481, 66, NULL, NULL, NULL, 'public', 'Tablet / Laptop', '568x1180', '', '', '2026-07-04 13:49:37', '2026-07-04 13:49:37', 0, 0, 3337),
(482, 66, NULL, NULL, NULL, 'friends', 'Tablet / Laptop', '568x1180', '', '', '2026-07-04 13:51:00', '2026-07-04 13:51:18', 0, 0, 3337),
(483, 66, NULL, NULL, 'Hi Guys,\r\n\r\nLorem ipsum dolor sit amet, consectetur adipisicing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.\r\n\r\nSed ut perspiciatis unde omnis iste natus error sit voluptatem accusantium doloremque laudantium, totam rem aperiam, eaque ipsa quae ab illo inventore veritatis et quasi architecto beatae vitae dicta sunt explicabo. Nemo enim ipsam voluptatem quia voluptas\r\n\r\nBest regards,\r\nKevin Douglas', 'friends', 'Tablet / Laptop', '568x1180', '', '', '2026-07-04 14:10:01', '2026-07-05 13:20:46', 0, 0, 3339),
(484, 74, NULL, NULL, NULL, 'public', 'Tablet / Laptop', '605x1180', '', '', '2026-07-05 00:13:41', '2026-07-05 00:13:41', 0, 0, 3527),
(485, 66, NULL, '[[layout:story]]', NULL, 'friends', 'Tablet / Laptop', '568x1180', '', '', '2026-07-05 13:24:11', '2026-07-05 13:24:11', 0, 0, 3337),
(486, 73, NULL, '[[layout:story]]', NULL, 'public', 'Tablet / Laptop', '568x1180', '', '', '2026-07-05 13:36:46', '2026-07-05 13:36:46', 0, 0, 3437),
(487, 73, 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', '[[live_post:109]] live_watch.php?live=109', 'friends', 'Tablet / Laptop', '367x663', '', '', '2026-07-05 13:37:58', '2026-07-05 13:37:58', 0, 0, NULL),
(488, 73, NULL, NULL, NULL, 'public', 'Tablet / Laptop', '568x1180', '', '', '2026-07-05 13:49:01', '2026-07-05 13:49:01', 0, 0, 3437),
(489, 75, NULL, NULL, NULL, 'friends', 'Tablet / Laptop', '605x1180', 'Me & My Jesus', 'Noël Mio', '2026-07-05 13:50:01', '2026-07-05 13:53:01', 0, 0, 3762),
(490, 74, NULL, NULL, NULL, 'friends', 'Tablet / Laptop', '568x1180', '', '', '2026-07-05 14:03:02', '2026-07-05 14:03:02', 0, 0, 3527),
(491, 75, NULL, NULL, NULL, 'friends', 'Tablet / Laptop', '568x1180', '', '', '2026-07-05 16:31:12', '2026-07-05 16:31:12', 0, 0, 3762),
(492, 75, NULL, NULL, NULL, 'public', 'Tablet / Laptop', '605x1180', '', '', '2026-07-05 18:02:38', '2026-07-05 18:03:18', 0, 0, 3763),
(493, 75, NULL, NULL, 'Hi Guys,\r\n\r\nLorem ipsum dolor sit amet, consectetur adipisicing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.\r\n\r\nSed ut perspiciatis unde omnis iste natus error sit voluptatem accusantium doloremque laudantium, totam rem aperiam, eaque ipsa quae ab illo inventore veritatis et quasi architecto beatae vitae dicta sunt explicabo. Nemo enim ipsam voluptatem quia voluptas\r\n\r\nBest regards,\r\nKevin Douglas', 'public', 'Tablet / Laptop', '605x1180', '', '', '2026-07-05 18:03:55', '2026-07-05 18:07:47', 0, 0, 3764);

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

DROP TABLE IF EXISTS `public_post_attachments`;
CREATE TABLE `public_post_attachments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `post_id` bigint(20) UNSIGNED NOT NULL,
  `type` enum('image','video','pdf','file') NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `thumb_path` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `public_post_attachments`
--

INSERT INTO `public_post_attachments` (`id`, `post_id`, `type`, `file_path`, `thumb_path`, `created_at`) VALUES
(361, 421, 'video', 'uploads/posts/202607/p421_eb1e5a8fa272.mp4', NULL, '2026-07-02 20:34:53'),
(362, 429, 'video', 'uploads/posts/202607/p429_79798e3d32c5.mp4', NULL, '2026-07-03 06:58:41'),
(363, 478, 'video', 'uploads/posts/202607/p478_18a54589288e.mp4', NULL, '2026-07-04 12:01:42'),
(364, 479, 'video', 'uploads/posts/202607/p479_1e627182af5c.mp4', NULL, '2026-07-04 12:56:12'),
(365, 480, 'video', 'uploads/posts/202607/p480_bc43b8cb30d0.mp4', NULL, '2026-07-04 13:41:43'),
(366, 481, 'video', 'uploads/posts/202607/p481_2fa8c8b9d504.mp4', NULL, '2026-07-04 13:49:37'),
(367, 482, 'video', 'uploads/posts/202607/p482_066389bfb081.mp4', NULL, '2026-07-04 13:51:00'),
(368, 484, 'video', 'uploads/posts/202607/p484_9939daa744d0.mp4', NULL, '2026-07-05 00:13:41'),
(369, 485, 'video', 'uploads/posts/202607/p485_b9d4ad770fe3.mp4', NULL, '2026-07-05 13:24:11'),
(370, 486, 'video', 'uploads/posts/202607/p486_e012bb48e665.mp4', NULL, '2026-07-05 13:36:46'),
(371, 488, 'video', 'uploads/posts/202607/p488_b9a4772dcf78.mp4', NULL, '2026-07-05 13:49:01'),
(372, 489, 'video', 'uploads/posts/202607/p489_f87e57110946.mp4', NULL, '2026-07-05 13:50:01'),
(373, 490, 'video', 'uploads/posts/202607/p490_a6e676bd40e1.mp4', NULL, '2026-07-05 14:03:02'),
(374, 491, 'video', 'uploads/posts/202607/p491_829b6cf4bbf5.mp4', NULL, '2026-07-05 16:31:12'),
(375, 492, 'image', 'uploads/posts/202607/p492_21574e23b8c7.jpg', NULL, '2026-07-05 18:02:38');

-- --------------------------------------------------------

--
-- Table structure for table `public_post_comments`
--

DROP TABLE IF EXISTS `public_post_comments`;
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

DROP TABLE IF EXISTS `public_post_reactions`;
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

DROP TABLE IF EXISTS `public_post_reads`;
CREATE TABLE `public_post_reads` (
  `post_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `last_seen_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `public_post_reads`
--

INSERT INTO `public_post_reads` (`post_id`, `user_id`, `last_seen_at`) VALUES
(421, 66, '2026-07-02 20:41:37'),
(429, 66, '2026-07-03 07:01:57'),
(430, 66, '2026-07-03 07:02:05'),
(478, 66, '2026-07-04 12:54:19'),
(480, 66, '2026-07-04 13:41:48'),
(482, 66, '2026-07-04 13:51:24'),
(483, 66, '2026-07-05 13:20:48'),
(488, 73, '2026-07-05 13:49:02'),
(489, 75, '2026-07-05 13:50:03'),
(490, 74, '2026-07-05 14:03:04'),
(491, 75, '2026-07-05 16:31:13'),
(492, 75, '2026-07-05 18:02:41'),
(493, 75, '2026-07-05 22:59:58');

-- --------------------------------------------------------

--
-- Table structure for table `public_post_saves`
--

DROP TABLE IF EXISTS `public_post_saves`;
CREATE TABLE `public_post_saves` (
  `post_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `saved_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `public_post_shares`
--

DROP TABLE IF EXISTS `public_post_shares`;
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

DROP TABLE IF EXISTS `public_post_tags`;
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

DROP TABLE IF EXISTS `public_post_views`;
CREATE TABLE `public_post_views` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `post_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `viewed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `public_post_views`
--

INSERT INTO `public_post_views` (`id`, `post_id`, `user_id`, `viewed_at`) VALUES
(6, 430, 74, '2026-07-03 19:28:56');

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

DROP TABLE IF EXISTS `public_post_view_daily`;
CREATE TABLE `public_post_view_daily` (
  `post_id` bigint(20) UNSIGNED NOT NULL,
  `view_date` date NOT NULL,
  `views` int(11) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `public_post_view_daily`
--

INSERT INTO `public_post_view_daily` (`post_id`, `view_date`, `views`) VALUES
(430, '2026-07-03', 2);

-- --------------------------------------------------------

--
-- Table structure for table `public_profile_access`
--

DROP TABLE IF EXISTS `public_profile_access`;
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

DROP TABLE IF EXISTS `public_saved_posts`;
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

DROP TABLE IF EXISTS `publisher_name_authority`;
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

--
-- Dumping data for table `publisher_name_authority`
--

INSERT INTO `publisher_name_authority` (`id`, `publisher_name_option_id`, `publisher_name`, `publisher_category`, `entity_type`, `legal_entity_name`, `registration_id`, `registration_country`, `authorized_contact_name`, `authorized_contact_email`, `request_note`, `authority_confirmed`, `status`, `reviewed_by_admin_id`, `reviewed_at`, `review_note`, `created_at`) VALUES
(9, 12, 'CNN', 'news', 'business', 'cnn', '', 'US', 'cnn', 'cnn@gmail.com', 'Just test!', 1, 'approved', 9, '2026-07-01 18:46:19', '', '2026-07-01 18:46:03');

-- --------------------------------------------------------

--
-- Table structure for table `publisher_name_options`
--

DROP TABLE IF EXISTS `publisher_name_options`;
CREATE TABLE `publisher_name_options` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(120) NOT NULL,
  `category` varchar(40) NOT NULL DEFAULT 'news',
  `registered_user_id` int(10) UNSIGNED DEFAULT NULL,
  `org_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `publisher_name_options`
--

INSERT INTO `publisher_name_options` (`id`, `name`, `category`, `registered_user_id`, `org_id`, `created_at`) VALUES
(12, 'CNN', 'news', 73, 26, '2026-07-01 18:46:19');

-- --------------------------------------------------------

--
-- Table structure for table `platform_payments`
--

DROP TABLE IF EXISTS `platform_payments`;
CREATE TABLE `platform_payments` (
  `id` bigint(20) NOT NULL,
  `org_id` bigint(20) NOT NULL,
  `plan_id` bigint(20) NOT NULL,
  `amount_cents` int(11) NOT NULL DEFAULT '0',
  `currency` varchar(3) NOT NULL DEFAULT 'USD',
  `months_paid` int(11) NOT NULL DEFAULT '1',
  `payment_method` varchar(40) DEFAULT NULL,
  `payment_reference` varchar(120) DEFAULT NULL,
  `status` enum('confirmed','pending','refunded') NOT NULL DEFAULT 'confirmed',
  `notes` text,
  `recorded_by_admin_id` int(11) DEFAULT NULL,
  `paid_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `platform_plans`
--

DROP TABLE IF EXISTS `platform_plans`;
CREATE TABLE `platform_plans` (
  `id` bigint(20) NOT NULL,
  `code` varchar(40) NOT NULL,
  `name` varchar(80) NOT NULL,
  `price_cents` int(11) NOT NULL DEFAULT '0',
  `currency` varchar(3) NOT NULL DEFAULT 'USD',
  `billing_interval` enum('none','monthly','yearly') NOT NULL DEFAULT 'monthly',
  `trial_days` int(11) NOT NULL DEFAULT '0',
  `max_products` int(11) NOT NULL DEFAULT '10',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int(11) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `platform_plans`
--

INSERT INTO `platform_plans` (`id`, `code`, `name`, `price_cents`, `currency`, `billing_interval`, `trial_days`, `max_products`, `is_active`, `sort_order`, `created_at`) VALUES
(1, 'shop_trial', 'Shop Trial', 0, 'USD', 'none', 30, 10, 1, 0, '2026-07-01 00:00:00'),
(2, 'shop_basic', 'Shop Basic', 499, 'USD', 'monthly', 0, 50, 1, 10, '2026-07-01 00:00:00'),
(3, 'shop_pro', 'Shop Pro', 999, 'USD', 'monthly', 0, 500, 1, 20, '2026-07-01 00:00:00');

-- --------------------------------------------------------

--
-- Table structure for table `role`
--

DROP TABLE IF EXISTS `role`;
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
(12, 'Teacher', 2, 1);

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
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

DROP TABLE IF EXISTS `role_chat_matrix`;
CREATE TABLE `role_chat_matrix` (
  `from_role` int(11) NOT NULL,
  `to_role` int(11) NOT NULL,
  `allowed` tinyint(4) NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `role_permissions`
--

DROP TABLE IF EXISTS `role_permissions`;
CREATE TABLE `role_permissions` (
  `role_id` int(11) NOT NULL,
  `perm` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `staff_accounts`
--

DROP TABLE IF EXISTS `staff_accounts`;
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

--
-- Dumping data for table `staff_accounts`
--

INSERT INTO `staff_accounts` (`id`, `org_id`, `friend_code`, `username`, `email`, `password`, `fullname`, `status`, `force_password_change`, `created_at`, `last_seen`) VALUES
(2, 26, 'STF-8A38-4747', 'ese_e', 'ese_e@gmail.com', '$2y$10$RiuDKQnSbY099JvLwc1Bi.rfYKdIJEjtUTYKXVqupe3NrDjPQ6LUW', 'Ese Eko', 1, 1, '2026-07-01 19:21:11', '2026-07-01 19:23:38');

-- --------------------------------------------------------

--
-- Table structure for table `timeline_access_requests`
--

DROP TABLE IF EXISTS `timeline_access_requests`;
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

DROP TABLE IF EXISTS `timeline_profiles`;
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

DROP TABLE IF EXISTS `users`;
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

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `username`, `friend_code`, `email`, `password`, `gender`, `mobile`, `age`, `designation`, `role`, `account_kind`, `publisher_category`, `publisher_tagline`, `image`, `image_blob`, `image_type`, `status`, `created_at`, `last_seen`, `birthday`, `policy_agreed`, `policy_agreed_at`, `age_confirmed`, `age_confirmed_at`) VALUES
(66, 'Isaac Cuma', 'isaaccuma3093', 'USR-PJ9X-DHM0', 'isaaccuma3093@gmail.com', '$2y$10$h.cs4sanBc5vPtAGHU0/EOTx7ByEWxRAHwHNQtv/ra6K1gQ0.wKMG', 'Male', '7834567890', '2002-08-08', '', 4, 'personal', '', '', 'default.jpg', NULL, NULL, 1, '2026-07-01 19:46:01', '2026-07-05 18:24:55', '2002-08-08', 1, '2026-07-01 19:46:01', 1, '2026-07-01 19:46:01'),
(67, 'Super Admin', 'admin_personal', 'USR-6JEO-DHXW', 'admin+personal.9@example.com', '$2y$10$lxKZ5.IuLW1KRkXhqMRxEODu8sH4Vt9kCN1syexRwmtnso3OZwB4m', 'N/A', 'N/A', '', 'Admin linked', 4, 'personal', '', '', 'default.jpg', NULL, NULL, 1, '2026-07-01 22:53:00', '2026-07-02 02:32:03', NULL, 0, NULL, 0, NULL),
(68, 'Super Admin Admin Publisher', 'admin_pub', 'PUB-Y1UJ-ZTK1', 'admin+pub.9@example.com', '$2y$10$lxKZ5.IuLW1KRkXhqMRxEODu8sH4Vt9kCN1syexRwmtnso3OZwB4m', 'N/A', 'N/A', '', 'Admin linked', 4, 'publisher', 'news', 'Admin publisher workspace', 'default.jpg', NULL, NULL, 1, '2026-07-01 22:53:00', '2026-07-02 02:32:55', NULL, 0, NULL, 0, NULL),
(69, 'Akin Teniola', 'akin_personal', 'USR-6E1Q-226V', 'akin+personal.10@gmail.com', '$2y$10$TbwJmt1dHFn1bPjmsbm15.IT7XRRB/hUadgNuh5KDX0fDblH.tgoi', 'N/A', 'N/A', '', 'Admin linked', 4, 'personal', '', '', 'default.jpg', NULL, NULL, 1, '2026-07-01 23:00:38', '2026-07-01 23:01:34', NULL, 0, NULL, 0, NULL),
(70, 'Akin Teniola Admin Publisher', 'akin_pub', 'PUB-4BTW-9T04', 'akin+pub.10@gmail.com', '$2y$10$TbwJmt1dHFn1bPjmsbm15.IT7XRRB/hUadgNuh5KDX0fDblH.tgoi', 'N/A', 'N/A', '', 'Admin linked', 4, 'publisher', 'news', 'Admin publisher workspace', 'default.jpg', NULL, NULL, 1, '2026-07-01 23:00:38', '2026-07-01 23:14:00', NULL, 0, NULL, 0, NULL),
(71, 'Brenda Renee', 'brenda_personal', 'USR-U4VJ-LYRK', 'brenda+personal.11@gmail.com', '$2y$10$Er78pelLRajUEW6.JjFHSOYAJ4YAOd3XEiFccmS7IQxk.KsF/Ozzq', 'N/A', 'N/A', '', 'Admin linked', 4, 'personal', '', '', 'default.jpg', NULL, NULL, 1, '2026-07-01 23:13:49', '2026-07-01 23:41:17', NULL, 0, NULL, 0, NULL),
(72, 'Brenda Renee Admin Publisher', 'brenda_pub', 'PUB-FEHV-XLSG', 'brenda+pub.11@gmail.com', '$2y$10$Er78pelLRajUEW6.JjFHSOYAJ4YAOd3XEiFccmS7IQxk.KsF/Ozzq', 'N/A', 'N/A', '', 'Admin linked', 4, 'publisher', 'news', 'Admin publisher workspace', 'default.jpg', NULL, NULL, 1, '2026-07-01 23:13:49', '2026-07-02 02:27:39', NULL, 0, NULL, 0, NULL),
(73, 'CNN', 'cnn', 'PUB-USSP-BYSX', 'cnn@gmail.com', '$2y$10$bxU5b35u/MtZ4oPRZ2WciODRRNkWBS6XOMPezGPkFPKSo/lzW./GW', 'N/A', 'N/A', '', 'Official CNN on Talentra', 4, 'publisher', 'news', '', 'default.jpg', NULL, NULL, 1, '2026-07-01 23:46:51', '2026-07-06 03:11:00', NULL, 1, '2026-07-01 23:46:51', 0, NULL),
(74, 'John K', 'john_k', 'USR-YD3Q-1EJR', 'john_k@gmail.com', '$2y$10$VMzlR8kkn9dVBhZAr4oH8OBkio/4vqYfO0knwPY2u1XHXbZlMG/wO', 'Male', '7893457890', '2003-05-01', '', 4, 'personal', '', '', 'default.jpg', NULL, NULL, 1, '2026-07-03 01:40:02', '2026-07-05 19:03:34', '2003-05-01', 1, '2026-07-03 01:40:02', 1, '2026-07-03 01:40:02'),
(75, 'Maka Ori', 'maka', 'USR-31L1-CWFR', 'maka@gmail.com', '$2y$10$KzWH.Y1xlUmr3ZURK.BCVOUv4aLhrc/h9JmehMWpNLz3pUgSjvAkG', 'Female', '8903425467', '1996-02-09', '', 4, 'personal', '', '', 'default.jpg', NULL, NULL, 1, '2026-07-05 16:39:20', '2026-07-06 04:00:36', '1996-02-09', 1, '2026-07-05 16:39:20', 1, '2026-07-05 16:39:20');

-- --------------------------------------------------------

--
-- Table structure for table `user_backgrounds`
--

DROP TABLE IF EXISTS `user_backgrounds`;
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

DROP TABLE IF EXISTS `user_chat_hidden_messages`;
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

DROP TABLE IF EXISTS `user_contacts`;
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

--
-- Dumping data for table `user_contacts`
--

INSERT INTO `user_contacts` (`id`, `owner_user_id`, `friend_user_id`, `display_name`, `contact_user_id`, `contact_email`, `contact_name`, `created_at`) VALUES
(57, 66, '74', 'John K', NULL, NULL, NULL, '2026-07-03 01:41:16'),
(58, 74, '66', 'Isaac Cuma', NULL, NULL, NULL, '2026-07-03 01:41:16');

-- --------------------------------------------------------

--
-- Table structure for table `user_contact_name_history`
--

DROP TABLE IF EXISTS `user_contact_name_history`;
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

DROP TABLE IF EXISTS `user_message_reactions`;
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

DROP TABLE IF EXISTS `user_post_categories`;
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

--
-- Dumping data for table `user_post_categories`
--

INSERT INTO `user_post_categories` (`id`, `user_id`, `name`, `slug`, `category_type`, `is_system`, `created_at`, `updated_at`) VALUES
(3337, 66, 'Video Category', 'video-category', 'video', 1, '2026-07-01 17:12:35', '2026-07-01 17:12:35'),
(3338, 66, 'Photo Category', 'photo-category', 'photo', 1, '2026-07-01 17:12:35', '2026-07-01 17:12:35'),
(3339, 66, 'Topic Category', 'topic-category', 'topic', 1, '2026-07-01 17:12:35', '2026-07-01 17:12:35'),
(3340, 66, 'Mixed Category', 'mixed-category', 'mixed', 1, '2026-07-01 17:12:35', '2026-07-01 17:12:35'),
(3341, 66, 'File Category', 'file-category', 'file', 1, '2026-07-01 17:12:35', '2026-07-01 17:12:35'),
(3437, 73, 'Video Category', 'video-category', 'video', 1, '2026-07-01 21:31:31', '2026-07-01 21:31:31'),
(3438, 73, 'Photo Category', 'photo-category', 'photo', 1, '2026-07-01 21:31:31', '2026-07-01 21:31:31'),
(3439, 73, 'Topic Category', 'topic-category', 'topic', 1, '2026-07-01 21:31:31', '2026-07-01 21:31:31'),
(3440, 73, 'Mixed Category', 'mixed-category', 'mixed', 1, '2026-07-01 21:31:31', '2026-07-01 21:31:31'),
(3441, 73, 'File Category', 'file-category', 'file', 1, '2026-07-01 21:31:31', '2026-07-01 21:31:31'),
(3442, 67, 'Video Category', 'video-category', 'video', 1, '2026-07-01 21:31:59', '2026-07-01 21:31:59'),
(3443, 67, 'Photo Category', 'photo-category', 'photo', 1, '2026-07-01 21:31:59', '2026-07-01 21:31:59'),
(3444, 67, 'Topic Category', 'topic-category', 'topic', 1, '2026-07-01 21:31:59', '2026-07-01 21:31:59'),
(3445, 67, 'Mixed Category', 'mixed-category', 'mixed', 1, '2026-07-01 21:31:59', '2026-07-01 21:31:59'),
(3446, 67, 'File Category', 'file-category', 'file', 1, '2026-07-01 21:31:59', '2026-07-01 21:31:59'),
(3447, 68, 'Video Category', 'video-category', 'video', 1, '2026-07-01 21:32:19', '2026-07-01 21:32:19'),
(3448, 68, 'Photo Category', 'photo-category', 'photo', 1, '2026-07-01 21:32:19', '2026-07-01 21:32:19'),
(3449, 68, 'Topic Category', 'topic-category', 'topic', 1, '2026-07-01 21:32:19', '2026-07-01 21:32:19'),
(3450, 68, 'Mixed Category', 'mixed-category', 'mixed', 1, '2026-07-01 21:32:19', '2026-07-01 21:32:19'),
(3451, 68, 'File Category', 'file-category', 'file', 1, '2026-07-01 21:32:19', '2026-07-01 21:32:19'),
(3527, 74, 'Video Category', 'video-category', 'video', 1, '2026-07-03 07:20:39', '2026-07-03 07:20:39'),
(3528, 74, 'Photo Category', 'photo-category', 'photo', 1, '2026-07-03 07:20:39', '2026-07-03 07:20:39'),
(3529, 74, 'Topic Category', 'topic-category', 'topic', 1, '2026-07-03 07:20:39', '2026-07-03 07:20:39'),
(3530, 74, 'Mixed Category', 'mixed-category', 'mixed', 1, '2026-07-03 07:20:39', '2026-07-03 07:20:39'),
(3531, 74, 'File Category', 'file-category', 'file', 1, '2026-07-03 07:20:39', '2026-07-03 07:20:39'),
(3762, 75, 'Video Category', 'video-category', 'video', 1, '2026-07-05 13:49:42', '2026-07-05 13:49:42'),
(3763, 75, 'Photo Category', 'photo-category', 'photo', 1, '2026-07-05 13:49:42', '2026-07-05 13:49:42'),
(3764, 75, 'Topic Category', 'topic-category', 'topic', 1, '2026-07-05 13:49:42', '2026-07-05 13:49:42'),
(3765, 75, 'Mixed Category', 'mixed-category', 'mixed', 1, '2026-07-05 13:49:42', '2026-07-05 13:49:42'),
(3766, 75, 'File Category', 'file-category', 'file', 1, '2026-07-05 13:49:42', '2026-07-05 13:49:42');

-- --------------------------------------------------------

--
-- Table structure for table `user_profile_settings`
--

DROP TABLE IF EXISTS `user_profile_settings`;
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

--
-- Dumping data for table `user_profile_settings`
--

INSERT INTO `user_profile_settings` (`user_id`, `theme_color`, `cover_image_path`, `avatar_image_path`, `profile_visibility`, `about_visibility`, `gallery_visibility`, `comment_permission`, `friend_request_permission`, `message_permission`, `timeline_visit_approval`, `auto_show_timeline`, `resurface_old_memories`, `show_timeline_reactions`, `show_timeline_comments`, `archive_memory_enabled`, `pin_memory_enabled`, `email_notifications`, `friend_request_notifications`, `comment_notifications`, `reaction_notifications`, `share_notifications`, `blocked_users_enabled`, `hidden_users_enabled`, `mute_users_enabled`, `report_history_enabled`, `appearance_mode`, `gallery_grid_size`, `autoplay_videos`, `sound_enabled`, `app_language`, `date_format`, `allow_download_data`, `allow_deactivate_account`, `allow_delete_account`, `allow_logout_all_devices`, `created_at`, `updated_at`, `publisher_workspace_json`) VALUES
(66, NULL, NULL, NULL, 'public', 'friends', 'friends', 'friends', 'public', 'friends', 1, 1, 1, 1, 1, 0, 0, 1, 1, 1, 1, 1, 1, 1, 1, 1, 'light', 'medium', 1, 1, 'English', 'F j, Y', 1, 1, 1, 1, '2026-07-01 22:18:41', '2026-07-05 05:45:03', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `user_sessions`
--

DROP TABLE IF EXISTS `user_sessions`;
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

--
-- Dumping data for table `user_sessions`
--

INSERT INTO `user_sessions` (`id`, `user_id`, `php_session_id`, `session_token`, `ip_address`, `user_agent`, `created_at`, `last_seen_at`, `revoked_at`) VALUES
(9735, 68, 'm9v4q7ur93bcldrk3h9bbk121q', 'db47bdc1bc48345de57c36d60065ce7676fa50159db62c6ceed028a18a543495', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-07-01 14:46:17', '2026-07-01 17:59:09', NULL),
(9779, 70, 'nigbj05j1qk68ckb7775rbsdhd', 'bf62ed7f90da46ae2e35dbb2de8475724b32b8dea6d053a7d0601cc5e873cf98', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-07-01 17:59:11', '2026-07-01 18:02:05', NULL),
(10010, 70, 'bl4dmdj7o7n0kgt2chic84orlo', 'a1846119ee6db2862c9ab1225ad903891b61a2dee4c14001a5c3f931d672fbac', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-07-01 18:02:08', '2026-07-01 18:14:00', NULL),
(10012, 71, '0bef18sgnqp0jor4a36gaqebm3', '32bab234a9d08906e2c782e6b1a48897a793a104ea48d196f1d46479a019ed0b', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-07-01 18:14:11', '2026-07-01 18:14:43', '2026-07-01 18:14:43'),
(10013, 72, 'a2dt1i1if7k2llgm88nqolsqpa', '6cf83ff5c135296580f7617e90919a81e21b107e0cc14ad2fc5aec9277fc6d56', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-07-01 18:14:54', '2026-07-01 19:22:25', '2026-07-01 19:22:25'),
(10014, 71, 'ego1abd356fsfrgr9eu2d7jcmh', '6f595328da61dcd0189cae6a8e91416e610e3b68817e6e0ce8b045caedfa3903', '127.0.0.1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:152.0) Gecko/20100101 Firefox/152.0', '2026-07-01 18:40:56', '2026-07-01 18:41:21', '2026-07-01 18:41:21'),
(10015, 72, 'ce1s5lv7rc14o1gg6tlcbab02g', 'e2b20a44f364ea6b44d1dd65b8ba1b3b201b2b5921e761fb808835eae0446bd4', '127.0.0.1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:152.0) Gecko/20100101 Firefox/152.0', '2026-07-01 18:41:42', '2026-07-01 18:44:24', NULL),
(10017, 73, 'e96mb9rfcfeorlo67htjf26v42', '4b7e536537d46b1610dce75b2850134ba2d17729c7757905e866116cb3d304ec', '127.0.0.1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:152.0) Gecko/20100101 Firefox/152.0', '2026-07-01 18:46:51', '2026-07-01 18:54:09', NULL),
(10018, 73, 'upj6kphc57e0t9ooqfa9rucld1', '76c8fbead6827e5ee9aaad1348fe1d43ff74014ed26063b3e06dc0491835ac41', '127.0.0.1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:152.0) Gecko/20100101 Firefox/152.0', '2026-07-01 19:37:20', '2026-07-01 19:38:16', '2026-07-01 19:38:16'),
(10019, 73, 'e998ar3tp43iortes6abijrrdl', '7e87be3d12bf9b19dfcb0c8035a968d2b998407dffc70f7a651132aaf2d5eee5', '127.0.0.1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:152.0) Gecko/20100101 Firefox/152.0', '2026-07-01 19:38:16', '2026-07-01 19:38:18', '2026-07-01 19:38:18'),
(10020, 73, 'h43ljvvv852tiaj4okg71e01af', '2efc69134e13e2ce79caa4d7239bd3c422862c83c5ae984985e21c788fd33e98', '127.0.0.1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:152.0) Gecko/20100101 Firefox/152.0', '2026-07-01 19:38:39', '2026-07-01 21:27:00', '2026-07-01 21:27:00'),
(10021, 72, 'b17aq5sitm00beoedvj8cqc1of', 'd0bd0d0bb73f29d95fbe12ec57bbd1c47df79acad0f2e2f3df7056f9456d409d', '127.0.0.1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:152.0) Gecko/20100101 Firefox/152.0', '2026-07-01 21:27:27', '2026-07-01 21:27:39', '2026-07-01 21:27:39'),
(10022, 73, 'ou692i7vdm4k5hb3qcjeqe5ena', '2b27bd670dda8220ce375c73d8b2c89f5e2fadfe2b83b5517376213760faabe7', '127.0.0.1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:152.0) Gecko/20100101 Firefox/152.0', '2026-07-01 21:27:59', '2026-07-01 21:31:18', '2026-07-01 21:31:18'),
(10023, 73, '6alj34f0kvi7vvbvu20f39m2m3', '38f6eea457c66e7239a6dfbb5109fb6b64ce9981a009876ecb6bf77b8842bc10', '127.0.0.1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:152.0) Gecko/20100101 Firefox/152.0', '2026-07-01 21:31:29', '2026-07-01 21:31:37', '2026-07-01 21:31:37'),
(10024, 67, '1op9lnq4huk359m8qnsd8c8ctm', '1f7daedfe54639c2d9d41d4b455d3ea1586d3e8445f3c4e2a2fff9c2a8b18b89', '127.0.0.1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:152.0) Gecko/20100101 Firefox/152.0', '2026-07-01 21:31:57', '2026-07-01 21:32:03', '2026-07-01 21:32:03'),
(10025, 68, 'eafpnre3qkug40tisr1vkfhe1a', '06259b8a5bc5c36df3ce8e7c0821d4a952990cbe2d347374b05b83a452f41d99', '127.0.0.1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:152.0) Gecko/20100101 Firefox/152.0', '2026-07-01 21:32:14', '2026-07-01 21:33:03', '2026-07-01 21:33:03'),
(10026, 73, 'ljks3k9474u3aa2l6o1isnegqf', 'da1b88f791f862c75a637bdf4230e9de1d3e40eaa99d3e6c4e267aa2e48bd54a', '127.0.0.1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:152.0) Gecko/20100101 Firefox/152.0', '2026-07-01 21:33:15', '2026-07-01 22:21:28', '2026-07-01 22:21:28'),
(10027, 73, 'djtvtdk19n53u7cb9ubb57r1rn', 'cd8ca0c71606da9a44ea68f1072b04ccf17a275971437dd8830eb42b9f9ff2fc', '127.0.0.1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:152.0) Gecko/20100101 Firefox/152.0', '2026-07-01 22:21:55', '2026-07-01 22:32:59', '2026-07-01 22:32:59'),
(10028, 73, '8omv1l6644l320m8f22nr60m3b', '6019ed4d8224750d6d3a30c4d2871e7a817a68a143163283ae04ce7d0ddbeb7b', '127.0.0.1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:152.0) Gecko/20100101 Firefox/152.0', '2026-07-01 22:33:13', '2026-07-01 22:52:18', '2026-07-01 22:52:18'),
(10029, 66, 'gq68k2j16ksvnj3da7614a061e', '2c9cb44bb5682caac4aaaa84d708527e05d430828ddd0f44726cb2acfad5e76c', '127.0.0.1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:152.0) Gecko/20100101 Firefox/152.0', '2026-07-01 22:52:48', '2026-07-02 22:52:47', NULL),
(10030, 66, 'd04p7ctacsgs221bua50v27qta', '428ac242897e872f1e6fb68fe75093645820e195ada4f028a7c59216c5401f79', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-07-02 11:32:38', '2026-07-02 13:54:09', '2026-07-02 13:54:09'),
(10031, 73, '7qrr7in9o3vcbfh6b5ki90i9f6', 'f13c9d3cad118e0f3afeb8271b89d45385726c8e1f7be8a8fecaededd53fe987', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-07-02 13:54:22', '2026-07-02 20:37:30', '2026-07-02 20:37:30'),
(10032, 74, 'nfr2eij7343rj27h8psutedorh', '08dedf46d3128a1a6b59f3fa5a45f27c33ed3ae3bcde579bba0bc859d44402e9', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-07-02 20:40:26', '2026-07-02 22:57:49', NULL),
(10033, 74, '0h5kd079hvt7k920dnldvgjscb', 'a2b155f2863b786195dbb97370a68e5d1cbf5909571eab18bdfad41c6028a78f', '127.0.0.1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:152.0) Gecko/20100101 Firefox/152.0', '2026-07-02 22:55:57', '2026-07-03 22:55:56', NULL),
(10035, 66, 'u2kagpmiq9lm9gif22l6hgf3uq', 'ebf651a6e4a4aeb8c8ecee293a8e27d09151c377e36a5b3da1c1ef235b20f1ac', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-07-02 22:59:59', '2026-07-03 22:59:58', NULL),
(10036, 74, 'qubc49hou54hdqpia0go14es6i', 'b2ce820aae551dd2774686b572a0b607621d78e17e3350dfcbb79d3951a2bb81', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Cursor/3.5.38 Chrome/142.0.7444.265 Electron/39.8.1 Safari/537.36', '2026-07-03 18:39:56', '2026-07-04 18:39:55', NULL),
(10037, 74, 'ro0iqdmtutjh23p2juqd86km5q', '253036834b9b76256f8fb8213a7475ee9d61ceafc5c15ef7420c16c9666f5777', '127.0.0.1', 'curl/8.7.1', '2026-07-03 18:43:37', '2026-07-03 18:43:39', NULL),
(10038, 66, '6i096018qu0bq7862i3jdolk4a', '2ea6e6bd1506f2e611931750c1e0cf14ad69e553e9839210e6e4a9838f80e2b5', '127.0.0.1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:152.0) Gecko/20100101 Firefox/152.0', '2026-07-03 19:09:10', '2026-07-03 19:09:10', NULL),
(10039, 66, '6usht25t1ckc65vt7tpklv24uj', '30d7f153aac87edbb1cd46cd42bd5388435a5d631db0fffad0090ca60312c7c7', '127.0.0.1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:152.0) Gecko/20100101 Firefox/152.0', '2026-07-03 19:09:27', '2026-07-03 19:09:27', NULL),
(10040, 74, 'r2uu9jmc2pke68c7bhnrh1akuu', 'b751973f6c9de6ad97dd4806e8d40bc7c4e1329b19efc253d5022237064c4dba', '127.0.0.1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:152.0) Gecko/20100101 Firefox/152.0', '2026-07-03 19:23:42', '2026-07-03 19:57:17', NULL),
(10041, 66, '2hvudl47rsa0sb8c7em124712a', 'b9c147c98d6e2650a804038b8c3ae7f9a0d3d32af95e6903fe592bd5eb300286', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-07-03 19:25:23', '2026-07-03 19:57:23', NULL),
(10042, 74, 'kmu8fd12lt3mn6j2urots5q0m5', 'a54569a7c52d6f61cc0b8a32b20c23b7992757f687b093ea21d1c252f07df630', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Cursor/3.5.38 Chrome/142.0.7444.265 Electron/39.8.1 Safari/537.36', '2026-07-03 19:33:07', '2026-07-03 19:57:23', NULL),
(10043, 66, '7s69elj3db74fhqa5dj9hmtl7u', 'b554a35ef8e823bdcda3fc7a70bef5a653d031acb78b96bfd999b1f1b616b134', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-07-03 23:09:17', '2026-07-04 23:09:14', NULL),
(10044, 74, 'pn51q39l0ko43jhh002icus57e', '295c5c1ac5ce249ba1ab63abadcd54dee8d26e03adea806df88a0e65e99361c5', '127.0.0.1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:152.0) Gecko/20100101 Firefox/152.0', '2026-07-03 23:19:07', '2026-07-04 23:19:05', NULL),
(10045, 66, 'mtepuq39thda0gem4cbes45rfb', '171fb29e56cd339f96f618d5ae770cb2412c50a1d1a3318adf307bca233f032e', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-07-04 23:54:13', '2026-07-05 00:10:03', '2026-07-05 00:10:03'),
(10046, 74, '20lbf4aur3jvb6o3oipkm7lbns', '86f47a87c570f35a3508b1a9d6ecd0a81f1eb25172ffdc3413a4d61deace5462', '127.0.0.1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:152.0) Gecko/20100101 Firefox/152.0', '2026-07-04 23:55:57', '2026-07-05 11:38:13', '2026-07-05 11:38:13'),
(10047, 74, 'bfq2j504otjkh122pbohnctnhc', '5aba8890d14bb1d70c54c4933b454bbc64f70678e0d16bde21c3ee50fab2382e', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-07-05 00:10:13', '2026-07-05 00:11:36', '2026-07-05 00:11:36'),
(10048, 66, 'v59lt5ho21chf0c0mnp9arfbv4', '19aa9ac9d15a1fbb1146776b1b1785e2a07b43031035b7e4203da711bb12e4a8', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-07-05 00:11:49', '2026-07-05 13:24:56', '2026-07-05 13:24:56'),
(10049, 73, 'ak0q7plpkc8p7rn8qh9gapm8mp', '9474f7e20091835adaa7447bf8b2652a1195e917af24edb8d8a448ec3ee085d0', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-07-05 13:25:19', '2026-07-05 14:02:08', '2026-07-05 14:02:08'),
(10050, 75, 'r2op5apg7uiibidvvm6jsu36l7', 'de79f625c7f656b18565d800e05331267d91f40c63f26a7bc30b793782381582', '127.0.0.1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:152.0) Gecko/20100101 Firefox/152.0', '2026-07-05 13:38:36', '2026-07-05 13:49:20', '2026-07-05 13:49:20'),
(10051, 75, 'a1h8603mbm5bs6h6m62p6776ht', 'de89989b87f4ad4380d59c71794ddea0e6fa2c0178caa2764a126f2a54287a27', '127.0.0.1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:152.0) Gecko/20100101 Firefox/152.0', '2026-07-05 13:49:38', '2026-07-05 23:00:40', NULL),
(10052, 74, 'kt9co40cds0o1amqmhav9p4b7h', 'fc1be7631f0764c2942fc48588eac090023910feb3c76c836d950099b19b23ac', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-07-05 14:02:17', '2026-07-05 14:03:34', NULL),
(10054, 75, 'o6lmrljs2i99saog00htqck2au', 'b036454f8e0d1c412c59936bb304c7301453a2cc79de9b2fbe3c8609ee94e1b2', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-07-05 14:03:41', '2026-07-05 22:10:07', NULL),
(10056, 73, 'fm04tic4hg090hpe9lnok10dtg', 'd9e35ed1a1472e360c3368b3f9494a5831b11b8175b93c775c2e7fcfe5c64ab3', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-07-05 22:10:19', '2026-07-05 22:11:13', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `user_video_calls`
--

DROP TABLE IF EXISTS `user_video_calls`;
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

DROP TABLE IF EXISTS `user_video_call_signals`;
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

DROP TABLE IF EXISTS `user_video_lives`;
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
  `host_door` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `studio_source` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
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
-- Dumping data for table `user_video_lives`
--

INSERT INTO `user_video_lives` (`id`, `user_id`, `friend_code`, `title`, `description`, `stream_key`, `playback_url`, `status`, `visibility`, `device_label`, `device_viewport`, `host_door`, `studio_source`, `scheduled_for`, `started_at`, `ended_at`, `viewer_count`, `share_count`, `peak_viewers`, `thumbnail_path`, `created_at`, `updated_at`) VALUES
(37, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_ae3ec0b65ac6cd33', NULL, 'ended', 'private', 'Tablet / Laptop', '367x739', '', '', NULL, '2026-07-02 12:23:31', '2026-07-02 12:56:14', 0, 0, 0, NULL, '2026-07-02 12:23:31', '2026-07-02 12:56:14'),
(38, 73, 'PUB-USSP-BYSX', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_e3512fd3c321227f', NULL, 'ended', 'private', 'Tablet / Laptop', '367x739', '', '', NULL, '2026-07-02 14:08:53', '2026-07-02 14:09:15', 0, 0, 0, NULL, '2026-07-02 14:08:53', '2026-07-02 14:09:15'),
(39, 73, 'PUB-USSP-BYSX', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_e982d4b282794218', NULL, 'ended', 'private', 'Tablet / Laptop', '367x739', '', '', NULL, '2026-07-02 14:11:25', '2026-07-02 14:14:55', 0, 0, 0, NULL, '2026-07-02 14:11:25', '2026-07-02 14:14:55'),
(40, 73, 'PUB-USSP-BYSX', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_b875c0e97f0730bf', NULL, 'ended', 'private', 'Tablet / Laptop', '367x739', '', '', NULL, '2026-07-02 14:16:32', '2026-07-02 14:22:15', 0, 0, 0, NULL, '2026-07-02 14:16:32', '2026-07-02 14:22:15'),
(41, 73, 'PUB-USSP-BYSX', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_c8760666d5d5d7d8', NULL, 'ended', 'private', 'Tablet / Laptop', '367x739', '', '', NULL, '2026-07-02 14:22:31', '2026-07-02 14:30:19', 0, 0, 0, NULL, '2026-07-02 14:22:31', '2026-07-02 14:30:19'),
(42, 73, 'PUB-USSP-BYSX', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_a92297c805f100c8', NULL, 'ended', 'private', 'Tablet / Laptop', '367x739', '', '', NULL, '2026-07-02 14:30:28', '2026-07-02 14:31:23', 0, 0, 0, NULL, '2026-07-02 14:30:28', '2026-07-02 14:31:23'),
(43, 73, 'PUB-USSP-BYSX', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_0920676a50bf000c', NULL, 'ended', 'private', 'Tablet / Laptop', '367x739', '', '', NULL, '2026-07-02 14:32:07', '2026-07-02 14:39:27', 0, 0, 0, NULL, '2026-07-02 14:32:07', '2026-07-02 14:39:27'),
(44, 73, 'PUB-USSP-BYSX', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_5bf3383399fdd9e3', NULL, 'ended', 'private', 'Tablet / Laptop', '367x739', '', '', NULL, '2026-07-02 14:40:41', '2026-07-02 14:41:19', 0, 0, 0, NULL, '2026-07-02 14:40:41', '2026-07-02 14:41:19'),
(45, 73, 'PUB-USSP-BYSX', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_e484863cc3d1ca6c', NULL, 'ended', 'private', 'Tablet / Laptop', '367x739', '', '', NULL, '2026-07-02 14:51:48', '2026-07-02 14:58:15', 0, 0, 0, NULL, '2026-07-02 14:51:48', '2026-07-02 14:58:15'),
(46, 73, 'PUB-USSP-BYSX', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_eeff73a6bb4417a5', NULL, 'ended', 'private', 'Tablet / Laptop', '367x739', '', '', NULL, '2026-07-02 15:01:19', '2026-07-02 15:15:38', 0, 0, 0, NULL, '2026-07-02 15:01:19', '2026-07-02 15:15:38'),
(47, 73, 'PUB-USSP-BYSX', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_dc2cdd5835197260', NULL, 'ended', 'private', 'Tablet / Laptop', '367x739', '', '', NULL, '2026-07-02 15:16:12', '2026-07-02 15:18:03', 0, 0, 0, NULL, '2026-07-02 15:16:12', '2026-07-02 15:18:03'),
(48, 73, 'PUB-USSP-BYSX', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_62e821e761b343bf', NULL, 'ended', 'private', 'Tablet / Laptop', '367x739', '', '', NULL, '2026-07-02 15:22:50', '2026-07-02 15:23:20', 0, 0, 0, NULL, '2026-07-02 15:22:50', '2026-07-02 15:23:20'),
(49, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_f42813e871a1fb7d', NULL, 'ended', 'private', 'Tablet / Laptop', '368x682', '', '', NULL, '2026-07-02 20:43:06', '2026-07-02 20:50:55', 0, 0, 0, NULL, '2026-07-02 20:43:06', '2026-07-02 20:50:55'),
(50, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_90504b8f40f68d17', NULL, 'ended', 'private', 'Tablet / Laptop', '368x682', '', '', NULL, '2026-07-02 20:52:25', '2026-07-02 20:55:05', 0, 0, 0, NULL, '2026-07-02 20:52:25', '2026-07-02 20:55:05'),
(51, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_869cb5f8e11e064c', NULL, 'ended', 'private', 'Tablet / Laptop', '368x682', '', '', NULL, '2026-07-02 21:01:20', '2026-07-02 21:03:47', 0, 0, 0, NULL, '2026-07-02 21:01:20', '2026-07-02 21:03:47'),
(52, 74, 'USR-YD3Q-1EJR', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_e61965b44f116310', NULL, 'ended', 'private', 'Tablet / Laptop', '367x626', '', '', NULL, '2026-07-02 21:22:52', '2026-07-02 21:27:05', 0, 0, 0, NULL, '2026-07-02 21:22:52', '2026-07-02 21:27:05'),
(53, 74, 'USR-YD3Q-1EJR', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_f1c4da4f3e2d24c1', NULL, 'ended', 'private', 'Tablet / Laptop', '367x626', '', '', NULL, '2026-07-02 21:44:34', '2026-07-02 22:50:30', 0, 0, 0, NULL, '2026-07-02 21:44:34', '2026-07-02 22:50:30'),
(54, 74, 'USR-YD3Q-1EJR', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_641c48f62207c6d2', NULL, 'ended', 'friends', 'Tablet / Laptop', '367x626', '', '', NULL, '2026-07-02 22:55:16', '2026-07-02 22:56:38', 0, 0, 0, NULL, '2026-07-02 22:55:16', '2026-07-02 22:56:38'),
(55, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_61fc572106628483', NULL, 'ended', 'friends', 'Tablet / Laptop', '367x642', '', '', NULL, '2026-07-02 23:04:12', '2026-07-02 23:07:02', 0, 0, 1, NULL, '2026-07-02 23:04:12', '2026-07-02 23:08:14'),
(56, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_22365c3b38d4f4b3', NULL, 'ended', 'friends', 'Tablet / Laptop', '367x642', '', '', NULL, '2026-07-02 23:16:01', '2026-07-02 23:30:38', 0, 0, 1, NULL, '2026-07-02 23:16:01', '2026-07-02 23:45:01'),
(57, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_e8c60a0f0480ced2', NULL, 'ended', 'friends', 'Tablet / Laptop', '367x642', '', '', NULL, '2026-07-02 23:45:30', '2026-07-02 23:49:55', 0, 0, 1, NULL, '2026-07-02 23:45:30', '2026-07-02 23:59:16'),
(58, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_a2e704f5050c0159', NULL, 'ended', 'friends', 'Tablet / Laptop', '367x642', '', '', NULL, '2026-07-02 23:59:53', '2026-07-03 00:07:52', 0, 0, 1, NULL, '2026-07-02 23:59:53', '2026-07-03 00:07:52'),
(59, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_a1d9100f2bcc91e6', NULL, 'ended', 'friends', 'Tablet / Laptop', '367x642', '', '', NULL, '2026-07-03 00:08:25', '2026-07-03 00:12:12', 0, 0, 1, NULL, '2026-07-03 00:08:25', '2026-07-03 00:16:11'),
(60, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_324feb7bf6693b51', NULL, 'ended', 'friends', 'Tablet / Laptop', '367x642', '', '', NULL, '2026-07-03 00:16:00', '2026-07-03 00:23:59', 0, 0, 1, NULL, '2026-07-03 00:16:00', '2026-07-03 00:24:03'),
(61, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_14f563f6273e9afe', NULL, 'ended', 'friends', 'Tablet / Laptop', '367x642', '', '', NULL, '2026-07-03 07:04:37', '2026-07-03 07:11:52', 0, 0, 1, NULL, '2026-07-03 07:04:37', '2026-07-03 07:17:10'),
(62, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_f3a264c9fd42ae52', NULL, 'ended', 'friends', 'Tablet / Laptop', '367x642', '', '', NULL, '2026-07-03 07:51:42', '2026-07-03 07:53:31', 1, 0, 1, NULL, '2026-07-03 07:51:42', '2026-07-03 07:53:35'),
(63, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_63dd8443a4a71389', NULL, 'ended', 'friends', 'Tablet / Laptop', '367x642', '', '', NULL, '2026-07-03 08:13:26', '2026-07-03 08:31:03', 0, 0, 1, NULL, '2026-07-03 08:13:26', '2026-07-03 08:31:03'),
(64, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_304442b389588a79', NULL, 'ended', 'friends', 'Tablet / Laptop', '367x642', '', '', NULL, '2026-07-03 08:38:44', '2026-07-03 08:40:20', 0, 0, 1, NULL, '2026-07-03 08:38:44', '2026-07-03 08:40:23'),
(65, 74, 'USR-YD3Q-1EJR', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_c0f9cba5f9bb0eb5', NULL, 'ended', 'friends', 'Tablet / Laptop', '782x1470', '', '', NULL, '2026-07-03 08:40:51', '2026-07-03 08:53:39', 0, 0, 0, NULL, '2026-07-03 08:40:51', '2026-07-03 08:53:39'),
(66, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_a024f89c08dcc741', NULL, 'ended', 'friends', 'Tablet / Laptop', '367x642', '', '', NULL, '2026-07-03 08:59:23', '2026-07-03 09:15:10', 0, 0, 1, NULL, '2026-07-03 08:59:23', '2026-07-03 09:15:17'),
(67, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_fb27e7e44e29b30f', NULL, 'ended', 'friends', 'Tablet / Laptop', '367x642', '', '', NULL, '2026-07-03 09:18:04', '2026-07-03 09:22:28', 0, 0, 1, NULL, '2026-07-03 09:18:04', '2026-07-03 09:22:33'),
(68, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_cee586542c1753b6', NULL, 'ended', 'friends', 'Tablet / Laptop', '367x642', '', '', NULL, '2026-07-03 09:27:18', '2026-07-03 09:39:05', 0, 0, 1, NULL, '2026-07-03 09:27:18', '2026-07-03 09:39:17'),
(69, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_27efe7cc2abf53f6', NULL, 'ended', 'friends', 'Tablet / Laptop', '367x642', '', '', NULL, '2026-07-03 09:39:37', '2026-07-03 10:37:28', 0, 0, 1, NULL, '2026-07-03 09:39:37', '2026-07-03 10:37:28'),
(70, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_c0c488e77dca99db', NULL, 'ended', 'friends', 'Tablet / Laptop', '367x642', '', '', NULL, '2026-07-03 10:37:54', '2026-07-03 10:41:05', 0, 0, 1, NULL, '2026-07-03 10:37:54', '2026-07-03 10:41:14'),
(71, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_9efb13a2a5d3a682', NULL, 'ended', 'friends', 'Tablet / Laptop', '367x642', '', '', NULL, '2026-07-03 10:49:47', '2026-07-03 10:54:35', 0, 0, 1, NULL, '2026-07-03 10:49:47', '2026-07-03 10:54:39'),
(72, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_b78e1c0646c31af5', NULL, 'ended', 'friends', 'Tablet / Laptop', '367x642', '', '', NULL, '2026-07-03 10:55:07', '2026-07-03 11:01:09', 0, 0, 1, NULL, '2026-07-03 10:55:07', '2026-07-03 11:01:09'),
(73, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_c61cc08fb2f71b94', NULL, 'ended', 'friends', 'Tablet / Laptop', '367x642', '', '', NULL, '2026-07-03 11:01:25', '2026-07-03 11:02:41', 1, 0, 1, NULL, '2026-07-03 11:01:25', '2026-07-03 11:02:46'),
(74, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_bafcf30741863065', NULL, 'ended', 'friends', 'Tablet / Laptop', '367x642', '', '', NULL, '2026-07-03 11:03:26', '2026-07-03 11:09:11', 0, 0, 1, NULL, '2026-07-03 11:03:26', '2026-07-03 11:09:18'),
(75, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_d542bd41e18f4426', NULL, 'ended', 'friends', 'Tablet / Laptop', '367x642', '', '', NULL, '2026-07-03 11:09:57', '2026-07-03 11:17:31', 0, 0, 1, NULL, '2026-07-03 11:09:57', '2026-07-03 11:45:24'),
(76, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_89a3f04671bac9da', NULL, 'ended', 'friends', 'Tablet / Laptop', '739x1468', '', '', NULL, '2026-07-03 11:17:57', '2026-07-03 11:21:57', 0, 0, 0, NULL, '2026-07-03 11:17:57', '2026-07-03 11:21:57'),
(77, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_863ff0a3870b015b', NULL, 'ended', 'friends', 'Tablet / Laptop', '367x642', '', '', NULL, '2026-07-03 11:51:08', '2026-07-03 11:52:30', 0, 0, 0, NULL, '2026-07-03 11:51:08', '2026-07-03 11:52:30'),
(78, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_ffcfa93b755c53d1', NULL, 'ended', 'friends', 'Tablet / Laptop', '367x642', '', '', NULL, '2026-07-03 11:56:09', '2026-07-03 12:36:22', 0, 0, 1, NULL, '2026-07-03 11:56:09', '2026-07-03 12:36:28'),
(79, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_b63a4dac330762ec', NULL, 'ended', 'friends', 'Tablet / Laptop', '367x663', '', '', NULL, '2026-07-03 12:40:45', '2026-07-03 12:54:55', 0, 0, 1, NULL, '2026-07-03 12:40:45', '2026-07-03 12:54:59'),
(80, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_8d0fea52338af74a', NULL, 'ended', 'public', 'Tablet / Laptop', '367x663', '', '', NULL, '2026-07-03 15:53:45', '2026-07-03 16:00:59', 0, 0, 1, NULL, '2026-07-03 15:53:45', '2026-07-03 16:01:08'),
(81, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_9732e42c5d8c4b47', NULL, 'ended', 'public', 'Tablet / Laptop', '367x663', '', '', NULL, '2026-07-03 16:03:25', '2026-07-03 16:42:24', 0, 0, 1, NULL, '2026-07-03 16:03:25', '2026-07-03 16:51:45'),
(82, 66, 'USR-PJ9X-DHM0', 'God is Love.', 'Hi Guys,\r\n\r\nLorem ipsum dolor sit amet, consectetur adipisicing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.', 'studio_f5c86cb6f221a32c', NULL, 'ended', 'friends', 'Tablet / Laptop', '367x663', '', '', NULL, '2026-07-03 16:43:33', '2026-07-03 16:50:45', 0, 0, 0, NULL, '2026-07-03 16:43:33', '2026-07-03 16:50:45'),
(83, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Hi Guys,\r\n\r\nLorem ipsum dolor sit amet, consectetur adipisicing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.', 'studio_03ad2a210d1f07fa', NULL, 'ended', 'friends', 'Tablet / Laptop', '367x663', '', '', NULL, '2026-07-03 16:51:34', '2026-07-03 16:57:16', 0, 0, 1, NULL, '2026-07-03 16:51:34', '2026-07-03 16:57:24'),
(84, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Hi Guys,\r\n\r\nLorem ipsum dolor sit amet, consectetur adipisicing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.', 'studio_2f1b519506e5babc', NULL, 'ended', 'friends', 'Tablet / Laptop', '367x663', '', '', NULL, '2026-07-03 17:01:29', '2026-07-03 17:41:10', 0, 0, 1, NULL, '2026-07-03 17:01:29', '2026-07-03 17:41:23'),
(85, 66, 'USR-PJ9X-DHM0', 'God is great!', 'Hi Guys,\r\n\r\nLorem ipsum dolor sit amet, consectetur adipisicing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.\r\n\r\nSed ut perspiciatis unde omnis iste natus error sit voluptatem accusantium doloremque laudantium, totam rem aperiam, eaque ipsa quae ab illo inventore veritatis et quasi architecto beatae vitae dicta sunt explicabo. Nemo enim ipsam voluptatem quia voluptas\r\n\r\nBest regards,\r\nKevin Douglas', 'studio_250cb86c0626d344', NULL, 'ended', 'friends', 'Tablet / Laptop', '367x663', '', '', NULL, '2026-07-03 17:41:59', '2026-07-03 18:26:45', 0, 0, 1, NULL, '2026-07-03 17:41:59', '2026-07-03 18:26:51'),
(86, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_30b8ac72355ef61d', NULL, 'ended', 'friends', 'Tablet / Laptop', '367x643', '', '', NULL, '2026-07-03 18:31:49', '2026-07-03 18:58:34', 0, 0, 1, NULL, '2026-07-03 18:31:49', '2026-07-03 19:13:11'),
(87, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_fb67eca3c8aec4ab', NULL, 'ended', 'friends', 'Tablet / Laptop', '367x643', '', '', NULL, '2026-07-03 19:04:51', '2026-07-03 19:05:44', 0, 0, 1, NULL, '2026-07-03 19:04:51', '2026-07-03 19:06:02'),
(88, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_b97a11dc5c35a777', NULL, 'ended', 'friends', 'Tablet / Laptop', '367x643', '', '', NULL, '2026-07-03 19:21:51', '2026-07-03 19:24:03', 0, 0, 1, NULL, '2026-07-03 19:21:51', '2026-07-03 19:40:03'),
(89, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_87c7856b001b24c8', NULL, 'ended', 'friends', 'Tablet / Laptop', '367x663', '', '', NULL, '2026-07-03 19:25:52', '2026-07-03 19:29:12', 0, 0, 1, NULL, '2026-07-03 19:25:52', '2026-07-03 19:30:26'),
(90, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_dd4fb897e569b129', NULL, 'ended', 'friends', 'Tablet / Laptop', '367x663', '', '', NULL, '2026-07-03 19:42:34', '2026-07-03 19:53:03', 1, 0, 1, NULL, '2026-07-03 19:42:34', '2026-07-03 19:57:21'),
(91, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_5ae812503b11f5ba', NULL, 'ended', 'friends', 'Tablet / Laptop', '367x663', '', '', NULL, '2026-07-03 19:54:01', '2026-07-03 20:29:27', 0, 0, 1, NULL, '2026-07-03 19:54:01', '2026-07-03 20:29:27'),
(92, 74, 'USR-YD3Q-1EJR', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_8a0cfc6bb114e8e9', NULL, 'ended', 'friends', 'Tablet / Laptop', '368x706', '', '', NULL, '2026-07-03 20:28:25', '2026-07-03 20:28:58', 0, 0, 0, NULL, '2026-07-03 20:28:25', '2026-07-03 20:28:58'),
(93, 74, 'USR-YD3Q-1EJR', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_2355f011915d599d', NULL, 'ended', 'friends', 'Tablet / Laptop', '368x706', '', '', NULL, '2026-07-03 20:30:04', '2026-07-03 20:30:41', 0, 0, 0, NULL, '2026-07-03 20:30:04', '2026-07-03 20:30:41'),
(94, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_6b70268b63ca2948', NULL, 'ended', 'friends', 'Tablet / Laptop', '367x663', '', '', NULL, '2026-07-03 20:45:17', '2026-07-03 21:00:39', 0, 0, 1, NULL, '2026-07-03 20:45:17', '2026-07-03 21:00:47'),
(95, 74, 'USR-YD3Q-1EJR', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_0b186c53b8ccdf6e', NULL, 'ended', 'friends', 'Tablet / Laptop', '368x706', '', '', NULL, '2026-07-03 21:19:25', '2026-07-03 21:23:07', 0, 0, 1, NULL, '2026-07-03 21:19:25', '2026-07-03 21:23:22'),
(96, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_80ef94a1b749fc92', NULL, 'ended', 'friends', 'Tablet / Laptop', '367x663', '', '', NULL, '2026-07-03 22:41:35', '2026-07-03 22:45:03', 0, 0, 1, NULL, '2026-07-03 22:41:35', '2026-07-03 22:51:57'),
(97, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_1c43e1fb9cd0cc67', NULL, 'ended', 'friends', 'Tablet / Laptop', '367x663', 'right', 'software', NULL, '2026-07-03 22:54:55', '2026-07-03 23:19:37', 0, 0, 1, NULL, '2026-07-03 22:54:55', '2026-07-03 23:19:37'),
(98, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_13d6ca388700b6a0', NULL, 'ended', 'friends', 'Tablet / Laptop', '367x663', 'right', 'software', NULL, '2026-07-03 23:31:45', '2026-07-03 23:53:43', 0, 0, 1, NULL, '2026-07-03 23:31:45', '2026-07-03 23:54:05'),
(99, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_479f5721e5e21279', NULL, 'ended', 'friends', 'Tablet / Laptop', '367x663', 'right', 'software', NULL, '2026-07-03 23:54:56', '2026-07-04 00:10:05', 0, 0, 1, NULL, '2026-07-03 23:54:56', '2026-07-04 00:10:23'),
(100, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_33a8e6cfbfbf0ccf', NULL, 'ended', 'friends', 'Tablet / Laptop', '367x663', 'right', 'software', NULL, '2026-07-04 00:11:46', '2026-07-04 00:14:22', 0, 0, 0, NULL, '2026-07-04 00:11:46', '2026-07-04 00:14:22'),
(101, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_971ebc4c1f9eee34', NULL, 'ended', 'friends', 'Tablet / Laptop', '367x663', 'right', 'software', NULL, '2026-07-04 07:04:11', '2026-07-04 07:08:24', 0, 0, 1, NULL, '2026-07-04 07:04:11', '2026-07-04 07:45:44'),
(102, 74, 'USR-YD3Q-1EJR', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_8a980fedc7511d50', NULL, 'ended', 'friends', 'Tablet / Laptop', '368x706', 'left', 'webcam', NULL, '2026-07-04 07:05:41', '2026-07-04 07:08:09', 0, 0, 0, NULL, '2026-07-04 07:05:41', '2026-07-04 07:08:09'),
(103, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_94e715648e74d29a', NULL, 'ended', 'friends', 'Tablet / Laptop', '367x663', 'right', 'software', NULL, '2026-07-04 07:35:48', '2026-07-04 07:36:34', 0, 0, 0, NULL, '2026-07-04 07:35:48', '2026-07-04 07:36:34'),
(104, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_9490ba5809ea0690', NULL, 'ended', 'friends', 'Tablet / Laptop', '367x663', 'right', 'software', NULL, '2026-07-04 07:37:10', '2026-07-04 07:43:47', 0, 0, 0, NULL, '2026-07-04 07:37:10', '2026-07-04 07:43:47'),
(105, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_1cbd111d26194cf1', NULL, 'ended', 'friends', 'Tablet / Laptop', '367x663', 'left', 'webcam', NULL, '2026-07-04 07:44:27', '2026-07-04 07:52:06', 0, 0, 1, NULL, '2026-07-04 07:44:27', '2026-07-04 08:08:02'),
(106, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_3467b8573e0da4a4', NULL, 'ended', 'friends', 'Tablet / Laptop', '367x663', 'right', 'software', NULL, '2026-07-04 07:45:00', '2026-07-04 07:50:22', 0, 0, 1, NULL, '2026-07-04 07:45:00', '2026-07-04 08:08:08'),
(107, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_0bba6d35886e64c2', NULL, 'ended', 'friends', 'Tablet / Laptop', '367x663', 'right', 'software', NULL, '2026-07-04 08:07:24', '2026-07-04 08:36:08', 0, 0, 1, NULL, '2026-07-04 08:07:24', '2026-07-04 08:36:08'),
(108, 66, 'USR-PJ9X-DHM0', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_06274e0fc0b77882', NULL, 'ended', 'friends', 'Tablet / Laptop', '367x663', 'left', 'webcam', NULL, '2026-07-04 08:07:51', '2026-07-04 08:17:46', 0, 0, 1, NULL, '2026-07-04 08:07:51', '2026-07-04 08:17:58'),
(109, 73, 'PUB-USSP-BYSX', 'Please stop by saying HI', 'Share update, answer questions, and go live with your audience', 'studio_0d4d4b0a784b6cb0', NULL, 'live', 'friends', 'Tablet / Laptop', '367x663', 'right', 'software', NULL, '2026-07-05 13:37:58', NULL, 0, 0, 0, NULL, '2026-07-05 13:37:58', '2026-07-05 13:48:25');

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

DROP TABLE IF EXISTS `user_video_live_comments`;
CREATE TABLE `user_video_live_comments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `live_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `parent_id` bigint(20) UNSIGNED NOT NULL DEFAULT '0',
  `body` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_video_live_comments`
--

INSERT INTO `user_video_live_comments` (`id`, `live_id`, `user_id`, `parent_id`, `body`, `created_at`, `updated_at`) VALUES
(1, 78, 66, 0, 'hi', '2026-07-03 11:56:25', '2026-07-03 11:56:25'),
(2, 81, 66, 0, 'hi', '2026-07-03 16:18:58', '2026-07-03 16:18:58'),
(3, 81, 66, 0, '@Isaac Cuma sup?', '2026-07-03 16:19:07', '2026-07-03 16:19:07'),
(4, 84, 74, 0, 'hi', '2026-07-03 17:04:12', '2026-07-03 17:04:12'),
(5, 84, 74, 0, 'sup?', '2026-07-03 17:04:32', '2026-07-03 17:04:32'),
(6, 84, 66, 0, 'hi', '2026-07-03 17:05:04', '2026-07-03 17:05:04'),
(7, 84, 66, 0, 'chilling', '2026-07-03 17:05:15', '2026-07-03 17:05:15'),
(8, 84, 66, 0, 'i hope everyone is doing good', '2026-07-03 17:05:50', '2026-07-03 17:05:50'),
(9, 84, 74, 0, 'I am doing good', '2026-07-03 17:06:09', '2026-07-03 17:06:09'),
(10, 91, 74, 0, 'hi', '2026-07-03 19:55:01', '2026-07-03 19:55:01'),
(11, 91, 74, 0, 'hi', '2026-07-03 19:55:01', '2026-07-03 19:55:01'),
(12, 91, 74, 0, 'sup', '2026-07-03 19:55:10', '2026-07-03 19:55:10'),
(13, 95, 66, 0, 'hi', '2026-07-03 21:22:41', '2026-07-03 21:22:41');

-- --------------------------------------------------------

--
-- Table structure for table `user_video_live_comment_likes`
--

DROP TABLE IF EXISTS `user_video_live_comment_likes`;
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

DROP TABLE IF EXISTS `user_video_live_guest_requests`;
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

DROP TABLE IF EXISTS `user_video_live_join_requests`;
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

DROP TABLE IF EXISTS `user_video_live_reactions`;
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

DROP TABLE IF EXISTS `user_video_live_recordings`;
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

DROP TABLE IF EXISTS `user_video_live_signals`;
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

--
-- Dumping data for table `user_video_live_signals`
--

INSERT INTO `user_video_live_signals` (`id`, `live_id`, `sender_user_id`, `receiver_user_id`, `peer_key`, `signal_type`, `payload_json`, `created_at`, `consumed_at`) VALUES
(55, 55, 74, 66, 'live-55-viewer-74-peer-1-inst-thxmh2jo', 'bye', '[]', '2026-07-02 23:08:13', NULL),
(56, 55, 74, 66, 'live-55-viewer-74-peer-1-inst-thxmh2jo', 'bye', '[]', '2026-07-02 23:08:13', NULL),
(57, 55, 74, 66, 'live-55-viewer-74-peer-1-inst-thxmh2jo', 'bye', '[]', '2026-07-02 23:08:14', NULL),
(129, 56, 74, 66, 'live-56-viewer-74-peer-1-inst-mrb2xams', 'bye', '[]', '2026-07-02 23:45:01', NULL),
(165, 57, 74, 66, 'live-57-viewer-74-peer-1-inst-aaikxuhl', 'bye', '[]', '2026-07-02 23:59:16', NULL),
(166, 57, 74, 66, 'live-57-viewer-74-peer-1-inst-aaikxuhl', 'bye', '[]', '2026-07-02 23:59:16', NULL),
(253, 59, 74, 66, 'live-59-viewer-74-peer-1-inst-3qnl91qn', 'bye', '[]', '2026-07-03 00:16:11', NULL),
(254, 59, 74, 66, 'live-59-viewer-74-peer-1-inst-3qnl91qn', 'bye', '[]', '2026-07-03 00:16:11', NULL),
(341, 60, 74, 66, 'live-60-viewer-74-peer-1-inst-eo2pfz84', 'bye', '[]', '2026-07-03 00:24:03', NULL),
(414, 61, 74, 66, 'live-61-viewer-74-peer-1-inst-6wmrr3ou', 'bye', '[]', '2026-07-03 07:17:09', NULL),
(415, 61, 74, 66, 'live-61-viewer-74-peer-1-inst-6wmrr3ou', 'bye', '[]', '2026-07-03 07:17:10', NULL),
(561, 64, 74, 66, 'live-64-viewer-74-peer-1-inst-c0cl3r4s', 'bye', '[]', '2026-07-03 08:40:23', NULL),
(669, 66, 74, 66, 'live-66-viewer-74-peer-1-inst-ahurejc5', 'bye', '[]', '2026-07-03 09:15:16', NULL),
(725, 67, 74, 66, 'live-67-viewer-74-peer-1-inst-qt3fzcgd', 'bye', '[]', '2026-07-03 09:22:33', NULL),
(726, 67, 74, 66, 'live-67-viewer-74-peer-1-inst-qt3fzcgd', 'bye', '[]', '2026-07-03 09:22:33', NULL),
(836, 68, 74, 66, 'live-68-viewer-74-peer-1-inst-gy6c2pqo', 'bye', '[]', '2026-07-03 09:39:17', NULL),
(1005, 70, 74, 66, 'live-70-viewer-74-peer-1-inst-p1uki259', 'bye', '[]', '2026-07-03 10:41:14', NULL),
(1006, 70, 74, 66, 'live-70-viewer-74-peer-1-inst-p1uki259', 'bye', '[]', '2026-07-03 10:41:14', NULL),
(1042, 71, 74, 66, 'live-71-viewer-74-peer-1-inst-0k9dayux', 'bye', '[]', '2026-07-03 10:54:39', NULL),
(1283, 74, 74, 66, 'live-74-viewer-74-peer-1-inst-nuswb6oj', 'bye', '[]', '2026-07-03 11:09:17', NULL),
(1405, 75, 74, 66, 'live-75-viewer-74-peer-1-inst-vts84rwu', 'bye', '[]', '2026-07-03 11:17:39', NULL),
(1406, 75, 74, 66, 'live-75-viewer-74-peer-1-inst-zy9o3e6z', 'bye', '[]', '2026-07-03 11:45:24', NULL),
(1669, 78, 74, 66, 'live-78-viewer-74-peer-1-inst-lyqzodel', 'bye', '[]', '2026-07-03 12:36:27', NULL),
(1791, 79, 74, 66, 'live-79-viewer-74-peer-1-inst-edrj95yz', 'bye', '[]', '2026-07-03 12:54:59', NULL),
(1792, 79, 74, 66, 'live-79-viewer-74-peer-1-inst-edrj95yz', 'bye', '[]', '2026-07-03 12:54:59', NULL),
(1873, 80, 74, 66, 'live-80-viewer-74-peer-1-inst-wb75fd2r', 'bye', '[]', '2026-07-03 16:01:08', NULL),
(2045, 81, 74, 66, 'live-81-viewer-74-peer-1-inst-q81uq5lb', 'bye', '[]', '2026-07-03 16:51:45', NULL),
(2163, 83, 74, 66, 'live-83-viewer-74-peer-1-inst-yzb2tsxk', 'bye', '[]', '2026-07-03 16:57:22', NULL),
(2345, 84, 74, 66, 'live-84-viewer-74-peer-1-inst-1bzmndwn', 'bye', '[]', '2026-07-03 17:41:23', NULL),
(2346, 84, 74, 66, 'live-84-viewer-74-peer-1-inst-1bzmndwn', 'bye', '[]', '2026-07-03 17:41:23', NULL),
(2403, 85, 74, 66, 'live-85-viewer-74-peer-1-inst-b8v78hs9', 'bye', '[]', '2026-07-03 18:26:51', NULL),
(3224, 86, 74, 66, 'live-86-viewer-74-peer-1-inst-ft0ywfp4', 'bye', '[]', '2026-07-03 18:58:42', NULL),
(3225, 86, 74, 66, 'live-86-viewer-74-peer-1-inst-ft0ywfp4', 'bye', '[]', '2026-07-03 18:58:44', NULL),
(3280, 87, 74, 66, 'live-87-viewer-74-peer-1-inst-p34647c8', 'bye', '[]', '2026-07-03 19:06:01', NULL),
(3281, 86, 74, 66, 'live-86-viewer-74-peer-1-inst-dihel8e9', 'bye', '[]', '2026-07-03 19:13:11', NULL),
(3414, 89, 74, 66, 'live-89-viewer-74-peer-2-inst-gc2uzgke', 'bye', '[]', '2026-07-03 19:30:25', NULL),
(3415, 89, 74, 66, 'live-89-viewer-74-peer-2-inst-gc2uzgke', 'bye', '[]', '2026-07-03 19:30:26', NULL),
(3416, 88, 74, 66, 'live-88-viewer-74-peer-1-inst-axg2rw0r', 'bye', '[]', '2026-07-03 19:39:59', NULL),
(3679, 94, 74, 66, 'live-94-viewer-74-peer-1-inst-atteq760', 'bye', '[]', '2026-07-03 21:00:46', NULL),
(3803, 95, 66, 74, 'live-95-viewer-66-peer-1-inst-ka1lkahn', 'bye', '[]', '2026-07-03 21:23:21', NULL),
(3924, 96, 74, 66, 'live-96-viewer-74-peer-1-inst-gp2z3s09', 'bye', '[]', '2026-07-03 22:51:56', NULL),
(3925, 96, 74, 66, 'live-96-viewer-74-peer-2-inst-hb45p5c4', 'bye', '[]', '2026-07-03 22:51:57', NULL),
(4137, 98, 74, 66, 'live-98-viewer-74-peer-1-inst-pca8pxjq', 'bye', '[]', '2026-07-03 23:54:05', NULL),
(4188, 99, 74, 66, 'live-99-viewer-74-peer-1-inst-2tsltn75', 'bye', '[]', '2026-07-04 00:10:20', NULL),
(4224, 101, 74, 66, 'live-101-viewer-74-peer-1-inst-f1s9107s', 'bye', '[]', '2026-07-04 07:45:44', NULL),
(4460, 106, 74, 66, 'live-106-viewer-74-peer-1-inst-wy5pm72a', 'bye', '[]', '2026-07-04 08:08:02', NULL),
(4461, 106, 74, 66, 'live-106-viewer-74-peer-1-inst-xqzkbb8h', 'bye', '[]', '2026-07-04 08:08:08', NULL),
(4462, 106, 74, 66, 'live-106-viewer-74-peer-1-inst-xh6kmjva', 'bye', '[]', '2026-07-04 08:08:08', NULL),
(4563, 108, 74, 66, 'live-108-viewer-74-peer-1-inst-o6tn37rw', 'bye', '[]', '2026-07-04 08:17:53', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `user_video_live_usage`
--

DROP TABLE IF EXISTS `user_video_live_usage`;
CREATE TABLE `user_video_live_usage` (
  `user_id` int(11) NOT NULL,
  `total_sessions` int(11) NOT NULL DEFAULT '0',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_video_live_viewers`
--

DROP TABLE IF EXISTS `user_video_live_viewers`;
CREATE TABLE `user_video_live_viewers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `live_id` bigint(20) UNSIGNED NOT NULL,
  `viewer_user_id` int(11) NOT NULL,
  `joined_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_video_live_viewers`
--

INSERT INTO `user_video_live_viewers` (`id`, `live_id`, `viewer_user_id`, `joined_at`, `last_seen_at`) VALUES
(1876, 62, 74, '2026-07-03 07:53:33', '2026-07-03 07:53:35'),
(4510, 73, 74, '2026-07-03 11:02:41', '2026-07-03 11:02:46'),
(16289, 90, 74, '2026-07-03 19:53:03', '2026-07-03 19:57:21');

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
  ADD KEY `idx_org_status_created` (`status`,`created_at`),
  ADD KEY `idx_org_kind_status` (`org_kind`,`status`,`created_at`),
  ADD KEY `idx_org_business_model` (`business_model`,`status`),
  ADD KEY `idx_org_rent_status` (`rent_status`,`rent_paid_until`),
  ADD KEY `idx_org_platform_plan` (`platform_plan_id`);

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
-- Indexes for table `org_orders`
--
ALTER TABLE `org_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_org_orders_code` (`order_code`),
  ADD KEY `idx_org_orders_org_status` (`org_id`,`status`,`created_at`),
  ADD KEY `idx_org_orders_buyer` (`buyer_user_id`,`created_at`),
  ADD KEY `idx_org_orders_product` (`product_id`),
  ADD KEY `idx_org_orders_type` (`org_id`,`order_type`,`created_at`),
  ADD KEY `idx_org_orders_subscription` (`subscription_id`),
  ADD KEY `idx_org_orders_stripe_session` (`stripe_checkout_session_id`);

--
-- Indexes for table `org_order_receipts`
--
ALTER TABLE `org_order_receipts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_org_order_receipts_code` (`receipt_code`),
  ADD UNIQUE KEY `uq_org_order_receipts_order` (`order_id`),
  ADD KEY `idx_org_order_receipts_org` (`org_id`,`issued_at`),
  ADD KEY `idx_org_order_receipts_buyer` (`buyer_user_id`,`issued_at`);

--
-- Indexes for table `org_product_images`
--
ALTER TABLE `org_product_images`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_org_product_images_product` (`product_id`,`sort_order`),
  ADD KEY `idx_org_product_images_org` (`org_id`,`created_at`);

--
-- Indexes for table `org_product_price_tiers`
--
ALTER TABLE `org_product_price_tiers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_org_price_tiers_product` (`product_id`,`min_qty`),
  ADD KEY `idx_org_price_tiers_org` (`org_id`);

--
-- Indexes for table `org_products`
--
ALTER TABLE `org_products`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_org_products_org_status` (`org_id`,`status`,`sort_order`,`created_at`),
  ADD KEY `idx_org_products_public_post` (`public_post_id`),
  ADD KEY `idx_org_products_sku` (`org_id`,`sku`),
  ADD KEY `idx_org_products_offer_type` (`org_id`,`offer_type`,`status`);

--
-- Indexes for table `org_subscriptions`
--
ALTER TABLE `org_subscriptions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_org_subscriptions_org_status` (`org_id`,`status`,`next_billing_at`),
  ADD KEY `idx_org_subscriptions_buyer` (`buyer_user_id`,`status`),
  ADD KEY `idx_org_subscriptions_product` (`product_id`);

--
-- Indexes for table `org_posts`
--
ALTER TABLE `org_posts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_org_posts` (`org_id`,`created_at`),
  ADD KEY `idx_org_posts_type` (`org_id`,`post_type`,`created_at`),
  ADD KEY `idx_org_posts_feed_state` (`org_id`,`is_deleted`,`is_visible`,`post_state`,`created_at`,`id`),
  ADD KEY `idx_org_posts_public_post` (`public_post_id`);

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
-- Indexes for table `platform_payments`
--
ALTER TABLE `platform_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_platform_payments_org` (`org_id`,`paid_at`),
  ADD KEY `idx_platform_payments_plan` (`plan_id`);

--
-- Indexes for table `platform_plans`
--
ALTER TABLE `platform_plans`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_platform_plans_code` (`code`),
  ADD KEY `idx_platform_plans_active` (`is_active`,`sort_order`);

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
  ADD KEY `idx_user_video_live_comments_user` (`user_id`,`created_at`),
  ADD KEY `idx_live_parent` (`live_id`,`parent_id`,`id`);

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
  MODIFY `idadmin` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

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
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `messages`
--
ALTER TABLE `messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notification`
--
ALTER TABLE `notification`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1605;

--
-- AUTO_INCREMENT for table `organizations`
--
ALTER TABLE `organizations`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `organization_users`
--
ALTER TABLE `organization_users`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=160;

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
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=118;

--
-- AUTO_INCREMENT for table `org_messages`
--
ALTER TABLE `org_messages`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `org_message_threads`
--
ALTER TABLE `org_message_threads`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `org_orders`
--
ALTER TABLE `org_orders`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `org_order_receipts`
--
ALTER TABLE `org_order_receipts`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `org_product_images`
--
ALTER TABLE `org_product_images`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `org_product_price_tiers`
--
ALTER TABLE `org_product_price_tiers`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `org_products`
--
ALTER TABLE `org_products`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `org_subscriptions`
--
ALTER TABLE `org_subscriptions`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

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
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=53;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

--
-- AUTO_INCREMENT for table `public_posts`
--
ALTER TABLE `public_posts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=494;

--
-- AUTO_INCREMENT for table `public_post_attachments`
--
ALTER TABLE `public_post_attachments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=376;

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
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

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
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `publisher_name_options`
--
ALTER TABLE `publisher_name_options`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `platform_payments`
--
ALTER TABLE `platform_payments`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `platform_plans`
--
ALTER TABLE `platform_plans`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `role`
--
ALTER TABLE `role`
  MODIFY `idrole` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `staff_accounts`
--
ALTER TABLE `staff_accounts`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `timeline_access_requests`
--
ALTER TABLE `timeline_access_requests`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=76;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=59;

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
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3867;

--
-- AUTO_INCREMENT for table `user_sessions`
--
ALTER TABLE `user_sessions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10057;

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
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=110;

--
-- AUTO_INCREMENT for table `user_video_live_comments`
--
ALTER TABLE `user_video_live_comments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `user_video_live_comment_likes`
--
ALTER TABLE `user_video_live_comment_likes`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

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
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4565;

--
-- AUTO_INCREMENT for table `user_video_live_viewers`
--
ALTER TABLE `user_video_live_viewers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23912;

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
-- Constraints for table `org_orders`
--
ALTER TABLE `org_orders`
  ADD CONSTRAINT `fk_org_orders_org` FOREIGN KEY (`org_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_org_orders_product` FOREIGN KEY (`product_id`) REFERENCES `org_products` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_org_orders_subscription` FOREIGN KEY (`subscription_id`) REFERENCES `org_subscriptions` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `org_order_receipts`
--
ALTER TABLE `org_order_receipts`
  ADD CONSTRAINT `fk_org_order_receipts_org` FOREIGN KEY (`org_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_org_order_receipts_order` FOREIGN KEY (`order_id`) REFERENCES `org_orders` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `org_product_images`
--
ALTER TABLE `org_product_images`
  ADD CONSTRAINT `fk_org_product_images_org` FOREIGN KEY (`org_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_org_product_images_product` FOREIGN KEY (`product_id`) REFERENCES `org_products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `org_product_price_tiers`
--
ALTER TABLE `org_product_price_tiers`
  ADD CONSTRAINT `fk_org_price_tiers_org` FOREIGN KEY (`org_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_org_price_tiers_product` FOREIGN KEY (`product_id`) REFERENCES `org_products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `org_products`
--
ALTER TABLE `org_products`
  ADD CONSTRAINT `fk_org_products_org` FOREIGN KEY (`org_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `org_subscriptions`
--
ALTER TABLE `org_subscriptions`
  ADD CONSTRAINT `fk_org_subscriptions_org` FOREIGN KEY (`org_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_org_subscriptions_product` FOREIGN KEY (`product_id`) REFERENCES `org_products` (`id`) ON DELETE RESTRICT;

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
-- Constraints for table `organizations`
--
ALTER TABLE `organizations`
  ADD CONSTRAINT `fk_organizations_platform_plan` FOREIGN KEY (`platform_plan_id`) REFERENCES `platform_plans` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `platform_payments`
--
ALTER TABLE `platform_payments`
  ADD CONSTRAINT `fk_platform_payments_org` FOREIGN KEY (`org_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_platform_payments_plan` FOREIGN KEY (`plan_id`) REFERENCES `platform_plans` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_platform_payments_admin` FOREIGN KEY (`recorded_by_admin_id`) REFERENCES `admin` (`idadmin`) ON DELETE SET NULL;

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

SET FOREIGN_KEY_CHECKS = 1;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
