<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\ImportMap;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\VarExporter\VarExporter;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * @experimental
 *
 * @author Kévin Dunglas <kevin@dunglas.dev>
 */
final class ImportMapManager
{
    public const PROVIDER_JSPM = 'jspm';
    public const PROVIDER_JSPM_SYSTEM = 'jspm.system';
    public const PROVIDER_SKYPACK = 'skypack';
    public const PROVIDER_JSDELIVR = 'jsdelivr';
    public const PROVIDER_UNPKG = 'unpkg';
    public const PROVIDERS = [
        self::PROVIDER_JSPM,
        self::PROVIDER_JSPM_SYSTEM,
        self::PROVIDER_SKYPACK,
        self::PROVIDER_JSDELIVR,
        self::PROVIDER_UNPKG,
    ];

    public const POLYFILL_URL = 'https://ga.jspm.io/npm:es-module-shims@1.7.0/dist/es-module-shims.js';

    /**
     * @see https://regex101.com/r/2cR9Rh/1
     *
     * Partially based on https://github.com/dword-design/package-name-regex
     */
    private const PACKAGE_PATTERN = '/^(?:https?:\/\/[\w\.-]+\/)?(?:(?<registry>\w+):)?(?<package>(?:@[a-z0-9-~][a-z0-9-._~]*\/)?[a-z0-9-~][a-z0-9-._~]*)(?:@(?<version>[\w\._-]+))?(?:(?<subpath>\/.*))?$/';

    private array $importMap;
    private array $modulesToPreload;
    private string $json;

    public function __construct(
        private readonly string $path,
        private readonly string $assetsDir = 'assets/',
        private readonly string $publicAssetsDir = 'public/assets/',
        private readonly string $assetsUrl = '/assets/',
        private readonly string $provider = self::PROVIDER_JSPM,
        private readonly bool $debug = false,
        private ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? HttpClient::create(['base_uri' => 'https://api.jspm.io/']);
    }

    public function getModulesToPreload(): array
    {
        $this->buildImportMap();

        return $this->modulesToPreload;
    }

    public function getImportMap(): string
    {
        $this->buildImportMap();

        return $this->json;
    }

    /**
     * Adds or updates packages.
     *
     * @param array<string, PackageOptions> $packages
     */
    public function require(array $packages): void
    {
        $this->createImportMap(false, $packages, []);
    }

    /**
     * Removes packages.
     *
     * @param string[] $packages
     */
    public function remove(array $packages): void
    {
        $this->createImportMap(false, [], $packages);
    }

    /**
     * Updates all existing packages to the latest version.
     */
    public function update(): void
    {
        $this->createImportMap(true, [], []);
    }

    private function loadImportMap(): void
    {
        if (isset($this->importMap)) {
            return;
        }

        $path = $this->path;
        $this->importMap = is_file($path) ? (static fn () => include $path)() : [];
    }

    private function buildImportMap(): void
    {
        if (isset($this->json)) {
            return;
        }

        $this->loadImportMap();
        $this->modulesToPreload = [];

        $importmap = ['imports' => []];
        foreach ($this->importMap as $packageName => $data) {
            if (isset($data['url'])) {
                $importmap['imports'][$packageName] = ($data['download'] ?? false) ? $this->vendorUrl($packageName) : $data['url'];
            } elseif (isset($data['path'])) {
                $importmap['imports'][$packageName] = $this->assetsUrl.$this->digestName($packageName, $data['path']);
            } else {
                continue;
            }

            if ($data['preload'] ?? false) {
                $this->modulesToPreload[] = $importmap[$packageName];
            }
        }

        // Use JSON_UNESCAPED_SLASHES | JSON_HEX_TAG to prevent XSS
        $this->json = json_encode($importmap, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_HEX_TAG);
    }

    /**
     * @param array<string, PackageOptions> $require
     * @param string[]                      $remove
     */
    private function createImportMap(bool $update, array $require, array $remove): void
    {
        $this->loadImportMap();

        foreach ($remove as $packageName) {
            if (!isset($this->importMap[$packageName])) {
                continue;
            }

            $this->cleanup($packageName);
            unset($this->importMap[$packageName]);
        }

        foreach ($require as $packageName => $packageOptions) {
            if (!$packageOptions->path) {
                continue;
            }

            $this->importMap[$packageName] = ['path' => $packageOptions->path];
            if ($packageOptions->preload) {
                $this->importMap[$packageName]['preload'] = true;
            }

            unset($require[$packageName]);
        }

        $install = [];
        $packages = [];
        foreach ($this->importMap ?? [] as $packageName => $data) {
            if (isset($data['path'])) {
                $publicPath = $this->publicAssetsDir.$this->digestName($packageName, $data['path']);
                if (is_file($publicPath)) {
                    continue;
                }

                $this->cleanup($packageName, false);
                @mkdir($this->publicAssetsDir, 0777, true);
                copy($this->assetsDir.$data['path'], $publicPath);

                continue;
            }

            $packages[$packageName] = new PackageOptions($data['download'] ?? false, $data['preload'] ?? false);
            if (preg_match(self::PACKAGE_PATTERN, $data['url'] ?? $packageName, $matches)) {
                $constraint = ($matches['registry'] ?? null) ? "{$matches['registry']}:{$matches['package']}" : $matches['package'];

                if (!$update && ($matches['version'] ?? null)) {
                    $constraint .= "@{$matches['version']}";
                }

                $install[$matches['package']] = $constraint;
            }
        }

        foreach ($require as $packageName => $packageOptions) {
            if (preg_match(self::PACKAGE_PATTERN, $packageName, $matches)) {
                $install[$matches['package']] = $packageName;
                $packages[$matches['package']] = $packageOptions;
            }
        }

        $this->jspmGenerate($install, $packages);

        file_put_contents(
            $this->path,
            sprintf("<?php\n\nreturn %s;\n", class_exists(VarExporter::class) ? VarExporter::export($this->importMap) : var_export($this->importMap)),
        );
    }

    private function jspmGenerate(array $install, array $packages): void
    {
        if (!$install) {
            return;
        }

        $json = [
            'install' => array_values($install),
            'flattenScope' => true,
            'env' => ['browser', 'module', $this->debug ? 'development' : 'production'],
        ];
        if (self::PROVIDER_JSPM !== $this->provider) {
            $json['provider'] = $this->provider;
        }

        $response = $this->httpClient->request('POST', 'generate', [
            'json' => $json,
        ]);

        if (200 !== $response->getStatusCode()) {
            $data = $response->toArray(false);

            if (isset($data['error'])) {
                throw new \RuntimeException($data['error']);
            }

            // Throws the original HttpClient exception
            $response->getHeaders();
        }

        foreach ($response->toArray()['map']['imports'] as $packageName => $url) {
            if ($packages[$packageName]->preload) {
                $this->importMap[$packageName]['preload'] = true;
            } else {
                unset($this->importMap[$packageName]['preload']);
            }

            $relativePath = 'vendor/'.$packageName.'.js';
            $localPath = $this->assetsDir.$relativePath;

            if (!$packages[$packageName]->download) {
                if ($this->importMap[$packageName]['download'] ?? false) {
                    $this->cleanup($packageName);
                }
                unset($this->importMap[$packageName]['download']);
                $this->importMap[$packageName]['url'] = $url;

                continue;
            }

            $this->importMap[$packageName]['download'] = true;
            if (($this->importMap[$packageName]['url'] ?? null) === $url) {
                continue;
            }

            $this->cleanup($packageName, false);

            $this->importMap[$packageName]['url'] = $url;

            @mkdir(\dirname($localPath), 0777, true);
            file_put_contents($localPath, $this->httpClient->request('GET', $url)->getContent());

            $publicPath = $this->publicAssetsDir.'vendor/'.$this->digestName($packageName, $relativePath);
            @mkdir(\dirname($publicPath), 0777, true);
            copy($localPath, $publicPath);
        }
    }

    private function cleanup(string $packageName, bool $cleanEmptyDirectories = true): void
    {
        if ($ths->importMap[$packageName]['download'] ?? false) {
            $assetPath = $this->assetsDir.'vendor/'.$packageName.'.js';

            if (!is_file($assetPath)) {
                return;
            }

            $publicAssetPath = $this->publicAssetsDir.'vendor/'.$this->digestName($packageName, 'vendor/'.$packageName.'.js');

            @unlink($assetPath);
            if ($cleanEmptyDirectories) {
                @rmdir(\dirname($assetPath));
            }

            @unlink($publicAssetPath);
            if ($cleanEmptyDirectories) {
                @rmdir(\dirname($publicAssetPath));
            }

            return;
        }

        if (!($ths->importMap[$packageName]['path'] ?? false)) {
            return;
        }

        $assetPath = $this->assetsDir.$this->importMap[$packageName]['path'];
        if (!is_file($assetPath)) {
            return;
        }

        $publicAssetPath = $this->publicAssetsDir.$this->digestName($packageName, $ths->importMap[$packageName]['path']);

        @unlink($publicAssetPath);
        if ($cleanEmptyDirectories) {
            @rmdir(\dirname($publicAssetPath));
        }
    }

    private function digestName(string $packageName, string $path): string
    {
        return sprintf('%s.%s.js', $packageName, hash_file('xxh128', $this->assetsDir.$path));
    }

    private function vendorUrl(string $packageName): string
    {
        return $this->assetsUrl.'vendor/'.$this->digestName($packageName, 'vendor/'.$packageName.'.js');
    }
}
