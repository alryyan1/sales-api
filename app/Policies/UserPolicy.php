<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization; // Use correct trait

class UserPolicy
{
    use HandlesAuthorization; // Use the correct trait

    /**
     * Determine whether the user can view any models.
     * Only users with 'manage-users' permission can view the list.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('manage-users');
    }

    /**
     * Determine whether the user can view the model.
     * Admins can view any profile, others can only view their own? Or based on 'manage-users'.
     */
    public function view(User $user, User $model): bool // $model is the user being viewed
    {
        // Option 1: Only admins can view any profile via this policy
        // return $user->can('manage-users');

        // Option 2: Admins can view any, users can view their own (ProfileController handles own view)
         return $user->id === $model->id || $user->can('manage-users');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('manage-users'); // Or specific 'create-users' permission
    }

    /**
     * Determine whether the user can update the model.
     * Admins can update any, users might update their own (handled by ProfileController).
     */
    public function update(User $user, User $model): bool
    {
         // Prevent admin from editing themselves via this route maybe? Let them use profile page.
         // if ($user->id === $model->id) return false;

         // Check if the user has permission to manage users
         return $user->can('manage-users'); // Or specific 'edit-users' permission
    }

    /**
     * Determine whether the user can delete the model.
     * Prevent self-deletion is handled in controller.
     */
    public function delete(User $user, User $model): bool
    {
        // Prevent deleting self (double check)
        if ($user->id === $model->id) {
            return false;
        }
        return $user->can('manage-users'); // Or specific 'delete-users' permission
    }

    /**
     * Determine whether the user can restore the model (if using soft deletes).
     */
    // public function restore(User $user, User $model): bool
    // {
    //     return $user->can('manage-users');
    // }

    /**
     * Determine whether the user can permanently delete the model (if using soft deletes).
     */
    // public function forceDelete(User $user, User $model): bool
    // {
    //     return $user->can('manage-users');
    // }
}