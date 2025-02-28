<?php

declare(strict_types=1);

namespace PhpMyAdmin\Controllers\View;

use PhpMyAdmin\Controllers\AbstractController;
use PhpMyAdmin\Controllers\Table\StructureController;
use PhpMyAdmin\Core;
use PhpMyAdmin\DatabaseInterface;
use PhpMyAdmin\Html\Generator;
use PhpMyAdmin\Http\ServerRequest;
use PhpMyAdmin\Message;
use PhpMyAdmin\ResponseRenderer;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statements\CreateStatement;
use PhpMyAdmin\SqlParser\TokensList;
use PhpMyAdmin\Template;
use PhpMyAdmin\Url;
use PhpMyAdmin\Util;

use function __;
use function array_merge;
use function explode;
use function htmlspecialchars;
use function in_array;
use function sprintf;
use function str_contains;
use function substr;

/**
 * Handles creation of VIEWs.
 */
class CreateController extends AbstractController
{
    private DatabaseInterface $dbi;

    /** @todo Move the whole view rebuilding logic to SQL parser */
    private const VIEW_SECURITY_OPTIONS = [
        'DEFINER',
        'INVOKER',
    ];

    private const VIEW_ALGORITHM_OPTIONS = [
        'UNDEFINED',
        'MERGE',
        'TEMPTABLE',
    ];

    private const VIEW_WITH_OPTIONS = [
        'CASCADED',
        'LOCAL',
    ];

    public function __construct(ResponseRenderer $response, Template $template, DatabaseInterface $dbi)
    {
        parent::__construct($response, $template);
        $this->dbi = $dbi;
    }

    public function __invoke(ServerRequest $request): void
    {
        $this->checkParameters(['db']);
        $GLOBALS['text_dir'] = $GLOBALS['text_dir'] ?? null;
        $GLOBALS['urlParams'] = $GLOBALS['urlParams'] ?? null;
        $GLOBALS['message'] = $GLOBALS['message'] ?? null;

        $GLOBALS['errorUrl'] = Util::getScriptNameForOption($GLOBALS['cfg']['DefaultTabDatabase'], 'database');
        $GLOBALS['errorUrl'] .= Url::getCommon(['db' => $GLOBALS['db']], '&');

        if (! $this->hasDatabase()) {
            return;
        }

        $GLOBALS['urlParams']['goto'] = Url::getFromRoute('/table/structure');
        $GLOBALS['urlParams']['back'] = Url::getFromRoute('/view/create');

        /** @var array|null $view */
        $view = $request->getParsedBodyParam('view');

        // View name is a compulsory field
        if (empty($view['name'])) {
            $GLOBALS['message'] = Message::error(__('View name can not be empty!'));
            $this->response->addJSON('message', $GLOBALS['message']);
            $this->response->setRequestStatus(false);

            return;
        }

        $createview = $request->hasBodyParam('createview');
        $alterview = $request->hasBodyParam('alterview');
        $ajaxdialog = $request->hasBodyParam('ajax_dialog');

        if ($createview || $alterview) {
            /**
             * Creates the view
             */
            $separator = "\r\n";

            if ($createview) {
                $GLOBALS['sql_query'] = 'CREATE';
                if (isset($view['or_replace'])) {
                    $GLOBALS['sql_query'] .= ' OR REPLACE';
                }
            } else {
                $GLOBALS['sql_query'] = 'ALTER';
            }

            if (
                isset($view['algorithm'])
                && in_array($view['algorithm'], self::VIEW_ALGORITHM_OPTIONS)
            ) {
                $GLOBALS['sql_query'] .= $separator . ' ALGORITHM = ' . $view['algorithm'];
            }

            if (! empty($view['definer'])) {
                if (! str_contains($view['definer'], '@')) {
                    $GLOBALS['sql_query'] .= $separator . 'DEFINER='
                        . Util::backquote($view['definer']);
                } else {
                    $definerArray = explode('@', $view['definer']);
                    $GLOBALS['sql_query'] .= $separator . 'DEFINER=' . Util::backquote($definerArray[0]);
                    $GLOBALS['sql_query'] .= '@' . Util::backquote($definerArray[1]) . ' ';
                }
            }

            if (
                isset($view['sql_security'])
                && in_array($view['sql_security'], self::VIEW_SECURITY_OPTIONS)
            ) {
                $GLOBALS['sql_query'] .= $separator . ' SQL SECURITY '
                    . $view['sql_security'];
            }

            $GLOBALS['sql_query'] .= $separator . ' VIEW '
                . Util::backquote($view['name']);

            if (! empty($view['column_names'])) {
                $GLOBALS['sql_query'] .= $separator . ' (' . $view['column_names'] . ')';
            }

            $GLOBALS['sql_query'] .= $separator . ' AS ' . $view['as'];

            if (isset($view['with']) && in_array($view['with'], self::VIEW_WITH_OPTIONS)) {
                $GLOBALS['sql_query'] .= $separator . ' WITH ' . $view['with'] . '  CHECK OPTION';
            }

            if (! $this->dbi->tryQuery($GLOBALS['sql_query'])) {
                if (! $ajaxdialog) {
                    $GLOBALS['message'] = Message::rawError($this->dbi->getError());

                    return;
                }

                $this->response->addJSON(
                    'message',
                    Message::error(
                        '<i>' . htmlspecialchars($GLOBALS['sql_query']) . '</i><br><br>'
                        . $this->dbi->getError()
                    )
                );
                $this->response->setRequestStatus(false);

                return;
            }

            // If different column names defined for VIEW
            $viewColumns = [];
            if (isset($view['column_names'])) {
                $viewColumns = explode(',', $view['column_names']);
            }

            $columnMap = $this->dbi->getColumnMapFromSql($view['as'], $viewColumns);

            $systemDb = $this->dbi->getSystemDatabase();
            $pmaTransformationData = $systemDb->getExistingTransformationData($GLOBALS['db']);

            if ($pmaTransformationData !== false) {
                // SQL for store new transformation details of VIEW
                $newTransformationsSql = $systemDb->getNewTransformationDataSql(
                    $pmaTransformationData,
                    $columnMap,
                    $view['name'],
                    $GLOBALS['db']
                );

                // Store new transformations
                if ($newTransformationsSql !== '') {
                    $this->dbi->tryQuery($newTransformationsSql);
                }
            }

            if ($ajaxdialog) {
                $GLOBALS['message'] = Message::success();
                /** @var StructureController $controller */
                $controller = Core::getContainerBuilder()->get(StructureController::class);
                $controller($request);
            } else {
                $this->response->addJSON(
                    'message',
                    Generator::getMessage(
                        Message::success(),
                        $GLOBALS['sql_query']
                    )
                );
                $this->response->setRequestStatus(true);
            }

            return;
        }

        $GLOBALS['sql_query'] = $request->getParsedBodyParam('sql_query', '');

        // prefill values if not already filled from former submission
        $viewData = [
            'operation' => 'create',
            'or_replace' => '',
            'algorithm' => '',
            'definer' => '',
            'sql_security' => '',
            'name' => '',
            'column_names' => '',
            'as' => $GLOBALS['sql_query'],
            'with' => '',
        ];

        // Used to prefill the fields when editing a view
        if (isset($_GET['db'], $_GET['table'])) {
            $item = $this->dbi->fetchSingleRow(
                sprintf(
                    "SELECT `VIEW_DEFINITION`, `CHECK_OPTION`, `DEFINER`,
            `SECURITY_TYPE`
            FROM `INFORMATION_SCHEMA`.`VIEWS`
            WHERE TABLE_SCHEMA='%s'
            AND TABLE_NAME='%s';",
                    $this->dbi->escapeString($_GET['db']),
                    $this->dbi->escapeString($_GET['table'])
                )
            );
            $createView = $this->dbi->getTable($_GET['db'], $_GET['table'])
                ->showCreate();

            // CREATE ALGORITHM=<ALGORITHM> DE...
            $item['ALGORITHM'] = explode(' ', substr($createView, 17))[0];

            $viewData['operation'] = 'alter';
            $viewData['definer'] = $item['DEFINER'];
            $viewData['sql_security'] = $item['SECURITY_TYPE'];
            $viewData['name'] = $_GET['table'];
            $viewData['as'] = $item['VIEW_DEFINITION'];
            $viewData['with'] = $item['CHECK_OPTION'];
            $viewData['algorithm'] = $item['ALGORITHM'];

            // MySQL 8.0+ - issue #16194
            if (empty($viewData['as'])) {
                $parser = new Parser($createView);
                /**
                 * @var CreateStatement $stmt
                 */
                $stmt = $parser->statements[0];
                $viewData['as'] = isset($stmt->body) ? TokensList::build($stmt->body) : $viewData['as'];
            }
        }

        $viewData = array_merge($viewData, $view);

        $GLOBALS['urlParams']['db'] = $GLOBALS['db'];
        $GLOBALS['urlParams']['reload'] = 1;

        $this->addScriptFiles(['sql.js']);

        echo $this->template->render('view_create', [
            'ajax_dialog' => $ajaxdialog,
            'text_dir' => $GLOBALS['text_dir'],
            'url_params' => $GLOBALS['urlParams'],
            'view' => $viewData,
            'view_algorithm_options' => self::VIEW_ALGORITHM_OPTIONS,
            'view_with_options' => self::VIEW_WITH_OPTIONS,
            'view_security_options' => self::VIEW_SECURITY_OPTIONS,
        ]);
    }
}
