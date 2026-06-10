<?php

namespace Tests\Feature\Foundation;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

class UserModelTest extends FoundationTestCase
{
    use RefreshDatabase;

    #[Test]
    public function when_a_user_is_persisted_then_it_honours_the_model_contract(): void
    {
        // Given / When a user is persisted
        $user = User::factory()->withPassword('plain-text-secret')->create();

        // Then its key and attribute casts honour the model contract
        $this->assertPersistedUserHonoursModelContract($user);
    }

    protected function assertPersistedUserHonoursModelContract(User $user): void
    {
        $this->assertTrue(Str::isUuid($user->id), 'users.id must be a char(36) UUID (ERD-1-01, R-042)');
        $this->assertInstanceOf(Carbon::class, $user->email_verified_at);
        $this->assertTrue(Hash::check('plain-text-secret', $user->password));
    }
}
