<?php

namespace App;

use Illuminate\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use DB;

class Profit extends Model
{
    protected $table = 'profit';
    
    protected $fillable = ['profit', 'day'];
    
    protected $hidden = ['created_at', 'updated_at']; 
}
