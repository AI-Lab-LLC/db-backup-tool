<?php

namespace Tests\Unit;

use App\Models\BackupConfig;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Boundary-case coverage for BackupConfig::dueForBackup().
 *
 * Pure logic — no DB needed, so we build unsaved model instances and freeze
 * "now" for the interval-window cases.
 */
class DueForBackupTest extends TestCase
{
    private function makeConfig(array $attrs): BackupConfig
    {
        $config = new BackupConfig();
        $config->forceFill(array_merge([
            'enabled' => true,
            'interval_minutes' => 60,
            'last_run_at' => null,
        ], $attrs));

        return $config;
    }

    public function test_interval_zero_is_never_due(): void
    {
        $config = $this->makeConfig(['interval_minutes' => 0, 'last_run_at' => now()->subYear()]);
        $this->assertFalse($config->dueForBackup());
    }

    public function test_disabled_is_never_due(): void
    {
        $config = $this->makeConfig(['enabled' => false, 'last_run_at' => null]);
        $this->assertFalse($config->dueForBackup());
    }

    public function test_never_run_is_due_immediately(): void
    {
        $config = $this->makeConfig(['last_run_at' => null]);
        $this->assertTrue($config->dueForBackup());
    }

    public function test_due_when_interval_has_elapsed(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        // last run 61 minutes ago, interval 60 -> due
        $config = $this->makeConfig([
            'interval_minutes' => 60,
            'last_run_at' => now()->subMinutes(61),
        ]);
        $this->assertTrue($config->dueForBackup());
        Carbon::setTestNow();
    }

    public function test_not_due_when_interval_has_not_elapsed(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        // last run 30 minutes ago, interval 60 -> not due
        $config = $this->makeConfig([
            'interval_minutes' => 60,
            'last_run_at' => now()->subMinutes(30),
        ]);
        $this->assertFalse($config->dueForBackup());
        Carbon::setTestNow();
    }

    public function test_due_exactly_at_boundary(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        // last run exactly interval ago -> lessThanOrEqualTo(now) is true -> due
        $config = $this->makeConfig([
            'interval_minutes' => 60,
            'last_run_at' => now()->subMinutes(60),
        ]);
        $this->assertTrue($config->dueForBackup());
        Carbon::setTestNow();
    }
}
