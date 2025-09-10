<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EurogrosMissingEanNumber extends Model
{
    use HasFactory;

    protected $fillable = ['ean'];
}
