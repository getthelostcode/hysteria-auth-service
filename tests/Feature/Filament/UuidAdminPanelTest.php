<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class UuidAdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): AdminUser
    {
        return AdminUser::query()->create([
            'name' => 'Admin', 'email' => 'panel@test', 'password' => 'admin-passw0rd',
            'role' => AdminUser::ROLE_SUPER_ADMIN, 'enabled' => true,
        ]);
    }

    private function user(): User
    {
        return User::query()->create([
            'username' => 'alice', 'password_hash' => Hash::make('secret-pass'), 'enabled' => true,
        ]);
    }

    public function test_user_list_shows_the_uuid(): void
    {
        $user = $this->user();

        $this->actingAs($this->admin(), 'admin')
            ->get('/admin/users')
            ->assertOk()
            ->assertSee((string) $user->uuid);
    }

    public function test_reset_uuid_action_changes_the_credential(): void
    {
        $user = $this->user();
        $old = (string) $user->uuid;

        Livewire::actingAs($this->admin(), 'admin')
            ->test(\App\Filament\Resources\UserResource\Pages\ListUsers::class)
            ->callTableAction('resetUuid', $user)
            ->assertHasNoTableActionErrors();

        $new = (string) $user->refresh()->uuid;
        $this->assertNotSame($old, $new);

        $this->postJson('/api/auth', ['addr' => '203.0.113.9:1', 'auth' => $old, 'tx' => 0])
            ->assertJson(['ok' => false]);
        $this->postJson('/api/auth', ['addr' => '203.0.113.9:2', 'auth' => $new, 'tx' => 0])
            ->assertJson(['ok' => true, 'id' => 'alice']);
    }

    public function test_viewer_cannot_see_the_reset_action(): void
    {
        $this->user();
        $viewer = AdminUser::query()->create([
            'name' => 'V', 'email' => 'v@test', 'password' => 'admin-passw0rd',
            'role' => AdminUser::ROLE_VIEWER, 'enabled' => true,
        ]);

        Livewire::actingAs($viewer, 'admin')
            ->test(\App\Filament\Resources\UserResource\Pages\ListUsers::class)
            ->assertTableActionHidden('resetUuid');
    }
}
