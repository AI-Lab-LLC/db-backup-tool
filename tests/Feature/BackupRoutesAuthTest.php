<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackupRoutesAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login_from_backups_index(): void
    {
        $this->get('/backups')->assertRedirect('/login');
    }

    public function test_guest_cannot_post_a_manual_backup(): void
    {
        $this->post('/backups/run', ['database' => 'breeze'])->assertRedirect('/login');
    }

    public function test_guest_cannot_post_a_restore(): void
    {
        $this->post('/backups/1/restore', [
            'confirm' => '1',
            'target_database' => 'breeze',
        ])->assertRedirect('/login');
    }
}
