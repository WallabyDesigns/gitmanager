<?php

namespace App\Livewire\System;

use App\Services\EditionService;
use App\Services\LicenseService;
use App\Services\SupportService;
use Livewire\Component;

class Support extends Component
{
    public bool $isEnterprise = false;
    public bool $licenseConfigured = false;
    public bool $testingBypass = false;
    public bool $supportEnabled = true;
    public string $supportEndpoint = '';
    public array $tickets = [];
    public ?int $selectedTicketId = null;
    public ?array $selectedTicket = null;
    public string $subject = '';
    public string $message = '';
    public string $priority = 'normal';
    public string $replyMessage = '';

    public function mount(EditionService $edition, LicenseService $license, SupportService $support): void
    {
        $this->isEnterprise = $edition->current() === EditionService::ENTERPRISE;
        $this->testingBypass = $this->isEnterprise && $edition->canSwapForTesting() && ! $license->keyConfigured();
        $this->licenseConfigured = $license->keyConfigured() || $this->testingBypass;
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

    public function openTicket(int $ticketId, SupportService $support): void
    {
        if (! $this->isEnterpriseReady()) {
            return;
        }

        try {
            $ticket = $support->getTicket($ticketId);
            $this->selectedTicketId = (int) ($ticket['id'] ?? 0);
            $this->selectedTicket = $ticket;
        } catch (\Throwable $exception) {
            $this->dispatch('notify', message: $exception->getMessage());
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
            $this->subject = '';
            $this->message = '';
            $this->priority = 'normal';

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

            if ($this->selectedTicketId) {
                $selected = collect($this->tickets)->firstWhere('id', $this->selectedTicketId);
                if (is_array($selected)) {
                    $this->selectedTicket = $selected;
                } else {
                    $this->selectedTicketId = null;
                    $this->selectedTicket = null;
                }
            } elseif (! empty($this->tickets)) {
                $firstId = (int) ($this->tickets[0]['id'] ?? 0);
                if ($firstId > 0) {
                    $this->openTicket($firstId, $support);
                }
            }
        } catch (\Throwable $exception) {
            $this->dispatch('notify', message: $exception->getMessage());
        }
    }

    private function isEnterpriseReady(): bool
    {
        return $this->isEnterprise
            && $this->licenseConfigured
            && $this->supportEnabled
            && trim($this->supportEndpoint) !== '';
    }
}
