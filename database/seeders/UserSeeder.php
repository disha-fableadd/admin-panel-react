<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $superAdminRole = Role::where('name', 'Super Admin')->first();
        $staffRole = Role::where('name', 'Staff')->first();

        // Super Admin
        User::firstOrCreate(
            ['email' => 'admin@gmail.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('12345678'),
                'role_id' => $superAdminRole ? $superAdminRole->id : null,
                'position' => 'Administrator',
                'status' => 'Active',
                'phone_number' => '1234567890'
            ]
        );

        // Staff
        User::firstOrCreate(
            ['email' => 'staff@gmail.com'],
            [
                'name' => 'Staff Member',
                'password' => Hash::make('12345678'),
                'role_id' => $staffRole ? $staffRole->id : null,
                'position' => 'Editor',
                'status' => 'Active',
                'phone_number' => '0987654321'
            ]
        );
    }
}
