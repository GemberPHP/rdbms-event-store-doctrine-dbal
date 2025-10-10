<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class V20251002194212 extends AbstractMigration
{
    public function change(): void
    {
        $this->table('saga_store', ['id' => false, 'primary_key' => ['saga_id', 'saga_name']])
            ->addColumn('saga_id', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('saga_name', 'string', ['null' => false])
            ->addColumn('payload', 'json', ['null' => false])
            ->addColumn('created_at', 'timestamp', ['limit' => 6, 'null' => false])
            ->addColumn('updated_at', 'timestamp', ['limit' => 6, 'null' => true])
            ->create();
    }
}
