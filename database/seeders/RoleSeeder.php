<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Role::firstOrCreate(
            ['name' => 'Super Admin'],
            ['description' => 'System Administrator with full access', 'status' => 'Active']
        );

        Role::firstOrCreate(
            ['name' => 'Staff'],
            ['description' => 'Standard staff user', 'status' => 'Active']
        );
    }
}
