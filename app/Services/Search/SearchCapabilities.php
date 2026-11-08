<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * What this database can do for search, detected once and remembered. Nothing is assumed: SQLite, a PostgreSQL host
 * without pg_trgm, and a database where the migration could not add the tsvector column each get the best engine
 * that works, and none of it is an error.
 */
class SearchCapabilities
{
    private ?bool $fullText = null;

    private ?bool $trigram = null;

    public function driver(): string
    {
        return DB::connection()->getDriverName();
    }

    /** The PostgreSQL full-text engine can be used: a pgsql connection and the generated tsvector column. */
    public function fullText(): bool
    {
        if (config('discovery.search.engine') === 'portable') {
            return false;
        }

        return $this->fullText ??= $this->remember('fulltext', function () {
            if ($this->driver() !== 'pgsql' || ! Schema::hasTable('search_documents')) {
                return false;
            }

            return Schema::hasColumn('search_documents', 'search_vector');
        });
    }

    /** pg_trgm is installed in this database (typo tolerance by trigram similarity). */
    public function trigram(): bool
    {
        return $this->trigram ??= $this->remember('trigram', function () {
            if ($this->driver() !== 'pgsql') {
                return false;
            }

            return DB::selectOne("select 1 as present from pg_extension where extname = 'pg_trgm'") !== null;
        });
    }

    public function forget(): void
    {
        $this->fullText = $this->trigram = null;

        foreach (['fulltext', 'trigram'] as $key) {
            try {
                Cache::forget($this->key($key));
            } catch (Throwable) {
            }
        }
    }

    /** @return array<string, mixed> */
    public function report(): array
    {
        return [
            'driver' => $this->driver(),
            'engine' => $this->fullText() ? 'postgres' : 'portable',
            'full_text' => $this->fullText(),
            'trigram' => $this->trigram(),
        ];
    }

    private function remember(string $name, callable $detect): bool
    {
        $ttl = (int) config('discovery.search.capability_cache_seconds', 3600);

        try {
            return (bool) Cache::remember($this->key($name), $ttl, function () use ($detect) {
                try {
                    return (bool) $detect();
                } catch (Throwable) {
                    return false;
                }
            });
        } catch (Throwable) {
            return (bool) $detect();
        }
    }

    private function key(string $name): string
    {
        return 'discovery.capability.'.$this->driver().'.'.$name;
    }
}
