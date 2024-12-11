<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class V20241210194054 extends AbstractMigration
{
    public function change(): void
    {
        $this->table('event_store_relation', ['id' => false, 'primary_key' => ['event_id', 'domain_id']])
            ->addColumn('event_id', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('domain_id', 'string', ['limit' => 50, 'null' => false])
            ->addForeignKey('event_id', 'event_store', 'id')
            ->create();
    }
}
