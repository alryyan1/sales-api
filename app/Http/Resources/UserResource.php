<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Note: $this->resource refers to the User model instance
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at?->toISOString(), // Format if not null
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
            // Conditionally include roles and permissions if they were loaded onto the model
            'roles' => $this->whenLoaded('roles', function () {
                return $this->getRoleNames(); // Get just the names
            }),
            'permissions' => $this->whenLoaded('permissions', function () {
                // Getting *all* permissions (direct + via roles) might require getAllPermissions()
                // Depending on what you need, just direct permissions might be $this->permissions->pluck('name')
                 return $this->getAllPermissions()->pluck('name'); // Get all permission names
            }),
        ];
    }
}