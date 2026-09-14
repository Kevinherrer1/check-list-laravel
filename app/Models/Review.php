<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    public function checks() {return $this->hasMany(Check::class);}
}
