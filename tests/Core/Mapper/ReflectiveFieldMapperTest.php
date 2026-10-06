<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Core\Mapper;

use ElevateDxp\Core\Mapper\ReflectiveFieldMapper;
use PHPUnit\Framework\TestCase;

final class ReflectiveFieldMapperTest extends TestCase
{
    public function testMapsGettersAndScalarizes(): void
    {
        $source = new class {
            public function getId(): int
            {
                return 7;
            }

            public function getName(): string
            {
                return 'Aurora';
            }

            public function getModificationDate(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-01-02T03:04:05+00:00');
            }

            public function getImage(): object
            {
                return new class {
                    public function getFullPath(): string
                    {
                        return '/products/x.png';
                    }
                };
            }

            public function getRelation(): object
            {
                return new \stdClass(); // no getFullPath / __toString → null
            }
        };

        $mapper = new ReflectiveFieldMapper();
        $out = $mapper->map($source, [
            'id' => 'id',
            'name' => 'name',
            'updatedAt' => 'modificationDate',
            'image' => 'image',
            'rel' => 'relation',
            'missing' => 'doesNotExist',
        ]);

        self::assertSame(7, $out['id']);
        self::assertSame('Aurora', $out['name']);
        self::assertSame('2026-01-02T03:04:05+00:00', $out['updatedAt']);
        self::assertSame('/products/x.png', $out['image']);
        self::assertNull($out['rel']);
        self::assertNull($out['missing']);
    }
}
