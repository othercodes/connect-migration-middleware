<?php

namespace Test\Unit;

use Closure;
use Connect\Middleware\Migration;
use Connect\Request;
use Connect\Skip;
use Mockery;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * Class MigrationTest
 * @package Test
 */
class MigrationTest extends TestCase
{
    /**
     * @return Migration
     */
    public function testDefaultInstantiation()
    {
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info');
        $logger->shouldReceive('error');
        $logger->shouldReceive('debug');

        $m = new Migration([
            'logger' => $logger
        ]);

        $this->assertInstanceOf(Migration::class, $m);
        $this->assertInstanceOf(LoggerInterface::class, $m->getLogger());
        $this->assertInternalType('string', $m->getMigrationFlag());
        $this->assertEquals('migration_info', $m->getMigrationFlag());
        $this->assertInternalType('array', $m->getTransformations());
        $this->assertCount(0, $m->getTransformations());
        $this->assertNull($m->getTransformation('fake-param'));

        return $m;
    }

    /**
     * @return Migration
     */
    public function testCustomInstantiation()
    {
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info');
        $logger->shouldReceive('error');
        $logger->shouldReceive('debug');

        $m = new Migration([
            'logger' => $logger,
            'migrationFlag' => 'some_migration_param',
            'transformations' => [
                'email' => function ($migrationData, LoggerInterface $logger) {
                    return strtolower($migrationData->teamAdminEmail);
                }
            ]
        ]);

        $m->setTransformation('name', function ($migrationData, LoggerInterface $logger) {
            return ucfirst($migrationData->name);
        });

        $this->assertInstanceOf(Migration::class, $m);
        $this->assertInstanceOf(LoggerInterface::class, $m->getLogger());
        $this->assertInternalType('string', $m->getMigrationFlag());
        $this->assertEquals('some_migration_param', $m->getMigrationFlag());
        $this->assertInternalType('array', $m->getTransformations());
        $this->assertCount(2, $m->getTransformations());
        $this->assertInstanceOf(Closure::class, $m->getTransformation('email'));
        $this->assertInstanceOf(Closure::class, $m->getTransformation('name'));

        return $m;
    }

    /**
     * @depends testDefaultInstantiation
     *
     * @param Migration $m
     */
    public function testIsMigration(Migration $m)
    {
        $request = new Request($this->getJSON(__DIR__ . '/request.migrate.valid.json'));
        $this->assertTrue($m->isMigration($request));

        $request = new Request($this->getJSON(__DIR__ . '/request.valid.json'));
        $this->assertFalse($m->isMigration($request));
    }

    /**
     * @depends testDefaultInstantiation
     *
     * @param Migration $m
     * @throws Skip
     */
    public function testMigrateOnNonMigrableRequest(Migration $m)
    {
        $request = new Request($this->getJSON(__DIR__ . '/request.valid.json'));
        $migrated = $m->migrate($request);
        $this->assertInstanceOf(Request::class, $migrated);
        $this->assertEquals(spl_object_hash($request), spl_object_hash($migrated));
    }

    /**
     * @depends testDefaultInstantiation
     * @expectedException \Connect\Skip
     * @expectedExceptionMessage Error fail parsing migration parameter.
     *
     * @param Migration $m
     * @throws \Connect\Skip
     */
    public function testMigrateWithInvalidMigrationData(Migration $m)
    {
        $request = new Request($this->getJSON(__DIR__ . '/request.migrate.invalid.json'));
        $migrated = $m->migrate($request);
        $this->assertInstanceOf(Request::class, $migrated);
        $this->assertEquals(spl_object_hash($request), spl_object_hash($migrated));
    }

    /**
     * @depends testDefaultInstantiation
     * @expectedException \Connect\Skip
     * @expectedExceptionMessage Error fail parsing migration parameter.
     *
     * @param Migration $m
     * @throws Skip
     */
    public function testMigrateDirectMapWithParameterNotSerializedFail(Migration $m)
    {
        $request = new Request($this->getJSON(__DIR__ . '/request.migrate.direct.notserialized.json'));
        $migrated = $m->migrate($request);
        $this->assertInstanceOf(Request::class, $migrated);
        $this->assertEquals(spl_object_hash($request), spl_object_hash($migrated));
    }

    /**
     * @depends testDefaultInstantiation
     *
     * @param Migration $m
     * @throws Skip
     */
    public function testMigrateDirectMapWithParameterNotSerializedSuccess(Migration $m)
    {
        $this->assertFalse($m->getSerialize());
        $m->setSerialize(true);

        $this->assertTrue($m->getSerialize());

        $request = new Request($this->getJSON(__DIR__ . '/request.migrate.direct.notserialized.json'));
        $migrated = $m->migrate($request);
        $this->assertInstanceOf(Request::class, $migrated);
        $this->assertNotEquals(spl_object_hash($request), spl_object_hash($migrated));

        $this->assertEquals(
            '["Some name"]',
            $migrated->asset->getParameterByID('team_name')->value
        );

        $migratedTeamName = json_decode($migrated->asset->getParameterByID('team_name')->value);

        $this->assertInternalType('array', $migratedTeamName);
        $this->assertCount(1, $migratedTeamName);
        $this->assertEquals('Some name', $migratedTeamName[0]);


        $m->setSerialize(false);
        $this->assertFalse($m->getSerialize());
    }

    /**
     * @depends testDefaultInstantiation
     * @expectedException \Connect\Skip
     * @expectedExceptionMessage Error fail parsing migration parameter.
     *
     * @param Migration $m
     * @throws Skip
     */
    public function testMigrateTransformationMapManualFail(Migration $m)
    {
        $m->setTransformation('team_id', function ($migrationData, LoggerInterface $logger) {
            throw new Migration\Exceptions\MigrationParameterFailException('Manual fail');
        });

        $this->assertInstanceOf(Closure::class, $m->getTransformation('team_id'));

        $request = new Request($this->getJSON(__DIR__ . '/request.migrate.transformation.manualfail.json'));
        $m->migrate($request);
    }

    /**
     * @depends testDefaultInstantiation
     *
     * @param Migration $m
     * @throws Skip
     */
    public function testMigrateDirectMapSuccess(Migration $m)
    {
        $this->assertTrue($m->unsetTransformation('team_id'));
        $this->assertNull($m->getTransformation('team_id'));

        $request = new Request($this->getJSON(__DIR__ . '/request.migrate.direct.success.json'));
        $migrated = $m->migrate($request);
        $this->assertInstanceOf(Request::class, $migrated);
        $this->assertNotEquals(spl_object_hash($request), spl_object_hash($migrated));

        $this->assertEquals(
            'example.migration@mailinator.com',
            $migrated->asset->getParameterByID('email')->value
        );

        $this->assertEquals(
            'dbtid:AADaQq_w53nMDQbIPM_X123456PuzpcM2BI',
            $migrated->asset->getParameterByID('team_id')->value
        );

        $this->assertEquals(
            'Migration Team',
            $migrated->asset->getParameterByID('team_name')->value
        );

        $this->assertEquals(
            '10',
            $migrated->asset->getParameterByID('num_licensed_users')->value
        );
    }

    /**
     * @depends testDefaultInstantiation
     *
     * @param Migration $m
     * @throws Skip
     */
    public function testMigrateTransformationMapSuccess(Migration $m)
    {
        $m->setTransformations([
            'email' => function ($migrationData, LoggerInterface $logger) {
                return strtoupper($migrationData->teamAdminEmail);
            },
            'team_id' => function ($migrationData, LoggerInterface $logger) {
                return strtoupper($migrationData->teamId);
            },
            'team_name' => function ($migrationData, LoggerInterface $logger) {
                return strtoupper($migrationData->teamName);
            },
            'num_licensed_users' => function ($migrationData, LoggerInterface $logger) {
                return (int)$migrationData->licNumber * 10;
            }
        ]);

        $request = new Request($this->getJSON(__DIR__ . '/request.migrate.transformation.success.json'));
        $migrated = $m->migrate($request);
        $this->assertInstanceOf(Request::class, $migrated);
        $this->assertNotEquals(spl_object_hash($request), spl_object_hash($migrated));

        $this->assertEquals(
            strtoupper('example.migration@mailinator.com'),
            $migrated->asset->getParameterByID('email')->value
        );

        $this->assertEquals(
            strtoupper('dbtid:AADaQq_w53nMDQbIPM_X123456PuzpcM2BI'),
            $migrated->asset->getParameterByID('team_id')->value
        );

        $this->assertEquals(
            strtoupper('Migration Team'),
            $migrated->asset->getParameterByID('team_name')->value
        );

        $this->assertEquals(
            10 * 10,
            $migrated->asset->getParameterByID('num_licensed_users')->value
        );
    }
}