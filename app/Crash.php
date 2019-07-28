<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Crash extends Model 
{
    protected $table = 'crash';

    protected $fillable = ['id', 'multiplier', 'price', 'finaly', 'status'];
}