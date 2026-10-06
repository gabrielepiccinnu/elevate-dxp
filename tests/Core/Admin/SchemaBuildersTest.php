<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Core\Admin;

use ElevateDxp\Core\Admin\AbstractAdminResource;
use ElevateDxp\Core\Admin\Action;
use ElevateDxp\Core\Admin\Field;
use PHPUnit\Framework\TestCase;

final class SchemaBuildersTest extends TestCase
{
    public function testFieldDefaultsAndOptions(): void
    {
        $f = Field::select('status', 'Status', ['draft' => 'Draft', 'running' => 'Running'], ['required' => true]);
        self::assertSame('select', $f['type']);
        self::assertTrue($f['required']);
        self::assertTrue($f['grid']);
        self::assertSame([['draft', 'Draft'], ['running', 'Running']], $f['options']);

        self::assertFalse(Field::json('cfg', 'Config')['grid']);
        self::assertTrue(Field::isStructured('keyvalue'));
        self::assertFalse(Field::isStructured('text'));
        self::assertTrue(Field::id()['readOnly']);
    }

    public function testActionResults(): void
    {
        self::assertSame('record', Action::record('run', 'Run')['scope']);
        $table = Action::table([['a' => 1, 'b' => 2]], 'T');
        self::assertSame(['a', 'b'], $table['columns']);
        self::assertTrue(Action::message('ok', true)['reload']);
    }

    public function testPaginateFiltersSortsAndSlices(): void
    {
        $resource = new class extends AbstractAdminResource {
            public function getKey(): string
            {
                return 'k';
            }

            public function getLabel(): string
            {
                return 'L';
            }

            public function getPermission(): string
            {
                return 'p';
            }

            public function getSchema(): array
            {
                return [];
            }

            public function page(array $rows, array $q): array
            {
                return $this->paginate($rows, $q);
            }
        };
        $rows = [['name' => 'beta', 'n' => 2], ['name' => 'alpha', 'n' => 1], ['name' => 'gamma', 'n' => 3]];

        $res = $resource->page($rows, ['sort' => 'n', 'dir' => 'DESC', 'start' => 0, 'limit' => 2]);
        self::assertSame(3, $res['total']);
        self::assertSame(['gamma', 'beta'], array_column($res['data'], 'name'));

        $res = $resource->page($rows, ['q' => 'alp']);
        self::assertSame(1, $res['total']);
    }

    public function testReadOnlyDefaultsThrow(): void
    {
        $resource = new class extends AbstractAdminResource {
            public function getKey(): string
            {
                return 'k';
            }

            public function getLabel(): string
            {
                return 'L';
            }

            public function getPermission(): string
            {
                return 'p';
            }

            public function getSchema(): array
            {
                return [];
            }
        };
        $this->expectException(\InvalidArgumentException::class);
        $resource->save([]);
    }
}
