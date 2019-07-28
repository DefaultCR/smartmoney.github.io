<?php

namespace App;

use Illuminate\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

class Roulette extends Model
{
    protected $table = 'roulette';
    
    protected $fillable = ['id', 'winner_color', 'winner_num', 'price', 'status'];
    
    protected $hidden = ['created_at', 'updated_at'];
    
}
