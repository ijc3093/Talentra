# Software Engineer Guide for public_user

Date: 2026-04-23

## Purpose

This document is the senior engineering handoff for `public_user`.

It explains:

1. what I recently did in the project
2. how the project currently runs
3. how the architecture should be owned going forward
4. where code should go
5. the standards for PHP, JS, CSS, SQL, security, and shared components
6. the current technical debt and the recommended cleanup order

This file should be treated as the main engineering guide for the `public_user` surface.

## 1. Recent work completed in public_user

### Step 1. Mapped the codebase

I reviewed the project structure and confirmed the main runtime surfaces:

- page entry files in the root such as `feed.php`, `public.php`, `public_live.php`, `live_watch.php`, `messages.php`, `dashboard.php`, and `profile.php`
- shared helpers in `includes/`
- action and polling endpoints in `ajax/`
- frontend assets in `assets/`, `css/`, and `js/`
- shared database and auth logic in `controller.php`

I also confirmed this workspace is not a git repository, so changes are being made directly in the working files.

### Step 2. Audited shared auth and identity flow

Files reviewed:

- `controller.php`
- `includes/session_user.php`
- `includes/user_identity.php`
- `includes/header.php`
- `includes/friend_system.php`

Why this mattered:

- these files affect almost every protected page
- bugs here can break multiple screens at once
- they control session state, current user resolution, notification counts, friend state, and shared header behavior

### Step 3. Audited the main user-facing pages

Files reviewed:

- `feed.php`
- `public.php`
- `public_live.php`
- `live_watch.php`
- `dashboard.php`
- `messages.php`
- `profile.php`
- `feed_api.php`
- `ajax/live_watch_room.php`
- `ajax/live_studio_host_action.php`

Goal of this review:

- understand page responsibilities
- identify duplicated logic
- identify shared risks around visibility, counters, session state, and live behavior

### Step 4. Verified syntax on critical files

I ran `php -l` on the main shared files and main page/API entry points.

Files verified:

- `controller.php`
- `includes/header.php`
- `includes/friend_system.php`
- `includes/user_identity.php`
- `feed.php`
- `public.php`
- `public_live.php`
- `live_watch.php`
- `dashboard.php`
- `messages.php`
- `profile.php`
- `feed_api.php`
- `ajax/live_watch_room.php`
- `ajax/live_studio_host_action.php`

Result:

- no syntax errors were found in the reviewed files

### Step 5. Fixed one shared identity bug

Changed file:

- `includes/user_identity.php`

Problem found:

- the current logged-in user row was being resolved using session username
- that is brittle because username can change or become stale
- a shared identity helper should prefer the stable primary key, which is the user id

Fix applied:

- `includes/user_identity.php` now bootstraps through `includes/session_user.php`
- it resolves the current user by `$_SESSION['user_id']` first
- it only falls back to username lookup for legacy safety

Why this matters:

- safer identity resolution across `feed.php`, `profile.php`, `messages.php`, and `includes/header.php`
- less risk after username changes
- less risk of loading the wrong user state from stale session username data

### Step 6. Verified the fix

After the change, I re-ran validation on `includes/user_identity.php` and confirmed:

- the file loads correctly
- the file still passes `php -l`

## 2. Exact recent code change

Only one shared code change was completed in this recent pass:

- `includes/user_identity.php`

Plain explanation:

- before: current user lookup depended on session username
- after: current user lookup depends on session user id first, with username as fallback only

Risk level:

- low risk
- no schema change
- no route change
- no HTML structure change
- stronger shared identity behavior

## 3. How public_user currently runs

At a high level, `public_user` is a PHP application with page entry points and AJAX endpoints.

Typical request flow:

1. the browser requests a page such as `feed.php` or `profile.php`
2. the page loads `includes/session_user.php`
3. `requireUserLogin()` validates the user session
4. `controller.php` creates the PDO database connection
5. the page runs SQL queries and renders HTML
6. JavaScript on the page calls `ajax/` endpoints for dynamic behavior such as feed polling, chat updates, live room state, reactions, counters, and notifications

This means the application is both:

- server-rendered at page load
- AJAX-driven after page load

## 4. Architecture ownership model

As the senior engineering direction for this project, the app should be thought of in layers.

### 4.1 Session and access layer

Main files:

- `includes/session_user.php`
- `controller.php`
- `includes/user_identity.php`

Responsibilities:

- session start
- protected page gating
- session validation
- current user identity helpers
- session revocation
- shared PDO access

Architecture rule:

- all protected pages should enter through this layer first
- no page should bypass `requireUserLogin()` if it exposes user data or actions

### 4.2 Shared shell and header layer

Main files:

- `includes/header.php`
- `includes/navleftbar.php`
- `includes/leftbar.php`
- `includes/sidebarleft.php`

Responsibilities:

- shared page shell
- notification summary
- unread chat summary
- friend request badges
- theme state
- shared rails and navigation

Architecture rule:

- UI markup should stay here
- heavy query logic should gradually move out into focused helper functions or smaller includes

### 4.3 Social graph and friend logic layer

Main files:

- `includes/friend_system.php`
- `ajax/friend_action.php`
- `ajax/friend_status.php`
- `contact_requests.php`
- `contacts.php`
- `add_contact.php`

Responsibilities:

- friend detection
- request sending
- request accepting
- request declining
- friend counts
- friend-only access rules

Architecture rule:

- friend relationship truth should be resolved in one shared place
- visibility decisions should reuse shared friend helpers instead of reimplementing custom checks per page

### 4.4 Feed and public content layer

Main files:

- `dashboard.php`
- `compose.php`
- `post_save.php`
- `feed.php`
- `feed_api.php`
- `public.php`
- `post_view.php`
- `profile.php`
- `includes/post_categories.php`

Responsibilities:

- post creation and editing
- post category management
- feed rendering
- public/friends visibility
- post counters
- post detail and profile timeline display

Architecture rule:

- page entry files should coordinate request flow
- reusable post logic should move into shared helpers instead of being copied between `feed.php`, `public.php`, `profile.php`, and `feed_api.php`

### 4.5 Messaging layer

Main files:

- `messages.php`
- `ajax/chat_poll.php`
- `ajax/chat_send.php`
- `ajax/user_chat_poll.php`
- `ajax/user_chat_send.php`
- `ajax/group_chat_poll.php`
- `ajax/group_chat_send.php`
- `ajax/chat_typing.php`
- `ajax/chat_typing_check.php`

Responsibilities:

- thread listing
- direct messaging
- group messaging
- unread counts
- typing indicators
- hidden messages
- group member actions

Architecture rule:

- `messages.php` is currently oversized and should be treated as a refactor priority
- shared message persistence, unread behavior, and presence checks should not be duplicated across endpoints

### 4.6 Live and realtime layer

Main files:

- `live_studio.php`
- `public_live.php`
- `live_watch.php`
- `ajax/live_studio_host_action.php`
- `ajax/live_studio_room_action.php`
- `ajax/live_watch_room.php`
- `ajax/live_signal.php`
- `ajax/live_snapshot.php`
- `ajax/live_stream_host_poll.php`

Responsibilities:

- live setup
- draft/schedule/start/end lifecycle
- public live listing
- viewer presence
- comments and reactions
- guest approvals
- snapshots
- signaling

Architecture rule:

- access rules and counters must be consistent between host endpoints and watcher endpoints
- live table bootstrap logic should eventually be centralized to reduce drift

## 5. File ownership rules

This is where code should go going forward.

### Root page files

Examples:

- `feed.php`
- `public.php`
- `dashboard.php`
- `messages.php`

Allowed responsibilities:

- request entry
- permission gate
- small page-specific query coordination
- page HTML assembly
- page-local wiring

Avoid:

- duplicating shared business logic
- defining the same helper in many pages
- embedding large reusable CSS or JS blocks that belong in assets

### `includes/`

Put here:

- shared PHP helpers
- shared UI partials
- shared business rules
- shared access and identity logic

Use `includes/` when logic is reused across multiple pages or multiple AJAX endpoints.

### `ajax/`

Put here:

- action endpoints
- polling endpoints
- JSON APIs for page updates

Rules:

- must enforce login and authorization
- must validate input
- must return consistent JSON
- must not trust page state from the browser

### `assets/`, `css/`, `js/`

Put here:

- shared styles
- shared UI behavior
- reusable JavaScript helpers

Rule:

- if the same CSS or JS is needed in more than one page, it should move out of inline blocks and into shared assets

### `docs/`

Put here:

- architecture notes
- engineering standards
- operational handoffs
- technical debt plans

## 6. Coding standards for this project

### 6.1 PHP

Standards:

- use `declare(strict_types=1);` in files where it is already consistent and safe
- prefer prepared statements for all database input
- keep helpers small and single-purpose
- escape HTML output with a shared helper such as `h()`
- prefer shared helper functions over copy-pasted inline logic
- page files should coordinate work, not become full business-logic libraries

Naming:

- use descriptive snake_case for helper functions when consistent with the current codebase
- keep names tied to feature scope, for example `watch_*`, `studio_*`, `fs_*`

### 6.2 JavaScript

Standards:

- keep page bootstrap small
- move reusable logic into shared asset files
- do not duplicate polling or rendering helpers across pages if they serve the same feature
- always handle failed AJAX responses gracefully

Rules:

- frontend state is not authorization
- the server must re-check permissions for every action

### 6.3 CSS

Standards:

- shared header, rail, button, tab, and layout styles belong in shared assets
- avoid pasting large style blocks into multiple pages
- page-local CSS is acceptable only when it is truly unique to that page
- use one naming pattern consistently inside a feature area

### 6.4 SQL

Standards:

- use prepared statements for dynamic values
- keep access rules in the query where appropriate
- prefer stable ownership checks such as user id over display text or usernames
- counters must have a clear source of truth

Avoid:

- name-based ownership logic
- duplicated counter rules in multiple places
- silent authorization gaps

### 6.5 Naming and structure

Standards:

- shared feature helpers should live near their domain
- friend logic in `includes/friend_system.php`
- live logic in live-related endpoints and helpers
- post/category logic in feed or post helper areas

Rule:

- do not fix the same bug in three different files if the bug is caused by one shared assumption

## 7. Security and permissions rules

These are the rules that should guide all future work.

### Session and login

- all protected pages and protected AJAX endpoints must call `requireUserLogin()`
- session identity should rely on `user_id` as the stable key
- session validation failures should fail closed, not open

### Authorization

- users should only update rows they own or are explicitly allowed to control
- friend-only visibility must be enforced server-side
- public visibility must still respect ownership and deletion rules
- live host actions must only be allowed for the live owner
- delete, edit, react, like, and join actions must check ownership or access every time

### Request validation

- never trust ids or action names directly from the browser
- cast and validate ids
- reject unsupported actions explicitly
- return clear JSON errors for AJAX endpoints

## 8. Data integrity rules

These rules matter most for counters and realtime state.

### Feed and post integrity

- `views_count` should remain a cached display value backed by unique view records
- unread state should depend on post timestamps versus read state, not duplicated client assumptions
- delete behavior should be soft-delete when the current model expects `is_deleted`

### Friend integrity

- requests should not duplicate
- accepting a request should create contact state safely in both directions when that is the model
- friend counts should be derived from the same source used by friend visibility checks

### Live integrity

- viewer count should come from active presence, not blind increments
- host end-live should clean active viewer/presence state
- comments, reactions, guest approvals, and viewer presence should all agree on the same live id and access rules

### Notification integrity

- unread counts should reflect stored unread state, not just page-local assumptions
- shared header counts should reuse shared logic where possible

## 9. Performance rules

Performance risks already visible in the codebase:

- large mixed files
- repeated polling
- runtime table existence checks
- duplicated queries across page shells
- heavy inline rendering blocks

Recommended direction:

1. reduce repeated queries in shared header code
2. centralize table existence/bootstrap checks where practical
3. move repeated UI and helper logic into shared files
4. review polling intervals and duplicate live/chat fetches
5. keep page entry files thinner so they do less work per request

## 10. Maintainability and technical debt priorities

This is the recommended cleanup order.

### Priority 1. Shared identity and access correctness

Why first:

- bugs here affect multiple pages at once

Status:

- one identity fix was already completed in `includes/user_identity.php`

Next:

- continue removing brittle shared assumptions

### Priority 2. `includes/header.php`

Why:

- it mixes UI, identity, unread counts, notifications, and shared shell behavior
- one regression here can break many pages

Recommended cleanup:

- extract chat summary queries
- extract notification summary queries
- reduce page-global logic in the template

### Priority 3. `messages.php`

Why:

- very large mixed PHP/HTML/JS file
- high chance of regressions

Recommended cleanup:

- split server-side helpers from rendering
- split reusable client behavior into assets
- identify core message actions and centralize them

### Priority 4. Live schema/bootstrap duplication

Why:

- multiple live endpoints create or alter related tables at runtime
- drift here can create inconsistent behavior between host and watcher flows

Recommended cleanup:

- centralize shared live bootstrap helpers

### Priority 5. Repeated utility logic

Examples:

- `h()`
- avatar URL builders
- time formatting helpers
- table existence helpers

Recommended cleanup:

- move stable shared utilities into reusable helpers

## 11. Review checklist for other developers’ changes

When reviewing future work in `public_user`, check these first:

1. does the change duplicate logic that already exists elsewhere
2. does it rely on username, name, or UI state where it should rely on user id or server state
3. does it enforce login and authorization in the endpoint itself
4. does it risk double-counting views, reactions, viewers, or notifications
5. does it add CSS or JS that should live in shared assets instead
6. does it make `messages.php`, `includes/header.php`, or live endpoints more tangled instead of clearer
7. does it introduce a page-specific fix where a shared helper should be corrected once

## 12. Quick fix vs proper refactor

Use a quick fix only when:

- the bug is isolated
- the logic is truly page-specific
- the change does not create a second source of truth

Do a proper refactor when:

- the same logic appears in multiple files
- the bug affects shared identity, permissions, counters, or header behavior
- the change touches `includes/header.php`, `messages.php`, `feed_api.php`, or live state endpoints

Rule:

- if a quick fix would need to be copied into multiple pages, stop and refactor the shared layer instead

## 13. Files to read first to understand the project

Read in this order:

1. `controller.php`
2. `includes/session_user.php`
3. `includes/user_identity.php`
4. `includes/header.php`
5. `includes/friend_system.php`
6. `dashboard.php`
7. `feed.php`
8. `feed_api.php`
9. `public.php`
10. `profile.php`
11. `messages.php`
12. `public_live.php`
13. `live_watch.php`
14. `ajax/live_studio_host_action.php`
15. `ajax/live_watch_room.php`

## 14. Related documentation

Also read:

- `docs/Senior_Backend_Engineer_database_system_architecture.md`
- `docs/Senior_Backend_Engineer _database_update_guide.md`

Those files explain the broader schema and data-layer hardening work.
This file is the application-side engineering guide focused on runtime behavior, standards, ownership, and recent work.

## 15. Final summary

The recent hands-on work in `public_user` was:

- codebase mapping
- shared auth and identity audit
- main page and live endpoint audit
- syntax verification on key files
- one shared identity fix in `includes/user_identity.php`

The current engineering direction is:

- centralize shared behavior
- reduce duplication
- protect authorization and counter integrity
- refactor the largest shared-risk files before patching them again
- keep the UI shell and shared behavior consistent across feed, public, messaging, and live features
