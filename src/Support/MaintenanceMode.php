<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Support;

class MaintenanceMode
{
    /** @return array<int,string> */
    public function downCommand(?string $renderView = null, bool $includeExcept = true): array
    {
        $command = ['php', 'artisan', 'down'];

        if ($renderView !== null && trim($renderView) !== '') {
            $command[] = '--render=' . trim($renderView);
        }

        if ($includeExcept) {
            foreach ($this->exceptPaths() as $path) {
                $command[] = '--except=' . $path;
            }
        }

        return $command;
    }


    public function hasExceptOptionError(\Throwable $e): bool
    {
        $message = mb_strtolower($e->getMessage());

        return str_contains($message, '"--except" option does not exist')
            || str_contains($message, 'the "--except" option does not exist')
            || str_contains($message, '--except');
    }

    /** @return array<int,string> */
public function exceptPaths(): array
{
    $raw = config('updater.maintenance.except_paths', []);
    $items = is_array($raw) ? $raw : [];

    $prefix = trim((string) config('updater.ui.prefix', '_updater'), '/');
    if ($prefix !== '') {
        foreach ([$prefix, $prefix.'/*', '/'.$prefix, '/'.$prefix.'/*'] as $p) {
            $items[] = $p;
        }
    }

    // Assets publicados (podem ficar fora do prefixo e precisam continuar acessíveis)
    foreach ([
        'vendor/laravel-updater',
        'vendor/laravel-updater/*',
        '/vendor/laravel-updater',
        '/vendor/laravel-updater/*',
    ] as $p) {
        $items[] = $p;
    }

    $clean = [];
    foreach ($items as $item) {
        $path = trim((string) $item);
        if ($path === '') {
            continue;
        }
        $clean[] = $path;
    }

    return array_values(array_unique($clean));
}
}
