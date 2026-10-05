<?php

namespace App\Compliance\Dsar\IO\Export;

use ZipArchive;
use Illuminate\Support\Str;
use Illuminate\Filesystem\Filesystem;

class DsarExportArchive
{
    public function __construct(private readonly Filesystem $files) {}

    /**
     * The export as ZIP bytes — one data.json holding every section. The ZIP
     * is built at a fresh temporary path that nothing else has opened, and
     * that path is gone when this returns.
     *
     * @param  array<string, mixed>  $sections
     */
    public function build(array $sections): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dsar-'.Str::uuid7()->toString().'.zip';

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
