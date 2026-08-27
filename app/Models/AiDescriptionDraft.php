<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiDescriptionDraft extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'product_id',
        'run_id',
        'status',
        'fields',
        'previous_values',
        'problems',
        'similarity',
        'driver',
        'model',
        'prompt_version',
        'input_tokens',
        'output_tokens',
        'attempts',
        'error',
        'reviewed_by',
        'reviewed_at',
        'applied_at',
    ];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<AiDescriptionRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AiDescriptionRun::class, 'run_id');
    }

    /**
     * A draft that was published can be rolled back as long as we still hold
     * the values it overwrote.
     *
     * The column is previous_values rather than previous because Eloquent's
     * Model declares a protected $previous, which would shadow the attribute
     * here and make this always return false.
     */
    public function isRevertible(): bool
    {
        return $this->status === self::STATUS_APPLIED && ! empty($this->previous_values);
    }

    protected function casts(): array
    {
        return [
            'fields'          => 'array',
            'previous_values' => 'array',
            'problems'        => 'array',
            'similarity'      => 'float',
            'reviewed_at'     => 'datetime',
            'applied_at'      => 'datetime',
        ];
    }
}
