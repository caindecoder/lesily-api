<?php

namespace App\Compliance\Dsar\IO\Export;

use ZipArchive;
use Illuminate\Support\Str;
use Illuminate\Filesystem\Filesystem;
use App\Compliance\Dsar\Exceptions\DsarExportArchiveFailedException;

class DsarExportArchive
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly string $workDirectory,
    ) {}

    /**
     * The export as ZIP bytes — one data.json holding every section, indented
     * for reading, beside report.pdf. The ZIP is built at a fresh name of its
     * own in the work directory, and nothing of it is left there when this
     * returns.
     *
     * @param  array<string, mixed>  $sections
     */
    public function build(array $sections, string $reportPdf): string
    {
        $path = sprintf('%s%s%s.zip', $this->workDirectory, DIRECTORY_SEPARATOR, Str::uuid7());

        try {
            $zip = new ZipArchive;
            $opened = $zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL);
            if ($opened !== true) {
                throw new DsarExportArchiveFailedException(sprintf('The export archive could not be created (ZipArchive error %d).', $opened));
            }
            $zip->addFromString('data.json', json_encode($sections, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            $zip->addFromString('report.pdf', $reportPdf);
            $zip->close();

            return $this->files->get($path);
        } finally {
            $this->files->delete($path);
        }
    }
}
