<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class V20260822120000 extends AbstractMigration
{
    public function change(): void
    {
        $this->table('snapshot_store', ['id' => false, 'primary_key' => 'id'])
            ->addColumn('id', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('boundary_hash', 'char', ['limit' => 64, 'null' => false])
            ->addColumn('last_event_id', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('event_count', 'integer', ['null' => false])
            ->addColumn('payload', 'text', ['limit' => \Phinx\Db\Adapter\MysqlAdapter::TEXT_LONG, 'null' => false])
            ->addColumn('created_at', 'timestamp', ['null' => false, 'precision' => 6])
            ->addIndex(['boundary_hash', 'event_count'], ['unique' => true, 'name' => 'boundary_event_count'])
            ->create();
    }
}
