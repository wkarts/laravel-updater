<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Support;

class MaintenanceMode
{
    /** @return array<int,string> */
    public function downCommand(?string $renderView = null): array
    {
        $command = ['php', 'artisan', 'down'];

        if ($renderView !== null && trim($renderView) !== '') {
            $command[] = '--render=' . trim($renderView);
        }

        foreach ($this->exceptPaths() as $path) {
            $command[] = '--except=' . $path;
        }

        return $command;
    }

    /** @return array<int,string> */
    public function exceptPaths(): array
    {
        $raw = config('updater.maintenance.except_paths', []);
        $items = is_array($raw) ? $raw : [];

        $prefix = trim((string) config('updater.ui.prefix', '_updater'), '/');
        if ($prefix !== '') {
            $items[] = $prefix;
            $items[] = $prefix . '/*';
        }

        $clean = [];
        foreach ($items as $item) {
            $path = trim((string) $item, " \t\n\r\0\x0B/");
            if ($path === '') {
                continue;
            }
            $clean[] = $path;
        }

        return array_values(array_unique($clean));
    }
}
