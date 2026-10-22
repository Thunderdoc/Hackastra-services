<?php

namespace Database\Factories;

use App\Helpers\Qs;
use App\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
/**
* The name of the factory's corresponding model.
*
* @var string
*/
    protected $model = User::class;

/**
* Define the model's default state.
*
* @return array
*/
    public function definition()
    {
        // Create random User Type
        $user_type = Qs::getStaff(['super_admin', 'librarian'])[rand(0,2)];

        return [
            'name' => $this->faker->name,
            /* Audit fix (2026-10-23): the factory never set phone,
               so every factory-made account showed a blank Phone
               column in Manage Users. Indian demo mobile pattern. */
            'phone' => '+91 ' . $this->faker->numerify('98########'),
            'email' => $this->faker->safeEmail,
            'username' => $this->faker->userName,
            'password' => Hash::make($user_type),
            'user_type' => $user_type,
            'code' => strtoupper(Str::random(10)),
            'remember_token' => Str::random(10),
        ];
    }
}
