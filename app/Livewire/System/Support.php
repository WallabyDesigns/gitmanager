<?php

namespace App\Livewire\System;

use App\Services\EditionService;
use App\Services\LicenseService;
use App\Services\SupportService;
use Illuminate\Support\Str;
use Livewire\Component;

class Support extends Component
{
    public bool $isEnterprise = false;
    public bool $licenseConfigured = false;
    public bool $supportEnabled = true;
    public string $supportEndpoint = '';
    public array $tickets = [];
    /** @var array<int, array{id: int, ticket_number: string, subject: string, status: string}> */
    public array $openTicketTabs = [];
    public string $activePane = 'tickets';
    public bool $showComposer = false;
    public ?int $selectedTicketId = null;
    public ?array $selectedTicket = null;
    public string $subject = '';
    public string $message = '';
    public string $priority = 'normal';
    public string $replyMessage = '';

    public function mount(EditionService $edition, LicenseService $license, SupportService $support): void
    {
        $this->isEnterprise = $edition->current() === EditionService::ENTERPRISE;
        $this->licenseConfigured = $license->keyConfigured();
        $this->supportEnabled = $support->enabled();
        $this->supportEndpoint = $support->endpoint();

        if ($this->isEnterprise && $this->licenseConfigured && $this->supportEnabled) {
            $this->loadTickets($support);
        }
    }

    public function render(): \Illuminate\View\View
    {
        return view('livewire.system.support')
            ->layout('layouts.app', [
                'title' => 'Enterprise Support',
                'header' => view('livewire.system.partials.header', [
                    'title' => 'System',
                    'subtitle' => 'Updates, security, settings, and enterprise support.',
                ]),
            ]);
    }

    public function refreshTickets(SupportService $support): void
    {
        if (! $this->isEnterpriseReady()) {
            return;
        }

        $this->loadTickets($support);
    }

    public function showTicketsPane(): void
    {
        $this->activePane = 'tickets';
    }

    public function startTicketComposer(): void
    {
        if (! $this->isEnterprise) {
            $this->dispatch('gwm-open-enterprise-modal', feature: 'Enterprise Support');
            return;
        }

        if (! $this->isEnterpriseReady()) {
            $this->dispatch('notify', message: 'Configure and verify your enterprise license before opening support tickets.');
            return;
        }

        $this->activePane = 'tickets';
        $this->showComposer = true;
    }

    public function cancelTicketComposer(): void
    {
        $this->showComposer = false;
        $this->subject = '';
        $this->message = '';
        $this->priority = 'normal';
        $this->resetValidation(['subject', 'message', 'priority']);
    }

    public function openTicket(int $ticketId, SupportService $support): void
    {
        if (! $this->isEnterpriseReady()) {
            return;
        }

        try {
            $ticket = $support->getTicket($ticketId);
            $this->selectedTicketId = (int) ($ticket['id'] ?? $ticketId);
            $this->selectedTicket = $ticket;
            $this->rememberOpenTicketTab($ticket);
            $this->activePane = 'ticket';
            $this->showComposer = false;
        } catch (\Throwable $exception) {
            $this->dispatch('notify', message: $exception->getMessage());
        }
    }

    public function closeTicketTab(int $ticketId): void
    {
        $ticketId = (int) $ticketId;
        if ($ticketId <= 0) {
            return;
        }

        $this->openTicketTabs = array_values(array_filter(
            $this->openTicketTabs,
            static fn (array $tab): bool => (int) ($tab['id'] ?? 0) !== $ticketId
        ));

        if ($this->selectedTicketId === $ticketId) {
            $this->selectedTicketId = null;
            $this->selectedTicket = null;
            $this->replyMessage = '';
            $this->activePane = 'tickets';
            $this->resetValidation(['replyMessage']);
        }
    }

    public function submitTicket(SupportService $support): void
    {
        if (! $this->isEnterprise) {
            $this->dispatch('gwm-open-enterprise-modal', feature: 'Enterprise Support');
            return;
        }

        if (! $this->isEnterpriseReady()) {
            $this->dispatch('notify', message: 'Configure and verify your enterprise license before opening support tickets.');
            return;
        }

        $this->validate([
            'subject' => ['required', 'string', 'max:180'],
            'message' => ['required', 'string', 'max:20000'],
            'priority' => ['required', 'in:normal,high,urgent'],
        ]);

        try {
            $ticket = $support->createTicket($this->subject, $this->message, $this->priority);
            $this->cancelTicketComposer();

            $this->loadTickets($support);

            $createdId = (int) ($ticket['id'] ?? 0);
            if ($createdId > 0) {
                $this->openTicket($createdId, $support);
            }

            $this->dispatch('notify', message: 'Support ticket created.');
        } catch (\Throwable $exception) {
            $this->dispatch('notify', message: $exception->getMessage());
        }
    }

    public function sendReply(SupportService $support): void
    {
        if (! $this->isEnterpriseReady()) {
            return;
        }

        $this->validate([
            'replyMessage' => ['required', 'string', 'max:20000'],
        ]);

        $ticketId = (int) ($this->selectedTicketId ?? 0);
        if ($ticketId <= 0) {
            $this->dispatch('notify', message: 'Select a support ticket first.');
            return;
        }

        try {
            $ticket = $support->addMessage($ticketId, $this->replyMessage);
            $this->replyMessage = '';
            $this->selectedTicket = $ticket;
            $this->selectedTicketId = (int) ($ticket['id'] ?? $ticketId);
            $this->rememberOpenTicketTab($ticket);
            $this->activePane = 'ticket';
            $this->loadTickets($support);
            $this->dispatch('notify', message: 'Support message sent.');
        } catch (\Throwable $exception) {
            $this->dispatch('notify', message: $exception->getMessage());
        }
    }

    private function loadTickets(SupportService $support): void
    {
        try {
            $tickets = $support->listTickets();
            $this->tickets = array_values(array_filter($tickets, fn ($ticket) => is_array($ticket)));
            $this->syncOpenTicketTabs();

            if ($this->selectedTicketId) {
                $selected = collect($this->tickets)->first(
                    fn (array $ticket): bool => (int) ($ticket['id'] ?? 0) === (int) $this->selectedTicketId
                );
                if (is_array($selected)) {
                    $this->selectedTicket = $support->getTicket((int) $this->selectedTicketId);
                    $this->rememberOpenTicketTab($this->selectedTicket);
                } else {
                    $this->selectedTicketId = null;
                    $this->selectedTicket = null;
                    $this->replyMessage = '';
                    $this->activePane = 'tickets';
                }
            }
        } catch (\Throwable $exception) {
            $this->dispatch('notify', message: $exception->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $ticket
     */
    private function rememberOpenTicketTab(array $ticket): void
    {
        $tab = $this->ticketTabSummary($ticket);
        if ($tab === null) {
            return;
        }

        $tabs = [];
        $replaced = false;

        foreach ($this->openTicketTabs as $existing) {
            if ((int) ($existing['id'] ?? 0) === $tab['id']) {
                $tabs[] = $tab;
                $replaced = true;
                continue;
            }

            $tabs[] = $existing;
        }

        if (! $replaced) {
            $tabs[] = $tab;
        }

        $this->openTicketTabs = array_values($tabs);
    }

    private function syncOpenTicketTabs(): void
    {
        if ($this->openTicketTabs === []) {
            return;
        }

        $summaries = [];
        foreach ($this->tickets as $ticket) {
            $summary = $this->ticketTabSummary($ticket);
            if ($summary === null) {
                continue;
            }

            $summaries[$summary['id']] = $summary;
        }

        $tabs = [];
        foreach ($this->openTicketTabs as $existing) {
            $ticketId = (int) ($existing['id'] ?? 0);
            if ($ticketId > 0 && isset($summaries[$ticketId])) {
                $tabs[] = $summaries[$ticketId];
            }
        }

        $this->openTicketTabs = $tabs;
    }

    /**
     * @param array<string, mixed> $ticket
     * @return array{id: int, ticket_number: string, subject: string, status: string}|null
     */
    private function ticketTabSummary(array $ticket): ?array
    {
        $ticketId = (int) ($ticket['id'] ?? 0);
        if ($ticketId <= 0) {
            return null;
        }

        $ticketNumber = trim((string) ($ticket['ticket_number'] ?? 'SUP-'.$ticketId));
        $subject = trim((string) ($ticket['subject'] ?? 'Untitled ticket'));

        return [
            'id' => $ticketId,
            'ticket_number' => $ticketNumber,
            'subject' => Str::limit($subject !== '' ? $subject : 'Untitled ticket', 34),
            'status' => ucfirst((string) ($ticket['status'] ?? 'open')),
        ];
    }

    private function isEnterpriseReady(): bool
    {
        return $this->isEnterprise
            && $this->licenseConfigured
            && $this->supportEnabled
            && trim($this->supportEndpoint) !== '';
    }
}
