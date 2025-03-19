<?php

namespace Webkul\WooCommerce\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Webkul\HistoryControl\Contracts\HistoryAuditable as HistoryContract;
use Webkul\HistoryControl\Interfaces\PresentableHistoryInterface;
use Webkul\HistoryControl\Traits\HistoryTrait;
use Webkul\WooCommerce\Contracts\Credential as CredentialContract;
use Webkul\WooCommerce\Database\Factories\CredentialFactory;
use Webkul\WooCommerce\Presenters\CredentialPresenter;

class Credential extends Model implements CredentialContract, HistoryContract, PresentableHistoryInterface
{
    use HasFactory;
    use HistoryTrait;

    protected $table = 'wk_woocommerce_credentials';

    protected $historyTags = ['woocommerce_credentials'];

    protected $auditExclude = ['consumerSecret'];

    protected $fillable = [
        'shopUrl',
        'consumerKey',
        'consumerSecret',
        'active',
        'defaultSet',
        'extras',
    ];

    protected $casts = [
        'extras'             => 'array',
    ];

    /**
     * {@inheritdoc}
     */
    public static function getPresenters(): array
    {
        return [
            'common'  => CredentialPresenter::class,
        ];
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): Factory
    {
        return CredentialFactory::new();
    }
}
