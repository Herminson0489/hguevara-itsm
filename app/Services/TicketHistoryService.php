<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketHistory;
use App\Models\User;

class TicketHistoryService
{
    /**
     * Registrar la creación de un ticket.
     */
    public function recordCreated(Ticket $ticket, User $user): void
    {
        TicketHistory::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'action' => 'created',
            'description' => 'Ticket creado.',
        ]);
    }

    /**
     * Registrar los cambios reales realizados en un ticket.
     *
     * @param array<string, array{0: mixed, 1: mixed}> $changes
     */
    public function recordChanges(
        Ticket $ticket,
        User $user,
        array $changes
    ): void {
        foreach ($changes as $field => $values) {
            [$oldValue, $newValue] = $values;

            TicketHistory::create([
                'ticket_id' => $ticket->id,
                'user_id' => $user->id,
                'action' => 'updated',
                'field' => $field,
                'old_value' => $this->stringify($oldValue),
                'new_value' => $this->stringify($newValue),
                'description' => "Campo {$field} actualizado.",
            ]);
        }
    }

    private function stringify(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE) ?: null;
    }
}
