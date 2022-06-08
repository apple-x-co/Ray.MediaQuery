<?php

declare(strict_types=1);

namespace Ray\MediaQuery;

use Aura\Sql\ExtendedPdoInterface;
use PDO;
use PHPUnit\Framework\TestCase;
use Ray\AuraSqlModule\AuraSqlModule;
use Ray\AuraSqlModule\Pagerfanta\Page;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;
use Ray\MediaQuery\Entity\Todo;
use Ray\MediaQuery\Entity\TodoConstruct;
use Ray\MediaQuery\Exception\InvalidPerPageVarNameException;
use Ray\MediaQuery\Exception\PerPageNotTypeNotInt;
use Ray\MediaQuery\Queries\DynamicPerPageInterface;
use Ray\MediaQuery\Queries\DynamicPerPageInvalidInterface;
use Ray\MediaQuery\Queries\DynamicPerPageInvalidType;
use Ray\MediaQuery\Queries\PromiseAddInterface;
use Ray\MediaQuery\Queries\PromiseItemInterface;
use Ray\MediaQuery\Queries\PromiseListInterface;
use Ray\MediaQuery\Queries\TodoAddInterface;
use Ray\MediaQuery\Queries\TodoConstcuctEntityInterface;
use Ray\MediaQuery\Queries\TodoEntityInterface;
use Ray\MediaQuery\Queries\TodoItemInterface;
use Ray\MediaQuery\Queries\TodoListInterface;

use function array_keys;
use function assert;
use function dirname;
use function file_get_contents;

class DbQueryModuleTest extends TestCase
{
    /** @var AbstractModule */
    protected $module;

    /** @var MediaQueryLoggerInterface */
    private $logger;

    /** @var Injector */
    private $injector;

    protected function setUp(): void
    {
        $mediaQueries = Queries::fromClasses([
            TodoAddInterface::class,
            TodoItemInterface::class,
            TodoListInterface::class,
            PromiseAddInterface::class,
            PromiseItemInterface::class,
            PromiseListInterface::class,
            TodoEntityInterface::class,
            TodoConstcuctEntityInterface::class,
            DynamicPerPageInterface::class,
            DynamicPerPageInvalidInterface::class,
            DynamicPerPageInvalidType::class,
        ]);
        $sqlDir = dirname(__DIR__) . '/tests/sql';
        $dbQueryConfig = new DbQueryConfig($sqlDir);
        $module = new MediaQueryModule($mediaQueries, [$dbQueryConfig], new AuraSqlModule('sqlite::memory:', '', '', '', [PDO::ATTR_STRINGIFY_FETCHES => true]));
        $this->injector = new Injector($module, __DIR__ . '/tmp');
        $pdo = $this->injector->getInstance(ExtendedPdoInterface::class);
        assert($pdo instanceof ExtendedPdoInterface);
        $pdo->query((string) file_get_contents($sqlDir . '/create_todo.sql'));
        $pdo->query((string) file_get_contents($sqlDir . '/create_promise.sql'));
        $pdo->perform((string) file_get_contents($sqlDir . '/todo_add.sql'), ['id' => '1', 'title' => 'run']);
        $pdo->perform((string) file_get_contents($sqlDir . '/promise_add.sql'), ['id' => '1', 'title' => 'run', 'time' => UnixEpocTime::TEXT]);
        /** @var MediaQueryLoggerInterface $logger */
        $logger = $this->injector->getInstance(MediaQueryLoggerInterface::class);
        $this->logger = $logger;
    }

    public function testInsertItem(): void
    {
        $todoAdd = $this->injector->getInstance(TodoAddInterface::class);
        assert($todoAdd instanceof TodoAddInterface);
        $todoAdd('1', 'run');
        $log = (string) $this->logger;
        $this->assertStringContainsString('query: todo_add', $log);
        $todoItem = $this->injector->getInstance(TodoItemInterface::class);

        assert($todoItem instanceof TodoItemInterface);
        $item = $todoItem('1');
        $this->assertSame(['id' => '1', 'title' => 'run'], $item);
        $log = (string) $this->logger;
        $this->assertStringContainsString('query: todo_item', $log);
    }

    public function testSelectItem(): void
    {
        $todoItem = $this->injector->getInstance(TodoItemInterface::class);
        assert($todoItem instanceof TodoItemInterface);
        $item = $todoItem('1');
        $this->assertSame(['id' => '1', 'title' => 'run'], $item);
        $log = (string) $this->logger;
        $this->assertStringContainsString('query: todo_item', $log);
    }

    public function testSelectList(): void
    {
        $promiselist = $this->injector->getInstance(PromiseListInterface::class);
        assert($promiselist instanceof PromiseListInterface);
        $list = $promiselist->get();
        $row = ['id' => '1', 'title' => 'run', 'time' => '1970-01-01 00:00:00'];
        $this->assertSame([$row], $list);
        $log = (string) $this->logger;
        $this->assertStringContainsString('query: promise_list([])', $log);
    }

    public function testSelectPager(): void
    {
        $todoList = $this->injector->getInstance(TodoListInterface::class);
        assert($todoList instanceof TodoListInterface);
        $list = ($todoList)();
        /** @var Page $page */
        $page = $list[1];
        $this->assertSame([['id' => '1', 'title' => 'run']], $page->data);
        $log = (string) $this->logger;
        $this->assertStringContainsString('query: todo_list', $log);
    }

    public function testPramInjection(): void
    {
        /** @var FakeFoo $foo */
        $foo = $this->injector->getInstance(FakeFoo::class);
        $foo->add();
        $item = $foo->get();
        $this->assertSame(['id', 'title', 'time'], array_keys($item));
    }

    public function testEntity(): void
    {
        /** @var TodoEntityInterface $todoList */
        $todoList = $this->injector->getInstance(TodoEntityInterface::class);
        $list = $todoList->getlist();
        $this->assertInstanceOf(Todo::class, $list[0]);
        $this->assertSame('run', $list[0]->title);
        $item = $todoList->getItem('1');
        $this->assertInstanceOf(Todo::class, $item);
    }

    public function testEntityWithConstructor(): void
    {
        /** @var TodoEntityInterface $todoList */
        $todoList = $this->injector->getInstance(TodoConstcuctEntityInterface::class);
        $list = $todoList->getlist();
        $this->assertInstanceOf(TodoConstruct::class, $list[0]);
        $this->assertSame('run', $list[0]->title);
        $item = $todoList->getItem('1');
        $this->assertInstanceOf(TodoConstruct::class, $item);
    }

    public function testDynamicPerPage(): void
    {
        $todoList = $this->injector->getInstance(DynamicPerPageInterface::class);
        assert($todoList instanceof DynamicPerPageInterface);
        $list = ($todoList)(2);
        /** @var Page $page */
        $page = $list[1];
        $this->assertSame([['id' => '1', 'title' => 'run']], $page->data);
        $this->assertSame(2, $page->maxPerPage);
        $log = (string) $this->logger;
        $this->assertStringContainsString('query: todo_list', $log);
    }

    public function testDynamicPerPageVariableNameNotGiven(): void
    {
        $this->expectException(InvalidPerPageVarNameException::class);
        $todoList = $this->injector->getInstance(DynamicPerPageInvalidInterface::class);
        assert($todoList instanceof DynamicPerPageInvalidInterface);
        ($todoList)(1);
    }

    public function testGivenPerPageShouldBeInt(): void
    {
        $this->expectException(PerPageNotTypeNotInt::class);
        $todoList = $this->injector->getInstance(DynamicPerPageInvalidType::class);
        $todoList('1'); // @phpstan-ignore-line
    }
}
