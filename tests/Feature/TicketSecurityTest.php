<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TicketSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Company $companyA;
    private Company $companyB;

    private User $adminA;
    private User $technicianA;
    private User $requesterA;
    private User $requesterB;

    private Ticket $ticketA;
    private Ticket $ticketB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = Company::create([
            'name' => 'Empresa Prueba A',
            'slug' => 'empresa-prueba-a',
            'status' => 'active',
        ]);

        $this->companyB = Company::create([
            'name' => 'Empresa Prueba B',
            'slug' => 'empresa-prueba-b',
            'status' => 'active',
        ]);

        $this->adminA = $this->createUser(
            $this->companyA,
            'admin',
            'company_admin'
        );

        $this->technicianA = $this->createUser(
            $this->companyA,
            'tecnico',
            'technician'
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

    public function test_ticket_cannot_be_assigned_to_user_from_another_company(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->patchJson(
            "/api/tickets/{$this->ticketA->id}",
            ['assigned_to' => $this->requesterB->id]
        )->assertUnprocessable();

        $this->assertDatabaseHas('tickets', [
            'id' => $this->ticketA->id,
            'assigned_to' => null,
        ]);
    }

    public function test_ticket_cannot_be_assigned_to_requester(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->patchJson(
            "/api/tickets/{$this->ticketA->id}",
            ['assigned_to' => $this->requesterA->id]
        )->assertUnprocessable();

        $this->assertDatabaseHas('tickets', [
            'id' => $this->ticketA->id,
            'assigned_to' => null,
        ]);
    }

    public function test_requester_cannot_forge_ticket_owner_or_company(): void
    {
        Sanctum::actingAs($this->requesterA);

        $response = $this->postJson('/api/tickets', [
            'title' => 'Ticket de prueba de seguridad',
            'description' => 'Verificar propietario y empresa.',
            'category' => 'software',
            'company_id' => $this->companyB->id,
            'requester_id' => $this->requesterB->id,
        ])->assertCreated();

        $ticketId = $response->json('data.id');

        $this->assertNotNull($ticketId);

        $this->assertDatabaseHas('tickets', [
            'id' => $ticketId,
            'company_id' => $this->companyA->id,
            'requester_id' => $this->requesterA->id,
        ]);
    }

    public function test_requester_cannot_set_privileged_priority_or_status(): void
    {
        Sanctum::actingAs($this->requesterA);

        $response = $this->postJson('/api/tickets', [
            'title' => 'Ticket de prioridad restringida',
            'description' => 'Verificar prioridad y estado.',
            'category' => 'software',
            'priority' => 'critical',
            'status' => 'closed',
            'assigned_to' => $this->technicianA->id,
        ])->assertCreated();

        $ticketId = $response->json('data.id');

        $this->assertNotNull($ticketId);

        $this->assertDatabaseHas('tickets', [
            'id' => $ticketId,
            'company_id' => $this->companyA->id,
            'requester_id' => $this->requesterA->id,
            'priority' => 'medium',
            'status' => 'open',
            'assigned_to' => null,
        ]);
    }

    public function test_requester_cannot_update_ticket(): void
    {
        Sanctum::actingAs($this->requesterA);

        $this->patchJson(
            "/api/tickets/{$this->ticketA->id}",
            ['status' => 'resolved']
        )->assertForbidden();

        $this->assertDatabaseHas('tickets', [
            'id' => $this->ticketA->id,
            'status' => 'open',
        ]);
    }

    public function test_requester_cannot_delete_ticket(): void
    {
        Sanctum::actingAs($this->requesterA);

        $this->deleteJson(
            "/api/tickets/{$this->ticketA->id}"
        )->assertForbidden();

        $this->assertDatabaseHas('tickets', [
            'id' => $this->ticketA->id,
            'deleted_at' => null,
        ]);
    }

    public function test_requester_cannot_view_another_users_ticket(): void
    {
        Sanctum::actingAs($this->requesterA);

        $this->getJson(
            "/api/tickets/{$this->ticketB->id}"
        )->assertNotFound();
    }

    public function test_company_admin_cannot_view_another_company_ticket(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->getJson(
            "/api/tickets/{$this->ticketB->id}"
        )->assertNotFound();
    }

    public function test_technician_cannot_delete_ticket(): void
    {
        Sanctum::actingAs($this->technicianA);

        $this->deleteJson(
            "/api/tickets/{$this->ticketA->id}"
        )->assertForbidden();

        $this->assertDatabaseHas('tickets', [
            'id' => $this->ticketA->id,
            'deleted_at' => null,
        ]);
    }

    public function test_requester_only_sees_own_company_tickets(): void
    {
        Sanctum::actingAs($this->requesterB);

        $response = $this->getJson('/api/tickets')
            ->assertOk();

        $ticketIds = collect($response->json('data'))
            ->pluck('id')
            ->all();

        $this->assertContains($this->ticketB->id, $ticketIds);
        $this->assertNotContains($this->ticketA->id, $ticketIds);
    }

    public function test_ticket_list_can_be_filtered_by_status(): void
    {
        $this->ticketA->status = 'resolved';
        $this->ticketA->save();

        Sanctum::actingAs($this->adminA);

        $response = $this->getJson('/api/tickets?status=resolved')
            ->assertOk();

        $ticketIds = collect($response->json('data'))
            ->pluck('id')
            ->all();

        $this->assertContains($this->ticketA->id, $ticketIds);
        $this->assertNotContains($this->ticketB->id, $ticketIds);
    }

    public function test_ticket_list_rejects_invalid_status_filter(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->getJson('/api/tickets?status=invalid_status')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_user_from_inactive_company_cannot_list_tickets(): void
    {
        $this->companyA->status = 'inactive';
        $this->companyA->save();

        Sanctum::actingAs($this->requesterA);

        $this->getJson('/api/tickets')
            ->assertForbidden();
    }

    public function test_company_admin_can_delete_ticket_from_own_company(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->deleteJson(
            "/api/tickets/{$this->ticketA->id}"
        )->assertOk();

        $this->assertSoftDeleted('tickets', [
            'id' => $this->ticketA->id,
        ]);
    }

    public function test_company_admin_cannot_delete_ticket_from_another_company(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->deleteJson(
            "/api/tickets/{$this->ticketB->id}"
        )->assertNotFound();

        $this->assertDatabaseHas('tickets', [
            'id' => $this->ticketB->id,
            'deleted_at' => null,
        ]);
    }

    public function test_ticket_rejects_invalid_status(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->patchJson(
            "/api/tickets/{$this->ticketA->id}",
            ['status' => 'invalid_status']
        )->assertUnprocessable();

        $this->assertDatabaseHas('tickets', [
            'id' => $this->ticketA->id,
            'status' => 'open',
        ]);
    }

    public function test_ticket_rejects_invalid_priority(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->patchJson(
            "/api/tickets/{$this->ticketA->id}",
            ['priority' => 'urgent_now']
        )->assertUnprocessable();

        $this->assertDatabaseHas('tickets', [
            'id' => $this->ticketA->id,
            'priority' => 'medium',
        ]);
    }

    public function test_resolving_ticket_sets_resolved_at(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->patchJson(
            "/api/tickets/{$this->ticketA->id}",
            ['status' => 'resolved']
        )->assertOk();

        $this->assertDatabaseMissing('tickets', [
            'id' => $this->ticketA->id,
            'resolved_at' => null,
        ]);
    }

    public function test_reopening_ticket_clears_resolved_at(): void
    {
        $this->ticketA->status = 'resolved';
        $this->ticketA->resolved_at = now();
        $this->ticketA->save();

        Sanctum::actingAs($this->adminA);

        $this->patchJson(
            "/api/tickets/{$this->ticketA->id}",
            ['status' => 'open']
        )->assertOk();

        $this->assertDatabaseHas('tickets', [
            'id' => $this->ticketA->id,
            'status' => 'open',
            'resolved_at' => null,
        ]);
    }

    public function test_ticket_creation_requires_title(): void
    {
        Sanctum::actingAs($this->requesterA);

        $this->postJson('/api/tickets', [
            'description' => 'Descripción de prueba.',
            'category' => 'software',
        ])->assertUnprocessable()
          ->assertJsonValidationErrors(['title']);
    }

    public function test_ticket_creation_requires_description(): void
    {
        Sanctum::actingAs($this->requesterA);

        $this->postJson('/api/tickets', [
            'title' => 'Ticket sin descripción',
            'category' => 'software',
        ])->assertUnprocessable()
          ->assertJsonValidationErrors(['description']);
    }

    public function test_ticket_creation_rejects_invalid_category(): void
    {
        Sanctum::actingAs($this->requesterA);

        $this->postJson('/api/tickets', [
            'title' => 'Ticket con categoría inválida',
            'description' => 'Descripción de prueba.',
            'category' => 'categoria_inexistente',
        ])->assertUnprocessable()
          ->assertJsonValidationErrors(['category']);
    }

    public function test_ticket_creation_rejects_empty_title(): void
    {
        Sanctum::actingAs($this->requesterA);

        $this->postJson('/api/tickets', [
            'title' => '',
            'description' => 'Descripción de prueba.',
            'category' => 'software',
        ])->assertUnprocessable()
          ->assertJsonValidationErrors(['title']);
    }

    public function test_privileged_user_cannot_create_ticket_with_invalid_due_date(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson('/api/tickets', [
            'title' => 'Ticket con fecha inválida',
            'description' => 'Prueba de validación de fecha.',
            'category' => 'software',
            'due_at' => 'fecha-invalida',
        ])->assertUnprocessable()
          ->assertJsonValidationErrors(['due_at']);
    }

    public function test_privileged_user_can_create_ticket_with_valid_due_date(): void
    {
        Sanctum::actingAs($this->adminA);

        $response = $this->postJson('/api/tickets', [
            'title' => 'Ticket con fecha límite',
            'description' => 'Prueba de fecha válida.',
            'category' => 'software',
            'due_at' => '2027-01-15 10:00:00',
        ])->assertCreated();

        $this->assertDatabaseHas('tickets', [
            'id' => $response->json('data.id'),
            'due_at' => '2027-01-15 10:00:00',
        ]);
    }

    public function test_requester_cannot_set_due_date_when_creating_ticket(): void
    {
        Sanctum::actingAs($this->requesterA);

        $response = $this->postJson('/api/tickets', [
            'title' => 'Ticket de solicitante',
            'description' => 'El solicitante no debe controlar la fecha límite.',
            'category' => 'software',
            'due_at' => '2027-01-15 10:00:00',
        ])->assertCreated();

        $this->assertDatabaseHas('tickets', [
            'id' => $response->json('data.id'),
            'due_at' => null,
        ]);
    }

    public function test_guest_cannot_list_tickets(): void
    {
        $this->getJson('/api/tickets')
            ->assertUnauthorized();
    }

    public function test_guest_cannot_create_ticket(): void
    {
        $this->postJson('/api/tickets', [
            'title' => 'Ticket sin autenticar',
            'description' => 'Esta solicitud debe rechazarse.',
            'category' => 'software',
        ])->assertUnauthorized();

        $this->assertDatabaseMissing('tickets', [
            'title' => 'Ticket sin autenticar',
        ]);
    }

    public function test_guest_cannot_access_authenticated_user_endpoint(): void
    {
        $this->getJson('/api/user')
            ->assertUnauthorized();
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
            'description' => 'Ticket creado para pruebas automatizadas.',
            'category' => 'software',
            'priority' => 'medium',
            'status' => 'open',
        ]);
    }
}
