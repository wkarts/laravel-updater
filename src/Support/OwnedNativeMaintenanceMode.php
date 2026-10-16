<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Support;

use Illuminate\Contracts\Foundation\MaintenanceMode;

/** Acrescenta a identidade na mesma gravação feita pelo artisan down. */
final class OwnedNativeMaintenanceMode implements MaintenanceMode
{
    public function __construct(private readonly MaintenanceMode $driver, private readonly string $owner)
    {
    }

    public function activate(array $payload): void
    {
        $this->driver->activate([
            ...$payload,
            UpdateMaintenanceState::NATIVE_OWNER_KEY => $this->owner,
        ]);
    }

    public function deactivate(): void { $this->driver->deactivate(); }
    public function active(): bool { return $this->driver->active(); }
    public function data(): array { return $this->driver->data(); }
}
