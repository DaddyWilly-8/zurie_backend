<?php

namespace App\Modules\Notification\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['email'])]
class NewsletterSubscriber extends Model
{
    protected $table = 'newsletter_subscribers';
}
