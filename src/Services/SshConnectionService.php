<?php

namespace TheCodeholic\LaravelHostingerDeploy\Services;

use Illuminate\Support\Facades\Process;
use phpseclib3\Net\SSH2;
use phpseclib3\Crypt\PublicKeyLoader;

class SshConnectionService
{
    protected string $host;
    protected string $username;
    protected int $port;
    protected int $timeout;

    public function __construct(string $host, string $username, int $port = 22, int $timeout = 30)
    {
        $this->host = $host;
        $this->username = $username;
        $this->port = $port;
        $this->timeout = $timeout;
    }

    /**
     * Get or establish an active SSH connection natively.
     */
    protected function getConnection(): SSH2
    {
        $ssh = new SSH2($this->host, $this->port, $this->timeout);

        // Try authenticating with password if provided
        if (!empty($this->password)) {
            if (!$ssh->login($this->username, $this->password)) {
                throw new \Exception("SSH connection failed: Invalid password or authentication rejected by {$this->host}");
            }
            return $ssh;
        }

        // Fallback: Try authenticating with local SSH keys
        $home = getenv('HOME') ?: getenv('USERPROFILE');
        $privateKeyPath = "{$home}/.ssh/id_rsa";

        if (file_exists($privateKeyPath)) {
            $key = PublicKeyLoader::load(file_get_contents($privateKeyPath));
            if (!$ssh->login($this->username, $key)) {
                throw new \Exception("SSH connection failed: Local SSH Key authentication rejected by {$this->host}");
            }
            return $ssh;
        }

        throw new \Exception("SSH connection failed: No password provided and no local SSH key found at {$privateKeyPath}");
    }

    /**
     * Execute a command on the remote server natively via phpseclib.
     */
    public function execute(string $command): string
    {
        try {
            $ssh = $this->getConnection();
            
            // Execute command and capture exit status
            $output = $ssh->exec($command);
            $exitCode = $ssh->getExitStatus();
            
            if ($exitCode !== 0 && $exitCode !== false) {
                // Try to capture stderr nicely
                $errorMessage = "SSH command failed (exit code: {$exitCode}): Command exited with non-zero status";
                if (!empty(trim((string)$output))) {
                    $errorMessage .= "\nOutput/Error: " . trim((string)$output);
                }
                throw new \Exception($errorMessage);
            }
            
            return (string)$output;
        } catch (\Exception $e) {
            // Re-throw our own formatted exceptions
            if (strpos($e->getMessage(), 'SSH') === 0) {
                throw $e;
            }
            throw new \Exception("SSH connection/command failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Execute multiple commands on the remote server.
     */
    public function executeMultiple(array $commands): string
    {
        $combinedCommand = implode(' && ', $commands);
        return $this->execute($combinedCommand);
    }

    /**
     * Check if SSH connection is working.
     */
    public function testConnection(): bool
    {
        try {
            $this->execute('echo "SSH connection test successful"');
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get the public key from the server.
     */
    public function getPublicKey(string $keyName = 'id_rsa'): ?string
    {
        try {
            return trim($this->execute("cat ~/.ssh/{$keyName}.pub 2>/dev/null || echo \"\""));
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get the private key from the server.
     */
    public function getPrivateKey(string $keyName = 'id_rsa'): ?string
    {
        try {
            return trim($this->execute("cat ~/.ssh/{$keyName} 2>/dev/null || echo \"\""));
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Generate SSH key pair on the server if it doesn't exist.
     */
    public function generateSshKey(string $keyName = 'id_rsa'): bool
    {
        try {
            $this->execute("ssh-keygen -t rsa -b 4096 -C \"github-deploy-key-{$keyName}\" -N \"\" -f ~/.ssh/{$keyName}");
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Add a public key to authorized_keys if it doesn't already exist.
     */
    public function addToAuthorizedKeys(string $publicKey): bool
    {
        try {
            // Check if the key already exists in authorized_keys
            $keyExists = $this->keyExistsInAuthorizedKeys($publicKey);
            
            if ($keyExists) {
                // Key already exists, don't add it again
                return true;
            }

            // Key doesn't exist, add it
            $this->execute("echo '{$publicKey}' >> ~/.ssh/authorized_keys");
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if a public key already exists in authorized_keys.
     */
    public function keyExistsInAuthorizedKeys(string $publicKey): bool
    {
        try {
            // Extract the key part (without comment) for comparison
            $keyParts = explode(' ', trim($publicKey));
            if (count($keyParts) < 2) {
                return false;
            }
            
            $keyData = $keyParts[1]; // The actual key data (middle part)
            
            // Check if this key data exists in authorized_keys
            // Use grep with escaped key data to avoid special character issues
            $escapedKeyData = escapeshellarg($keyData);
            $command = "grep -Fq {$escapedKeyData} ~/.ssh/authorized_keys 2>/dev/null && echo 'exists' || echo 'not_exists'";
            $result = trim($this->execute($command));
            
            return $result === 'exists';
        } catch (\Exception $e) {
            // If we can't check, assume it doesn't exist
            return false;
        }
    }

    /**
     * Check if SSH key exists on the server.
     */
    public function sshKeyExists(string $keyName = 'id_rsa'): bool
    {
        try {
            $result = $this->execute("test -f ~/.ssh/{$keyName} && echo \"exists\" || echo \"not_exists\"");
            return trim($result) === 'exists';
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Build the SSH command string.
     * Uses bash -c with proper escaping for reliable command execution.
     */
    protected function buildSshCommand(string $command): string
    {
        $sshOptions = [
            '-p ' . $this->port,
            '-o ConnectTimeout=' . $this->timeout,
            '-o StrictHostKeyChecking=no',
            '-o UserKnownHostsFile=/dev/null',
        ];

        // Use proper escaping for SSH command execution
        // Escape the command properly for the shell
        $escapedCommand = escapeshellarg($command);
        $sshCommand = 'ssh ' . implode(' ', $sshOptions) . ' ' . $this->username . '@' . $this->host . ' ' . $escapedCommand;

        return $sshCommand;
    }

    /**
     * Check if a directory exists.
     */
    public function directoryExists(string $path): bool
    {
        try {
            // Path is escaped by buildSshCommand, so use single quotes inside
            $result = $this->execute("test -d '{$path}' && echo 'exists' || echo 'not_exists'");
            return trim($result) === 'exists';
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if a file exists.
     */
    public function fileExists(string $path): bool
    {
        try {
            // Path is escaped by buildSshCommand, so use single quotes inside
            $result = $this->execute("test -f '{$path}' && echo 'exists' || echo 'not_exists'");
            return trim($result) === 'exists';
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if a directory is empty.
     */
    public function directoryIsEmpty(string $path): bool
    {
        try {
            // Path is escaped by buildSshCommand, so use single quotes inside
            $result = $this->execute("test -d '{$path}' && [ -z \"\$(ls -A '{$path}' 2>/dev/null)\" ] && echo 'empty' || echo 'not_empty'");
            return trim($result) === 'empty';
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Execute a command in a specific directory.
     */
    public function executeInDirectory(string $path, string $command): string
    {
        $fullCommand = "cd " . escapeshellarg($path) . " && " . $command;
        return $this->execute($fullCommand);
    }

    /**
     * Get connection details for display.
     */
    public function getConnectionString(): string
    {
        return "ssh -p {$this->port} {$this->username}@{$this->host}";
    }
}
