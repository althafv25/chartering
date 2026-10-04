<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @property string $role */
class CompanyRoleAssignment extends Model
{
    protected $table = 'company_roles';

    protected $fillable = ['company_id', 'role'];
}
