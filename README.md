name: Hostinger Deploy

on:
  push:
    branches: [ main, master ]
  workflow_dispatch:

jobs:
  tests:
    name: Run Tests
    runs-on: ubuntu-latest
    
    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: mbstring, xml, bcmath, pdo_mysql, pdo_sqlite, gd, zip
          coverage: none

      - name: Copy .env
        run: |
          if [ -f .env.example ]; then
            cp .env.example .env
          else
            echo "⚠️ .env.example not found, creating minimal .env"
            echo "APP_ENV=testing" > .env
            echo "APP_KEY=" >> .env
          fi

      - name: Install Composer Dependencies
        run: composer install --prefer-dist --no-interaction --no-progress

      - name: Generate Application Key
        run: php artisan key:generate --ansi

      - name: Run PHPUnit Tests
        continue-on-error: true
        run: |
          if [ -f phpunit.xml ] || [ -f phpunit.xml.dist ]; then
            php artisan test || php vendor/bin/phpunit
          else
            echo "⚠️ No PHPUnit configuration found, skipping tests"
          fi

      - name: Run Static Analysis (PHPStan/Pint)
        continue-on-error: true
        run: |
          if [ -f vendor/bin/phpstan ]; then
            vendor/bin/phpstan analyse --no-progress || echo "⚠️ PHPStan check failed or not configured"
          fi
          if [ -f vendor/bin/pint ]; then
            vendor/bin/pint --test || echo "⚠️ Pint check failed or not configured"
          fi

  build-assets:
    name: Build Frontend Assets
    runs-on: ubuntu-latest
    continue-on-error: true
    
    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: mbstring, xml, bcmath, pdo_mysql, pdo_sqlite, gd, zip
          coverage: none

      - name: Copy .env
        run: |
          if [ -f .env.example ]; then
            cp .env.example .env
          else
            echo "⚠️ .env.example not found, creating minimal .env"
            echo "APP_ENV=testing" > .env
            echo "APP_KEY=" >> .env
          fi

      - name: Install Composer Dependencies
        run: composer install --prefer-dist --no-interaction --no-progress

      - name: Generate Application Key
        run: php artisan key:generate --ansi

      - name: Check for package.json
        id: check_package
        run: |
          if [ -f package.json ]; then
            echo "exists=true" >> $GITHUB_OUTPUT
          else
            echo "exists=false" >> $GITHUB_OUTPUT
          fi

      - name: Setup Node.js
        if: steps.check_package.outputs.exists == 'true'
        uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: ${{ hashFiles('package-lock.json') != '' && 'npm' || '' }}

      - name: Install NPM Dependencies
        if: steps.check_package.outputs.exists == 'true'
        continue-on-error: true
        run: |
          if [ -f package-lock.json ]; then
            npm ci
          else
            echo "ℹ️ No package-lock.json found, using npm install"
            npm install
          fi

      - name: Build Assets
        if: steps.check_package.outputs.exists == 'true'
        continue-on-error: true
        run: |
          if grep -q "\"build\"" package.json || grep -q "\"prod\"" package.json; then
            npm run build || npm run prod || echo "⚠️ Build script not found or failed"
          else
            echo "⚠️ No build script found in package.json"
          fi

      - name: Upload Built Assets
        if: steps.check_package.outputs.exists == 'true'
        uses: actions/upload-artifact@v4
        with:
          name: build-assets
          path: public/build/
          if-no-files-found: ignore
          retention-days: 1

  deploy:
    name: Deploy to Hostinger
    runs-on: ubuntu-latest
    needs: [tests, build-assets]
    if: success() || failure() # Deploy even if tests fail (you can change to 'success()' to require passing tests)
    
    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: mbstring, xml, bcmath, pdo_mysql

      - name: Download Built Assets
        uses: actions/download-artifact@v4
        with:
          name: build-assets
          path: public/build/
        continue-on-error: true

      - name: Install SSH key
        run: |
          mkdir -p ~/.ssh/
          echo "${{ secrets.SSH_KEY }}" | tr -d '\r' > ~/.ssh/id_rsa
          echo "" >> ~/.ssh/id_rsa
          chmod 600 ~/.ssh/id_rsa
          ssh-keyscan -p ${{ secrets.SSH_PORT }} ${{ secrets.SSH_HOST }} >> ~/.ssh/known_hosts || true

      - name: Copy Build Files to Target Server
        continue-on-error: true
        run: |
          if [ -d "public/build" ]; then
            echo "📤 Uploading built assets to server..."
            ssh -p ${{ secrets.SSH_PORT }} -o StrictHostKeyChecking=no ${{ secrets.SSH_USERNAME }}@${{ secrets.SSH_HOST }} "mkdir -p ~/domains/${{ secrets.WEBSITE_FOLDER }}/public/build"
            rsync -rzv --progress -e "ssh -p ${{ secrets.SSH_PORT }} -o StrictHostKeyChecking=no" public/build/ ${{ secrets.SSH_USERNAME }}@${{ secrets.SSH_HOST }}:~/domains/${{ secrets.WEBSITE_FOLDER }}/public/build/
          else
            echo "ℹ️ No build directory found, skipping asset copy"
          fi

      - name: Deploy to Hostinger Server
        uses: appleboy/ssh-action@master
        with:
          host: ${{ secrets.SSH_HOST }}
          username: ${{ secrets.SSH_USERNAME }}
          port: ${{ secrets.SSH_PORT }}
          key: ${{ secrets.SSH_KEY }}
          script: |
            set -e
            # Detect PHP binary - try CloudLinux paths (Hostinger), then fallback
            PHP_BIN=$(
              ls /opt/alt/php*/usr/bin/php 2>/dev/null | sort -rV | head -1 || \
              which php8.4 2>/dev/null || which php8.3 2>/dev/null || which php8.2 2>/dev/null || which php
            )
            
            echo "🚀 Starting deployment..."
            
            cd domains/${{ secrets.WEBSITE_FOLDER }}
            
            # Set up SSH key for git operations (use domain-specific key)
            SAFE_FOLDER=$(echo "${{ secrets.WEBSITE_FOLDER }}" | sed 's/[^a-zA-Z0-9]/_/g')
            if [ -f "$HOME/.ssh/id_rsa_${SAFE_FOLDER}" ]; then
              SSH_KEY="$HOME/.ssh/id_rsa_${SAFE_FOLDER}"
            else
              SSH_KEY=$(ls ~/.ssh/id_rsa_* 2>/dev/null | head -1 || echo "$HOME/.ssh/id_rsa")
            fi
            export GIT_SSH_COMMAND="ssh -i $SSH_KEY -o IdentitiesOnly=yes -o StrictHostKeyChecking=no"

            if [ ! -d ".git" ]; then
              # No git repo yet - fresh clone
              echo "📦 No git repo found. Doing fresh clone..."
              shopt -s dotglob
              rm -rf *
              REMOTE_URL=$(cat ~/.git_remote_url_${SAFE_FOLDER} 2>/dev/null || cat ~/.git_remote_url 2>/dev/null || echo "")
              if [ -z "$REMOTE_URL" ]; then
                echo "❌ Cannot determine repo URL. Please redeploy via php artisan hostinger:deploy"
                exit 1
              fi
              SSH_URL=$(echo "$REMOTE_URL" | sed 's|https://github.com/|git@github.com:|')
              git clone "$SSH_URL" .
            else
              # Existing repo - convert HTTPS remote to SSH and pull
              REMOTE_URL=$(git remote get-url origin 2>/dev/null || echo "")
              if echo "$REMOTE_URL" | grep -q "https://github.com/"; then
                SSH_URL=$(echo "$REMOTE_URL" | sed 's|https://github.com/|git@github.com:|')
                git remote set-url origin "$SSH_URL"
              fi
              git fetch origin
              CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo "")
              if [ -z "$CURRENT_BRANCH" ] || [ "$CURRENT_BRANCH" = "HEAD" ]; then
                CURRENT_BRANCH="master"
              fi
              git reset --hard origin/$CURRENT_BRANCH
            fi
            
            echo "📦 Installing Composer dependencies..."
            composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist --ignore-platform-reqs
            
            # Set up .env if missing
            if [ -f .env.example ] && [ ! -f .env ]; then
              cp .env.example .env
              echo "⚙️ Created .env from .env.example"
            fi
            
            # Generate APP_KEY if missing or invalid length (valid key = exactly 44 chars after base64:)
            CURRENT_KEY=$(grep '^APP_KEY=' .env 2>/dev/null | sed 's/^APP_KEY=base64://' | tr -d '"' | tr -d "'")
            if [ "${#CURRENT_KEY}" -ne 44 ] || [ -z "$CURRENT_KEY" ]; then
              NEW_KEY="base64:$(openssl rand -base64 32)"
              sed -i "s|^APP_KEY=.*|APP_KEY=${NEW_KEY}|" .env
              echo "🔑 Generated valid APP_KEY (was invalid or missing)"
            fi
            
            # Create public_html symlink so Hostinger serves Laravel's public/ dir
            if [ -d public ] && [ ! -L public_html ] && [ ! -d public_html ]; then
              ln -s public public_html
              echo "🔗 Created public_html symlink"
            fi

            # Create storage symlink
            if [ ! -L public/storage ]; then
              $PHP_BIN artisan storage:link --quiet || true
            fi
            
            echo "🔧 Running Laravel setup commands..."
            
            # Run migrations
            $PHP_BIN artisan migrate --force
            
            # Seed roles & permissions (uses firstOrCreate, safe to re-run)
            $PHP_BIN artisan db:seed --class=RolePermissionSeeder --force
            
            # Clear and cache configuration
            $PHP_BIN artisan config:clear
            $PHP_BIN artisan config:cache
            
            # Clear and cache routes
            $PHP_BIN artisan route:clear
            $PHP_BIN artisan route:cache
            
            # Clear and cache views
            $PHP_BIN artisan view:clear
            $PHP_BIN artisan view:cache
            
            # Optimize application
            $PHP_BIN artisan optimize:clear
            $PHP_BIN artisan optimize
            
            # Clear and cache events (if available)
            $PHP_BIN artisan event:clear || true
            $PHP_BIN artisan event:cache || true
            
            # Restart queue workers (if using queues)
            $PHP_BIN artisan queue:restart || true
            
            # Restart Horizon (if using Laravel Horizon)
            $PHP_BIN artisan horizon:terminate || true
            
            # Clear application cache
            $PHP_BIN artisan cache:clear
            
            # Clear OPcache (if available)
            $PHP_BIN artisan opcache:clear || true
            
            echo "✅ Deployment completed successfully!"
            
            # Show deployment info
            echo ""
            echo "📊 Deployment Information:"
            echo "   Branch: $CURRENT_BRANCH"
            echo "   Commit: $(git rev-parse --short HEAD)"
            echo "   Date: $(date)"
