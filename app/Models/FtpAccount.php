<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class FtpAccount extends Model
{
    protected $fillable = [
        'name',
        'host',
        'port',
        'ssh_port',
        'username',
        'password_encrypted',
        'ssh_pass_binary',
        'ssh_key_path',
        'root_path',
        'passive',
        'ssl',
        'timeout',
        'ftp_test_status',
        'ftp_test_message',
        'ftp_tested_at',
        'ftp_test_signature',
        'ssh_test_status',
        'ssh_test_message',
        'ssh_tested_at',
        'ssh_test_signature',
    ];

    protected $casts = [
        'passive' => 'boolean',
        'ssl' => 'boolean',
        'port' => 'integer',
        'ssh_port' => 'integer',
        'timeout' => 'integer',
        'ftp_tested_at' => 'datetime',
        'ssh_tested_at' => 'datetime',
    ];

    protected $hidden = [
        'password_encrypted',
    ];

    public function setPassword(string $password): void
    {
        $password = trim($password);
        if ($password === '') {
            return;
        }

        $this->password_encrypted = Crypt::encryptString($password);
    }

    public function getDecryptedPassword(): string
    {
        if (! $this->password_encrypted) {
            return '';
        }

        try {
            return Crypt::decryptString($this->password_encrypted);
        } catch (\Throwable $exception) {
            return '';
        }
    }

    public function currentFtpTestSignature(): string
    {
        $passwordSignature = $this->passwordSignature();
        $payload = [
            'host' => $this->host,
            'port' => (int) ($this->port ?? 21),
            'username' => $this->username,
            'password_signature' => $passwordSignature,
            'root_path' => $this->root_path,
            'passive' => (bool) $this->passive,
            'ssl' => (bool) $this->ssl,
            'timeout' => (int) ($this->timeout ?? 30),
        ];

        return hash('sha256', json_encode($payload));
    }

    public function currentSshTestSignature(): string
    {
        $passwordSignature = $this->passwordSignature();
        $payload = [
            'host' => $this->host,
            'port' => (int) ($this->ssh_port ?? 22),
            'username' => $this->username,
            'password_signature' => $passwordSignature,
            'root_path' => $this->root_path,
            'ssh_pass_binary' => $this->ssh_pass_binary,
            'ssh_key_path' => $this->ssh_key_path,
        ];

        return hash('sha256', json_encode($payload));
    }

    public function ftpNeedsTest(): bool
    {
        if (! $this->ftp_test_status || ! $this->ftp_test_signature) {
            return true;
        }

        return ! hash_equals($this->ftp_test_signature, $this->currentFtpTestSignature());
    }

    public function sshNeedsTest(): bool
    {
        if (! $this->ssh_test_status || ! $this->ssh_test_signature) {
            return true;
        }

        return ! hash_equals($this->ssh_test_signature, $this->currentSshTestSignature());
    }

    private function passwordSignature(): string
    {
        $password = $this->getDecryptedPassword();
        $key = (string) config('app.key', 'gwm');
        if ($key === '') {
            $key = 'gwm';
        }

        return hash_hmac('sha256', $password, $key);
    }
}
