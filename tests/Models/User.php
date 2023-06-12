<?php

namespace Prismaticoder\MakerChecker\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Prismaticoder\MakerChecker\Traits\ChecksRequests;
use Prismaticoder\MakerChecker\Traits\MakesRequests;

class User extends Model
{
    use MakesRequests, ChecksRequests;

    protected $guarded = [];
}
