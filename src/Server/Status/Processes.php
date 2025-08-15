<?php

declare(strict_types=1);

namespace PhpMyAdmin\Server\Status;

use PhpMyAdmin\Dbal\DatabaseInterface;
use PhpMyAdmin\Html\Generator;
use PhpMyAdmin\Util;

use function __;
use function array_change_key_case;
use function array_key_exists;
use function number_format;

use const CASE_LOWER;

final class Processes
{
    public function __construct(private DatabaseInterface $dbi)
    {
    }

    /** @return array<string, array|string|bool> */
    public function getList(bool $showExecuting, bool $showFullSql, string $orderByField, string $sortOrder): array
    {
        $urlParams = [
            'full' => $showFullSql ? '' : 1,
        ];

        $sqlQuery = $showFullSql ? 'SHOW FULL PROCESSLIST' : 'SHOW PROCESSLIST';
        $useIS = $showExecuting || ($orderByField !== '' && $sortOrder !== '');
        if ($useIS) {
            $urlParams['order_by_field'] = $orderByField;
            $urlParams['sort_order'] = $sortOrder;
            $urlParams['showExecuting'] = $showExecuting;
            $sqlQuery = 'SELECT * FROM `INFORMATION_SCHEMA`.`PROCESSLIST`';
            if ($showExecuting) {
                $sqlQuery .= " WHERE `STATE` <> ''";
            }

            if ($orderByField !== '' && $sortOrder !== '') {
                $sqlQuery .= ' ORDER BY ' . Util::backquote($orderByField) . ' ' . $sortOrder;
            }
        }

        $result = $this->dbi->query($sqlQuery);
        $rows = [];
        while ($process = $result->fetchAssoc()) {
            // Array keys need to modify due to the way it has used to display column values
            $process = array_change_key_case($process, CASE_LOWER);

            $progress = $process['progress'] ?? '---';
            if ($useIS && isset($process['progress'])) {
                $stage = array_key_exists('stage', $process) ? (int) $process['stage'] : null;
                $maxStage = array_key_exists('max_stage', $process) ? (int) $process['max_stage'] : null;
                if ($stage !== null && $maxStage !== null && $maxStage > 1) {
                    $progress = number_format(($stage - 1) / $maxStage * 100 + ((float) $progress) / $maxStage, 3);
                }
            }

            $rows[] = [
                'id' => $process['id'],
                'user' => $process['user'],
                'host' => $process['host'],
                'db' => $process['db'] ?? '',
                'command' => $process['command'],
                'time' => $process['time'],
                'state' => ! empty($process['state']) ? $process['state'] : '---',
                'progress' => $progress,
                'info' => ! empty($process['info']) ? Generator::formatSql($process['info'], ! $showFullSql) : '---',
            ];
        }

        $columns = $this->getSortableColumnsForProcessList($orderByField, $sortOrder);

        return [
            'columns' => $columns,
            'is_full' => $showFullSql,
            'rows' => $rows,
            'refresh_params' => $urlParams,
            'is_mariadb' => $this->dbi->isMariaDB(),
        ];
    }

    /** @return mixed[] */
    private function getSortableColumnsForProcessList(
        string $orderByField,
        string $sortOrder,
    ): array {
        // This array contains display name and real column name of each
        // sortable column in the table
        $sortableColumns = [
            ['column_name' => __('ID'), 'order_by_field' => 'ID'],
            ['column_name' => __('User'), 'order_by_field' => 'USER'],
            ['column_name' => __('Host'), 'order_by_field' => 'HOST'],
            ['column_name' => __('Database'), 'order_by_field' => 'DB'],
            ['column_name' => __('Command'), 'order_by_field' => 'COMMAND'],
            ['column_name' => __('Time'), 'order_by_field' => 'TIME'],
            ['column_name' => __('Status'), 'order_by_field' => 'STATE'],
        ];
        if ($this->dbi->isMariaDB()) {
            $sortableColumns[] = ['column_name' => __('Progress'), 'order_by_field' => 'PROGRESS'];
        }

        $sortableColumns[] = ['column_name' => __('SQL query'), 'order_by_field' => 'INFO'];

        $columns = [];
        foreach ($sortableColumns as $column) {
            $isSorted = $sortOrder !== '' && $orderByField === $column['order_by_field'];
            $column['sort_order'] = $isSorted && $sortOrder === 'ASC' ? 'DESC' : 'ASC';

            $columns[] = [
                'name' => $column['column_name'],
                'params' => $column,
                'is_sorted' => $isSorted,
                'sort_order' => $column['sort_order'],
            ];
        }

        return $columns;
    }
}
