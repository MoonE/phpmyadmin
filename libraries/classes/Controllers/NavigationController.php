<?php

declare(strict_types=1);

namespace PhpMyAdmin\Controllers;

use PhpMyAdmin\Config\PageSettings;
use PhpMyAdmin\ConfigStorage\Relation;
use PhpMyAdmin\Http\ServerRequest;
use PhpMyAdmin\Message;
use PhpMyAdmin\Navigation\Navigation;
use PhpMyAdmin\ResponseRenderer;
use PhpMyAdmin\Template;
use PhpMyAdmin\Utils\SessionCache;

use function __;

/**
 * The navigation panel
 *
 * Displays server, database and table selection tree.
 */
class NavigationController extends AbstractController
{
    private Navigation $navigation;

    private Relation $relation;

    public function __construct(
        ResponseRenderer $response,
        Template $template,
        Navigation $navigation,
        Relation $relation
    ) {
        parent::__construct($response, $template);
        $this->navigation = $navigation;
        $this->relation = $relation;
    }

    public function __invoke(ServerRequest $request): void
    {
        if (! $this->response->isAjax()) {
            $this->response->addHTML(
                Message::error(
                    __('Fatal error: The navigation can only be accessed via AJAX')
                )->getDisplay()
            );

            return;
        }

        $getNaviSettings = $request->getParsedBodyParam('getNaviSettings');
        if ($getNaviSettings !== null && $getNaviSettings) {
            $pageSettings = new PageSettings('Navi', 'pma_navigation_settings');
            $this->response->addHTML($pageSettings->getErrorHTML());
            $this->response->addJSON('message', $pageSettings->getHTML());

            return;
        }

        if ($request->hasBodyParam('reload')) {
            SessionCache::set('dbs_to_test', false);// Empty database list cache, see #14252
        }

        $relationParameters = $this->relation->getRelationParameters();
        if ($relationParameters->navigationItemsHidingFeature !== null) {
            $itemName = $request->getParsedBodyParam('itemName', '');
            $itemType = $request->getParsedBodyParam('itemType', '');
            $dbName = $request->getParsedBodyParam('dbName', '');
            $tableName = $request->getParsedBodyParam('tableName', '');

            if ($request->getParsedBodyParam('hideNavItem') !== null) {
                if (! empty($itemName) && ! empty($itemType) && ! empty($dbName)) {
                    $this->navigation->hideNavigationItem(
                        $itemName,
                        $itemType,
                        $dbName,
                        (! empty($tableName) ? $tableName : null)
                    );
                }

                return;
            }

            if ($request->hasBodyParam('unhideNavItem')) {
                if (! empty($itemName) && ! empty($itemType) && ! empty($dbName)) {
                    $this->navigation->unhideNavigationItem(
                        $itemName,
                        $itemType,
                        $dbName,
                        (! empty($tableName) ? $tableName : null)
                    );
                }

                return;
            }

            if ($request->hasBodyParam('showUnhideDialog')) {
                if (! empty($dbName)) {
                    $this->response->addJSON(
                        'message',
                        $this->navigation->getItemUnhideDialog($dbName)
                    );
                }

                return;
            }
        }

        $this->response->addJSON('message', $this->navigation->getDisplay());
    }
}
