<?php

declare(strict_types=1);

namespace Marrow\Auth\Concerns;

use Marrow\Application;
use Marrow\Database\Connection;

/**
 * Auditable — automatically log model create/update/delete events.
 *
 * Attach to a Model subclass:
 *   use Auditable;
 *
 * Requires the audit_logs table (see Audit module migration).
 *
 * Events logged: created, updated, deleted.
 * Tracks: user_id, event, model_type, model_id, old_values, new_values, ip, user_agent.
 *
 * Opt-out of specific fields:
 *   protected array $auditExclude = ['password', 'remember_token'];
 */
trait Auditable
{
    /** Fields that should never appear in audit logs. */
    protected array $auditExclude = ['password', 'password_confirmation', 'remember_token'];

    // ── Lifecycle hooks (call from Model::save/delete) ─────────────────

    /**
     * Log a "created" event.
     * Call after a successful INSERT in save().
     */
    public function auditCreated(): void
    {
        $this->writeAuditLog('created', [], $this->auditableAttributes());
    }

    /**
     * Log an "updated" event.
     *
     * @param array $original Attribute values before the update.
     */
    public function auditUpdated(array $original): void
    {
        $current  = $this->auditableAttributes();
        $old      = array_intersect_key($original, $current);
        $changed  = array_diff_assoc($current, $old);

        if (empty($changed)) {
            return;
        }

        $this->writeAuditLog('updated', $old, $changed);
    }

    /**
     * Log a "deleted" event.
     * Call before a DELETE.
     */
    public function auditDeleted(): void
    {
        $this->writeAuditLog('deleted', $this->auditableAttributes(), []);
    }

    // ── Audit log query ───────────────────────────────────────────────

    /** Retrieve audit log entries for this model instance. */
    public function auditLogs(): array
    {
        try {
            return $this->auditDb()->select(
                'SELECT * FROM audit_logs WHERE model_type = ? AND model_id = ? ORDER BY created_at DESC',
                [$this->auditModelType(), $this->auditModelId()]
            );
        } catch (\Throwable) {
            return [];
        }
    }

    // ── Internal ──────────────────────────────────────────────────────

    private function writeAuditLog(string $event, array $old, array $new): void
    {
        try {
            $userId = $this->resolveAuditUserId();

            $this->auditDb()->statement(
                'INSERT INTO audit_logs
                 (user_id, event, model_type, model_id, old_values, new_values, ip_address, user_agent, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $userId,
                    $event,
                    $this->auditModelType(),
                    $this->auditModelId(),
                    $old  ? json_encode($old,  JSON_UNESCAPED_UNICODE) : null,
                    $new  ? json_encode($new,  JSON_UNESCAPED_UNICODE) : null,
                    $_SERVER['REMOTE_ADDR']     ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null,
                    date('Y-m-d H:i:s'),
                ]
            );
        } catch (\Throwable) {
            // Audit failures must never break the application
        }
    }

    private function auditableAttributes(): array
    {
        $attrs = property_exists($this, 'attributes') ? $this->attributes : (array) $this;
        return array_diff_key($attrs, array_flip($this->auditExclude));
    }

    private function auditModelType(): string
    {
        return static::class;
    }

    private function auditModelId(): int|string|null
    {
        $pk = property_exists($this, 'primaryKey') ? $this->primaryKey : 'id';
        return $this->{$pk} ?? null;
    }

    private function resolveAuditUserId(): int|string|null
    {
        try {
            $auth = Application::getInstance()->getContainer()->make(\Marrow\Auth\AuthManager::class);
            return $auth->id();
        } catch (\Throwable) {
            return null;
        }
    }

    private function auditDb(): Connection
    {
        return Application::getInstance()->getContainer()->make(Connection::class);
    }
}
