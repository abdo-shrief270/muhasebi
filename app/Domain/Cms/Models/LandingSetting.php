<?php

namespace App\Domain\Cms\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

#[Table('landing_settings')]
#[Fillable(['section', 'data'])]
class LandingSetting extends Model
{

    protected function casts(): array
    {
        return [
            'data' => 'array',
        ];
    }
}
