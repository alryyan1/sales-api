<?php // app/Policies/RolePolicy.php

namespace App\Policies;

use App\Models\User;
use Spatie\Permission\Models\Role;
use Illuminate\Auth\Access\HandlesAuthorization;

class RolePolicy
{
    use HandlesAuthorization;

    // Grant all abilities to admin for simplicity in this example
    public function before(User $user, string $ability): bool|null
    {
        if ($user->hasRole('admin')) {
            return true;
        }
        return null; // Let other checks proceed
    }

    public function viewAny(User $user): bool
    {
        return $user->can('manage-roles');
    }
    public function view(User $user, Role $role): bool
    {
        return $user->can('manage-roles');
    }
    public function create(User $user): bool
    {
        return $user->can('manage-roles');
    }
    public function update(User $user, Role $role): bool
    {
        // Prevent editing admin role?
        // if ($role->name === 'admin') return false;
        return $user->can('manage-roles');
    }
    public function delete(User $user, Role $role): bool
    {
        // Prevent deleting admin role?
        // if ($role->name === 'admin') return false;
        return $user->can('manage-roles');
    }
    // public function restore(User $user, Role $role): bool { return $user->can('manage-roles'); }
    // public function forceDelete(User $user, Role $role): bool { return $user->can('manage-roles'); }
}
