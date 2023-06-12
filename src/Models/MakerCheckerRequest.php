<?php

namespace Prismaticode\MakerChecker\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Prismaticode\MakerChecker\Contracts\MakerCheckerRequestInterface;
use Prismaticode\MakerChecker\Enums\RequestStatuses;

class MakerCheckerRequest extends Model implements MakerCheckerRequestInterface
{
    protected $guarded = ['id', 'code'];

    protected $casts = [
        'payload' => 'array',
        'metadata' => 'array',
    ];

    public function subject(): MorphTo
    {
        return $this->morphTo()->withDefault();
    }

    public function maker(): MorphTo
    {
        return $this->morphTo();
    }

    public function checker(): MorphTo
    {
        return $this->morphTo()->withDefault();
    }

    public function isPending(): bool
    {
        return $this->isOfStatus(RequestStatuses::PENDING);
    }

    public function isProcessing(): bool
    {
        return $this->isOfStatus(RequestStatuses::PROCESSING);
    }

    public function isApproved(): bool
    {
        return $this->isOfStatus(RequestStatuses::APPROVED);
    }

    public function isRejected(): bool
    {
        return $this->isOfStatus(RequestStatuses::REJECTED);
    }

    public function isExpired(): bool
    {
        return $this->isOfStatus(RequestStatuses::EXPIRED);
    }

    public function isFailed(): bool
    {
        return $this->isOfStatus(RequestStatuses::FAILED);
    }

    public function isOfStatus(string $status): bool
    {
        return $this->status === $status;
    }

    public function isOfType(string $type): bool
    {
        return $this->type === $type;
    }

    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }
}
