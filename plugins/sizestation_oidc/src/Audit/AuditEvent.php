<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Oidc\Audit;

enum AuditEvent: string
{
    case OidcLoginSuccess = 'oidc_login_success';
    case OidcLoginFailure = 'oidc_login_failure';
    case PrincipalCreated = 'principal_created';
    case PrincipalDisabled = 'principal_disabled';
    case PrincipalEnabled = 'principal_enabled';
    case AssignmentCreated = 'assignment_created';
    case AssignmentBound = 'assignment_bound';
    case AssignmentMaterialized = 'assignment_materialized';
    case AssignmentDisabled = 'assignment_disabled';
    case AssignmentEnabled = 'assignment_enabled';
    case AssignmentRemoved = 'assignment_removed';
    case AnchorSelected = 'anchor_selected';
    case PreferredAccountChanged = 'preferred_account_changed';
    case MailboxSwitch = 'mailbox_switch';
    case CredentialRotated = 'credential_rotated';
    case CredentialValidationSuccess = 'credential_validation_success';
    case CredentialValidationFailure = 'credential_validation_failure';
    case OpenBaoUnavailable = 'openbao_unavailable';
    case ReconciliationStarted = 'reconciliation_started';
    case ReconciliationCompleted = 'reconciliation_completed';
    case ReconciliationFailed = 'reconciliation_failed';
    case CompleteLogout = 'complete_logout';
}
