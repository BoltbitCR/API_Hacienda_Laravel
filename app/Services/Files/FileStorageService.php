<?php

namespace App\Services\Files;

use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;

/**
 * Almacenamiento de archivos subidos (ex módulo legacy `files`): réplica de
 * files_upload/filesGetUrl/files_createDownloadCode sobre la tabla
 * stored_files y storage/app.
 *
 * Los archivos de tipo `hacienda` (certificados .p12 y XML) se guardan
 * cifrados en reposo con la APP_KEY; los anteriores a ese cambio siguen en
 * claro (stored_files.encrypted = false) y se leen igual.
 *
 * Códigos de error del legacy (strings; tools_reply no los mapea a HTTP):
 *   -400 demasiado grande, -402 error al subir, -404 extensión no permitida.
 */
class FileStorageService
{
    public const ERROR_TOO_BIG = '-400';

    public const ERROR_UPLOAD = '-402';

    public const ERROR_EXT_NOT_ALLOWED = '-404';

    public const DEFAULT_ALLOWED_EXT = 'jpg,JPG,jpeg,JPEG,png,PNG,gif,GIF,p12,P12,pfx,PFX,xml,XML,Xml';

    public const DEFAULT_MAX_SIZE_MB = 2;

    /** Tipos que se cifran en reposo. */
    public const ENCRYPTED_TYPES = ['hacienda'];

    /** @var list<string> Temporales descifrados pendientes de borrar. */
    private array $temps = [];

    /** Ruta local del archivo por downloadCode, o null si no existe. */
    public function findPathByDownloadCode(string $downloadCode): ?string
    {
        if ($downloadCode === '') {
            return null;
        }

        $file = StoredFile::where('download_code', $downloadCode)->first();
        if ($file === null) {
            return null;
        }

        $path = storage_path('app/'.$file->path);

        return is_file($path) ? $path : null;
    }

    public function findByDownloadCode(string $downloadCode): ?StoredFile
    {
        return $downloadCode === '' ? null : StoredFile::where('download_code', $downloadCode)->first();
    }

    /** Contenido en claro del archivo (descifrado si hace falta), o null si no existe. */
    public function readContents(StoredFile $file): ?string
    {
        $path = storage_path('app/'.$file->path);
        if (! is_file($path)) {
            return null;
        }

        $raw = (string) file_get_contents($path);

        return $file->encrypted ? Crypt::decryptString($raw) : $raw;
    }

    /**
     * Ruta legible del archivo: la real si está en claro, o un temporal
     * (0600) con el contenido descifrado. Liberar con discardTemp().
     */
    public function readablePath(StoredFile $file): ?string
    {
        $path = storage_path('app/'.$file->path);
        if (! is_file($path)) {
            return null;
        }

        if (! $file->encrypted) {
            return $path;
        }

        $temp = tempnam(sys_get_temp_dir(), 'p12-');
        chmod($temp, 0600);
        file_put_contents($temp, Crypt::decryptString((string) file_get_contents($path)));
        $this->temps[] = $temp;

        return $temp;
    }

    /** Borra el temporal creado por readablePath(); no hace nada con una ruta real. */
    public function discardTemp(?string $path): void
    {
        if ($path !== null && in_array($path, $this->temps, true)) {
            @unlink($path);
            $this->temps = array_values(array_diff($this->temps, [$path]));
        }
    }

    public function __destruct()
    {
        foreach ($this->temps as $temp) {
            @unlink($temp);
        }
    }

    /**
     * Réplica de files_upload(): valida extensión y tamaño, guarda en
     * storage/app/legacy-files/<user>/<type>/ y registra en stored_files.
     *
     * @return array{idFile: string, name: string, downloadCode: string}|string
     *                                                                          El shape legacy de éxito, o el código de error como string.
     */
    public function upload(
        User $user,
        ?UploadedFile $file,
        string $type = 'attach',
        string|false $finalName = false,
        string|false $ext = false,
        int $maxSizeMb = 0,
    ): array|string {
        if ($file === null || ! $file->isValid()) {
            return self::ERROR_UPLOAD;
        }

        $allowed = $ext === false ? self::DEFAULT_ALLOWED_EXT : $ext;
        $maxSizeMb = $maxSizeMb > 0 ? $maxSizeMb : self::DEFAULT_MAX_SIZE_MB;

        $originalName = $file->getClientOriginalName();
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $name = $finalName === false ? basename($originalName) : $finalName.'.'.$extension;

        if ($file->getSize() > $maxSizeMb * 1000000) {
            return self::ERROR_TOO_BIG;
        }

        if ($allowed !== '*' && ! in_array($extension, explode(',', $allowed), true)) {
            return self::ERROR_EXT_NOT_ALLOWED;
        }

        $relativeDir = 'legacy-files/'.$user->id.'/'.$type;
        $downloadCode = $this->createDownloadCode($name, $user->id);
        $size = (int) $file->getSize();
        $encrypted = in_array($type, self::ENCRYPTED_TYPES, true);

        // Directo a storage/app: el disco "local" tiene root en app/private,
        // pero findPathByDownloadCode() y la emisión leen storage_path('app/'.path).
        $targetDir = storage_path('app/'.$relativeDir);

        if (! is_dir($targetDir) && ! @mkdir($targetDir, 0775, recursive: true)) {
            return self::ERROR_UPLOAD;
        }

        $contents = @file_get_contents($file->getRealPath());
        if ($contents === false) {
            return self::ERROR_UPLOAD;
        }

        if (@file_put_contents($targetDir.'/'.$name, $encrypted ? Crypt::encryptString($contents) : $contents) === false) {
            return self::ERROR_UPLOAD;
        }

        $stored = StoredFile::updateOrCreate(
            ['user_id' => $user->id, 'type' => $type, 'name' => $name],
            [
                // md5 del contenido original, como el legacy.
                'md5' => md5($contents),
                'legacy_timestamp' => time(),
                'size' => $size,
                'download_code' => $downloadCode,
                'file_type' => '',
                'path' => $relativeDir.'/'.$name,
                'encrypted' => $encrypted,
            ]
        );

        // Contrato legacy: valores de BD como strings.
        return [
            'idFile' => (string) $stored->id,
            'name' => $name,
            'downloadCode' => $downloadCode,
        ];
    }

    /**
     * Código de descarga de 32 hex, como el md5 del legacy pero aleatorio:
     * el original era md5(nombre."//".time().idUser), adivinable conociendo
     * el nombre del archivo y el segundo de la subida.
     */
    public function createDownloadCode(string $name, int $userId): string
    {
        return bin2hex(random_bytes(16));
    }
}
