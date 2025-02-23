<?php

declare(strict_types=1);

/*
 * This file is part of the "schema" extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Brotkrueml\Schema\Type;

use Brotkrueml\Schema\Core\Model\WebPageElementTypeInterface;
use Brotkrueml\Schema\Core\Model\WebPageTypeInterface;
use Brotkrueml\Schema\Extension;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Cache\Frontend\PhpFrontend;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Provide lists of all available types or a subset of them
 *
 * The lists of types shipped with the schema extension are
 * generated from the schema.org core definitions. Additionally,
 * more types can be registered by other extensions via
 * Configuration/TxSchema/TypeModels.php.
 *
 * @api
 */
class TypeRegistry implements SingletonInterface
{
    private const CACHE_ENTRY_IDENTIFIER_TYPES = 'types';
    private const CACHE_ENTRY_IDENTIFIER_WEBPAGE_TYPES = 'webpage_types';
    private const CACHE_ENTRY_IDENTIFIER_WEBPAGEELEMENT_TYPES = 'webpageelement_types';

    /**
     * @var array<string,class-string>
     */
    private array $types = [];

    /**
     * @var string[]
     */
    private array $webPageTypes = [];

    /**
     * @var string[]
     */
    private array $webPageElementTypes = [];

    private ?FrontendInterface $cache;
    private PackageManager $packageManager;

    public function __construct(?FrontendInterface $cache = null, ?PackageManager $packageManager = null)
    {
        $this->cache = $cache;
        $this->packageManager = $packageManager ?? GeneralUtility::makeInstance(PackageManager::class);
    }

    /**
     * Get all available types
     *
     * @return string[]
     */
    public function getTypes(): array
    {
        return \array_keys($this->getTypesWithModels());
    }

    /**
     * @return array<string,class-string>
     */
    private function getTypesWithModels(): array
    {
        if ($this->types === []) {
            $this->types = $this->loadConfiguration();
        }

        return $this->types;
    }

    /**
     * @return array<string,class-string>
     */
    private function loadConfiguration(): array
    {
        $cacheEntry = $this->requireCacheEntry(self::CACHE_ENTRY_IDENTIFIER_TYPES);
        if ($cacheEntry !== null) {
            return $cacheEntry;
        }

        $packages = $this->packageManager->getActivePackages();
        $allTypeModels = [[]];
        foreach ($packages as $package) {
            $typeModelsConfiguration = $package->getPackagePath() . 'Configuration/TxSchema/TypeModels.php';
            if (\file_exists($typeModelsConfiguration)) {
                $typeModelsInPackage = require $typeModelsConfiguration;
                if (\is_array($typeModelsInPackage)) {
                    $allTypeModels[] = $this->enrichTypeModelsArrayWithTypeKey($typeModelsInPackage);
                }
            }
        }
        $typeModels = \array_replace_recursive(...$allTypeModels);
        \ksort($typeModels);

        $this->setCacheEntry(self::CACHE_ENTRY_IDENTIFIER_TYPES, $typeModels);

        return $typeModels;
    }

    private function getCache(): ?FrontendInterface
    {
        if ($this->cache instanceof FrontendInterface) {
            return $this->cache;
        }

        try {
            $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
        } catch (\LogicException $e) {
            // The exception is thrown in TYPO3 v12 when the boot state is not completed:
            // "TYPO3\CMS\Core\Cache\CacheManager can not be injected/instantiated during ext_localconf.php or TCA
            // loading. Use lazy loading instead."
            // This is due to the initialisation of the page field "tx_schema_webpagetype" in TCA - which is not
            // needed at build time and the exception can be ignored safely.
            // See: TYPO3\CMS\Core\ServiceProvider::getCacheManager()
            return null;
        }

        try {
            $this->cache = $cacheManager->getCache(Extension::CACHE_CORE_IDENTIFIER);
        } catch (NoSuchCacheException $e) {
            // Ignore: This should not happen
        }

        return $this->cache;
    }

    /**
     * @return array<string, class-string>|list<string>|null
     */
    private function requireCacheEntry(string $identifier): ?array
    {
        $cache = $this->getCache();
        if (! $cache instanceof PhpFrontend) {
            return null;
        }
        if (! $cache->has($identifier)) {
            return null;
        }

        return $cache->require($identifier);
    }

    /**
     * @param array<string, class-string>|list<string> $data
     */
    private function setCacheEntry(string $identifier, array $data): void
    {
        $cache = $this->getCache();
        if ($cache instanceof PhpFrontend) {
            $cache->set($identifier, 'return ' . \var_export($data, true) . ';');
        }
    }

    /**
     * @param list<class-string> $typeModels
     * @return array<string, class-string>
     */
    private function enrichTypeModelsArrayWithTypeKey(array $typeModels): array
    {
        $typeModelsWithTypeKey = [];
        foreach ($typeModels as $typeModel) {
            $type = \substr(\strrchr($typeModel, '\\') ?: '', 1);
            // In PHP < 8.0 substr('', 1) returns false, in PHP >= 8.0 an empty string is returned, see: https://3v4l.org/Zk6kK
            // An empty string should not be used, here is something wrong with the type
            if ($type === false) { // @phpstan-ignore-line
                continue;
            }
            if ($type === '') {
                continue;
            }
            $typeModelsWithTypeKey[$type] = $typeModel;
        }

        return $typeModelsWithTypeKey;
    }

    /**
     * Get the WebPage types
     *
     * @return string[]
     *
     * @see https://schema.org/WebPage
     */
    public function getWebPageTypes(): array
    {
        if ($this->webPageTypes === []) {
            $this->webPageTypes = $this->loadSpecialTypes(
                self::CACHE_ENTRY_IDENTIFIER_WEBPAGE_TYPES,
                WebPageTypeInterface::class
            );
        }

        return $this->webPageTypes;
    }

    /**
     * @return mixed[]|string[]
     */
    private function loadSpecialTypes(string $cacheEntryIdentifier, string $typeInterface): array
    {
        $cacheEntry = $this->requireCacheEntry($cacheEntryIdentifier);
        if ($cacheEntry !== null) {
            return $cacheEntry;
        }

        $specialTypes = [];
        foreach ($this->getTypesWithModels() as $type => $typeModel) {
            try {
                if (array_key_exists($typeInterface, (new \ReflectionClass($typeModel))->getInterfaces())) {
                    $specialTypes[] = $type;
                }
            } catch (\ReflectionException $e) {
                // Ignore
            }
        }

        \sort($specialTypes);
        $this->setCacheEntry($cacheEntryIdentifier, $specialTypes);

        return $specialTypes;
    }

    /**
     * Get the WebPageElement types
     *
     * @return string[]
     *
     * @see https://schema.org/WebPageElement
     */
    public function getWebPageElementTypes(): array
    {
        if ($this->webPageElementTypes === []) {
            $this->webPageElementTypes = $this->loadSpecialTypes(
                self::CACHE_ENTRY_IDENTIFIER_WEBPAGEELEMENT_TYPES,
                WebPageElementTypeInterface::class
            );
        }

        return $this->webPageElementTypes;
    }

    /**
     * Get the content types
     * "Content types" mean: Useful for structuring page content by an editor
     *
     * @return string[]
     */
    public function getContentTypes(): array
    {
        return \array_values(
            \array_diff(
                $this->getTypes(),
                $this->getWebPageTypes(),
                $this->getWebPageElementTypes(),
                [
                    'BreadcrumbList',
                    'WebSite',
                ]
            )
        );
    }

    /**
     * @internal Only for internal use, not a public API!
     */
    public function resolveModelClassFromType(string $type): ?string
    {
        if ($type === '') {
            return null;
        }

        if ($this->types === []) {
            $this->getTypesWithModels();
        }

        if (\array_key_exists($type, $this->types)) {
            return $this->types[$type];
        }

        return null;
    }
}
