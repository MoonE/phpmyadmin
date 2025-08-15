<?php

declare(strict_types=1);

namespace PhpMyAdmin\Tests\Controllers\Server\Status\Processes;

use PhpMyAdmin\Config;
use PhpMyAdmin\Controllers\Server\Status\Processes\RefreshController;
use PhpMyAdmin\Current;
use PhpMyAdmin\Dbal\DatabaseInterface;
use PhpMyAdmin\Http\Factory\ServerRequestFactory;
use PhpMyAdmin\Server\Status\Data;
use PhpMyAdmin\Server\Status\Processes;
use PhpMyAdmin\Template;
use PhpMyAdmin\Tests\AbstractTestCase;
use PhpMyAdmin\Tests\Stubs\ResponseRenderer;
use PhpMyAdmin\Url;
use PHPUnit\Framework\Attributes\CoversClass;

use function __;
use function htmlspecialchars;

#[CoversClass(RefreshController::class)]
class RefreshControllerTest extends AbstractTestCase
{
    private Data $data;

    protected function setUp(): void
    {
        parent::setUp();

        DatabaseInterface::$instance = $this->createDatabaseInterface();

        $this->setGlobalConfig();

        Current::$database = 'db';
        Current::$table = 'table';
        $config = Config::getInstance();
        $config->selectedServer['DisableIS'] = false;
        $config->selectedServer['host'] = 'localhost';

        $this->data = new Data(DatabaseInterface::getInstance(), $config);
    }

    public function testRefresh(): void
    {
        $process = [
            'user' => 'User1',
            'host' => 'Host1',
            'id' => 9,
            'db' => 'db1',
            'command' => 'Command1',
            'info' => 'Info1',
            'state' => 2,
            'time' => 1,
        ];
        Config::getInstance()->settings['MaxCharactersInDisplayedSQL'] = 12;

        $response = new ResponseRenderer();

        $controller = new RefreshController(
            $response,
            new Template(),
            $this->data,
            new Processes(DatabaseInterface::getInstance()),
        );

        $request = ServerRequestFactory::create()->createServerRequest('POST', 'https://example.com/')
            ->withParsedBody([
                'ajax_request' => 'true',
                'column_name' => '',
                'order_by_field' => 'PROCESS',
                'sort_order' => 'DESC',
                'full' => '1',
            ]);

        $controller($request);
        $html = $response->getHTMLResult();

        self::assertStringContainsString('index.php?route=/server/status/processes', $html);
        $killProcess = 'data-post="' . Url::getCommon(['kill' => $process['id']], '') . '"';
        self::assertStringContainsString($killProcess, $html);
        self::assertStringContainsString('ajax kill_process', $html);
        self::assertStringContainsString(__('Kill'), $html);
        self::assertStringContainsString(htmlspecialchars($process['user']), $html);
        self::assertStringContainsString(htmlspecialchars($process['host']), $html);
        self::assertStringContainsString($process['db'], $html);
        self::assertStringContainsString(htmlspecialchars($process['command']), $html);
        self::assertStringContainsString((string) $process['time'], $html);
        self::assertStringContainsString((string) $process['state'], $html);
        self::assertStringContainsString($process['info'], $html);
    }
}
