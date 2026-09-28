<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Một lượt mở link chia sẻ (không thuộc tenant: người xem không đăng nhập). */
class ShareLinkView extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['viewed_at' => 'datetime'];
    }
}
