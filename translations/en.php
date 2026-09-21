<?php

/**
 * The base catalogue. Every other locale is measured against this file, and
 * `php bin/console i18n:check` fails if one drifts from it.
 *
 * Keys are flat and dotted, grouped by where the string appears. Messages are
 * ICU MessageFormat: anything in braces is an argument, and a count that reads
 * differently in the singular takes a `plural` block so that a language with
 * more than two forms can say all of them. Adding a language means copying
 * this file to `translations/<locale>.php` and translating the right-hand
 * side — see the README.
 *
 * Keys beginning `js.` are handed to the browser by `js_translations()` in the
 * layout. They live here rather than in a JavaScript file so that one check
 * covers every string the application can show.
 */

declare(strict_types=1);

return [
    // -----------------------------------------------------------------------
    // Shared vocabulary
    // -----------------------------------------------------------------------
    'account.choose_picture' => 'Choose a picture',
    'account.confirm_password' => 'Confirm new password',
    'account.current_password' => 'Current password',
    'account.email_cancel' => 'Cancel that change',
    'account.email_current' => 'You sign in as {email}.',
    'account.email_hint' =>
        'We will send a link to the new address. Nothing changes until you follow it, so a typo '
        . 'cannot lock you out.',
    'account.email_new' => 'New email address',
    'account.email_none' =>
        'This account has no mailbox of its own. An Owner of your household can give it an address.',
    'account.email_pending' => 'Waiting for {email} to be confirmed.',
    'account.email_request' => 'Send confirmation link',
    'account.must_change_heading' => 'Choose a password of your own',
    'account.must_change_note' =>
        'You are signed in with a password somebody else chose for you. Set one of your own below '
        . 'and the rest of Renovo opens up.',
    'account.new_password' => 'New password',
    'account.picture_hint' =>
        'PNG, JPEG, WebP or GIF, up to {kilobytes} KB. It is cropped square and resized, and the '
        . 'original is not kept.',
    'account.remove_picture' => 'Remove picture',
    'account.save_name' => 'Save name',
    'account.save_password' => 'Change password',
    'account.save_picture' => 'Save picture',
    'account.sign_out_others' => 'Sign out my other devices',
    'account.sign_out_others_hint' =>
        'Leave this on unless you meant somebody else to stay signed in. This device stays signed '
        . 'in either way.',
    'account.title' => 'Your account',
    'account.who_you_are' => 'Who you are',
    'account.your_email' => 'Your email address',
    'account.your_password' => 'Your password',
    'account.your_picture' => 'Your picture',
    'audit_action.account.avatar_changed' => 'Picture changed',
    'audit_action.account.avatar_removed' => 'Picture removed',
    'audit_action.account.email_change_requested' => 'Email change requested',
    'audit_action.account.email_changed' => 'Email address changed',
    'audit_action.account.name_changed' => 'Name changed',
    'audit_action.member.added' => 'Member added',
    'audit_action.member.invite_resent' => 'Invitation resent',
    'audit_action.member.login_restored' => 'Login restored',
    'audit_action.member.login_revoked' => 'Login revoked',
    'audit_action.member.password_reset_sent' => 'Password reset sent',
    'audit_action.member.removed' => 'Member removed from household',
    'audit_action.member.temporary_password_issued' => 'Temporary password issued',
    'auth.back_to_sign_in' => 'Back to sign in',
    'auth_email_change_failed.back' => 'Back to your account',
    'auth_email_change_failed.intro' =>
        'Confirmation links are valid for one hour and can be used once. Your address has not been '
        . 'changed.',
    'auth_email_change_failed.title' => 'That link did not work',
    'auth_invite.choose_password' => 'Choose a password',
    'auth_invite.confirm_password' => 'Confirm password',
    'auth_invite.intro' =>
        'Your account is nearly ready. Choose a password and it is yours — nobody else, including '
        . 'whoever invited you, ever sees it.',
    'auth_invite.join' => 'Set password and join',
    'auth_invite.title' => 'Join the household',
    'auth_invite_expired.intro' =>
        'Invitations are valid for seven days, and each one can be used once. Ask whoever invited '
        . 'you to send another.',
    'auth_invite_expired.title' => 'This invitation has expired',
    'common.none_symbol' => '—',

    // -----------------------------------------------------------------------
    // Domain vocabulary — the enum labels. The keys are the ones the enums
    // themselves return from labelKey().
    // -----------------------------------------------------------------------
    'cycle.weekly' => 'Weekly',
    'cycle.monthly' => 'Monthly',
    'cycle.quarterly' => 'Quarterly',
    'cycle.yearly' => 'Yearly',
    'cycle.custom' => 'Custom',
    'cycle.custom_days' => 'Every {days, plural, one {# day} other {# days}}',

    'error.auth.disabled' =>
        'This account has been closed by an administrator of your household. Ask them to restore it.',
    'error.avatar.dimensions' => 'That image is too large to work with. Try a smaller one.',
    'error.avatar.too_large' => 'A picture must be {kilobytes} KB or smaller.',
    'error.avatar.type' => 'That file is not a PNG, JPEG, WebP or GIF image.',
    'error.email.no_mailbox' =>
        'This account has no mailbox of its own, so it cannot confirm a new address. An Owner of your '
        . 'household can change it for you.',
    'error.email.unchanged' => 'That is already your email address.',
    'error.email_change.invalid_token' =>
        'That confirmation link has expired or has already been used.',
    'error.invite.invalid_token' => 'That invitation has expired or has already been used.',
    'error.member.last_owner' =>
        'This is the household\'s last Owner. Make somebody else an Owner first — a household with '
        . 'nobody able to administer it cannot be put right from inside.',
    'error.member.no_mailbox' => 'That member has no email address, so nothing can be sent to them.',
    'error.member.not_found' => 'That person is not a member of this household.',
    'error.member.not_your_own_role' =>
        'You cannot change your own role. Ask another Owner to change it for you.',
    'error.member.not_yourself' => 'You cannot do that to your own account.',
    'error.member.role_invalid' => 'Choose a role from the list.',
    'flash.avatar_missing' => 'There was no picture to remove.',
    'flash.avatar_removed' => 'Your picture has been removed.',
    'flash.avatar_saved' => 'Your picture has been saved.',
    'flash.email_change_cancelled' => 'The pending email change has been cancelled.',
    'flash.email_change_confirmed' =>
        'Your email address has been changed. Use the new one to sign in from now on.',
    'flash.email_change_requested' =>
        'Check the new address for a confirmation link. Until you follow it, your current address '
        . 'stays your sign-in.',
    'flash.invite_accepted' => 'Your account is ready. Sign in with the password you just chose.',
    'flash.member_added_temporary' => 'The account has been created. Give them the password below.',
    'flash.member_invite_resent' => 'The invitation has been sent again.',
    'flash.member_invited' => 'An invitation is on its way.',
    'flash.member_removed' => 'They are no longer a member of this household.',
    'flash.member_reset_sent' => 'A password reset link has been sent to them.',
    'flash.member_restored' => 'They can sign in again.',
    'flash.member_revoked' =>
        'Their login has been revoked and they have been signed out everywhere.',
    'flash.member_role_changed' => 'Their role has been changed.',
    'flash.name_saved' => 'Your name has been saved.',
    'flash.password_changed_sessions' =>
        'Your password has been changed, and {count, plural, one {# other session was} other {# other sessions were}} signed out.',
    'mail.email_change.body' =>
        'Hello {name},\n\nConfirm that you can read mail at this address, and it becomes the one '
        . 'you sign in with:\n\n{link}\n\nThe link is valid for one hour. Until you follow it, '
        . 'nothing changes. If you did not ask for this, you can ignore this message.',
    'mail.email_change.subject' => 'Confirm your new {instance} address',
    'mail.email_change_notice.body' =>
        'Hello {name},\n\nSomebody asked to move your {instance} account to {new_email}. Nothing '
        . 'has changed yet: this address is still your sign-in until the new one is '
        . 'confirmed.\n\nIf that was not you, sign in and change your password now.',
    'mail.email_change_notice.subject' => 'A change of address was requested on {instance}',
    'mail.invite.body' =>
        'Hello {name},\n\n{inviter} has added you to their household on {instance}, a shared '
        . 'subscription tracker.\n\nChoose a password and you are in:\n\n{link}\n\nThe link is '
        . 'valid for seven days. If you were not expecting this, you can ignore this message.',
    'mail.invite.subject' => '{inviter} has invited you to {instance}',
    'members.actions' => 'Actions',
    'members.add_button' => 'Add member',
    'members.add_heading' => 'Add someone',
    'members.add_intro' =>
        'They get an invitation and choose their own password — you never see it. A member with no '
        . 'mailbox of their own is the one exception; tick the box below for that.',
    'members.change_role' => 'Change',
    'members.email_hint' => 'Where their invitation goes. Leave it empty if they have no address.',
    'members.intro' =>
        'Everybody who shares this household, what they may do in it, and whether they have arrived '
        . 'yet.',
    'members.last_seen' => 'Last seen',
    'members.never_signed_in' => 'Never',
    'members.no_mailbox' => 'No email address',
    'members.remove' => 'Remove',
    'members.remove_account_kept' =>
        'Their account itself is not deleted — this removes their membership of this household.',
    'members.remove_confirm' => 'Remove from household',
    'members.remove_data_delete' =>
        'Delete them, along with their price history, attachments and budgets. This cannot be undone.',
    'members.remove_data_legend' =>
        'What should happen to the {count, plural, one {# subscription} other {# subscriptions}} they '
        . 'own?',
    'members.remove_data_reassign' => 'Give them to me. Nothing is lost, and I become their owner.',
    'members.remove_data_shared' =>
        'Anything they own stays with the household and becomes yours. Their share of any split cost '
        . 'is removed.',
    'members.remove_heading' => 'Remove {name}?',
    'members.remove_intro' =>
        '{name} will lose access to this household immediately, and will be signed out everywhere.',
    'members.resend_invite' => 'Resend invitation',
    'members.restore_login' => 'Restore login',
    'members.revoke_login' => 'Revoke login',
    'members.send_reset' => 'Send password reset',
    'members.temporary_password_heading' => 'Their temporary password',
    'members.temporary_password_note' =>
        'Give this to {name}. They will be asked to replace it as soon as they sign in.',
    'members.temporary_password_once' =>
        'This is the only time it is shown. Renovo keeps only a hash of it, so it cannot be looked '
        . 'up again — if it is lost, add nothing and simply issue a new one.',
    'members.this_household' => 'This household',
    'members.title' => 'Household members',
    'members.without_email' => 'This member has no email address',
    'members.without_email_hint' =>
        'For a child with no mailbox. Renovo creates the account with a one-time password shown to '
        . 'you once, which they must replace the first time they sign in.',
    'membership_status.active' => 'Active',
    'membership_status.pending' => 'Invited',
    'membership_status.revoked' => 'Revoked',
    'settings.manage_members' => 'Manage members',
    'type.recurring' => 'Recurring',
    'type.one_off' => 'One-off',
    'type.lifetime' => 'Lifetime',

    'role.owner_admin' => 'Owner / Admin',
    'role.editor' => 'Editor',
    'role.viewer' => 'Viewer',

    'isolation.shared' => 'Shared — everyone in a household sees its subscriptions',
    'isolation.isolated' => 'Isolated — each member sees only their own',

    'price_change.initial' => 'Starting price',
    'price_change.manual' => 'Price changed',
    'price_change.scheduled' => 'Scheduled change',
    'price_change.trial_conversion' => 'Free trial ended',
    'price_change.currency_change' => 'Currency converted',
    'price_change.unknown' => 'Price',

    'split.none' => 'Not split',
    'split.equal' => 'Split equally',
    'split.custom' => 'Split by share',

    'budget_period.monthly' => 'Next month',
    'budget_period.annual' => 'Next 12 months',

    'notice.none' => 'None',
    'notice.days' => '{count, plural, one {# day} other {# days}}',
    'notice.weeks' => '{count, plural, one {# week} other {# weeks}}',
    'notice.months' => '{count, plural, one {# month} other {# months}}',

    'alert.renewal' => 'Upcoming renewal',
    'alert.trial_conversion' => 'Trial about to convert',
    'alert.cancel_by' => 'Cancellation deadline',
    'alert.budget_exceeded' => 'Budget projected to be exceeded',

    // -----------------------------------------------------------------------
    // Display preferences
    // -----------------------------------------------------------------------
    'settings.appearance' => 'Appearance',
    'settings.theme' => 'Theme',
    'settings.language' => 'Language',
    'settings.language_instance_default' => 'Whatever this instance is set to',
    'settings.week_start' => 'Weeks start on',
    'settings.week_start_hint' => 'Used by the calendar.',
    'settings.density' => 'List density',
    'settings.landing_view' => 'Open on',
    'settings.landing_view_hint' => 'The page you see when you open Renovo.',

    'theme.system' => 'Match my system',
    'theme.light' => 'Light',
    'theme.dark' => 'Dark',

    'density.comfortable' => 'Comfortable',
    'density.compact' => 'Compact',

    'week_start.sunday' => 'Sunday',
    'week_start.monday' => 'Monday',

    // -----------------------------------------------------------------------
    // Error pages and the API's error envelope
    // -----------------------------------------------------------------------
    'error.page.unexpected_title' => 'Something went wrong',
    'error.page.unexpected_message' => 'An unexpected error occurred. The details have been logged.',
    'error.page.validation_title' => 'Validation failed',
    'error.page.validation_message' => 'The submitted data is not valid.',
    'error.page.not_found_title' => 'Not found',
    'error.page.not_found_message' => 'That page does not exist.',
    'error.page.not_found_or_forbidden' => 'That page does not exist, or you do not have access to it.',
    'error.page.forbidden_title' => 'Not allowed',
    'error.page.forbidden_message' => 'Your role does not permit that action.',
    'error.page.method_title' => 'Method not allowed',
    'error.page.method_message' => 'That address does not accept this kind of request.',

    // -----------------------------------------------------------------------
    // Validation messages. Raised by services as keys and resolved by whoever
    // is rendering — a Twig form, or the API's error envelope.
    // -----------------------------------------------------------------------
    'error.subscription.not_found' => 'That subscription does not exist.',
    'error.currency.required' => 'Choose a currency.',
    'error.member.not_in_household' => 'Choose a member of this household.',
    'error.member.required' => 'Choose a member.',
    'error.category.not_found' => 'That category does not exist.',
    'error.price.negative' => 'Enter a price of zero or more.',
    'error.price.invalid' => 'Enter a price, for example 9.99.',
    'error.amount.negative' => 'Enter an amount of zero or more.',
    'error.amount.invalid' => 'Enter an amount, for example 150.00.',
    'error.email.invalid' => 'Enter a valid email address.',
    'error.email.taken' => 'An account with that email address already exists.',
    'error.name.required' => 'Enter a name.',
    'error.name.required_yours' => 'Enter your name.',
    'error.name.too_long_60' => 'Name must be 60 characters or fewer.',
    'error.name.too_long_100' => 'Name must be 100 characters or fewer.',
    'error.name.too_long_150' => 'Name must be 150 characters or fewer.',
    'error.note.too_long_255' => 'Note must be 255 characters or fewer.',
    'error.notes.too_long_5000' => 'Notes must be 5000 characters or fewer.',
    'error.date.invalid' => 'Enter a valid date.',
    'error.file.required' => 'Choose a file to upload.',
    'error.upload.failed' => 'The file could not be uploaded. Try again.',

    'error.password.incorrect' => 'That password is not correct.',
    'error.password.too_long' => 'That password is too long.',
    'error.password.mismatch' => 'The two passwords do not match.',
    'error.password.too_short' =>
        'Use at least {count, plural, one {# character} other {# characters}}.',
    'error.recovery_code.invalid' => 'That recovery code is not valid, or has already been used.',
    'error.totp.code_wrong' => 'That code is not correct.',
    'error.auth.credentials' => 'Those credentials are not correct.',
    'error.auth.unverified' => 'Confirm your email address before signing in. Check your inbox for the link.',
    'error.auth.throttled' =>
        'Too many attempts. Try again in {minutes, plural, one {# minute} other {# minutes}}.',
    'error.reset.throttled' =>
        'Too many reset requests. Try again in {minutes, plural, one {# minute} other {# minutes}}.',
    'error.reset.invalid_token' => 'That reset link has expired or has already been used.',

    'error.totp.no_pending' => 'Start the setup again — no pending enrolment was found.',
    'error.totp.already_set_up' => 'An authenticator app is already set up for this account.',
    'error.totp.code_incorrect' => 'That code is not correct. Check the clock on your device.',
    'error.two_factor.none_set_up' =>
        'There is no second factor set up for this account, so there is nothing to recover.',
    'error.passkey.not_registration' => 'That is not a registration response.',
    'error.passkey.registration_failed' => 'That security key could not be registered: {reason}',
    'error.passkey.verification_failed' => 'That key could not be verified: {reason}',
    'error.passkey.already_registered' => 'That key is already registered.',
    'error.passkey.not_authentication' => 'That is not an authentication response.',
    'error.passkey.unknown' => 'That key is not registered on this instance.',
    'error.passkey.other_account' => 'That key belongs to a different account.',
    'error.passkey.missing' => 'That passkey no longer exists.',
    'error.passkey.unreadable' => 'The browser sent a response we could not read.',

    'error.url.required' => 'Enter a URL.',
    'error.website.too_long' => 'That address is too long.',
    'error.url.invalid' => 'Enter a valid URL, including https://.',
    'error.url.scheme' => 'The URL must start with https:// or http://.',
    'error.url.no_host' => 'The URL is missing a host name.',
    'error.gotify.token_required' => 'Enter the Gotify application token.',
    'error.gotify.priority_range' => 'Enter a priority between 0 and 10.',
    'error.slack.token_required' => 'Enter the Slack bot token.',
    'error.slack.channel_required' => 'Enter the channel or user to message.',
    'error.slack.channel_invalid' => 'That does not look like a channel or user id.',
    'error.discord.https_required' => 'A Discord webhook URL must start with https://.',
    'error.discord.url_invalid' =>
        'That is not a Discord webhook URL. Copy it from Server Settings → Integrations → Webhooks.',
    'error.mattermost.url_invalid' =>
        'That is not a Mattermost webhook URL — it should contain /hooks/.',
    'error.mattermost.channel_invalid' => 'Use a channel name such as bills, or @username for a direct message.',
    'error.ntfy.topic_required' => 'Enter the topic to publish to.',
    'error.ntfy.topic_invalid' => 'A topic may use letters, numbers, dashes and underscores only.',
    'error.ntfy.priority_range' => 'Enter a priority between 1 and 5.',
    'error.ntfy.tags_invalid' => 'Enter tags separated by commas, using letters, numbers, dashes and underscores.',
    'error.pushover.token_required' => 'Enter the Pushover application token.',
    'error.pushover.token_invalid' => 'A Pushover application token is 30 letters and numbers.',
    'error.pushover.user_key_required' => 'Enter your Pushover user or group key.',
    'error.pushover.user_key_invalid' => 'A Pushover user key is 30 letters and numbers.',
    'error.pushover.priority_range' =>
        'Enter a priority between -2 and 1. Emergency priority is not supported.',
    'error.pushplus.token_required' => 'Enter the Pushplus token.',
    'error.pushplus.token_invalid' => 'A Pushplus token is 32 characters.',
    'error.pushplus.topic_invalid' => 'That topic code is too long.',
    'error.serverchan.sendkey_required' => 'Enter your Server酱 SendKey.',
    'error.serverchan.sendkey_invalid' => 'A Turbo SendKey starts with SCT followed by letters and numbers.',
    'error.telegram.token_required' => 'Enter the Telegram bot token.',
    'error.telegram.token_invalid' => 'A bot token looks like 123456789:AA... — copy it from BotFather.',
    'error.telegram.chat_id_required' => 'Enter the chat to message.',
    'error.telegram.chat_id_invalid' =>
        'Use a numeric chat id, or @name for a public channel.',
    'error.channel.type_required' => 'Choose a channel type.',
    'error.channel.missing' => 'That channel no longer exists.',
    'error.channel.type_unavailable' => 'That channel type is no longer available.',
    'error.digest.weekday' => 'Choose a day of the week.',
    'error.demo.read_only' =>
        'This instance is a read-only demonstration. Nothing here can be changed.',
    'error.digest.monthday' => 'Choose a day of the month between 1 and 28.',
    'error.lead_days.invalid' => 'Enter whole numbers of days, separated by commas — for example 30, 7, 1.',
    'error.lead_days.too_many' =>
        'Use at most {max, plural, one {# reminder} other {# reminders}} per subscription.',

    'error.token.name_required' => 'Give the token a name so you can recognise it later.',
    'error.token.expiry_past' => 'Choose an expiry date in the future.',
    'error.token.not_found' => 'That token no longer exists.',
    'error.token.not_reissuable' =>
        'Only a token that still works can be reissued. This one has been revoked or has expired — '
        . 'create a new one instead.',

    'error.attachment.type' => 'Upload a PDF, PNG, JPEG, WebP or GIF file.',
    'error.attachment.archive_type' => 'The archive contains a file of an unsupported type.',
    'error.attachment.too_large' => 'The file must be {megabytes} MB or smaller.',
    'error.logo.upload_failed' => 'The logo could not be uploaded. Try again.',
    'error.logo.too_large' => 'The logo must be {kilobytes} KB or smaller.',
    'error.logo.type' => 'Upload a PNG, JPEG, GIF or WebP image.',

    'error.backup.not_zip' => 'That file is not a readable ZIP archive.',
    'error.backup.foreign' => 'That archive was not produced by Renovo.',
    'error.backup.too_many_files' => 'That archive contains too many files.',
    'error.backup.unexpected_file' => 'That archive contains an unexpected file and was not restored.',
    'error.backup.missing_entry' => 'That archive has no {entry}.',
    'error.backup.invalid_json' => 'The {entry} in that archive is not valid JSON.',

    'error.import.empty' => 'That file is empty.',
    'error.import.unreadable' => 'That file could not be read.',
    'error.import.no_header' => 'That file has no header row.',
    'error.import.invalid_json' => 'That file is not valid JSON.',
    'error.import.no_subscriptions' => 'That file contains no subscriptions.',
    'error.import.file_required' => 'Choose a CSV or JSON file to import.',
    'error.import.too_large' => 'The file must be {megabytes} MB or smaller.',
    'error.import.too_large_absolute' => 'That file is too large to import.',
    'error.import.expired' => 'That upload has expired. Upload the file again.',
    'error.import.too_many_rows' => 'That file has more than {max} rows. Split it and import the parts separately.',
    'error.import.too_many_entries' =>
        'That file has more than {max} entries. Split it and import the parts separately.',

    'error.budget.name_required' => 'Give the budget a name.',
    'error.budget.period' => 'Choose monthly or annual.',
    'error.budget.threshold_range' => 'Enter a warning threshold between 1 and 100 percent.',

    'error.bulk.no_subscriptions' => 'Choose at least one subscription.',
    'error.bulk.no_action' => 'Choose an action to apply.',
    'error.bulk.isolated_reassign' =>
        "Ownership cannot be reassigned while the instance keeps members' data separate.",
    'error.bulk.no_rate' =>
        'No exchange rate is available between {from} and {to}, so "{name}" cannot be converted.',
    'error.tag.required' => 'Enter a tag.',

    'error.category.name_required' => 'Enter a category name.',
    'error.category.duplicate' => 'A category with that name already exists.',
    'error.category.colour_required' => 'Choose a colour.',

    'error.price_change.date_required' => 'Enter the date the new price starts.',
    'error.price_change.date_past' => 'Choose a future date. To change the price now, edit the subscription.',

    'error.saved_view.name_required' => 'Give the view a name so you can recognise it later.',
    'error.saved_view.too_many' => 'You already have {max} saved views. Forget one before saving another.',
    'error.split.owner_only' => 'Only the member who owns this subscription can change how it is split.',
    'error.split.share_range' => 'Shares must be between 0 and 10000.',
    'error.split.no_members' => 'Choose at least one member to share the cost.',

    'error.trial.end_date_required' => 'Enter the date the trial ends.',
    'error.trial.converts_to_invalid' => 'Enter the price it converts to, for example 9.99.',
    'error.cycle.required' => 'Choose a billing cycle.',
    'error.cycle_days.range' => 'Enter the number of days between payments (1–3650).',
    'error.reminder_days.invalid' => 'Enter days as whole numbers, for example 30, 7, 1 — or "none".',
    'error.reminder_days.too_many' => 'Use at most six reminders for one subscription.',
    'error.next_payment.required' => 'Enter the next payment date.',
    'error.notice.range' => 'Enter a notice period between 0 and 3650.',
    'error.notice.unit_required' => 'Choose a notice period unit.',

    'error.trusted_host.required' => 'Enter a host name, address or CIDR range.',
    'error.trusted_host.too_long' => 'That is too long to be a host or range.',
    'error.trusted_host.invalid' =>
        'Enter a host name (gotify.lan), a suffix (.lan), an address (192.168.1.10) or a range (100.64.0.0/10).',
    'error.trusted_host.duplicate' => 'That is already on the list.',

    // -----------------------------------------------------------------------
    // Flash messages. Queued by the request that acted and rendered by the
    // next one, so what is stored is the key and its arguments.
    // -----------------------------------------------------------------------
    'flash.raw' => '{message}',
    'flash.welcome_back' => 'Welcome back, {name}.',
    'flash.signed_out' => 'You have been signed out.',
    'flash.email_confirmed' => 'Your email address is confirmed. You can sign in now.',
    'flash.password_changed' => 'Your password has been changed. Sign in with it now.',
    'flash.two_factor_expired' => 'That sign-in attempt expired. Start again.',
    'flash.preferences_saved' => 'Your preferences have been saved.',

    'flash.subscription_added' => 'Subscription added.',
    'flash.subscription_saved' => 'Subscription saved.',
    'flash.subscription_deleted' => 'Subscription deleted.',
    'flash.subscription_paused' => 'Subscription paused.',
    'flash.subscription_resumed' => 'Subscription resumed.',
    'flash.bulk_updated' => '{count, plural, one {# subscription} other {# subscriptions}} updated.',
    'flash.price_change_scheduled' => 'Price change scheduled.',
    'flash.split_updated' => 'Cost split updated.',
    'flash.usage_reset' => 'Usage count reset.',

    'flash.budget_created' => 'Budget created.',
    'flash.budget_saved' => 'Budget saved.',
    'flash.budget_deleted' => 'Budget deleted.',

    'flash.category_added' => 'Category added.',
    'flash.category_renamed' => 'Category renamed.',
    'flash.category_deleted' => 'Category deleted.',
    'flash.tag_deleted' => 'Tag deleted.',

    'flash.channel_added' => 'Notification channel added. Send a test message to check it works.',
    'flash.channel_updated' => 'Channel updated.',
    'flash.channel_removed' => 'Channel removed.',
    'flash.test_message_sent' => 'Test message sent to {label}.',
    'flash.notification_preferences_saved' => 'Notification preferences saved.',

    'flash.totp_enabled' => 'Two-factor authentication is on. Save your recovery codes now.',
    'flash.totp_disabled' => 'Two-factor authentication is off.',
    'flash.recovery_codes_issued' => 'New recovery codes issued. The old ones no longer work.',
    'flash.passkey_added' => 'Passkey "{name}" added.',
    'flash.passkey_renamed' => 'Passkey renamed.',
    'flash.passkey_revoked' => 'Passkey revoked.',
    'flash.saved_view_created' => 'View saved.',
    'flash.saved_view_deleted' => 'View forgotten.',
    'flash.saved_view_missing' => 'That view no longer exists.',
    'flash.session_revoked' => 'That session was signed out.',
    'flash.session_already_gone' => 'That session is no longer active.',
    'flash.other_sessions_revoked' =>
        '{count, plural, =0 {No other sessions were signed in.} one {# other session signed out.}'
        . ' other {# other sessions signed out.}}',

    'flash.token_created' => 'Token created. Copy it now — it is not shown again.',
    'flash.token_reissued' =>
        'Token reissued. The previous one has stopped working — copy the new one now, it is not shown again.',
    'flash.token_revoked' => 'Token revoked.',
    'flash.token_revoke_failed' => 'That token could not be revoked.',

    'flash.attachment_required' => 'Choose a file to attach.',
    'flash.attachment_uploaded' => 'Attachment uploaded.',
    'flash.attachment_deleted' => 'Attachment deleted.',
    'flash.attachment_missing' => 'No such attachment.',

    'flash.import_finished' => 'Imported {count, plural, one {# subscription} other {# subscriptions}}.',
    'flash.import_skipped' => '{count, plural, one {# row was} other {# rows were}} skipped.',
    'flash.backup_failed' => 'The backup could not be prepared.',
    'flash.backup_file_required' => 'Choose a backup archive to restore.',
    'flash.backup_unreadable' => 'The archive could not be read.',
    'flash.restore_finished' =>
        'Restored {subscriptions, plural, one {# subscription} other {# subscriptions}},'
        . ' {categories, plural, one {# category} other {# categories}},'
        . ' {tags, plural, one {# tag} other {# tags}},'
        . ' {budgets, plural, one {# budget} other {# budgets}}'
        . ' and {attachments, plural, one {# attachment} other {# attachments}}.',
    'flash.restore_skipped' => '{count, plural, one {# item was} other {# items were}} skipped.',

    'flash.household_saved' => 'Household settings saved.',
    'flash.instance_saved' => 'Instance settings saved.',
    'flash.trusted_host_added' => 'Trusted host added. Notifications may now reach it.',
    'flash.trusted_host_removed' => 'Trusted host removed.',

    'flash.setup_ready' => 'Your instance is ready. One more step: where should reminders go?',
    'flash.setup_channel_added' => 'Channel added. Send yourself a test message to confirm it arrives.',
    'flash.setup_finished' => 'All set. Add your first subscription to get started.',

    // -----------------------------------------------------------------------
    // Notifications
    // -----------------------------------------------------------------------
    'digest.section' => '{label}:',
    'digest.title.weekly' =>
        'Weekly subscription summary: {count, plural, one {# thing to know} other {# things to know}}',
    'digest.title.monthly' =>
        'Monthly subscription summary: {count, plural, one {# thing to know} other {# things to know}}',

    'landing.dashboard' => 'Dashboard',
    'landing.subscriptions' => 'Subscriptions',
    'landing.calendar' => 'Calendar',
    'landing.budgets' => 'Budgets',
    'landing.forecast' => 'Forecast',
    'landing.stats' => 'Statistics',

    // -----------------------------------------------------------------------
    // The interface itself, grouped by the page each string appears on.
    // `action.*`, `field.*`, `state.*` and `nav.*` are shared across pages:
    // "Save" is one string in this application, not eleven.
    //
    // `js.*` is the group handed to the browser by js_translations(); those
    // messages take named arguments and never plurals, because the formatter
    // on that side is three lines long and deliberately stays that way.
    // -----------------------------------------------------------------------

    // action
    'action.apply' => 'Apply',
    'action.back_to_sign_in' => 'Back to sign in',
    'action.cancel' => 'Cancel',
    'action.close' => 'Close',
    'action.continue' => 'Continue',
    'action.delete' => 'Delete',
    'action.edit' => 'Edit',
    'action.newer' => 'Newer',
    'action.next' => 'Next',
    'action.older' => 'Older',
    'action.preview' => 'Preview',
    'action.previous' => 'Previous',
    'action.remove' => 'Remove',
    'action.rename' => 'Rename',
    'action.reissue' => 'Reissue',
    'action.revoke' => 'Revoke',
    'action.save' => 'Save',
    'action.send_test' => 'Send test',
    'action.upload' => 'Upload',

    // audit
    'audit.all_events' => 'All events',
    'audit.filter' => 'Filter',
    'audit.retention_note' => 'Entries older than the configured retention period are removed by the nightly prune.',
    'audit.scope_household' => 'Security-relevant events in your household.',
    'audit.scope_instance' => 'Every security-relevant event on this instance.',
    'audit.title' => 'Audit log',

    // audit_action
    'audit_action.api_token.issued' => 'API token issued',
    'audit_action.api_token.revoked' => 'API token revoked',
    'audit_action.attachment.deleted' => 'Attachment deleted',
    'audit_action.attachment.uploaded' => 'Attachment uploaded',
    'audit_action.backup.exported' => 'Backup exported',
    'audit_action.backup.restored' => 'Backup restored',
    'audit_action.data.imported' => 'Data imported',
    'audit_action.email.verified' => 'Email address verified',
    'audit_action.household.updated' => 'Household renamed',
    'audit_action.instance.settings_changed' => 'Instance settings changed',
    'audit_action.login.blocked' => 'Sign-in blocked by throttle',
    'audit_action.login.failed' => 'Failed sign-in',
    'audit_action.login.succeeded' => 'Signed in',
    'audit_action.logout' => 'Signed out',
    'audit_action.membership.role_changed' => 'Household role changed',
    'audit_action.passkey.registered' => 'Passkey added',
    'audit_action.passkey.renamed' => 'Passkey renamed',
    'audit_action.passkey.revoked' => 'Passkey revoked',
    'audit_action.password.changed' => 'Password changed',
    'audit_action.password.reset_requested' => 'Password reset requested',
    'audit_action.session.revoked' => 'Session revoked',
    'audit_action.session.revoked_all' => 'All other sessions revoked',
    'audit_action.totp.disabled' => 'Authenticator app disabled',
    'audit_action.totp.enabled' => 'Authenticator app enabled',
    'audit_action.totp.recovery_code_used' => 'Recovery code used',
    'audit_action.totp.recovery_codes_regenerated' => 'Recovery codes regenerated',
    'audit_action.trusted_host.added' => 'Trusted host added',
    'audit_action.trusted_host.removed' => 'Trusted host removed',
    'audit_action.two_factor.failed' => 'Second factor rejected',
    'audit_action.two_factor.succeeded' => 'Second factor accepted',

    // audit_entries
    'audit_entries.no_events_recorded' => 'No events recorded.',
    'audit_entries.pager' => 'Page {page} of {pages} · {total, plural, one {# entry} other {# entries}}',

    // auth
    'auth.link_expired' => 'Link expired',

    // auth_forgot_password
    'auth_forgot_password.intro' => 'Enter your email address and we will send you a link to set a new password.',
    'auth_forgot_password.send_reset_link' => 'Send reset link',
    'auth_forgot_password.title' => 'Reset your password',

    // auth_forgot_password_sent
    'auth_forgot_password_sent.intro' =>
        'If that address has an account, a reset link is on its way. It is valid for one hour.',
    'auth_forgot_password_sent.title' => 'Check your inbox',

    // auth_login
    'auth_login.forgotten_your_password' => 'Forgotten your password?',
    'auth_login.passkey' => 'Sign in with a passkey',
    'auth_login.title' => 'Sign in',

    // auth_register
    'auth_register.already_have_an_account' => 'Already have an account?',
    'auth_register.create_account' => 'Create account',
    'auth_register.title' => 'Create an account',

    // auth_register_sent
    'auth_register_sent.follow_it' => 'Follow it to finish setting up your account. The link is valid for two days.',
    'auth_register_sent.sent_to' => 'We have sent a confirmation link to',
    'auth_register_sent.title' => 'Confirm your email',

    // auth_reset_expired
    'auth_reset_expired.intro' => 'Reset links last one hour and can only be used once.',
    'auth_reset_expired.request_a_new_link' => 'Request a new link',
    'auth_reset_expired.title' => 'That reset link has expired',

    // auth_reset_password
    'auth_reset_password.change_password' => 'Change password',
    'auth_reset_password.confirm_new_password' => 'Confirm new password',
    'auth_reset_password.new_password' => 'New password',
    'auth_reset_password.title' => 'Choose a new password',

    // auth_two_factor
    'auth_two_factor.cancel' => 'Cancel and sign in as someone else',
    'auth_two_factor.code_label' => 'Code from your authenticator app',
    'auth_two_factor.greeting' => 'Hello {name} — one more step to finish signing in.',
    'auth_two_factor.no_factor' => 'This account has no second factor available. Ask an administrator for help.',
    'auth_two_factor.passkey' => 'Use a passkey or security key',
    'auth_two_factor.recovery_code' => 'Recovery code',
    'auth_two_factor.recovery_toggle' => 'Lost your device? Use a recovery code',
    'auth_two_factor.title' => 'Two-step verification',
    'auth_two_factor.use_recovery_code' => 'Use recovery code',
    'auth_two_factor.verify' => 'Verify',

    // auth_verify_failed
    'auth_verify_failed.intro' =>
        'Confirmation links expire after two days and can only be used once. Sign in to request a new '
        . 'one.',
    'auth_verify_failed.title' => 'That link is no longer valid',

    // backup
    'backup.archive_label' => 'Backup archive (format {version})',
    'backup.download_backup' => 'Download backup',
    'backup.export' => 'Export',
    'backup.export_exclusions' =>
        'Not included: user accounts, passwords, API tokens, the audit log and instance-wide settings. '
        . 'Those belong to the server rather than to this household.',
    'backup.export_intro' =>
        'Downloads a ZIP containing everything this household has: its subscriptions, categories, tags, '
        . 'budgets, logos and attached invoices, as JSON plus the original files. Nothing in it needs a '
        . 'database to read.',
    'backup.export_scope' =>
        'It contains what you can see. If this instance keeps members\' data separate, your export has '
        . 'your own subscriptions and not other members\'.',
    'backup.restore' => 'Restore',
    'backup.restore_is_additive' =>
        'Reads a backup archive back into this household. It adds — nothing is deleted or overwritten, '
        . 'so restoring the same file twice leaves two copies of everything.',
    'backup.restore_matching' =>
        'Members are matched by email address. A subscription whose owner is no longer in this household '
        . 'comes back owned by you, and every file in the archive is re-checked before it is stored.',
    'backup.title' => 'Backup and restore',

    // budgets
    'budgets.alerts_note' =>
        'Over-budget and approaching-budget states are shown here only. Sending an alert about them '
        . 'arrives in a later version.',
    'budgets.all_categories' => 'All categories',
    'budgets.empty' => 'No budgets yet.',
    'budgets.intro' =>
        'A budget measures projected spend, not spend so far: scheduled price rises and trials about to '
        . 'convert are counted before they happen, which is while there is still something you can do '
        . 'about them. Each budget covers its owner\'s own share — their subscriptions, plus their portion '
        . 'of anything split.',
    'budgets.meter_label' => '{percent} percent of the budget projected',
    'budgets.new_budget' => 'New budget',
    'budgets.of_limit' => 'of {limit}',
    'budgets.over' => '{percent}% — over by {amount}.',
    'budgets.remaining' => '{percent}% · {remaining} left.',
    'budgets.set_one_up' => 'Set one up.',
    'budgets.unconvertible' =>
        'Cannot be calculated: no exchange rate is available for {currencies}. Rather than leave that '
        . 'spending out and show a figure that looks comfortable, no figure is shown.',
    'budgets.warning' => '{percent}% — past the {threshold}% warning mark. {remaining} left.',

    // budgets_form
    'budgets_form.delete_budget' => 'Delete budget',
    'budgets_form.edit_title' => 'Edit budget',
    'budgets_form.everything' => 'Everything',
    'budgets_form.limit' => 'Limit',
    'budgets_form.owner_hint' => 'A budget counts only this member\'s own share of what is spent.',
    'budgets_form.period_hint' =>
        'Both are rolling windows measured from today, so the figure is always a complete one. This '
        . 'application tracks what is due rather than what has been paid, so a calendar month would have '
        . 'to leave out whatever was already charged earlier in it.',
    'budgets_form.save_budget' => 'Save budget',
    'budgets_form.threshold_hint' => 'Flag the budget once projected spend reaches this share of the limit.',
    'budgets_form.warn_at_optional' => 'Warn at (optional)',
    'budgets_form.whose_budget' => 'Whose budget',

    // calendar
    'calendar.caption' => 'Renewals and trial conversions in {month}',
    'calendar.cancel_by' => 'Cancel by {date}',
    'calendar.deadlines_note' =>
        'The last day notice can be given to avoid the charge shown. After it, the next charge is due '
        . 'whether the subscription is cancelled or not.',
    'calendar.deadlines_this_month' =>
        '{count, plural, one {# cancellation deadline} other {# cancellation deadlines}} this month',
    'calendar.due_this_month' =>
        '{count, plural, one {# charge} other {# charges}} due this month',
    'calendar.intro' =>
        'Renewals and the day a free trial starts charging, on the dates they actually fall. '
        . 'Scheduled price changes are applied from their own dates, so an amount here is what will '
        . 'be taken rather than what is charged today.',
    'calendar.insight' => 'Calendar insight',
    'calendar.just_mine' => 'Just mine',
    'calendar.month_navigation' => 'Month navigation',
    'calendar.next_month' => 'Next month',
    'calendar.next_up' => 'Next up',
    'calendar.next_up_note' => 'The next charges due, wherever in the year they fall.',
    'calendar.nothing_due' => 'Nothing due this month.',
    'calendar.nothing_upcoming' => 'Nothing due in the next twelve months.',
    'calendar.previous_month' => 'Previous month',
    'calendar.rail' => 'Calendar summary',
    'calendar.trials_ending' =>
        '{count, plural, one {# free trial ends} other {# free trials end}} this month',
    'calendar.trials_starting_note' => 'in new charges once they convert.',

    // cancellations
    'cancellations.days_left' => '{days, plural, one {# day left} other {# days left}}',
    'cancellations.deadline_passed' => 'Deadline passed',
    'cancellations.empty' => 'Nothing with a notice period. Add one to a subscription and it will appear here.',
    'cancellations.intro' =>
        'The date that matters is not the renewal — it is the last day you can still give notice and '
        . 'avoid the next charge. Only subscriptions with a notice period appear here; everything else can '
        . 'be cancelled right up to its renewal date, which the subscriptions list already shows.',
    'cancellations.note' =>
        'Deadlines within {days} days are highlighted. Anything already passed is kept on the list '
        . 'rather than hidden — you have been committed to another period, and that is worth knowing.',
    'cancellations.notice' => 'Notice',
    'cancellations.passed_days' =>
        '{days, plural, one {# day ago} other {# days ago}} — this renewal is already committed.',

    // categories
    'categories.add_category' => 'Add category',
    'categories.category_colour' => 'Category colour',
    'categories.category_name' => 'Category name',
    'categories.colour' => 'Colour',
    'categories.new_category' => 'New category',
    'categories.no_categories_yet' => 'No categories yet.',
    'categories.no_tags_yet' => 'No tags yet.',
    'categories.tags_intro' => 'Tags are created as you type them on a subscription.',
    'categories.title' => 'Categories and tags',

    // confirm — the questions a destructive control asks before it acts. They
    // are read out by the browser's own dialog, which is why they are short
    // sentences rather than labels, and they are here rather than written into
    // an onsubmit attribute so that one check covers every string the
    // application can show.
    'confirm.delete_subscription' => 'Delete {name}?',
    'confirm.delete_category' => 'Delete {name}?',
    'confirm.delete_budget' => 'Delete this budget?',
    'confirm.delete_attachment' => 'Delete this file?',
    'confirm.reset_usage' => 'Reset the usage count to zero?',
    'confirm.restore_backup' => 'Restore this archive into the current household?',
    'confirm.reissue_token' =>
        'Reissue this token? The current one stops working straight away, and anything using it will need '
        . 'the new one.',
    'confirm.revoke_token' => 'Revoke this token? Anything using it stops working straight away.',

    // dashboard
    'dashboard.active_subscriptions' => 'Active subscriptions',
    'dashboard.by_category' => 'By category',
    'dashboard.chart_unconvertible' =>
        'The twelve-month chart is not drawn, because no exchange rate is available for {currencies} and '
        . 'a month missing one of its currencies would be drawn as a cheap month rather than an unknown '
        . 'one. The forecast shows those months per currency.',
    'dashboard.combined_in' => 'Combined · {currency}',
    'dashboard.coming_soon' => 'Coming soon',
    'dashboard.coming_soon_note' => 'Next payments in the following {days} days',
    'dashboard.days_away' =>
        '{days, plural, one {# day} other {# days}}',
    'dashboard.combined_note' =>
        '{amount} per year, converted at the latest cached rates. The per-currency figures above are the '
        . 'amounts actually charged.',
    'dashboard.converts_on' => 'converts {date}',
    'dashboard.due_in_total' => 'due in total',
    'dashboard.metrics' => 'Spending at a glance',
    'dashboard.monthly_spend' => 'Monthly spend',
    'dashboard.next_charge' => 'Next: {name}, {date} · {amount}',
    'dashboard.no_budget' => 'No budget set for you yet, so there is nothing to measure this against.',
    'dashboard.no_household' => 'No household',
    'dashboard.no_household_note' =>
        'You are not a member of a household yet, so there is nothing to show. Instance administration '
        . 'does not by itself grant access to anybody\'s subscriptions.',
    'dashboard.nothing_due' => 'Nothing due.',
    'dashboard.nothing_renewing' => 'Nothing renewing in the near window.',
    'dashboard.other_categories' => '{count, plural, one {# other category} other {# other categories}}',
    'dashboard.peak_month' => 'Busiest month',
    'dashboard.one_off_in' => 'One-off & lifetime · {currency}',
    'dashboard.one_off_note' => '{count, plural, one {# entry} other {# entries}}, not included in monthly totals',
    'dashboard.per_month_unit' => '/ month',
    'dashboard.per_year_unit' => '/ year',
    'dashboard.per_year_and_count' =>
        '{amount} per year · {count, plural, one {# subscription} other {# subscriptions}}',
    'dashboard.recurring' => 'Recurring',
    'dashboard.recurring_empty' => 'No active recurring subscriptions yet.',
    'dashboard.recurring_in' => 'Recurring · {currency}',
    'dashboard.renewing_soon' => 'Renewing soon',
    'dashboard.renewing_soon_note' => 'in the next {days} days',
    'dashboard.share_of_spend' => '{name}: {percent}% of monthly spend',
    'dashboard.part_month' => 'Part month',
    'dashboard.part_month_note' =>
        'The first month counts only the charges still ahead of today, so it is short by whatever has '
        . 'already been paid this month.',
    'dashboard.series_committed' => 'Excluding trial conversions',
    'dashboard.series_trial_gap' => 'What trials will add',
    'dashboard.series_with_trials' => 'Including trial conversions',
    'dashboard.spend_chart' => 'The next twelve months',
    'dashboard.spend_chart_alt' =>
        'Line chart of spend per month for the next twelve months, in {currency}: one line including trial '
        . 'conversions and one excluding them. The same figures are in the table that follows.',
    // The same chart with no trial converting inside the horizon, where the
    // second line would sit exactly on the first and is not drawn. The
    // description has to match the picture, and the table under it.
    'dashboard.spend_chart_alt_single' =>
        'Line chart of spend per month for the next twelve months, in {currency}. The same figures are in '
        . 'the table that follows.',
    'dashboard.trials_ending_soon' => 'Trials ending soon',
    'dashboard.trials_note' => 'Free today. About to stop being.',
    'dashboard.trials_total_note' => 'about to start being charged',
    'dashboard.usage' => 'Budget and where it goes',
    'dashboard.where_it_goes' => 'Where it goes',
    'dashboard.yearly_spend' => 'Yearly spend',
    'dashboard.unconvertible' =>
        'Totals are shown per currency. They cannot be combined because no exchange rate is available '
        . 'for {currencies} — a total leaving that out would be a wrong number rather than an approximate '
        . 'one.',

    // error
    'error.back_to_the_dashboard' => 'Back to the dashboard',

    // field
    'field.actions' => 'Actions',
    'field.added' => 'Added',
    'field.address' => 'Address',
    'field.alert' => 'Alert',
    'field.amount' => 'Amount',
    'field.category' => 'Category',
    'field.change' => 'Change',
    'field.channel' => 'Channel',
    'field.confirm_password' => 'Confirm password',
    'field.currency' => 'Currency',
    'field.cycle' => 'Cycle',
    'field.detail' => 'Detail',
    'field.device' => 'Device',
    'field.due' => 'Due',
    'field.email' => 'Email address',
    'field.event' => 'Event',
    'field.file' => 'File',
    'field.last_seen' => 'Last seen',
    'field.last_used' => 'Last used',
    'field.member' => 'Member',
    'field.month' => 'Month',
    'field.name' => 'Name',
    'field.next_charge' => 'Next charge',
    'field.next_payment' => 'Next payment',
    'field.note' => 'Note',
    'field.notes' => 'Notes',
    'field.password' => 'Password',
    'field.per_month' => 'Per month',
    'field.period' => 'Period',
    'field.price' => 'Price',
    'field.rating' => 'Rating',
    'field.result' => 'Result',
    'field.role' => 'Role',
    'field.share' => 'Share',
    'field.size' => 'Size',
    'field.started' => 'Started',
    'field.status' => 'Status',
    'field.subscription' => 'Subscription',
    'field.subscriptions' => 'Subscriptions',
    'field.tag' => 'Tag',
    'field.tags' => 'Tags',
    'field.total' => 'Total',
    'field.type' => 'Type',
    'field.uses' => 'Uses',
    'field.website' => 'Website',
    'field.when' => 'When',
    'field.who' => 'Who',
    'field.your_name' => 'Your name',

    // forecast
    'forecast.intro' =>
        'Each renewal is shown in the month it actually falls, rather than spread evenly — a yearly bill '
        . 'is a bill in one month. Scheduled price changes and trial conversions are applied from their '
        . 'own dates, so a figure here does not move when the change eventually happens.',
    'forecast.next_12_months' => 'Next 12 months',
    'forecast.show_only_my_share' => 'Show only my share',
    'forecast.show_whole_household' => 'Show the whole household',
    'forecast.no_rate' => 'No rate to combine these.',
    'forecast.nothing_due' => 'Nothing due',

    // form
    'form.has_errors' => 'Check the highlighted fields and try again.',

    // hint
    'hint.password_length' => 'At least 10 characters.',

    // import_map
    'import_map.column_in_your_file' => 'Column in your file',
    'import_map.do_not_import' => 'Do not import',
    'import_map.example' => 'Example',
    'import_map.field' => 'Field',
    'import_map.map_the_columns' => 'Map the columns',
    'import_map.required_note' => '* Name and price are required. A row missing either is skipped.',
    'import_map.summary' =>
        '{rows, plural, one {# row} other {# rows}}. Suggested using “{preset}”. Change anything that '
        . 'looks wrong; “Do not import” leaves a field empty.',
    'import_map.title' => 'Map columns',

    // import_preview
    'import_preview.back_to_mapping' => 'Back to mapping',
    'import_preview.commit' => 'Import {count, plural, one {# subscription} other {# subscriptions}}',
    'import_preview.intro' =>
        'Rows with a problem are marked below. Importing goes ahead without them — or go back and change '
        . 'the mapping.',
    'import_preview.problem' => 'Problem',
    'import_preview.ready' => 'Ready',
    'import_preview.skipped' => '{invalid, plural, one {# row} other {# rows}} will be skipped.',
    'import_preview.summary' => '{valid, plural, one {# row} other {# rows}} will be imported.',
    'import_preview.title' => 'Preview import',

    // import_start
    'import_start.file_hint' => 'CSV (comma, semicolon or tab separated) or JSON, up to {rows} rows.',
    'import_start.import_subscriptions' => 'Import subscriptions',
    'import_start.intro' =>
        'Bring in a CSV or JSON file from a spreadsheet or another tracker. You choose which column '
        . 'feeds which field, and you see exactly what will be created before anything is written.',
    'import_start.it_came_from' => 'It came from',
    'import_start.source_hint' => 'Only a starting point for the column mapping — you confirm it on the next screen.',

    // insight
    'insight.action' => 'Review subscription',
    'insight.more' => '{count, plural, one {# more insight} other {# more insights}}',
    'insight.note' =>
        'Each of these is a rule over your own subscriptions, and every figure is one '
        . 'subscription\'s own price. Nothing here is estimated, and nothing is converted.',
    'insight.overlap.detail' =>
        'Dropping {name} would remove {amount} a year. Also in this category: {others}.',
    'insight.overlap.headline' =>
        '{count, plural, one {# subscription} other {# subscriptions}} in {category}',
    'insight.price_risen.detail' =>
        '{name} went up by {difference} on {date} — {amount} a year more than before.',
    'insight.price_risen.headline' => 'A price went up',
    'insight.price_rising.detail' =>
        '{name} goes up by {difference} on {date} — {amount} a year more than now.',
    'insight.price_rising.headline' => 'A price is going up',
    'insight.rarely_used.detail' =>
        '{name} costs {amount} a year and has been used {count, plural, one {# time} other {# times}}.',
    'insight.rarely_used.headline' => 'Paid for, barely used',
    'insight.rarely_used.per_use' => 'That works out at {amount} a use.',
    'insight.title' => 'Spend insights',
    'insight.trial_converting.detail' =>
        '{name} converts on {date}, {days, plural, =0 {today} one {# day away} other {# days away}}, '
        . 'and starts costing {amount} a year.',
    'insight.trial_converting.headline' => 'A free trial is about to convert',

    // js
    'js.dashboard.part_month' => 'part month',
    'js.dashboard.spend' => 'Spend',
    'js.dashboard.spend_committed' => 'Excluding trial conversions',
    'js.dashboard.spend_with_trials' => 'Including trial conversions',
    'js.dashboard.trial_gap' => 'Trials add {amount}',
    'js.quick_add_failed' => 'That form could not be loaded. Open the full page instead.',
    'js.passkey_generic_error' => 'That did not work. Try again.',
    'js.passkey_not_used' => 'No passkey was used.',
    'js.passkey_register_cancelled' => 'Registration was cancelled.',
    'js.passkey_register_failed' => 'That key could not be registered: {reason}',
    'js.passkey_register_unsupported' => 'This browser cannot register passkeys on this connection.',
    'js.passkey_unsupported' => 'This browser cannot use passkeys on this connection.',
    'js.passkey_use_failed' => 'That passkey could not be used: {reason}',

    // nav
    'nav.analytics' => 'Analytics',
    'nav.audit' => 'Audit',
    'nav.budgets' => 'Budgets',
    'nav.calendar' => 'Billing Calendar',
    'nav.cancellations' => 'Cancel by',
    'nav.categories' => 'Categories',
    'nav.dashboard' => 'Dashboard',
    'nav.forecast' => 'Forecast',
    'nav.import' => 'Import',
    'nav.more' => 'More',
    'nav.notifications' => 'Notifications',
    'nav.primary' => 'Primary',
    'nav.profile' => 'Profile',
    'nav.search' => 'Search',
    'nav.settings' => 'Settings',
    'nav.sign_out' => 'Sign out',
    'nav.skip_to_content' => 'Skip to content',
    'nav.subscriptions' => 'Subscriptions',
    'nav.tools' => 'Tools',

    // notifications
    'notifications.add_a_channel' => 'Add a channel',
    'notifications.add_channel_of_type' => 'Add {type}',
    'notifications.channel_enabled' => 'Send notifications to this channel',
    'notifications.channels' => 'Channels',
    'notifications.channels_empty' => 'No channels yet. Add one below and send yourself a test message.',
    'notifications.delivery' => 'Delivery',
    'notifications.digest_day_hint' =>
        'For a weekly summary, 1 is Monday. For a monthly one it is the day of the month, up to 28 — a '
        . 'summary set for the 30th would skip February.',
    'notifications.digest_hint' =>
        'A summary collects everything coming up into one message instead of sending them separately. '
        . 'Lead times do not apply to a summary — each thing is mentioned once, shortly before it happens.',
    'notifications.intro' =>
        'Reminders are sent by the scheduler once a day. Each one is recorded when it goes out, so a run '
        . 'that happens twice — or a scheduler that catches up after being offline — never sends you the '
        . 'same thing again.',
    'notifications.last_delivered' => 'Last delivered {when}.',
    'notifications.last_error' => 'Last attempt failed: {reason}',
    'notifications.lead_days_hint' =>
        'Separate with commas, for example {example}. Each one is a separate reminder. Leave blank for '
        . 'no advance reminders. A single subscription can override this on its own page.',
    'notifications.lead_days_label' => 'Remind me this many days before a charge',
    'notifications.recently_sent' => 'Recently sent',
    'notifications.routing_hint' =>
        'Leave every box ticked — or every box clear — to send everything to every channel.',
    'notifications.save_channel' => 'Save channel',
    'notifications.save_preferences' => 'Save preferences',
    'notifications.secret_placeholder' => 'Leave blank to keep the stored value',
    'notifications.summary_day' => 'Summary day',
    'notifications.title' => 'Notifications',
    'notifications.when_to_tell_me' => 'When to tell me',
    'notifications.where_each_alert_goes' => 'Where each alert goes',

    // period
    'period.per_daily' => 'Per day',
    'period.per_monthly' => 'Per month',
    'period.per_weekly' => 'Per week',
    'period.per_yearly' => 'Per year',

    // quick_add
    'quick_add.loading' => 'Loading…',

    // shortcuts
    'shortcuts.close' => 'Close a dialog',
    'shortcuts.help' => 'Show this list',
    'shortcuts.key_escape' => 'Esc',
    'shortcuts.new' => 'Add a subscription',
    'shortcuts.search' => 'Search the list',
    'shortcuts.title' => 'Keyboard shortcuts',

    // saved_views
    'saved_views.empty' => 'No saved views yet. Filter the list, then give the result a name.',
    'saved_views.forget' => 'Forget the view “{name}”',
    'saved_views.heading' => 'Saved views',
    'saved_views.name_label' => 'Name for this view',
    'saved_views.name_placeholder' => 'Streaming, shared bills…',
    'saved_views.save_current' => 'Save this view',

    // security
    'security.active_sessions' => 'Active sessions',
    'security.add_a_passkey' => 'Add a passkey',
    'security.authenticator_app' => 'Authenticator app',
    'security.none_registered' => 'None registered.',
    'security.passkey_name' => 'Passkey name',
    'security.passkey_name_label' => 'Name for the new passkey',
    'security.passkeys_and_security_keys' => 'Passkeys and security keys',
    'security.passkeys_intro' =>
        'A passkey signs you in without a password, and counts as your second factor when you do use '
        . 'one. You can register more than one — a phone and a hardware key, say — so losing one device is '
        . 'not losing access.',
    'security.phone_yubikey_laptop' => 'Phone, YubiKey, laptop…',
    'security.recovery_codes' => 'Recovery codes',
    'security.recovery_codes_note' =>
        'Save these now — they are shown once and each works a single time. They are the way back in if '
        . 'you lose your authenticator.',
    'security.recovery_password_label' => 'Confirm your password to issue a new set',
    'security.recovery_remaining' =>
        '{count, plural, one {# unused code} other {# unused codes}}. Each works once, and they are the '
        . 'way back in if you lose your authenticator or every passkey you have registered.',
    'security.regenerate_recovery_codes' => 'Regenerate recovery codes',
    'security.sessions_intro' =>
        'Every browser currently signed in as you. Revoking one signs it out on its next request.',
    'security.sign_out_everywhere_else' => 'Sign out everywhere else',
    'security.this_device' => 'This device',
    'security.title' => 'Account security',
    'security.totp_off' => 'Off. Add an authenticator app to require a six-digit code as well as your password.',
    'security.totp_off_password_label' => 'Confirm your password to turn two-step verification off',
    'security.totp_on' => 'On. Codes from your authenticator app are required when you sign in.',
    'security.totp_setup_action' => 'Set up an authenticator app',
    'security.transports' => 'Transports',
    'security.turn_off' => 'Turn off',
    'security.your_recovery_codes' => 'Your recovery codes',

    // security_totp_setup
    'security_totp_setup.confirm' => 'Turn on two-step verification',
    'security_totp_setup.enter_code' => 'Type the six-digit code it shows, to prove the app has the key.',
    'security_totp_setup.heading' => 'Set up your authenticator app',
    'security_totp_setup.scan' => 'Scan this code with your authenticator app, or enter the key by hand.',
    'security_totp_setup.setup_key' => 'Setup key:',
    'security_totp_setup.six_digit_code' => 'Six-digit code',
    'security_totp_setup.title' => 'Set up your authenticator',

    // settings
    'settings.allow_registration' => 'Allow anyone to create an account',
    'settings.api_and_calendar' => 'API and calendar',
    'settings.api_intro' =>
        'Tokens for scripts, other machines and calendar apps. A token can never do more than you can.',
    'settings.api_key' => 'API key',
    'settings.base_currency' => 'Base currency',
    'settings.card_position' => 'Position of the {card} card',
    'settings.card_visible' => 'Show',
    'settings.dashboard_cards' => 'Dashboard cards',
    'settings.dashboard_cards_hint' =>
        'Lower numbers come first. Clear the box to hide a card without losing where you had put it.',
    'settings.data_intro' =>
        'Bring subscriptions in from a file, or take everything out again — including the attached '
        . 'invoices — in a format that needs no database to read.',
    'settings.data_isolation' => 'Data isolation',
    'settings.demo_mode' => 'Read-only demonstration',
    'settings.demo_mode_hint' =>
        'Nothing on this instance can be changed while this is on — by anybody, through the browser or the '
        . 'API. Only an instance administrator can switch it off again, from this form.',
    'settings.exchange_rates' => 'Exchange rates',
    'settings.host_address_or_range' => 'Host, address or range',
    'settings.host_or_range' => 'Host or range',
    'settings.household' => 'Household',
    'settings.household_permission_note' => 'Only an owner or admin of this household can change these.',
    'settings.import_a_file' => 'Import a file',
    'settings.instance' => 'Instance',
    'settings.instance_note' => 'These apply to everybody on this instance.',
    'settings.members' => 'Members',
    'settings.rate_key_clear' => 'Remove the stored API key',
    'settings.rate_key_env_wins' => '{variable} in the environment overrides whatever is stored here.',
    'settings.rate_key_placeholder' => 'Leave blank to keep the current key',
    'settings.rates_cached' =>
        '{count, plural, one {# currency} other {# currencies}} cached, last updated {when} UTC.',
    'settings.rates_need_key' =>
        '{provider} needs an API key and has not been given one, so no rates are being fetched.',
    'settings.rates_none' =>
        'No rates cached yet. They are fetched the first time a dashboard is viewed, or by running',
    'settings.rates_note' =>
        'Rates are cached and used only for display. Amounts stay in the currency they were entered in. '
        . 'When a rate is unavailable, totals are shown per currency instead of combined.',
    'settings.rates_stale' => 'Due a refresh.',
    'settings.role_for' => 'Role for {name}',
    'settings.save_household' => 'Save household',
    'settings.save_instance_settings' => 'Save instance settings',
    'settings.security_intro' => 'Two-step verification, passkeys and the browsers currently signed in as you.',
    'settings.signing_in' => 'Signing in',
    'settings.trust_this_host' => 'Trust this host',
    'settings.trusted_host_note_placeholder' => 'What this is, for later',
    'settings.trusted_host_placeholder' => 'gotify.lan, .lan, 192.168.1.10 or 100.64.0.0/10',
    'settings.trusted_hosts' => 'Trusted hosts',
    'settings.trusted_hosts_empty' => 'Nothing is trusted. Only public addresses can be reached.',
    'settings.trusted_hosts_exception' =>
        'A self-hosted Gotify on your LAN or on a Tailscale address is on the wrong side of that rule, '
        . 'so add it here. Each entry is an exception you have chosen, and every use of one is written to '
        . 'the log. A trusted host may also be reached over plain',
    'settings.trusted_hosts_intro' =>
        'Notifications and webhooks may only be sent to addresses on the public internet. That is what '
        . 'stops somebody using a webhook URL to make this server fetch something on your private network '
        . '— including, on a cloud host, the metadata service holding its credentials.',
    'settings.you' => '(you)',
    'settings.your_data' => 'Your data',

    // setup_notifications
    'setup_notifications.configured' => 'Configured',
    'setup_notifications.finish' => 'Finish',
    'setup_notifications.intro' =>
        'Step 2 of 2. Renovo will tell you before a subscription renews, before a free trial starts '
        . 'charging, before a cancellation deadline passes, and when a budget is heading over. Add '
        . 'somewhere for it to send that — and send yourself a test message, because a token that looks '
        . 'right and is not is the sort of thing you want to find out now rather than the week you miss a '
        . 'renewal.',
    'setup_notifications.later_note' =>
        'You can add more channels, or change any of this, later under Settings → Notifications. Every '
        . 'member configures their own.',
    'setup_notifications.skip_for_now' => 'Skip for now',
    'setup_notifications.mail_relay' => 'Mail goes out through {host}, from {from}. Those come from the environment:',
    'setup_notifications.mail_relay_env' =>
        'An instance secret belongs in the environment, not in the database, so they are not set here. '
        . 'Add an Email channel below and send a test to confirm the relay actually works.',
    'setup_notifications.test_message_delivered' => 'Test message delivered.',
    'setup_notifications.title' => 'Set up notifications',
    'setup_notifications.where_should_reminders_go' => 'Where should reminders go?',

    // setup_wizard
    'setup_wizard.finish_setup' => 'Finish setup',
    'setup_wizard.intro' =>
        'This instance has no accounts yet. The account you create here is the instance administrator.',
    'setup_wizard.title' => 'Set up {instance}',
    'setup_wizard.welcome' => 'Welcome',
    'setup_wizard.your_account' => 'Your account',

    // state
    'state.active' => 'Active',
    'state.all' => 'All',
    'state.expired' => 'Expired',
    'state.never' => 'Never',
    'state.none' => 'None',
    'state.paused' => 'Paused',
    'state.revoked' => 'Revoked',
    'state.shared' => 'Shared',
    'state.today' => 'Today',
    'state.trial' => 'Trial',
    'state.trial_converts' => 'Trial converts',

    // stats
    'stats.cost_per_use' => 'Cost per use',
    'stats.donut_alt' =>
        'Doughnut chart of recurring monthly spend by category, in {currency}. The same figures are in '
        . 'the table that follows.',
    'stats.donut_per_currency' =>
        'Shown per currency rather than as one chart: no exchange rate is available for {currencies}, so '
        . 'there is no single total for the categories to be shares of. Each figure below is a share of '
        . 'its own currency\'s monthly total.',
    'stats.last_12_months' => 'Last 12 months',
    'stats.least_expensive' => 'Least expensive',
    'stats.most_expensive' => 'Most expensive',
    'stats.no_previous_year' =>
        'Nothing recorded for the year before last, so there is nothing to compare against. {amount} in '
        . 'the last twelve months.',
    'stats.no_uses_recorded' => 'No uses recorded',
    'stats.notable' => 'Notable subscriptions',
    'stats.notable_empty' => 'Nothing with a monthly cost to compare yet.',
    'stats.notable_excluded' =>
        '{count, plural, one {# subscription is} other {# subscriptions are}} not ranked: no exchange '
        . 'rate is available to compare them with the rest.',
    'stats.notable_note' =>
        'Ranked on cost per month converted to the base currency, because two prices in different '
        . 'currencies have no order otherwise. Each is shown in the currency it is actually charged in. '
        . 'One-off and lifetime entries have no monthly cost, and a trial has none until it converts, so '
        . 'none of them is ranked.',
    'stats.per_period' => 'The same cost, by period',
    'stats.per_year_heading' => 'Recurring cost per year, by currency',
    'stats.per_year_note' =>
        'All four are derived from the yearly figure, so they always multiply up to one another. Days '
        . 'and weeks use the mean Gregorian year of 365.25 days. One-off and lifetime entries are '
        . 'excluded.',
    'stats.rarely_used' => 'Rarely used',
    'stats.rating_label' => '{rating} out of {max}',
    'stats.the_12_before_that' => 'The 12 before that',
    'stats.title' => 'Analytics',
    'stats.trajectory' => 'Spending trajectory',
    'stats.trajectory_note' =>
        'Each renewal in the month it actually falls, with scheduled price changes and trial conversions '
        . 'applied from their own dates. The same figures the dashboard chart and the forecast show.',
    'stats.unconvertible' =>
        'These cannot be combined into {currency}: no exchange rate is available for {currencies}. The '
        . 'per-currency figures below are the complete picture; a combined number leaving that spending '
        . 'out would not be.',
    'stats.used_it' => 'Used it',
    'stats.uses_per_month' => '{count} / month',
    'stats.value_empty' => 'No active recurring subscriptions to rank.',
    'stats.value_note' =>
        'Cost per use, highest first. Something with no uses recorded is unmeasured rather than poor '
        . 'value, and sorts to the bottom.',
    'stats.worth_it' => 'Worth it?',
    'stats.year_over_year' => 'Year over year',
    'stats.year_over_year_empty' => 'Not enough convertible data to compare the two years.',
    'stats.year_over_year_excluded' =>
        '{count, plural, one {# subscription has} other {# subscriptions have}} no start date and so '
        . 'contribute nothing to either year.',
    'stats.year_over_year_note' =>
        'Reconstructed from start dates, billing cycles and recorded price history — this application '
        . 'tracks what is due rather than keeping a ledger of payments taken.',

    // subscriptions
    'subscriptions.active_note' => 'Running right now, paused ones aside.',
    'subscriptions.add_subscription' => 'Add subscription',
    'subscriptions.apply_filters' => 'Apply filters',
    'subscriptions.cancel_by_empty' =>
        'Nothing with a notice period is due. Add one to a subscription and its deadline appears here.',
    'subscriptions.cancel_by_heading' => 'Cancel by',
    'subscriptions.cancel_by_note' =>
        'The last day to give notice and avoid the next charge. Shown only where a notice period is set — '
        . 'without one the deadline is the renewal date itself.',
    'subscriptions.categories_empty' => 'No recurring spend to break down yet.',
    'subscriptions.categories_heading' => 'Category spending',
    'subscriptions.categories_per_currency' =>
        'No exchange rate for {currencies}, so each currency is shown against its own total rather than '
        . 'blended into one bar.',
    'subscriptions.categories_total' => 'of {total} a month',
    'subscriptions.expiring_heading' => 'Expiring soon',
    'subscriptions.include_paused' => 'Include paused',
    'subscriptions.name_or_notes' => 'Name or notes',
    'subscriptions.paused_heading' => 'Paused / inactive',
    'subscriptions.paused_if_resumed' => 'a year if resumed',
    'subscriptions.paused_note' => 'Switched off, and costing nothing while they are.',
    'subscriptions.renewing_note' => 'A charge falling in the next {days, plural, one {# day} other {# days}}.',
    'subscriptions.search' => 'Search',
    'subscriptions.strip' => 'Subscription totals',
    'subscriptions.notice_of' => '{period} notice',
    'subscriptions.then_costs' => 'when it converts',
    'subscriptions.trials_heading' => 'Free trials',
    'subscriptions.trials_note' =>
        'The last day of a trial is the day of its first charge, so the countdown runs to the day it '
        . 'converts and the amount shown is what it converts to.',

    // subscriptions_form
    'subscriptions_form.back_to_list' => 'Back to list',
    'subscriptions_form.belongs_to' => 'Belongs to',
    'subscriptions_form.billing_cycle' => 'Billing cycle',
    'subscriptions_form.converts_to' => 'Converts to',
    'subscriptions_form.converts_to_cycle' => 'Converts to cycle',
    'subscriptions_form.converts_to_cycle_days' => 'Converts to days between payments',
    'subscriptions_form.converts_to_cycle_days_hint' => 'Only used when it converts to a custom cycle.',
    'subscriptions_form.converts_to_price_hint' => 'Leave blank if it converts to the price above.',
    'subscriptions_form.cycle_days_hint' => 'Only used when the cycle is “Custom”.',
    'subscriptions_form.days_between_payments' => 'Days between payments',
    'subscriptions_form.edit_title' => 'Edit subscription',
    'subscriptions_form.free_trial' => 'Free trial',
    'subscriptions_form.is_trial' => 'This is a free trial',
    'subscriptions_form.isolated_owner_note' =>
        'This instance keeps members\' subscriptions separate, so new entries belong to you.',
    'subscriptions_form.logo' => 'Logo',
    'subscriptions_form.logo_hint' => 'Uploading a new file replaces it.',
    'subscriptions_form.next_payment_date' => 'Next payment date',
    'subscriptions_form.not_recorded' => 'Not recorded',
    'subscriptions_form.notice_hint' => 'Used to work out the last day you can cancel before the next charge.',
    'subscriptions_form.notice_period' => 'Notice period',
    'subscriptions_form.notice_period_unit' => 'Notice period unit',
    'subscriptions_form.paid_by' => 'Paid by',
    'subscriptions_form.reminders' => 'Reminders',
    'subscriptions_form.reminders_hint' =>
        'Days before the charge, for example {example} — or {never} to never be reminded about this one. '
        . 'Leave blank to use the schedule from',
    'subscriptions_form.reminders_hint_link' => 'your notification settings',
    'subscriptions_form.same_as_above' => 'Same as above',
    'subscriptions_form.save_changes' => 'Save changes',
    'subscriptions_form.section_billing' => 'When it’s billed',
    'subscriptions_form.section_cost' => 'What it costs',
    'subscriptions_form.section_identity' => 'What it is',
    'subscriptions_form.section_ownership' => 'Who it’s for',
    'subscriptions_form.start_date_hint' =>
        'Used to reconstruct what you spent in previous years, and to date the first entry in the price '
        . 'history.',
    'subscriptions_form.started_on' => 'Started on',
    'subscriptions_form.streaming_shared' => 'streaming, shared',
    'subscriptions_form.remove_tag' => 'Remove {name}',
    'subscriptions_form.tags_hint' => 'Comma separated. New tags are created automatically.',
    'subscriptions_form.trial_end_hint' => 'The last free day — and the day the first charge falls.',
    'subscriptions_form.trial_ends' => 'Trial ends',
    'subscriptions_form.type_hint' => 'One-off and lifetime entries are tracked but left out of monthly totals.',
    'subscriptions_form.use_my_usual_reminders' => 'Use my usual reminders',

    'subscriptions_form.website_hint' =>
        'Used for the link on this subscription, and to fetch its icon if you have not uploaded one.',
    'subscriptions_form.website_placeholder' => 'https://example.com',

    // subscriptions_list
    'subscriptions_list.add_tag' => 'Add tag',
    'subscriptions_list.add_the_first_one' => 'Add the first one.',
    'subscriptions_list.cancel_by' => 'Cancel by {date}',
    'subscriptions_list.convert_currency' => 'Convert currency',
    'subscriptions_list.convert_note' =>
        'Converting currency uses today\'s exchange rate and records the result in each subscription\'s '
        . 'price history. If a rate is unavailable the whole action is refused rather than re-labelling '
        . 'the amount, which would be a silent price change.',
    'subscriptions_list.cost' => 'Cost',
    'subscriptions_list.empty' => 'No subscriptions yet.',
    'subscriptions_list.no_category' => 'No category',
    'subscriptions_list.no_matches' => 'Nothing matches those filters.',
    'subscriptions_list.nobody' => 'Nobody',
    'subscriptions_list.not_amortised' => 'One-off and lifetime entries are not amortised',
    'subscriptions_list.pager' => 'Page {page} of {pages} · {total} total',
    'subscriptions_list.pagination' => 'Pagination',
    'subscriptions_list.pause' => 'Pause',
    'subscriptions_list.remove_tag' => 'Remove tag',
    'subscriptions_list.resume' => 'Resume',
    'subscriptions_list.select' => 'Select',
    'subscriptions_list.set_category' => 'Set category',
    'subscriptions_list.set_member' => 'Set member',
    'subscriptions_list.set_payer' => 'Set payer',
    'subscriptions_list.with_selected' => 'With selected',

    // subscriptions_money
    'subscriptions_money.attach_a_file' => 'Attach a file',
    'subscriptions_money.attachment_hint' =>
        'PDF or an image. Stored outside the web root and only readable by people who can see this '
        . 'subscription.',
    'subscriptions_money.attachment_period_label' => 'Billing period it covers (optional)',
    'subscriptions_money.by_the_shares_below' => 'By the shares below',
    'subscriptions_money.free_until' => 'Free until',
    'subscriptions_money.history_empty' => 'No price history recorded yet.',
    'subscriptions_money.history_note' =>
        'Every price is kept. A change is a new entry, never an edit, so what this cost last year stays '
        . 'knowable.',
    'subscriptions_money.how_it_is_split' => 'How it is split',
    'subscriptions_money.invoices_and_receipts' => 'Invoices and receipts',
    'subscriptions_money.it_converts_to' => 'It converts to',
    'subscriptions_money.its_owner' => 'its owner',
    'subscriptions_money.new_price' => 'New price',
    'subscriptions_money.no_rating' => 'No rating',
    'subscriptions_money.not_split' => 'Not split',
    'subscriptions_money.not_split_owner' => 'The whole cost sits with {owner}.',
    'subscriptions_money.note_optional' => 'Note (optional)',
    'subscriptions_money.nothing_attached_yet' => 'Nothing attached yet.',
    'subscriptions_money.price_history' => 'Price history',
    'subscriptions_money.price_note_placeholder' => 'Announced in their email of 3 March',
    'subscriptions_money.reset_count' => 'Reset count',
    'subscriptions_money.save_rating' => 'Save rating',
    'subscriptions_money.save_split' => 'Save split',
    'subscriptions_money.schedule_a_price_change' => 'Schedule a price change',
    'subscriptions_money.schedule_change' => 'Schedule change',
    'subscriptions_money.schedule_note' =>
        'For an increase you have been told about but which has not happened yet. It is counted in '
        . 'forecasts and budgets from its date, and becomes the current price on the day it arrives.',
    'subscriptions_money.scheduled' => 'Scheduled',
    'subscriptions_money.shared_cost' => 'Shared cost',
    'subscriptions_money.shares_hint' =>
        'A share of 0 means that member is not part of the split. Shares are relative weights, not '
        . 'percentages — 2 and 1 means two thirds and one third.',
    'subscriptions_money.split_equal' => 'Equally between the members below',
    'subscriptions_money.split_note' =>
        'Shares are worked out from the current price every time, and always add up to it exactly — the '
        . 'odd penny goes to one member rather than disappearing.',
    'subscriptions_money.starts_on' => 'Starts on',
    'subscriptions_money.title' => 'Cost',
    'subscriptions_money.trial_end_is_first_charge' =>
        'That is also the day the first charge falls — the last day of the trial, not the day after.',
    'subscriptions_money.usage' => 'Usage',
    'subscriptions_money.used_count' => 'Recorded {count, plural, one {# time} other {# times}}.',
    'subscriptions_money.used_since' => 'Recorded {count, plural, one {# time} other {# times}} since {since}.',

    // tokens
    'tokens.abilities_hint' =>
        'Choose read-only unless something genuinely needs to make changes. The calendar feed accepts '
        . 'read-only tokens only.',
    'tokens.as_json' => 'as JSON',
    'tokens.bearer_note' => 'Send the token as a bearer credential:',
    'tokens.calendar_feed' => 'Calendar feed',
    'tokens.calendar_note' =>
        'Subscribe to this URL in your calendar app, with a read-only token in place of the placeholder. '
        . 'It shows renewals, trial conversions and the last day to cancel each subscription.',
    'tokens.can' => 'Can',
    'tokens.create_token' => 'Create token',
    'tokens.expires_on' => 'Expires {date}',
    'tokens.expires_optional' => 'Expires (optional)',
    'tokens.identifier' => 'Identifier',
    'tokens.intro' =>
        'A token lets a script, a calendar app or another machine reach this instance without a '
        . 'password. It can never do more than you can: a token issued by a Viewer reads what a Viewer '
        . 'reads and writes nothing.',
    'tokens.issue_a_token' => 'Issue a token',
    'tokens.name_placeholder' => 'Home Assistant, my calendar, a backup script',
    'tokens.new_token_note' =>
        'Copy it now. Only a hash of it is stored, so this is the one and only time it can be shown.',
    'tokens.none_yet' => 'None yet.',
    'tokens.reference_is_at' => 'The full reference is at',
    'tokens.title' => 'API tokens',
    'tokens.using_them' => 'Using them',
    'tokens.what_it_is_for' => 'What it is for',
    'tokens.what_it_may_do' => 'What it may do',
    'tokens.your_new_token' => 'Your new token',
    'tokens.your_tokens' => 'Your tokens',

    // type
    'type.one_off' => 'One-off',

    // -----------------------------------------------------------------------
    // Vocabulary owned by the domain and the services: enum labels a template
    // asks for by key, the fields a notification channel declares, the text of
    // an alert, and the transactional emails.
    // -----------------------------------------------------------------------

    // alert
    'alert.budget.over' => 'That is {amount} over.',
    'alert.budget.projected' => 'Projected {projected} against a limit of {limit}.',
    'alert.budget.title' => 'Budget "{budget}" is projected to be exceeded',
    'alert.cancel.line' => 'That is {when} — the last day to give notice and avoid the next charge.',
    'alert.cancel.renews' => 'It renews at {amount}.',
    'alert.cancel.title' => 'Cancel {name} by {date}',
    'alert.renewal.line' => '{amount} is due on {date}.',
    'alert.renewal.title' => '{name} renews {when}',
    'alert.test.line' => 'If you are reading this, {channel} is configured correctly.',
    'alert.test.title' => 'Test notification',
    'alert.test.what_arrives' =>
        'Renewal reminders, trial warnings, cancellation deadlines and budget alerts will arrive '
        . 'here.',
    'alert.trial.line' => 'It starts charging {amount} on {date}.',
    'alert.trial.title' => '{name} trial ends {when}',
    'alert.when.in_days' => 'in {days, plural, one {# day} other {# days}}',
    'alert.when.today' => 'today',
    'alert.when.tomorrow' => 'tomorrow',

    // calendar
    'calendar.cancel_description' => 'Cancel {name} on or before this date to avoid the next charge of {amount}.',
    'calendar.cancel_summary' => 'Last day to cancel {name}',
    'calendar.feed_description' => 'Renewals, trial conversions and cancellation deadlines.',
    'calendar.feed_name' => '{instance} subscriptions',
    'calendar.payment_description' => '{name} is due ({amount}).',
    'calendar.payment_summary' => '{name} — {amount}',
    'calendar.trial_description' => 'The free trial of {name} ends and it converts to {amount}.',
    'calendar.trial_summary' => '{name} trial ends ({amount})',

    // channel_field
    'channel_field.email.address' => 'Email address',
    'channel_field.email.address_hint' => 'Leave blank to use your account address.',
    'channel_field.discord.url' => 'Webhook URL',
    'channel_field.discord.url_hint' =>
        'Server Settings → Integrations → Webhooks → Copy Webhook URL. It is the credential, so it is '
        . 'stored like a password and not shown again.',
    'channel_field.gotify.priority' => 'Priority',
    'channel_field.gotify.priority_hint' => '0–10. Higher priorities ring.',
    'channel_field.gotify.token' => 'Application token',
    'channel_field.gotify.token_hint' => 'Created under Apps in Gotify.',
    'channel_field.gotify.url' => 'Server URL',
    'channel_field.gotify.url_hint' => 'For example https://gotify.example.com',
    'channel_field.mattermost.channel' => 'Channel',
    'channel_field.mattermost.channel_hint' =>
        'Optional. Overrides the webhook’s own channel. Use @username for a direct message.',
    'channel_field.mattermost.url' => 'Webhook URL',
    'channel_field.mattermost.url_hint' =>
        'From Integrations → Incoming Webhooks. A self-hosted server on a private address must be on the '
        . 'trusted-host list. The URL is the credential, so it is stored like a password and not shown again.',
    'channel_field.ntfy.priority' => 'Priority',
    'channel_field.ntfy.priority_hint' => '1–5. 3 is the default; 5 bypasses Do Not Disturb.',
    'channel_field.ntfy.server' => 'Server URL',
    'channel_field.ntfy.server_hint' =>
        'Leave blank for https://ntfy.sh. A self-hosted server on a private address must be on the trusted-host list.',
    'channel_field.ntfy.tags' => 'Tags',
    'channel_field.ntfy.tags_hint' => 'Optional, comma-separated. Emoji shortcodes such as warning become icons.',
    'channel_field.ntfy.token' => 'Access token',
    'channel_field.ntfy.token_hint' => 'Optional. Only needed for a protected topic.',
    'channel_field.ntfy.topic' => 'Topic',
    'channel_field.ntfy.topic_hint' =>
        'Anyone who knows a topic name on a public server can read it. Choose something hard to guess.',
    'channel_field.pushover.priority' => 'Priority',
    'channel_field.pushover.priority_hint' => '-2 silent to 1 high. 0 is the default.',
    'channel_field.pushover.token' => 'Application token',
    'channel_field.pushover.token_hint' => 'Created under Your Applications on pushover.net.',
    'channel_field.pushover.user_key' => 'User or group key',
    'channel_field.pushover.user_key_hint' => 'Shown on your Pushover dashboard.',
    'channel_field.pushplus.token' => 'Token',
    'channel_field.pushplus.token_hint' => 'From the pushplus.plus dashboard.',
    'channel_field.pushplus.topic' => 'Topic code',
    'channel_field.pushplus.topic_hint' => 'Optional. Sends to a group instead of your own account.',
    'channel_field.serverchan.sendkey' => 'SendKey',
    'channel_field.serverchan.sendkey_hint' =>
        'From sct.ftqq.com. It travels in the request URL, so it may appear in Server酱’s own logs.',
    'channel_field.slack.channel' => 'Channel or user',
    'channel_field.slack.channel_hint' =>
        'A channel id (C0123…), a channel name (#bills) or a user id (U0123…) for a direct message.',
    'channel_field.slack.token' => 'Bot token',
    'channel_field.slack.token_hint' => 'Starts with xoxb-. Needs the chat:write scope.',
    'channel_field.telegram.chat_id' => 'Chat id',
    'channel_field.telegram.chat_id_hint' =>
        'Message your bot, then open api.telegram.org/bot<token>/getUpdates to find the id. '
        . 'Negative for a group; @name works for a public channel.',
    'channel_field.telegram.token' => 'Bot token',
    'channel_field.telegram.token_hint' => 'Created by BotFather. Looks like 123456789:AA…',
    'channel_field.webhook.secret' => 'Shared secret',
    'channel_field.webhook.secret_hint' =>
        'Optional. Sent as an HMAC-SHA256 signature of the body in X-Renovo-Signature.',
    'channel_field.webhook.url' => 'Endpoint URL',

    // dashboard_card
    'dashboard_card.budget_usage' => 'Budget and where it goes',
    'dashboard_card.by_category' => 'By category',
    'dashboard_card.spend_chart' => 'The next twelve months',
    'dashboard_card.totals' => 'Spending at a glance',
    'dashboard_card.trials' => 'Trials ending soon',
    'dashboard_card.upcoming' => 'Coming soon',

    // digest_mode
    'digest_mode.immediate' => 'As they happen',
    'digest_mode.monthly' => 'Monthly summary',
    'digest_mode.weekly' => 'Weekly summary',

    // error
    'error.api.attachment_missing' => 'That file is no longer stored.',
    'error.api.attachment_unreadable' => 'The attachment could not be read back.',
    'error.api.category_not_found' => 'No such category.',
    'error.api.read_only_tokens_only' => 'This endpoint accepts read-only tokens only.',
    'error.api.subscription_not_found' => 'No such subscription.',
    'error.api.subscription_unreadable' => 'The subscription could not be read back.',
    'error.api.token_invalid' => 'That API token is not valid.',
    'error.api.token_read_only' => 'This token is read-only.',
    'error.api.token_required' => 'This endpoint requires an API token.',
    'error.auth.registration_closed' => 'Registration is closed on this instance.',
    'error.auth.throttled_short' => 'Too many attempts. Try again shortly.',
    'error.auth.unverified_short' => 'Confirm your email address before signing in.',
    'error.csrf.expired' => 'The form has expired. Reload the page and try again.',
    'error.passkey.unexpected_response' => 'The browser sent an unexpected response.',
    'error.two_factor.expired' => 'That sign-in attempt expired.',
    'error.two_factor.restart_passkey' => 'Start the passkey step again.',

    // import_field
    'import_field.billing_cycle.hint' => 'weekly, monthly, quarterly, yearly or a number of days',
    'import_field.billing_cycle.label' => 'Billing cycle',
    'import_field.category.label' => 'Category',
    'import_field.converts_to_price.label' => 'Price after trial',
    'import_field.converts_to_price_minor.hint' =>
        'A whole number of pence or cents, for example 999. Takes precedence over the decimal '
        . 'price.',
    'import_field.converts_to_price_minor.label' => 'Price after trial, in minor units',
    'import_field.currency.label' => 'Currency',
    'import_field.cycle_days.label' => 'Days between payments',
    'import_field.is_active.hint' => 'yes/no, true/false or 1/0',
    'import_field.is_active.label' => 'Active',
    'import_field.name.label' => 'Name',
    'import_field.next_payment_date.hint' => 'YYYY-MM-DD or DD/MM/YYYY',
    'import_field.next_payment_date.label' => 'Next payment date',
    'import_field.notes.label' => 'Notes',
    'import_field.notice_period_amount.label' => 'Notice period',
    'import_field.notice_period_unit.hint' => 'days, weeks or months',
    'import_field.notice_period_unit.label' => 'Notice period unit',
    'import_field.price.hint' => 'A decimal amount, for example 9.99',
    'import_field.price.label' => 'Price',
    'import_field.price_minor.hint' =>
        'A whole number of pence or cents, for example 999. Takes precedence over the decimal '
        . 'price.',
    'import_field.price_minor.label' => 'Price in minor units',
    'import_field.start_date.hint' => 'YYYY-MM-DD or DD/MM/YYYY',
    'import_field.start_date.label' => 'Start date',
    'import_field.subscription_type.label' => 'Type',
    'import_field.tags.hint' => 'Separated by commas or semicolons',
    'import_field.tags.label' => 'Tags',
    'import_field.trial_end_date.hint' => 'YYYY-MM-DD or DD/MM/YYYY',
    'import_field.trial_end_date.label' => 'Trial end date',

    // mail
    'mail.password_changed.body' =>
        'Hello {name},\n\nYour password has just been changed. If this was not you, contact the '
        . 'administrator of this instance immediately.',
    'mail.password_changed.subject' => 'Your {instance} password was changed',
    'mail.reset.body' =>
        'Hello {name},\n\nSomeone asked to reset the password for this account. If it was you, follow '
        . 'this link within the next hour:\n\n{link}\n\nIf it was not you, no action is needed — the '
        . 'password has not changed.',
    'mail.reset.subject' => 'Reset your {instance} password',
    'mail.verify.body' =>
        'Hello {name},\n\nConfirm your email address to finish setting up your account:\n\n{link}\n\nThe '
        . 'link is valid for two days. If you did not create an account, you can ignore this message.',
    'mail.verify.subject' => 'Confirm your {instance} account',

    // rate_provider
    'rate_provider.exchangerate_host' =>
        'Free tier with a wider currency list than the ECB feed. Requires a free account and an '
        . 'access key.',
    'rate_provider.fixer' =>
        'Commercial service. Requires an access key; the free tier publishes EUR rates only, from '
        . 'which other bases are derived.',
    'rate_provider.frankfurter' =>
        'Free, no account needed. European Central Bank reference rates, updated each working day.',

    // token_ability
    'token_ability.read' => 'Read-only',
    'token_ability.write' => 'Read and write',
];
