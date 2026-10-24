<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketHistoryController extends Controller
{
    public function index(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        if (
            $user === null ||
            $user->company === null ||
            $user->company->status !== 'active'
        ) {
            return response()->json([
                'message' => 'El usuario no pertenece a una empresa activa.',
            ], 403);
        }

        $query = Ticket::query()
            ->where('company_id', $user->company_id);

        if ($user->isRequester()) {
            $query->where('requester_id', $user->id);
        }

        $ticket = $query->findOrFail($id);

        $history = $ticket->histories()
            ->with('user:id,name,email')
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($history);
    }
}
