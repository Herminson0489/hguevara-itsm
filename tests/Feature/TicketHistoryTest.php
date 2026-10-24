<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TicketHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Company $companyA;
    private Company $companyB;

    private User $adminA;
    private User $requesterA;
    private User $requesterB;

    private Ticket $ticketA;
    private Ticket $ticketB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = $this->createCompany('empresa-a');
        $this->companyB = $this->createCompany('empresa-b');

        $this->adminA = $this->createUser(
            $this->companyA,
            'admin-a',
            'company_admin'
        );

        $this->requesterA = $this->createUser(
            $this->companyA,
            'solicitante-a',
            'requester'
        );

        $this->requesterB = $this->createUser(
            $this->companyB,
            'solicitante-b',
            'requester'
        );

        $this->ticketA = $this->createTicket(
            $this->companyA,
            $this->requesterA,
            'INC-TEST-000001'
        );

        $this->ticketB = $this->createTicket(
            $this->companyB,
            $this->requesterB,
            'INC-TEST-000002'
        );
    }

    public function test_guest_cannot_view_ticket_history(): void
    {
        $this->getJson("/api/tickets/{$this->ticketA->id}/history")
            ->assertUnauthorized();
    }

    public function test_company_admin_cannot_view_history_from_another_company(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->getJson("/api/tickets/{$this->ticketB->id}/history")
            ->assertNotFound();
    }

    public function test_requester_cannot_view_another_users_ticket_history(): void
    {
        Sanctum::actingAs($this->requesterA);

        $this->getJson("/api/tickets/{$this->ticketB->id}/history")
            ->assertNotFound();
    }

    public function test_requester_can_view_history_endpoint_for_own_ticket(): void
    {
        Sanctum::actingAs($this->requesterA);

        $this->getJson("/api/tickets/{$this->ticketA->id}/history")
            ->assertOk();
    }
public function test_ticket_creation_records_audit_history(): void
{
    Sanctum::actingAs($this->requesterA);

    $response = $this->postJson('/api/tickets', [
        'title' => 'Nuevo ticket de auditoría',
        'description' => 'Prueba del registro de creación.',
        'category' => 'software',
    ]);

    $response->assertCreated();

    $ticketId = $response->json('data.id');

    $this->assertDatabaseHas('ticket_histories', [
        'ticket_id' => $ticketId,
        'user_id' => $this->requesterA->id,
        'action' => 'created',
        'description' => 'Ticket creado.',
    ]);
}

public function test_ticket_update_records_old_and_new_values(): void
{
    Sanctum::actingAs($this->adminA);

    $response = $this->putJson(
        "/api/tickets/{$this->ticketA->id}",
        ['title' => 'Título actualizado']
    );

    $response->assertOk();

    $this->assertDatabaseHas('ticket_histories', [
        'ticket_id' => $this->ticketA->id,
        'user_id' => $this->adminA->id,
        'action' => 'updated',
        'field' => 'title',
        'old_value' => 'Ticket de prueba',
        'new_value' => 'Título actualizado',
    ]);
}

public function test_ticket_update_without_changes_does_not_create_history(): void
{
    Sanctum::actingAs($this->adminA);

    $historyCountBefore = \App\Models\TicketHistory::query()
        ->where('ticket_id', $this->ticketA->id)
        ->count();

    $this->putJson(
        "/api/tickets/{$this->ticketA->id}",
        ['title' => 'Ticket de prueba']
    )->assertOk();

    $historyCountAfter = \App\Models\TicketHistory::query()
        ->where('ticket_id', $this->ticketA->id)
        ->count();

    $this->assertSame($historyCountBefore, $historyCountAfter);
}
    private function createCompany(string $slug): Company
    {
        return Company::create([
            'name' => "Empresa {$slug}",
            'slug' => $slug,
            'status' => 'active',
        ]);
    }

    private function createUser(
        Company $company,
        string $prefix,
        string $role
    ): User {
        $user = User::create([
            'name' => "Usuario {$prefix}",
            'email' => "{$prefix}@example.test",
            'password' => 'PruebaTemporal2026!',
            'company_id' => $company->id,
        ]);

        $user->role = $role;
        $user->save();

        return $user;
    }

    private function createTicket(
        Company $company,
        User $requester,
        string $number
    ): Ticket {
        return Ticket::create([
            'company_id' => $company->id,
            'requester_id' => $requester->id,
            'ticket_number' => $number,
            'title' => 'Ticket de prueba',
            'description' => 'Ticket para pruebas del historial.',
            'category' => 'software',
            'priority' => 'medium',
            'status' => 'open',
        ]);
    }
}
