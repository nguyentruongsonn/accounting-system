<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportRunStorageBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_immutable_actor_reference_uses_restrict_instead_of_set_null(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Foreign-key metadata assertion is implemented for the SQLite test runtime.');
        }

        $foreignKey = collect(DB::select("PRAGMA foreign_key_list('report_runs')"))
            ->first(fn (object $key): bool => $key->from === 'issued_by');

        $this->assertNotNull($foreignKey);
        $this->assertSame('RESTRICT', strtoupper($foreignKey->on_delete));
    }
}
