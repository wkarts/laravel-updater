<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Support;

use PDO;

/** Scope metadata is additive: existing token hashes and legacy credentials remain valid. */
class ApiTokenRegistry
{
    public const READ_SCOPES = ['status:read', 'check:read', 'runs:read', 'releases:read'];
    public const SCOPES = ['status:read', 'check:read', 'runs:read', 'releases:read', 'updates:simulate', 'updates:apply'];

    public function __construct(private readonly ManagerStore $manager, private readonly StateStore $state)
    {
    }

    public function create(string $name, array $scopes = self::READ_SCOPES): array
    {
        $scopes = array_values(array_unique(array_intersect(self::SCOPES, $scopes)));
        if ($scopes === []) {
            throw new \InvalidArgumentException('Selecione ao menos um escopo.');
        }
        $token = $this->manager->generateApiToken($name);
        $this->manager->setRuntimeOption('api_token_' . $token['id'], ['scopes' => $scopes, 'last_used_at' => null]);

        return $token + ['scopes' => $scopes];
    }

    public function authenticate(string $plain): ?array
    {
        if ($plain === '' || strlen($plain) > 1024) {
            return null;
        }
        $legacy = (string) config('updater.sync_token', '');
        if ($legacy !== '' && hash_equals($legacy, $plain)) {
            return ['id' => 'legacy', 'scopes' => self::SCOPES];
        }
        $rows = $this->state->pdo()->query('SELECT id,token_hash FROM updater_api_tokens WHERE revoked_at IS NULL')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            if (!password_verify($plain, (string) $row['token_hash'])) {
                continue;
            }
            $id = (int) $row['id'];
            $metadata = $this->manager->getRuntimeOption('api_token_' . $id, null);
            // Tokens issued before scopes existed retain their previous access.
            $scopes = is_array($metadata) ? (array) ($metadata['scopes'] ?? []) : self::SCOPES;
            $this->manager->setRuntimeOption('api_token_' . $id, ['scopes' => $scopes, 'last_used_at' => date(DATE_ATOM)]);

            return ['id' => $id, 'scopes' => $scopes];
        }

        return null;
    }

    public function tokens(): array
    {
        return array_map(function (array $token): array {
            $meta = $this->manager->getRuntimeOption('api_token_' . $token['id'], null);
            return $token + ['scopes' => is_array($meta) ? ($meta['scopes'] ?? []) : self::SCOPES,
                'last_used_at' => is_array($meta) ? ($meta['last_used_at'] ?? null) : null,
                'legacy_scopes' => !is_array($meta)];
        }, $this->manager->apiTokens());
    }
}
