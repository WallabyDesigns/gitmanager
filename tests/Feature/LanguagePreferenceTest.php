<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LanguagePreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_save_language_preference(): void
    {
        $user = User::factory()->create(['locale' => 'en']);

        $this->actingAs($user)
            ->from('/projects')
            ->post('/language', ['locale' => 'fr'])
            ->assertRedirect('/projects')
            ->assertSessionHas('locale', 'fr');

        $this->assertSame('fr', $user->refresh()->locale);
    }

    public function test_guest_language_preference_is_saved_to_session(): void
    {
        User::factory()->create();

        $this->from('/login')
            ->post('/language', ['locale' => 'es'])
            ->assertRedirect('/login')
            ->assertSessionHas('locale', 'es');
    }

    public function test_user_locale_is_applied_to_page_language(): void
    {
        $user = User::factory()->create(['locale' => 'de']);

        $this->actingAs($user)
            ->get('/projects')
            ->assertOk()
            ->assertSee('<html lang="de"', false);
    }

    public function test_selected_language_translates_visible_navigation_text(): void
    {
        $user = User::factory()->create(['locale' => 'ja']);

        $this->actingAs($user)
            ->get('/projects')
            ->assertOk()
            ->assertSee('ダッシュボード')
            ->assertSee('プロジェクト')
            ->assertSee('言語')
            ->assertSee('プロジェクト一覧')
            ->assertSee('ワークスペース')
            ->assertSee('ヘルスチェック')
            ->assertSee('更新を確認')
            ->assertDontSee('Project List')
            ->assertDontSee('Workspace')
            ->assertDontSee('Check Health')
            ->assertDontSee('Create Project')
            ->assertDontSee('FTP/SSH Access');
    }
}
