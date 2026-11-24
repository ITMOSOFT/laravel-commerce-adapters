<?php

namespace ItmoSoft\CommerceAdapters\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $table = 'products';

    protected $guarded = [];

    protected $casts = [
        'price' => 'decimal:2',
    ];
}
