#!/usr/bin/env nix-shell
#!nix-shell -i bash -p php82 php82Extensions.dom php82Extensions.mbstring php82Extensions.xml php82Extensions.xmlwriter php82Extensions.tokenizer php82Extensions.pdo php82Extensions.pdo_sqlite php82Extensions.sqlite3 php82Extensions.curl php82Extensions.openssl php82Extensions.fileinfo

# Test script for NixOS - runs PHPUnit with proper PHP extensions

echo "Running Laravel Package Tests..."
echo "PHP version: $(php --version | head -n 1)"
echo ""

vendor/bin/phpunit "$@"
