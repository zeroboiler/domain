<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;
use ZeroBoiler\Domain\Snapshots\SnapshotStore;

/**
 * Inspect snapshot store status.
 *
 * Usage:
 *   php artisan domain:snapshot --class=App\\Models\\Order
 *   php artisan domain:snapshot --class=App\\Models\\Order --id=order-123
 */
final class SnapshotCommand extends Command
{
    #[\Override]
    protected $signature = 'domain:snapshot
                            {--class= : Aggregate class FQCN to inspect}
                            {--id= : Aggregate ID to inspect}';

    #[\Override]
    protected $description = 'Inspect domain aggregate snapshot store';

    public function handle(): int
    {
        $class = $this->option('class');
        $id = $this->option('id');

        /** @var SnapshotStore|null $store */
        $store = App::make(SnapshotStore::class);

        if ($store === null) {
            $this->error('No SnapshotStore is registered. Enable snapshot support in your domain config.');

            return self::FAILURE;
        }

        if ($class === null) {
            $this->info('Snapshot store: ' . $store::class);

            if ($store instanceof InMemorySnapshotStore) {
                $this->info('Stored snapshots: ' . $store->count());
            }

            return self::SUCCESS;
        }

        if ($id !== null) {
            $snapshot = $store->load($class, $id);

            if ($snapshot === null) {
                $this->warn(sprintf('No snapshot found for %s #%s', $class, $id));

                return self::SUCCESS;
            }

            $this->info(sprintf('Snapshot for %s #%s:', $class, $id));
            $this->line('  Version: ' . $snapshot->version);
            $this->line('  Created: ' . $snapshot->createdAt->format('Y-m-d H:i:s'));
            $this->line('  State:');
            foreach ($snapshot->state as $key => $value) {
                if (is_scalar($value)) {
                    $display = (string) $value;
                } elseif (is_array($value) || is_object($value)) {
                    $display = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                    if ($display === false) {
                        $display = '<non-serializable>';
                    }
                } elseif (is_null($value)) {
                    $display = 'null';
                } else {
                    $display = '<' . gettype($value) . '>';
                }

                $this->line(sprintf('    %s: %s', $key, $display));
            }

            return self::SUCCESS;
        }

        return self::SUCCESS;
    }
}
