<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase; // Use RefreshDatabase to mimic the failing environment

class DebugSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app)
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
    }

    /** @test */
    public function check_database_schema()
    {
        dump('DB Driver: ' . Config('database.default'));
        dump('Actual Driver: ' . \DB::connection()->getDriverName());

        dump('Checking Users table columns...');
        $columns = Schema::getColumnListing('users');
        dump($columns);

        $hasWarehouseId = Schema::hasColumn('users', 'warehouse_id');
        $this->assertTrue($hasWarehouseId, 'Users table is missing warehouse_id column!');

        $hasWarehousesTable = Schema::hasTable('warehouses');
        dump('Has warehouses table: ' . ($hasWarehousesTable ? 'YES' : 'NO'));
    }
}
