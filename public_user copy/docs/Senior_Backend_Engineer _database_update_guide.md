# Public User Database Update Guide

This guide explains the schema hardening work added to [Talentra.sql](https://github.com/ijc3093/Business3/blob/master/Data/Talentra.sql:5051) for the `public_user` project.

The goal of the update was to make the public social tables, live tables, and security tables safer to run in production without breaking the existing PHP pages and AJAX endpoints.

## 1. How to read this guide

Read it in this order:

1. Start with the ownership model around `users`.
2. Follow the public post lifecycle.
3. Follow privacy and access-control tables.
4. Follow the live-session lifecycle.
5. Finish with counters, analytics, and security rules.

## 2. Scope of the recent SQL update

The recent SQL block did six kinds of work:

1. Cleaned invalid rows before adding stricter rules.
2. Normalized mixed ID types so foreign keys could be added safely.
3. Added missing foreign keys between public/live/security tables and their parent records.
4. Added feed and reporting indexes for high-traffic queries.
5. Added triggers to stop invalid writes and keep counters in sync.
6. Documented data contracts directly in the SQL for source-of-truth vs cached tables.

## 3. Main architecture

The project now reads best as five layers:

1. Identity layer: `users`, `admin`
2. Public content layer: `public_posts` and its child tables
3. Privacy layer: `public_follows`, `public_profile_access`
4. Live layer: `user_video_lives` and its child tables
5. Security and reporting layer: `password_reset_tokens`, `public_post_view_daily`, cached counters

The root ownership rule is simple:

- `users` owns most public and live data.
- `public_posts` owns public attachments, comments, reactions, reads, saves, shares, tags, and view records.
- `user_video_lives` owns live comments, live reactions, viewers, guest requests, and join requests.

## 4. SQL anchors

Use these links when you want to cross-check the dump:

- Hardening block: [Talentra.sql](https://github.com/ijc3093/Business3/blob/master/Data/Talentra.sql:5051)
- `users`: [Talentra.sql](https://github.com/ijc3093/Business3/blob/master/Data/Talentra.sql:2702)
- `user_post_categories`: [Talentra.sql](https://github.com/ijc3093/Business3/blob/master/Data/Talentra.sql:2883)
- `public_posts`: [Talentra.sql](https://github.com/ijc3093/Business3/blob/master/Data/Talentra.sql:2200)
- `public_post_attachments`: [Talentra.sql](https://github.com/ijc3093/Business3/blob/master/Data/Talentra.sql:2258)
- `public_post_comments`: [Talentra.sql](https://github.com/ijc3093/Business3/blob/master/Data/Talentra.sql:2315)
- `public_post_reactions`: [Talentra.sql](https://github.com/ijc3093/Business3/blob/master/Data/Talentra.sql:2340)
- `public_post_reads`: [Talentra.sql](https://github.com/ijc3093/Business3/blob/master/Data/Talentra.sql:2369)
- `public_post_saves`: [Talentra.sql](https://github.com/ijc3093/Business3/blob/master/Data/Talentra.sql:2418)
- `public_post_shares`: [Talentra.sql](https://github.com/ijc3093/Business3/blob/master/Data/Talentra.sql:2444)
- `public_post_tags`: [Talentra.sql](https://github.com/ijc3093/Business3/blob/master/Data/Talentra.sql:2470)
- `public_post_views`: [Talentra.sql](https://github.com/ijc3093/Business3/blob/master/Data/Talentra.sql:2483)
- `public_post_view_daily`: [Talentra.sql](https://github.com/ijc3093/Business3/blob/master/Data/Talentra.sql:2522)
- `public_profile_access`: [Talentra.sql](https://github.com/ijc3093/Business3/blob/master/Data/Talentra.sql:2550)
- `public_saved_posts`: [Talentra.sql](https://github.com/ijc3093/Business3/blob/master/Data/Talentra.sql:2566)
- `public_comment_likes`: [Talentra.sql](https://github.com/ijc3093/Business3/blob/master/Data/Talentra.sql:2168)
- `public_follows`: [Talentra.sql](https://github.com/ijc3093/Business3/blob/master/Data/Talentra.sql:2187)
- `password_reset_tokens`: [Talentra.sql](https://github.com/ijc3093/Business3/blob/master/Data/Talentra.sql:2096)
- `org_posts`: [Talentra.sql](https://github.com/ijc3093/Business3/blob/master/Data/Talentra.sql:1890)
- `user_video_lives`: [Talentra.sql](https://github.com/ijc3093/Business3/blob/master/Data/Talentra.sql:3228)
- `user_video_live_comments`: [Talentra.sql](https://github.com/ijc3093/Business3/blob/master/Data/Talentra.sql:3302)
- `user_video_live_comment_likes`: [Talentra.sql](https://github.com/ijc3093/Business3/blob/master/Data/Talentra.sql:3376)
- `user_video_live_guest_requests`: [Talentra.sql](https://github.com/ijc3093/Business3/blob/master/Data/Talentra.sql:3389)
- `user_video_live_join_requests`: [Talentra.sql](https://github.com/ijc3093/Business3/blob/master/Data/Talentra.sql:3404)
- `user_video_live_reactions`: [Talentra.sql](https://github.com/ijc3093/Business3/blob/master/Data/Talentra.sql:3422)
- `user_video_live_usage`: [Talentra.sql](https://github.com/ijc3093/Business3/blob/master/Data/Talentra.sql:3524)
- `user_video_live_viewers`: [Talentra.sql](https://github.com/ijc3093/Business3/blob/master/Data/Talentra.sql:3547)

## 5. Ownership model and root tables

### `users`

Role:

- Root identity table for public-user accounts.
- Parent table for most public and live relationships.

Important keys:

- Primary key: `id`
- Unique: `email`
- Unique: `friend_code`
- Unique: `username`

What changed recently:

- The recent update did not change `users` itself.
- It made `users.id` the enforced parent for many tables that previously only trusted PHP logic.

### `user_post_categories`

Role:

- Per-user folder/category table for public posts.
- Used to group posts by owner-specific categories.

Important keys:

- Primary key: `id`
- Unique: `(user_id, slug)`
- New foreign key: `user_id -> users.id`

What changed recently:

- `user_id` was normalized from `int unsigned` to `int` so it matches `users.id`.
- Orphan categories were deleted before the foreign key was added.
- `public_posts` now has a trigger that enforces category ownership, so a post can only point to a category owned by the same user.

Important note:

- `category_type` still behaves like an enum in practice, but it is still stored as `varchar(24)`, not a true SQL enum.

## 6. Public content model

### `public_posts`

Role:

- Canonical parent row for each public/friends post.
- This is the main post header record for feed, profile, and public-page rendering.

Important columns:

- `user_id`: owner
- `visibility`: `public` or `friends`
- `is_deleted`: soft-delete flag
- `views_count`: cached display counter
- `category_id`: optional owner category
- `activity_at`: new generated column used for feed sorting

Important keys:

- Primary key: `id`
- New foreign key: `user_id -> users.id`
- New foreign key: `category_id -> user_post_categories.id`
- New index: `(is_deleted, visibility, activity_at, id)`
- New index: `(user_id, is_deleted, activity_at, id)`

What changed recently:

- `user_id` was normalized to `int`.
- `activity_at` was added as a stored generated column using `COALESCE(updated_at, created_at)`.
- Missing categories were cleaned by setting bad `category_id` values to `NULL`.
- `views_count` was rebuilt from `public_post_views`.
- Two feed-oriented indexes were added.
- New triggers enforce that `category_id`, if present, belongs to the same owner as the post.

Why this matters:

- Feed queries can sort by one stable field instead of branching between `updated_at` and `created_at`.
- Broken category assignments are now blocked at write time.

### `public_post_attachments`

Role:

- Child table for media/files attached to a public post.

Important columns:

- `type`: `image`, `video`, `pdf`, `file`
- `file_path`
- `thumb_path`

Important keys:

- Primary key: `id`
- Existing foreign key: `post_id -> public_posts.id`

What changed recently:

- No new change was required here because orphan protection already existed through the old `fk_pub_attach_post` foreign key.

### `public_post_comments`

Role:

- Comment and reply table for public posts.
- `parent_id` is used for replies.

Important keys:

- Primary key: `id`
- Existing foreign key: `post_id -> public_posts.id`
- New foreign key: `user_id -> users.id`
- New foreign key: `parent_id -> public_post_comments.id`
- New index: `(post_id, is_deleted, created_at, id)`

What changed recently:

- `user_id` was normalized to `int`.
- Orphan comments were deleted.
- Invalid `parent_id` values were repaired to `NULL` if the parent was missing, self-referential, or on another post.
- New triggers now block:
  - blank comments
  - self-parent replies
  - replies to comments that belong to another post

Why this matters:

- Reply trees now stay valid.
- Comment polling and post-detail queries can use the new composite index.

### `public_comment_likes`

Role:

- Many-to-many table for likes on public comments.

Important keys:

- Primary key: `(comment_id, user_id)`
- Existing foreign key: `comment_id -> public_post_comments.id`
- New foreign key: `user_id -> users.id`

What changed recently:

- `user_id` was normalized to `int`.
- Invalid likes were deleted before the new user foreign key was added.

Why this matters:

- A like can no longer point to a missing user.
- The composite primary key still prevents duplicate likes by the same user on the same comment.

### `public_post_reactions`

Role:

- One reaction per user per post.

Important columns:

- `reaction`: `like` or `love`

Important keys:

- Primary key: `(post_id, user_id)`
- Existing foreign key: `post_id -> public_posts.id`
- New foreign key: `user_id -> users.id`
- New index: `(post_id, reaction)`

What changed recently:

- `user_id` was normalized to `int`.
- Invalid reactions were deleted.
- The new user foreign key was added.
- The new reaction index helps post-level aggregation and filtering.

### `public_post_reads`

Role:

- Stores the latest time a user saw a post.
- This is not the same as the view-event table.

Important keys:

- Primary key: `(post_id, user_id)`
- Existing foreign key: `post_id -> public_posts.id`
- New foreign key: `user_id -> users.id`
- New index: `(user_id, post_id)`

What changed recently:

- `user_id` was normalized to `int`.
- Invalid read rows were deleted.
- The new user foreign key and lookup index were added.

Important note:

- This table is best understood as a state table, not an event table.
- One row means "this user last saw this post at this time."

### `public_post_saves`

Role:

- Many-to-many save/bookmark table keyed by `(post_id, user_id)`.

Important keys:

- Primary key: `(post_id, user_id)`
- New foreign key: `post_id -> public_posts.id`
- New foreign key: `user_id -> users.id`

What changed recently:

- `post_id` was normalized to `bigint unsigned`.
- `user_id` was normalized to `int`.
- Invalid rows were deleted before the foreign keys were added.

### `public_post_shares`

Role:

- Tracks shares of a public post.

Important keys:

- Primary key: `id`
- Unique: `(post_id, user_id)`
- New foreign key: `post_id -> public_posts.id`
- New foreign key: `user_id -> users.id`

What changed recently:

- `post_id` was normalized to `bigint unsigned`.
- `user_id` was normalized to `int`.
- Invalid rows were deleted before the new foreign keys were added.

### `public_post_tags`

Role:

- Tagged users on a public post.

Important keys:

- Primary key: `id`
- New foreign key: `post_id -> public_posts.id`
- New foreign key: `tagged_user_id -> users.id`

What changed recently:

- `post_id` was normalized to `bigint unsigned`.
- Invalid tag rows were deleted before the foreign keys were added.

### `public_post_views`

Role:

- Source-of-truth event table for unique public post views.

Important keys:

- Primary key: `id`
- Unique: `(post_id, user_id)`
- New foreign key: `post_id -> public_posts.id`
- New foreign key: `user_id -> users.id`
- New index: `(post_id, viewed_at, user_id)`

What changed recently:

- `post_id` was normalized to `bigint unsigned`.
- Invalid view rows were deleted.
- New foreign keys were added.
- New triggers were added to maintain `public_post_view_daily` on insert and delete.

Why this matters:

- Duplicate per-user views are still blocked by the existing unique key.
- The view table now also safely drives analytics rollups.

### `public_post_view_daily`

Role:

- Derived daily rollup table for public post views.

Important keys:

- Primary key: `(post_id, view_date)`
- New foreign key: `post_id -> public_posts.id`

What changed recently:

- `post_id` was normalized to `bigint unsigned`.
- The table was fully rebuilt from `public_post_views`.
- New triggers on `public_post_views` now keep it synchronized going forward.

Important note:

- This is not the source-of-truth table.
- It is a reporting cache derived from `public_post_views`.

## 7. Privacy and access model

### `public_follows`

Role:

- Follow graph for public profiles.

Important keys:

- Primary key: `id`
- Unique: `(follower_id, following_id)`
- New foreign key: `follower_id -> users.id`
- New foreign key: `following_id -> users.id`

What changed recently:

- Invalid follow rows were deleted.
- Self-follow rows were removed.
- New triggers now block self-follows on insert and update.

Why this matters:

- Duplicate follows were already blocked by the old unique key.
- The new trigger closes the remaining logic gap: a user cannot follow themselves.

### `public_profile_access`

Role:

- Access request and approval table for profile visibility workflows.

Important columns:

- `status`: `pending`, `approved`, `denied`
- `responded_at`: tracks when an access request was resolved

Important keys:

- Primary key: `id`
- Unique: `(owner_id, viewer_id)`
- New foreign key: `owner_id -> users.id`
- New foreign key: `viewer_id -> users.id`

What changed recently:

- `owner_id` and `viewer_id` were normalized to `int`.
- Invalid and self-access rows were deleted.
- Triggers now:
  - block owner-to-self access records
  - clear `responded_at` when status is `pending`
  - auto-set `responded_at` when status becomes `approved` or `denied`

Why this matters:

- Request state is now internally consistent.

### `public_saved_posts`

Role:

- Another save/bookmark table with its own surrogate `id`.

Important keys:

- Primary key: `id`
- Unique: `(user_id, post_id)`
- New foreign key: `user_id -> users.id`
- New foreign key: `post_id -> public_posts.id`

What changed recently:

- `user_id` was normalized to `int`.
- `post_id` was normalized to `bigint unsigned`.
- Invalid rows were deleted before the foreign keys were added.

Important debt note:

- `public_post_saves` and `public_saved_posts` overlap in responsibility.
- The recent update made both safer, but it did not consolidate them.
- This remains schema debt and should eventually be reduced to one canonical save table.

## 8. Live-session model

### `user_video_lives`

Role:

- Canonical parent row for each live session.

Important columns:

- `user_id`: live owner
- `friend_code`: owner-facing public code
- `status`: `draft`, `scheduled`, `live`, `ended`, `cancelled`
- `visibility`: `public`, `friends`, `private`
- `viewer_count`: cached active-viewer counter
- `share_count`
- `peak_viewers`: cached max concurrent viewers

Important keys:

- Primary key: `id`
- Unique: `stream_key`
- New foreign key: `user_id -> users.id`

What changed recently:

- Invalid live rows with missing users were deleted.
- `share_count` was changed to `int unsigned`.
- `viewer_count` and `peak_viewers` were rebuilt from `user_video_live_viewers`.
- New triggers now enforce:
  - `user_id` must exist
  - `friend_code` must match the owner user
  - `ended_at` cannot be earlier than `started_at`
  - `peak_viewers` cannot be lower than `viewer_count`

Important note:

- `viewer_count` and `peak_viewers` are caches, not source-of-truth event data.

### `user_video_live_comments`

Role:

- Comment stream for live sessions.

Important keys:

- Primary key: `id`
- Existing foreign key: `live_id -> user_video_lives.id`
- New foreign key: `user_id -> users.id`

What changed recently:

- Invalid rows were deleted if either the live session or the user was missing.
- The new user foreign key was added.

### `user_video_live_comment_likes`

Role:

- Likes on live comments.

Important keys:

- Primary key: `id`
- Unique: `(comment_id, user_id)`
- New foreign key: `comment_id -> user_video_live_comments.id`
- New foreign key: `user_id -> users.id`

What changed recently:

- New foreign keys were added so likes cannot point at missing comments or missing users.

### `user_video_live_guest_requests`

Role:

- Tracks guest requests to join a live session.

Important keys:

- Primary key: `id`
- Unique: `(live_id, requester_user_id)`
- New foreign key: `live_id -> user_video_lives.id`
- New foreign key: `requester_user_id -> users.id`

What changed recently:

- Invalid guest requests were deleted.
- New foreign keys were added.

Important note:

- `status` is still `varchar(20)`, not a true enum.
- That means allowed statuses still depend on application code.

### `user_video_live_join_requests`

Role:

- Tracks host/viewer join state for live co-presence or call-backed joins.

Important keys:

- Primary key: `id`
- Unique: `(live_id, viewer_user_id)`
- New foreign key: `live_id -> user_video_lives.id`
- New foreign key: `call_id -> user_video_calls.id`
- New foreign key: `host_user_id -> users.id`
- New foreign key: `viewer_user_id -> users.id`

What changed recently:

- Invalid rows were deleted if the host, viewer, live, or optional call reference was broken.
- New foreign keys were added.

Important note:

- `status` is still `varchar(20)`, not a true enum.

### `user_video_live_reactions`

Role:

- Reaction stream for live sessions.

Important columns:

- `reaction`: `love`, `like`, `fire`, `wow`, `clap`

Important keys:

- Primary key: `id`
- Unique: `(live_id, user_id)`
- Existing foreign key: `live_id -> user_video_lives.id`
- New foreign key: `user_id -> users.id`

What changed recently:

- Invalid rows were deleted if the live or user was missing.
- The new user foreign key was added.

### `user_video_live_viewers`

Role:

- Source-of-truth table for active viewers inside a live session.

Important keys:

- Primary key: `id`
- Unique: `(live_id, viewer_user_id)`
- New foreign key: `live_id -> user_video_lives.id`
- New foreign key: `viewer_user_id -> users.id`

What changed recently:

- Invalid rows were deleted.
- New foreign keys were added.
- New triggers now recalculate `user_video_lives.viewer_count` after inserts and deletes.

Why this matters:

- This table is now the authoritative viewer-presence table.
- The parent `user_video_lives` counters are maintained from it.

### `user_video_live_usage`

Role:

- Per-user aggregate table for total live sessions.

Important keys:

- Primary key: `user_id`
- New foreign key: `user_id -> users.id`

What changed recently:

- Invalid rows were deleted.
- New foreign key was added.

Important note:

- This is an aggregate state table, not an event log.

## 9. Security and recovery-related tables

### `password_reset_tokens`

Role:

- Reset-token table for both normal users and admins.

Important columns:

- `account_type`: `user` or `admin`
- `user_id`
- `admin_id`
- `token_hash`
- `expires_at`
- `used_at`

Important keys:

- Primary key: `id`
- New unique key: `token_hash`
- New index: `(account_type, username, used_at, expires_at)`
- New foreign key: `user_id -> users.id`
- New foreign key: `admin_id -> admin.idadmin`

What changed recently:

- The old non-unique `idx_token_hash` index was replaced with a unique key on `token_hash`.
- A lookup index was added for active-token checks.
- Triggers now enforce:
  - `account_type = 'user'` means `user_id` must be set and `admin_id` must be `NULL`
  - `account_type = 'admin'` means `admin_id` must be set and `user_id` must be `NULL`
  - `expires_at` must be in the future on insert
  - `used_at` cannot be earlier than `created_at`

Why this matters:

- Token rows now have a strict identity contract.
- Duplicate token hashes are blocked.

## 10. Organization table touched by the update

### `org_posts`

Role:

- Organization feed post table used by the organization area.

Important keys:

- Primary key: `id`
- New index: `(org_id, is_deleted, is_visible, post_state, created_at, id)`

What changed recently:

- No new relationship was added here.
- Only a feed-oriented composite index was added.

Why this matters:

- Organization feed loading can filter by state/visibility without scanning as much.

## 11. Data contracts introduced by the update

The recent SQL block made the following contracts explicit:

| Concern | Source-of-truth table | Cached or derived table |
| --- | --- | --- |
| Public unique views | `public_post_views` | `public_posts.views_count`, `public_post_view_daily` |
| Daily public view analytics | `public_post_views` | `public_post_view_daily` |
| Active live viewers | `user_video_live_viewers` | `user_video_lives.viewer_count`, `user_video_lives.peak_viewers` |
| Post categories | `user_post_categories` | `public_posts.category_id` |
| Public reads | `public_post_reads` | none |
| Password reset state | `password_reset_tokens` | none |

Practical meaning:

- Event tables should be trusted first.
- Cached counters should be rebuilt from event tables if data ever drifts.
- In the current SQL, `public_post_view_daily`, `viewer_count`, and `peak_viewers` have database maintenance rules.
- `public_posts.views_count` was rebuilt by the hardening block, but it is still a cache that is not maintained by its own trigger yet.

## 12. Existing keys that already prevented duplicates

The update did not invent all integrity protections from scratch. Some duplicate-prevention rules were already present:

- `public_follows`: unique `(follower_id, following_id)`
- `public_post_views`: unique `(post_id, user_id)`
- `public_post_reactions`: primary key `(post_id, user_id)`
- `public_post_reads`: primary key `(post_id, user_id)`
- `public_post_saves`: primary key `(post_id, user_id)`
- `public_post_shares`: unique `(post_id, user_id)`
- `public_profile_access`: unique `(owner_id, viewer_id)`
- `public_saved_posts`: unique `(user_id, post_id)`
- `user_video_live_comment_likes`: unique `(comment_id, user_id)`
- `user_video_live_guest_requests`: unique `(live_id, requester_user_id)`
- `user_video_live_join_requests`: unique `(live_id, viewer_user_id)`
- `user_video_live_reactions`: unique `(live_id, user_id)`
- `user_video_live_viewers`: unique `(live_id, viewer_user_id)`

What the recent update added on top:

- real foreign keys
- ownership checks
- self-link protection
- rollup maintenance
- type alignment

## 13. New indexes and what pages they help

### Public feed and profile queries

- `idx_public_posts_visibility_activity`
  - Supports feed/public page queries that filter active posts by `visibility` and sort by newest activity.
- `idx_public_posts_user_activity`
  - Supports loading one user's post list or gallery in activity order.

### Comments and interactions

- `idx_public_post_comments_post_deleted_created`
  - Supports post-detail comment loading and comment polling.
- `idx_public_post_reactions_post_reaction`
  - Helps reaction counts or reaction filters per post.
- `idx_public_post_reads_user_post`
  - Helps upserts/lookups for "last seen" checks.

### Views and reporting

- `idx_public_post_views_post_viewed`
  - Helps timeline queries and reporting by post and date.
- `idx_org_posts_feed_state`
  - Helps organization feed filtering and sorting.
- `idx_password_reset_lookup_active`
  - Helps active-token lookup by account and username.

## 14. Enums and status values

### True SQL enums

- `public_posts.visibility`: `public`, `friends`
- `public_post_attachments.type`: `image`, `video`, `pdf`, `file`
- `public_post_reactions.reaction`: `like`, `love`
- `public_profile_access.status`: `pending`, `approved`, `denied`
- `password_reset_tokens.account_type`: `user`, `admin`
- `user_video_lives.status`: `draft`, `scheduled`, `live`, `ended`, `cancelled`
- `user_video_lives.visibility`: `public`, `friends`, `private`
- `user_video_live_reactions.reaction`: `love`, `like`, `fire`, `wow`, `clap`
- `org_posts.author_role`: `admin`, `manager`
- `org_posts.post_type`: `announcement`, `direction`, `update`, `recognition`
- `org_posts.visibility`: `organization`, `team`

### Soft enums still stored as `varchar`

- `user_post_categories.category_type`
  - Acts like: `video`, `photo`, `topic`, `mixed`, `file`
- `user_video_live_guest_requests.status`
- `user_video_live_join_requests.status`
- `org_posts.post_state`

Important note:

- The recent update did not convert these `varchar` status columns into SQL enums.
- They still rely on application discipline.

## 15. Step-by-step runtime flows

### Flow A: Create a public post

1. App inserts one row into `public_posts`.
2. `user_id` must exist in `users`.
3. If `category_id` is present, the trigger verifies the category exists and belongs to the same user.
4. App inserts child rows into `public_post_attachments`, `public_post_tags`, and later comments/reactions/views as needed.
5. Feed and profile pages load the post through `public_posts`, using `activity_at` for ordering.

### Flow B: Load the public feed

1. Query `public_posts` for rows where `is_deleted = 0`.
2. Filter by `visibility`.
3. Sort by `activity_at DESC, id DESC`.
4. Join child tables only when needed for counts/details.
5. Use `public_post_reads` to mark or check last-seen state per viewer.

### Flow C: Add a comment or reply

1. App inserts into `public_post_comments`.
2. `post_id` must exist.
3. `user_id` must exist.
4. If `parent_id` is set, the parent must exist and must belong to the same post.
5. Blank comments are rejected by trigger.

### Flow D: React, save, or share a post

1. Reactions go to `public_post_reactions`.
2. Saves go to `public_post_saves` and, depending on app usage, maybe also `public_saved_posts`.
3. Shares go to `public_post_shares`.
4. Unique or primary keys stop duplicates for the same user/post pair.
5. Foreign keys now guarantee the user and post exist.

### Flow E: Record a unique public view

1. App inserts into `public_post_views`.
2. The unique key on `(post_id, user_id)` blocks duplicate unique views for the same pair.
3. The insert trigger increments or creates the daily rollup row in `public_post_view_daily`.
4. `public_posts.views_count` is the cached display value and can be rebuilt from `public_post_views`.

### Flow F: Follow another user

1. App inserts into `public_follows`.
2. The unique key prevents duplicate follow edges.
3. The trigger rejects self-follows.
4. Foreign keys reject missing users.

### Flow G: Request profile access

1. App inserts into `public_profile_access`.
2. The unique key allows only one request row per owner/viewer pair.
3. The trigger blocks owner-to-self access.
4. When status is `pending`, `responded_at` stays `NULL`.
5. When status becomes `approved` or `denied`, `responded_at` is set automatically if missing.

### Flow H: Create and use a password reset token

1. App inserts into `password_reset_tokens`.
2. The trigger checks that the row is either a user token or an admin token, never both.
3. `token_hash` must be unique.
4. `expires_at` must be in the future.
5. When the token is used, `used_at` cannot be set earlier than the row creation time.

### Flow I: Start a live session

1. App inserts into `user_video_lives`.
2. `user_id` must exist.
3. `friend_code` must match that user, or the trigger fills it automatically if blank.
4. Time order must stay valid: `ended_at >= started_at` when both exist.
5. `peak_viewers` is never allowed to be less than `viewer_count`.

### Flow J: Viewer joins or leaves a live session

1. Viewer presence is inserted into `user_video_live_viewers`.
2. Unique `(live_id, viewer_user_id)` prevents duplicate active presence rows.
3. Insert/delete triggers recalculate `viewer_count`.
4. `peak_viewers` is updated upward when concurrency grows.

### Flow K: Live comments and reactions

1. Comments go into `user_video_live_comments`.
2. Comment likes go into `user_video_live_comment_likes`.
3. Reactions go into `user_video_live_reactions`.
4. Foreign keys now guarantee the user and live session exist.

## 16. Cleanup that happened before constraints were added

The update intentionally cleaned bad data first so the new constraints could be applied without import failure.

Rows deleted if parent records were missing:

- orphan category rows
- orphan public saves
- orphan public shares
- orphan comment likes
- orphan public comments
- orphan public reactions
- orphan public reads
- orphan public tags
- orphan public views
- orphan profile-access rows
- orphan follow rows
- orphan saved-post rows
- orphan live rows
- orphan live comments
- orphan live reactions
- orphan live viewers
- orphan live guest requests
- orphan live join requests
- orphan live usage rows

Rows repaired instead of deleted:

- `public_post_comments.parent_id` was set to `NULL` if the parent was invalid
- `public_posts.category_id` was set to `NULL` if the category was invalid

Counters rebuilt:

- `public_posts.views_count`
- `public_post_view_daily`
- `user_video_lives.viewer_count`
- `user_video_lives.peak_viewers`

## 17. What this update improved

The recent SQL work improved the project in these ways:

1. Fewer broken relationships because foreign keys now back up app code.
2. Better ownership rules because categories, follows, profile access, and live ownership are validated in the database.
3. Safer counters because views and live viewers now have explicit source tables and rebuild logic.
4. Better feed and polling performance because the main public and org queries have better indexes.
5. Better reset-token safety because identity mapping and token uniqueness are enforced.

## 18. What still remains as technical debt

These issues were not fully solved by the recent update:

1. `public_post_saves` and `public_saved_posts` are still overlapping save systems.
2. Some status fields are still `varchar` instead of enum-backed state machines.
3. Mixed `int` and `bigint` conventions still exist in the wider schema outside the updated surfaces.
4. `public_posts.views_count` is still an app-managed cache after the initial rebuild, not a fully trigger-maintained counter.
5. `user_video_lives.share_count` was type-hardened, but it is still app-managed.
6. The recent work was verified at dump level, but not yet with a full MySQL import and application smoke test.

## 19. Best mental model for this project

If you want one short mental model, use this:

1. `users` is the root owner.
2. `public_posts` is the root public-content record.
3. `user_video_lives` is the root live-session record.
4. Event/state child tables hang under those parents.
5. Counter tables and cached columns must be treated as derived values, not the final truth.

That mental model matches the recent SQL changes and is the safest way to extend the schema in the future.
