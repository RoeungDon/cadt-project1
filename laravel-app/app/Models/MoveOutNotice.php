<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MoveOutNotice extends Model
{
    public const NOTICE_DAYS = 30;

    protected $guarded = ['id'];

    protected $casts = [
        'given_on'              => 'date',
        'planned_move_out_date' => 'date',
        'cancelled_at'          => 'datetime',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('cancelled_at');
    }

    // The latest notice of a tenant that was not cancelled
    public static function activeFor(int $tenantId): ?self
    {
        return static::active()->where('tenant_id', $tenantId)->latest('id')->first();
    }

    public function daysOfNotice(): int
    {
        return (int) $this->given_on->diffInDays($this->planned_move_out_date);
    }

    public function present(): array
    {
        return [
            'id'                    => $this->id,
            'given_on'              => $this->given_on->toDateString(),
            'planned_move_out_date' => $this->planned_move_out_date->toDateString(),
            'days_of_notice'        => $this->daysOfNotice(),
            'enough_notice'         => $this->daysOfNotice() >= self::NOTICE_DAYS,
            'reason'                => $this->reason,
        ];
    }
}
