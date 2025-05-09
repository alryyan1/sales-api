<?php // app/Http/Resources/RoleResource.php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            // Include permission names if the relationship is loaded
            'permissions' => $this->whenLoaded('permissions', function() {
                return $this->permissions->pluck('name'); // Return only the names
            }),
            // Optionally include user count if loaded via withCount
            'users_count' => $this->whenCounted('users'),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}