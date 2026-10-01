<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Super Admin
        User::create([
            'name' => 'Super Admin',
            'email' => 'admin@gmail.com',
            'password' => Hash::make('12345678'),
            'role' => 'super_admin',
            'position' => 'Administrator',
            'status' => 'Active',
            'phone_number' => '1234567890'
        ]);

        // Staff
        User::create([
            'name' => 'Staff Member',
            'email' => 'staff@gmail.com',
            'password' => Hash::make('12345678'),
            'role' => 'staff',
            'position' => 'Editor',
            'status' => 'Active',
            'phone_number' => '0987654321'
        ]);
    }
}
