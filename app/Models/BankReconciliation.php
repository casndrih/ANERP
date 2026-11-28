<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class BankReconciliation extends Model { protected $guarded = []; protected function casts(): array { return ['statement_start'=>'date','statement_end'=>'date','reconciled_at'=>'datetime']; } }