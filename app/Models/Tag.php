<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class Tag extends Model
{
    protected $fillable = ['name', 'slug'];

    /**
     * @return BelongsToMany<Question, $this>
     */
    public function questions(): BelongsToMany
    {
        return $this->belongsToMany(Question::class);
    }

    /**
     * Find or create tags from free text ("geo, capitals; Asia"), returning their ids.
     *
     * @return Collection<int, int>
     */
    public static function idsFromList(string $list): Collection
    {
        return collect(preg_split('/[,;]/', $list))
            ->map(fn ($name) => Str::limit(Str::squish($name), 50, ''))
            ->filter()
            ->unique(fn ($name) => Str::slug($name))
            ->map(fn ($name) => static::query()->firstOrCreate(['slug' => Str::slug($name)], ['name' => $name])->id)
            ->values();
    }
}
