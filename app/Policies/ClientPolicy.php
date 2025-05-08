<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization; // Correct trait

class ClientPolicy
{
    use HandlesAuthorization; // Correct trait

    /**
     * Determine whether the user can view any models (index).
     * Adjust based on roles/permissions.
     * Example: Allow admin or anyone with 'view-clients' permission.
     */
    public function viewAny(User $user): bool
    {
        // Option 1: Check for specific permission
        return $user->can('view-clients');

        // Option 2: Allow specific roles
        // return $user->hasRole(['admin', 'salesperson', 'manager']); // Example roles

        // Option 3: Allow any authenticated user (if no specific restriction needed)
        // return true;
    }

    /**
     * Determine whether the user can view the model (show).
     * Example: Allow admin or anyone with 'view-clients' permission.
     * You might add ownership checks here if relevant (e.g., salesperson can only view their clients).
     */
    public function view(User $user, Client $client): bool
    {
        // Option 1: Check for specific permission
        return $user->can('view-clients');

        // Option 2: Ownership check (example - requires relationship setup)
        // Replace 'salesperson_id' with your actual foreign key on the Client model if applicable
        // return $user->can('view-clients') || $client->salesperson_id === $user->id;

        // Option 3: Allow specific roles
        // return $user->hasRole(['admin', 'salesperson', 'manager']);
    }

    /**
     * Determine whether the user can create models.
     * Example: Allow admin or anyone with 'create-clients' permission.
     */
    public function create(User $user): bool
    {
        return $user->can('create-clients');
        // Or check roles: return $user->hasRole(['admin', 'salesperson']);
    }

    /**
     * Determine whether the user can update the model.
     * Example: Allow admin or anyone with 'edit-clients' permission.
     * Again, ownership could be a factor.
     */
    public function update(User $user, Client $client): bool
    {
        // Option 1: Permission check
        return $user->can('edit-clients');

        // Option 2: Ownership check
        // return $user->can('edit-clients') || $client->salesperson_id === $user->id;
    }

    /**
     * Determine whether the user can delete the model.
     * Example: Allow admin or anyone with 'delete-clients' permission.
     * Often restricted more tightly than editing.
     */
    public function delete(User $user, Client $client): bool
    {
        return $user->can('delete-clients');
        // Or maybe only Admins: return $user->hasRole('admin');
    }

    /**
     * Determine whether the user can restore the model (if using Soft Deletes).
     */
    // public function restore(User $user, Client $client): bool
    // {
    //     return $user->can('delete-clients'); // Or a specific 'restore-clients' permission
    // }

    /**
     * Determine whether the user can permanently delete the model (if using Soft Deletes).
     */
    // public function forceDelete(User $user, Client $client): bool
    // {
    //     return $user->hasRole('admin'); // Usually restricted to admins
    // }
}