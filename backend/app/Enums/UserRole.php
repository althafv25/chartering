<?php

namespace App\Enums;

/**
 * System roles (single company). `super-admin` bypasses permission checks via
 * Gate::before; all other roles get explicit permissions in the seeder.
 */
enum UserRole: string
{
    case SuperAdmin = 'super-admin';
    case Management = 'management';
    case Chartering = 'chartering';
    case Operations = 'operations';
    case Commercial = 'commercial';
    case Finance = 'finance';
    case Accounts = 'accounts';
    case MarineOperations = 'marine-operations';
    case ReadOnly = 'read-only';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Management => 'Management',
            self::Chartering => 'Chartering',
            self::Operations => 'Operations',
            self::Commercial => 'Commercial',
            self::Finance => 'Finance',
            self::Accounts => 'Accounts',
            self::MarineOperations => 'Marine Operations',
            self::ReadOnly => 'Read Only',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function isSystem(string $name): bool
    {
        return in_array($name, self::values(), true);
    }
}
