<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Brand extends Model
{
    use HasFactory;

    protected $fillable = [
        'agency_id',
        'user_id',
        'name',
        'website',
        'description',
        'monthly_posts',
        'status',
        'is_completed',
        'current_step',
        'session_id',
        'completed_at',
        'can_create_posts',
        'post_creation_note',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'can_create_posts' => 'boolean',
    ];

    /**
     * Get the current month's posts count for this brand.
     */
    public function getCurrentMonthPostsCount(): int
    {
        return $this->posts()
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agency_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function prompts(): HasMany
    {
        return $this->hasMany(BrandPrompt::class);
    }

    public function subreddits(): HasMany
    {
        return $this->hasMany(BrandSubreddit::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }
}
