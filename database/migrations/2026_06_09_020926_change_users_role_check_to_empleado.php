<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('PRAGMA foreign_keys=off;');

        DB::statement("
            CREATE TABLE users_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                name VARCHAR NOT NULL,
                email VARCHAR NOT NULL,
                email_verified_at DATETIME NULL,
                password VARCHAR NOT NULL,
                remember_token VARCHAR NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                role VARCHAR NOT NULL DEFAULT 'empleado' CHECK (role IN ('admin', 'empleado'))
            )
        ");

        DB::statement("
            INSERT INTO users_new (
                id, name, email, email_verified_at, password, remember_token, created_at, updated_at, role
            )
            SELECT
                id,
                name,
                email,
                email_verified_at,
                password,
                remember_token,
                created_at,
                updated_at,
                CASE
                    WHEN role = 'cajero' THEN 'empleado'
                    ELSE role
                END
            FROM users
        ");

        DB::statement('DROP TABLE users;');
        DB::statement('ALTER TABLE users_new RENAME TO users;');

        DB::statement('CREATE UNIQUE INDEX users_email_unique ON users (email);');

        DB::statement('PRAGMA foreign_keys=on;');
    }

    public function down(): void
    {
        DB::statement('PRAGMA foreign_keys=off;');

        DB::statement("
            CREATE TABLE users_old (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                name VARCHAR NOT NULL,
                email VARCHAR NOT NULL,
                email_verified_at DATETIME NULL,
                password VARCHAR NOT NULL,
                remember_token VARCHAR NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                role VARCHAR NOT NULL DEFAULT 'cajero' CHECK (role IN ('admin', 'cajero'))
            )
        ");

        DB::statement("
            INSERT INTO users_old (
                id, name, email, email_verified_at, password, remember_token, created_at, updated_at, role
            )
            SELECT
                id,
                name,
                email,
                email_verified_at,
                password,
                remember_token,
                created_at,
                updated_at,
                CASE
                    WHEN role = 'empleado' THEN 'cajero'
                    ELSE role
                END
            FROM users
        ");

        DB::statement('DROP TABLE users;');
        DB::statement('ALTER TABLE users_old RENAME TO users;');

        DB::statement('CREATE UNIQUE INDEX users_email_unique ON users (email);');

        DB::statement('PRAGMA foreign_keys=on;');
    }
};