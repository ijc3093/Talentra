# QA Tester Guide for public_user

Date: 2026-04-24

## Purpose

This document explains the recent QA and testing work completed for `public_user`.

It is written so you can clearly understand:

1. what was tested
2. how the QA pass was executed
3. which pages and endpoints were covered
4. which bugs were found
5. which areas behaved correctly
6. what temporary QA data was created and cleaned up
7. how future bug-fix verification should be done

This is the QA handoff for the recent live test pass on `public_user`.

## 1. Summary of the recent QA pass

The recent QA pass was a live application test, not only a code review.

I tested the running `public_user` app against the local environment:

- local site: `http://127.0.0.1:8888/Talentra/public_user/`
- local database: MySQL on port `8889`

The QA work included:

- protected page access testing
- login and session testing
- feed and public-post testing
- live-page testing
- messages and contacts testing
- friend-request workflow testing
- permission and visibility testing
- regression checks around related AJAX endpoints
- documenting exact reproduction steps for issues

I also cleaned up the temporary QA data created during testing after the pass was complete.

## 2. Main goal of the QA work

The goal was to test the main user flows and confirm whether the application correctly handles:

- normal navigation
- protected routes
- friend-only vs public visibility
- owner vs non-owner behavior
- logged-in vs logged-out behavior
- page loading and redirects
- chat and contact flows
- live-room permissions
- counts and state consistency

## 3. How the QA pass was performed

### Step 1. Mapped the app structure

I first reviewed the project structure to confirm the major user surfaces and supporting files.

Main page files identified:

- `feed.php`
- `public.php`
- `public_live.php`
- `live_watch.php`
- `dashboard.php`
- `profile.php`
- `messages.php`
- `chat.php`
- `contacts.php`
- `contact_requests.php`
- `add_contact.php`
- `compose.php`

Main supporting areas identified:

- `includes/` for auth, identity, shared header, friend helpers
- `ajax/` for chat, notifications, live-room actions, friend actions
- `controller.php` for database access and shared login logic
- `../config.php` for local database credentials

### Step 2. Confirmed the local runtime

I verified that the app was actually running locally and reachable over HTTP.

I confirmed:

- the app responded with `200 OK`
- session cookies were being issued
- the database was reachable on the local MAMP/MySQL port

This mattered because QA needed to be performed on the real running app, not only by reading PHP files.

### Step 3. Inspected the real database schema

I checked the actual schema used by the app so the QA work would match the real implementation.

Important finding:

- the app does not use generic tables like `friends` or `notifications`
- it uses real feature tables such as `contact_requests`, `user_contacts`, `public_posts`, `public_post_*`, `user_video_lives`, and `notification`

This mattered because correct QA depends on testing the real data model.

### Step 4. Identified useful seeded accounts

I inspected existing users and data distribution to pick accounts that would provide realistic coverage.

The seeded data was concentrated mostly on user `1`, which had:

- most public posts
- most contacts
- most ended live sessions

For testing, I used a small matrix of local users:

- user `1` as the main content owner
- user `2` as an existing friend of user `1`
- user `24` as a clean non-friend edge-case account

This allowed friend vs non-friend testing.

### Step 5. Established temporary QA login access

To run the live flows end to end, I temporarily set QA passwords on a few local users so I could sign in through the real login form.

This was only for local QA execution.

After the QA pass:

- the original password hashes were restored

### Step 6. Tested logged-out protection

I tested major protected pages while logged out.

Checked routes:

- `feed.php`
- `public.php`
- `public_live.php`
- `live_watch.php`
- `dashboard.php`
- `profile.php`
- `messages.php`
- `chat.php`
- `contacts.php`
- `contact_requests.php`

Result:

- these routes correctly redirected to `index.php?session=reset` when not logged in

### Step 7. Tested logged-in page loads

I then logged in and loaded the main application pages as a real user.

Checked:

- `feed.php`
- `public.php`
- `public_live.php`
- `live_watch.php`
- `dashboard.php`
- `profile.php`
- `messages.php`
- `chat.php`
- `contacts.php`
- `contact_requests.php`

Result:

- the main pages loaded successfully
- `chat.php` without a conversation id returned a plain text response instead of redirecting to a better UX location

This did not become a top severity issue, but it is still a rough route behavior.

### Step 8. Tested contacts and friend-request flows

Because the seeded data had no useful pending request coverage, I created a real request through the app itself.

Flow tested:

1. logged in as non-friend user `24`
2. opened `add_contact.php`
3. sent a friend request to user `1`
4. verified the request appeared on `contact_requests.php`
5. accepted the request from user `1`
6. confirmed the request status changed to `accepted`
7. confirmed contact rows were created on both sides

This validated:

- request creation
- request visibility
- acceptance flow
- contacts population

After the QA pass:

- the temporary request and temporary contact rows were removed

### Step 9. Tested messaging flows

I reviewed and exercised the messages stack through:

- `compose.php`
- `user_sendreply.php`
- `messages.php`
- `ajax/user_chat_send.php`
- `ajax/user_chat_poll.php`

Important observation:

- the modern direct-message UI uses the `feedback` table
- `chat.php` is an older conversation-based route and had no seeded conversation data

So the real user-facing messaging flow was validated through `messages.php` and related AJAX.

### Step 10. Tested permission and visibility behavior

This was the most important part of the QA pass.

I checked:

- friend vs non-friend visibility
- profile privacy behavior
- message permission behavior
- gallery visibility behavior
- live-room access behavior

This uncovered the most important bugs in the pass.

### Step 11. Tested live-room access

I inspected live data and tested:

- `public_live.php`
- `live_watch.php`
- `ajax/live_watch_room.php`
- `ajax/live_snapshot.php`

I specifically checked friend-only live visibility.

Verified:

- after cleanup, a non-friend user was blocked from viewing a friends-only live room
- non-friend access to the tested friends-only live room correctly showed an access-denied message

This was one of the areas that behaved correctly.

### Step 12. Cleaned up temporary QA data

At the end of the QA pass I removed the temporary local QA data created for the test.

Cleanup completed:

- restored original password hashes for the temporary QA login accounts
- deleted the temporary friend request
- deleted the temporary contact rows created from that request
- deleted the temporary non-friend test message
- deleted the temporary settings row created during a Gear/settings test

This means the current local DB should not be left in a polluted QA state from this pass.

## 4. Pages and flows covered

### Main pages tested

- `index.php`
- `feed.php`
- `public.php`
- `public_live.php`
- `live_watch.php`
- `dashboard.php`
- `profile.php`
- `messages.php`
- `chat.php`
- `contacts.php`
- `contact_requests.php`
- `add_contact.php`
- `compose.php`

### Main AJAX and action endpoints checked

- `ajax/friend_action.php`
- `ajax/user_chat_send.php`
- `ajax/user_chat_poll.php`
- `ajax/live_watch_room.php`
- `ajax/live_snapshot.php`
- `save_privacy.php`

## 5. What passed during QA

The following areas behaved correctly during this pass:

- logged-out protection redirected protected routes correctly
- login through `index.php` worked with temporary QA credentials
- the main pages loaded without fatal PHP output in the tested flows
- friend-request creation and acceptance worked when executed through the app
- contacts updated correctly after accepting a request
- friends-only live-room access was denied for a non-friend after the temporary friendship was removed
- `public.php` did not expose the same friends-only posts that leaked through the profile gallery

## 6. Main bugs found

These were the main issues found during the recent QA pass.

### Bug 1. Non-friends can send direct messages to users whose messages are set to friends-only

Severity:

- High

What was tested:

- non-friend messaging permissions

How it was tested:

1. logged in as user `24` who was not a friend of user `2`
2. opened the compose flow
3. submitted user `2` friend code `USR-2JS7-VP8L`
4. verified redirect into the message thread
5. sent a test message through `ajax/user_chat_send.php`

What happened:

- the app allowed the thread to open
- the app accepted and stored the direct message

What should have happened:

- the app should have blocked the message because the target user had `message_permission = friends`

Why this is happening:

- `compose.php` validates friend code and same role, but not friend relationship or profile message permission
- `ajax/user_chat_send.php` also validates receiver existence, but not whether the sender is allowed to message the target

Relevant files:

- `compose.php`
- `ajax/user_chat_send.php`

### Bug 2. Profile gallery leaks friends-only posts to non-friends

Severity:

- High

What was tested:

- profile gallery visibility for a non-friend

How it was tested:

1. logged in as non-friend user `24`
2. opened `profile.php?id=1&tab=gallery`
3. checked for known friends-only posts owned by user `1`

What happened:

- the page rendered friends-only posts for a non-friend
- examples included:
  - post `163`
  - post `127` with title `Just test`

What should have happened:

- non-friends should only see public gallery items

Why this is happening:

- the profile gallery query loads all posts for the viewed user without applying visibility filtering for the viewer

Relevant file:

- `profile.php`

### Bug 3. Profile post count leaks hidden content totals

Severity:

- High

What was tested:

- post count shown on another user's profile

How it was tested:

1. logged in as non-friend user `24`
2. opened `profile.php?id=1&tab=gallery`
3. compared the displayed post total with the target user's real public vs friends-only post distribution

What happened:

- the page showed `24 posts`
- this total included hidden friends-only content

What should have happened:

- the displayed post count should reflect only the posts visible to the current viewer

Why this is happening:

- the profile post counter counts all non-deleted posts for the owner, not only viewer-accessible posts

Relevant file:

- `profile.php`

### Bug 4. Non-owners can open another user's Gear/settings panel

Severity:

- Medium

What was tested:

- owner vs non-owner access on the profile Gear tab

How it was tested:

1. logged in as non-owner user `24`
2. opened `profile.php?id=1&tab=gear`
3. inspected the rendered controls

What happened:

- the full Gear control center was rendered
- privacy, messaging, security, and account-tool controls were visible
- save-capable controls were present in the DOM

What should have happened:

- Gear should be owner-only
- or it should render a read-only / blocked state for non-owners

Why this is happening:

- the Gear tab is only hidden in one special live-visitor mode
- it is not generally protected for non-owner profile views

Relevant file:

- `profile.php`

### Bug 5. Gear/settings UX is misleading because it reads the viewed user but saves the logged-in user

Severity:

- Medium

What was tested:

- save behavior from the Gear/settings surface

How it was tested:

1. opened another user's Gear tab while logged in
2. checked that controls were rendered from the viewed profile page
3. confirmed that `save_privacy.php` always writes using `$_SESSION['user_id']`

What happened:

- the page shows another user's profile context
- the save endpoint is bound to the logged-in user only

Why this is a problem:

- even if this does not overwrite the viewed user's settings, it is still a dangerous and confusing UX
- a non-owner appears to be editing someone else's settings page while actually affecting their own account state

Relevant files:

- `profile.php`
- `save_privacy.php`

## 7. Important technical observations from QA

These are important for future testing and debugging.

### Observation 1. The modern DM system is not the same as `chat.php`

`chat.php` uses the older `conversations` and `messages` tables.

But the active UI flow for private messaging uses:

- `messages.php`
- `feedback`
- `ajax/user_chat_send.php`
- `ajax/user_chat_poll.php`

So future QA on direct messages should focus on the `messages.php` flow unless the older route is being actively maintained.

### Observation 2. `public.php` and `profile.php` do not enforce visibility the same way

During QA:

- `public.php` behaved correctly for the tested friends-only content
- `profile.php` leaked friends-only posts in the gallery

This means visibility logic is inconsistent across surfaces and needs regression coverage after fixes.

### Observation 3. Live-room access looked better protected than profile-gallery access

The tested friends-only live-room access correctly blocked a non-friend after cleanup.

So the current permission problems are not uniform across every surface.

## 8. Exact QA workflow that should be repeated after fixes

When a developer fixes any of the bugs above, QA should re-run this order:

1. log in as owner, friend, and non-friend users
2. test `profile.php` gallery as friend and non-friend
3. confirm post counts match viewer permissions
4. test `compose.php` and `ajax/user_chat_send.php` as a non-friend
5. confirm DM creation is blocked when `message_permission = friends`
6. open another user's Gear tab as a non-owner
7. confirm the Gear tab is hidden, blocked, or read-only
8. retest `public.php` to make sure public content still works
9. retest `public_live.php` and `live_watch.php` to make sure live visibility still works
10. retest `contact_requests.php` and `contacts.php` so friend-based logic is not broken by the permission fix

## 9. Recommended regression areas after each fix

If the team fixes messaging permission logic, re-test:

- `compose.php`
- `user_sendreply.php`
- `messages.php`
- `ajax/user_chat_send.php`
- `ajax/user_chat_poll.php`
- profile message button behavior in `profile.php`

If the team fixes gallery/profile privacy, re-test:

- `profile.php`
- `public.php`
- `feed_api.php`
- profile post counters
- gallery search and category filtering

If the team fixes Gear access, re-test:

- `profile.php`
- `save_privacy.php`
- settings rendering for owner
- settings rendering for non-owner

## 10. What was not done in this pass

To keep the scope honest, these items were not part of the recent pass:

- browser-driven screenshot capture
- video capture
- mobile device emulator testing
- theme-specific visual comparison screenshots
- automated test-suite creation
- code changes to fix the bugs

This pass was focused on live QA execution, bug discovery, and clean documentation.

## 11. Final QA conclusion

The recent QA pass showed that the app is reachable, the main logged-in surfaces load, and several core flows are functional.

However, the most important issues found were permission and visibility bugs:

- DM permission is not enforced for non-friends
- profile gallery visibility is not enforced for non-friends
- profile post counts leak hidden content totals
- non-owners can open the Gear/settings surface of another user's profile

These should be treated as priority regression areas because they affect privacy, user trust, and expected social-access rules.
