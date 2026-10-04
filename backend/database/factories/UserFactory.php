<?php

namespace Database\Factories;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        $first = fake()->firstName();
        $last = fake()->lastName();

        return [
            'name' => "{$first} {$last}",
            'first_name' => $first,
            'last_name' => $last,
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => 'Password123',
            'status' => UserStatus::Active,
            'timezone' => 'Asia/Dubai',
            'remember_token' => Str::random(10),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => UserStatus::Inactive]);
    }

    public function withRole(string $role): static
    {
        return $this->afterCreating(fn (User $user) => $user->assignRole($role));
    }
}
