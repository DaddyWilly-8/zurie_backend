<?php

namespace App\Modules\Wishlist\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['customer_id', 'product_id'])]
class WishlistItem extends Model
{
}
