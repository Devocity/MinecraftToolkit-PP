<?php

declare(strict_types=1);

namespace BlueWolf\MinecraftToolkit\Services;

use App\Exceptions\Repository\FileExistsException;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use BlueWolf\MinecraftToolkit\Exceptions\MinecraftToolkitException;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class MinecraftServerFileService
{
    /** @var array<string, string> */
    private array $backupDirectories = [];

    public function write(Server $server, string $path, string $contents): void
    {
        $this->repository($server)->putContent($this->safePath($path), $contents)->throw();
    }

    public function read(Server $server, string $path, ?int $limit = null): string
    {
        return $this->repository($server)->getContent($this->safePath($path), $limit);
    }

    public function makeDirectory(Server $server, string $path): void
    {
        $path = $this->safePath($path);
        $parent = dirname($path);
        if ($parent !== '/' && $parent !== '\\' && $parent !== '.') {
            $this->makeDirectory($server, $parent);
        }

        $this->ensureDirectory($this->repository($server), basename($path), $parent === '\\' ? '/' : $parent);
    }

    /** @return array<int, array<string, mixed>> */
    public function listDirectory(Server $server, string $path): array
    {
        return collect($this->repository($server)->getDirectory($this->safePath($path)))
            ->filter(fn (mixed $file): bool => is_array($file))
            ->values()
            ->all();
    }

    public function exists(Server $server, string $path): bool
    {
        $path = $this->safePath($path);
        $directory = dirname($path);
        $name = basename($path);

        try {
            return collect($this->repository($server)->getDirectory($directory === '\\' ? '/' : $directory))
                ->contains(fn (mixed $file): bool => is_array($file) && ($file['name'] ?? null) === $name);
        } catch (\Throwable $exception) {
            if ($this->isMissingPathException($exception)) {
                return false;
            }

            throw $exception;
        }
    }

    private function isMissingPathException(\Throwable $exception): bool
    {
        return $exception instanceof FileNotFoundException
            || ($exception instanceof RequestException && $exception->response->status() === 404);
    }

    public function pullJar(Server $server, string $url, string $fileName = 'server.jar'): void
    {
        $this->assertFileName($fileName, ['jar']);
        $this->downloadJar($server, $url, '/'.$fileName);
    }

    /** @param string[] $allowedExtensions */
    public function pullFile(Server $server, string $url, string $fileName, array $allowedExtensions = ['jar']): void
    {
        $this->assertFileName($fileName, $allowedExtensions);
        $download = $this->downloadContents($url, $allowedExtensions);
        $this->writeAtomically($server, '/'.$fileName, $download['contents']);
    }

    /** @param string[] $allowedExtensions */
    public function downloadFile(Server $server, string $url, string $fileName, array $allowedExtensions = ['jar']): array
    {
        $this->assertFileName($fileName, $allowedExtensions);
        $this->assertDownloadUrl($url);

        $response = Http::withUserAgent((string) config('minecrafttoolkit.user_agent'))
            ->withHeaders([
                'Accept' => 'application/zip,application/octet-stream,*/*',
            ])
            ->connectTimeout(10)
            ->timeout((int) config('minecrafttoolkit.download_timeout', 300))
            ->get($url)
            ->throw();

        $contents = $response->body();
        if ($contents === '' || strlen($contents) > (int) config('minecrafttoolkit.max_package_bytes', 104857600)) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.download_empty_or_too_large'));
        }

        $this->writeAtomically($server, '/'.$fileName, $contents);

        return [
            'sha1' => hash('sha1', $contents),
            'sha256' => hash('sha256', $contents),
            'sha512' => hash('sha512', $contents),
            'size' => strlen($contents),
        ];
    }

    /** @param string[] $allowedExtensions
     * @return array{contents: string, sha1: string, sha256: string, sha512: string, size: int}
     */
    public function downloadContents(string $url, array $allowedExtensions = ['jar', 'zip', 'mrpack']): array
    {
        $path = parse_url($url, PHP_URL_PATH);
        $extension = strtolower(pathinfo(is_string($path) ? $path : '', PATHINFO_EXTENSION));
        if ($extension !== '' && ! in_array($extension, $allowedExtensions, true)) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.download_type_not_allowed'));
        }

        $response = $this->downloadResponse($url);
        $contents = $response->body();
        if ($contents === '' || strlen($contents) > $this->configInt('max_package_bytes', 104857600)) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.download_empty_or_too_large'));
        }

        return [
            'contents' => $contents,
            'sha1' => hash('sha1', $contents),
            'sha256' => hash('sha256', $contents),
            'sha512' => hash('sha512', $contents),
            'size' => strlen($contents),
        ];
    }

    /** @param string[] $allowedExtensions */
    private function assertFileName(string $fileName, array $allowedExtensions): void
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (! preg_match('/^[a-zA-Z0-9._-]+$/', $fileName)
            || ! in_array($extension, $allowedExtensions, true)) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.download_target_name_invalid'));
        }
    }

    /** @param array<string, string> $hashes */
    public function downloadJar(Server $server, string $url, string $path, array $hashes = []): void
    {
        $this->downloadJarWithMetadata($server, $url, $path, $hashes);
    }

    /** @param array<string, string> $hashes
     * @param  null|callable(array{sha1: string, sha256: string, sha512: string, size: int, plugin_version: ?string, class_major_version: ?int}): void  $beforeWrite
     * @return array{sha1: string, sha256: string, sha512: string, size: int, plugin_version: ?string, class_major_version: ?int}
     */
    public function downloadJarWithMetadata(
        Server $server,
        string $url,
        string $path,
        array $hashes = [],
        ?callable $beforeWrite = null,
    ): array {
        $this->assertDownloadUrl($url);
        $path = $this->safePath($path);
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'jar') {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.only_jar_allowed'));
        }

        $response = $this->downloadResponse($url);
        $contents = $response->body();

        if ($contents === '' || strlen($contents) > $this->configInt('max_package_bytes', 104857600)) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.download_empty_or_too_large'));
        }

        $this->assertJarMagicBytes($contents);
        $this->assertExpectedHashes($contents, $hashes);

        $metadata = $this->inspectJarContents($contents);
        if ($beforeWrite !== null) {
            $beforeWrite($metadata);
        }

        $this->writeAtomically($server, $path, $contents);

        return $metadata;
    }

    /** @param array<string, string> $hashes
     * @return array{sha1: string, sha256: string, sha512: string, size: int, plugin_version: ?string, class_major_version: ?int}
     */
    public function inspectExistingJarWithMetadata(Server $server, string $path, array $hashes): array
    {
        if (! $this->hasVerifiableHash($hashes)) {
            throw new MinecraftToolkitException(
                trans('minecrafttoolkit::strings.messages.existing_file_no_checksum')
            );
        }

        $contents = $this->read(
            $server,
            $this->safePath($path),
            $this->configInt('max_package_bytes', 104857600) + 1
        );
        if ($contents === '' || strlen($contents) > $this->configInt('max_package_bytes', 104857600)) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.existing_file_empty_or_too_large'));
        }

        $this->assertExpectedHashes($contents, $hashes);

        return $this->inspectJarContents($contents);
    }

    /** @return array{sha1: string, sha256: string, sha512: string, size: int, plugin_version: ?string, class_major_version: ?int} */
    public function inspectJarContents(string $contents): array
    {
        if ($contents === '' || strlen($contents) > $this->configInt('max_package_bytes', 104857600)) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.package_content_empty_or_too_large'));
        }

        $this->assertJarMagicBytes($contents);
        $this->assertSafeJarStructure($contents);

        $metadata = [
            'sha1' => hash('sha1', $contents),
            'sha256' => hash('sha256', $contents),
            'sha512' => hash('sha512', $contents),
            'size' => strlen($contents),
            'plugin_version' => $this->extractPluginVersionFromJar($contents),
            'class_major_version' => $this->extractMaxClassMajorVersionFromJar($contents),
        ];

        $this->assertJavaClassVersionAllowed($metadata['class_major_version']);

        return $metadata;
    }

    /** @return array{sha1: string, sha256: string, sha512: string, size: int} */
    public function inspectFileContents(string $contents): array
    {
        if ($contents === '' || strlen($contents) > $this->configInt('max_package_bytes', 104857600)) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.file_content_empty_or_too_large'));
        }

        return [
            'sha1' => hash('sha1', $contents),
            'sha256' => hash('sha256', $contents),
            'sha512' => hash('sha512', $contents),
            'size' => strlen($contents),
        ];
    }

    private function downloadResponse(string $url): Response
    {
        $currentUrl = $url;

        for ($redirects = 0; $redirects <= 5; $redirects++) {
            $this->assertDownloadUrl($currentUrl);

            $response = Http::withUserAgent($this->configString('user_agent', 'Pelican-Minecraft-Toolkit/1.2.0'))
                ->connectTimeout(5)
                ->timeout($this->configInt('download_timeout', 300))
                ->withoutRedirecting()
                ->get($currentUrl);

            if (! in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                return $response->throw();
            }

            $location = $response->header('Location');
            if (! is_string($location) || trim($location) === '') {
                throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.redirect_invalid'));
            }

            $currentUrl = $this->resolveRedirectUrl($currentUrl, $location);
        }

        throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.too_many_redirects'));
    }

    private function extractPluginVersionFromJar(string $contents): ?string
    {
        if (! class_exists(\ZipArchive::class)) {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'mtk-jar-');
        if ($tmp === false) {
            return null;
        }

        try {
            file_put_contents($tmp, $contents);
            $zip = new \ZipArchive;
            if ($zip->open($tmp) !== true) {
                return null;
            }

            foreach (['plugin.yml', 'paper-plugin.yml', 'bungee.yml', 'velocity-plugin.json'] as $entry) {
                $data = $zip->getFromName($entry);
                if (! is_string($data)) {
                    continue;
                }

                if ($entry === 'velocity-plugin.json') {
                    $json = json_decode($data, true);
                    if (is_array($json) && is_string($json['version'] ?? null)) {
                        return trim($json['version']);
                    }
                }

                if (preg_match('/^version:\s*["\']?([^"\'\r\n#]+)["\']?/mi', $data, $matches)) {
                    return trim($matches[1]);
                }
            }

            return null;
        } finally {
            if (isset($zip) && $zip instanceof \ZipArchive) {
                $zip->close();
            }
            @unlink($tmp);
        }
    }

    private function extractMaxClassMajorVersionFromJar(string $contents): ?int
    {
        if (! class_exists(\ZipArchive::class)) {
            return null;
        }

        return $this->withJar($contents, function (\ZipArchive $zip): ?int {
            $max = null;

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                if (! is_string($name) || ! str_ends_with(strtolower($name), '.class')) {
                    continue;
                }

                $data = $zip->getFromIndex($index);
                if (! is_string($data) || strlen($data) < 8 || substr($data, 0, 4) !== "\xCA\xFE\xBA\xBE") {
                    continue;
                }

                $header = unpack('nminor/nmajor', substr($data, 4, 4));
                $major = is_array($header) ? (int) ($header['major'] ?? 0) : 0;
                if ($major > 0) {
                    $max = $max === null ? $major : max($max, $major);
                }
            }

            return $max;
        });
    }

    private function assertJavaClassVersionAllowed(?int $majorVersion): void
    {
        $allowed = $this->configInt('java_class_version_max', 69);
        if ($majorVersion === null || $allowed <= 0 || $majorVersion <= $allowed) {
            return;
        }

        throw new MinecraftToolkitException(
            trans('minecrafttoolkit::strings.messages.java_class_version_too_high', ['version' => $majorVersion, 'allowed' => $allowed])
        );
    }

    public function backupIfPresent(Server $server, string $path): ?string
    {
        if (! $this->exists($server, $path)) {
            return null;
        }

        $timestamp = now()->format('Y-m-d-H-i-s');
        $base = '/.minecraft-toolkit';
        $backupRoot = "$base/backups";
        $target = $this->backupDirectories[$server->uuid] ?? "$backupRoot/$timestamp";
        $repository = $this->repository($server);

        $this->ensureDirectory($repository, '.minecraft-toolkit', '/');
        $this->ensureDirectory($repository, 'backups', $base);
        $this->ensureDirectory($repository, basename($target), $backupRoot);
        $destination = "$target/".basename($path);
        if ($this->exists($server, $destination)) {
            $retryDirectory = "$target/retries";
            $this->ensureDirectory($repository, 'retries', $target);
            $destination = $retryDirectory.'/'.now()->format('Y-m-d-H-i-s').'-'.bin2hex(random_bytes(4)).'-'.basename($path);
        }
        $repository->renameFiles('/', [[
            'from' => ltrim($path, '/'),
            'to' => ltrim($destination, '/'),
        ]])->throw();

        return $destination;
    }

    public function withBackupDirectory(Server $server, string $directory, callable $callback): mixed
    {
        $directory = $this->safeBackupPath($directory);
        $key = $server->uuid;
        $previous = $this->backupDirectories[$key] ?? null;
        $this->backupDirectories[$key] = $directory;

        try {
            return $callback();
        } finally {
            if ($previous === null) {
                unset($this->backupDirectories[$key]);
            } else {
                $this->backupDirectories[$key] = $previous;
            }
        }
    }

    public function move(Server $server, string $from, string $to): void
    {
        $from = $this->safePath($from);
        $to = $this->safePath($to);
        $this->repository($server)->renameFiles('/', [[
            'from' => ltrim($from, '/'),
            'to' => ltrim($to, '/'),
        ]])->throw();
    }

    public function delete(Server $server, string $path): void
    {
        $path = $this->safePath($path);
        $this->repository($server)->deleteFiles('/', [ltrim($path, '/')])->throw();
    }

    /** @return array<string, mixed> */
    public function compress(Server $server, string $root, array $paths, string $name, string $extension = 'tar.gz'): array
    {
        $root = $this->safePath($root);
        $safePaths = collect($paths)->map(fn (string $path): string => ltrim($this->safePath($path), '/'))->all();
        if (! preg_match('/^[A-Za-z0-9._-]+$/', $name) || ! in_array($extension, ['zip', 'tar.gz'], true)) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.archive_invalid'));
        }

        return $this->repository($server)->compressFiles($root, $safePaths, $name, $extension);
    }

    public function decompress(Server $server, string $root, string $archive): void
    {
        $root = $this->safePath($root);
        $archive = basename($this->safePath($archive));
        $this->repository($server)->decompressFile($root, $archive)->throw();
    }

    public function writeAtomically(Server $server, string $path, string $contents): ?string
    {
        $path = $this->safePath($path);
        $directory = dirname($path);
        $directory = $directory === '\\' || $directory === '.' ? '/' : $directory;
        if ($directory !== '/') {
            $this->makeDirectory($server, $directory);
        }

        $temporaryPath = rtrim($directory, '/').'/.mctk-upload-'.bin2hex(random_bytes(12)).'.tmp';
        $temporaryPath = $this->safePath($temporaryPath);
        $backup = null;

        try {
            $this->write($server, $temporaryPath, $contents);
            $written = $this->read($server, $temporaryPath, strlen($contents) + 1);
            if (strlen($written) !== strlen($contents)
                || ! hash_equals(hash('sha256', $contents), hash('sha256', $written))) {
                throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.temp_file_unverified'));
            }

            if ($this->exists($server, $path)) {
                $backup = $this->backupIfPresent($server, $path);
            }
            $this->move($server, $temporaryPath, $path);

            return $backup;
        } catch (\Throwable $exception) {
            try {
                if ($this->exists($server, $temporaryPath)) {
                    $this->delete($server, $temporaryPath);
                }
            } catch (\Throwable) {
            }

            try {
                if ($backup !== null && ! $this->exists($server, $path) && $this->exists($server, $backup)) {
                    $this->move($server, $backup, $path);
                }
            } catch (\Throwable) {
            }

            throw $exception;
        }
    }

    public function restoreBackupFile(Server $server, string $backupPath, string $fileName, string $targetPath): ?string
    {
        $backupPath = $this->safeBackupPath($backupPath);
        $fileName = $this->safeBackupFileName($fileName);
        $targetPath = $this->safePath($targetPath);

        $contents = $this->read(
            $server,
            "$backupPath/$fileName",
            $this->configInt('max_package_bytes', 104857600) + 1
        );
        if ($contents === '' || strlen($contents) > $this->configInt('max_package_bytes', 104857600)) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.backup_file_empty_or_too_large'));
        }

        $currentBackup = $this->backupIfPresent($server, $targetPath);
        try {
            $this->writeAtomically($server, $targetPath, $contents);
        } catch (\Throwable $exception) {
            try {
                if ($currentBackup !== null && ! $this->exists($server, $targetPath) && $this->exists($server, $currentBackup)) {
                    $this->move($server, $currentBackup, $targetPath);
                }
            } catch (\Throwable) {
            }

            throw $exception;
        }

        return $currentBackup;
    }

    /** @return array<int, array{path: string, created: string, files: array<int, array{name: string, size: int|null}>}> */
    public function listBackups(Server $server, int $limit = 10): array
    {
        $root = '/.minecraft-toolkit/backups';

        try {
            $directories = collect($this->listDirectory($server, $root))
                ->filter(fn (array $file): bool => (bool) ($file['is_file'] ?? false) === false)
                ->sortByDesc(fn (array $file): string => (string) ($file['name'] ?? ''))
                ->take(max(1, $limit));

            return $directories
                ->map(function (array $directory) use ($server, $root): array {
                    $name = (string) ($directory['name'] ?? '');
                    $path = "$root/$name";

                    try {
                        $files = collect($this->listDirectory($server, $path))
                            ->filter(fn (array $file): bool => (bool) ($file['is_file'] ?? true))
                            ->map(fn (array $file): array => [
                                'name' => (string) ($file['name'] ?? ''),
                                'size' => isset($file['size']) ? (int) $file['size'] : null,
                            ])
                            ->filter(fn (array $file): bool => $file['name'] !== '')
                            ->values()
                            ->all();
                    } catch (\Throwable) {
                        $files = [];
                    }

                    return [
                        'path' => $path,
                        'created' => $name,
                        'files' => $files,
                    ];
                })
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    private function ensureDirectory(DaemonFileRepository $repository, string $name, string $path): void
    {
        try {
            $repository->createDirectory($name, $path)->throw();
        } catch (FileExistsException) {
        }
    }

    private function repository(Server $server): DaemonFileRepository
    {
        return (new DaemonFileRepository)->setServer($server);
    }

    private function safePath(string $path): string
    {
        $path = '/'.ltrim(str_replace('\\', '/', $path), '/');
        if (str_contains($path, "\0") || str_contains($path, '../')) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.file_path_invalid'));
        }

        return $path;
    }

    private function safeBackupPath(string $path): string
    {
        $path = $this->safePath($path);
        if (! preg_match('#^/\.minecraft-toolkit/backups/[0-9]{4}-[0-9]{2}-[0-9]{2}-[0-9]{2}-[0-9]{2}-[0-9]{2}(?:-[0-9a-f]{8})?$#', $path)) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.backup_path_invalid'));
        }

        return $path;
    }

    private function safeBackupFileName(string $fileName): string
    {
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._+() -]{0,199}$/', $fileName)
            || str_contains($fileName, '..')
            || str_contains($fileName, '/')
            || str_contains($fileName, '\\')) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.backup_file_name_invalid'));
        }

        return $fileName;
    }

    private function assertJarMagicBytes(string $contents): void
    {
        if (str_starts_with($contents, "PK\x03\x04")
            || str_starts_with($contents, "PK\x05\x06")
            || str_starts_with($contents, "PK\x07\x08")) {
            return;
        }

        throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.download_not_jar_zip'));
    }

    /** @param array<string, string> $hashes */
    private function assertStrongHashPolicy(array $hashes): void
    {
        if (! $this->configBool('hash_required', false)) {
            return;
        }

        if (is_string($hashes['sha512'] ?? null) || is_string($hashes['sha256'] ?? null)) {
            return;
        }

        throw new MinecraftToolkitException(
            trans('minecrafttoolkit::strings.messages.strong_checksum_required')
        );
    }

    /** @param array<string, string> $hashes */
    private function assertExpectedHashes(string $contents, array $hashes): void
    {
        $this->assertStrongHashPolicy($hashes);

        $expectedSha512 = Arr::get($hashes, 'sha512');
        $expectedSha256 = Arr::get($hashes, 'sha256');
        $expectedSha1 = Arr::get($hashes, 'sha1');
        $expectedMd5 = Arr::get($hashes, 'md5');
        if (is_string($expectedSha512) && ! hash_equals(strtolower($expectedSha512), hash('sha512', $contents))) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.sha512_invalid'));
        }
        if (! is_string($expectedSha512)
            && is_string($expectedSha256)
            && ! hash_equals(strtolower($expectedSha256), hash('sha256', $contents))) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.sha256_invalid'));
        }
        if (! is_string($expectedSha512)
            && ! is_string($expectedSha256)
            && is_string($expectedSha1)
            && ! hash_equals(strtolower($expectedSha1), hash('sha1', $contents))) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.sha1_invalid'));
        }
        if (! is_string($expectedSha512)
            && ! is_string($expectedSha256)
            && ! is_string($expectedSha1)
            && is_string($expectedMd5)
            && ! hash_equals(strtolower($expectedMd5), md5($contents))) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.md5_invalid'));
        }
    }

    /** @param array<string, string> $hashes */
    private function hasVerifiableHash(array $hashes): bool
    {
        foreach (['sha512', 'sha256', 'sha1', 'md5'] as $algorithm) {
            if (is_string($hashes[$algorithm] ?? null) && trim($hashes[$algorithm]) !== '') {
                return true;
            }
        }

        return false;
    }

    private function assertSafeJarStructure(string $contents): void
    {
        if (! class_exists(\ZipArchive::class)) {
            return;
        }

        $this->withJar($contents, function (\ZipArchive $zip): void {
            if ($zip->numFiles > $this->configInt('max_jar_entries', 20000)) {
                throw new MinecraftToolkitException('Die JAR enthaelt zu viele Dateien.');
            }

            $maxEntryBytes = $this->configInt('max_jar_entry_bytes', 52428800);
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                $stat = $zip->statIndex($index);
                if (! is_string($name)
                    || $name === ''
                    || str_contains($name, "\0")
                    || str_contains($name, '\\')
                    || str_starts_with($name, '/')
                    || preg_match('#(^|/)\.\.(/|$)#', $name)
                    || preg_match('/^[A-Za-z]:/', $name)) {
                    throw new MinecraftToolkitException('Die JAR enthaelt unsichere Dateipfade.');
                }

                $size = is_array($stat) ? (int) ($stat['size'] ?? 0) : 0;
                if ($size > $maxEntryBytes) {
                    throw new MinecraftToolkitException('Die JAR enthaelt eine zu grosse Einzeldatei.');
                }
            }
        });
    }

    /** @param callable(\ZipArchive): mixed $callback */
    private function withJar(string $contents, callable $callback): mixed
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mtk-jar-');
        if ($tmp === false) {
            return null;
        }

        try {
            file_put_contents($tmp, $contents);
            $zip = new \ZipArchive;
            if ($zip->open($tmp) !== true) {
                throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.jar_unreadable'));
            }

            return $callback($zip);
        } finally {
            if (isset($zip) && $zip instanceof \ZipArchive) {
                $zip->close();
            }
            @unlink($tmp);
        }
    }

    private function resolveRedirectUrl(string $baseUrl, string $location): string
    {
        if (parse_url($location, PHP_URL_SCHEME) !== null) {
            return $location;
        }

        $base = parse_url($baseUrl);
        $scheme = (string) ($base['scheme'] ?? 'https');
        $host = (string) ($base['host'] ?? '');
        $port = isset($base['port']) ? ':'.$base['port'] : '';
        if (str_starts_with($location, '//')) {
            return "$scheme:$location";
        }
        if (str_starts_with($location, '/')) {
            return "$scheme://$host$port$location";
        }

        $path = (string) ($base['path'] ?? '/');
        $directory = rtrim(str_replace('\\', '/', dirname($path)), '/');

        return "$scheme://$host$port$directory/$location";
    }

    private function hostUsesPrivateAddress(string $host): bool
    {
        if (! $this->configBool('block_private_download_ips', true)) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->isPrivateAddress($host);
        }

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (! is_array($records) || $records === []) {
            $ips = @gethostbynamel($host) ?: [];
            $records = array_map(fn (string $ip): array => ['ip' => $ip], $ips);
        }

        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip) && $this->isPrivateAddress($ip)) {
                return true;
            }
        }

        return false;
    }

    private function isPrivateAddress(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    private function configBool(string $key, bool $default): bool
    {
        return filter_var($this->configValue($key, $default), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    private function configInt(string $key, int $default): int
    {
        return max(0, (int) $this->configValue($key, $default));
    }

    private function configString(string $key, string $default): string
    {
        return (string) $this->configValue($key, $default);
    }

    private function configValue(string $key, mixed $default): mixed
    {
        try {
            return function_exists('config') ? config("minecrafttoolkit.$key", $default) : $default;
        } catch (\Throwable) {
            return $default;
        }
    }

    private function assertDownloadUrl(string $url): void
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'https'
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || ! in_array((int) ($parts['port'] ?? 443), [443], true)) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.download_url_not_allowed'));
        }
        $allowedDomains = [
            'mojang.com',
            'minecraft.net',
            'papermc.io',
            'purpurmc.org',
            'modrinth.com',
            'geysermc.org',
            'fabricmc.net',
            'minecraftforge.net',
            'neoforged.net',
            'curseforge.com',
            'forgecdn.net',
        ];
        $allowed = false;
        foreach ($allowedDomains as $domain) {
            if ($host === $domain || str_ends_with($host, ".$domain")) {
                $allowed = true;
                break;
            }
        }

        if (! $allowed || $this->hostUsesPrivateAddress($host)) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.download_url_not_allowed'));
        }
    }
}
