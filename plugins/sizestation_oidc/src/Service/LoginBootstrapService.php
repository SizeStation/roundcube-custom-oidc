<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Oidc\Service;

use SizeStation\Roundcube\Oidc\Audit\AuditEvent;
use SizeStation\Roundcube\Oidc\Repository\AssignmentRepository;
use SizeStation\Roundcube\Oidc\Repository\AuditLogRepository;
use SizeStation\Roundcube\Oidc\Repository\PrincipalRepository;
use SizeStation\Roundcube\Oidc\Security\ValidatedIdentity;

final class LoginBootstrapService
{
    public function __construct(
        private readonly PrincipalRepository $principals,
        private readonly AssignmentRepository $assignments,
        private readonly AuditLogRepository $audit,
    ) {
    }

    public function prepare(ValidatedIdentity $identity, ?string $sourceIp, ?string $userAgent): LoginPhase
    {
        $existing = $this->principals->findBySubject($identity->issuer, $identity->subject);
        $principal = $this->principals->resolveOrCreate(
            $identity->issuer,
            $identity->subject,
            $identity->externalUserId,
            [
                'email' => $identity->email,
                'preferred_username' => $identity->preferredUsername,
                'display_name' => $identity->displayName,
            ],
        );
        $principalId = (int) $principal['id'];
        if ($existing === null) {
            $this->audit->record(
                AuditEvent::PrincipalCreated,
                'oidc',
                $identity->subject,
                $principalId,
                metadata: ['issuer' => $identity->issuer],
                sourceIp: $sourceIp,
                userAgent: $userAgent,
            );
        }

        $assignments = $this->assignments->bindPending(
            $principalId,
            $identity->issuer,
            $identity->externalUserId,
        );
        $anchor = $this->assignments->anchor($assignments);
        $this->audit->record(
            AuditEvent::AssignmentBound,
            'oidc',
            $identity->subject,
            $principalId,
            (string) $anchor['id'],
            ['assignment_count' => count($assignments)],
            $sourceIp,
            $userAgent,
        );
        $this->audit->record(
            AuditEvent::AnchorSelected,
            'oidc',
            $identity->subject,
            $principalId,
            (string) $anchor['id'],
            sourceIp: $sourceIp,
            userAgent: $userAgent,
        );

        return new LoginPhase($principal, $anchor, $assignments, $identity);
    }
}
