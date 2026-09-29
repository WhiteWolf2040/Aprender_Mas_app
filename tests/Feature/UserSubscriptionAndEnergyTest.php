<?php

namespace Tests\Feature;

use App\Models\ChildProfile;
use App\Models\Activity;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserSubscriptionAndEnergyTest extends TestCase
{
    use RefreshDatabase;

    private function parentAccount(string $name = 'Parent', string $email = 'parent@example.com'): User
    {
        return User::create([
            'name' => $name,
            'email' => $email,
            'password' => 'secret123',
            'role' => 'padre',
            'plan_key' => 'max',
            'subscription_status' => 'active',
            'subscription_ends_at' => now()->addMonth(),
        ]);
    }

    private function childProfile(User $parent, string $name = 'Santi', array $attributes = []): ChildProfile
    {
        return ChildProfile::create([
            'parent_id' => $parent->id,
            'name' => $name,
            'avatar' => '🦊',
            'energy' => 3,
            ...$attributes,
        ]);
    }

    public function test_parent_is_the_only_account_that_can_register_and_login(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Familia Leo',
            'email' => 'leo@example.com',
            'password' => 'secret123',
            'account_type' => 'parent',
        ])->assertCreated()->assertJsonPath('user.role', 'padre');

        $this->postJson('/api/auth/register', [
            'name' => 'Leo',
            'email' => 'child@example.com',
            'password' => 'secret123',
            'account_type' => 'student',
        ])->assertUnprocessable();

        $this->postJson('/api/auth/login', [
            'name' => 'leo@example.com',
            'password' => 'secret123',
        ])->assertOk()->assertJsonPath('user.role', 'padre');
    }

    public function test_child_profile_has_no_credentials_and_inherits_parent_subscription(): void
    {
        $parent = $this->parentAccount();
        $response = $this->actingAs($parent)->postJson('/api/parent/profiles', [
            'name' => 'Santi',
            'age' => 8,
            'avatar' => '🦁',
        ]);

        $response->assertCreated()
            ->assertJsonPath('child.parent_id', $parent->id)
            ->assertJsonPath('child.age', 8)
            ->assertJsonPath('child.has_premium', true);
        $this->assertDatabaseHas('child_profiles', ['name' => 'Santi', 'parent_id' => $parent->id]);
        $this->assertDatabaseMissing('users', ['name' => 'Santi', 'email' => null]);

        $this->postJson('/api/auth/login', ['name' => 'Santi', 'password' => '1234'])
            ->assertUnprocessable();

        $child = ChildProfile::where('name', 'Santi')->firstOrFail();
        $parent->forceFill(['subscription_status' => 'canceled'])->save();
        $this->assertFalse($child->fresh()->hasActivePlan());
        $this->withHeader('X-Child-Profile-ID', (string) $child->id)->getJson('/api/profile')
            ->assertOk()->assertJsonPath('user.has_premium', false);
    }

    public function test_parent_profile_limit_is_four(): void
    {
        $parent = $this->parentAccount();
        foreach (['Ana', 'Beto', 'Caro', 'Dani'] as $name) {
            $this->childProfile($parent, $name);
        }

        $this->actingAs($parent)->postJson('/api/parent/profiles', [
            'name' => 'Eva',
            'avatar' => '🐼',
        ])->assertUnprocessable();
    }

    public function test_activity_completion_consumes_child_energy_using_parent_token_and_profile_context(): void
    {
        $parent = $this->parentAccount();
        $child = $this->childProfile($parent, 'Matias', ['energy' => 2]);

        $this->actingAs($parent)->withHeader('X-Child-Profile-ID', (string) $child->id)
            ->postJson('/api/progress/activity', [
                'subject' => 'math',
                'score' => 5,
                'total' => 5,
                'earned_stars' => 10,
            ])->assertOk()
            ->assertJsonPath('unlimited_energy', true)
            ->assertJsonPath('user.level', 3);

        $this->assertSame(2, $child->fresh()->energy);
        $this->assertDatabaseHas('progresses', ['child_profile_id' => $child->id]);
    }

    public function test_every_five_wrong_answers_reduce_activity_stars_by_one(): void
    {
        $parent = $this->parentAccount();
        $child = $this->childProfile($parent);

        $this->actingAs($parent)->withHeader('X-Child-Profile-ID', (string) $child->id)
            ->postJson('/api/progress/activity', [
                'subject' => 'math',
                'score' => 1,
                'total' => 1,
                'mistakes' => 10,
            ])->assertOk()
            ->assertJsonPath('base_stars', 16)
            ->assertJsonPath('stars_deducted', 2)
            ->assertJsonPath('stars_awarded', 14)
            ->assertJsonPath('mistakes', 10)
            ->assertJsonPath('user.total_stars', 14);

            $this->actingAs($parent)->withHeader('X-Child-Profile-ID', (string) $child->id)
                ->postJson('/api/progress/activity', [
                    'subject' => 'math',
                    'score' => 1,
                    'total' => 2,
                    'mistakes' => 4,
                ])->assertOk()
                ->assertJsonPath('stars_awarded', 6)
                ->assertJsonPath('user.total_stars', 20);
    }

    public function test_each_activity_with_a_correct_answer_adds_two_levels_and_robot_unlocks_at_level_eight(): void
    {
        $parent = $this->parentAccount();
        $child = $this->childProfile($parent);

        foreach ([1, 2, 3, 4] as $attempt) {
            $response = $this->actingAs($parent)->withHeader('X-Child-Profile-ID', (string) $child->id)
                ->postJson('/api/progress/activity', [
                    'subject' => 'math',
                    'score' => 1,
                    'total' => 1,
                    'earned_stars' => 1,
                ])->assertOk();
            $response->assertJsonPath('user.level', 1 + 2 * $attempt);
        }

        $this->actingAs($parent)->withHeader('X-Child-Profile-ID', (string) $child->id)
            ->postJson('/api/profile/customize', ['type' => 'avatar', 'item' => 'robot'])
            ->assertOk()
            ->assertJsonPath('user.avatar', '🤖');
    }

    public function test_parent_can_create_wordsearch_with_ten_words_of_up_to_twelve_characters(): void
    {
        $parent = $this->parentAccount();
        $child = $this->childProfile($parent);
        $subject = Subject::where('name', 'Ciencias')->firstOrFail();

        $this->actingAs($parent)->postJson('/api/activities', [
            'subject_id' => $subject->id,
            'child_id' => $child->id,
            'title' => 'Sopa de ciencias',
            'type' => 'wordsearch',
            'prompt' => 'Encuentra las palabras.',
            'content' => ['words' => [
                'PLANETA',
                'ESTRELLA',
                'GALAXIA',
                'SATELITE',
                'UNIVERSO',
                'GRAVEDAD',
                'ORBITA',
                'METEORO',
            ]],
        ])->assertCreated()->assertJsonPath('content.words.4', 'UNIVERSO');
    }

    public function test_child_profile_creation_only_accepts_free_starter_characters(): void
    {
        $parent = $this->parentAccount();
        $this->actingAs($parent)->postJson('/api/parent/profiles', [
            'name' => 'Robo',
            'avatar' => '🤖',
        ])->assertUnprocessable();

        $this->actingAs($parent)->postJson('/api/parent/profiles', [
            'name' => 'Panda',
            'avatar' => '🐼',
        ])->assertCreated();
    }

    public function test_child_profile_without_active_parent_plan_has_three_energy_and_exhaustion_is_enforced(): void
    {
        $parent = User::create([
            'name' => 'Free Parent',
            'email' => 'free@example.com',
            'password' => 'secret123',
            'role' => 'padre',
            'plan_key' => 'free',
        ]);
        $child = $this->childProfile($parent, 'Sofia', ['energy' => 0, 'energy_reset_at' => now()->addDay()]);

        $this->actingAs($parent)->withHeader('X-Child-Profile-ID', (string) $child->id)
            ->postJson('/api/progress/activity', [
                'subject' => 'math',
                'score' => 5,
                'total' => 5,
                'earned_stars' => 10,
            ])->assertUnprocessable()->assertJsonPath('code', 'ENERGY_EXHAUSTED');
    }

    public function test_profile_context_rejects_child_owned_by_another_parent(): void
    {
        $parent = $this->parentAccount();
        $otherParent = $this->parentAccount('Other', 'other@example.com');
        $child = $this->childProfile($otherParent);

        $this->actingAs($parent)->withHeader('X-Child-Profile-ID', (string) $child->id)
            ->postJson('/api/profile/customize', ['type' => 'avatar', 'item' => 'lion'])
            ->assertNotFound();
    }

    public function test_rankings_are_available_without_authentication(): void
    {
        $child = $this->childProfile($this->parentAccount(), 'Luna', ['total_stars' => 45]);
        $otherChild = $this->childProfile(
            $this->parentAccount('Another Parent', 'global-ranking@example.com'),
            'Mateo',
            ['total_stars' => 25],
        );
        $this->getJson('/api/rankings')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.id', $child->id)
            ->assertJsonPath('0.name', 'Luna')
            ->assertJsonPath('0.total_stars', 45)
            ->assertJsonPath('1.id', $otherChild->id)
            ->assertJsonPath('1.name', 'Mateo');
    }

    public function test_global_ranking_includes_profiles_past_the_first_hundred(): void
    {
        $parent = $this->parentAccount();
        for ($index = 1; $index <= 101; $index++) {
            $this->childProfile($parent, "Child {$index}", ['total_stars' => 101 - $index]);
        }

        $this->getJson('/api/rankings')
            ->assertOk()
            ->assertJsonCount(101)
            ->assertJsonPath('100.name', 'Child 101');
    }

    public function test_family_ranking_only_lists_children_from_the_authenticated_parent(): void
    {
        $parent = $this->parentAccount();
        $otherParent = $this->parentAccount('Other Parent', 'other-ranking@example.com');
        $this->childProfile($parent, 'Santi', ['total_stars' => 45]);
        $this->childProfile($parent, 'Sofia', ['total_stars' => 20]);
        $this->childProfile($otherParent, 'Luna', ['total_stars' => 90]);

        $this->getJson('/api/rankings?scope=family')
            ->assertUnauthorized();

        Sanctum::actingAs($parent);
        $this->getJson('/api/rankings?scope=family')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.name', 'Santi')
            ->assertJsonPath('1.name', 'Sofia')
            ->assertJsonMissing(['name' => 'Luna']);
    }

    public function test_child_can_select_a_free_avatar_with_parent_session_context(): void
    {
        $parent = $this->parentAccount();
        $child = $this->childProfile($parent, 'Nico', ['equipped_costume' => 'panda']);

        $this->actingAs($parent)->withHeader('X-Child-Profile-ID', (string) $child->id)
            ->postJson('/api/profile/customize', ['type' => 'avatar', 'item' => 'lion'])
            ->assertOk()
            ->assertJsonPath('user.avatar', '🦁')
            ->assertJsonPath('user.equipped_costume', null)
            ->assertJsonPath('user.has_premium', true);
    }

    public function test_parent_can_assign_activity_and_child_context_only_sees_its_assignment(): void
    {
        $parent = $this->parentAccount();
        $child = $this->childProfile($parent, 'Sofia');
        $subject = Subject::where('name', 'Ciencias')->firstOrFail();
        $otherParent = $this->parentAccount('Other Parent', 'other-activities@example.com');
        $otherChild = $this->childProfile($otherParent, 'Other Child');
        Activity::create([
            'subject_id' => $subject->id,
            'title' => 'Actividad global',
            'type' => 'order',
            'prompt' => 'No debe mostrarse en el perfil familiar.',
            'content' => ['numbers' => ['1', '2']],
        ]);
        Activity::create([
            'subject_id' => $subject->id,
            'created_by' => $otherParent->id,
            'child_id' => $otherChild->id,
            'title' => 'Actividad de otra familia',
            'type' => 'order',
            'prompt' => 'No debe mostrarse a otra familia.',
            'content' => ['numbers' => ['1', '2']],
        ]);

        $this->actingAs($parent)->postJson('/api/activities', [
            'subject_id' => $subject->id,
            'child_id' => $child->id,
            'title' => 'Sopa de animales',
            'type' => 'wordsearch',
            'prompt' => 'Encuentra los animales.',
            'content' => ['words' => ['gato', 'perro']],
        ])->assertCreated();

        $this->actingAs($parent)->getJson('/api/activities')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.title', 'Sopa de animales');

        $this->withHeader('X-Child-Profile-ID', (string) $child->id)->getJson('/api/activities')
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.title', 'Sopa de animales');
        $this->actingAs($parent)->withHeader('X-Child-Profile-ID', (string) $child->id)
            ->postJson('/api/progress/activity', [
                'subject' => 'Ciencias',
                'score' => 1,
                'total' => 1,
                'earned_stars' => 5,
                'activity_id' => Activity::where('title', 'Actividad de otra familia')->value('id'),
            ])->assertForbidden();
        $this->assertTrue($child->fresh()->hasUnlimitedEnergy());
    }

    public function test_parent_dashboard_lists_its_profiles_and_plan_limit(): void
    {
        $parent = $this->parentAccount();
        $this->childProfile($parent, 'Tomas', ['total_stars' => 50, 'level' => 2]);

        $this->actingAs($parent)->getJson('/api/parent/dashboard')
            ->assertOk()
            ->assertJsonPath('children.0.name', 'Tomas')
            ->assertJsonPath('children.0.total_stars', 50)
            ->assertJsonPath('plan.children_limit', 4)
            ->assertJsonPath('plan.children_count', 1);
    }

    public function test_cosmetic_purchase_deducts_stars_and_cannot_charge_twice(): void
    {
        $parent = $this->parentAccount();
        $child = $this->childProfile($parent, 'Emma', ['total_stars' => 100]);
        $request = fn () => $this->actingAs($parent)
            ->withHeader('X-Child-Profile-ID', (string) $child->id)
            ->postJson('/api/profile/purchase', ['type' => 'accessory', 'item' => 'cap']);

        $request()->assertOk()
            ->assertJsonPath('purchased', true)
            ->assertJsonPath('user.total_stars', 50)
            ->assertJsonPath('user.purchased_items.0', 'accessory:cap');
        $request()->assertOk()
            ->assertJsonPath('purchased', false)
            ->assertJsonPath('user.total_stars', 50);

        $this->assertSame(50, $child->fresh()->total_stars);
    }

    public function test_sticker_purchase_requires_enough_stars_and_updates_inventory(): void
    {
        $parent = $this->parentAccount();
        $child = $this->childProfile($parent, 'Leo', ['total_stars' => 24]);

        $this->actingAs($parent)->withHeader('X-Child-Profile-ID', (string) $child->id)
            ->postJson('/api/profile/purchase', ['type' => 'sticker', 'item' => 'rainbow'])
            ->assertUnprocessable();
        $this->assertSame(24, $child->fresh()->total_stars);

        $child->update(['total_stars' => 25]);
        $this->actingAs($parent)->withHeader('X-Child-Profile-ID', (string) $child->id)
            ->postJson('/api/profile/purchase', ['type' => 'sticker', 'item' => 'rainbow'])
            ->assertOk()
            ->assertJsonPath('user.total_stars', 0)
            ->assertJsonPath('user.purchased_items.0', 'sticker:rainbow');
    }

    public function test_child_can_equip_up_to_two_purchased_accessories_only(): void
    {
        $parent = $this->parentAccount();
        $child = $this->childProfile($parent, 'Mia', [
            'purchased_items' => ['accessory:cap', 'accessory:glasses', 'accessory:scarf'],
        ]);
        $headers = ['X-Child-Profile-ID' => (string) $child->id];

        $this->actingAs($parent)->withHeaders($headers)
            ->postJson('/api/profile/customize', ['type' => 'accessories', 'item' => ['cap', 'glasses']])
            ->assertOk()
            ->assertJsonPath('user.equipped_accessories.0', 'cap')
            ->assertJsonPath('user.equipped_accessories.1', 'glasses');
        $this->actingAs($parent)->withHeaders($headers)
            ->postJson('/api/profile/customize', ['type' => 'accessories', 'item' => ['cap', 'glasses', 'scarf']])
            ->assertUnprocessable();
        $this->actingAs($parent)->withHeaders($headers)
            ->postJson('/api/profile/customize', ['type' => 'accessories', 'item' => ['crown']])
            ->assertUnprocessable();
    }

    public function test_cosmetic_purchase_requires_an_active_premium_parent_plan(): void
    {
        $parent = User::create([
            'name' => 'Free Parent',
            'email' => 'store-free@example.com',
            'password' => 'secret123',
            'role' => 'padre',
            'plan_key' => 'free',
        ]);
        $child = $this->childProfile($parent, 'Noa', ['total_stars' => 100]);

        $this->actingAs($parent)->withHeader('X-Child-Profile-ID', (string) $child->id)
            ->postJson('/api/profile/purchase', ['type' => 'accessory', 'item' => 'cap'])
            ->assertForbidden();
        $this->assertSame(100, $child->fresh()->total_stars);
    }

    public function test_parent_can_create_a_family_subject_without_exposing_it_to_another_family(): void
    {
        $parent = $this->parentAccount();
        $otherParent = $this->parentAccount('Other Parent', 'other-parent@example.com');

        $response = $this->actingAs($parent)->postJson('/api/subjects', [
            'name' => 'Astronomía',
            'description' => 'El espacio y sus planetas',
            'icon' => '🪐',
        ]);
        $response->assertCreated()->assertJsonPath('created_by', $parent->id);
        $subjectId = $response->json('id');

        $this->actingAs($parent)->getJson('/api/subjects')->assertJsonFragment(['id' => $subjectId]);
        $this->actingAs($otherParent)->getJson('/api/subjects')->assertJsonMissing(['id' => $subjectId]);
        $this->actingAs($otherParent)->deleteJson('/api/subjects/' . $subjectId)->assertForbidden();
    }

    public function test_parent_can_create_one_to_five_distinct_tasks_atomically(): void
    {
        $parent = $this->parentAccount();
        $child = $this->childProfile($parent);
        $subject = Subject::where('name', 'Ciencias')->firstOrFail();
        $activities = collect(range(1, 5))->map(fn ($number) => [
            'subject_id' => $subject->id,
            'child_id' => $child->id,
            'title' => "Ciencias {$number}",
            'type' => 'order',
            'prompt' => 'Ordena los números.',
            'content' => ['numbers' => ['1', '2']],
        ])->all();

        $this->actingAs($parent)->postJson('/api/parent/activities/batch', ['activities' => $activities])
            ->assertCreated()
            ->assertJsonCount(5, 'activities')
            ->assertJsonPath('activities.4.title', 'Ciencias 5');
        $this->assertDatabaseCount('activities', 5);

        $sixActivities = [...$activities, $activities[0]];
        $this->actingAs($parent)->postJson('/api/parent/activities/batch', ['activities' => $sixActivities])
            ->assertUnprocessable();

        $invalidBatch = [$activities[0], [...$activities[1], 'content' => []]];
        $this->actingAs($parent)->postJson('/api/parent/activities/batch', ['activities' => $invalidBatch])
            ->assertUnprocessable();
        $this->assertDatabaseCount('activities', 5);
    }

    public function test_child_mission_rewards_are_granted_once_and_amount_is_server_controlled(): void
    {
        $parent = $this->parentAccount();
        $child = $this->childProfile($parent, 'Luna', ['total_stars' => 12]);
        $request = fn () => $this->actingAs($parent)
            ->withHeader('X-Child-Profile-ID', (string) $child->id)
            ->postJson('/api/progress/missions/match-animals/complete', ['stars' => 100]);

        $request()->assertOk()
            ->assertJsonPath('completed', true)
            ->assertJsonPath('stars_awarded', 30)
            ->assertJsonPath('user.total_stars', 42);
        $request()->assertOk()
            ->assertJsonPath('completed', false)
            ->assertJsonPath('stars_awarded', 0)
            ->assertJsonPath('user.total_stars', 42);

        $this->actingAs($parent)->withHeader('X-Child-Profile-ID', (string) $child->id)
            ->getJson('/api/progress/missions')
            ->assertOk()
            ->assertJsonPath('missions.0', 'match-animals')
            ->assertJsonPath('date', today()->toDateString());
        $this->assertDatabaseHas('mission_completions', [
            'child_profile_id' => $child->id,
            'mission_key' => today()->toDateString() . ':match-animals',
            'stars' => 30,
        ]);

        Carbon::setTestNow(now()->addDay());
        $request()->assertOk()
            ->assertJsonPath('completed', true)
            ->assertJsonPath('stars_awarded', 30)
            ->assertJsonPath('user.total_stars', 72);
        $this->actingAs($parent)->withHeader('X-Child-Profile-ID', (string) $child->id)
            ->getJson('/api/progress/missions')
            ->assertOk()
            ->assertJsonPath('missions.0', 'match-animals')
            ->assertJsonPath('date', today()->toDateString());
        Carbon::setTestNow();

        $this->actingAs($parent)->withHeader('X-Child-Profile-ID', (string) $child->id)
            ->postJson('/api/progress/missions/not-a-real-mission/complete')
            ->assertNotFound();
    }
}
