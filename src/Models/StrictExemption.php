<?php

namespace Warlof\Seat\Connector\Models;

use Illuminate\Database\Eloquent\Model;

/** Discord Strict exemptions belonging to a SeAT account, not a connector identity. */
class StrictExemption extends Model
{
    protected $table = 'seat_connector_strict_exemptions';

    protected $fillable = ['user_id', 'character_id'];

    protected $casts = ['user_id' => 'integer', 'character_id' => 'integer'];
}
