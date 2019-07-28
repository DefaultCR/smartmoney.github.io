<?php

namespace App;

use Illuminate\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

class Room1 extends Model
{
    protected $table = 'jackpot_room1';
    
    protected $fillable = ['winner_id', 'winner_chance', 'random_number', 'price', 'status'];
    
    protected $hidden = ['created_at', 'updated_at'];
    
}
