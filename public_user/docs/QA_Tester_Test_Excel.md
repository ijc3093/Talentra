# QA Tester Excel-Based Test Walkthrough for `public_user`

Date of CSV-based QA execution: `2026-04-24`  
Date this documentation was organized with screenshots: `2026-04-25`

## 1. Purpose

This document explains the recent Excel/CSV-style QA pass that was done for `public_user` using:

- User 1: `john_k / test123`
- User 2: `mike_i / test123`

The goal of this file is to make the QA work easy to read in plain language:

1. what was tested
2. how the QA sheet was organized
3. which pages were opened
4. which behaviors passed
5. which bugs failed
6. what the screenshots represent

This document is the readable walkthrough for the Excel/CSV test run.

## 2. Related Files

- Main CSV test sheet: [QA_Test_Cases_public_user.csv](https://github.com/ijc3093/Business3/blob/master/public_user/docs/QA_Test_Cases_public_user.csv)
- Earlier broader QA narrative: [QA_Tester.md](https://github.com/ijc3093/Business3/blob/master/public_user/docs/QA_Tester.md)
- Screenshot evidence folder: [https://github.com/ijc3093/Business3/blob/master/public_user/docs/screens](https://github.com/ijc3093/Business3/blob/master/public_user/docs/screens)

Important note:

- `QA_Tester.md` explains the earlier broader QA pass, including deeper permission checks with another non-friend test account.
- This file explains the newer Excel/CSV-style pass that was organized around John and Mike and recorded in `QA_Test_Cases_public_user.csv`.

## 3. Environment and Accounts Used

Application under test:

- `http://localhost:8888/Talentra/public_user`

Accounts used for this recent CSV-based test pass:

- `john_k / test123`
- `mike_i / test123`

Why these two accounts were used:

- John had strong seeded content and existing account data
- Mike already had a meaningful relationship with John for friend-based testing
- this allowed QA to check owner pages, friend pages, and interaction flows without creating a large amount of new test data

## 4. How the Excel / CSV QA Method Was Used

The QA work was tracked in one CSV sheet with one row per test case.

Main headers used in the sheet:

- `TC ID`
- `Module`
- `Page URL`
- `User Account`
- `Execution Type`
- `Test Title`
- `Preconditions`
- `Test Steps`
- `Test Data`
- `Expected Result`
- `Actual Result`
- `Status`
- `Priority`
- `Severity`
- `Defect ID`
- `Environment`
- `Test Date`
- `Evidence`
- `Retest Required`
- `Comments`

Why this format was used:

- it keeps each test case separate and easy to filter
- it records both expected and actual behavior
- it makes retesting easier after a developer says something is fixed
- it gives a clean place to attach bug ids and screenshot evidence

## 5. How the QA Tester Executed the Recent Pass

This is the exact testing approach that was followed.

### Step 1. Confirm the application is reachable

The local app URL was checked first to make sure the site was available before testing flows.

### Step 2. Verify login credentials

I verified that both provided credentials actually worked:

- John could log in
- Mike could log in

This was necessary before testing any protected page.

### Step 3. Check session and redirect behavior

I tested:

- valid login redirect
- logout behavior
- logged-out protection on `feed.php`

This confirmed that the base session flow worked correctly.

### Step 4. Open the main application pages

I then opened the major app surfaces one by one:

- `feed.php`
- `dashboard.php`
- `public.php`
- `profile.php`
- `contacts.php`
- `contact_requests.php`
- `add_contact.php`
- `messages.php`
- `support.php`
- `public_live.php`
- `live_watch.php`
- `timeline.php`
- `change-password.php`
- `manage_devices.php`
- `account_tools.php`
- `security_tools.php`
- `chat.php`
- `compose.php`

### Step 5. Compare expected behavior against actual behavior

For each page, I checked:

- whether the page loaded
- whether the key buttons or sections appeared
- whether the route redirected correctly
- whether the content matched the account being used
- whether ownership and friend behavior looked correct

### Step 6. Record the result in the CSV

For every tested function, I added:

- what I tested
- which user I used
- what should have happened
- what actually happened
- pass or fail
- any bug id if it failed

### Step 7. Mark the confirmed failures

In this recent CSV-based pass, two issues were confirmed and recorded:

- `BUG-001`: Mike could open John's `Gear` settings view
- `BUG-002`: `chat.php` with no conversation id returned raw text instead of a better fallback

### Step 8. Generate screenshot evidence for documentation

To make this document easier to understand, I generated screenshots from the same authenticated routes that were tested in the QA pass and stored them in `docs/screens/`.

That means:

- the screenshots are documentation evidence for the tested routes
- the CSV remains the master execution log
- this markdown file is the organized explanation layer

## 6. Evidence by Test Area

## 6.1 Authentication and Session

Covered CSV rows:

- `QA-001` valid login redirects to feed
- `QA-002` logout clears session and blocks feed
- `QA-003` protected feed redirects when session is missing

What I did:

- logged in as John
- logged out
- requested `feed.php` while logged out

What happened:

- login worked
- logout worked
- logged-out access correctly returned the user to the sign-in flow

Result:

- `Pass`

Screenshot evidence:

The login page below is the base UI used for the login and logout redirect checks.

![Login Page](https://github.com/ijc3093/Business3/blob/master/public_user/docs/screens/01_login_page.png)

## 6.2 Feed

Covered CSV row:

- `QA-004` main feed page loads

What I did:

- logged in as John
- opened `feed.php`
- checked for a successful page load and core feed layout

What happened:

- the route loaded successfully
- the feed shell rendered without fatal error output

Result:

- `Pass`

Screenshot evidence:

![Feed Page](https://github.com/ijc3093/Business3/blob/master/public_user/docs/screens/02_feed_john.png)

## 6.3 Dashboard

Covered CSV row:

- `QA-005` dashboard create-post UI renders

What I did:

- opened `dashboard.php` as John
- checked that the create-post area, category controls, and recent-post related UI rendered

What happened:

- the create-post dashboard loaded correctly

Result:

- `Pass`

Screenshot evidence:

![Dashboard Page](https://github.com/ijc3093/Business3/blob/master/public_user/docs/screens/03_dashboard_john.png)

## 6.4 Public Posts

Covered CSV row:

- `QA-006` public page hides John's friends-only posts

What I did:

- logged in as Mike
- opened `public.php`
- checked that known friend-only markers from John's content were not exposed there

What happened:

- no friend-only marker matched on the public page
- the route did not leak the known private gallery markers that were used for verification

Result:

- `Pass`

Screenshot evidence:

![Public Page](https://github.com/ijc3093/Business3/blob/master/public_user/docs/screens/04_public_mike.png)

## 6.5 Profile Testing

Covered CSV rows:

- `QA-007` owner profile shows owner actions
- `QA-008` friend can see John's friend-only profile content
- `QA-009` non-owner should not see another user's Gear controls

What I did:

- opened John's own profile as John
- opened John's gallery as Mike
- opened John's `Gear` tab as Mike

What happened:

- owner profile showed owner actions correctly
- friend gallery rendered correctly for Mike
- John's `Gear` settings were visible to Mike even though Mike was not the owner

Result:

- `QA-007`: `Pass`
- `QA-008`: `Pass`
- `QA-009`: `Fail` as `BUG-001`

Screenshot evidence:

Owner profile as John:

![Owner Profile](https://github.com/ijc3093/Business3/blob/master/public_user/docs/screens/05_profile_owner_john.png)

Friend view of John's gallery as Mike:

![Friend Gallery](https://github.com/ijc3093/Business3/blob/master/public_user/docs/screens/06_profile_gallery_mike.png)

Bug evidence: Mike can open John's Gear controls:

![Gear Bug](https://github.com/ijc3093/Business3/blob/master/public_user/docs/screens/07_profile_gear_bug_mike.png)

## 6.6 Contacts, Friend Requests, and Add Friend

Covered CSV rows:

- `QA-010` contacts page shows Mike as an existing friend
- `QA-011` friend-request page shows empty state when no requests are pending
- `QA-012` adding an existing friend is blocked gracefully

What I did:

- opened John's contacts page
- opened John's friend-request page
- attempted to add Mike again from `add_contact.php`

What happened:

- Mike appeared in John's contacts as expected
- friend requests page showed a clean empty state
- duplicate add was blocked with `This user is already your friend.`

Result:

- `Pass`

Screenshot evidence:

Contacts page:

![Contacts Page](https://github.com/ijc3093/Business3/blob/master/public_user/docs/screens/08_contacts_john.png)

Friend request empty state:

![Friend Requests Page](https://github.com/ijc3093/Business3/blob/master/public_user/docs/screens/09_requests_john.png)

Duplicate add-friend protection:

![Add Friend Duplicate Check](https://github.com/ijc3093/Business3/blob/master/public_user/docs/screens/21_add_contact_duplicate_john.png)

## 6.7 Messaging and Chat Routes

Covered CSV rows:

- `QA-013` John can open the DM thread to Mike
- `QA-014` support route opens a support chat conversation
- `QA-022` direct legacy chat route should handle missing conversation id gracefully
- `QA-023` compose page renders friend-code messaging form

What I did:

- opened `messages.php?peer=USR-NZQ2-GP6U`
- opened `support.php`
- opened `chat.php` without `c`
- opened `compose.php`

What happened:

- the main private-message route loaded correctly
- the support route redirected into the old chat UI
- direct `chat.php` returned raw text `Missing conversation id.`
- compose loaded its friend-code-only form

Result:

- `QA-013`: `Pass`
- `QA-014`: `Pass`
- `QA-022`: `Fail` as `BUG-002`
- `QA-023`: `Pass`

Screenshot evidence:

Messages page:

![Messages Page](https://github.com/ijc3093/Business3/blob/master/public_user/docs/screens/10_messages_john.png)

Support route landing in legacy chat:

![Support Chat](https://github.com/ijc3093/Business3/blob/master/public_user/docs/screens/11_support_chat_john.png)

Bug evidence: `chat.php` without conversation id:

![Missing Conversation Id](https://github.com/ijc3093/Business3/blob/master/public_user/docs/screens/19_chat_missing_id_john.png)

Compose page:

![Compose Page](https://github.com/ijc3093/Business3/blob/master/public_user/docs/screens/20_compose_john.png)

## 6.8 Live and Timeline

Covered CSV rows:

- `QA-015` friend live page renders empty state when no active friend rooms exist
- `QA-016` friend can open John's friends-only live page
- `QA-017` timeline page reflects John's approval gate cleanly

What I did:

- opened `public_live.php?scope=friends` as John
- opened `live_watch.php?live=647` as Mike
- opened `timeline.php?u=1` as Mike

What happened:

- friend live page showed a clean empty state
- the friends-only live page opened for Mike
- timeline page displayed John's private-room approval gate cleanly

Result:

- `Pass`

Screenshot evidence:

Friend live empty state:

![Friend Live Empty State](https://github.com/ijc3093/Business3/blob/master/public_user/docs/screens/12_public_live_john.png)

Live watch page:

![Live Watch Page](https://github.com/ijc3093/Business3/blob/master/public_user/docs/screens/13_live_watch_mike.png)

Timeline approval gate:

![Timeline Gate](https://github.com/ijc3093/Business3/blob/master/public_user/docs/screens/14_timeline_mike.png)

## 6.9 Account and Security Tools

Covered CSV rows:

- `QA-018` change-password form renders required fields
- `QA-019` manage devices page shows current and tracked sessions
- `QA-020` account tools page shows export and download actions
- `QA-021` security tools page shows security actions

What I did:

- opened each account and security page as John
- checked for the expected form fields, buttons, counts, and action panels

What happened:

- all four pages loaded
- change-password fields were present
- device-management counts and device cards were present
- account tools and security tools showed the expected actions

Result:

- `Pass`

Screenshot evidence:

Change password:

![Change Password](https://github.com/ijc3093/Business3/blob/master/public_user/docs/screens/15_change_password_john.png)

Manage devices:

![Manage Devices](https://github.com/ijc3093/Business3/blob/master/public_user/docs/screens/16_manage_devices_john.png)

Account tools:

![Account Tools](https://github.com/ijc3093/Business3/blob/master/public_user/docs/screens/17_account_tools_john.png)

Security tools:

![Security Tools](https://github.com/ijc3093/Business3/blob/master/public_user/docs/screens/18_security_tools_john.png)

## 7. Bugs Found in This Excel-Based Pass

### BUG-001: Non-owner can open another user's Gear controls

CSV reference:

- `QA-009`

What was tested:

- Mike opened `profile.php?id=1&tab=gear`

What happened:

- the page rendered John's settings and control sections for Mike

What should have happened:

- only John should be allowed to see John's gear/settings controls
- non-owners should be blocked or shown a safer fallback

Evidence:

![BUG-001 Evidence](https://github.com/ijc3093/Business3/blob/master/public_user/docs/screens/07_profile_gear_bug_mike.png)

### BUG-002: `chat.php` without `c` shows raw text instead of a better fallback

CSV reference:

- `QA-022`

What was tested:

- John opened `chat.php` directly without a conversation id

What happened:

- the route returned plain text only: `Missing conversation id.`

What should have happened:

- the route should redirect to a safer page or show a structured empty state

Evidence:

![BUG-002 Evidence](https://github.com/ijc3093/Business3/blob/master/public_user/docs/screens/19_chat_missing_id_john.png)

## 8. How QA Should Retest After a Developer Fix

When a developer says a bug is fixed, QA should repeat the original failing steps and then do quick regression checks around the same area.

### Retest for `BUG-001`

Use Mike again:

1. log in as `mike_i`
2. open `profile.php?id=1&tab=gear`
3. confirm John's gear/settings controls no longer appear
4. open John's normal profile and gallery tabs to confirm allowed friend views still work
5. log in as John and confirm John can still access his own gear page

### Retest for `BUG-002`

Use John again:

1. log in as `john_k`
2. open `chat.php` directly
3. confirm the route now redirects or renders a friendly fallback
4. open `support.php` to make sure valid legacy chat still works
5. open `messages.php?peer=USR-NZQ2-GP6U` to make sure normal messaging still works

## 9. Final Reading Guide

If you want to understand the recent QA work clearly, read the files in this order:

1. [QA_Tester_Test_Excel.md](https://github.com/ijc3093/Business3/blob/master/public_user/docs/QA_Tester_Test_Excel.md) for the organized walkthrough
2. [QA_Test_Cases_public_user.csv](https://github.com/ijc3093/Business3/blob/master/public_user/docs/QA_Test_Cases_public_user.csv) for the row-by-row execution record
3. [QA_Tester.md](https://github.com/ijc3093/Business3/blob/master/public_user/docs/QA_Tester.md) for the earlier broader QA pass

This file is meant to answer the question:

`How did the QA tester test public_user recently, what exactly was checked, and what did the tester find?`

The short answer is:

- the tester used a manual Excel/CSV test-case method
- the tester logged in as John and Mike
- the tester opened the important pages and compared expected vs actual behavior
- the tester recorded the outcome row by row
- the tester found two confirmed issues in this pass
- the screenshots in `docs/screens/` now show the same major functions that were covered in the CSV
