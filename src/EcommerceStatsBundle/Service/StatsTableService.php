<?php

namespace ChameleonSystem\EcommerceStatsBundle\Service;

use ChameleonSystem\EcommerceStatsBundle\DataModel\StatsGroupDataModel;
use ChameleonSystem\EcommerceStatsBundle\DataModel\StatsTableDataModel;
use ChameleonSystem\EcommerceStatsBundle\Interfaces\StatsTableServiceInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\FetchMode;
use Psr\Log\LoggerInterface;
use Symfony\Component\Translation\TranslatorInterface;

/**
 * Note: This service is not shared because it is stateful.
 *       As such every time you inject it you'll get a new instance
 *       of it without any data.
 */
class StatsTableService implements StatsTableServiceInterface
{
    /**
     * @var StatsGroupDataModel[]
     */
    private $blocks = [];

    /**
     * @var bool
     */
    private $showDiffColumn = false;

    /**
     * @var string|null
     */
    private $blockName = null;

    /**
     * @var string[]
     */
    private $columnNames;

    /**
     * @var int
     */
    private $maxGroupCount;

    /**
     * @var Connection
     */
    private $connection;

    /**
     * @var TranslatorInterface
     */
    private $translator;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        Connection $connection,
        TranslatorInterface $translator,
        LoggerInterface $logger
    ) {
        $this->connection = $connection;
        $this->translator = $translator;
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public function evaluate(string $startDate, string $endDate, string $dateGroupType, bool $showDiffColumn, string $portalId = ''): void
    {
        $this->blocks = [];
        $this->blockName = null;
        $this->dateGroupType = $dateGroupType;
        $this->setShowDiffColumn($showDiffColumn);

        switch ($this->dateGroupType) {
            case self::DATA_GROUP_TYPE_YEAR:
                $dateQueryPart = 'YEAR(datecreated)';
                break;

            case self::DATA_GROUP_TYPE_MONTH:
                $dateQueryPart = "DATE_FORMAT(datecreated,'%Y-%m')";
                break;

            case self::DATA_GROUP_TYPE_WEEK:
                $dateQueryPart = "CONCAT(YEAR(datecreated), '-KW', WEEK(datecreated, 7))";
                break;

            case self::DATA_GROUP_TYPE_DAY:
            default:
                $dateQueryPart = 'DATE(datecreated)';
                break;
        }

        $groups = \TdbPkgShopStatisticGroupList::GetList();
        while ($group = $groups->Next()) {
            $params = [];
            $baseConditionList = [];

            if (null !== $startDate) {
                $baseConditionList[] = $this->connection->quoteIdentifier(str_replace('`', '', $group->fieldDateRestrictionField)).' >= :from';
                $params[':from'] = $startDate;
            }

            if (null !== $endDate) {
                $baseConditionList[] = $this->connection->quoteIdentifier(str_replace('`', '', $group->fieldDateRestrictionField)).' <= :to';
                $params[':to'] = $endDate;
            }

            if ('' !== $group->fieldPortalRestrictionField && '' !== $portalId) {
                $baseConditionList[] = $this->connection->quoteIdentifier(str_replace('`', '', $group->fieldPortalRestrictionField)).' = :portalId';
                $params[':portalId'] = $portalId;
            }

            $conditionList = $baseConditionList;
            $baseQuery = $group->fieldQuery;
            $condition = '';
            if (count($conditionList) > 0) {
                $condition = 'WHERE ('.implode(') AND (', $conditionList).')';
            }
            $blockQuery = str_replace(['[{sColumnName}]', '[{sCondition}]'], [$dateQueryPart, $condition], $baseQuery);
            $groupFields = explode(',', $group->fieldGroups);
            $realGroupFields = array_filter(array_map('trim', $groupFields));

            $this->addBlock($group->fieldName, $blockQuery, $realGroupFields, $params);
        }

        $this->columnNames = $this->getColumnNames();
        $this->maxGroupCount = $this->getMaxGroupColumnCount();
        $this->showDiffColumn = $this->isShowDiffColumn();
    }

    /*
     * add a new block to the list
    */
    protected function addBlock(string $blockName, string $query, array $subGroups = [], array $params = []): void
    {
        try {
            $sqlStatement = $this->connection->executeQuery($query, $params);
        } catch (DBALException $e) {
            $this->logger->error(\sprintf('Error adding ecommerce stats block'), ['exception' => $e]);

            return;
        }

        if (false === array_key_exists($blockName, $this->blocks)) {
            $ecommerceStatsGroup = new StatsGroupDataModel();
            $ecommerceStatsGroup->init($blockName);
            $this->blocks[$blockName] = $ecommerceStatsGroup;
        }

        while ($dataRow = $sqlStatement->fetch(FetchMode::ASSOCIATIVE)) {
            $realNames = [];
            foreach ($subGroups as $groupName) {
                if (strlen($dataRow[$groupName]) > 0) {
                    $realNames[] = $dataRow[$groupName];
                } else {
                    $realNames[] = $this->translator->trans('chameleon_system_ecommerce_stats.nothing_assigned');
                }
            }

            if (!\array_key_exists('sColumnName', $dataRow) || !\array_key_exists('dColumnValue', $dataRow)) {
                $this->logger->error(sprintf(
                    'Could not add block `%s` to table: Query must select at least `sColumnName` and `dColumnValue`',
                    $blockName
                ));

                return;
            }

            $this->blocks[$blockName]->addRow($realNames, $dataRow['sColumnName'], $dataRow['dColumnValue'], $dataRow);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getTableData(): StatsTableDataModel
    {
        return new StatsTableDataModel(
            $this->blocks,
            $this->columnNames,
            $this->showDiffColumn,
            $this->maxGroupCount
        );
    }

    public function getColumnNames(): array
    {
        $nameColumns = [];
        foreach ($this->blocks as $block) {
            $tmpNames = $block->getColumnNames();
            foreach ($tmpNames as $name) {
                if (false === in_array($name, $nameColumns)) {
                    $nameColumns[] = $name;
                }
            }
        }
        asort($nameColumns);

        return $nameColumns;
    }

    public function getMaxGroupColumnCount(): int
    {
        $maxCount = 0;
        foreach ($this->blocks as $block) {
            $maxCount = max($block->getMaxGroupDepth() + 1, $maxCount);
        }

        return $maxCount;
    }

    public function setShowDiffColumn(bool $showDiffColumn): void
    {
        $this->showDiffColumn = $showDiffColumn;
    }

    public function isShowDiffColumn(): bool
    {
        return $this->showDiffColumn;
    }

    public function setBlockName(?string $blockName): void
    {
        $this->blockName = $blockName;
    }

    /**
     * @return StatsGroupDataModel[]
     */
    public function getBlocks(): array
    {
        return $this->blocks;
    }

    public function getCSVData(): array
    {
        $this->local = \TCMSLocal::GetActive();

        $row = array_fill(0, $this->maxGroupCount, '');

        foreach ($this->columnNames as $name) {
            $row[] = $name;
            if ($this->showDiffColumn) {
                $row[] = $this->translator->trans('chameleon_system_ecommerce_stats.delta');
            }
        }

        $data = [$row]; // header

        foreach ($this->blocks as $block) {
            $this->exportBlockCSV($data, $block, 1);
            $data[] = [];
        }

        return $data;
    }

    protected function exportBlockCSV(array &$data, StatsGroupDataModel $group, int $level = 1): void
    {
        $row = array_fill(0, $level - 1, '');
        $row[] = $group->getGroupTitle();

        $emptyGroups = $this->maxGroupCount - $level;

        for ($i = 0; $i < $emptyGroups; ++$i) {
            $row[] = $this->translator->trans('chameleon_system_ecommerce_stats.total');
        }

        $oldVal = 0;
        foreach ($this->columnNames as $name) {
            $newVal = $group->getTotals($name) ?? 0;
            $row[] = $this->local->FormatNumber($newVal, 2);
            $dDiff = $newVal - $oldVal;
            if ($this->showDiffColumn) {
                $row[] = $this->local->FormatNumber($dDiff, 2);
            }
            $oldVal = $newVal;
        }

        $data[] = $row;

        foreach ($group->getSubGroups() as $subGroup) {
            $this->exportBlockCSV($data, $subGroup, $level + 1);
        }
    }
}