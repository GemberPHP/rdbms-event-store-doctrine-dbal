<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class V20241210193448 extends AbstractMigration
{
    public function change(): void
    {
        $this->table('event_store', ['id' => false, 'primary_key' => 'id'])
            ->addColumn('id', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('event_name', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('payload', 'json', ['null' => false])
            ->addColumn('metadata', 'json', ['null' => false])
            ->addColumn('applied_at', 'timestamp', ['limit' => 6, 'null' => false])
            ->addIndex('event_name')
            ->create();
    }
}
