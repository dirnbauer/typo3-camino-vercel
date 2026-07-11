<?php

declare(strict_types=1);

namespace Webconsulting\Typo3VercelSolrDemo\Command;

use ApacheSolrForTypo3\Solr\ConnectionManager;
use ApacheSolrForTypo3\Solr\Domain\Index\IndexService;
use ApacheSolrForTypo3\Solr\Domain\Index\Queue\QueueInitializationService;
use ApacheSolrForTypo3\Solr\Domain\Search\Query\Query;
use ApacheSolrForTypo3\Solr\Domain\Site\Site;
use ApacheSolrForTypo3\Solr\Domain\Site\SiteRepository;
use ApacheSolrForTypo3\Solr\IndexQueue\Queue;
use ApacheSolrForTypo3\Solr\System\Solr\Document\Document;
use ApacheSolrForTypo3\Solr\System\DateTime\FormatService;
use ApacheSolrForTypo3\Solr\System\Solr\ResponseAdapter;
use ApacheSolrForTypo3\Solr\System\Solr\SolrConnection;
use ApacheSolrForTypo3\Solr\Task\IndexQueueWorkerTask;
use ApacheSolrForTypo3\Solr\Util;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\Domain\Repository\SchedulerTaskRepository;
use TYPO3\CMS\Scheduler\Execution;
use TYPO3\CMS\Scheduler\Service\TaskService;

#[AsCommand(
    name: 'webconsulting:solr-demo:setup',
    description: 'Creates the Camino Solr search page and optionally indexes pages.'
)]
final class SetupSolrDemoCommand extends Command
{
    private const SEARCH_PLUGIN_CTYPE = 'vercel_solr_demo_results';
    private const LEGACY_SEARCH_PLUGIN_CTYPES = ['solr_pi_results'];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('site-identifier', null, InputOption::VALUE_REQUIRED, 'TYPO3 site identifier.', 'camino')
            ->addOption('root-page-id', null, InputOption::VALUE_REQUIRED, 'TYPO3 root page uid.', '1')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Search page title.', 'Search')
            ->addOption('slug', null, InputOption::VALUE_REQUIRED, 'Search page slug.', '/search')
            ->addOption('index', null, InputOption::VALUE_NONE, 'Rebuild and process the EXT:solr page index queue.')
            ->addOption('diagnose', null, InputOption::VALUE_NONE, 'Print page and index queue diagnostics.')
            ->addOption('flush-caches', null, InputOption::VALUE_NONE, 'Flush TYPO3 caches after creating or updating the search page.')
            ->addOption('normalize-demo-pages', null, InputOption::VALUE_NONE, 'Make visible Camino demo pages indexable before queue initialization.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum queue documents to process per run.', '50')
            ->addOption('scheduler-task', null, InputOption::VALUE_NONE, 'Create or update the EXT:solr Index Queue Worker scheduler task.')
            ->addOption('scheduler-interval', null, InputOption::VALUE_REQUIRED, 'Scheduler task interval in seconds.', '300')
            ->addOption('indexing-configuration', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'EXT:solr indexing configuration name.', ['pages']);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rootPageId = max(1, $this->intOption($input, 'root-page-id', 1));
        $title = $this->stringOption($input, 'title', 'Search');
        $slug = $this->normalizeSlug($this->stringOption($input, 'slug', '/search'));
        $limit = max(1, $this->intOption($input, 'limit', 50));
        $indexingConfigurations = $this->stringArrayOption($input, 'indexing-configuration', ['pages']);

        $searchPage = $this->ensureSearchPage($rootPageId, $title, $slug);
        $searchPageUid = (int)$searchPage['uid'];
        $content = $this->ensureSearchPlugin($searchPageUid);
        $contentUid = (int)$content['uid'];
        $changed = (bool)$searchPage['changed'] || (bool)$content['changed'];

        $output->writeln(sprintf(
            'Solr demo search page is ready: page uid %d, content uid %d, slug %s.',
            $searchPageUid,
            $contentUid,
            $slug,
        ));

        if ((bool)$input->getOption('normalize-demo-pages')) {
            $this->normalizeDemoPagesForIndexing($rootPageId, $searchPageUid, $output);
        }

        $flushCaches = $changed || (bool)$input->getOption('flush-caches');

        if ((bool)$input->getOption('diagnose')) {
            $this->writePageDiagnostics($rootPageId, $searchPageUid, $output);
        }

        if ((bool)$input->getOption('index')) {
            $fallbackQueueSeeded = $this->rebuildIndexQueue($rootPageId, $searchPageUid, $indexingConfigurations, $output);
            if ($fallbackQueueSeeded) {
                $this->indexVisibleDemoPagesDirectly($rootPageId, $searchPageUid, $output);
            } else {
                try {
                    $queueProgressed = $this->processIndexQueue($rootPageId, $limit, $output);
                    if (!$queueProgressed) {
                        $output->writeln(
                            'EXT:solr index queue worker left the page queue pending, using direct Camino demo indexing fallback.',
                        );
                        $this->indexVisibleDemoPagesDirectly($rootPageId, $searchPageUid, $output);
                    }
                } catch (\Throwable $exception) {
                    $output->writeln(sprintf(
                        'EXT:solr index queue worker failed, using direct Camino demo indexing fallback: %s',
                        $exception->getMessage(),
                    ));
                    $this->indexVisibleDemoPagesDirectly($rootPageId, $searchPageUid, $output);
                }
            }
        }

        if ((bool)$input->getOption('scheduler-task')) {
            $this->ensureIndexQueueSchedulerTask(
                $rootPageId,
                $limit,
                max(60, $this->intOption($input, 'scheduler-interval', 300)),
                $output,
            );
        }

        // The command changes page records, so only frontend/page caches need to
        // be cleared. Flushing every cache here would clear cache.core without
        // TYPO3's matching DI-cache lifecycle and break the next HTTP request.
        if ($flushCaches) {
            $this->flushCaches($output);
        }

        return Command::SUCCESS;
    }

    /**
     * @return array{uid:int, changed:bool}
     */
    private function ensureSearchPage(int $rootPageId, string $title, string $slug): array
    {
        $connection = $this->connectionPool->getConnectionForTable('pages');
        $queryBuilder = $connection->createQueryBuilder();
        $row = $queryBuilder
            ->select('uid', 'hidden', 'doktype', 'title', 'nav_title', 'no_search')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($rootPageId, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('slug', $queryBuilder->createNamedParameter($slug)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        $now = time();
        if (is_array($row) && isset($row['uid'])) {
            $uid = (int)$row['uid'];
            $expected = [
                'hidden' => 0,
                'doktype' => 1,
                'title' => $title,
                'nav_title' => $title,
                'no_search' => 1,
            ];

            if ($this->rowDiffers($row, $expected)) {
                $connection->update(
                    'pages',
                    ['tstamp' => $now] + $expected,
                    ['uid' => $uid],
                );
                return ['uid' => $uid, 'changed' => true];
            }

            return ['uid' => $uid, 'changed' => false];
        }

        $sorting = $this->nextSorting('pages', $rootPageId);
        $connection->insert('pages', [
            'pid' => $rootPageId,
            'tstamp' => $now,
            'crdate' => $now,
            'hidden' => 0,
            'deleted' => 0,
            'sorting' => $sorting,
            'doktype' => 1,
            'title' => $title,
            'nav_title' => $title,
            'slug' => $slug,
            'no_search' => 1,
            'perms_user' => 31,
            'perms_group' => 27,
            'perms_everybody' => 0,
        ]);

        return ['uid' => $this->findPageUidBySlug($rootPageId, $slug), 'changed' => true];
    }

    /**
     * @return array{uid:int, changed:bool}
     */
    private function ensureSearchPlugin(int $searchPageUid): array
    {
        $connection = $this->connectionPool->getConnectionForTable('tt_content');
        $flexForm = $this->buildSearchPluginFlexForm($searchPageUid);
        $queryBuilder = $connection->createQueryBuilder();
        $row = $queryBuilder
            ->select('uid', 'hidden', 'header', 'CType', 'colPos', 'pi_flexform')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($searchPageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->eq('CType', $queryBuilder->createNamedParameter(self::SEARCH_PLUGIN_CTYPE)),
                    ...array_map(
                        static fn (string $contentType): string => $queryBuilder->expr()->eq(
                            'CType',
                            $queryBuilder->createNamedParameter($contentType),
                        ),
                        self::LEGACY_SEARCH_PLUGIN_CTYPES,
                    ),
                ),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        $now = time();
        if (is_array($row) && isset($row['uid'])) {
            $uid = (int)$row['uid'];
            $expected = [
                'hidden' => 0,
                'header' => 'Search',
                'CType' => self::SEARCH_PLUGIN_CTYPE,
                'colPos' => 0,
                'pi_flexform' => $flexForm,
            ];

            if ($this->rowDiffers($row, $expected)) {
                $connection->update(
                    'tt_content',
                    ['tstamp' => $now] + $expected,
                    ['uid' => $uid],
                );
                return ['uid' => $uid, 'changed' => true];
            }

            return ['uid' => $uid, 'changed' => false];
        }

        $connection->insert('tt_content', [
            'pid' => $searchPageUid,
            'tstamp' => $now,
            'crdate' => $now,
            'hidden' => 0,
            'deleted' => 0,
            'sorting' => $this->nextSorting('tt_content', $searchPageUid),
            'CType' => self::SEARCH_PLUGIN_CTYPE,
            'header' => 'Search',
            'header_layout' => 1,
            'colPos' => 0,
            'sys_language_uid' => 0,
            'pi_flexform' => $flexForm,
        ]);

        return ['uid' => $this->findSearchPluginUid($searchPageUid), 'changed' => true];
    }

    private function buildSearchPluginFlexForm(int $targetPageUid): string
    {
        return sprintf(
            '<?xml version="1.0" encoding="utf-8" standalone="yes" ?>' . "\n"
            . '<T3FlexForms><data><sheet index="sDEF"><language index="lDEF">'
            . '<field index="search.targetPage"><value index="vDEF">%d</value></field>'
            . '</language></sheet></data></T3FlexForms>',
            $targetPageUid,
        );
    }

    private function findPageUidBySlug(int $rootPageId, string $slug): int
    {
        $connection = $this->connectionPool->getConnectionForTable('pages');
        $queryBuilder = $connection->createQueryBuilder();
        $uid = $queryBuilder
            ->select('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($rootPageId, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('slug', $queryBuilder->createNamedParameter($slug)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        if ($uid === false) {
            throw new \RuntimeException(sprintf('Could not create Solr search page "%s".', $slug), 1773312906);
        }

        return (int)$uid;
    }

    private function findSearchPluginUid(int $pageUid): int
    {
        $connection = $this->connectionPool->getConnectionForTable('tt_content');
        $queryBuilder = $connection->createQueryBuilder();
        $uid = $queryBuilder
            ->select('uid')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('CType', $queryBuilder->createNamedParameter(self::SEARCH_PLUGIN_CTYPE)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        if ($uid === false) {
            throw new \RuntimeException(sprintf('Could not create Solr search plugin on page %d.', $pageUid), 1773312907);
        }

        return (int)$uid;
    }

    private function nextSorting(string $tableName, int $pid): int
    {
        $connection = $this->connectionPool->getConnectionForTable($tableName);
        $queryBuilder = $connection->createQueryBuilder();
        $sorting = $queryBuilder
            ->selectLiteral('MAX(sorting)')
            ->from($tableName)
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne();

        return ((int)($sorting ?: 0)) + 256;
    }

    private function flushCaches(OutputInterface $output): void
    {
        /** @var CacheManager $cacheManager */
        $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
        $cacheManager->flushCachesInGroup('pages');
        $output->writeln('TYPO3 page caches were flushed after Solr demo setup.');
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $expected
     */
    private function rowDiffers(array $row, array $expected): bool
    {
        foreach ($expected as $field => $value) {
            if ((string)($row[$field] ?? '') !== (string)$value) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string[] $indexingConfigurations
     */
    private function rebuildIndexQueue(int $rootPageId, int $searchPageUid, array $indexingConfigurations, OutputInterface $output): bool
    {
        $site = $this->getSolrSite($rootPageId);

        /** @var QueueInitializationService $indexQueueInitializationService */
        $indexQueueInitializationService = GeneralUtility::makeInstance(QueueInitializationService::class);
        $results = $indexQueueInitializationService->initializeBySiteAndIndexConfigurations($site, $indexingConfigurations);
        $failedConfigurations = array_keys(array_filter(
            $results,
            static fn (bool $success): bool => !$success,
        ));

        if ($results === [] || $failedConfigurations !== []) {
            throw new \RuntimeException(sprintf(
                'EXT:solr index queue initialization failed for root page %d. Requested: %s. Failed: %s.',
                $rootPageId,
                implode(', ', $indexingConfigurations),
                $failedConfigurations === [] ? 'no enabled index queue configuration matched' : implode(', ', $failedConfigurations),
            ), 1773312909);
        }

        $output->writeln(sprintf(
            'EXT:solr index queue was initialized for root page %d (%s).',
            $rootPageId,
            implode(', ', array_keys($results)),
        ));

        $this->writeQueueStatistics($site, $output, 'after initialization');
        return $this->seedIndexQueueFallback($site, $searchPageUid, $indexingConfigurations, $output) > 0;
    }

    private function processIndexQueue(int $rootPageId, int $limit, OutputInterface $output): bool
    {
        $site = $this->getSolrSite($rootPageId);

        /** @var Queue $queue */
        $queue = GeneralUtility::makeInstance(Queue::class);
        $beforeStatistics = $queue->getStatisticsBySite($site);
        $beforePending = $beforeStatistics->getPendingCount();
        $beforeSuccess = $beforeStatistics->getSuccessCount();

        /** @var IndexService $indexService */
        $indexService = GeneralUtility::makeInstance(IndexService::class, $site);
        $success = $indexService->indexItems($limit);
        $failedItems = $indexService->getFailCount();

        if (!$success) {
            throw new \RuntimeException(sprintf(
                'EXT:solr index queue worker failed for root page %d. Failed queue items: %d.',
                $rootPageId,
                $failedItems,
            ), 1773312910);
        }

        $output->writeln(sprintf(
            'EXT:solr index queue worker processed up to %d documents. Progress: %.2f%%, failed queue items: %d.',
            $limit,
            $indexService->getProgress(),
            $failedItems,
        ));
        $this->writeQueueStatistics($site, $output, 'after worker run');

        $afterStatistics = $queue->getStatisticsBySite($site);
        if ($beforePending === 0) {
            return true;
        }

        return $afterStatistics->getPendingCount() < $beforePending
            || $afterStatistics->getSuccessCount() > $beforeSuccess;
    }

    private function writeQueueStatistics(Site $site, OutputInterface $output, string $label): void
    {
        /** @var Queue $queue */
        $queue = GeneralUtility::makeInstance(Queue::class);
        $statistics = $queue->getStatisticsBySite($site);

        $output->writeln(sprintf(
            'EXT:solr index queue %s: total %d, pending %d, indexed %d, failed %d.',
            $label,
            $statistics->getTotalCount(),
            $statistics->getPendingCount(),
            $statistics->getSuccessCount(),
            $statistics->getFailedCount(),
        ));
    }

    private function ensureIndexQueueSchedulerTask(
        int $rootPageId,
        int $documentsToIndexLimit,
        int $interval,
        OutputInterface $output,
    ): void {
        /** @var SchedulerTaskRepository $taskRepository */
        $taskRepository = GeneralUtility::makeInstance(SchedulerTaskRepository::class);
        $task = $this->findIndexQueueSchedulerTask($rootPageId, $taskRepository);
        $created = $task === null;

        if ($task === null) {
            $existingTaskUid = $this->findIndexQueueSchedulerTaskUidByDescription($rootPageId);
            /** @var IndexQueueWorkerTask $task */
            $task = GeneralUtility::makeInstance(IndexQueueWorkerTask::class);
            if ($existingTaskUid > 0) {
                $task->setTaskUid($existingTaskUid);
                $created = false;
            }
        }

        $task->setRootPageId($rootPageId);
        $task->setDocumentsToIndexLimit($documentsToIndexLimit);
        $task->setExecution(Execution::createRecurringExecution(time(), $interval, 0, false));
        $task->setRunOnNextCronJob(true);
        $task->setDisabled(false);
        $task->setDescription(sprintf(
            'EXT:solr index queue worker for Camino root page %d (%d documents/run).',
            $rootPageId,
            $documentsToIndexLimit,
        ));

        $success = $this->persistIndexQueueSchedulerTask($task, $created);
        if (!$success) {
            throw new \RuntimeException('Could not create or update the EXT:solr scheduler task.', 1773312920);
        }

        $output->writeln(sprintf(
            'EXT:solr scheduler task %s: uid %d, root page %d, %d documents/run, interval %d seconds.',
            $created ? 'created' : 'updated',
            $task->getTaskUid(),
            $rootPageId,
            $documentsToIndexLimit,
            $interval,
        ));
    }

    private function findIndexQueueSchedulerTask(
        int $rootPageId,
        SchedulerTaskRepository $taskRepository,
    ): ?IndexQueueWorkerTask {
        $connection = $this->connectionPool->getConnectionForTable('tx_scheduler_task');
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();
        $uids = $queryBuilder
            ->select('uid')
            ->from('tx_scheduler_task')
            ->where(
                $queryBuilder->expr()->eq('tasktype', $queryBuilder->createNamedParameter(IndexQueueWorkerTask::class)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchFirstColumn();

        foreach ($uids as $uid) {
            try {
                $task = $taskRepository->findByUid((int)$uid);
            } catch (\Throwable) {
                continue;
            }

            if ($task instanceof IndexQueueWorkerTask && (int)$task->getRootPageId() === $rootPageId) {
                return $task;
            }
        }

        return null;
    }

    private function findIndexQueueSchedulerTaskUidByDescription(int $rootPageId): int
    {
        $connection = $this->connectionPool->getConnectionForTable('tx_scheduler_task');
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();
        $uid = $queryBuilder
            ->select('uid')
            ->from('tx_scheduler_task')
            ->where(
                $queryBuilder->expr()->eq('tasktype', $queryBuilder->createNamedParameter(IndexQueueWorkerTask::class)),
                $queryBuilder->expr()->like(
                    'description',
                    $queryBuilder->createNamedParameter(sprintf(
                        'EXT:solr index queue worker for Camino root page %d %%',
                        $rootPageId,
                    )),
                ),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->orderBy('uid', 'ASC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return $uid === false ? 0 : (int)$uid;
    }

    private function persistIndexQueueSchedulerTask(IndexQueueWorkerTask $task, bool $created): bool
    {
        /** @var TaskService $taskService */
        $taskService = GeneralUtility::makeInstance(TaskService::class);
        $fields = $this->normalizeSchedulerTaskFields($taskService->getFieldsForRecord($task));
        $connection = $this->connectionPool->getConnectionForTable('tx_scheduler_task');

        if ($created) {
            $connection->insert(
                'tx_scheduler_task',
                [
                    'pid' => 0,
                    'crdate' => time(),
                    'deleted' => 0,
                    'priority' => 100,
                    'serialized_task_object' => '',
                    'serialized_executions' => '',
                    'lastexecution_time' => 0,
                    'lastexecution_failure' => '',
                    'lastexecution_context' => '',
                ] + $fields,
            );
            $task->setTaskUid((int)$connection->lastInsertId());
            if ($task->getTaskUid() <= 0) {
                $task->setTaskUid($this->findPersistedIndexQueueSchedulerTaskUid($fields['description']));
            }
            return $task->getTaskUid() > 0;
        }

        $connection->update('tx_scheduler_task', $fields, ['uid' => $task->getTaskUid()]);
        return true;
    }

    private function findPersistedIndexQueueSchedulerTaskUid(string $description): int
    {
        $connection = $this->connectionPool->getConnectionForTable('tx_scheduler_task');
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();
        $uid = $queryBuilder
            ->select('uid')
            ->from('tx_scheduler_task')
            ->where(
                $queryBuilder->expr()->eq('tasktype', $queryBuilder->createNamedParameter(IndexQueueWorkerTask::class)),
                $queryBuilder->expr()->eq('description', $queryBuilder->createNamedParameter($description)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->orderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return $uid === false ? 0 : (int)$uid;
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function normalizeSchedulerTaskFields(array $fields): array
    {
        foreach (['parameters', 'execution_details'] as $jsonField) {
            $value = $fields[$jsonField] ?? [];
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                $fields[$jsonField] = json_last_error() === JSON_ERROR_NONE ? $decoded : [];
            } else {
                $fields[$jsonField] = $value;
            }
        }

        $fields['task_group'] = (int)($fields['task_group'] ?? 0);
        $fields['disable'] = (int)($fields['disable'] ?? 0);
        $fields['nextexecution'] = (int)($fields['nextexecution'] ?? time());
        $fields['description'] = (string)($fields['description'] ?? '');
        $fields['tasktype'] = (string)($fields['tasktype'] ?? IndexQueueWorkerTask::class);

        return $fields;
    }

    /**
     * EXT:solr's regular initializer is the preferred path. The Camino starter
     * can still produce an empty queue on Vercel when the generated site tree
     * is not discovered by the page-list helper, so seed visible demo pages as
     * a fallback and keep the normal EXT:solr worker for actual indexing.
     *
     * @param string[] $indexingConfigurations
     */
    private function seedIndexQueueFallback(Site $site, int $searchPageUid, array $indexingConfigurations, OutputInterface $output): int
    {
        if (!in_array('pages', $indexingConfigurations, true) && !in_array('*', $indexingConfigurations, true)) {
            return 0;
        }

        /** @var Queue $queue */
        $queue = GeneralUtility::makeInstance(Queue::class);
        $statistics = $queue->getStatisticsBySite($site);
        if ($statistics->getTotalCount() > 0) {
            return 0;
        }

        $rows = array_values(array_filter(
            $this->collectSitePageRows($site->getRootPageId()),
            fn (array $row): bool => $this->isIndexableDemoPageRow($row, $searchPageUid),
        ));

        if ($rows === []) {
            $output->writeln('EXT:solr fallback queue seeding found no visible demo pages.');
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
                'indexing_configuration' => 'pages',
                'has_indexing_properties' => 0,
                'indexing_priority' => 0,
                'changed' => $changed,
                'indexed' => 0,
                'errors' => '',
                'pages_mountidentifier' => '',
            ]);
            $inserted++;
        }

        $output->writeln(sprintf(
            'EXT:solr fallback queue seeding added %d visible Camino page item(s).',
            $inserted,
        ));
        $this->writeQueueStatistics($site, $output, 'after fallback seeding');
        return $inserted;
    }

    private function indexVisibleDemoPagesDirectly(int $rootPageId, int $searchPageUid, OutputInterface $output): void
    {
        $site = $this->getSolrSite($rootPageId);
        $rows = array_values(array_filter(
            $this->collectSitePageRows($rootPageId),
            fn (array $row): bool => $this->isIndexableDemoPageRow($row, $searchPageUid),
        ));

        if ($rows === []) {
            $output->writeln('EXT:solr direct Camino demo indexing found no visible pages.');
            return;
        }

        $contentByPage = $this->fetchVisibleContentRowsByPageUids(array_map(
            static fn (array $row): int => (int)$row['uid'],
            $rows,
        ));

        $documents = [];
        foreach ($rows as $row) {
            $pageUid = (int)$row['uid'];
            $documents[] = $this->buildDemoPageDocument($site, $row, $contentByPage[$pageUid] ?? []);
        }

        /** @var ConnectionManager $connectionManager */
        $connectionManager = GeneralUtility::makeInstance(ConnectionManager::class);
        $solrConnection = $connectionManager->getConnectionByRootPageId($rootPageId, 0);
        $writeService = $solrConnection->getWriteService();
        $this->runSolrWriteWithRetries(
            static fn (): ResponseAdapter => $writeService->deleteByQuery(sprintf(
                'siteHash:"%s" AND type:pages',
                addcslashes($site->getSiteHash(), '"\\'),
            )),
            'delete existing Camino demo page documents',
            $output,
        );

        $this->runSolrWriteWithRetries(
            static fn (): ResponseAdapter => $writeService->addDocuments($documents),
            'add Camino demo page documents',
            $output,
        );

        $this->runSolrWriteWithRetries(
            static fn (): ResponseAdapter => $writeService->commit(false, false),
            'commit Camino demo page documents',
            $output,
        );

        $visibleDocumentCount = $this->waitForVisibleSolrDocumentCount($solrConnection, $site, count($documents), $output);
        $this->markFallbackQueueItemsIndexed($site, $rows);
        $output->writeln(sprintf(
            'EXT:solr direct Camino demo indexing wrote and committed %d page document(s).',
            count($documents),
        ));
        if ($visibleDocumentCount !== null) {
            $output->writeln(sprintf(
                'EXT:solr post-commit verification sees %d/%d Camino page document(s).',
                $visibleDocumentCount,
                count($documents),
            ));
        }
        $this->writeQueueStatistics($site, $output, 'after direct demo indexing');
    }

    private function waitForVisibleSolrDocumentCount(
        SolrConnection $solrConnection,
        Site $site,
        int $expectedDocuments,
        OutputInterface $output,
    ): ?int {
        $delaysInSeconds = [1, 2, 4];
        $lastCount = null;
        $lastError = null;

        for ($attempt = 0; $attempt <= count($delaysInSeconds); $attempt++) {
            try {
                $lastCount = $this->countVisibleSolrDocuments($solrConnection, $site);
                if ($lastCount >= $expectedDocuments) {
                    return $lastCount;
                }
            } catch (\Throwable $exception) {
                $lastError = $exception->getMessage();
            }

            if ($attempt >= count($delaysInSeconds)) {
                break;
            }

            sleep($delaysInSeconds[$attempt]);
        }

        if ($lastError !== null) {
            $output->writeln(sprintf(
                'WARNING: Could not verify Camino Solr documents on the same connection after commit: %s',
                $lastError,
            ));
            return null;
        }

        return $lastCount;
    }

    private function countVisibleSolrDocuments(SolrConnection $solrConnection, Site $site): int
    {
        /** @var Query $query */
        $query = GeneralUtility::makeInstance(Query::class);
        $query->setQuery('*:*');
        $query->setRows(0);
        $query->createFilterQuery('siteHash')->setQuery(sprintf(
            'siteHash:"%s"',
            addcslashes($site->getSiteHash(), '"\\'),
        ));
        $query->createFilterQuery('type')->setQuery('type:pages');

        $response = $solrConnection->getReadService()->search($query);
        $rawResponse = json_decode($response->getRawResponse(), true);
        if (!is_array($rawResponse)) {
            throw new \RuntimeException('Solr returned a non-JSON response.', 1783529461);
        }

        return (int)($rawResponse['response']['numFound'] ?? 0);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $contentRows
     */
    private function buildDemoPageDocument(Site $site, array $row, array $contentRows): Document
    {
        $uid = (int)$row['uid'];
        /** @var Document $document */
        $document = GeneralUtility::makeInstance(Document::class);
        $documentId = Util::getPageDocumentId($uid, 0, 0, '0');
        $document->setField('id', $documentId);
        $document->setField('site', $site->getSiteIdentifier());
        $document->setField('typo3Context_stringS', (string)Environment::getContext());
        $document->setField('siteHash', $site->getSiteHash());
        $document->setField('domain_stringS', $site->getDomain());
        $document->setField('appKey', 'EXT:solr');
        $document->setField('type', 'pages');
        $document->setField('uid', $uid);
        $document->setField('pid', (int)$row['pid']);
        $document->setField('variantId', $documentId);
        $document->setField('typeNum', 0);
        $document->setField('created', $this->solrIsoDate((int)($row['crdate'] ?? $row['tstamp'] ?? time())));
        $document->setField('changed', $this->solrIsoDate((int)($row['SYS_LASTCHANGED'] ?? $row['tstamp'] ?? time())));
        $document->setField('rootline', (string)$uid);
        $document->setField('access', 'c:0');
        $document->setField('title', trim((string)($row['title'] ?? '')));
        $document->setField('subTitle', trim((string)($row['subtitle'] ?? '')));
        $document->setField('navTitle', trim((string)($row['nav_title'] ?? '')));
        $document->setField('author', trim((string)($row['author'] ?? '')));
        $document->setField('description', trim((string)($row['description'] ?? '')));
        $document->setField('abstract', trim((string)($row['abstract'] ?? '')));
        $document->setField('content', $this->extractDemoPageText($row, $contentRows));
        $document->setField('url', $this->buildDemoPageUrl($site, $row));

        foreach (GeneralUtility::trimExplode(',', (string)($row['keywords'] ?? ''), true) as $keyword) {
            $document->addField('keywords', $keyword);
        }

        return $document;
    }

    private function solrIsoDate(int $timestamp): string
    {
        /** @var FormatService $formatService */
        $formatService = GeneralUtility::makeInstance(FormatService::class);
        return $formatService->timestampToIso(max(0, $timestamp));
    }

    /**
     * @param int[] $pageUids
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function fetchVisibleContentRowsByPageUids(array $pageUids): array
    {
        $pageUids = array_values(array_unique(array_filter(array_map('intval', $pageUids), static fn (int $uid): bool => $uid > 0)));
        if ($pageUids === []) {
            return [];
        }

        $connection = $this->connectionPool->getConnectionForTable('tt_content');
        $queryBuilder = $connection->createQueryBuilder();
        $rows = $queryBuilder
            ->select('*')
            ->from('tt_content')
            ->where(
                'pid IN (' . implode(',', $pageUids) . ')',
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('hidden', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->in('sys_language_uid', ['0', '-1']),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->eq('endtime', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    $queryBuilder->expr()->gt('endtime', $queryBuilder->createNamedParameter(time(), Connection::PARAM_INT)),
                ),
            )
            ->orderBy('pid', 'ASC')
            ->addOrderBy('sorting', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $byPage = [];
        foreach ($rows as $row) {
            $byPage[(int)$row['pid']][] = $row;
        }

        return $byPage;
    }

    /**
     * @param array<string, mixed> $pageRow
     * @param array<int, array<string, mixed>> $contentRows
     */
    private function extractDemoPageText(array $pageRow, array $contentRows): string
    {
        $parts = [];
        foreach (['title', 'subtitle', 'nav_title', 'description', 'abstract', 'keywords'] as $field) {
            $this->appendTextPart($parts, $pageRow[$field] ?? null);
        }

        $ignoredFields = [
            'uid' => true,
            'pid' => true,
            'sorting' => true,
            'deleted' => true,
            'hidden' => true,
            'sys_language_uid' => true,
            'pi_flexform' => true,
        ];

        foreach ($contentRows as $contentRow) {
            foreach ($contentRow as $field => $value) {
                if (isset($ignoredFields[$field]) || str_starts_with((string)$field, 't3ver_')) {
                    continue;
                }
                $this->appendTextPart($parts, $value);
            }
        }

        return mb_substr($this->normalizeText(implode(' ', $parts)), 0, 60000);
    }

    /**
     * @param string[] $parts
     */
    private function appendTextPart(array &$parts, mixed $value): void
    {
        if (!is_scalar($value)) {
            return;
        }
        $text = $this->normalizeText((string)$value);
        if ($text !== '' && !is_numeric($text)) {
            $parts[] = $text;
        }
    }

    private function normalizeText(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';
        return trim($value);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function buildDemoPageUrl(Site $site, array $row): string
    {
        $slug = $this->normalizeSlug((string)($row['slug'] ?? '/'));
        $base = getenv('TYPO3_SOLR_DEMO_PUBLIC_BASE_URL') ?: getenv('TYPO3_SOLR_SITE_BASE') ?: '';
        if ($base === '') {
            $siteBase = (string)$site->getTypo3SiteObject()->getBase();
            if (str_starts_with($siteBase, 'http://') || str_starts_with($siteBase, 'https://')) {
                $base = $siteBase;
            }
        }
        if ($base === '' || $base === '/') {
            $vercelHost = getenv('VERCEL_PROJECT_PRODUCTION_URL') ?: getenv('VERCEL_URL') ?: 'typo3-camino-vercel.vercel.app';
            $base = str_starts_with($vercelHost, 'http://') || str_starts_with($vercelHost, 'https://')
                ? $vercelHost
                : 'https://' . $vercelHost;
        }

        return rtrim($base, '/') . ($slug === '/' ? '/' : $slug);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function markFallbackQueueItemsIndexed(Site $site, array $rows): void
    {
        $uids = array_values(array_unique(array_map(
            static fn (array $row): int => (int)$row['uid'],
            $rows,
        )));
        if ($uids === []) {
            return;
        }

        $connection = $this->connectionPool->getConnectionForTable('tx_solr_indexqueue_item');
        $connection->executeStatement(
            'UPDATE tx_solr_indexqueue_item SET indexed = ?, errors = ? WHERE root = ? AND item_type = ? AND item_uid IN (' . implode(',', $uids) . ')',
            [time(), '', $site->getRootPageId(), 'pages'],
            [Connection::PARAM_INT, Connection::PARAM_STR, Connection::PARAM_INT, Connection::PARAM_STR],
        );
    }

    private function assertSolrResponseOk(int $httpStatus, string $action): void
    {
        if ($httpStatus < 200 || $httpStatus >= 300) {
            throw new \RuntimeException(sprintf(
                'Could not %s; Solr returned HTTP %d.',
                $action,
                $httpStatus,
            ), 1773312912);
        }
    }

    /**
     * @param \Closure(): ResponseAdapter $operation
     */
    private function runSolrWriteWithRetries(\Closure $operation, string $action, OutputInterface $output): ResponseAdapter
    {
        $delaysInSeconds = [1, 2, 4, 6];
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $response = $operation();
                $httpStatus = $response->getHttpStatus();
                if ($httpStatus >= 200 && $httpStatus < 300) {
                    return $response;
                }

                if (!$this->isRetryableSolrStatus($httpStatus) || $attempt > count($delaysInSeconds)) {
                    $this->assertSolrResponseOk($httpStatus, $action);
                }

                $output->writeln(sprintf(
                    'Solr returned HTTP %d while trying to %s; retrying after %d second(s).',
                    $httpStatus,
                    $action,
                    $delaysInSeconds[$attempt - 1],
                ));
            } catch (\Throwable $exception) {
                if ($attempt > count($delaysInSeconds)) {
                    throw new \RuntimeException(sprintf(
                        'Could not %s; Solr request failed after %d attempt(s): %s',
                        $action,
                        $attempt,
                        $exception->getMessage(),
                    ), 1773312913, $exception);
                }

                $output->writeln(sprintf(
                    'Solr request failed while trying to %s; retrying after %d second(s): %s',
                    $action,
                    $delaysInSeconds[$attempt - 1],
                    $exception->getMessage(),
                ));
            }

            sleep($delaysInSeconds[$attempt - 1]);
        }
    }

    private function isRetryableSolrStatus(int $httpStatus): bool
    {
        return in_array($httpStatus, [500, 502, 503, 504], true);
    }

    private function normalizeDemoPagesForIndexing(int $rootPageId, int $searchPageUid, OutputInterface $output): void
    {
        $connection = $this->connectionPool->getConnectionForTable('pages');
        $rows = $this->collectSitePageRows($rootPageId);
        $changedPages = 0;
        $now = time();

        foreach ($rows as $row) {
            $uid = (int)$row['uid'];
            $updates = [];

            if ($uid === $rootPageId && (int)($row['no_search_sub_entries'] ?? 0) !== 0) {
                $updates['no_search_sub_entries'] = 0;
            }

            if ($uid !== $searchPageUid && $this->isVisibleStandardPageRow($row) && (int)($row['no_search'] ?? 0) !== 0) {
                $updates['no_search'] = 0;
            }

            if ($updates === []) {
                continue;
            }

            $connection->update(
                'pages',
                ['tstamp' => $now] + $updates,
                ['uid' => $uid],
            );
            $changedPages++;
        }

        $output->writeln(sprintf(
            'TYPO3 Solr demo page normalization checked %d page row(s), updated %d.',
            count($rows),
            $changedPages,
        ));
    }

    private function writePageDiagnostics(int $rootPageId, int $searchPageUid, OutputInterface $output): void
    {
        $site = $this->getSolrSite($rootPageId);
        $sitePageIds = $site->getPages(null, 'pages');
        $rows = $this->collectSitePageRows($rootPageId);
        $candidateRows = array_values(array_filter(
            $rows,
            fn (array $row): bool => $this->isIndexableDemoPageRow($row, $searchPageUid),
        ));

        $output->writeln(sprintf(
            'TYPO3 Solr diagnostics: site page helper returned %d id(s): %s',
            count($sitePageIds),
            implode(',', array_slice($sitePageIds, 0, 40)),
        ));
        $output->writeln(sprintf(
            'TYPO3 Solr diagnostics: direct site-tree scan found %d page row(s), %d indexable visible page row(s).',
            count($rows),
            count($candidateRows),
        ));

        foreach (array_slice($rows, 0, 25) as $row) {
            $output->writeln(sprintf(
                'page uid=%d pid=%d doktype=%d hidden=%d deleted=%d lang=%d no_search=%d no_search_sub=%d slug=%s title=%s',
                (int)$row['uid'],
                (int)$row['pid'],
                (int)($row['doktype'] ?? 0),
                (int)($row['hidden'] ?? 0),
                (int)($row['deleted'] ?? 0),
                (int)($row['sys_language_uid'] ?? 0),
                (int)($row['no_search'] ?? 0),
                (int)($row['no_search_sub_entries'] ?? 0),
                (string)($row['slug'] ?? ''),
                trim((string)($row['title'] ?? '')),
            ));
        }

        $this->writeQueueStatistics($site, $output, 'diagnostic snapshot');
        $this->writeSearchContentDiagnostics($searchPageUid, $output);
    }

    private function writeSearchContentDiagnostics(int $searchPageUid, OutputInterface $output): void
    {
        $connection = $this->connectionPool->getConnectionForTable('tt_content');
        $queryBuilder = $connection->createQueryBuilder();
        $rows = $queryBuilder
            ->select('uid', 'pid', 'CType', 'hidden', 'deleted', 'colPos', 'header', 'pi_flexform')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($searchPageUid, Connection::PARAM_INT)),
            )
            ->orderBy('sorting', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        if ($rows === []) {
            $output->writeln(sprintf('TYPO3 Solr diagnostics: search page %d has no content rows.', $searchPageUid));
            return;
        }

        foreach ($rows as $row) {
            $output->writeln(sprintf(
                'search-content uid=%d pid=%d CType=%s hidden=%d deleted=%d colPos=%d flexformBytes=%d header=%s',
                (int)$row['uid'],
                (int)$row['pid'],
                (string)($row['CType'] ?? ''),
                (int)($row['hidden'] ?? 0),
                (int)($row['deleted'] ?? 0),
                (int)($row['colPos'] ?? 0),
                strlen((string)($row['pi_flexform'] ?? '')),
                trim((string)($row['header'] ?? '')),
            ));
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collectSitePageRows(int $rootPageId): array
    {
        $rows = [];
        $pending = [$rootPageId];
        $seen = [];

        while ($pending !== [] && count($seen) < 1000) {
            $currentIds = array_values(array_unique(array_map('intval', $pending)));
            $pending = [];

            foreach ($this->fetchPagesByUids($currentIds) as $row) {
                $uid = (int)$row['uid'];
                if (isset($seen[$uid])) {
                    continue;
                }
                $seen[$uid] = true;
                $rows[$uid] = $row;
            }

            foreach ($this->fetchPagesByPids($currentIds) as $row) {
                $uid = (int)$row['uid'];
                if (isset($seen[$uid])) {
                    continue;
                }
                $rows[$uid] = $row;
                $pending[] = $uid;
            }
        }

        ksort($rows, SORT_NUMERIC);
        return array_values($rows);
    }

    /**
     * @param int[] $uids
     * @return array<int, array<string, mixed>>
     */
    private function fetchPagesByUids(array $uids): array
    {
        return $this->fetchPagesByField('uid', $uids);
    }

    /**
     * @param int[] $pids
     * @return array<int, array<string, mixed>>
     */
    private function fetchPagesByPids(array $pids): array
    {
        return $this->fetchPagesByField('pid', $pids);
    }

    /**
     * @param int[] $values
     * @return array<int, array<string, mixed>>
     */
    private function fetchPagesByField(string $field, array $values): array
    {
        $values = array_values(array_unique(array_filter(array_map('intval', $values), static fn (int $value): bool => $value >= 0)));
        if ($values === []) {
            return [];
        }

        $connection = $this->connectionPool->getConnectionForTable('pages');
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder
            ->select(
                'uid',
                'pid',
                'tstamp',
                'crdate',
                'SYS_LASTCHANGED',
                'starttime',
                'endtime',
                'hidden',
                'deleted',
                'doktype',
                'title',
                'subtitle',
                'nav_title',
                'author',
                'description',
                'abstract',
                'keywords',
                'slug',
                'no_search',
                'no_search_sub_entries',
                'is_siteroot',
                'sys_language_uid',
                't3ver_wsid',
            )
            ->from('pages')
            ->where($field . ' IN (' . implode(',', $values) . ')')
            ->orderBy('sorting', 'ASC')
            ->addOrderBy('uid', 'ASC');

        return $queryBuilder->executeQuery()->fetchAllAssociative();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isIndexableDemoPageRow(array $row, int $searchPageUid): bool
    {
        return (int)$row['uid'] !== $searchPageUid
            && $this->isVisibleStandardPageRow($row)
            && (int)($row['no_search'] ?? 0) === 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isVisibleStandardPageRow(array $row): bool
    {
        $doktype = (int)($row['doktype'] ?? 0);
        return (int)($row['deleted'] ?? 0) === 0
            && (int)($row['hidden'] ?? 0) === 0
            && in_array((int)($row['sys_language_uid'] ?? 0), [0, -1], true)
            && (int)($row['t3ver_wsid'] ?? 0) === 0
            && ((int)($row['endtime'] ?? 0) === 0 || (int)$row['endtime'] > time())
            && ($doktype === 1 || $doktype === 7);
    }

    private function getSolrSite(int $rootPageId): Site
    {
        /** @var SiteRepository $siteRepository */
        $siteRepository = GeneralUtility::makeInstance(SiteRepository::class);
        $site = $siteRepository->getSiteByRootPageId($rootPageId);

        if (!$site instanceof Site) {
            throw new \RuntimeException(sprintf('No EXT:solr site found for root page %d.', $rootPageId), 1773312911);
        }

        return $site;
    }

    private function normalizeSlug(string $slug): string
    {
        $slug = trim($slug);
        if ($slug === '') {
            return '/search';
        }
        if (!str_starts_with($slug, '/')) {
            $slug = '/' . $slug;
        }
        return $slug;
    }

    private function stringOption(InputInterface $input, string $name, string $default): string
    {
        $value = $input->getOption($name);
        return is_string($value) && $value !== '' ? $value : $default;
    }

    private function intOption(InputInterface $input, string $name, int $default): int
    {
        $value = $input->getOption($name);
        return is_numeric($value) ? (int)$value : $default;
    }

    /**
     * @return string[]
     */
    private function stringArrayOption(InputInterface $input, string $name, array $default): array
    {
        $value = $input->getOption($name);
        if (!is_array($value)) {
            return $default;
        }

        $value = array_values(array_filter(array_map(
            static fn (mixed $item): string => trim((string)$item),
            $value,
        )));

        return $value === [] ? $default : $value;
    }
}
