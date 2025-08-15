<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $requester_user_id
 * @property int|null $approved_by_user_id
 * @property string|null $department_or_reason
 * @property string|null $notes
 * @property string $status
 * @property \Illuminate\Support\Carbon $request_date
 * @property \Illuminate\Support\Carbon|null $issue_date
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $approvedByUser
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\StockRequisitionItem> $items
 * @property-read int|null $items_count
 * @property-read \App\Models\User $requesterUser
 * @method static \Illuminate\Database\Eloquent\Builder|StockRequisition newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|StockRequisition newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|StockRequisition query()
 * @method static \Illuminate\Database\Eloquent\Builder|StockRequisition whereApprovedByUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StockRequisition whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StockRequisition whereDepartmentOrReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StockRequisition whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StockRequisition whereIssueDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StockRequisition whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StockRequisition whereRequestDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StockRequisition whereRequesterUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StockRequisition whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StockRequisition whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class StockRequisition extends Model
{
    use HasFactory; // Remember to create StockRequisitionFactory

    protected $fillable = [
        'requester_user_id',
        'approved_by_user_id',
        'department_or_reason',
        'notes',
        'status',
        'request_date',
        'issue_date',
    ];

    protected $casts = [
        'request_date' => 'date',
        'issue_date' => 'date',
    ];

    public function requesterUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockRequisitionItem::class);
    }
}