<?php

namespace App\Models;

class MilestoneType extends ReferenceModel
{
    protected $table = 'milestone_types';

    protected function casts(): array
    {
        return ['is_laytime_relevant' => 'boolean'];
    }
}
