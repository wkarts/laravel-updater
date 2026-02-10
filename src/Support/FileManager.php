<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Support;

use Illuminate\Filesystem\Filesystem;

class FileManager
{
    public function __construct(private readonly Filesystem $files)
    {
    }

    public function ensureDirectory(string $path): void
    {
        if (!$this->files->isDirectory($path)) {
            $this->files->makeDirectory($path, 0755, true);
        }
    }

    public function deleteOldFiles(string $path, int $keep): void
    {
        $this->ensureDirectory($path);
        $files = collect($this->files->files($path))->sortByDesc(fn ($file) => $file->getMTime())->values();
        $files->slice($keep)->each(fn ($file) => $this->files->delete($file->getPathname()));
    }
}
