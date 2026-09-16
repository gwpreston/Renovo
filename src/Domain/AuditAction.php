<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Every event the audit log records.
 *
 * An enum rather than free strings so that the admin view can label and filter
 * them, and so that adding an event is a deliberate act with a name, not a
 * typo waiting to split one event into two.
 *
 * What is absent is as deliberate as what is present: there is no
 * `user.disabled` case, because nothing in the application can disable an
 * account — the users table has no such column and there is no
 * user-administration UI. Adding the case now would give the admin view a
 * filter that can never match anything and imply an audit guarantee nothing is
 * enforcing. It arrives with the feature it describes.
 */
enum AuditAction: string
{
    case LoginSucceeded = 'login.succeeded';
    case LoginFailed = 'login.failed';
    case LoginBlocked = 'login.blocked';
    case LoggedOut = 'logout';

    case TwoFactorSucceeded = 'two_factor.succeeded';
    case TwoFactorFailed = 'two_factor.failed';

    case PasswordResetRequested = 'password.reset_requested';
    case PasswordChanged = 'password.changed';
    case EmailVerified = 'email.verified';

    case TotpEnabled = 'totp.enabled';
    case TotpDisabled = 'totp.disabled';
    case RecoveryCodesRegenerated = 'totp.recovery_codes_regenerated';
    case RecoveryCodeUsed = 'totp.recovery_code_used';

    case PasskeyRegistered = 'passkey.registered';
    case PasskeyRenamed = 'passkey.renamed';
    case PasskeyRevoked = 'passkey.revoked';

    case SessionRevoked = 'session.revoked';
    case SessionsRevokedAll = 'session.revoked_all';

    case InstanceSettingsChanged = 'instance.settings_changed';
    case HouseholdUpdated = 'household.updated';
    case RoleChanged = 'membership.role_changed';
    case TrustedHostAdded = 'trusted_host.added';
    case TrustedHostRemoved = 'trusted_host.removed';

    // Phase 5. A token is a standing credential, so both ends of its life are
    // recorded; what is deliberately absent is a "token used" event, which
    // would write a row on every API call and drown the log it belongs to.
    case ApiTokenIssued = 'api_token.issued';
    case ApiTokenRevoked = 'api_token.revoked';

    case DataImported = 'data.imported';
    case BackupExported = 'backup.exported';
    case BackupRestored = 'backup.restored';

    case AttachmentUploaded = 'attachment.uploaded';
    case AttachmentDeleted = 'attachment.deleted';

    /**
     * The key for a short description of the event, for the log view.
     *
     * Kept here rather than in a template because the API describes the same
     * events, and a key rather than a sentence because the log is read by
     * whoever is looking at it, in their own language.
     */
    public function labelKey(): string
    {
        return 'audit_action.' . $this->value;
    }

    /**
     * Whether the entry describes something that failed or was refused. The
     * view uses it to make a run of failures visible at a glance.
     */
    public function isFailure(): bool
    {
        return in_array($this, [self::LoginFailed, self::LoginBlocked, self::TwoFactorFailed], true);
    }
}
