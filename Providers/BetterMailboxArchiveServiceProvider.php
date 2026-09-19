<?php

namespace Modules\BetterMailboxArchive\Providers;

use App\Mailbox;
use App\User;
use Illuminate\Support\ServiceProvider;

class BetterMailboxArchiveServiceProvider extends ServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    /**
     * Minimum FreeScout core version. 1.8.240 is the release that introduced
     * archived mailboxes: Mailbox::STATE_ARCHIVED, the "st" meta key and
     * Mailbox::isArchived(). Everything this module does is layered on top of
     * that state, and calling isArchived() on an older core is a fatal error,
     * so the version is enforced at runtime in boot() as well as declared in
     * module.json (which FreeScout only checks at install/update time).
     *
     * @var string
     */
    const REQUIRED_APP_VERSION = '1.8.240';

    /**
     * Prefix for every Option key this module writes. Per-mailbox keys are
     * "<prefix>.<setting>_mailbox_<id>", matching the convention used by our
     * other FreeScout modules.
     *
     * @var string
     */
    const OPTION_PREFIX = 'bettermailboxarchive';

    /**
     * Ajax actions that put mail on the wire, blocked server-side for a
     * mailbox that cannot send. Hiding the buttons is never the only defence:
     * a cancelled Swift send is silent (see filterSwiftMessage()), so the
     * request has to be refused before a thread is marked as delivered.
     *
     * @var string[]
     */
    const BLOCKED_SEND_ACTIONS = array('send_reply', 'retry_send');

    /**
     * Conversation ajax actions blocked on an archived mailbox, which is read
     * only apart from moving a conversation out of it.
     *
     * Deliberately absent: move_conv / conversation_move, the delete and
     * restore actions (only admins can reach an archived mailbox at all, and
     * housekeeping should stay possible), and the personal bookmarks star and
     * follow, which change nothing for anyone else.
     *
     * @var string[]
     */
    const BLOCKED_ARCHIVED_ACTIONS = array(
        'send_reply',
        'retry_send',
        'save_draft',
        'discard_draft',
        'save_edit_thread',
        'delete_thread',
        'update_subject',
        'change_customer',
        'conversation_change_customer',
        'conversation_change_status',
        'conversation_change_user',
        'bulk_conversation_change_status',
        'bulk_conversation_change_user',
        'conversation_merge',
        'merge_conv',
    );

    /**
     * Routes that are never blocked, however hidden a mailbox is.
     *
     * Evaluated before any deny logic, so that no later change here can strand
     * an administrator inside a mailbox they can no longer open. Manage >
     * Mailboxes is deliberately included: MailboxesController@mailboxes has no
     * Eventy filter, so that list always renders and is the way back in.
     *
     * mailboxes.view and mailboxes.view.folder are deliberately absent, and
     * are the only mailboxes.* routes this module ever refuses.
     *
     * @var string[]
     */
    const ALWAYS_ALLOWED_ROUTES = array(
        'dashboard',
        'mailboxes',
        'mailboxes.create',
        'mailboxes.update',
        'mailboxes.update.save',
        'mailboxes.connection',
        'mailboxes.connection.save',
        'mailboxes.connection.incoming',
        'mailboxes.connection.incoming.save',
        'mailboxes.permissions',
        'mailboxes.permissions.save',
        'mailboxes.auto_reply',
        'mailboxes.auto_reply.save',
        'mailboxes.oauth',
        'mailboxes.oauth_callback',
        'mailboxes.oauth_disconnect',
        'mailboxes.ajax',
        'modules',
        'modules.ajax',
        'system.tools',
        'system.tools.action',
    );

    /**
     * Per-request memo of every option this module owns, loaded in one query
     * the first time any of them is read.
     *
     * @var string[]|null
     */
    protected static $options_cache = null;

    /**
     * Per-request memo of the mailboxes that must not send.
     *
     * Only ever populated on cached reads. The uncached path deliberately
     * recomputes, because this static outlives a single job inside a
     * long-running queue worker.
     *
     * @var \App\Mailbox[]|null
     */
    protected static $send_disabled_mailboxes = null;

    /**
     * Per-request memo of the ids hidden from administrators too.
     *
     * @var int[]|null
     */
    protected static $hidden_mailbox_ids = null;

    /**
     * Boot the application events.
     *
     * @return void
     */
    public function boot()
    {
        $incompatibility = $this->checkCompatibility();

        if ($incompatibility) {
            $this->hooksIncompatible($incompatibility);

            return;
        }

        $this->commands(array(
            \Modules\BetterMailboxArchive\Console\Commands\RestoreAddresses::class,
        ));

        $this->hooks();
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Returns a human-readable reason this module cannot run, or null when
     * the running core is new enough.
     *
     * @return string|null
     */
    protected function checkCompatibility()
    {
        $app_version = config('app.version');

        if ($app_version && version_compare($app_version, self::REQUIRED_APP_VERSION, '<')) {
            return sprintf(
                'requires FreeScout %s or newer, which is the release that added Archived mailboxes (found %s).',
                self::REQUIRED_APP_VERSION,
                $app_version
            );
        }

        if (!method_exists('App\Mailbox', 'isArchived')) {
            return 'requires a FreeScout version with Archived mailboxes (Mailbox::isArchived() not found).';
        }

        return null;
    }

    /**
     * On an incompatible core, stay active but inert and say so where the
     * settings would have been, rather than silently doing nothing.
     *
     * @param string $reason
     *
     * @return void
     */
    protected function hooksIncompatible($reason)
    {
        \Eventy::addAction('mailbox.update.before_name', function () use ($reason) {
            echo '<div class="alert alert-warning">'
                .'[Better Mailbox Archive] '.__('Not active').': '.htmlspecialchars($reason)
                .'</div>';
        }, 10, 1);
    }

    /**
     * Register all of the module's hooks.
     *
     * @return void
     */
    public function hooks()
    {
        // Settings UI, on Manage > Mailboxes > [mailbox] > Settings, rendered
        // immediately above core's own "Archived" switch so the three read as
        // one group.
        \Eventy::addAction('mailbox.update.before_name', array($this, 'renderSettings'), 10, 2);
        \Eventy::addFilter('mailbox.settings_validator', array($this, 'validateSettings'), 10, 3);
        \Eventy::addAction('mailbox.settings_before_save', array($this, 'saveSettings'), 10, 2);

        // Explain on the Fetching Emails page why fetching is off.
        \Eventy::addAction('mailbox.connection_incoming.after_default_settings', array($this, 'renderFetchingNote'), 10, 1);

        // Fetching. Core already skips archived mailboxes; this covers the
        // switch being used on a mailbox that is not archived.
        \Eventy::addFilter('mailbox.in_active', array($this, 'filterInActive'), 10, 2);
        \Eventy::addFilter('mailbox.fetch_test', array($this, 'filterFetchTest'), 10, 2);

        // Sending.
        \Eventy::addFilter('mail.process_swift_message', array($this, 'filterSwiftMessage'), 10, 2);
        \Eventy::addFilter('autoreply.should_send', array($this, 'filterAutoReply'), 10, 2);

        // Request guard, for everything the hidden buttons would otherwise be
        // the only protection for.
        \Eventy::addAction('middleware.web.custom_handle', array($this, 'handleRequest'), 10, 1);

        // Conversation actions.
        \Eventy::addFilter('conversation.get_action_buttons', array($this, 'filterActionButtons'), 10, 4);
        \Eventy::addFilter('conversation.reply_button.enabled', array($this, 'filterReplyButton'), 10, 2);
        \Eventy::addFilter('conversation.note_button.enabled', array($this, 'filterNoteButton'), 10, 2);

        // Nothing can be moved into an archived mailbox.
        \Eventy::addFilter('conversations.move_conv.mailboxes', array($this, 'filterMoveMailboxes'), 10, 1);

        // "Hide from administrators too": core already removes an archived
        // mailbox from these lists for everyone else, so this only has to
        // repeat that for the administrators core still lets through.
        \Eventy::addFilter('menu.mailboxes', array($this, 'filterMenuMailboxes'), 10, 1);
        \Eventy::addFilter('dashboard.mailboxes', array($this, 'filterDashboardMailboxes'), 10, 1);
        \Eventy::addFilter('search.conversations.apply_filters', array($this, 'filterSearchConversations'), 10, 3);

        // Cosmetics.
        \Eventy::addAction('layout.head', array($this, 'printStyles'), 10, 1);
        \Eventy::addFilter('flash_messages.flashes', array($this, 'filterFlashes'), 10, 1);
    }

    /*
    |--------------------------------------------------------------------------
    | State
    |--------------------------------------------------------------------------
    */

    /**
     * Build a per-mailbox Option key.
     *
     * @param string $setting
     * @param int    $mailbox_id
     *
     * @return string
     */
    protected function optionKey($setting, $mailbox_id)
    {
        return self::OPTION_PREFIX.'.'.$setting.'_mailbox_'.(int) $mailbox_id;
    }

    /**
     * Read one of this module's per-mailbox options.
     *
     * The three guards that run inside queue jobs pass $use_cache = false:
     * Option::$cache is a static per-process map, so a long-running worker
     * booted before a settings change would otherwise hold the old value for
     * the rest of its life.
     *
     * @param string $setting
     * @param int    $mailbox_id
     * @param string $default
     * @param bool   $use_cache
     *
     * @return string
     */
    protected function option($setting, $mailbox_id, $default = '1', $use_cache = true)
    {
        $key = $this->optionKey($setting, $mailbox_id);

        if (!$use_cache) {
            $row = \App\Option::where('name', $key)->first();

            return $row ? (string) $row->value : (string) $default;
        }

        if (self::$options_cache === null) {
            self::$options_cache = array();

            foreach (\App\Option::where('name', 'like', self::OPTION_PREFIX.'.%')->get() as $row) {
                self::$options_cache[$row->name] = (string) $row->value;
            }
        }

        return array_key_exists($key, self::$options_cache) ? self::$options_cache[$key] : (string) $default;
    }

    /**
     * Write one of this module's options, keeping the per-request memo in
     * step so a later read in the same request does not see a stale value.
     *
     * @param string $setting
     * @param int    $mailbox_id
     * @param string $value
     *
     * @return void
     */
    protected function setOption($setting, $mailbox_id, $value)
    {
        $key = $this->optionKey($setting, $mailbox_id);

        \Option::set($key, $value);

        if (is_array(self::$options_cache)) {
            self::$options_cache[$key] = (string) $value;
        }

        self::$send_disabled_mailboxes = null;
        self::$hidden_mailbox_ids = null;
    }

    /**
     * Whether the mailbox may fetch. Archiving always wins over the switch,
     * and the switch's own stored value is left untouched so it comes back
     * when the mailbox is un-archived.
     *
     * @param \App\Mailbox $mailbox
     * @param bool         $use_cache
     *
     * @return bool
     */
    public function isFetchEnabled($mailbox, $use_cache = true)
    {
        if (!$mailbox || $mailbox->isArchived()) {
            return false;
        }

        return $this->option('fetch_enabled', $mailbox->id, '1', $use_cache) == '1';
    }

    /**
     * Whether the mailbox may send.
     *
     * @param \App\Mailbox $mailbox
     * @param bool         $use_cache
     *
     * @return bool
     */
    public function isSendEnabled($mailbox, $use_cache = true)
    {
        if (!$mailbox || $mailbox->isArchived()) {
            return false;
        }

        return $this->option('send_enabled', $mailbox->id, '1', $use_cache) == '1';
    }

    /**
     * Whether the mailbox is hidden from administrators too.
     *
     * Only meaningful while the mailbox is archived, since core already hides
     * an archived mailbox from everybody else. The stored value survives an
     * un-archive untouched, like the other two switches.
     *
     * @param \App\Mailbox $mailbox
     * @param bool         $use_cache
     *
     * @return bool
     */
    public function isHiddenFromAdmins($mailbox, $use_cache = true)
    {
        if (!$mailbox || !$mailbox->isArchived()) {
            return false;
        }

        return $this->option('hidden_from_admins', $mailbox->id, '0', $use_cache) == '1';
    }

    /**
     * Ids of every mailbox hidden from administrators too, memoised per
     * request.
     *
     * @return int[]
     */
    protected function hiddenMailboxIds()
    {
        if (self::$hidden_mailbox_ids !== null) {
            return self::$hidden_mailbox_ids;
        }

        $ids = array();

        foreach (Mailbox::all() as $mailbox) {
            if ($this->isHiddenFromAdmins($mailbox)) {
                $ids[] = (int) $mailbox->id;
            }
        }

        self::$hidden_mailbox_ids = $ids;

        return $ids;
    }

    /**
     * Drop hidden mailboxes from a list core has handed us.
     *
     * values() is not optional: layouts/app.blade.php:77 does
     * "count($mailboxes) == 1" and then "$mailboxes[0]", and both reject() and
     * core's own Mailbox::excludeArchived() preserve the original keys.
     *
     * @param mixed $mailboxes
     *
     * @return mixed
     */
    protected function rejectHidden($mailboxes)
    {
        $ids = $this->hiddenMailboxIds();

        if (!count($ids) || !$mailboxes) {
            return $mailboxes;
        }

        if (is_array($mailboxes)) {
            foreach ($mailboxes as $i => $mailbox) {
                if (in_array((int) $mailbox->id, $ids, true)) {
                    unset($mailboxes[$i]);
                }
            }

            return array_values($mailboxes);
        }

        return $mailboxes->reject(function ($mailbox) use ($ids) {
            return in_array((int) $mailbox->id, $ids, true);
        })->values();
    }

    /**
     * Every address that must not send, lower-cased, memoised per request.
     *
     * Includes each mailbox's own address, the address core would actually
     * put in the From header, and every alias, because a reply can legitimately
     * go out from any of them.
     *
     * @param bool $use_cache
     *
     * @return string[]
     */
    protected function getSendDisabledAddresses($use_cache = true)
    {
        $addresses = array();

        foreach ($this->sendDisabledMailboxes($use_cache) as $mailbox) {
            $candidates = array($mailbox->email);

            $mail_from = $mailbox->getMailFrom();
            if (!empty($mail_from['address'])) {
                $candidates[] = $mail_from['address'];
            }

            foreach ($mailbox->getAliases() as $alias) {
                $candidates[] = $alias;
            }

            foreach ($candidates as $candidate) {
                $candidate = \App\Email::sanitizeEmail($candidate);
                if ($candidate) {
                    $addresses[] = $candidate;
                }
            }
        }

        return array_values(array_unique($addresses));
    }

    /**
     * Every mailbox that must not send.
     *
     * The memo is only used, and only filled, on cached reads. Inside a queue
     * worker this static would otherwise survive from one job to the next and
     * never notice a mailbox being archived.
     *
     * @param bool $use_cache
     *
     * @return \App\Mailbox[]
     */
    protected function sendDisabledMailboxes($use_cache = true)
    {
        if ($use_cache && self::$send_disabled_mailboxes !== null) {
            return self::$send_disabled_mailboxes;
        }

        $disabled = array();

        foreach (Mailbox::all() as $mailbox) {
            if (!$this->isSendEnabled($mailbox, $use_cache)) {
                $disabled[] = $mailbox;
            }
        }

        if ($use_cache) {
            self::$send_disabled_mailboxes = $disabled;
        }

        return $disabled;
    }

    /**
     * Resolve a mailbox from an id, tolerating a missing row.
     *
     * @param int $mailbox_id
     *
     * @return \App\Mailbox|null
     */
    protected function findMailbox($mailbox_id)
    {
        $mailbox_id = (int) $mailbox_id;

        if (!$mailbox_id) {
            return null;
        }

        return Mailbox::find($mailbox_id);
    }

    /*
    |--------------------------------------------------------------------------
    | Settings UI
    |--------------------------------------------------------------------------
    */

    /**
     * The two switches, on Manage > Mailboxes > [mailbox] > Settings.
     *
     * Admin only, matching core's own Archived switch. The hidden marker is
     * what tells saveSettings() that this form actually contained our fields:
     * a mailbox manager with ACCESS_PERM_EDIT reaches the same save hook with
     * a form that has neither our switches nor core's, and must not be read as
     * having turned everything back on.
     *
     * @param \App\Mailbox $mailbox
     * @param mixed        $errors
     *
     * @return void
     */
    public function renderSettings($mailbox, $errors = null)
    {
        if (!$mailbox || !$mailbox->exists) {
            return;
        }

        $user = auth()->user();
        if (!$user || !$user->isAdmin()) {
            return;
        }

        $fetch_on = $this->option('fetch_enabled', $mailbox->id) == '1';
        $send_on = $this->option('send_enabled', $mailbox->id) == '1';
        $original_email = $this->option('original_email', $mailbox->id, '');

        // Archiving already forces both switches off, so they are irrelevant
        // while it is on. Rendered hidden server-side rather than left to the
        // script below, so the state is right even before any JavaScript runs.
        $hide_switches = $mailbox->isArchived() ? ' style="display: none;"' : '';

        // The opposite: only meaningful while the mailbox is archived.
        $hide_archived_only = $mailbox->isArchived() ? '' : ' style="display: none;"';
        $hidden_on = $this->option('hidden_from_admins', $mailbox->id, '0') == '1';
        ?>
        <div id="better-mailbox-archive-options">
            <?php if ($original_email) : ?>
                <div class="form-group">
                    <label class="col-sm-2 control-label"><?php echo __('Released address'); ?></label>
                    <div class="col-sm-6">
                        <div class="controls">
                            <div class="alert alert-info">
                                <?php echo __('This mailbox is archived, so its email address has been released and another mailbox can now use it.'); ?>
                                <br>
                                <strong><?php echo htmlspecialchars($original_email); ?></strong>
                                <?php echo __('will be restored when you un-archive this mailbox, unless another mailbox or user has taken it by then.'); ?>
                                <br>
                                <?php echo __('Mail sent to that address still arrives in the original inbox on your mail server. Remove or redirect it there too.'); ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            <div class="form-group better-mailbox-archive-switch"<?php echo $hide_switches; ?>>
                <label for="bettermailboxarchive_fetch_enabled" class="col-sm-2 control-label"><?php echo __('Fetch emails'); ?></label>
                <div class="col-sm-6">
                    <div class="controls">
                        <div class="onoffswitch-wrap">
                            <div class="onoffswitch">
                                <input type="checkbox" name="bettermailboxarchive_fetch_enabled" value="1" id="bettermailboxarchive_fetch_enabled" class="onoffswitch-checkbox" <?php if ($fetch_on) echo 'checked="checked"'; ?>>
                                <label class="onoffswitch-label" for="bettermailboxarchive_fetch_enabled"></label>
                            </div>
                            <i class="glyphicon glyphicon-info-sign icon-info icon-info-inline" data-toggle="popover" data-trigger="hover" data-placement="top" data-content="<?php echo __('Turn off to stop checking this mailbox for new email, while leaving it fully visible and searchable for everyone.'); ?>"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="form-group better-mailbox-archive-switch"<?php echo $hide_switches; ?>>
                <label for="bettermailboxarchive_send_enabled" class="col-sm-2 control-label"><?php echo __('Send emails'); ?></label>
                <div class="col-sm-6">
                    <div class="controls">
                        <div class="onoffswitch-wrap">
                            <div class="onoffswitch">
                                <input type="checkbox" name="bettermailboxarchive_send_enabled" value="1" id="bettermailboxarchive_send_enabled" class="onoffswitch-checkbox" <?php if ($send_on) echo 'checked="checked"'; ?>>
                                <label class="onoffswitch-label" for="bettermailboxarchive_send_enabled"></label>
                            </div>
                            <i class="glyphicon glyphicon-info-sign icon-info icon-info-inline" data-toggle="popover" data-trigger="hover" data-placement="top" data-content="<?php echo __('Turn off to stop this mailbox sending anything at all: replies, forwards, auto replies and staff notifications. Reply and Forward are removed from its conversations.'); ?>"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="form-group better-mailbox-archive-archived-only"<?php echo $hide_archived_only; ?>>
                <label for="bettermailboxarchive_hidden_from_admins" class="col-sm-2 control-label"><?php echo __('Hide from administrators too'); ?></label>
                <div class="col-sm-6">
                    <div class="controls">
                        <div class="onoffswitch-wrap">
                            <div class="onoffswitch">
                                <input type="checkbox" name="bettermailboxarchive_hidden_from_admins" value="1" id="bettermailboxarchive_hidden_from_admins" class="onoffswitch-checkbox" <?php if ($hidden_on) echo 'checked="checked"'; ?>>
                                <label class="onoffswitch-label" for="bettermailboxarchive_hidden_from_admins"></label>
                            </div>
                            <i class="glyphicon glyphicon-info-sign icon-info icon-info-inline" data-toggle="popover" data-trigger="hover" data-placement="top" data-content="<?php echo __('Archiving already hides this mailbox from everyone who is not an administrator. Turn this on to hide it from administrators as well: no conversations, no search results, nowhere in the menus. Only these settings pages stay reachable, so you can turn it back off.'); ?>"></i>
                        </div>
                    </div>
                </div>
            </div>
            <input type="hidden" name="bettermailboxarchive_present" value="1">
            <hr>
        </div>
        <script type="text/javascript"<?php echo \Helper::cspNonceAttr(); ?>>
            document.addEventListener('DOMContentLoaded', function () {
                var state = document.getElementById('mailbox_state');
                var email = document.getElementById('email');
                var switches = document.querySelectorAll('.better-mailbox-archive-switch');
                var archived_only = document.querySelectorAll('.better-mailbox-archive-archived-only');
                var options = document.getElementById('better-mailbox-archive-options');

                // Our fields belong under core's "Archived" switch, but the only
                // hook on this page fires above it (mailbox.update.before_name at
                // resources/views/mailboxes/update.blade.php:25) and core has no
                // hook between that block and the Mailbox Name field. So render
                // there and move into place. If anything about that markup
                // changes, the fields simply stay where they were rendered.
                if (state && options) {
                    var archived_group = state.closest('.form-group');

                    if (archived_group && archived_group.parentNode) {
                        archived_group.parentNode.insertBefore(options, archived_group.nextSibling);
                    }
                }

                function sync() {
                    var archived = state && state.checked;
                    for (var i = 0; i < switches.length; i++) {
                        switches[i].style.display = archived ? 'none' : '';
                    }
                    for (var j = 0; j < archived_only.length; j++) {
                        archived_only[j].style.display = archived ? '' : 'none';
                    }
                    if (email) {
                        email.readOnly = !!archived;
                        if (archived) {
                            email.classList.add('disabled');
                        } else {
                            email.classList.remove('disabled');
                        }
                    }
                }

                if (state) {
                    state.addEventListener('change', sync);
                }
                sync();
            });
        </script>
        <?php
    }

    /**
     * A note on Connection Settings > Fetching Emails explaining that fetching
     * is off deliberately and where to turn it back on.
     *
     * @param \App\Mailbox $mailbox
     *
     * @return void
     */
    public function renderFetchingNote($mailbox)
    {
        if (!$mailbox || $this->isFetchEnabled($mailbox)) {
            return;
        }

        $reason = $mailbox->isArchived()
            ? __('This mailbox is archived, so it does not check for new email.')
            : __('Fetching is turned off for this mailbox.');
        ?>
        <div class="form-group">
            <div class="col-sm-6 col-sm-offset-2">
                <div class="alert alert-info">
                    <?php echo $reason; ?>
                    <a href="<?php echo route('mailboxes.update', array('id' => $mailbox->id)); ?>"><?php echo __('Mailbox Settings'); ?></a>
                </div>
            </div>
        </div>
        <hr>
        <?php
    }

    /*
    |--------------------------------------------------------------------------
    | Saving, and the email address release
    |--------------------------------------------------------------------------
    */

    /**
     * Refuse a state change that cannot be carried out, before anything is
     * written. Core redirects back with these errors the same way it does for
     * its own userEmailExists() check.
     *
     * @param mixed                    $validator
     * @param \App\Mailbox             $mailbox
     * @param \Illuminate\Http\Request $request
     *
     * @return mixed
     */
    public function validateSettings($validator, $mailbox, $request)
    {
        if (!$this->formHasOurFields($request) || !$mailbox || !$mailbox->exists) {
            return $validator;
        }

        $will_be_archived = $request->filled('state');
        $is_archived = $mailbox->isArchived();

        if (!$is_archived && $will_be_archived) {
            // About to release the address.
            if (!$this->buildTaggedEmail($mailbox->email, $mailbox->id)) {
                $validator->errors()->add('email', __('Cannot archive this mailbox: its email address is too long to release.'));
            }

            return $validator;
        }

        if ($is_archived && !$will_be_archived) {
            // About to restore the address.
            $original = $this->option('original_email', $mailbox->id, '');

            if (!$original) {
                return $validator;
            }

            $clash_mailbox = Mailbox::where('email', $original)->where('id', '!=', $mailbox->id)->first();
            if ($clash_mailbox) {
                $validator->errors()->add('email', __('Cannot un-archive this mailbox: the address :email is now used by the mailbox ":name" (#:id).', array(
                    'email' => $original,
                    'name'  => $clash_mailbox->name,
                    'id'    => $clash_mailbox->id,
                )));
            }

            $clash_user = User::where('email', $original)->first();
            if ($clash_user) {
                $validator->errors()->add('email', __('Cannot un-archive this mailbox: the address :email is now used by the user ":name".', array(
                    'email' => $original,
                    'name'  => $clash_user->getFullName(),
                )));
            }
        }

        return $validator;
    }

    /**
     * Persist the two switches and perform the address release or restore.
     *
     * Fires before core sets $mailbox->state (a few lines further down in
     * MailboxesController::updateSave()), so the target state is read from the
     * request rather than from the model. The address itself is written by
     * merging into the request and letting core's own fill()/save() do it.
     *
     * @param \App\Mailbox             $mailbox
     * @param \Illuminate\Http\Request $request
     *
     * @return void
     */
    public function saveSettings($mailbox, $request)
    {
        if (!$this->formHasOurFields($request) || !$mailbox || !$mailbox->exists) {
            return;
        }

        $will_be_archived = $request->filled('state');
        $is_archived = $mailbox->isArchived();

        // While archived the switches are hidden, so nothing meaningful is
        // posted for them and the stored values are left alone. They come
        // back as they were when the mailbox is un-archived.
        if (!$will_be_archived) {
            $this->setOption('fetch_enabled', $mailbox->id, $request->filled('bettermailboxarchive_fetch_enabled') ? '1' : '0');
            $this->setOption('send_enabled', $mailbox->id, $request->filled('bettermailboxarchive_send_enabled') ? '1' : '0');
        } else {
            // Only shown, and so only posted, while archived.
            $this->setOption('hidden_from_admins', $mailbox->id, $request->filled('bettermailboxarchive_hidden_from_admins') ? '1' : '0');
        }

        if (!$is_archived && $will_be_archived) {
            $this->releaseAddress($mailbox, $request);
        } elseif ($is_archived && !$will_be_archived) {
            $this->restoreAddress($mailbox, $request);
        } elseif ($is_archived && $will_be_archived) {
            // Stays archived: the address is not editable, whatever was posted.
            $request->merge(array('email' => $mailbox->email));
        }

        if ($is_archived != $will_be_archived) {
            \Helper::queueWorkerRestart();
        }
    }

    /**
     * Whether the submitted form actually carried our fields. See the note in
     * renderSettings() for why this guard is not optional.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return bool
     */
    protected function formHasOurFields($request)
    {
        return (bool) $request->filled('bettermailboxarchive_present');
    }

    /**
     * Store the original address and rewrite the mailbox's own to a tagged
     * variant, freeing the original for another mailbox.
     *
     * @param \App\Mailbox             $mailbox
     * @param \Illuminate\Http\Request $request
     *
     * @return void
     */
    protected function releaseAddress($mailbox, $request)
    {
        $original = \App\Email::sanitizeEmail($request->input('email', $mailbox->email));
        if (!$original) {
            $original = $mailbox->email;
        }

        $tagged = $this->buildTaggedEmail($original, $mailbox->id);
        if (!$tagged) {
            // validateSettings() should have stopped this already.
            return;
        }

        $this->setOption('original_email', $mailbox->id, $original);
        $request->merge(array('email' => $tagged));
    }

    /**
     * Put the original address back. Conflicts were already refused in
     * validateSettings().
     *
     * @param \App\Mailbox             $mailbox
     * @param \Illuminate\Http\Request $request
     *
     * @return void
     */
    protected function restoreAddress($mailbox, $request)
    {
        $original = $this->option('original_email', $mailbox->id, '');

        if (!$original) {
            return;
        }

        $request->merge(array('email' => $original));
        $this->setOption('original_email', $mailbox->id, '');
    }

    /**
     * Build the tagged address an archived mailbox parks on.
     *
     * The mailbox id in the tag is what allows any number of archived
     * mailboxes to share one original address, which is the whole point: an
     * annual events mailbox archived once per year, each holding the same real
     * address underneath.
     *
     * Falls back from a plus tag to a prefix when the local part would exceed
     * 64 characters or the whole address 128 (the column's own limit), and
     * disambiguates with a counter in the unlikely case someone genuinely owns
     * the generated address, since a collision would otherwise be an unhandled
     * QueryException on the unique index.
     *
     * @param string $original
     * @param int    $mailbox_id
     *
     * @return string|false
     */
    public function buildTaggedEmail($original, $mailbox_id)
    {
        $original = \App\Email::sanitizeEmail($original);

        if (!$original || strpos($original, '@') === false) {
            return false;
        }

        list($local, $domain) = explode('@', $original, 2);

        for ($n = 1; $n <= 20; $n++) {
            $tag = 'archived-'.(int) $mailbox_id.($n > 1 ? '-'.$n : '');

            $candidate = $local.'+'.$tag.'@'.$domain;

            if (strlen($local.'+'.$tag) > 64 || strlen($candidate) > 128) {
                $prefix = $tag.'-';
                $max_local = min(64, 128 - 1 - strlen($domain));
                $keep = max(0, $max_local - strlen($prefix));
                $candidate = $prefix.substr($local, 0, $keep).'@'.$domain;
            }

            if (strlen($candidate) > 128) {
                $candidate = $tag.'@'.$domain;
            }

            if (strlen($candidate) > 128) {
                return false;
            }

            if (!$this->addressTaken($candidate, $mailbox_id)) {
                return $candidate;
            }
        }

        return false;
    }

    /**
     * Whether an address is already spoken for by another mailbox or by a
     * user, matching core's own rule that the two cannot overlap.
     *
     * @param string $email
     * @param int    $except_mailbox_id
     *
     * @return bool
     */
    protected function addressTaken($email, $except_mailbox_id)
    {
        if (Mailbox::where('email', $email)->where('id', '!=', (int) $except_mailbox_id)->exists()) {
            return true;
        }

        return (bool) User::where('email', $email)->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | Fetching
    |--------------------------------------------------------------------------
    */

    /**
     * Report the mailbox as not receiving when fetching is switched off.
     *
     * Returns null rather than true in every other case, so a genuinely
     * unconfigured mailbox is still reported inactive by core's own check.
     * Core already skips archived mailboxes on its own.
     *
     * @param bool|null    $in_active
     * @param \App\Mailbox $mailbox
     *
     * @return bool|null
     */
    public function filterInActive($in_active, $mailbox)
    {
        if ($in_active === false) {
            return $in_active;
        }

        return $this->isFetchEnabled($mailbox, false) ? $in_active : false;
    }

    /**
     * Say why instead of opening an IMAP socket that is not wanted.
     *
     * @param array        $response
     * @param \App\Mailbox $mailbox
     *
     * @return array
     */
    public function filterFetchTest($response, $mailbox)
    {
        if ($this->isFetchEnabled($mailbox)) {
            return $response;
        }

        return array(
            'status' => 'error',
            'msg'    => __('Fetching is turned off for this mailbox.'),
            'tested' => true,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Sending
    |--------------------------------------------------------------------------
    */

    /**
     * Cancel any message going out from a mailbox that must not send.
     *
     * Returning false here makes Illuminate\Mail\Mailer::shouldSendMessage()
     * drop the message. That is silent: no exception is thrown and
     * Mail::failures() stays empty, so callers believe the send succeeded.
     * This is therefore a last-resort net behind handleRequest(), not the
     * primary control.
     *
     * @param bool  $process
     * @param mixed $message
     *
     * @return bool
     */
    public function filterSwiftMessage($process, $message)
    {
        if ($process === false || !$message) {
            return $process;
        }

        $from = $message->getFrom();
        if (!is_array($from) || !count($from)) {
            return $process;
        }

        $blocked = $this->getSendDisabledAddresses(false);
        if (!count($blocked)) {
            return $process;
        }

        foreach (array_keys($from) as $address) {
            $address = \App\Email::sanitizeEmail($address);

            if ($address && in_array($address, $blocked, true)) {
                $type = '';
                $header = $message->getHeaders()->get('X-FreeScout-Mail-Type');
                if ($header) {
                    $type = $header->getFieldBody();
                }

                \Log::warning('[Better Mailbox Archive] Cancelled an outgoing message from '.$address.' because sending is turned off for that mailbox.'.($type ? ' Type: '.$type.'.' : ''));

                return false;
            }
        }

        return $process;
    }

    /**
     * No auto replies from a mailbox that must not send.
     *
     * @param bool              $should_send
     * @param \App\Conversation $conversation
     *
     * @return bool
     */
    public function filterAutoReply($should_send, $conversation)
    {
        if (!$should_send || !$conversation) {
            return $should_send;
        }

        return $this->isSendEnabled($this->findMailbox($conversation->mailbox_id), false);
    }

    /*
    |--------------------------------------------------------------------------
    | Request guard
    |--------------------------------------------------------------------------
    */

    /**
     * Refuse the requests behind the buttons we remove.
     *
     * Core's own policies already handle who may see an archived mailbox, so
     * there is nothing to do here for visibility. What is left is everything
     * that would put mail on the wire, plus the conversation mutations an
     * archived mailbox no longer accepts.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return void
     */
    public function handleRequest($request)
    {
        if (!auth()->user()) {
            return;
        }

        $route_name = \Route::currentRouteName();

        // Anti-lockout, evaluated before anything that can deny: the screens
        // that let an administrator turn "Hide from administrators too" back
        // off are never refused. mailboxes.ajax stays reachable here because
        // the settings screens need it; the narrower send_test check below
        // still applies to it.
        $always_allowed = in_array($route_name, self::ALWAYS_ALLOWED_ROUTES, true);

        if (!$always_allowed) {
            $hidden_mailbox = $this->mailboxForVisibilityCheck($request, $route_name);

            if ($hidden_mailbox && $this->isHiddenFromAdmins($hidden_mailbox)) {
                if (strpos((string) $route_name, '.ajax') !== false) {
                    $this->abortAjax(__('Mailbox not found'));
                }

                abort(404);
            }
        }

        if ($route_name == 'mailboxes.ajax' && $request->input('action') == 'send_test') {
            $mailbox = $this->findMailbox($request->input('mailbox_id'));

            if ($mailbox && !$this->isSendEnabled($mailbox)) {
                $this->abortAjax(__('Sending is turned off for this mailbox.'));
            }

            return;
        }

        if ($route_name == 'conversations.create' || $route_name == 'conversations.clone_conversation') {
            $mailbox = $this->findMailbox($request->route('mailbox_id'));

            if ($mailbox && !$this->isSendEnabled($mailbox)) {
                \Session::flash('flash_error_floating', __('Sending is turned off for this mailbox.'));

                throw new \Illuminate\Http\Exceptions\HttpResponseException(
                    redirect()->route('mailboxes.view', array('id' => $mailbox->id))
                );
            }

            return;
        }

        if ($route_name == 'conversations.ajax' || $route_name == 'conversations.ajax_html') {
            // ajaxHtml takes the action from the route rather than the body.
            $action = (string) ($request->input('action') ?: $request->route('action'));
            $mailbox = $this->mailboxFromConversationRequest($request);

            if (!$mailbox) {
                return;
            }

            if ($mailbox->isArchived() && in_array($action, self::BLOCKED_ARCHIVED_ACTIONS, true)) {
                $this->abortAjax(__('This mailbox is archived and read only.'));
            }

            if (!$this->isSendEnabled($mailbox) && in_array($action, self::BLOCKED_SEND_ACTIONS, true)) {
                $this->abortAjax(__('Sending is turned off for this mailbox.'));
            }
        }
    }

    /**
     * The mailbox a request would expose, for the visibility check.
     *
     * Conversation ajax names its subject in the body rather than the route,
     * so it needs the conversation resolver; everything else is a route
     * parameter.
     *
     * @param \Illuminate\Http\Request $request
     * @param string|null              $route_name
     *
     * @return \App\Mailbox|null
     */
    protected function mailboxForVisibilityCheck($request, $route_name)
    {
        if ($route_name == 'conversations.ajax' || $route_name == 'conversations.ajax_html') {
            return $this->mailboxFromConversationRequest($request);
        }

        return $this->findMailbox($this->currentMailboxId());
    }

    /**
     * Work out which mailbox a conversation ajax request is about. Bulk
     * actions post an array of conversation ids, so the first one is used.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \App\Mailbox|null
     */
    protected function mailboxFromConversationRequest($request)
    {
        if ($request->filled('mailbox_id')) {
            return $this->findMailbox($request->input('mailbox_id'));
        }

        $conversation_id = $request->input('conversation_id');

        if (is_array($conversation_id)) {
            $conversation_id = reset($conversation_id);
        }

        $conversation_id = (int) $conversation_id;
        if (!$conversation_id) {
            return null;
        }

        $conversation = \App\Conversation::find($conversation_id);

        return $conversation ? $this->findMailbox($conversation->mailbox_id) : null;
    }

    /**
     * Stop the request with the status/msg JSON shape core's ajax JS expects.
     *
     * @param string $message
     *
     * @return void
     */
    protected function abortAjax($message)
    {
        throw new \Illuminate\Http\Exceptions\HttpResponseException(
            response()->json(array('status' => 'error', 'msg' => $message))
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Conversation actions
    |--------------------------------------------------------------------------
    */

    /**
     * An archived mailbox is read only apart from moving a conversation out of
     * it, which is the one thing an archive genuinely needs: last year's box is
     * closed, but this one thread is still live. Delete stays for admins.
     *
     * A mailbox that is merely not allowed to send keeps everything except
     * Reply and Forward.
     *
     * @param array             $actions
     * @param \App\Conversation $conversation
     * @param \App\User         $user
     * @param \App\Mailbox      $mailbox
     *
     * @return array
     */
    public function filterActionButtons($actions, $conversation, $user, $mailbox)
    {
        if (!$mailbox) {
            return $actions;
        }

        if ($mailbox->isArchived()) {
            $keep = array('move', 'print');

            if ($user && $user->isAdmin()) {
                $keep[] = 'delete';
                $keep[] = 'delete_mobile';
            }

            foreach (array_keys($actions) as $key) {
                if (!in_array($key, $keep, true)) {
                    unset($actions[$key]);
                }
            }

            return $actions;
        }

        if (!$this->isSendEnabled($mailbox)) {
            unset($actions['reply'], $actions['forward']);
        }

        return $actions;
    }

    /**
     * Core decides whether to build the reply entry before the action array
     * exists, so it needs its own answer.
     *
     * @param bool              $enabled
     * @param \App\Conversation $conversation
     *
     * @return bool
     */
    public function filterReplyButton($enabled, $conversation)
    {
        if (!$enabled || !$conversation) {
            return $enabled;
        }

        return $this->isSendEnabled($this->findMailbox($conversation->mailbox_id));
    }

    /**
     * Notes survive a send-disabled mailbox but not an archived one.
     *
     * @param bool              $enabled
     * @param \App\Conversation $conversation
     *
     * @return bool
     */
    public function filterNoteButton($enabled, $conversation)
    {
        if (!$enabled || !$conversation) {
            return $enabled;
        }

        $mailbox = $this->findMailbox($conversation->mailbox_id);

        return !($mailbox && $mailbox->isArchived());
    }

    /**
     * Nothing can be moved into an archived mailbox.
     *
     * values() is not optional: layouts/app.blade.php does
     * "count($mailboxes) == 1" and then "$mailboxes[0]", and reject() keeps
     * the original keys.
     *
     * @param mixed $mailboxes
     *
     * @return mixed
     */
    public function filterMoveMailboxes($mailboxes)
    {
        if (!$mailboxes || is_array($mailboxes)) {
            return Mailbox::excludeArchived($mailboxes);
        }

        return Mailbox::excludeArchived($mailboxes)->values();
    }

    /*
    |--------------------------------------------------------------------------
    | Hidden from administrators too
    |--------------------------------------------------------------------------
    */

    /**
     * Remove hidden mailboxes from the top navigation menu.
     *
     * @param mixed $mailboxes
     *
     * @return mixed
     */
    public function filterMenuMailboxes($mailboxes)
    {
        return $this->rejectHidden($mailboxes);
    }

    /**
     * Remove hidden mailboxes from the dashboard.
     *
     * @param mixed $mailboxes
     *
     * @return mixed
     */
    public function filterDashboardMailboxes($mailboxes)
    {
        return $this->rejectHidden($mailboxes);
    }

    /**
     * Keep conversations from hidden mailboxes out of search results.
     *
     * Non-administrators are already excluded by core, because the search is
     * scoped to mailboxesIdsCanView() and archived mailboxes are not in it.
     *
     * @param mixed  $query
     * @param array  $filters
     * @param string $q
     *
     * @return mixed
     */
    public function filterSearchConversations($query, $filters = array(), $q = '')
    {
        $ids = $this->hiddenMailboxIds();

        if (count($ids) && $query) {
            $query->whereNotIn('conversations.mailbox_id', $ids);
        }

        return $query;
    }

    /*
    |--------------------------------------------------------------------------
    | Cosmetics
    |--------------------------------------------------------------------------
    */

    /**
     * Hide what core still renders for a mailbox that cannot send: the reply
     * form block and the New Conversation buttons. Core gates the dashboard
     * one on isConnected() rather than isArchived(), so it is still offered on
     * an archived card.
     *
     * Injected inline rather than as a published module asset so it works even
     * before the module's public symlink exists, matching how core itself
     * guards against that in app.blade.php. Inline styles are fine under
     * FreeScout's CSP, which allows 'unsafe-inline' for style-src but not for
     * script-src (see renderSettings()).
     *
     * @return void
     */
    public function printStyles()
    {
        $ids = $this->sendDisabledMailboxIds();

        if (!count($ids)) {
            return;
        }

        $rules = array();

        // route('conversations.create') is /mailbox/{mailbox_id}/new-ticket.
        // Hidden everywhere, since the dashboard lists every mailbox at once.
        foreach ($ids as $id) {
            $rules[] = 'a[href$="/mailbox/'.$id.'/new-ticket"] { display: none !important; }';
        }

        // The rest only applies to the mailbox whose page is being rendered.
        $current_id = $this->currentMailboxId();

        if ($current_id && in_array($current_id, $ids, true)) {
            $rules[] = '.conv-reply-block { display: none !important; }';

            // Hiding the New Conversation link leaves the settings button with
            // a square, border-less right edge, because core rounds the two
            // sidebar buttons structurally rather than by position:
            //   .sidebar-buttons .btn-group a.btn { border-radius: 14px 0 0 14px; }
            //   .sidebar-buttons > a.btn          { border-radius: 0 14px 14px 0; }
            // and .sidebar-buttons .btn-group .btn drops border-right, relying
            // on the neighbour we just hid to supply that edge.
            //
            // !important is not optional: layout.head fires at
            // layouts/app.blade.php:21 but the stylesheets load at :33, and
            // core's own comment there says style.css must come last so it
            // can redefine styles. Our selector has the same specificity as
            // core's, so without it core simply wins on source order.
            $rules[] = '.sidebar-buttons .btn-group a.btn { border-radius: 14px !important; border-right-style: solid !important; border-right-width: 1px !important; }';
        }

        echo '<style>'."\n".implode("\n", $rules)."\n".'</style>'."\n";
    }

    /**
     * The mailbox the page being rendered belongs to, or 0.
     *
     * Covers the mailbox screens (route parameter "id"), the screens that name
     * the mailbox directly ("mailbox_id"), and a conversation, which only
     * knows its mailbox through the database.
     *
     * @return int
     */
    protected function currentMailboxId()
    {
        $request = request();

        if (!$request || !$request->route()) {
            return 0;
        }

        $mailbox_id = (int) $request->route('mailbox_id');

        if ($mailbox_id) {
            return $mailbox_id;
        }

        $route_name = \Route::currentRouteName();
        $id = (int) $request->route('id');

        if (!$id) {
            return 0;
        }

        if (strpos((string) $route_name, 'mailboxes.') === 0) {
            return $id;
        }

        if ($route_name == 'conversations.view') {
            $conversation = \App\Conversation::find($id);

            return $conversation ? (int) $conversation->mailbox_id : 0;
        }

        return 0;
    }

    /**
     * Ids of every mailbox that cannot send, memoised per request.
     *
     * @return int[]
     */
    protected function sendDisabledMailboxIds()
    {
        $ids = array();

        foreach ($this->sendDisabledMailboxes() as $mailbox) {
            $ids[] = (int) $mailbox->id;
        }

        return $ids;
    }

    /**
     * Replace core's "Receiving emails need to be configured" warning with
     * something true when our switch is the reason for it.
     *
     * @param array $flashes
     *
     * @return array
     */
    public function filterFlashes($flashes)
    {
        if (!is_array($flashes) || !count($flashes)) {
            return $flashes;
        }

        $mailbox = $this->findMailbox(request()->route('id'));

        if (!$mailbox || $this->isFetchEnabled($mailbox)) {
            return $flashes;
        }

        // Core warns that receiving "needs to be configured" whenever the
        // mailbox reports as not fetching, which is misleading when that is
        // exactly what was asked for.
        $replacement = $mailbox->isArchived()
            ? __('This mailbox is archived, so it does not check for new email.')
            : __('Fetching is turned off for this mailbox.');

        $needle = route('mailboxes.connection.incoming', array('id' => $mailbox->id));

        foreach ($flashes as $i => $flash) {
            if (!empty($flash['text']) && strpos($flash['text'], $needle) !== false) {
                $flashes[$i] = array(
                    'type'      => 'info',
                    'text'      => $replacement,
                    'unescaped' => true,
                );
            }
        }

        return $flashes;
    }
}
