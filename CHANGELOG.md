# Changelog

## 0.2 - 2026-09-19

* New "Hide from administrators too" option, which appears once a mailbox is archived and is off by default. With it on, the mailbox disappears for administrators as well: no conversations, no search results, nowhere in the menus. Only its settings pages stay reachable, and it is marked "Hidden" in Manage > Mailboxes, so you can always turn it back off
* The "Fetch emails" and "Send emails" switches now sit right below the "Archived" switch, instead of above it
* Fixed every page failing to load for staff who are not administrators, when they had access to exactly two mailboxes and one of them was archived
* Fixed the "Fetch emails" and "Send emails" switches not disappearing when the mailbox was archived, and the mailbox address not being shown as read-only while it is released
* Fixed archived mailboxes not being fully read only: the assignee and status controls are now gone from their conversations, and from the bulk actions in their conversation list
* Fixed turning fetching off making FreeScout flag the mailbox as badly configured, with an unexplained lightning bolt next to its name, a greyed out dashboard card and no folder counts. A mailbox that really is missing its settings still gets flagged
* Fixed the mailbox settings button being left with a square, border-less right edge when the "New conversation" button is hidden

## 0.1 - 2026-09-19

* First version
