<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class Folder extends Model
{
    protected $fillable = ['name', 'parent', 'user_id', 'brand_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * Get all descendants of this folder recursively.
     */
    public function getDescendants(): Collection
    {
        $descendants = collect();
        $folderPath = $this->parent ? $this->parent . '/' . $this->name : $this->name;
        
        $children = Folder::where('parent', $folderPath)->get();
        
        foreach ($children as $child) {
            $descendants->push($child);
            $descendants = $descendants->merge($child->getDescendants());
        }
        
        return $descendants;
    }

    /**
     * Check if a given folder ID is a descendant of this folder.
     */
    public function isDescendantOf(Folder $potentialParent): bool
    {
        return $potentialParent->getDescendants()->contains('id', $this->id);
    }

    /**
     * Check if moving to a parent would create a circular structure.
     */
    public function canMoveTo(?string $newParent): bool
    {
        // Moving to root is always allowed
        if ($newParent === null || $newParent === '') {
            return true;
        }

        // Own full path
        $ownPath = $this->parent
            ? $this->parent . '/' . $this->name
            : $this->name;

        /**
         * ❌ Block:
         * - moving to itself
         * - moving to any child or sub-child
         */
        if (
            $newParent === $ownPath ||
            str_starts_with($newParent, $ownPath . '/')
        ) {
            return false;
        }

        return true;
    }

}
