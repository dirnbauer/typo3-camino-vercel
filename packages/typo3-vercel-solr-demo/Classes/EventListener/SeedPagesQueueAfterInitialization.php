<?php

declare(strict_types=1);

namespace Webconsulting\Typo3VercelSolrDemo\EventListener;

use ApacheSolrForTypo3\Solr\Domain\Site\Site;
use ApacheSolrForTypo3\Solr\Event\IndexQueue\AfterIndexQueueHasBeenInitializedEvent;
use ApacheSolrForTypo3\Solr\IndexQueue\Queue;
use ApacheSolrForTypo3\Solr\System\Records\Pages\PagesRepository;
use Doctrine\DBAL\ArrayParameterType;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Keeps the Camino demo queue usable on PostgreSQL while EXT:solr 14 is beta.
 *
 * EXT:solr's native queue initialization can leave an empty pages queue on
 * PostgreSQL while still reporting success. When that happens, seed the same
 * visible page rows through DBAL inserts so the backend module and scheduler
 * can continue with the normal EXT:solr queue.
 */
final readonly class SeedPagesQueueAfterInitialization
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private PagesRepository $pagesRepository,
    ) {}

    #[AsEventListener(
        identifier: 'webconsulting/typo3-vercel-solr-demo/seed-pages-queue-after-initialization',
    )]
    public function __invoke(AfterIndexQueueHasBeenInitializedEvent $event): void
    {
        if ($event->getType() !== 'pages' || $event->getIndexingConfigurationName() !== 'pages') {
            return;
        }

        /** @var Queue $queue */
        $queue = GeneralUtility::makeInstance(Queue::class);
        $statistics = $queue->getStatisticsBySite($event->getSite(), $event->getIndexingConfigurationName());
        if ($statistics->getTotalCount() > 0) {
            return;
        }

        $inserted = $this->seedVisiblePages($event->getSite(), $event->getIndexingConfigurationName());
        if ($inserted > 0) {
            $event->setIsInitialized(true);
        }
    }

    private function seedVisiblePages(Site $site, string $indexingConfigurationName): int
    {
        $pageIds = array_values(array_unique(array_map(
            'intval',
            $site->getPages(null, $indexingConfigurationName),
        )));
        if ($pageIds === []) {
            return 0;
        }

        $pageIds = array_values(array_diff(
            $pageIds,
            $this->pagesRepository->findAllPagesWithinNoSearchSubEntriesMarkedPages(),
        ));
        if ($pageIds === []) {
            return 0;
        }

        $rows = $this->fetchIndexablePageRows($pageIds);
        if ($rows === []) {
            return 0;
        }

        $connection = $this->connectionPool->getConnectionForTable('tx_solr_indexqueue_item');
        $inserted = 0;
        foreach ($rows as $row) {
            $uid = (int)$row['uid'];
            $changed = max((int)($row['starttime'] ?? 0), (int)($row['tstamp'] ?? 0), 1);

            $connection->insert('tx_solr_indexqueue_item', [
                'root' => $site->getRootPageId(),
                'item_type' => 'pages',
                'item_uid' => $uid,
                'item_pid' => $uid,
                'indexing_configuration' => $indexingConfigurationName,
                'has_indexing_properties' => 0,
                'indexing_priority' => 0,
                'changed' => $changed,
                'indexed' => 0,
                'errors' => '',
                'pages_mountidentifier' => '',
            ]);
            $inserted++;
        }

        return $inserted;
    }

    /**
     * @param int[] $pageIds
     * @return array<int, array<string, mixed>>
     */
    private function fetchIndexablePageRows(array $pageIds): array
    {
        $connection = $this->connectionPool->getConnectionForTable('pages');
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();

        $now = time();
        $queryBuilder
            ->select('uid', 'tstamp', 'starttime')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->in(
                    'uid',
                    $queryBuilder->createNamedParameter($pageIds, ArrayParameterType::INTEGER),
                ),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('hidden', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->in(
                    'sys_language_uid',
                    $queryBuilder->createNamedParameter([0, -1], ArrayParameterType::INTEGER),
                ),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->eq('endtime', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    $queryBuilder->expr()->gt('endtime', $queryBuilder->createNamedParameter($now, Connection::PARAM_INT)),
                ),
                $queryBuilder->expr()->eq('no_search', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->eq('doktype', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)),
                    $queryBuilder->expr()->and(
                        $queryBuilder->expr()->eq('doktype', $queryBuilder->createNamedParameter(7, Connection::PARAM_INT)),
                        $queryBuilder->expr()->eq('mount_pid_ol', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    ),
                ),
            )
            ->orderBy('sorting', 'ASC')
            ->addOrderBy('uid', 'ASC');

        return $queryBuilder->executeQuery()->fetchAllAssociative();
    }
}
