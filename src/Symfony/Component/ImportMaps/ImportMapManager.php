<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\ImportMaps;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\ScopingHttpClient;
use Symfony\Component\VarExporter\VarExporter;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * @experimental
 *
 * @author Kévin Dunglas <kevin@dunglas.dev>
 */
final class ImportMapManager
{
    public const POLYFILL_URL = 'https://ga.jspm.io/npm:es-module-shims@1.7.0/dist/es-module-shims.js';

    /**
     * @see https://regex101.com/r/2cR9Rh/1
     *
     * Partially based on https://github.com/dword-design/package-name-regex
     */
    private const PACKAGE_PATTERN = '/^(?:https?:\/\/[\w\.-]+\/)?(?:(?<registry>\w+):)?(?<package>(?:@[a-z0-9-~][a-z0-9-._~]*\/)?[a-z0-9-~][a-z0-9-._~]*)(?:@(?<version>[\w\._-]+))?(?:(?<subpath>\/.*))?$/';

    private HttpClientInterface $apiHttpClient;
    private ?array $importMap = null;

    public function __construct(
        private readonly string $path = 'importmap.php',
        private readonly string $assetsDir = 'assets/',
        private readonly string $publicAssetsDir = 'public/assets/',
        private readonly string $assetsUrl = '/assets/',
        private readonly Provider $provider = Provider::Jspm,
        private ?HttpClientInterface $httpClient = null,
        private readonly string $api = 'https://api.jspm.io',
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
        $this->httpClient ??= HttpClient::create();
        $this->apiHttpClient = ScopingHttpClient::forBaseUri($this->httpClient, $this->api);
    }

    private function loadImportMap(): void
    {
        if (null !== $this->importMap) {
            return;
        }

        $this->importMap = $this->filesystem->exists($this->path) ? include $this->path : [];
    }

    public function getImportMap(): string
    {
        $this->loadImportMap();

        $importmap = ['imports' => []];
        foreach ($this->importMap as $packageName => $data) {
            if (isset($data['url'])) {
                if ($data['download'] ?? false) {
                    $importmap['imports'][$packageName] = $this->vendorUrl($packageName);

                    continue;
                }

                $importmap['imports'][$packageName] = $data['url'];

                continue;
            }

            if (isset($data['path'])) {
                $importmap['imports'][$packageName] = $this->assetsUrl.$this->digestName($packageName, $data['path']);
            }
        }

        // Use JSON_UNESCAPED_SLASHES | JSON_HEX_TAG to prevent XSS
        return json_encode($importmap, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_HEX_TAG);
    }

    // TODO: find a better name
    public function getImportMapArray(): array
    {
        $this->loadImportMap();

        return $this->importMap;
    }

    /**
     * Adds or updates packages.
     *
     * @param array<string, PackageOptions> $packages
     */
    public function require(array $packages, Env $env = Env::Production, ?Provider $provider = null): void
    {
        $this->createImportMap($env, $provider, false, $packages, []);
    }

    /**
     * Removes packages.
     *
     * @param string[] $packages
     */
    public function remove(array $packages, Env $env = Env::Production, ?Provider $provider = null): void
    {
        $this->createImportMap($env, $provider, false, [], $packages);
    }

    /**
     * Updates all existing packages to the latest version.
     */
    public function update(Env $env = Env::Production, ?Provider $provider = null): void
    {
        $this->createImportMap($env, $provider, true, [], []);
    }

    /**
     * @param array<string, PackageOptions> $require
     * @param string[] $remove
     */
    private function createImportMap(Env $env, ?Provider $provider, bool $update, array $require, array $remove): void
    {
        $this->loadImportMap();
        $this->removeFromImportMap($remove);

        $install = [];
        $packages = [];
        foreach ($this->importMap ?? [] as $name => $data) {
            if (isset($data['path'])) {
                $this->filesystem->mkdir($this->publicAssetsDir);
                $this->filesystem->copy($this->assetsDir.$data['path'], $this->publicAssetsDir.$this->digestName($name, $data['path']));

                continue;
            }

            if (!$data['url']) {
                continue;
            }

            $packages[$name] = new PackageOptions((bool) ($data['download'] ?? false), $data['preload'] ?? false);

            if (preg_match(self::PACKAGE_PATTERN, $data['url'], $matches)) {
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

        $this->jspmGenerate($env, $provider, $install, $packages);

        $this->filesystem->dumpFile(
            $this->path,
            sprintf("<?php\n\nreturn %s;\n", VarExporter::export($this->importMap))
        );
    }

    /**
     * @param string[] $remove
     */
    private function removeFromImportMap(array $remove): void {
        foreach ($remove as $packageName) {
            if (!isset($this->importMap[$packageName])) {
                continue;
            }

            $this->removeIfExists($this->assetsDir.$this->importMap[$packageName]['digest']);
            unset($this->importMap[$packageName]);
        }
    }

    private function jspmGenerate(Env $env, ?Provider $provider, array $install, array $packages): void
    {
        if (!$install) {
            return;
        }

        $json = [
            'install' => array_values($install),
            'flattenScope' => true,
        ];
        $provider = $provider?->value ?? $this->provider->value;
        if ($provider !== Provider::Jspm) {
            $json['env'] = $provider;
        }

        $json['env'] = ['browser', 'module', $env->value];

        $response = $this->apiHttpClient->request('POST', '/generate', [
            'json' => $json,
        ]);

        if ($response->getStatusCode() !== 200) {
            $data = $response->toArray((false));

            if ($data['error']) {
                throw new \RuntimeException($data['error']);
            }

            $response->getHeaders();
        }

        $this->filesystem->mkdir($this->assetsDir.'vendor/');
        foreach ($response->toArray()['map']['imports'] as $packageName => $url) {
            if ($packages[$packageName]->preload) {
                $this->importMap[$packageName]['preload'] = true;
            } else {
                unset($this->importMap[$packageName]['preload']);
            }

            $relativePath = 'vendor/'.$packageName.'.js';
            if (!$packages[$packageName]->download) {
                if ($this->importMap[$packageName]['download'] ?? false) {
                    $this->removeIfExists($this->assetsDir.$relativePath);
                    // todo: remove parent dir if empty
                }
                unset($this->importMap[$packageName]['download']);

                continue;
            }

            $this->importMap[$packageName]['download'] = true;
            if ($previousPackageData['url'] ?? null === $url) {
                continue;
            }

            $this->importMap[$packageName]['url'] = $url;
            $this->filesystem->dumpFile(
                $this->assetsDir.$relativePath,
                $this->httpClient->request('GET', $url)->getContent(),
            );
            $this->filesystem->mkdir($this->publicAssetsDir);
            $this->filesystem->copy($this->assetsDir.$relativePath, $this->publicAssetsDir.'vendor/'.$this->digestName($packageName, $relativePath).'.js');
        }
    }

    private function removeIfExists(string $path): void
    {
        if ($this->filesystem->exists($path)) {
            $this->filesystem->remove($path);
        }
    }

    private function digestName(string $packageName, string $path): string
    {
        return sprintf('%s.%s.js', $packageName, hash('xxh128', file_get_contents($this->assetsDir.$path)));
    }

    private function vendorPath(string $packageName): string
    {
        return $this->assetsUrl.'vendor/'.$packageName.'.js';
    }

    private function vendorUrl(string $packageName): string
    {
        return $this->publicAssetsDir.'vendor/'.$this->digestName($packageName, $this->vendorPath($packageName));
    }
}
