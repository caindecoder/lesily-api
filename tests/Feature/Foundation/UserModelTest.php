<?php

namespace Tests\Feature\Foundation;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

class UserModelTest extends FoundationTestCase
{
    use RefreshDatabase;

    #[Test]
    public function when_a_user_is_persisted_then_its_attributes_are_cast(): void
    {
        $user = User::factory()->withPassword('plain-text-secret')->create();

        $this->assertUserAttributesAreCast($user);
    }

    protected function assertUserAttributesAreCast(User $user): void
    {
        $this->assertInstanceOf(Carbon::class, $user->email_verified_at);
        $this->assertTrue(Hash::check('plain-text-secret', $user->password));
    }
}
