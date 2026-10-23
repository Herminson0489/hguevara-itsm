<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TicketController extends Controller
{
    /**
     * Verificar que el usuario pertenezca a una empresa activa.
     */
    private function companyIsActive(Request $request): bool
    {
        $user = $request->user();

        return $user !== null
            && $user->company !== null
            && $user->company->status === 'active';
    }

    /**
     * Listar tickets según el rol del usuario autenticado.
     */
    public function index(Request $request): JsonResponse
    {
        if (!$this->companyIsActive($request)) {
            return response()->json([
                'message' => 'El usuario no pertenece a una empresa activa.',
            ], 403);
        }

        $validated = $request->validate([
            'status' => [
                'sometimes',
                Rule::in([
                    'open',
                    'in_progress',
                    'pending',
                    'resolved',
                    'closed',
                ]),
            ],
            'priority' => [
                'sometimes',
                Rule::in(['low', 'medium', 'high', 'critical']),
            ],
        ]);

        $user = $request->user();

        $query = Ticket::query()
            ->where('company_id', $user->company_id);

        // Los solicitantes solo consultan sus propios tickets.
        if ($user->isRequester()) {
            $query->where('requester_id', $user->id);
        }

        $tickets = $query
            ->with(['company', 'requester', 'assignee'])
            ->when(
                isset($validated['status']),
                fn ($query) => $query->where(
                    'status',
                    $validated['status']
                )
            )
            ->when(
                isset($validated['priority']),
                fn ($query) => $query->where(
                    'priority',
                    $validated['priority']
                )
            )
            ->latest()
            ->paginate(15);

        return response()->json($tickets);
    }

    /**
     * Crear un ticket para la empresa del usuario autenticado.
     */
    public function store(Request $request): JsonResponse
    {
        if (!$this->companyIsActive($request)) {
            return response()->json([
                'message' => 'El usuario no pertenece a una empresa activa.',
            ], 403);
        }

        $user = $request->user();

        $rules = [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'category' => [
                'sometimes',
                Rule::in([
                    'hardware',
                    'software',
                    'network',
                    'access',
                    'email',
                    'other',
                ]),
            ],
        ];

        // Solo técnicos y administradores pueden definir estos campos.
        $isPrivileged = $user->isTechnician()
            || $user->isCompanyAdmin()
            || $user->isSuperAdmin();

        if ($isPrivileged) {
            $rules['assigned_to'] = [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('users', 'id')
                    ->where('company_id', $user->company_id)
                    ->where('role', 'technician'),
            ];

            $rules['priority'] = [
                'sometimes',
                Rule::in(['low', 'medium', 'high', 'critical']),
            ];

            $rules['status'] = [
                'sometimes',
                Rule::in([
                    'open',
                    'in_progress',
                    'pending',
                    'resolved',
                    'closed',
                ]),
            ];

            $rules['due_at'] = [
                'sometimes',
                'nullable',
                'date',
            ];
        }

        $validated = $request->validate($rules);

        $ticket = DB::transaction(function () use ($validated, $user) {
            $ticket = new Ticket();

            // La empresa y el solicitante vienen del usuario autenticado.
            $ticket->company_id = $user->company_id;
            $ticket->requester_id = $user->id;
            $ticket->assigned_to = $validated['assigned_to'] ?? null;

            $ticket->ticket_number = 'TMP-' . uniqid();
            $ticket->title = $validated['title'];
            $ticket->description = $validated['description'];
            $ticket->category = $validated['category'] ?? 'other';
            $ticket->priority = $validated['priority'] ?? 'medium';
            $ticket->status = $validated['status'] ?? 'open';
            $ticket->due_at = $validated['due_at'] ?? null;

            $ticket->resolved_at = in_array(
                $ticket->status,
                ['resolved', 'closed'],
                true
            ) ? now() : null;

            $ticket->save();

            $ticket->ticket_number = sprintf(
                'INC-%s-%06d',
                now()->format('Y'),
                $ticket->id
            );

            $ticket->save();

            return $ticket;
        });

        return response()->json([
            'message' => 'Ticket creado correctamente.',
            'data' => $ticket->load([
                'company',
                'requester',
                'assignee',
            ]),
        ], 201);
    }

    /**
     * Consultar un ticket según el rol y la empresa.
     */
    public function show(int $id, Request $request): JsonResponse
    {
        if (!$this->companyIsActive($request)) {
            return response()->json([
                'message' => 'El usuario no pertenece a una empresa activa.',
            ], 403);
        }

        $user = $request->user();

        $query = Ticket::query()
            ->where('company_id', $user->company_id);

        if ($user->isRequester()) {
            $query->where('requester_id', $user->id);
        }

        $ticket = $query
            ->with(['company', 'requester', 'assignee'])
            ->findOrFail($id);

        return response()->json($ticket);
    }

    /**
     * Actualizar un ticket dentro de la empresa.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        if (!$this->companyIsActive($request)) {
            return response()->json([
                'message' => 'El usuario no pertenece a una empresa activa.',
            ], 403);
        }

        $user = $request->user();

        // Solo técnicos y administradores pueden actualizar tickets.
        if (
            !$user->isTechnician()
            && !$user->isCompanyAdmin()
            && !$user->isSuperAdmin()
        ) {
            return response()->json([
                'message' => 'No tienes permisos para actualizar tickets.',
            ], 403);
        }

        $validated = $request->validate([
            'assigned_to' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('users', 'id')
                    ->where('company_id', $user->company_id)
                    ->where('role', 'technician'),
            ],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'required', 'string'],
            'category' => [
                'sometimes',
                Rule::in([
                    'hardware',
                    'software',
                    'network',
                    'access',
                    'email',
                    'other',
                ]),
            ],
            'priority' => [
                'sometimes',
                Rule::in(['low', 'medium', 'high', 'critical']),
            ],
            'status' => [
                'sometimes',
                Rule::in([
                    'open',
                    'in_progress',
                    'pending',
                    'resolved',
                    'closed',
                ]),
            ],
            'due_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $ticket = Ticket::query()
            ->where('company_id', $user->company_id)
            ->findOrFail($id);

        $ticket->fill($validated);

        if (isset($validated['status'])) {
            if (in_array(
                $validated['status'],
                ['resolved', 'closed'],
                true
            )) {
                if ($ticket->resolved_at === null) {
                    $ticket->resolved_at = now();
                }
            } else {
                $ticket->resolved_at = null;
            }
        }

        $ticket->save();

        return response()->json([
            'message' => 'Ticket actualizado correctamente.',
            'data' => $ticket->load([
                'company',
                'requester',
                'assignee',
            ]),
        ]);
    }

    /**
     * Eliminar lógicamente un ticket de la empresa.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        if (!$this->companyIsActive($request)) {
            return response()->json([
                'message' => 'El usuario no pertenece a una empresa activa.',
            ], 403);
        }

        $user = $request->user();

        // Solo administradores pueden eliminar tickets.
        if (!$user->isCompanyAdmin() && !$user->isSuperAdmin()) {
            return response()->json([
                'message' => 'No tienes permisos para eliminar tickets.',
            ], 403);
        }

        $ticket = Ticket::query()
            ->where('company_id', $user->company_id)
            ->findOrFail($id);

        $ticket->delete();

        return response()->json([
            'message' => 'Ticket eliminado correctamente.',
        ]);
    }
}