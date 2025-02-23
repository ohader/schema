<?php

declare(strict_types=1);

/*
 * This file is part of the "schema" extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Brotkrueml\Schema\EventListener;

use Brotkrueml\Schema\Core\Model\TypeInterface;
use Brotkrueml\Schema\Event\RenderAdditionalTypesEvent;
use Brotkrueml\Schema\Type\TypeFactory;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;

/**
 * @internal
 */
final class AddBreadcrumbList
{
    private const DEFAULT_DOKTYPES_TO_EXCLUDE = [
        PageRepository::DOKTYPE_RECYCLER,
        PageRepository::DOKTYPE_SPACER,
        PageRepository::DOKTYPE_SYSFOLDER,
    ];

    private ExtensionConfiguration $extensionConfiguration;
    private ContentObjectRenderer $contentObjectRenderer;

    public function __construct(
        ContentObjectRenderer $contentObjectRenderer,
        ExtensionConfiguration $configuration
    ) {
        $this->contentObjectRenderer = $contentObjectRenderer;
        $this->extensionConfiguration = $configuration;
    }

    public function __invoke(RenderAdditionalTypesEvent $event): void
    {
        $configuration = $this->extensionConfiguration->get('schema');
        $shouldEmbedBreadcrumbMarkup = (bool)$configuration['automaticBreadcrumbSchemaGeneration'];
        if (! $shouldEmbedBreadcrumbMarkup) {
            return;
        }

        $additionalDoktypesToExclude = GeneralUtility::intExplode(
            ',',
            $configuration['automaticBreadcrumbExcludeAdditionalDoktypes'],
            true
        );
        $doktypesToExclude = \array_merge(self::DEFAULT_DOKTYPES_TO_EXCLUDE, $additionalDoktypesToExclude);
        $rootLine = [];
        foreach ($this->getTypoScriptFrontendController()->rootLine as $page) {
            if ($page['is_siteroot'] ?? false) {
                break;
            }

            if ($page['nav_hide'] ?? false) {
                continue;
            }

            if (\in_array($page['doktype'] ?? PageRepository::DOKTYPE_DEFAULT, $doktypesToExclude, true)) {
                continue;
            }

            $rootLine[] = $page;
        }

        if ($rootLine === []) {
            return;
        }

        $rootLine = \array_reverse($rootLine);
        $event->addType($this->buildBreadCrumbList($rootLine));
    }

    /**
     * @param non-empty-array<int, array<string, mixed>> $rootLine
     */
    private function buildBreadCrumbList(array $rootLine): TypeInterface
    {
        $breadcrumbList = TypeFactory::createType('BreadcrumbList');
        foreach (\array_values($rootLine) as $index => $page) {
            $givenItemType = ($page['tx_schema_webpagetype'] ?? '') ?: 'WebPage';
            $itemType = TypeFactory::createType($givenItemType);

            $link = $this->contentObjectRenderer->typoLink_URL([
                'parameter' => (string)$page['uid'],
                'forceAbsoluteUrl' => true,
            ]);

            $itemType->setId($link);

            $item = TypeFactory::createType('ListItem')->setProperties([
                'position' => $index + 1,
                'name' => $page['nav_title'] ?: $page['title'],
                'item' => $itemType,
            ]);

            $breadcrumbList->addProperty('itemListElement', $item);
        }

        return $breadcrumbList;
    }

    private function getTypoScriptFrontendController(): TypoScriptFrontendController
    {
        return $GLOBALS['TSFE'];
    }
}
