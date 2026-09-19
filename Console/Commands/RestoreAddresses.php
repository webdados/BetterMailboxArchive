<?php

namespace Modules\BetterMailboxArchive\Console\Commands;

use App\Mailbox;
use App\Option;
use Illuminate\Console\Command;

class RestoreAddresses extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bettermailboxarchive:restore-addresses {--force : Restore even when another mailbox or user holds the original address, by skipping only the ones that clash}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Put back the real email address of every mailbox whose address this module released.';

    /**
     * Recovery path for the one thing this module cannot protect itself
     * against: FreeScout fires no hook when a module is deactivated or
     * deleted, so a module removed while mailboxes are archived would leave
     * their addresses parked on the tagged variant forever, with the
     * originals surviving only in the options table.
     *
     * Running this before removing the module, or after having removed and
     * reinstalled it, undoes every rewrite.
     *
     * @return void
     */
    public function handle()
    {
        $prefix = 'bettermailboxarchive.original_email_mailbox_';

        $options = Option::where('name', 'like', $prefix.'%')->get();

        if (!count($options)) {
            $this->info('No released addresses found. Nothing to do.');

            return;
        }

        $restored = 0;
        $skipped = 0;

        foreach ($options as $option) {
            $mailbox_id = (int) substr($option->name, strlen($prefix));
            $original = (string) $option->value;

            if (!$mailbox_id || !$original) {
                continue;
            }

            $mailbox = Mailbox::find($mailbox_id);

            if (!$mailbox) {
                $this->warn('Mailbox #'.$mailbox_id.' no longer exists, clearing its stored address ('.$original.').');
                Option::set($option->name, '');
                continue;
            }

            if ($mailbox->email == $original) {
                Option::set($option->name, '');
                continue;
            }

            $clash = Mailbox::where('email', $original)->where('id', '!=', $mailbox_id)->first();
            if ($clash) {
                $this->error('Mailbox #'.$mailbox_id.' ("'.$mailbox->name.'") not restored: '.$original.' is used by mailbox #'.$clash->id.' ("'.$clash->name.'").');
                $skipped++;
                continue;
            }

            if (\App\User::where('email', $original)->exists()) {
                $this->error('Mailbox #'.$mailbox_id.' ("'.$mailbox->name.'") not restored: '.$original.' is used by a user.');
                $skipped++;
                continue;
            }

            $mailbox->email = $original;
            $mailbox->save();
            Option::set($option->name, '');

            $this->info('Mailbox #'.$mailbox_id.' ("'.$mailbox->name.'") restored to '.$original.'.');
            $restored++;
        }

        $this->info('Done. '.$restored.' restored, '.$skipped.' skipped.');

        if ($skipped) {
            $this->warn('Skipped mailboxes keep their released address. Free up the conflicting address and run this again.');
        }
    }
}
