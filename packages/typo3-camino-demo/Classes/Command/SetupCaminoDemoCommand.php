<?php

declare(strict_types=1);

namespace Webconsulting\Typo3CaminoDemo\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AsCommand(
    name: 'webconsulting:camino-demo:setup',
    description: 'Creates the Visual Editor video page and strict Camino translations.'
)]
final class SetupCaminoDemoCommand extends Command
{
    private const VISUAL_EDITOR_CTYPE = 'typo3_camino_visual_editor_demo';

    private ?BackendUserAuthentication $setupUser = null;

    /** @var array<string, array{pageSlug:string,contentType:string,header?:string}> */
    private const CONTENT_TARGETS = [
        'home.hero' => ['pageSlug' => '/', 'contentType' => 'camino_hero', 'header' => 'Walk the Camino de Compostela'],
        'home.intro' => ['pageSlug' => '/', 'contentType' => 'text', 'header' => 'What Is the Camino de Compostela?'],
        'privacy.hero' => ['pageSlug' => '/privacy', 'contentType' => 'camino_hero_text_only', 'header' => 'Privacy'],
        'privacy.text' => ['pageSlug' => '/privacy', 'contentType' => 'text', 'header' => ''],
        'imprint.hero' => ['pageSlug' => '/imprint', 'contentType' => 'camino_hero_text_only', 'header' => 'Imprint'],
        'imprint.text' => ['pageSlug' => '/imprint', 'contentType' => 'text', 'header' => ''],
        'faqs.hero' => ['pageSlug' => '/faqs', 'contentType' => 'camino_hero_small', 'header' => 'FAQs'],
        'faqs.text' => ['pageSlug' => '/faqs', 'contentType' => 'text', 'header' => 'What is the Camino de Compostela?'],
        'packing.hero' => ['pageSlug' => '/packing-list', 'contentType' => 'camino_hero_small', 'header' => 'Packing List'],
        'packing.text' => ['pageSlug' => '/packing-list', 'contentType' => 'text', 'header' => 'Essential Items'],
        'routes.hero' => ['pageSlug' => '/camino-route-comparison', 'contentType' => 'camino_hero_small', 'header' => 'Camino Route Comparison'],
        'routes.text' => ['pageSlug' => '/camino-route-comparison', 'contentType' => 'textpic', 'header' => 'Camino Francés – The French Way'],
        'search.results' => ['pageSlug' => '/search', 'contentType' => 'vercel_solr_demo_results', 'header' => 'Search'],
        'visual.demo' => ['pageSlug' => '/visual-editor', 'contentType' => self::VISUAL_EDITOR_CTYPE, 'header' => 'Edit content where it lives'],
    ];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('root-page-id', null, InputOption::VALUE_REQUIRED, 'TYPO3 root page uid.', '1')
            ->addOption('flush-caches', null, InputOption::VALUE_NONE, 'Flush TYPO3 page caches after setup.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rootPageId = max(1, (int)$input->getOption('root-page-id'));
        $visualPageUid = $this->ensureVisualEditorPage($rootPageId);
        $this->ensureVisualEditorContent($visualPageUid);

        $translations = require dirname(__DIR__, 2) . '/Configuration/Demo/Translations.php';
        foreach ($translations as $languageId => $translation) {
            $this->applyPageTranslations((int)$languageId, $translation['pages'], $output);
            $this->applyContentTranslations((int)$languageId, $translation['content'], $output);
        }

        if ((bool)$input->getOption('flush-caches')) {
            GeneralUtility::makeInstance(CacheManager::class)->flushCachesInGroup('pages');
            $output->writeln('TYPO3 page caches were flushed after Camino demo setup.');
        }

        $output->writeln(sprintf(
            'Camino Visual Editor page %d and %d strict language variants are ready.',
            $visualPageUid,
            count($translations),
        ));
        return Command::SUCCESS;
    }

    private function ensureVisualEditorPage(int $rootPageId): int
    {
        $connection = $this->connectionPool->getConnectionForTable('pages');
        $uid = $this->findPageUid('/visual-editor');
        $now = time();
        $fields = [
            'tstamp' => $now,
            'hidden' => 0,
            'doktype' => 1,
            'title' => 'Visual Editor',
            'nav_title' => 'Visual Editor',
            'slug' => '/visual-editor',
            'no_search' => 0,
            'backend_layout' => 'pagets__CaminoContentpage',
            'backend_layout_next_level' => 'pagets__CaminoContentpage',
        ];

        if ($uid !== null) {
            $connection->update('pages', $fields, ['uid' => $uid]);
            return $uid;
        }

        $connection->insert('pages', [
            'pid' => $rootPageId,
            'crdate' => $now,
            'deleted' => 0,
            'sorting' => $this->nextSorting('pages', $rootPageId),
            'sys_language_uid' => 0,
            'l10n_parent' => 0,
            'perms_user' => 31,
            'perms_group' => 27,
            'perms_everybody' => 0,
        ] + $fields);

        return $this->findPageUid('/visual-editor')
            ?? throw new \RuntimeException('Could not create the Visual Editor demo page.', 1783681201);
    }

    private function ensureVisualEditorContent(int $pageUid): int
    {
        $connection = $this->connectionPool->getConnectionForTable('tt_content');
        $queryBuilder = $connection->createQueryBuilder();
        $uid = $queryBuilder
            ->select('uid')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('CType', $queryBuilder->createNamedParameter(self::VISUAL_EDITOR_CTYPE)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        $now = time();
        $fields = [
            'tstamp' => $now,
            'hidden' => 0,
            'CType' => self::VISUAL_EDITOR_CTYPE,
            'header' => 'Edit content where it lives',
            'subheader' => 'Recorded in this TYPO3 14 Camino demo.',
            'bodytext' => '<p>The Friends of TYPO3 Visual Editor opens the real Camino page inside the backend. Editors can update text in context, identify content areas, and save related changes together.</p>',
            'header_layout' => 1,
            'colPos' => 0,
            'frame_class' => 'default',
        ];

        if ($uid !== false) {
            $connection->update('tt_content', $fields, ['uid' => (int)$uid]);
            return (int)$uid;
        }

        $connection->insert('tt_content', [
            'pid' => $pageUid,
            'crdate' => $now,
            'deleted' => 0,
            'sorting' => $this->nextSorting('tt_content', $pageUid),
            'sys_language_uid' => 0,
            'l18n_parent' => 0,
        ] + $fields);

        $target = self::CONTENT_TARGETS['visual.demo'];
        return $this->findDefaultContentUid($target)
            ?? throw new \RuntimeException('Could not create the Visual Editor demo content.', 1783681202);
    }

    /**
     * @param array<string, array{title:string,slug:string}> $pages
     */
    private function applyPageTranslations(int $languageId, array $pages, OutputInterface $output): void
    {
        foreach ($pages as $defaultSlug => $fields) {
            $defaultUid = $this->findPageUid($defaultSlug);
            if ($defaultUid === null) {
                throw new \RuntimeException(sprintf('Default page "%s" was not found.', $defaultSlug), 1783681203);
            }

            $translationUid = $this->findTranslationUid('pages', 'l10n_parent', $defaultUid, $languageId)
                ?? $this->localizeRecord('pages', $defaultUid, $languageId);
            $this->connectionPool->getConnectionForTable('pages')->update('pages', [
                'tstamp' => time(),
                'hidden' => 0,
                'title' => $fields['title'],
                'nav_title' => $fields['title'],
                'slug' => $fields['slug'],
            ], ['uid' => $translationUid]);
        }

        $output->writeln(sprintf('Language %d: %d page translations are ready.', $languageId, count($pages)));
    }

    /**
     * @param array<string, array<string, string>> $content
     */
    private function applyContentTranslations(int $languageId, array $content, OutputInterface $output): void
    {
        foreach ($content as $key => $fields) {
            $target = self::CONTENT_TARGETS[$key]
                ?? throw new \RuntimeException(sprintf('Unknown translation target "%s".', $key), 1783681204);
            $defaultUid = $this->findDefaultContentUid($target);
            if ($defaultUid === null) {
                throw new \RuntimeException(sprintf('Default content for "%s" was not found.', $key), 1783681205);
            }

            $translationUid = $this->findTranslationUid('tt_content', 'l18n_parent', $defaultUid, $languageId)
                ?? $this->localizeRecord('tt_content', $defaultUid, $languageId);
            $this->connectionPool->getConnectionForTable('tt_content')->update(
                'tt_content',
                ['tstamp' => time(), 'hidden' => 0] + $fields,
                ['uid' => $translationUid],
            );
        }

        $output->writeln(sprintf('Language %d: %d content translations are ready.', $languageId, count($content)));
    }

    /** @param array{pageSlug:string,contentType:string,header?:string} $target */
    private function findDefaultContentUid(array $target): ?int
    {
        $pageUid = $this->findPageUid($target['pageSlug']);
        if ($pageUid === null) {
            return null;
        }

        $queryBuilder = $this->connectionPool->getConnectionForTable('tt_content')->createQueryBuilder();
        $conditions = [
            $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
            $queryBuilder->expr()->eq('CType', $queryBuilder->createNamedParameter($target['contentType'])),
            $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
        ];
        if (array_key_exists('header', $target)) {
            $conditions[] = $queryBuilder->expr()->eq('header', $queryBuilder->createNamedParameter($target['header']));
        }

        $uid = $queryBuilder
            ->select('uid')
            ->from('tt_content')
            ->where(...$conditions)
            ->orderBy('sorting')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
        return $uid === false ? null : (int)$uid;
    }

    private function findPageUid(string $slug): ?int
    {
        $queryBuilder = $this->connectionPool->getConnectionForTable('pages')->createQueryBuilder();
        $uid = $queryBuilder
            ->select('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('slug', $queryBuilder->createNamedParameter($slug)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
        return $uid === false ? null : (int)$uid;
    }

    private function findTranslationUid(string $table, string $parentField, int $parentUid, int $languageId): ?int
    {
        $queryBuilder = $this->connectionPool->getConnectionForTable($table)->createQueryBuilder();
        $uid = $queryBuilder
            ->select('uid')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq($parentField, $queryBuilder->createNamedParameter($parentUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($languageId, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
        return $uid === false ? null : (int)$uid;
    }

    private function localizeRecord(string $table, int $uid, int $languageId): int
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->bypassAccessCheckForRecords = true;
        $dataHandler->start(
            [],
            [$table => [$uid => ['localize' => $languageId]]],
            $this->setupBackendUser(),
        );
        $dataHandler->process_cmdmap();
        if ($dataHandler->errorLog !== []) {
            throw new \RuntimeException(implode(' ', $dataHandler->errorLog), 1783681206);
        }

        $localizedUid = $dataHandler->copyMappingArray_merged[$table][$uid] ?? null;
        if (is_numeric($localizedUid)) {
            return (int)$localizedUid;
        }

        $parentField = $table === 'pages' ? 'l10n_parent' : 'l18n_parent';
        return $this->findTranslationUid($table, $parentField, $uid, $languageId)
            ?? throw new \RuntimeException(sprintf('TYPO3 did not localize %s:%d.', $table, $uid), 1783681207);
    }

    private function setupBackendUser(): BackendUserAuthentication
    {
        if ($this->setupUser instanceof BackendUserAuthentication) {
            return $this->setupUser;
        }

        $queryBuilder = $this->connectionPool->getConnectionForTable('be_users')->createQueryBuilder();
        $uid = $queryBuilder
            ->select('uid')
            ->from('be_users')
            ->where(
                $queryBuilder->expr()->eq('admin', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('disable', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->orderBy('uid')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
        if ($uid === false) {
            throw new \RuntimeException('No active TYPO3 backend administrator exists for demo localization.', 1783681208);
        }

        $backendUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $backendUser->setBeUserByUid((int)$uid);
        $backendUser->fetchGroupData();
        if (!$backendUser->isAdmin()) {
            throw new \RuntimeException('The TYPO3 demo setup user is not an administrator.', 1783681209);
        }

        return $this->setupUser = $backendUser;
    }

    private function nextSorting(string $table, int $pid): int
    {
        $queryBuilder = $this->connectionPool->getConnectionForTable($table)->createQueryBuilder();
        $sorting = $queryBuilder
            ->selectLiteral('MAX(sorting)')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne();
        return ((int)($sorting ?: 0)) + 256;
    }
}
