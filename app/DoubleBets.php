<?php

namespace App;

use Illuminate\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use DB;

class DoubleBets extends Model
{
    protected $table = 'double_bets';
    
    protected $fillable = ['user_id', 'game_id', 'type', 'value', 'is_winner', 'value_winner'];
    
    protected $hidden = ['created_at', 'updated_at'];
    
}
