<?php

namespace Prismaticode\MakerChecker\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Prismaticode\MakerChecker\Traits\ChecksRequests;
use Prismaticode\MakerChecker\Traits\MakesRequests;

class User extends Model
{
    use MakesRequests, ChecksRequests;

    protected $guarded = [];
}
