<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class V20260820120000 extends AbstractMigration
{
    public function change(): void
    {
        $this->table('outbox', ['id' => false, 'primary_key' => 'id'])
            ->addColumn('id', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('message_type', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('message_name', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('payload', 'json', ['null' => false])
            ->addColumn('created_at', 'timestamp', ['null' => false, 'precision' => 6])
            ->addColumn('retry_count', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('processed_at', 'timestamp', ['null' => true, 'default' => null, 'precision' => 6])
            ->addColumn('dead_lettered_at', 'timestamp', ['null' => true, 'default' => null, 'precision' => 6])
            ->addIndex(['processed_at', 'dead_lettered_at', 'created_at', 'id'], ['name' => 'outbox_unprocessed'])
            ->create();
    }
}
