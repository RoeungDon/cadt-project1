<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on'   => 'date',
    ];

    // Shown to tenants today
    public function scopeCurrent(Builder $query): Builder
    {
        $today = now()->toDateString();

        return $query->whereDate('starts_on', '<=', $today)
            ->where(fn ($inner) => $inner->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today));
    }

    public function present(): array
    {
        return [
            'id'        => $this->id,
            'title'     => $this->title,
            'body'      => $this->body,
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on'   => $this->ends_on?->toDateString(),
        ];
    }
}
