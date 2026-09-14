<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Server extends Model
{
    public function mounts() {return $this->hasMany(Mount::class);}
    public function checks() {return $this->hasMany(Check::class);}
}
