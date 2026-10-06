<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Core\Admin;

use Doctrine\DBAL\Connection;
use ElevateDxp\Core\Admin\AbstractDbalResource;
use ElevateDxp\Core\Admin\Field;
use PHPUnit\Framework\TestCase;

final class AbstractDbalResourceTest extends TestCase
{
    public function testSaveWithoutIdInsertsEncodedRow(): void
    {
        $db = $this->connection();
        $db->expects(self::never())->method('update');
        $db->expects(self::once())->method('insert')->with('`items`', ['`name`' => 'Alpha', '`tags`' => '["a","b"]', '`enabled`' => 1]);
        $db->method('lastInsertId')->willReturn('5');
        $db->method('fetchAssociative')->willReturn(['id' => 5, 'name' => 'Alpha', 'tags' => '["a","b"]', 'enabled' => 1]);

        $saved = $this->resource($db)->save(['id' => '', 'name' => 'Alpha', 'tags' => ['a', 'b'], 'enabled' => 'on']);

        self::assertSame(['id' => 5, 'name' => 'Alpha', 'tags' => ['a', 'b'], 'enabled' => true], $saved);
    }

    public function testSaveWithNullIdInserts(): void
    {
        $db = $this->connection();
        $db->expects(self::once())->method('insert');
        $db->expects(self::never())->method('update');
        $db->method('lastInsertId')->willReturn('1');
        $db->method('fetchAssociative')->willReturn(['id' => 1, 'name' => 'Beta']);

        $this->resource($db)->save(['id' => null, 'name' => 'Beta']);
    }

    public function testSaveWithExistingIdUpdates(): void
    {
        $db = $this->connection();
        $db->expects(self::never())->method('insert');
        $db->expects(self::once())->method('update')->with('`items`', ['`name`' => 'Gamma'], ['`id`' => '7']);
        $db->method('fetchAssociative')->willReturn(['id' => 7, 'name' => 'Gamma']);

        $this->resource($db)->save(['id' => 7, 'name' => 'Gamma']);
    }

    public function testSaveWithUnknownIdThrows(): void
    {
        $db = $this->connection();
        $db->expects(self::never())->method('insert');
        $db->method('fetchAssociative')->willReturn(false);

        $this->expectException(\InvalidArgumentException::class);
        $this->resource($db)->save(['id' => 99, 'name' => 'Missing']);
    }

    public function testRequiredFieldIsEnforced(): void
    {
        $db = $this->connection();
        $db->expects(self::never())->method('insert');

        $this->expectExceptionMessage('Field "Name" is required.');
        $this->resource($db)->save(['name' => '']);
    }

    /** @return Connection&\PHPUnit\Framework\MockObject\MockObject */
    private function connection(): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('quoteIdentifier')->willReturnCallback(static fn (string $identifier): string => '`'.$identifier.'`');

        return $db;
    }

    private function resource(Connection $db): AbstractDbalResource
    {
        return new class($db) extends AbstractDbalResource {
            public function getKey(): string
            {
                return 'items';
            }

            public function getLabel(): string
            {
                return 'Items';
            }

            public function getPermission(): string
            {
                return 'items';
            }

            /** @return array<string, mixed> */
            public function getSchema(): array
            {
                return [
                    'fields' => [
                        Field::id(),
                        Field::text('name', 'Name', ['required' => true]),
                        Field::tags('tags', 'Tags'),
                        Field::bool('enabled', 'Enabled'),
                    ],
                ];
            }

            protected function getTable(): string
            {
                return 'items';
            }
        };
    }
}
