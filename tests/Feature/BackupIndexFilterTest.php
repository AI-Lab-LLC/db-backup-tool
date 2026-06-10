<?php

namespace Tests\Feature;

use App\Models\Backup;
use App\Models\BackupConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackupIndexFilterTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        return User::factory()->create();
    }

    private function makeBackup(string $database, string $createdAt): Backup
    {
        $backup = Backup::create([
            'database_name' => $database,
            'filename' => "{$database}_dump.dump",
            's3_path' => "postgres-backups/{$database}/{$database}_dump.dump",
            'status' => 'success',
            'trigger' => 'manual',
        ]);

        $backup->created_at = $createdAt;
        $backup->save();

        return $backup;
    }

    public function test_database_filter_returns_only_that_database(): void
    {
        $this->makeBackup('alpha', '2026-06-01 10:00:00');
        $this->makeBackup('beta', '2026-06-01 10:00:00');

        $response = $this->actingAs($this->actingUser())
            ->get('/backups?database=alpha');

        $response->assertOk();
        $backups = $response->viewData('backups');

        $this->assertCount(1, $backups);
        $this->assertSame('alpha', $backups->first()->database_name);
    }

    public function test_no_filter_returns_all(): void
    {
        $this->makeBackup('alpha', '2026-06-01 10:00:00');
        $this->makeBackup('beta', '2026-06-01 10:00:00');

        $response = $this->actingAs($this->actingUser())->get('/backups');

        $response->assertOk();
        $this->assertCount(2, $response->viewData('backups'));
    }

    public function test_date_range_is_inclusive_by_day(): void
    {
        // Created at the very start and very end of the boundary days, plus one
        // outside the range on each side.
        $this->makeBackup('alpha', '2026-06-02 00:00:01'); // inside (date_from day)
        $this->makeBackup('alpha', '2026-06-04 23:59:59'); // inside (date_to day, end)
        $this->makeBackup('alpha', '2026-06-01 23:59:59'); // before range
        $this->makeBackup('alpha', '2026-06-05 00:00:01'); // after range

        $response = $this->actingAs($this->actingUser())
            ->get('/backups?date_from=2026-06-02&date_to=2026-06-04');

        $response->assertOk();
        $this->assertCount(2, $response->viewData('backups'));
    }

    public function test_pagination_preserves_query_string(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->makeBackup('alpha', '2026-06-01 10:00:00');
        }

        $response = $this->actingAs($this->actingUser())
            ->get('/backups?database=alpha');

        $response->assertOk();
        $backups = $response->viewData('backups');

        // withQueryString() keeps the database filter on the page-2 link.
        $this->assertStringContainsString('database=alpha', $backups->nextPageUrl());
    }

    public function test_database_options_merge_history_and_configs_sorted_unique(): void
    {
        $this->makeBackup('zeta', '2026-06-01 10:00:00');
        $this->makeBackup('alpha', '2026-06-01 10:00:00');

        BackupConfig::create([
            'database_name' => 'alpha', // dup with history -> deduped
            'enabled' => true,
            'interval_minutes' => 0,
            'retention_days' => 7,
        ]);
        BackupConfig::create([
            'database_name' => 'mango', // config-only db
            'enabled' => true,
            'interval_minutes' => 0,
            'retention_days' => 7,
        ]);

        $response = $this->actingAs($this->actingUser())->get('/backups');

        $response->assertOk();

        $this->assertSame(
            ['alpha', 'mango', 'zeta'],
            $response->viewData('databaseOptions'),
        );
    }

    public function test_invalid_date_to_before_date_from_is_rejected(): void
    {
        $response = $this->actingAs($this->actingUser())
            ->get('/backups?date_from=2026-06-04&date_to=2026-06-01');

        $response->assertSessionHasErrors('date_to');
    }
}
