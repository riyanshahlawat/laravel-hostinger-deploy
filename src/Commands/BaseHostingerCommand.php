<?php

namespace TheCodeholic\LaravelHostingerDeploy\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use TheCodeholic\LaravelHostingerDeploy\Services\SshConnectionService;
use TheCodeholic\LaravelHostingerDeploy\Services\GitHubActionsService;
use TheCodeholic\LaravelHostingerDeploy\Services\GitHubAPIService;

abstract class BaseHostingerCommand extends Command
{
    protected SshConnectionService $ssh;
    protected GitHubActionsService $github;
    protected ?GitHubAPIService $githubAPI = null;

    public function __construct()
    {
        parent::__construct();
        $this->github = new GitHubActionsService();
    }

    /**
     * Validate and interactively prompt for missing configuration.
     */
    protected function validateConfiguration(): bool
    {
        $envFile = base_path('.env');
        $envVars = [];
        
        $required = [
            'HOSTINGER_SSH_HOST' => [
                'current' => config('hostinger-deploy.ssh.host'),
                'prompt' => 'Enter your Hostinger Server IP (e.g., 72.61.247.242)',
                'secret' => false
            ],
            'HOSTINGER_SSH_USERNAME' => [
                'current' => config('hostinger-deploy.ssh.username'),
                'prompt' => 'Enter your Hostinger SSH Username (e.g., u321483967)',
                'secret' => false
            ],
            'HOSTINGER_SSH_PASSWORD' => [
                'current' => config('hostinger-deploy.ssh.password'),
                'prompt' => 'Enter your Hostinger SSH Password',
                'secret' => true
            ],
            'HOSTINGER_SITE_DIR' => [
                'current' => $this->getSiteDir(),
                'prompt' => 'Enter your Hostinger Website Folder Name (e.g., mywebsite.com)',
                'secret' => false
            ],
            'GITHUB_API_TOKEN' => [
                'current' => env('GITHUB_API_TOKEN'),
                'prompt' => 'Enter your GitHub Personal Access Token (or press enter to skip)',
                'secret' => true,
                'optional' => true
            ]
        ];

        $missingKeys = false;

        foreach ($required as $key => $details) {
            if (empty($details['current'])) {
                $missingKeys = true;
                
                if ($details['secret']) {
                    $value = $this->secret($details['prompt']);
                } else {
                    $value = $this->ask($details['prompt']);
                }

                if (empty($value) && empty($details['optional'])) {
                    $this->error("❌ {$key} is required to continue.");
                    return false;
                }

                if (!empty($value)) {
                    $envVars[$key] = $value;
                    
                    // Temporarily set it for the current command's memory
                    putenv("{$key}={$value}");
                    $_ENV[$key] = $value;
                    $_SERVER[$key] = $value;
                }
            }
        }

        // If we gathered new configuration, save it to .env
        if ($missingKeys && !empty($envVars)) {
            $this->info("💾 Saving your configuration to .env file...");
            
            if (file_exists($envFile)) {
                $envContent = file_get_contents($envFile);
                $updates = "\n# Laravel Hostinger Deploy Settings\n";
                
                foreach ($envVars as $key => $value) {
                    // Overwrite if it exists but is empty, otherwise append
                    if (preg_match("/^{$key}=.*/m", $envContent)) {
                        $envContent = preg_replace("/^{$key}=.*/m", "{$key}=\"{$value}\"", $envContent);
                    } else {
                        $updates .= "{$key}=\"{$value}\"\n";
                    }
                }
                
                if (trim($updates) !== "# Laravel Hostinger Deploy Settings") {
                    $envContent .= $updates;
                }
                
                file_put_contents($envFile, $envContent);
            }
            
            // Re-hydrate the config array with the new memory values so connections work immediately
            config([
                'hostinger-deploy.ssh.host' => env('HOSTINGER_SSH_HOST', $envVars['HOSTINGER_SSH_HOST'] ?? null),
                'hostinger-deploy.ssh.username' => env('HOSTINGER_SSH_USERNAME', $envVars['HOSTINGER_SSH_USERNAME'] ?? null),
                'hostinger-deploy.ssh.password' => env('HOSTINGER_SSH_PASSWORD', $envVars['HOSTINGER_SSH_PASSWORD'] ?? null),
                'hostinger-deploy.deployment.site_dir' => env('HOSTINGER_SITE_DIR', $envVars['HOSTINGER_SITE_DIR'] ?? null),
            ]);
        }

        return true;
    }

    /**
     * Get repository information.
     */
    protected function getRepositoryInfo(): ?array
    {
        if (!$this->github->isGitRepository()) {
            return null;
        }

        return $this->github->getRepositoryInfo();
    }

    /**
     * Get repository URL from Git.
     */
    protected function getRepositoryUrl(): ?string
    {
        $repoUrl = $this->github->getRepositoryUrl();
        
        if (!$repoUrl) {
            return null;
        }

        $this->info("✅ Detected Git repository: {$repoUrl}");

        return $repoUrl;
    }

    /**
     * Setup SSH connection service.
     */
    protected function setupSshConnection(): void
    {
        $this->ssh = new SshConnectionService(
            config('hostinger-deploy.ssh.host') ?? env('HOSTINGER_SSH_HOST'),
            config('hostinger-deploy.ssh.username') ?? env('HOSTINGER_SSH_USERNAME'),
            config('hostinger-deploy.ssh.password') ?? env('HOSTINGER_SSH_PASSWORD'),
            config('hostinger-deploy.ssh.port', 22),
            config('hostinger-deploy.ssh.timeout', 30)
        );
    }

    /**
     * Initialize GitHub API service (optional).
     */
    protected function initializeGitHubAPI(?string $repoUrl = null, bool $required = false): bool
    {
        try {
            $token = $this->option('token') ?: env('GITHUB_API_TOKEN');

            if (!$token) {
                if ($required) {
                    $this->line('');
                    $this->warn('⚠️  GitHub Personal Access Token is not set.');
                    $this->line('');
                    
                    if (!$this->confirm('Do you want to proceed?', true)) {
                        // User chose "no" - show instructions and exit
                        $this->line('');
                        $this->warn('💡 How to provide your GitHub Personal Access Token:');
                        $this->line('   Option 1: Set GITHUB_API_TOKEN in your .env file');
                        $this->line('   Option 2: Use --token=YOUR_TOKEN option when running this command');
                        $this->line('');
                        $this->showGitHubTokenInstructions();
                        $this->line('');
                        $this->info('📝 Please add GITHUB_API_TOKEN to your .env file and rerun the script.');
                        $this->line('');
                        return false;
                    }
                    
                    // User chose "yes" - continue without token, secrets will be displayed manually
                    $this->warn('⚠️  Continuing without Personal Access Token. Secrets will be displayed for manual setup.');
                    return true;
                }

                $this->line('');
                $this->warn('⚠️  GitHub Personal Access Token is not set.');
                $this->line('');
                
                if (!$this->confirm('Do you want to proceed?', true)) {
                    // User chose "no" - show instructions and halt
                    $this->line('');
                    $this->showGitHubTokenInstructions();
                    $this->line('');
                    $this->info('📝 Please add GITHUB_API_TOKEN to your .env file and rerun the script.');
                    $this->line('');
                    exit(0);
                }
                
                // User chose "yes" - continue without token, deploy key will be shown manually
                $this->warn('⚠️  Continuing without Personal Access Token. Deploy key will be displayed for manual addition.');
                return true;
            }

            $this->githubAPI = new GitHubAPIService($token);

            // Test API connection
            if (!$this->githubAPI->testConnection()) {
                if ($required) {
                    $this->error('❌ Failed to authenticate with GitHub API. Please check your token.');
                    $this->githubAPI = null;
                    return false;
                }

                $this->warn('⚠️  GitHub API connection failed. Deploy key will need to be added manually.');
                $this->githubAPI = null;
                return true;
            }

            $this->info('✅ GitHub API connection successful');
            return true;
        } catch (\Exception $e) {
            if ($required) {
                $this->error("❌ GitHub API error: " . $e->getMessage());
                $this->githubAPI = null;
                return false;
            }

            // API is optional, continue without it
            $this->warn('⚠️  GitHub API initialization failed: ' . $e->getMessage());
            $this->warn('   Deploy key will need to be added manually.');
            $this->githubAPI = null;
            return true;
        }
    }

    /**
     * Show instructions for generating GitHub Personal Access Token.
     */
    protected function showGitHubTokenInstructions(): void
    {
        $this->info('🔑 To create a GitHub Personal Access Token:');
        $this->line('');
        $this->line('   1. Go to: https://github.com/settings/personal-access-tokens');
        $this->line('   2. Click "Generate new token" → "Generate new token (classic)"');
        $this->line('   3. Give your token a descriptive name (e.g., "Hostinger Deploy")');
        $this->line('   4. Set expiration (or no expiration)');
        $this->line('   5. Select the following permissions:');
        $this->line('');
        $this->info('   📋 Required Permissions:');
        $this->info('      ✓ Administration → Read and write');
        $this->info('        (Allows managing deploy keys for the repository)');
        $this->info('      ✓ Secrets → Read and write');
        $this->info('        (Allows creating/updating GitHub Actions secrets)');
        $this->info('      ✓ Metadata → Read-only');
        $this->info('        (Automatically selected, required for API access)');
        $this->line('');
        $this->warn('   6. Click "Generate token" and copy the token immediately');
        $this->warn('      ⚠️  You won\'t be able to see it again!');
        $this->line('');
        $this->info('💡 Tip: You can also set GITHUB_API_TOKEN in your .env file to skip this prompt.');
    }

    /**
     * Get the unique SSH key name for the site directory.
     */
    protected function getKeyName(): string
    {
        $siteDir = $this->getSiteDir();
        $safeDir = preg_replace('/[^a-zA-Z0-9]/', '_', $siteDir);
        $specificKey = 'id_rsa_' . $safeDir;

        // Backward compatibility: If the specific key exists, use it
        if ($this->ssh->sshKeyExists($specificKey)) {
            return $specificKey;
        }

        // Backward compatibility: If legacy id_rsa exists
        if ($this->ssh->sshKeyExists('id_rsa')) {
            $absolutePath = $this->getAbsoluteSitePath($siteDir);
            try {
                // If it's an existing deployment without core.sshCommand, use id_rsa
                $command = "test -d '{$absolutePath}/.git' && cd '{$absolutePath}' && (git config core.sshCommand 2>/dev/null || echo 'not_set') || echo 'no_git'";
                $result = trim($this->ssh->execute($command));
                
                if ($result === 'not_set') {
                    return 'id_rsa';
                }
            } catch (\Exception $e) {
                // Ignore errors
            }
        }

        return $specificKey;
    }

    /**
     * Setup SSH keys on server if needed.
     */
    protected function setupSshKeys(bool $addToAuthorizedKeys = true): bool
    {
        $keyName = $this->getKeyName();
        try {
            if (!$this->ssh->sshKeyExists($keyName)) {
                $this->info("🔑 Generating SSH keys ({$keyName}) on server...");
                if (!$this->ssh->generateSshKey($keyName)) {
                    $this->error('❌ Failed to generate SSH keys');
                    return false;
                }
            } else {
                $this->info("🔑 SSH keys ({$keyName}) already exist on server");
            }

            // Get public key
            $publicKey = $this->ssh->getPublicKey($keyName);
            
            if (!$publicKey) {
                $this->error("❌ Could not retrieve public key ({$keyName}) from server");
                return false;
            }

            // Add to authorized_keys if requested
            if ($addToAuthorizedKeys) {
                if (!$this->ssh->addToAuthorizedKeys($publicKey)) {
                    $this->warn('⚠️  Could not add public key to authorized_keys (may already exist)');
                }
            }

            $this->info('✅ SSH keys setup completed');
            return true;
        } catch (\Exception $e) {
            $this->error("SSH keys setup error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Add deploy key to GitHub repository via API.
     */
    protected function addDeployKeyViaAPI(string $publicKey, ?array $repoInfo = null): void
    {
        if (!$this->githubAPI) {
            return;
        }

        try {
            // Get repository information if not provided
            if (!$repoInfo) {
                $repoInfo = $this->github->getRepositoryInfo();
                if (!$repoInfo) {
                    $this->warn('⚠️  Could not detect repository information. Skipping automatic deploy key setup.');
                    return;
                }
            }

            $owner = $repoInfo['owner'];
            $repo = $repoInfo['name'];

            $this->info('🔑 Adding deploy key to GitHub repository via API...');

            // Check if key already exists
            if ($this->githubAPI->keyExists($owner, $repo, $publicKey)) {
                $this->info('✅ Deploy key already exists in repository');
                return;
            }

            // Create deploy key
            $this->githubAPI->createDeployKey($owner, $repo, $publicKey, 'Hostinger Server', false);
            $this->info('✅ Deploy key added successfully to repository');
        } catch (\Exception $e) {
            $this->warn('⚠️  Failed to add deploy key via API: ' . $e->getMessage());
            $this->warn('   You may need to add it manually.');
            
            // Check if key might already exist before showing manual instructions
            $repoInfoForCheck = $repoInfo ?: $this->github->getRepositoryInfo();
            $keyExists = false;
            if ($repoInfoForCheck) {
                try {
                    $keyExists = $this->githubAPI->keyExists($repoInfoForCheck['owner'], $repoInfoForCheck['name'], $publicKey);
                    if ($keyExists) {
                        $this->info('✅ Deploy key already exists in repository');
                        return;
                    }
                } catch (\Exception $e) {
                    // If check fails, proceed with manual instructions
                }
            }

            // Show manual instructions as fallback
            $this->line('');
            $this->warn('🔑 Please add this SSH key manually to your GitHub repository:');
            $this->warn('   Settings → Deploy keys → Add deploy key');
            $this->line('');
            $this->line($publicKey);
            $this->line('');
            
            if (method_exists($this, 'ask')) {
                $this->ask('Press ENTER after adding the key to GitHub to continue...', '');
            }
        }
    }

    /**
     * Generate workflow content with placeholders replaced.
     */
    protected function generateWorkflowContent(string $branch, string $phpVersion): string
    {
        $stubPath = __DIR__ . '/../../stubs/hostinger-deploy.yml';
        
        if (!File::exists($stubPath)) {
            throw new \Exception("Workflow stub not found: {$stubPath}");
        }

        $content = File::get($stubPath);
        $content = str_replace('{{BRANCH}}', $branch, $content);
        $content = str_replace('{{PHP_VERSION}}', $phpVersion, $content);

        return $content;
    }

    /**
     * Get site directory from option or config.
     */
    protected function getSiteDir(): string
    {
        if ($this->hasOption('site-dir') && $this->option('site-dir')) {
            return $this->option('site-dir');
        }
        
        return config('hostinger-deploy.deployment.site_dir');
    }

    /**
     * Get absolute path for the site directory.
     */
    protected function getAbsoluteSitePath(string $siteDir): string
    {
        $username = config('hostinger-deploy.ssh.username');
        return "/home/{$username}/domains/{$siteDir}";
    }

    /**
     * Test if repository is accessible via SSH (deploy key works).
     */
    protected function testRepositoryAccess(string $repoUrl): bool
    {
        try {
            // Test repository access using git ls-remote
            // This is a lightweight way to verify if the deploy key has access to the repository
            // Escape the repo URL properly
            $escapedRepoUrl = escapeshellarg($repoUrl);
            $sshKeyName = $this->getKeyName();
            $gitSshCommand = "GIT_SSH_COMMAND=\"ssh -i ~/.ssh/{$sshKeyName} -o IdentitiesOnly=yes -o StrictHostKeyChecking=no\"";
            $testCommand = "{$gitSshCommand} git ls-remote {$escapedRepoUrl} HEAD 2>&1";
            
            try {
                $result = $this->ssh->execute($testCommand);
                
                // Check for successful access indicators
                // If we get a commit hash or refs/heads/, the access works
                if (preg_match('/^[a-f0-9]{40}\s+refs\/heads\/HEAD/', $result) || 
                    preg_match('/^[a-f0-9]{40}\s+HEAD/', $result) ||
                    stripos($result, 'refs/heads') !== false) {
                    return true;
                }
                
                // If no permission errors, assume it works
                $errorIndicators = [
                    'Permission denied',
                    'repository not found',
                    'Could not read from remote repository',
                    'Authentication failed',
                    'Host key verification failed'
                ];
                
                foreach ($errorIndicators as $indicator) {
                    if (stripos($result, $indicator) !== false) {
                        return false;
                    }
                }
                
                // If we got some output and no errors, assume it works
                return !empty(trim($result));
                
            } catch (\Exception $e) {
                // Check the error message for permission issues
                $errorMsg = $e->getMessage();
                $errorIndicators = [
                    'Permission denied',
                    'repository not found',
                    'Could not read from remote repository',
                    'Authentication failed',
                    'Host key verification failed',
                    'SSH command failed'
                ];
                
                foreach ($errorIndicators as $indicator) {
                    if (stripos($errorMsg, $indicator) !== false) {
                        return false;
                    }
                }
                
                // If error doesn't indicate permission issue, might be network/server issue
                // Assume deploy key might not be set up
                return false;
            }
        } catch (\Exception $e) {
            // If we can't test, assume deploy key might not be set up
            return false;
        }
    }

    /**
     * Check if the exception is a git authentication error.
     */
    protected function isGitAuthenticationError(\Exception $e): bool
    {
        $errorMessage = $e->getMessage();
        
        // Check for common git authentication error messages
        $authErrorPatterns = [
            'Repository not found',
            'Could not read from remote repository',
            'Permission denied',
            'Please make sure you have the correct access rights',
            'Host key verification failed',
            'Authentication failed',
        ];

        foreach ($authErrorPatterns as $pattern) {
            if (stripos($errorMessage, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Display GitHub secrets and variables for manual setup.
     */
    protected function displayGitHubSecrets(array $repoInfo): void
    {
        $this->line('');
        $this->info('🔒 GitHub Secrets and Variables Setup');
        $this->line('');

        $keyName = $this->getKeyName();

        // Get private key
        $privateKey = $this->ssh->getPrivateKey($keyName);
        if (!$privateKey) {
            $this->error("❌ Could not retrieve private key ({$keyName}) from server");
            return;
        }

        // Display secrets
        $this->warn('📋 Add these secrets to your GitHub repository:');
        $this->line('Go to: ' . $repoInfo['secrets_url']);
        $this->line('');

        $secrets = [
            'SSH_HOST' => config('hostinger-deploy.ssh.host'),
            'SSH_USERNAME' => config('hostinger-deploy.ssh.username'),
            'SSH_PORT' => (string) config('hostinger-deploy.ssh.port', 22),
            'SSH_KEY' => $privateKey,
            'WEBSITE_FOLDER' => $this->getSiteDir(),
        ];

        foreach ($secrets as $name => $value) {
            $this->line("🔑 {$name}:");
            if ($name === 'SSH_KEY') {
                $this->line('   [Copy the private key below]');
                $this->line('');
                $this->line('   ' . str_repeat('-', 50));
                $this->line($value);
                $this->line('   ' . str_repeat('-', 50));
            } else {
                $this->line("   {$value}");
            }
            $this->line('');
        }

        // Display deploy key information (only if not already added)
        // Test repository access first - if it works, deploy key is already configured
        $publicKey = $this->ssh->getPublicKey($keyName);
        if ($publicKey) {
            // Test if we can access the repository via SSH (best way to verify deploy key works)
            $repoUrl = $repoInfo['url'];
            $sshRepoUrl = "git@github.com:{$repoInfo['owner']}/{$repoInfo['name']}.git";
            
            // Check if deploy key already exists via API (if available)
            $keyExists = false;
            if ($this->githubAPI) {
                try {
                    $keyExists = $this->githubAPI->keyExists($repoInfo['owner'], $repoInfo['name'], $publicKey);
                } catch (\Exception $e) {
                    // If check fails, test repository access instead
                }
            }
            
            // If API check didn't confirm, test repository access via SSH
            if (!$keyExists) {
                $keyExists = $this->testRepositoryAccess($sshRepoUrl);
            }
            
            // Fallback: If API is not initialized but we might have a token, try to check via API
            if (!$keyExists && !$this->githubAPI) {
                $token = $this->option('token') ?: env('GITHUB_API_TOKEN');
                if ($token) {
                    try {
                        $tempAPI = new \TheCodeholic\LaravelHostingerDeploy\Services\GitHubAPIService($token);
                        $keyExists = $tempAPI->keyExists($repoInfo['owner'], $repoInfo['name'], $publicKey);
                    } catch (\Exception $e) {
                        // If check fails, test repository access instead
                        $keyExists = $this->testRepositoryAccess($sshRepoUrl);
                    }
                } else {
                    // No token available, test repository access
                    $keyExists = $this->testRepositoryAccess($sshRepoUrl);
                }
            }

            if ($keyExists) {
                $this->info('✅ Deploy key already exists and repository access is working');
                $this->line('');
            } else {
                $this->warn('🔑 Deploy Key Information:');
                $this->line('Go to: ' . $repoInfo['deploy_keys_url']);
                $this->line('');
                $this->line('Add this public key as a Deploy Key:');
                $this->line('');
                $this->line('   ' . str_repeat('-', 50));
                $this->line($publicKey);
                $this->line('   ' . str_repeat('-', 50));
                $this->line('');
            }
        }
    }
}

