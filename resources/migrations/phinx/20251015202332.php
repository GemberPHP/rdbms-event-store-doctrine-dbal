<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class V20251015202332 extends AbstractMigration
{
    public function change(): void
    {
        $this->table('saga_store_relation', ['id' => false, 'primary_key' => ['id', 'saga_id']])
            ->addColumn('id', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('saga_id', 'string', ['limit' => 50, 'null' => false])
            ->addForeignKey('id', 'saga_store', 'id')
            ->create();
    }
}
