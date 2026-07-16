<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Oidc\Repository;

use JsonException;
use SizeStation\Roundcube\Oidc\Audit\AuditEvent;
use SizeStation\Roundcube\Oidc\Security\Redactor;

final class AuditLogRepository
{
    public function __construct(
        private readonly \rcube_db $database,
        private readonly Redactor $redactor = new Redactor(),
    ) {
    }

    /** @param array<string, mixed> $metadata */
    public function record(
        AuditEvent $event,
        string $actorType,
        string $actorIdentifier,
        ?int $principalId = null,
        ?string $assignmentId = null,
        array $metadata = [],
        ?string $sourceIp = null,
        ?string $userAgent = null,
    ): void {
        try {
            $json = json_encode($this->redactor->redact($metadata), JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $json = '{"error":"metadata_encoding_failed"}';
        }

        $query = $this->database->query(
            'INSERT INTO ' . $this->database->table_name('sizestation_oidc_audit_log')
            . ' (principal_id, assignment_id, actor_type, actor_identifier, event_type, source_ip,'
            . ' user_agent, metadata_json, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $principalId,
            $assignmentId,
            $actorType,
            $actorIdentifier,
            $event->value,
            $sourceIp,
            $userAgent === null ? null : substr($userAgent, 0, 512),
            $json,
            gmdate('Y-m-d\TH:i:s\Z'),
        );

        if (!$query) {
            throw new RepositoryException('Unable to record the audit event');
        }
    }
}
