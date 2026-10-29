<?php

namespace App\Livewire\FtpAccounts;

use App\Jobs\TestFtpConnection;
use App\Models\FtpAccount;
use App\Models\Project;
use App\Services\FtpService;
use App\Services\SshService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Livewire\Component;

class Index extends Component
{
    use AuthorizesRequests;

    public string $tab = 'list';

    public array $form = [];

    public ?int $editingId = null;

    public ?string $testStatus = null;

    public ?string $testMessage = null;

    public ?string $sshTestStatus = null;

    public ?string $sshTestMessage = null;

    public ?string $ftpTestSignature = null;

    public ?string $sshTestSignature = null;

    public bool $ftpTestRan = false;

    public ?string $ftpTestToken = null;

    public ?int $ftpTestQueuedAt = null;

    private const FTP_TEST_PENDING_LIMIT_SECONDS = 240;

    public bool $sshTestRan = false;

    public function mount(): void
    {
        $this->resetForm();
    }

    public function render()
    {
        return view('livewire.ftp-accounts.index', [
            'accounts' => FtpAccount::query()->orderBy('name')->get(),
        ])->layout('layouts.app', [
            'title' => 'Remote Access',
            'header' => view('livewire.ftp-accounts.partials.header'),
        ]);
    }

    public function setTab(string $tab): void
    {
        error_log('Setting tab to: '.$tab);
        $this->tab = $tab;
        if ($tab === 'ftpcreate') {
            $this->resetForm();
        }
    }

    public function edit(int $accountId): void
    {
        $account = FtpAccount::query()->findOrFail($accountId);
        $this->editingId = $account->id;
        $this->form = [
            'name' => $account->name,
            'host' => $account->host,
            'port' => $account->port ?? 21,
            'ssh_port' => $account->ssh_port ?? 22,
            'username' => $account->username,
            'password' => '',
            'ssh_pass_binary' => $account->ssh_pass_binary,
            'ssh_key_path' => $account->ssh_key_path,
            'root_path' => $account->root_path,
            'passive' => (bool) $account->passive,
            'encryption' => $account->ftpEncryption(),
            'timeout' => $account->timeout ?? 30,
        ];
        $this->testStatus = $account->ftp_test_status;
        $this->testMessage = $account->ftp_test_message;
        $this->sshTestStatus = $account->ssh_test_status;
        $this->sshTestMessage = $account->ssh_test_message;
        $this->ftpTestSignature = $account->ftp_test_signature;
        $this->sshTestSignature = $account->ssh_test_signature;
        $this->ftpTestRan = false;
        $this->sshTestRan = false;
        $this->ftpTestToken = null;
        $this->ftpTestQueuedAt = null;
        $this->tab = 'ftpcreate';
    }

    public function save(): void
    {
        $validated = $this->validate($this->rules());
        $data = $validated['form'];
        $data['ssl'] = $data['encryption'] !== 'none';
        $password = $data['password'] ?? '';
        unset($data['password']);

        if ($this->editingId) {
            $account = FtpAccount::query()->findOrFail($this->editingId);
            $account->fill($data);
            if ($password !== '') {
                $account->setPassword($password);
            }
            $this->applyTestResultsToModel($account);
            $account->save();
            $message = 'Remote access updated.';
        } else {
            $this->runAutoTestsOnCreate($data, $password);
            $account = new FtpAccount($data);
            $account->setPassword($password);
            $this->applyTestResultsToModel($account);
            $account->save();
            $message = 'Remote access created.';
        }

        $this->dispatch('notify', message: $message);
        $this->resetForm();
        $this->tab = 'list';
    }

    public function delete(int $accountId): void
    {
        $account = FtpAccount::query()->findOrFail($accountId);
        $inUse = Project::query()->where('ftp_account_id', $account->id)->exists();

        if ($inUse) {
            $this->dispatch('notify', message: 'This remote access record is in use by one or more projects.');

            return;
        }

        $account->delete();
        $this->dispatch('notify', message: 'Remote access deleted.');
    }

    public function testConnection(): void
    {
        $this->sshTestStatus = null;
        $this->sshTestMessage = null;
        $this->sshTestSignature = null;
        $this->sshTestRan = false;

        $validated = $this->validate($this->testRules());
        $data = $validated['form'];

        $password = (string) ($data['password'] ?? '');
        if ($password === '' && $this->editingId) {
            $account = FtpAccount::query()->find($this->editingId);
            if ($account) {
                $password = $account->getDecryptedPassword();
            }
        }

        if ($password === '') {
            $this->testStatus = 'error';
            $this->testMessage = 'Password is required to test FTP.';

            return;
        }

        $this->ftpTestToken = (string) Str::uuid();
        $this->ftpTestQueuedAt = time();
        $this->ftpTestSignature = $this->computeFtpSignature($data, $password);
        $this->ftpTestRan = false;
        $this->testStatus = 'pending';
        $this->testMessage = 'Testing in the background...';

        TestFtpConnection::dispatch(
            $this->ftpTestToken,
            $data['host'],
            (int) ($data['port'] ?? 21),
            $data['username'],
            Crypt::encryptString($password),
            ($data['encryption'] ?? 'explicit') !== 'none',
            (bool) ($data['passive'] ?? true),
            (int) ($data['timeout'] ?? 30),
            $data['root_path'] ?? null
        );
    }

    /**
     * Polled by the view while a background FTP test is pending.
     */
    public function checkFtpTestResult(): void
    {
        if (! $this->ftpTestToken) {
            return;
        }

        $result = Cache::get(TestFtpConnection::resultKey($this->ftpTestToken));

        if ($result === null) {
            if (time() - (int) $this->ftpTestQueuedAt > self::FTP_TEST_PENDING_LIMIT_SECONDS) {
                $this->finishFtpTest('error', 'The FTP test did not complete. Check that the queue worker (php artisan queue:work) is running.');
            }

            return;
        }

        $this->finishFtpTest($result['status'] ?? 'error', $result['message'] ?? 'Unable to test FTP connection.');
    }

    private function finishFtpTest(string $status, string $message): void
    {
        Cache::forget(TestFtpConnection::resultKey((string) $this->ftpTestToken));
        $this->ftpTestToken = null;
        $this->ftpTestQueuedAt = null;
        $this->testStatus = $status;
        $this->testMessage = $message;
        $this->ftpTestRan = true;
        $this->persistAccessTestResult('ftp', $status, $message);
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->form = [
            'name' => '',
            'host' => '',
            'port' => 21,
            'ssh_port' => 22,
            'username' => '',
            'password' => '',
            'ssh_pass_binary' => '',
            'ssh_key_path' => '',
            'root_path' => '',
            'passive' => true,
            'encryption' => 'explicit',
            'timeout' => 30,
        ];
        $this->testStatus = null;
        $this->testMessage = null;
        $this->sshTestStatus = null;
        $this->sshTestMessage = null;
        $this->ftpTestSignature = null;
        $this->sshTestSignature = null;
        $this->ftpTestRan = false;
        $this->sshTestRan = false;
        $this->ftpTestToken = null;
        $this->ftpTestQueuedAt = null;
    }

    private function rules(): array
    {
        return [
            'form.name' => ['required', 'string', 'max:255'],
            'form.host' => ['required', 'string', 'max:255'],
            'form.port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'form.ssh_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'form.username' => ['required', 'string', 'max:255'],
            'form.password' => [
                $this->editingId ? 'nullable' : 'required',
                'string',
                'max:255',
            ],
            'form.ssh_pass_binary' => ['nullable', 'string', 'max:255'],
            'form.ssh_key_path' => ['nullable', 'string', 'max:255'],
            'form.root_path' => ['nullable', 'string', 'max:255'],
            'form.passive' => ['boolean'],
            'form.encryption' => ['required', 'in:none,explicit'],
            'form.timeout' => ['nullable', 'integer', 'min:5', 'max:120'],
        ];
    }

    private function testRules(): array
    {
        return [
            'form.host' => ['required', 'string', 'max:255'],
            'form.port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'form.username' => ['required', 'string', 'max:255'],
            'form.password' => [
                $this->editingId ? 'nullable' : 'required',
                'string',
                'max:255',
            ],
            'form.root_path' => ['nullable', 'string', 'max:255'],
            'form.passive' => ['boolean'],
            'form.encryption' => ['required', 'in:none,explicit'],
            'form.timeout' => ['nullable', 'integer', 'min:5', 'max:120'],
        ];
    }

    private function sshTestRules(): array
    {
        return [
            'form.host' => ['required', 'string', 'max:255'],
            'form.ssh_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'form.username' => ['required', 'string', 'max:255'],
            'form.password' => ['nullable', 'string', 'max:255'],
            'form.root_path' => ['nullable', 'string', 'max:255'],
            'form.ssh_pass_binary' => ['nullable', 'string', 'max:255'],
            'form.ssh_key_path' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function testSshConnection(): void
    {
        $this->testStatus = null;
        $this->testMessage = null;
        $this->ftpTestSignature = null;
        $this->ftpTestRan = false;
        $this->ftpTestToken = null;
        $this->ftpTestQueuedAt = null;

        $validated = $this->validate($this->sshTestRules());
        $data = $validated['form'];

        $host = (string) ($data['host'] ?? '');
        $username = (string) ($data['username'] ?? '');
        $password = (string) ($data['password'] ?? '');
        if ($password === '' && $this->editingId) {
            $account = FtpAccount::query()->find($this->editingId);
            if ($account) {
                $password = $account->getDecryptedPassword();
            }
        }
        $rootPath = trim((string) ($data['root_path'] ?? ''));
        $rootPath = $rootPath !== '' ? $rootPath : null;

        $port = (int) ($data['ssh_port'] ?? 22);
        if ($port <= 0) {
            $port = 22;
        }

        $passBinary = trim((string) ($data['ssh_pass_binary'] ?? ''));
        $keyPath = trim((string) ($data['ssh_key_path'] ?? ''));

        $output = [];

        if ($password === '' && $keyPath === '') {
            $this->sshTestStatus = 'error';
            $this->sshTestMessage = 'Provide an SSH password or key path to test the connection.';

            return;
        }

        try {
            $lines = app(SshService::class)->runCommand(
                $host,
                $port,
                $username,
                $password !== '' ? $password : null,
                $rootPath,
                'pwd',
                $output,
                $passBinary !== '' ? $passBinary : null,
                $keyPath !== '' ? $keyPath : null
            );

            $path = $lines[0] ?? null;
            $this->sshTestStatus = 'ok';
            $this->sshTestMessage = $path
                ? 'SSH connected. Remote path: '.$path
                : 'SSH connection verified.';
        } catch (\Throwable $exception) {
            $this->sshTestStatus = 'error';
            $this->sshTestMessage = $exception->getMessage();
        }

        $this->sshTestSignature = $this->computeSshSignature($data, $password);
        $this->sshTestRan = true;
        $this->persistAccessTestResult('ssh', $this->sshTestStatus, $this->sshTestMessage);
    }

    private function applyTestResultsToModel(FtpAccount $account): void
    {
        if ($this->ftpTestRan) {
            $account->ftp_test_status = $this->testStatus;
            $account->ftp_test_message = $this->testMessage;
            $account->ftp_tested_at = now();
            $account->ftp_test_signature = $this->ftpTestSignature;
        }

        if ($this->sshTestRan) {
            $account->ssh_test_status = $this->sshTestStatus;
            $account->ssh_test_message = $this->sshTestMessage;
            $account->ssh_tested_at = now();
            $account->ssh_test_signature = $this->sshTestSignature;
        }
    }

    private function persistAccessTestResult(string $type, ?string $status, ?string $message): void
    {
        if (! $this->editingId || ! $status) {
            return;
        }

        $account = FtpAccount::query()->find($this->editingId);
        if (! $account) {
            return;
        }

        if ($type === 'ftp') {
            $account->ftp_test_status = $status;
            $account->ftp_test_message = $message;
            $account->ftp_tested_at = now();
            $account->ftp_test_signature = $this->ftpTestSignature;
        } else {
            $account->ssh_test_status = $status;
            $account->ssh_test_message = $message;
            $account->ssh_tested_at = now();
            $account->ssh_test_signature = $this->sshTestSignature;
        }

        $account->save();
    }

    private function runAutoTestsOnCreate(array $data, string $password): void
    {
        $this->ftpTestRan = false;
        $this->sshTestRan = false;

        if ($password !== '') {
            $this->autoTestFtp($data, $password);
        }

        $keyPath = trim((string) ($data['ssh_key_path'] ?? ''));
        if ($password !== '' || $keyPath !== '') {
            $this->autoTestSsh($data, $password, $keyPath);
        }
    }

    private function autoTestFtp(array $data, string $password): void
    {
        $result = app(FtpService::class)->testConnection(
            $data['host'],
            (int) ($data['port'] ?? 21),
            $data['username'],
            $password,
            ($data['encryption'] ?? 'explicit') !== 'none',
            (bool) ($data['passive'] ?? true),
            (int) ($data['timeout'] ?? 30),
            $data['root_path'] ?? null
        );

        $this->testStatus = $result['status'] ?? 'error';
        $this->testMessage = $result['message'] ?? 'Unable to test FTP connection.';
        $this->ftpTestSignature = $this->computeFtpSignature($data, $password);
        $this->ftpTestRan = true;
    }

    private function autoTestSsh(array $data, string $password, string $keyPath): void
    {
        $port = (int) ($data['ssh_port'] ?? 22);
        if ($port <= 0) {
            $port = 22;
        }

        $passBinary = trim((string) ($data['ssh_pass_binary'] ?? ''));
        $rootPath = trim((string) ($data['root_path'] ?? '')) ?: null;
        $output = [];

        try {
            $lines = app(SshService::class)->runCommand(
                (string) ($data['host'] ?? ''),
                $port,
                (string) ($data['username'] ?? ''),
                $password !== '' ? $password : null,
                $rootPath,
                'pwd',
                $output,
                $passBinary !== '' ? $passBinary : null,
                $keyPath !== '' ? $keyPath : null
            );

            $path = $lines[0] ?? null;
            $this->sshTestStatus = 'ok';
            $this->sshTestMessage = $path ? 'SSH connected. Remote path: '.$path : 'SSH connection verified.';
        } catch (\Throwable $exception) {
            $this->sshTestStatus = 'error';
            $this->sshTestMessage = $exception->getMessage();
        }

        $this->sshTestSignature = $this->computeSshSignature($data, $password);
        $this->sshTestRan = true;
    }

    public function cleanBuildEnvironments(): void
    {
        Artisan::call('workspaces:clean');
        $this->dispatch('notify', message: 'Build environments cleaned.', type: 'success');
    }

    private function computeFtpSignature(array $data, string $password): string
    {
        $account = new FtpAccount($data);
        if ($password !== '') {
            $account->setPassword($password);
        }

        return $account->currentFtpTestSignature();
    }

    private function computeSshSignature(array $data, string $password): string
    {
        $account = new FtpAccount($data);
        if ($password !== '') {
            $account->setPassword($password);
        }

        return $account->currentSshTestSignature();
    }
}
