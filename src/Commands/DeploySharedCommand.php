<?php

namespace TheCodeholic\LaravelHostingerDeploy\Commands;

class DeploySharedCommand extends BaseHostingerCommand
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'hostinger:deploy 
                            {--fresh : Delete and clone fresh repository}
                            {--site-dir= : Override site directory from config}
                            {--token= : GitHub Personal Access Token}
                            {--show-errors : Show detailed error messages}';

    /**
     * The console command description.
     */
    protected $description = 'Deploy Laravel application to Hostinger shared hosting';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🚀 Starting Hostinger deployment...');

        // Validate configuration
        if (!$this->validateConfiguration()) {
            return self::FAILURE;
        }

        // Get repository URL
        $repoUrl = $this->getRepositoryUrl();
        if (!$repoUrl) {
            $this->error('❌ Could not detect Git repository URL. Please run this command from a Git repository.');
            return self::FAILURE;
        }

        $this->info("📦 Repository: {$repoUrl}");

        // Initialize GitHub API (optional, for automatic deploy key management)
        $this->initializeGitHubAPI($repoUrl, false);

        // Setup SSH connection
        $this->setupSshConnection();

        // Test SSH connection
        if (!$this->ssh->testConnection()) {
            $this->error('❌ SSH connection failed. Please check your SSH configuration.');
            return self::FAILURE;
        }

        $this->info('✅ SSH connection successful');

        // Build frontend assets if package.json exists
        $this->buildFrontendAssets();

        // Deploy to server
        if (!$this->deployToServer($repoUrl)) {
            $this->error('❌ Deployment failed');
            return self::FAILURE;
        }

        // Copy built assets to server
        $this->copyBuiltAssetsToServer();

        $this->info('✅ Deployment completed successfully!');
        $this->info("🌐 Your Laravel application: https://{$this->getSiteDir()}");

        return self::SUCCESS;
    }


    /**
     * Deploy application to server.
     */
    protected function deployToServer(string $repoUrl): bool
    {
        $siteDir = $this->getSiteDir();
        $isFresh = $this->option('fresh');

        try {
            // Setup SSH keys if needed
            $this->setupSshKeysForDeployment();

            // Check folder status and get deployment choice
            $cloneChoice = $this->getDeploymentChoice($siteDir, $isFresh);

            // Prepare deployment commands
            $commands = $this->buildDeploymentCommands($repoUrl, $siteDir, $cloneChoice);

            // Execute deployment
            $this->info('📦 Deploying application...');
            try {
                $this->ssh->executeMultiple($commands);
                return true;
            } catch (\Exception $e) {
                // Check if this is a git clone authentication error
                if ($this->isGitAuthenticationError($e)) {
                    return $this->handleGitAuthenticationError($repoUrl, $siteDir, $cloneChoice);
                }
                throw $e; // Re-throw if it's not an auth error
            }
        } catch (\Exception $e) {
            // Check if this is a git authentication error that wasn't caught earlier
            if ($this->isGitAuthenticationError($e)) {
                return $this->handleGitAuthenticationError($repoUrl, $siteDir, 'clone_direct');
            }
            
            // Show error message
            $this->error("❌ Deployment failed.");
            $this->line('');
            
            // Show actual error details if show-errors flag is set or if error contains useful info
            $showErrors = $this->option('show-errors');
            $errorMessage = $e->getMessage();
            
            if ($showErrors || strpos($errorMessage, 'Error output:') !== false || strpos($errorMessage, 'exit code:') !== false) {
                $this->warn('📋 Error Details:');
                $this->line('');
                // Display the error message, breaking it into lines if it contains newlines
                $errorLines = explode("\n", $errorMessage);
                foreach ($errorLines as $line) {
                    // Highlight exit codes and error outputs
                    if (strpos($line, 'exit code:') !== false || strpos($line, 'Error output:') !== false) {
                        $this->line('   ⚠️  ' . $line);
                    } else {
                        $this->line('   ' . $line);
                    }
                }
                $this->line('');
            }
            
            $this->warn('💡 This might be due to:');
            $this->line('   1. Server connection issues');
            $this->line('   2. Repository access problems');
            $this->line('   3. Missing dependencies on the server');
            $this->line('   4. Command execution failures (composer, git, etc.)');
            $this->line('');
            
            if (!$showErrors) {
                $this->info('💡 Tip: Run with --show-errors flag to see detailed error messages.');
                $this->line('');
            }
            
            $this->info('🔧 Please check your server configuration and try again.');
            return false;
        }
    }


    /**
     * Setup SSH keys on server if needed and add deploy key via API if available.
     * Does not display the key to user - only shows it on actual permission errors.
     */
    protected function setupSshKeysForDeployment(): void
    {
        if (!$this->setupSshKeys(false)) {
            return;
        }

        // Get public key
        $keyName = $this->getKeyName();
        $publicKey = $this->ssh->getPublicKey($keyName);
        
        if (!$publicKey) {
            $this->error("❌ Could not retrieve public key ({$keyName}) from server");
            return;
        }

        // Try to add deploy key via API if available (silently)
        if ($this->githubAPI) {
            // Try to add via API, but don't show manual instructions if it fails
            // The key will be shown only if git clone fails with permission error
            try {
                $repoInfo = $this->github->getRepositoryInfo();
                if ($repoInfo) {
                    if ($this->githubAPI->keyExists($repoInfo['owner'], $repoInfo['name'], $publicKey)) {
                        // Key already exists, nothing to do
                        return;
                    }
                    // Try to add the key
                    $this->githubAPI->createDeployKey($repoInfo['owner'], $repoInfo['name'], $publicKey, 'Hostinger Server', false);
                }
            } catch (\Exception $e) {
                // Silent failure - will be handled if git clone fails
            }
        }
        // If no API, we'll handle it when git clone fails with permission error
    }


    /**
     * Check folder status and get deployment choice from user.
     */
    protected function getDeploymentChoice(string $siteDir, bool $forceFresh): string
    {
        if ($forceFresh) {
            return 'delete_and_clone_direct';
        }

        $this->info('🔍 Checking website folder...');

        // Check if folder is empty
        $folderStatus = $this->checkFolderStatus($siteDir);

        if ($folderStatus === 'empty') {
            $this->info('✅ Empty folder - ready to deploy');
            return 'clone_direct';
        }

        $this->warn('⚠️  Folder not empty - checking contents...');

        // Check if it's a Laravel project
        $isLaravel = $this->isLaravelProject($siteDir);

        $fullPath = $this->getAbsoluteSitePath($siteDir);

        if ($isLaravel) {
            $this->info("✅ Found existing Laravel project in: {$fullPath}");
            $this->line('');
            $this->line('1. Replace with fresh deployment');
            $this->line('2. Keep existing and continue');
            $this->line('');

            $choice = $this->ask('Choose [1/2]', '2');

            if ($choice === '1') {
                $this->info('🔄 Will replace existing project');
                return 'delete_and_clone_direct';
            } else {
                $this->info('⏭️  Keeping existing project');
                return 'skip';
            }
        } else {
            $this->error("❌ Non-Laravel project detected in: {$fullPath}");
            $this->line('');
            $this->line('1. Replace with Laravel project');
            $this->line('2. Cancel deployment');
            $this->line('');

            $choice = $this->ask('Choose [1/2]', '2');

            if ($choice === '1') {
                $this->info('🔄 Will replace with Laravel project');
                return 'delete_and_clone_direct';
            } else {
                $this->info('❌ Deployment cancelled');
                throw new \Exception('Deployment cancelled by user');
            }
        }
    }

    /**
     * Check if the folder is empty or not.
     */
    protected function checkFolderStatus(string $siteDir): string
    {
        $absolutePath = $this->getAbsoluteSitePath($siteDir);
        
        // First check if directory exists
        if (!$this->ssh->directoryExists($absolutePath)) {
            return 'empty';
        }
        
        // Then check if it's empty
        if ($this->ssh->directoryIsEmpty($absolutePath)) {
            return 'empty';
        }
        
        return 'not_empty';
    }

    /**
     * Check if the folder contains a Laravel project.
     */
    protected function isLaravelProject(string $siteDir): bool
    {
        $absolutePath = $this->getAbsoluteSitePath($siteDir);
        
        // Check if directory exists
        if (!$this->ssh->directoryExists($absolutePath)) {
            return false;
        }
        
        // Check for Laravel-specific files
        $artisanPath = $absolutePath . '/artisan';
        $composerPath = $absolutePath . '/composer.json';
        
        if (!$this->ssh->fileExists($artisanPath) || !$this->ssh->fileExists($composerPath)) {
            return false;
        }
        
        // Check if composer.json contains laravel/framework
        try {
            // Path is escaped by buildSshCommand, so use single quotes inside
            $grepCommand = "grep -q 'laravel/framework' '{$composerPath}' 2>/dev/null && echo 'yes' || echo 'no'";
            $result = trim($this->ssh->execute($grepCommand));
            return trim($result) === 'yes';
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Build deployment commands.
     */
    protected function buildDeploymentCommands(string $repoUrl, string $siteDir, string $cloneChoice): array
    {
        $commands = [];
        $absolutePath = $this->getAbsoluteSitePath($siteDir);

        // Create site directory
        $commands[] = "mkdir -p {$absolutePath}";
        $commands[] = "cd {$absolutePath}";

        // Save repo URL for GitHub Actions fresh-clone fallback (domain-specific)
        $safeSiteDir = preg_replace('/[^a-zA-Z0-9]/', '_', $siteDir);
        $commands[] = "echo '{$repoUrl}' > ~/.git_remote_url_{$safeSiteDir}";

        // Remove public_html if exists
        $commands[] = "rm -rf public_html";

        // Add Git host to known_hosts to avoid interactive prompt on first clone
        // This is safe and necessary for automated deployments
        $gitHost = $this->extractGitHost($repoUrl);
        if ($gitHost) {
            $commands[] = "mkdir -p ~/.ssh";
            $commands[] = "chmod 700 ~/.ssh";
            // Escape hostname for security
            $escapedHost = escapeshellarg($gitHost);
            $commands[] = "ssh-keyscan -H {$escapedHost} >> ~/.ssh/known_hosts 2>/dev/null || true";
        }

        $sshKeyName = $this->getKeyName();
        $gitSshCommand = "GIT_SSH_COMMAND=\"ssh -i ~/.ssh/{$sshKeyName} -o IdentitiesOnly=yes -o StrictHostKeyChecking=no\"";

        switch ($cloneChoice) {
            case 'clone_direct':
                // Fresh deployment - clone directly
                $commands[] = "{$gitSshCommand} git clone {$repoUrl} . && git config core.sshCommand \"ssh -i ~/.ssh/{$sshKeyName} -o IdentitiesOnly=yes -o StrictHostKeyChecking=no\"";
                break;
            case 'delete_and_clone_direct':
                // Replace existing - delete everything and clone
                $commands[] = "rm -rf * .[^.]* 2>/dev/null || true";
                $commands[] = "{$gitSshCommand} git clone {$repoUrl} . && git config core.sshCommand \"ssh -i ~/.ssh/{$sshKeyName} -o IdentitiesOnly=yes -o StrictHostKeyChecking=no\"";
                break;
            case 'skip':
                // Keep existing - just update dependencies
                $commands[] = "git config core.sshCommand \"ssh -i ~/.ssh/{$sshKeyName} -o IdentitiesOnly=yes -o StrictHostKeyChecking=no\" || true";
                break;
            default:
                // Default: check if repository exists
                $commands[] = "if [ -d .git ]; then git config core.sshCommand \"ssh -i ~/.ssh/{$sshKeyName} -o IdentitiesOnly=yes -o StrictHostKeyChecking=no\"; {$gitSshCommand} git pull; else {$gitSshCommand} git clone {$repoUrl} . && git config core.sshCommand \"ssh -i ~/.ssh/{$sshKeyName} -o IdentitiesOnly=yes -o StrictHostKeyChecking=no\"; fi";
                break;
        }

        // Install dependencies
        $composerFlags = config('hostinger-deploy.deployment.composer_flags', '--no-dev --optimize-autoloader');
        $commands[] = "composer install {$composerFlags}";

        // Copy .env.example to .env ONLY if .env doesn't exist
        $commands[] = "if [ -f .env.example ] && [ ! -f .env ]; then cp .env.example .env; fi";

        // Create symbolic link for Laravel public folder (only if it doesn't exist)
        $commands[] = "if [ -d public ] && [ ! -L public_html ] && [ ! -d public_html ]; then ln -s public public_html; fi";

        // Generate app key ONLY if APP_KEY is not set in .env
        $commands[] = "if ! grep -q '^APP_KEY=base64:' .env 2>/dev/null && ! grep -q '^APP_KEY=\"base64:' .env 2>/dev/null; then php artisan key:generate --quiet; fi";

        // Run migrations
        if (config('hostinger-deploy.deployment.run_migrations', true)) {
            $commands[] = "echo 'yes' | php artisan migrate --quiet";
        }

        // Create storage link only if it doesn't exist
        if (config('hostinger-deploy.deployment.run_storage_link', true)) {
            $commands[] = "if [ -d public ] && [ ! -L public/storage ] && [ ! -d public/storage ]; then php artisan storage:link --quiet; fi";
        }

        // Post-deployment optimization commands
        // Clear and cache configuration
        if (config('hostinger-deploy.deployment.run_config_cache', false)) {
            $commands[] = "php artisan config:clear --quiet";
            $commands[] = "php artisan config:cache --quiet";
        }

        // Clear and cache routes
        if (config('hostinger-deploy.deployment.run_route_cache', false)) {
            $commands[] = "php artisan route:clear --quiet";
            $commands[] = "php artisan route:cache --quiet";
        }

        // Clear and cache views
        if (config('hostinger-deploy.deployment.run_view_cache', false)) {
            $commands[] = "php artisan view:clear --quiet";
            $commands[] = "php artisan view:cache --quiet";
        }

        // Optimize application (always run)
        $commands[] = "php artisan optimize:clear --quiet";
        $commands[] = "php artisan optimize --quiet";

        // Clear and cache events (if available)
        $commands[] = "php artisan event:clear --quiet || true";
        $commands[] = "php artisan event:cache --quiet || true";

        // Restart queue workers (if using queues)
        $commands[] = "php artisan queue:restart --quiet || true";

        // Restart Horizon (if using Laravel Horizon)
        $commands[] = "php artisan horizon:terminate --quiet || true";

        // Clear application cache
        $commands[] = "php artisan cache:clear --quiet";

        // Clear OPcache (if available)
        $commands[] = "php artisan opcache:clear --quiet || true";

        return $commands;
    }


    /**
     * Handle git authentication error by displaying public key and instructions.
     */
    protected function handleGitAuthenticationError(string $repoUrl, string $siteDir, string $cloneChoice): bool
    {
        $this->line('');
        $this->warn('🔑 Git authentication failed! The deploy key is not set up correctly.');
        $this->line('');

        // Get public key from server
        $keyName = $this->getKeyName();
        $publicKey = $this->ssh->getPublicKey($keyName);
        
        if (!$publicKey) {
            $this->error("❌ Could not retrieve public key ({$keyName}) from server. Generating new key...");
            if ($this->ssh->generateSshKey($keyName)) {
                $publicKey = $this->ssh->getPublicKey($keyName);
            }
        }

        if ($publicKey) {
            // Get repository information
            $repoInfo = $this->github->parseRepositoryUrl($repoUrl);
            
            // Check if deploy key already exists (via API if available)
            $keyExists = false;
            if ($this->githubAPI && $repoInfo) {
                try {
                    $keyExists = $this->githubAPI->keyExists($repoInfo['owner'], $repoInfo['name'], $publicKey);
                    if ($keyExists) {
                        $this->info('✅ Deploy key already exists in repository');
                        // Retry deployment
                        $this->info('🔄 Retrying deployment...');
                        try {
                            $commands = $this->buildDeploymentCommands($repoUrl, $siteDir, $cloneChoice);
                            $this->ssh->executeMultiple($commands);
                            return true;
                        } catch (\Exception $e) {
                            $this->warn('⚠️  Deployment still failed: ' . $e->getMessage());
                            // Continue to show the key below
                        }
                    }
                } catch (\Exception $e) {
                    // If check fails, proceed to show the key
                }
            }
            
            // If key doesn't exist, try to add via API first
            if (!$keyExists && $this->githubAPI && $repoInfo) {
                try {
                    $this->info('🔑 Attempting to add deploy key via API...');
                    $this->githubAPI->createDeployKey($repoInfo['owner'], $repoInfo['name'], $publicKey, 'Hostinger Server', false);
                    $this->info('✅ Deploy key added successfully via API');
                    // Retry deployment
                    $this->info('🔄 Retrying deployment...');
                    try {
                        $commands = $this->buildDeploymentCommands($repoUrl, $siteDir, $cloneChoice);
                        $this->ssh->executeMultiple($commands);
                        return true;
                    } catch (\Exception $e) {
                        $this->warn('⚠️  Deployment still failed: ' . $e->getMessage());
                        // Continue to show manual instructions below
                    }
                } catch (\Exception $e) {
                    $this->warn('⚠️  Failed to add deploy key via API: ' . $e->getMessage());
                    $this->warn('   Falling back to manual method...');
                    $this->line('');
                }
            }
            
            // Only show deploy key if it doesn't exist and needs to be added manually
            if (!$keyExists) {
                $this->info('📋 Add this SSH public key to your GitHub repository:');
                $this->line('');
                
                if ($repoInfo) {
                    $deployKeysUrl = $this->github->getDeployKeysUrl($repoInfo['owner'], $repoInfo['name']);
                    $this->line("   Go to: {$deployKeysUrl}");
                } else {
                    $this->line("   Go to: Your repository → Settings → Deploy keys");
                }
                
                $this->line('');
                $this->warn('   Steps:');
                $this->line('   1. Click "Add deploy key"');
                $this->line('   2. Give it a title (e.g., "Hostinger Server")');
                $this->line('   3. Paste the public key below');
                $this->line('   4. ✅ Check "Allow write access" (optional, for deployments)');
                $this->line('   5. Click "Add key"');
                $this->line('');
                $this->line('   ' . str_repeat('-', 60));
                $this->line($publicKey);
                $this->line('   ' . str_repeat('-', 60));
                $this->line('');
                
                // Retry loop - keep asking until deployment succeeds or user gives up
                $maxRetries = 3;
                $attempt = 0;
                
                while ($attempt < $maxRetries) {
                    $this->ask('Press ENTER after you have added the deploy key to GitHub to continue...', '');
                    $this->info('🔄 Retrying deployment...');
                    
                    try {
                        $commands = $this->buildDeploymentCommands($repoUrl, $siteDir, $cloneChoice);
                        $this->ssh->executeMultiple($commands);
                        
                        // Success - let main handle method display success message
                        return true;
                    } catch (\Exception $e) {
                        $attempt++;
                        
                        // Check if it's still an authentication error
                        if ($this->isGitAuthenticationError($e) && $attempt < $maxRetries) {
                            $this->line('');
                            $this->warn("⚠️  Authentication still failed (attempt {$attempt}/{$maxRetries})");
                            $this->line('');
                            $this->warn('💡 Please make sure:');
                            $this->line('   1. You have copied the public key correctly');
                            $this->line('   2. You have added it as a deploy key (not SSH key)');
                            $this->line('   3. You have saved the deploy key');
                            $this->line('');
                            $this->info('📋 Here is your public key again:');
                            $this->line('');
                            $this->line('   ' . str_repeat('-', 60));
                            $this->line($publicKey);
                            $this->line('   ' . str_repeat('-', 60));
                            $this->line('');
                            continue;
                        } else {
                            // Not an auth error or max retries reached
                            return $this->handleDeploymentFailure($e, $repoUrl, $attempt >= $maxRetries);
                        }
                    }
                }
                
                return false;
            } else {
                // Key already exists but deployment still failed - show error
                return $this->handleDeploymentFailure(new \Exception('Deployment failed even though deploy key exists'), $repoUrl, false);
            }
        } else {
            $this->error('❌ Could not retrieve or generate SSH public key.');
            return false;
        }
    }

    /**
     * Handle deployment failure with user-friendly error messages.
     */
    protected function handleDeploymentFailure(\Exception $e, string $repoUrl, bool $maxRetriesReached): bool
    {
        $this->line('');
        
        $showErrors = $this->option('show-errors');
        $errorMessage = $e->getMessage();
        
        if ($maxRetriesReached) {
            $this->error('❌ Maximum retry attempts reached.');
            $this->line('');
            
            // Show actual error details if show-errors flag is set or if error contains useful info
            if ($showErrors || strpos($errorMessage, 'Error output:') !== false || strpos($errorMessage, 'exit code:') !== false) {
                $this->warn('📋 Error Details:');
                $this->line('');
                $errorLines = explode("\n", $errorMessage);
                foreach ($errorLines as $line) {
                    if (strpos($line, 'exit code:') !== false || strpos($line, 'Error output:') !== false) {
                        $this->line('   ⚠️  ' . $line);
                    } else {
                        $this->line('   ' . $line);
                    }
                }
                $this->line('');
            }
            
            $this->warn('💡 Please check:');
            $this->line('   1. The deploy key has been added correctly to GitHub');
            $this->line('   2. The repository URL is correct: ' . $repoUrl);
            $this->line('   3. You have access to the repository');
            $this->line('   4. The deploy key has write access (if needed)');
            $this->line('');
            
            if (!$showErrors) {
                $this->info('💡 Tip: Run with --show-errors flag to see detailed error messages.');
                $this->line('');
            }
            
            $this->info('🔧 You can try running the command again after fixing the issue.');
        } else {
            // Not an authentication error - show general deployment failure
            $this->error('❌ Deployment failed.');
            $this->line('');
            
            // Show actual error details if show-errors flag is set or if error contains useful info
            if ($showErrors || strpos($errorMessage, 'Error output:') !== false || strpos($errorMessage, 'exit code:') !== false) {
                $this->warn('📋 Error Details:');
                $this->line('');
                $errorLines = explode("\n", $errorMessage);
                foreach ($errorLines as $line) {
                    if (strpos($line, 'exit code:') !== false || strpos($line, 'Error output:') !== false) {
                        $this->line('   ⚠️  ' . $line);
                    } else {
                        $this->line('   ' . $line);
                    }
                }
                $this->line('');
            }
            
            $this->warn('💡 This might be due to:');
            $this->line('   1. Server connection issues');
            $this->line('   2. Repository access problems');
            $this->line('   3. Missing dependencies on the server');
            $this->line('   4. Command execution failures (composer, git, etc.)');
            $this->line('');
            
            if (!$showErrors) {
                $this->info('💡 Tip: Run with --show-errors flag to see detailed error messages.');
                $this->line('');
            }
            
            $this->info('🔧 Please check your server configuration and try again.');
        }
        
        return false;
    }

    /**
     * Extract Git hostname from repository URL.
     */
    protected function extractGitHost(string $repoUrl): ?string
    {
        // Handle SSH URLs: git@github.com:owner/repo.git or git@gitlab.com:owner/repo.git
        if (preg_match('/git@([^:]+):/', $repoUrl, $matches)) {
            return $matches[1];
        }
        
        // Handle HTTPS URLs: https://github.com/owner/repo.git or https://gitlab.com/owner/repo.git
        if (preg_match('/https?:\/\/([^\/]+)/', $repoUrl, $matches)) {
            return $matches[1];
        }
        
        return null;
    }

    /**
     * Build frontend assets if package.json exists.
     */
    protected function buildFrontendAssets(): void
    {
        $packageJsonPath = base_path('package.json');
        
        if (!file_exists($packageJsonPath)) {
            return;
        }

        $this->info('📦 Found package.json - building frontend assets...');

        try {
            // Check if npm is available
            $npmCheck = \Illuminate\Support\Facades\Process::run('which npm');
            if (!$npmCheck->successful()) {
                $this->warn('⚠️  npm not found. Skipping asset build.');
                return;
            }

            // Install dependencies if node_modules doesn't exist
            if (!is_dir(base_path('node_modules'))) {
                $this->info('📥 Installing npm dependencies...');
                $installProcess = \Illuminate\Support\Facades\Process::path(base_path())
                    ->timeout(300)
                    ->run('npm install');
                
                if (!$installProcess->successful()) {
                    $this->warn('⚠️  Failed to install npm dependencies: ' . $installProcess->errorOutput());
                    return;
                }
            }

            // Run build command
            $this->info('🔨 Running npm run build...');
            $buildProcess = \Illuminate\Support\Facades\Process::path(base_path())
                ->timeout(300)
                ->run('npm run build');
            
            if (!$buildProcess->successful()) {
                $this->warn('⚠️  npm run build failed: ' . $buildProcess->errorOutput());
                $this->warn('   Continuing deployment without built assets...');
                return;
            }

            $this->info('✅ Frontend assets built successfully');
        } catch (\Exception $e) {
            $this->warn('⚠️  Error building frontend assets: ' . $e->getMessage());
            $this->warn('   Continuing deployment without built assets...');
        }
    }

    /**
     * Copy built assets to remote server.
     */
    protected function copyBuiltAssetsToServer(): void
    {
        $buildPath = base_path('public/build');
        
        if (!is_dir($buildPath)) {
            return;
        }

        $this->info('📤 Copying built assets to server...');

        try {
            $siteDir = $this->getSiteDir();
            $absolutePath = $this->getAbsoluteSitePath($siteDir);
            $remoteBuildPath = "{$absolutePath}/public/build";

            // Build rsync command
            $host = config('hostinger-deploy.ssh.host');
            $username = config('hostinger-deploy.ssh.username');
            $port = config('hostinger-deploy.ssh.port', 22);

            // Use rsync to copy files
            $rsyncCommand = sprintf(
                'rsync -r -e "ssh -p %d -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null" %s/ %s@%s:%s',
                $port,
                escapeshellarg($buildPath),
                $username,
                $host,
                escapeshellarg($remoteBuildPath)
            );

            $rsyncProcess = \Illuminate\Support\Facades\Process::timeout(60)->run($rsyncCommand);

            if (!$rsyncProcess->successful()) {
                $this->warn('⚠️  Failed to copy built assets: ' . $rsyncProcess->errorOutput());
                return;
            }

            $this->info('✅ Built assets copied to server successfully');
        } catch (\Exception $e) {
            $this->warn('⚠️  Error copying built assets: ' . $e->getMessage());
        }
    }
}
