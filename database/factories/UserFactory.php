<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => Role::Employee,
            'remember_token' => Str::random(10),
        ];
    }

    public function employee(): static
    {
        return $this->state(fn () => ['role' => Role::Employee]);
    }

    public function manager(): static
    {
        return $this->state(fn () => ['role' => Role::Manager]);
    }

    public function operations(): static
    {
        return $this->state(fn () => ['role' => Role::Operations]);
    }

    /** Make this user a direct report of the given manager. */
    public function reportingTo(User $manager): static
    {
        return $this->state(fn () => ['manager_id' => $manager->id]);
    }
}
