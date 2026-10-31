<?php

namespace App\Legacy\Modules;

use App\Legacy\LegacyModule;
use App\Legacy\LegacyParams;
use App\Legacy\LegacyRoute;
use App\Services\Files\FileStorageService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Port de legacy/api/modules/files (rutas HTTP del módulo de archivos).
 *
 * Divergencia deliberada: la ruta `upload` anónima del legacy (acceso
 * abierto, escribía como usuario 0) queda deshabilitada; la subida real de
 * los clientes usa w=fileUploader con sesión.
 */
class FilesModule implements LegacyModule
{
    public function __construct(private readonly FileStorageService $files) {}

    public function routes(): array
    {
        return [
            new LegacyRoute(
                r: 'filesGetUrl',
                action: function (LegacyParams $p): string|false {
                    // El legacy devolvía el path físico del archivo o false.
                    return $this->files->findPathByDownloadCode((string) $p->get('downloadCode')) ?? false;
                },
                params: [['key' => 'downloadCode', 'def' => '', 'req' => true]],
            ),
            new LegacyRoute(
                r: 'files_view_file',
                action: function (LegacyParams $p) {
                    $file = $this->files->findByDownloadCode((string) $p->get('code', ''));
                    $path = $file ? storage_path('app/'.$file->path) : null;
                    $contents = $file ? $this->files->readContents($file) : null;

                    if ($path === null || $contents === null) {
                        return '-405'; // ERROR_FILES_NOT_FOUND
                    }

                    return new Response($contents, 200, [
                        'Content-Type' => mime_content_type($path) ?: 'application/octet-stream',
                        'Content-Disposition' => 'filename='.time().basename($path),
                    ]);
                },
            ),
            new LegacyRoute(
                r: 'upload',
                access: LegacyRoute::ACCESS_NONE,
                action: fn (): string => FileStorageService::ERROR_UPLOAD,
            ),
        ];
    }
}
