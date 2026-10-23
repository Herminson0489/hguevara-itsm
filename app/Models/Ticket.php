<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ticket extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Campos que pueden asignarse de forma masiva.
     */
    protected $fillable = [
        'company_id',
        'requester_id',
        'assigned_to',
        'ticket_number',
        'title',
        'description',
        'category',
        'priority',
        'status',
        'due_at',
        'resolved_at',
    ];

    /**
     * Conversión de fechas.
     */
    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * Empresa propietaria del ticket.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Usuario que creó el ticket.
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /**
     * Técnico asignado al ticket.
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
