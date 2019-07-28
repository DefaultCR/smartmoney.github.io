<?php

namespace App;

use Illuminate\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use DB;

class Bonus extends Model
{
    protected $table = 'bonus';
    
    protected $fillable = ['user_id', 'bonus', 'remaining', 'type', 'status'];
    
    protected $hidden = ['created_at', 'updated_at']; 
}
