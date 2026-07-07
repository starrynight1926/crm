<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@sweetsica.com'],
            [
                'name' => 'Quản trị viên',
                'password' => 'admin@123',
                'status' => User::STATUS_ACTIVE,
            ]
        );

        $this->call([
            PermissionSeeder::class,
            OrgAndRoleSeeder::class,
            DemoDataSeeder::class,
        ]);
    }
}
