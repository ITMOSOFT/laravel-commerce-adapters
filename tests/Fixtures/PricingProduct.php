<?php

namespace ItmoSoft\CommerceAdapters\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use ItmoSoft\Pricing\Models\Price;

class PricingProduct extends Model
{
    protected $table = 'adapter_products';

    protected $guarded = [];

    public function prices(): MorphMany
    {
        return $this->morphMany(Price::class, 'priceable');
    }
}
