<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class V20260818120000 extends AbstractMigration
{
    public function change(): void
    {
        $this->table('event_store_lock', ['id' => false, 'primary_key' => 'boundary_hash'])
            ->addColumn('boundary_hash', 'char', ['limit' => 64, 'null' => false])
            ->create();
    }
}
