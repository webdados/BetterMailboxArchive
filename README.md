# Better Mailbox Archive for Freescout

Freescout module that finishes what core's **Archived mailboxes** started: it stops an archived mailbox from *sending*, frees its email address so another mailbox can take it over, and adds separate switches to turn fetching and sending off on their own.

## Why this exists

We asked for a way to deactivate a mailbox in [freescout#4244](https://github.com/freescout-help-desk/freescout/issues/4244), back in September 2024. The use case is an annual event: once this year's event is over the mailbox should stop fetching, and next year's team should get a fresh mailbox on the same email address.

The answers were to change the old mailbox's email address, and then to "just remove the IMAP password and the mailbox will be disabled". Neither is a feature, both are side effects of something else, and another user has since reported on the same issue that removing the password no longer even works: it cannot be saved, it keeps reappearing, and Freescout carries on hammering the mail server every minute and filling the log. Our own production workaround was to point the mailbox at a deliberately non-existent IMAP and SMTP host, so Freescout spends forever failing to connect to nothing.

Turning something off should be a switch, not a trick you have to know. Software should be built for the people using it, not for the people who wrote it.

Freescout **1.8.240** finally added one: an "Archived" switch on each mailbox. It is a genuine improvement and this module does not try to replace it. But it stops half way, so this module adds the other half.

## What core's Archived already does, and what it does not

Core's Archived switch stops the mailbox fetching, hides it from everyone who is not an administrator, and marks it with a padlock. Administrators keep normal access to it.

What it does not do:

- **It does not stop the mailbox sending.** An administrator can still reply from an archived mailbox, auto replies can still fire, staff notification emails still go out through its SMTP server, and "Send Test" still works.
- **It does not free the email address.** Freescout requires mailbox addresses to be unique, so you still cannot create a new mailbox on the address an archived one is holding. That is the actual thing issue #4244 asked for, and it is still not possible with core alone.
- **It is all or nothing.** There is no setting for "this mailbox is closed, but the whole team can still read and search last year's threads". Archiving hides it from every non-administrator.

## What this module adds

- **Send emails** switch, per mailbox. Turn it off and nothing leaves that mailbox at all: replies, forwards, auto replies, staff notifications and Send Test are all blocked, at the mail layer and not just in the interface. Reply and Forward disappear from its conversations.
- **Fetch emails** switch, per mailbox, so you can stop checking for new mail while the mailbox stays completely visible and searchable for everyone. Turning both switches off, without archiving, gives you the "closed but still readable by the whole team" state that core has no setting for.
- **Automatic address release.** Archive a mailbox and its email address is parked on a tagged variant (`events+archived-7@example.com`), freeing the real address for a new mailbox straight away. Un-archive it and the real address comes back, unless another mailbox or a user has taken it meanwhile, in which case you get a clear error naming whoever has it rather than a silent failure. The mailbox id in the tag means **any number of archived mailboxes can share the same real address**, which is exactly the annual-event case: one archived mailbox per year, all of them remembering `events@example.com`.
- **Read-only archives.** An archived mailbox keeps only Move (and Delete, for administrators). Everything else is gone, so the history stays exactly as it was. Move is deliberately kept, because "last year's box is closed but this one thread is still live" is the whole reason you would open an archive at all. Nothing can be moved *into* an archived mailbox.

## Requirements

- Freescout **1.8.240** or newer. That is the release that introduced archived mailboxes, and everything here is layered on top of that state. On anything older the module stays active but inert and says so on the mailbox settings page, rather than silently doing nothing.

## Installation

* Download the latest version from this repository
* Upload/extract to the Modules folder on your Freescout install, inside a folder named BetterMailboxArchive
* Go to Manage > Settings > Tools and Clear Cache
* Go to Modules and activate "Better Mailbox Archive"
* The two switches appear in Manage > Mailboxes > [mailbox] > Settings, next to core's own "Archived" switch

No further configuration is needed.

## Important: before you remove this module

Freescout gives modules no way to run anything when they are deactivated or deleted. If you remove this module while a mailbox is archived, that mailbox's email address stays parked on the tagged variant, and the real address survives only in the options table.

**Un-archive every mailbox before removing the module**, or, if it is already too late, reinstall it and run:

```
php artisan bettermailboxarchive:restore-addresses
```

That puts every released address back, and tells you about any it could not restore because something else has taken the address in the meantime.

## Known limitations

* **Releasing an address only means something inside Freescout.** Mail sent to the real address still arrives in the original inbox on your mail server and piles up there. Remove or redirect it on the mail server too.
* **Administrators still see archived mailboxes in search results.** That is core's behaviour, and arguably the right one for an archive.
* A customer's profile page lists conversations without any extension point for modules, so conversations from an archived mailbox can still show up there for an administrator.

## To do

* Propose upstream the hooks that would let this module do less: a `mailbox.out_active` filter mirroring the existing `mailbox.in_active` would remove nearly all of the send-blocking machinery here. `MailboxesController::connectionOutgoingSave()` is also missing a `mailbox.outgoing_settings_before_save` action, and the `send_test` ajax case is missing a `mailbox.send_test` filter, both of which their incoming counterparts already have.

PRs are welcome.
