<?php

namespace App\Compliance\Dsar\IO\Export;

use ZipArchive;
use Illuminate\Filesystem\Filesystem;

class DsarExportArchive
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly string $workDirectory,
    ) {}

    /**
     * The export as ZIP bytes — one data.json holding every section, indented
     * for reading. The ZIP is built at a fresh name in the work directory
     * (reserved, then freed, so nothing else holds the file), and nothing of
     * it is left there when this returns.
     *
     * @param  array<string, mixed>  $sections
     */
    public function build(array $sections): string
    {
        $path = (string) tempnam($this->workDirectory, 'dsar');
        $this->files->delete($path);

        try {
            $zip = new ZipArchive;
            $zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL);
            $zip->addFromString('data.json', json_encode($sections, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            $zip->close();

            return $this->files->get($path);
        } finally {
            $this->files->delete($path);
        }
    }
}
