<?php

namespace App\Models\Boardroom;

use App\Boardroom\Support\Secrets;
use Illuminate\Database\Eloquent\Model;

/**
 * Paraan ng bayad ng isang contact. Naka-encrypt ang account number sa database;
 * huling 4 na digit lang ang ipinapadala sa AI. Hindi dito inilalagay ang password, PIN, OTP, o CVV.
 */
class PaymentAccount extends Model
{
    protected $table = 'br_payment_accounts';

    protected $fillable = [
        'user_id', 'resource_id', 'method', 'account_name', 'account_number_encrypted', 'last4', 'notes',
        'status', 'source', 'agent_id', 'meeting_id', 'message_id',
    ];

    // Hindi kailanman isinasama sa array/JSON ang encrypted na numero.
    protected $hidden = ['account_number_encrypted'];

    public function setNumber(string $number): void
    {
        $clean = self::normalize($number);
        $this->account_number_encrypted = Secrets::encrypt($number);
        $this->last4 = mb_substr($clean, -4);
    }

    public function number(): ?string
    {
        try {
            return Secrets::decrypt((string) $this->account_number_encrypted);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function masked(): string
    {
        return '****' . ($this->last4 ?: '');
    }

    /** Numero na walang espasyo at gitling — para sa paghahambing. */
    public static function normalize(string $number): string
    {
        return preg_replace('/[\s\-]+/', '', trim($number)) ?? '';
    }

    /** Ang mga field na itinatala sa kasaysayan. Ang numero ay naka-encrypt pa rin dito. */
    public function snapshot(): array
    {
        return [
            'resource_id'              => $this->resource_id,
            'method'                   => $this->method,
            'account_name'             => $this->account_name,
            'account_number_encrypted' => $this->account_number_encrypted,
            'last4'                    => $this->last4,
            'notes'                    => $this->notes,
            'status'                   => $this->status,
        ];
    }
}
