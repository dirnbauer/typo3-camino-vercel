<?php

declare(strict_types=1);

namespace Webconsulting\Typo3CaminoDemo\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
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
            $this->applyListItemTranslations((int)$languageId, $translation['listItems'], $output);
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

        return (int)$connection->lastInsertId();
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
     * @param array<int, array<string, string>> $content
     */
    private function applyContentTranslations(int $languageId, array $content, OutputInterface $output): void
    {
        $this->assertCatalogCoverage('tt_content', $content, $languageId);
        foreach ($content as $defaultUid => $fields) {
            $defaultUid = (int)$defaultUid;
            $translationUid = $this->findTranslationUid('tt_content', 'l18n_parent', $defaultUid, $languageId)
                ?? $this->localizeRecord('tt_content', $defaultUid, $languageId);
            $this->connectionPool->getConnectionForTable('tt_content')->update(
                'tt_content',
                ['tstamp' => time(), 'hidden' => 0] + $fields,
                ['uid' => $translationUid],
            );
            $this->ensureFileReferenceTranslations($defaultUid, $translationUid, $languageId);
        }

        $output->writeln(sprintf('Language %d: %d content translations are ready.', $languageId, count($content)));
    }

    /** @param array<int, array<string, string>> $listItems */
    private function applyListItemTranslations(int $languageId, array $listItems, OutputInterface $output): void
    {
        $table = 'tx_themecamino_list_item';
        $this->assertCatalogCoverage($table, $listItems, $languageId);
        $connection = $this->connectionPool->getConnectionForTable($table);
        foreach ($listItems as $defaultUid => $fields) {
            $defaultUid = (int)$defaultUid;
            $queryBuilder = $connection->createQueryBuilder();
            $defaultParentUid = $queryBuilder
                ->select('uid_foreign')
                ->from($table)
                ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($defaultUid, Connection::PARAM_INT)))
                ->executeQuery()
                ->fetchOne();
            if ($defaultParentUid === false) {
                throw new \RuntimeException(sprintf('Default Camino list item %d was not found.', $defaultUid), 1783681204);
            }
            $translatedParentUid = $this->findTranslationUid('tt_content', 'l18n_parent', (int)$defaultParentUid, $languageId);
            if ($translatedParentUid === null) {
                throw new \RuntimeException(sprintf('Translated parent content %d was not found.', $defaultParentUid), 1783681205);
            }

            $translationUid = $this->findTranslationUid($table, 'l10n_parent', $defaultUid, $languageId)
                ?? $this->localizeRecord($table, $defaultUid, $languageId);
            $connection->update(
                $table,
                ['tstamp' => time(), 'hidden' => 0, 'uid_foreign' => $translatedParentUid] + $fields,
                ['uid' => $translationUid],
            );
        }

        $output->writeln(sprintf('Language %d: %d nested list-item translations are ready.', $languageId, count($listItems)));
    }

    /** @param array<int, array<string, string>> $catalog */
    private function assertCatalogCoverage(string $table, array $catalog, int $languageId): void
    {
        $queryBuilder = $this->connectionPool->getConnectionForTable($table)->createQueryBuilder();
        $defaultUids = array_map('intval', $queryBuilder
            ->select('uid')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchFirstColumn());
        $catalogUids = array_map('intval', array_keys($catalog));
        sort($defaultUids);
        sort($catalogUids);
        if ($defaultUids !== $catalogUids) {
            $missing = array_diff($defaultUids, $catalogUids);
            $unknown = array_diff($catalogUids, $defaultUids);
            throw new \RuntimeException(sprintf(
                'Language %d does not cover all %s records. Missing: %s. Unknown: %s.',
                $languageId,
                $table,
                $missing === [] ? 'none' : implode(', ', $missing),
                $unknown === [] ? 'none' : implode(', ', $unknown),
            ), 1783681210);
        }
    }

    private function ensureFileReferenceTranslations(int $defaultContentUid, int $translatedContentUid, int $languageId): void
    {
        $table = 'sys_file_reference';
        $connection = $this->connectionPool->getConnectionForTable($table);
        $queryBuilder = $connection->createQueryBuilder();
        $referenceUids = array_map('intval', $queryBuilder
            ->select('uid')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('uid_foreign', $queryBuilder->createNamedParameter($defaultContentUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter('tt_content')),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchFirstColumn());

        foreach ($referenceUids as $referenceUid) {
            $translatedReferenceUid = $this->findTranslationUid($table, 'l10n_parent', $referenceUid, $languageId)
                ?? $this->localizeRecord($table, $referenceUid, $languageId);
            $connection->update($table, [
                'tstamp' => time(),
                'hidden' => 0,
                'uid_foreign' => $translatedContentUid,
            ], ['uid' => $translatedReferenceUid]);
        }
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
        $parentField = $this->localizationParentField($table);
        $existingUid = $this->findTranslationUid($table, $parentField, $uid, $languageId);
        if ($existingUid !== null) {
            return $existingUid;
        }

        $connection = $this->connectionPool->getConnectionForTable($table);
        $queryBuilder = $connection->createQueryBuilder();
        $record = $queryBuilder
            ->select('*')
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();
        if ($record === false) {
            throw new \RuntimeException(sprintf('Could not read %s:%d for localization.', $table, $uid), 1783681206);
        }

        unset($record['uid']);
        $record['sys_language_uid'] = $languageId;
        $record[$parentField] = $uid;
        if (array_key_exists('l10n_source', $record)) {
            $record['l10n_source'] = $uid;
        }
        if (array_key_exists('deleted', $record)) {
            $record['deleted'] = 0;
        }
        if (array_key_exists('hidden', $record)) {
            $record['hidden'] = 0;
        }
        if (array_key_exists('tstamp', $record)) {
            $record['tstamp'] = time();
        }
        if (array_key_exists('crdate', $record)) {
            $record['crdate'] = time();
        }
        $connection->insert($table, $record);

        return (int)$connection->lastInsertId();
    }

    private function localizationParentField(string $table): string
    {
        return in_array($table, ['pages', 'sys_file_reference', 'tx_themecamino_list_item'], true)
            ? 'l10n_parent'
            : 'l18n_parent';
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
