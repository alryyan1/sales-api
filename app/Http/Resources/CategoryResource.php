<?php // app/Http/Resources/CategoryResource.php
namespace App\Http\Resources;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource {
    public function toArray(Request $request): array {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'parent_id' => $this->parent_id,
            'parent_name' => $this->whenLoaded('parent', fn() => $this->parent?->name),
            // Optionally count products or children
            'products_count' => $this->whenCounted('products'),
            'children_count' => $this->whenCounted('children'),
            // To include children recursively (can be large):
            // 'children' => CategoryResource::collection($this->whenLoaded('children')),
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}