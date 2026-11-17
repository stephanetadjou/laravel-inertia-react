<?php

namespace App\Modules\CarteDigitale\Models;

use Illuminate\Database\Eloquent\Model;

class DigitalCardEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['digital_card_id', 'type', 'created_at'];
}
