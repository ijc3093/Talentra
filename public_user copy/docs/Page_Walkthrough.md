# Page Walkthrough for public_user

Date: 2026-04-29

## Purpose

This document explains the `public_user` application from the page and runtime perspective.

It is meant to help a developer quickly understand:

1. what each main page does
2. which shared backend files control behavior
3. how the AJAX surface supports the UI
4. how the pages connect to the main data domains

This is a practical walkthrough document, not only an architecture note.


## Architecture Screens

The following visual architecture screens are available in `docs/screens`:

1. [public_user_senior_backend_architecture.svg](https://github.com/ijc3093/Business3/blob/master/public_user/docs/screens/public_user_senior_backend_architecture.svg)
2. [public_user_behavior_request_flow.svg](https://github.com/ijc3093/Business3/blob/master/public_user/docs/screens/public_user_behavior_request_flow.svg)

Use them together with this page walkthrough:

- the senior backend architecture screen explains the ownership model, backend rules, and safe-change flow
- the behavior request flow screen explains how user actions move through pages, shared policy checks, and source-of-truth tables


## 1. What public_user is

`public_user` is the user-facing social application surface of the larger `Talentra` platform.

At a high level, it allows users to:

1. register and log in
2. manage a profile
3. create and browse posts
4. add friends and manage contacts
5. send direct and group messages
6. receive notifications
7. host or watch live sessions
8. manage sessions, privacy, and account security

This project is a custom PHP application backed by a shared MySQL database.

It is not structured as a Laravel-style framework app. Instead, it uses:

1. top-level PHP pages as request entry points
2. shared include files for authentication and repeated rules
3. many AJAX endpoints for realtime updates and interactions
4. one central database access layer in `controller.php`


## 2. Main runtime model

The most common request flow is:

1. the browser requests a PHP page
2. the page loads `includes/session_user.php`
3. `requireUserLogin()` validates the user session
4. the page creates a PDO connection through `controller.php`
5. shared helpers resolve current-user and relationship state
6. the page renders HTML
7. frontend JavaScript calls AJAX endpoints for live updates

Important shared files in that flow:

- `controller.php`
- `includes/session_user.php`
- `includes/user_identity.php`
- `includes/friend_system.php`
- `includes/header.php`


## 3. Top-level page walkthrough

### 3.1 Authentication and account entry

#### `index.php`

Purpose:

- login page for end users

What it does:

- loads session bootstrap from `includes/session_user.php`
- redirects already-authenticated users to `feed.php`
- accepts username or email plus password
- authenticates through `Controller::userLogin()`
- creates the logged-in session
- updates `users.last_seen`

Main behavior:

- this is the normal public entry point into the app


#### `register.php`

Purpose:

- account creation page

What it does:

- validates required fields
- checks duplicate username or email
- hashes the password
- generates a unique `friend_code`
- inserts a new row into `users`
- creates an admin notification row

Main behavior:

- every new user gets a stable friend code used across social features


#### `forget.php`

Purpose:

- password reset request page

Expected behavior:

- accepts an account identifier
- triggers token-based reset behavior


#### `reset.php`

Purpose:

- complete password reset with a valid token

Expected behavior:

- validates a reset token
- writes a new password hash


#### `logout.php`

Purpose:

- sign the user out

Expected behavior:

- clears session data
- revokes the current tracked session when session tracking is enabled


### 3.2 Feed, posts, and public content

#### `feed.php`

Purpose:

- main logged-in feed experience

What it does:

- requires login early
- loads current user theme preferences
- prepares the feed shell and layout
- uses frontend code plus `feed_api.php` for dynamic content behavior

Main behavior:

- this is the main home screen after login
- it is primarily a page shell for the friends/private feed experience


#### `feed_api.php`

Purpose:

- backend JSON API for feed interactions

What it does:

- validates the session
- lists posts
- applies visibility rules
- supports filters like `all`, `mine`, `author`, and `unread`
- handles reactions, comments, reads, and notifications
- updates derived state like view/read counters

Main behavior:

- this file carries a large share of the real feed business logic


#### `dashboard.php`

Purpose:

- authoring and control surface for user posts

What it does:

- requires login
- ensures post category schema exists
- loads the user’s recent posts
- supports create-category actions
- loads edit context for an existing post

Main behavior:

- acts as a post management and post preparation screen


#### `compose.php`

Purpose:

- create a new post

Expected behavior:

- provide the content composition UI
- collect text/media/category input


#### `post_save.php`

Purpose:

- save or update a post

Expected behavior:

- validate ownership
- write into `public_posts`
- save related attachments into `public_post_attachments`


#### `post_view.php`

Purpose:

- view one post in detail

Expected behavior:

- load a single post plus related comments and reactions


#### `public.php`

Purpose:

- public discovery page for public posts

What it does:

- requires login
- shows only `public` visibility posts
- supports search
- loads attachments for each post
- computes reaction and save/share counts
- lets the owner soft-delete their own posts

Main behavior:

- acts as the public browsing surface, separate from the main feed


### 3.3 Profile and social identity

#### `profile.php`

Purpose:

- view a user profile

What it does:

- requires login
- resolves the viewed user by `friend_code`, `username`, or `id`
- loads core profile fields from `users`
- loads extended about/background data when available
- computes post count and friend count
- resolves friendship state between viewer and viewed profile
- adjusts available tabs/actions based on access context

Main behavior:

- profile behavior depends heavily on identity resolution and friend status


#### `profile_update.php`

Purpose:

- save profile changes

Expected behavior:

- write updated profile fields
- persist user profile/about information


#### `save_gear_media.php`

Purpose:

- save profile gear media

Expected behavior:

- update avatar or cover-style assets


#### `save_privacy.php`

Purpose:

- save privacy settings

Expected behavior:

- persist profile/feed/privacy preferences


#### `avatar.php`

Purpose:

- return a user avatar image or generated fallback

Main behavior:

- many pages use this file to render avatars consistently


### 3.4 Contacts, follows, and relationship management

#### `contacts.php`

Purpose:

- show and manage the user’s contact/friend list


#### `contact_requests.php`

Purpose:

- manage incoming and outgoing friend requests

Main behavior:

- works with `contact_requests` and `user_contacts`


#### `add_contact.php`

Purpose:

- help the user add a new contact/friend


#### `follow_toggle.php`

Purpose:

- change follow/unfollow state

Main behavior:

- supports follow graph behavior separate from full friendship


### 3.5 Messaging and conversations

#### `messages.php`

Purpose:

- primary messaging interface

What it does:

- requires login
- updates the current user’s `last_seen`
- supports private chat and group chat modes
- resolves peer identity and thread state
- formats messages and timestamps
- supports reply payloads
- manages typing state and unread state
- integrates some group video-call support

Main behavior:

- this is one of the most complex pages in the app
- it acts like the main communication hub


#### `chat.php`

Purpose:

- conversation-focused chat page

Expected behavior:

- render a specific chat conversation or support thread


#### `support.php`

Purpose:

- route a user into a support conversation

What it does:

- creates or finds a support conversation
- adds the user as a participant
- redirects into `chat.php`


#### `user_sendreply.php`

Purpose:

- send a chat or reply action

Expected behavior:

- persist a response into the app’s chat/message system


### 3.6 Live video and realtime

#### `public_live.php`

Purpose:

- list active live sessions

What it does:

- requires login
- supports `public` and `friends` scope
- loads live rows from `user_video_lives`
- loads host display data from `users`
- resolves friend status between viewer and host
- adapts UI behavior based on relationship status

Main behavior:

- this is the live-session discovery page


#### `live_watch.php`

Purpose:

- watch a single live session

What it does:

- requires login
- loads one row from `user_video_lives`
- joins host data from `users`
- enforces room visibility rules
- only allows access when the viewer is the owner, the room is public, or friendship grants access

Main behavior:

- this is the protected live-room viewer shell


#### `live_studio.php`

Purpose:

- host-side live session setup and control

Expected behavior:

- create drafts
- schedule/start/end live rooms
- manage the host room state


### 3.7 Security and session management

#### `manage_devices.php`

Purpose:

- show and manage active device sessions

What it does:

- requires login
- reads from `user_sessions`
- lists tracked session rows
- allows revoking one session
- allows revoking all other sessions
- respects the user setting `allow_logout_all_devices`

Main behavior:

- this page is the UI for multi-device session control


#### `security_tools.php`

Purpose:

- security-focused account tools


#### `change-password.php`

Purpose:

- authenticated password update page


#### `account_tools.php`

Purpose:

- account-level actions and controls


### 3.8 Other top-level pages

#### `timeline.php`

Purpose:

- timeline-related experience

Likely behavior:

- timeline requests, access decisions, or timeline presentation workflows


#### `user_edit.php`

Purpose:

- edit user-specific details


#### `render_media.php`

Purpose:

- shared media rendering helper/endpoint


#### `phpinfo.php`

Purpose:

- PHP environment inspection page

Important note:

- this should not be exposed in production


## 4. Shared backend files and what they control

### `controller.php`

Responsibilities:

- create the shared PDO connection
- handle login checks
- verify and upgrade password hashes
- write security audit logs
- support password-reset email behavior

Main role:

- this is the common backend entry point for database access


### `includes/session_user.php`

Responsibilities:

- start the user session
- enforce login on protected pages
- validate the current session against `user_sessions`
- track session token, user agent, and IP
- revoke current or other sessions
- update `users.last_seen`

Main role:

- this is the core session and device-safety layer


### `includes/user_identity.php`

Responsibilities:

- resolve the current logged-in user reliably
- prefer stable `user_id` over username

Main role:

- this is the shared current-user identity helper


### `includes/friend_system.php`

Responsibilities:

- detect whether two users are friends
- count friends
- send friend requests
- accept friend requests
- decline friend requests
- compute friend relationship state

Main role:

- this is the shared social-relationship rule layer


### `includes/header.php`

Responsibilities:

- load cross-page user header state
- resolve avatar/theme information
- load unread thread summaries
- load friend request count
- load recent notifications

Main role:

- this is a shared UI-plus-query layer used across many pages


### `includes/group_video_call_lib.php`

Responsibilities:

- ensure group video call tables exist
- manage participants
- manage call status
- manage signaling rows

Main role:

- this is the shared group call support library


## 5. AJAX and API surface

The `ajax/` and `api/` files support the dynamic side of the product.

These endpoints are important because many features do not complete with a full page reload.

### 5.1 Chat and messaging endpoints

- `ajax/chat_poll.php`
- `ajax/chat_send.php`
- `ajax/chat_typing.php`
- `ajax/chat_typing_check.php`
- `ajax/chat_unread_poll.php`
- `ajax/user_chat_poll.php`
- `ajax/user_chat_send.php`
- `ajax/user_chat_threads_poll.php`
- `ajax/user_chat_unread_poll.php`
- `ajax/user_chat_typing.php`
- `ajax/user_chat_typing_ping.php`
- `ajax/user_chat_typing_poll.php`
- `ajax/user_chat_message_action.php`
- `ajax/group_chat_poll.php`
- `ajax/group_chat_send.php`
- `api/chat_mark_read.php`
- `api/chat_mark_all_read.php`

Responsibilities:

- polling messages
- sending messages
- typing indicators
- thread unread counts
- thread actions
- group chat updates


### 5.2 Friendship and contact endpoints

- `ajax/friend_action.php`
- `ajax/friend_status.php`
- `ajax/contact_rename.php`
- `ajax/contact_undo_rename.php`
- `ajax/contact_save_from_messages.php`

Responsibilities:

- friend request actions
- status checks
- per-contact display name changes


### 5.3 Notification and presence endpoints

- `ajax/notifications_poll.php`
- `ajax/user_notifications_poll.php`
- `ajax/notification_mark_read.php`
- `ajax/user_mark_read.php`
- `ajax/user_mark_all_read.php`
- `ajax/me_presence_heartbeat.php`
- `ajax/user_presence_ping.php`
- `ajax/user_presence_batch.php`

Responsibilities:

- notification polling
- mark read behavior
- current-user presence updates
- batch presence checks for UI state


### 5.4 Live and realtime endpoints

- `ajax/live_watch_room.php`
- `ajax/live_studio_host_action.php`
- `ajax/live_studio_room_action.php`
- `ajax/live_signal.php`
- `ajax/live_snapshot.php`
- `ajax/live_stream_host_poll.php`
- `ajax/live_stream_start.php`
- `ajax/live_watch_room.php`

Responsibilities:

- watch-room state polling
- host room actions
- signaling
- viewer/guest state
- snapshots
- room start/poll behavior


### 5.5 Video-call endpoints

- `ajax/video_call_start.php`
- `ajax/video_call_poll.php`
- `ajax/video_call_signal.php`

Responsibilities:

- one-to-one call initiation
- call polling
- signaling exchange


### 5.6 Timeline-related endpoints

- `ajax/timeline_request.php`
- `ajax/timeline_decide.php`
- `ajax/timeline_requests_poll.php`

Responsibilities:

- timeline access/request workflows


## 6. Main data domains behind the pages

The page layer maps onto a few main backend domains.

### 6.1 Identity and session domain

Important tables:

- `users`
- `user_sessions`
- `user_profile_settings`
- `timeline_profiles`
- `user_backgrounds`

Used by:

- `index.php`
- `register.php`
- `manage_devices.php`
- `profile.php`
- `includes/session_user.php`


### 6.2 Social graph domain

Important tables:

- `user_contacts`
- `contact_requests`
- `public_follows`
- `public_profile_access`

Used by:

- `profile.php`
- `contacts.php`
- `contact_requests.php`
- `add_contact.php`
- `public_live.php`
- `includes/friend_system.php`


### 6.3 Feed and public content domain

Important tables:

- `public_posts`
- `public_post_attachments`
- `public_post_comments`
- `public_comment_likes`
- `public_post_reactions`
- `public_post_reads`
- `public_post_saves`
- `public_post_shares`
- `public_post_tags`
- `public_post_views`
- `public_post_view_daily`
- `public_saved_posts`
- `user_post_categories`

Used by:

- `feed.php`
- `feed_api.php`
- `dashboard.php`
- `compose.php`
- `post_save.php`
- `post_view.php`
- `public.php`
- `profile.php`


### 6.4 Messaging domain

Important tables:

- `feedback`
- `chat_messages`
- `chat_groups`
- `chat_group_members`
- `chat_group_messages`
- `chat_typing`
- `conversations`
- `conversation_participants`
- `messages`
- `message_reads`

Used by:

- `messages.php`
- `chat.php`
- `support.php`
- chat-related AJAX endpoints


### 6.5 Live and video domain

Important tables:

- `user_video_lives`
- `user_video_live_comments`
- `user_video_live_comment_likes`
- `user_video_live_guest_requests`
- `user_video_live_join_requests`
- `user_video_live_reactions`
- `user_video_live_viewers`
- `user_video_live_usage`
- `user_video_live_signals`
- `user_video_calls`
- `user_video_call_signals`
- `group_video_calls`
- `group_video_call_participants`
- `group_video_call_signals`

Used by:

- `public_live.php`
- `live_watch.php`
- `live_studio.php`
- live/video AJAX endpoints
- `includes/group_video_call_lib.php`


## 7. Key behavior guarantees the system depends on

From a maintenance point of view, `public_user` is healthy when these guarantees remain true:

1. current-user identity resolves correctly on every request
2. protected pages reject invalid or expired sessions
3. ownership checks protect writes like delete, edit, and manage actions
4. friendship and visibility rules are applied consistently across pages and AJAX
5. unread counts and presence state stay reasonably accurate
6. live-room access follows the same rules in both page loads and AJAX calls

This matters because one user session can move through feed, profile, chat, and live behavior without leaving the app.


## 8. Plain-language summary

`public_user` is a custom PHP social application inside the larger `Talentra` platform.

It combines:

1. identity and profile management
2. friends and contacts
3. posting and feed behavior
4. direct and group messaging
5. notifications and presence
6. live streaming and video interaction
7. device and security controls

The most important thing to understand is that it is one connected application, not a set of isolated pages.

The page layer, shared includes, AJAX endpoints, and database tables all work together as one runtime system.
