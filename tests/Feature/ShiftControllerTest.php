<?php

namespace Tests\Feature;

use App\Models\Shift;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Set the base URL for testing
        $this->app['url']->forceRootUrl('http://localhost');

        // Create authenticated user
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    /** @test */
    public function user_can_open_a_shift()
    {
        $response = $this->postJson('/api/shifts/open');

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => [
                'id',
                'user_id',
                'opened_at',
                'closed_at',
                'is_open',
            ]
        ]);

        $this->assertDatabaseHas('shifts', [
            'user_id' => $this->user->id,
            'closed_at' => null,
        ]);

        $shift = Shift::where('user_id', $this->user->id)->first();
        $this->assertNotNull($shift->opened_at);
        $this->assertNull($shift->closed_at);
        $this->assertTrue($shift->is_open);
    }

    /** @test */
    public function user_cannot_open_multiple_shifts_simultaneously()
    {
        // Create an open shift
        Shift::create([
            'user_id' => $this->user->id,
            'opened_at' => now(),
        ]);

        $response = $this->postJson('/api/shifts/open');

        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'هناك وردية مفتوحة بالفعل لهذا المستخدم.',
        ]);

        // Verify only one shift exists
        $this->assertEquals(1, Shift::where('user_id', $this->user->id)->count());
    }

    /** @test */
    public function user_can_get_current_shift()
    {
        $shift = Shift::create([
            'user_id' => $this->user->id,
            'opened_at' => now(),
        ]);

        $response = $this->getJson('/api/shifts/current');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'id',
                'user_id',
                'opened_at',
                'closed_at',
                'is_open',
            ]
        ]);

        $response->assertJson([
            'data' => [
                'id' => $shift->id,
                'user_id' => $this->user->id,
                'is_open' => true,
            ]
        ]);
    }

    /** @test */
    public function returns_204_when_no_shift_exists()
    {
        $response = $this->getJson('/api/shifts/current');

        $response->assertStatus(204);
    }

    /** @test */
    public function user_can_close_current_shift()
    {
        $shift = Shift::create([
            'user_id' => $this->user->id,
            'opened_at' => now(),
        ]);

        $response = $this->postJson('/api/shifts/close');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'id',
                'user_id',
                'opened_at',
                'closed_at',
                'is_open',
            ]
        ]);

        $shift->refresh();
        $this->assertNotNull($shift->closed_at);
        $this->assertEquals($this->user->id, $shift->closed_by_user_id);
        $this->assertFalse($shift->is_open);

        $this->assertDatabaseHas('shifts', [
            'id' => $shift->id,
            'closed_at' => $shift->closed_at->toDateTimeString(),
            'closed_by_user_id' => $this->user->id,
        ]);
    }

    /** @test */
    public function cannot_close_non_existent_shift()
    {
        $response = $this->postJson('/api/shifts/close');

        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'لا توجد وردية مفتوحة لإغلاقها.',
        ]);
    }

    /** @test */
    public function can_close_already_closed_shift()
    {
        // Create a closed shift
        $shift = Shift::create([
            'user_id' => $this->user->id,
            'opened_at' => now()->subHours(2),
            'closed_at' => now()->subHour(),
            'closed_by_user_id' => $this->user->id,
        ]);

        // The controller gets the latest shift (even if closed)
        // and tries to close it again
        $response = $this->postJson('/api/shifts/close');

        // This should still work - it updates the closed_at timestamp
        $response->assertStatus(200);
        
        $shift->refresh();
        $this->assertNotNull($shift->closed_at);
    }

    /** @test */
    public function shift_includes_user_relationship()
    {
        $shift = Shift::create([
            'user_id' => $this->user->id,
            'opened_at' => now(),
        ]);

        $response = $this->getJson('/api/shifts/current');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'user_name',
            ]
        ]);
    }
}


